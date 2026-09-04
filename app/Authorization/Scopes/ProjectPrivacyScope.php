<?php

namespace App\Authorization\Scopes;

use App\Authorization\AccessContext;
use App\Enums\ProjectVisibility;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The enforcement point for "Only me" cards (FR-4.11).
 *
 * A sibling of TrackerVisibilityScope rather than a branch inside it, and attached by
 * the same #[ScopedBy] mechanism, so the two axes compose without either knowing about
 * the other: tracker membership decides which board you are looking at, this decides
 * which of its cards exist for you. A query written a year from now inherits both
 * without its author knowing either class exists — the property that makes an
 * isolation control a control rather than a convention.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════
 * ATTACHED TO THE CHILDREN TOO, AND THAT IS THE WHOLE FEATURE.
 *
 * Hiding the card while its trail stays public is not privacy, it is a card with the
 * title removed. project_activities carries the name in its payload and renders on
 * /activity to every member of the tracker; comments are searched verbatim by FR-4.10's
 * palette; tasks, attachments and movements each leak the same way one screen further
 * along. Every one of those tables is scoped by tracker_id ALONE, so none of them are
 * touched by the predicate on `projects`.
 *
 * So this scope resolves per model: the projects table filters on its own columns,
 * everything else filters on an EXISTS against the parent card. The child tables are
 * the reason this class exists at all.
 * ═══════════════════════════════════════════════════════════════════════════════════
 *
 * WHAT IS DELIBERATELY NOT SUPPRESSED: the writes. ActivityRecorder still records
 * every action on a private card, and MovementRecorder still opens and closes its
 * intervals. It has to — last_activity_at is what stall detection reads, and the
 * movement rows are what every metric is rebuilt from, so a card that recorded nothing
 * would be a card that never ages and never appears in its owner's own watchlist. The
 * record is kept and the READ is filtered, which also means a private card made public
 * later arrives with its history intact rather than with a hole where it was hidden.
 */
final class ProjectPrivacyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var AccessContext $context */
        $context = app(AccessContext::class);

        // System context is exempt, and this is a narrower exemption than it looks.
        // It exists for the stall detector, which must be able to see a private card
        // in order to flag it and mail its OWNER — the reminder the owner put it on a
        // board to get. Nothing in system context renders to another user; the one
        // place it fans out to people, OutboxWriter::resolveRecipients(), collapses a
        // private card's recipient list to the owner alone.
        if ($context->seesAllPrivateProjects()) {
            return;
        }

        $viewerId = $context->privateOwnerUserId();

        $model instanceof Project
            ? $this->applyToProjects($builder, $model, $viewerId)
            : $this->applyToChildren($builder, $model, $viewerId);
    }

    /**
     * The card itself: shared, or mine.
     *
     * Wrapped in a closure so the two halves are one parenthesised group. Eloquent
     * nests conditions a CALLER appends after a scope, which is what stops an
     * orWhere() elsewhere from dissolving the tracker predicate — but that protection
     * says nothing about a disjunction the scope writes itself, and an unnested
     * `OR owner_user_id = me` here would attach to whatever the caller had already
     * built and return every card that user owns, in every tracker.
     */
    private function applyToProjects(Builder $builder, Model $model, ?int $viewerId): void
    {
        $builder->where(function (Builder $query) use ($model, $viewerId) {
            $query->where($model->qualifyColumn('visibility'), ProjectVisibility::Tracker->value);

            if ($viewerId !== null) {
                $query->orWhere($model->qualifyColumn('owner_user_id'), $viewerId);
            }
        });
    }

    /**
     * Anything hanging off a card: exists only if the card does, for this viewer.
     *
     * whereExists over whereIn-a-subquery because the correlated form stays a
     * primary-key probe per candidate row rather than materialising every private
     * card id, and because it cannot be defeated by a NULL project_id.
     *
     * The raw `projects` read is why this file is on the allowlist in
     * ArchitectureInvariantsTest: reaching the parent through the Project MODEL would
     * re-enter this very scope, and the recursion has no base case. A raw builder is
     * safe here in a way it is not elsewhere, because the predicate below is written
     * in full by this class and no caller can append to it.
     */
    private function applyToChildren(Builder $builder, Model $model, ?int $viewerId): void
    {
        $builder->whereExists(function (QueryBuilder $query) use ($model, $viewerId) {
            $query->select(DB::raw(1))
                ->from('projects')
                ->whereColumn('projects.id', $model->qualifyColumn('project_id'))
                ->where(function (QueryBuilder $card) use ($viewerId) {
                    $card->where('projects.visibility', ProjectVisibility::Tracker->value);

                    if ($viewerId !== null) {
                        $card->orWhere('projects.owner_user_id', $viewerId);
                    }
                });
        });
    }
}
