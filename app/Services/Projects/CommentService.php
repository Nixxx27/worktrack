<?php

namespace App\Services\Projects;

use App\Models\Comment;
use App\Models\Project;
use App\Models\User;
use App\Services\Notifications\OutboxWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Comments and @mentions (FR-4.5, FR-5.5, FR-7.4).
 *
 * THE ONLY WRITE PATH for comments, and therefore the only writer of
 * projects.comments_count.
 *
 * Mentions are resolved ONCE, at write time, into comment_mentions. That is a
 * deliberate snapshot of who was named — renaming a user later must not silently
 * re-target a mention that has already been sent. What is emphatically NOT
 * snapshotted is the authorization to receive the resulting email: the dispatcher
 * re-asserts membership and account status immediately before sending, because a
 * digest can go out 24 hours later. VERIFICATION.md data-model-2 records the version
 * of this that froze authorization at enqueue time and let a suspended ex-member
 * receive a digest naming a tracker they could no longer see.
 */
class CommentService
{
    /**
     * @* matches a display name of one to four words, so "@Maria Clara Santos" works
     * without requiring usernames the product does not have. Resolution is then done
     * greedily against the actual member list — longest name first — because "@Maria"
     * and "@Maria Clara" are both plausible prefixes and only the member list can say
     * which was meant.
     */
    private const MENTION_PATTERN = '/@([\p{L}][\p{L}\'\-\.]*(?:\s+[\p{L}][\p{L}\'\-\.]*){0,3})/u';

    public function __construct(
        private ActivityRecorder $activity,
        private OutboxWriter $outbox,
    ) {}

    public function create(Project $project, string $body, User $author): Comment
    {
        $body = trim($body);

        if ($body === '') {
            throw new \InvalidArgumentException('A comment cannot be empty.');
        }

        return DB::transaction(function () use ($project, $body, $author) {
            $comment = Comment::create([
                'tracker_id' => $project->tracker_id,
                'project_id' => $project->id,
                'user_id' => $author->id,
                'body' => $body,
            ]);

            $mentioned = $this->syncMentions($comment, $project, $body, $author);

            $this->syncCount($project);

            // FR-4.7 counts a comment as activity, so this resets the stall clock.
            // That is the point: a project people are still discussing is not rotting.
            $this->activity->record($project, 'comment', $author, [
                'excerpt' => Str::limit($body, 140),
                'mentioned' => $mentioned->pluck('name')->all(),
            ]);

            foreach ($mentioned as $user) {
                $this->outbox->queueCommentMention($project, $comment, $user, $author);
            }

            return $comment;
        });
    }

    /**
     * Edit a comment's body.
     *
     * edited_at is stamped so the thread shows the comment was changed. A silently
     * edited comment in a thread that others have already replied to rewrites the
     * conversation, and the reply that answered the original is left looking like a
     * non sequitur.
     */
    public function update(Comment $comment, string $body, User $actor): bool
    {
        $body = trim($body);

        if ($body === '') {
            throw new \InvalidArgumentException('A comment cannot be empty.');
        }

        if ($body === $comment->body) {
            return false;
        }

        return DB::transaction(function () use ($comment, $body, $actor) {
            $project = $comment->project;

            $comment->forceFill(['body' => $body, 'edited_at' => now()])->save();

            // Newly named people are notified; people dropped from the text are not
            // un-notified, because their email has already gone.
            $before = $comment->mentions()->pluck('users.id');
            $after = $this->syncMentions($comment, $project, $body, $actor);

            $this->activity->record($project, 'comment_edited', $actor, [
                'excerpt' => Str::limit($body, 140),
            ]);

            foreach ($after->whereNotIn('id', $before) as $user) {
                $this->outbox->queueCommentMention($project, $comment, $user, $actor);
            }

            return true;
        });
    }

    /** Soft-deleted, so a thread that reads as an argument cannot later read as agreement. */
    public function delete(Comment $comment, User $actor): void
    {
        DB::transaction(function () use ($comment, $actor) {
            $project = $comment->project;

            $comment->delete();

            $this->syncCount($project);

            // Deliberately does NOT count as activity: deleting your own comment is
            // not progress on the work, and letting it reset the stall clock would
            // give anyone a one-click way to make a rotting project look fresh.
            $this->activity->record($project, 'comment_deleted', $actor, [
                'excerpt' => Str::limit($comment->body, 140),
            ], countsAsActivity: false);
        });
    }

    /**
     * Which tracker members this text names.
     *
     * Only members are resolvable, which is what stops a mention from being an
     * existence oracle for people outside the tracker: an unmatched @name is simply
     * left as plain text, exactly as an unmatched typo would be.
     *
     * @return Collection<int, User>
     */
    public function resolveMentions(Project $project, string $body): Collection
    {
        if (! preg_match_all(self::MENTION_PATTERN, $body, $matches)) {
            return collect();
        }

        $candidates = collect($matches[1])
            ->map(fn (string $c) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $c) ?? '')))
            ->filter()
            ->unique();

        // Full models, NOT a column subset. These instances are handed to the outbox,
        // which asks them isActive() and reads ->role; a trimmed select leaves those
        // attributes null, isActive() returns false for everyone, and every mention
        // notification is silently dropped with no error anywhere. Caught by
        // ProjectDrawerTest — the failure mode is an absence, so nothing else would.
        $members = User::query()
            ->whereIn('id', fn ($q) => $q->select('user_id')
                ->from('tracker_members')
                ->where('tracker_id', $project->tracker_id))
            ->where('status', 'active')
            ->get();

        // Longest name first: "@Maria Clara" must win over "@Maria" when both are
        // members, otherwise the more specific mention silently reaches the wrong person.
        $byName = $members
            ->sortByDesc(fn (User $u) => mb_strlen($u->name))
            ->values();

        $matched = collect();

        foreach ($candidates as $candidate) {
            $hit = $byName->first(function (User $u) use ($candidate) {
                $name = mb_strtolower($u->name);

                // Exact, or the candidate begins with the full member name — which is
                // what makes "@Maria Clara, can you look?" resolve while "@Mar" does not.
                return $candidate === $name || str_starts_with($candidate, $name.' ');
            });

            if ($hit && ! $matched->contains('id', $hit->id)) {
                $matched->push($hit);
            }
        }

        return $matched;
    }

    /** @return Collection<int, User> the people to notify (never the author) */
    private function syncMentions(Comment $comment, Project $project, string $body, User $author): Collection
    {
        $mentioned = $this->resolveMentions($project, $body);

        DB::table('comment_mentions')->where('comment_id', $comment->id)->delete();

        if ($mentioned->isNotEmpty()) {
            DB::table('comment_mentions')->insert(
                $mentioned->map(fn (User $u) => [
                    'tracker_id' => $project->tracker_id,
                    'comment_id' => $comment->id,
                    'user_id' => $u->id,
                ])->all()
            );
        }

        // Nobody needs an email about their own comment.
        return $mentioned->reject(fn (User $u) => $u->id === $author->id)->values();
    }

    private function syncCount(Project $project): void
    {
        $project->forceFill([
            'comments_count' => Comment::where('project_id', $project->id)->count(),
        ])->save();
    }
}
