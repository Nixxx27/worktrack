<?php

namespace App\Models;

use App\Authorization\Scopes\TrackerVisibilityScope;
use App\Models\Concerns\BelongsToTracker;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * READ MODEL ONLY for the per-project feed and the activity log screen.
 *
 * ActivityRecorder remains the single WRITER — it inserts through the query builder
 * so that no observer, touch(), or mass-assignment path can produce a row that skips
 * the stall-clock bookkeeping the recorder does alongside the insert. Nothing here
 * should ever create(), update(), or delete(): the table is append-only by intent,
 * and a row in it is a claim about what happened.
 */
#[ScopedBy(TrackerVisibilityScope::class)]
class ProjectActivity extends Model
{
    use BelongsToTracker;

    protected $table = 'project_activities';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'counts_as_activity' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * One human sentence for the log screen.
     *
     * Rendered from the payload frozen at write time rather than from live relations,
     * so a renamed step or a re-tagged project cannot rewrite what the log says
     * happened. The one deliberate exception is step_move, which resolves step names
     * live for the same reason movement history does: it is the same column under a
     * new name, not a different one.
     */
    public function describe(): string
    {
        $payload = $this->payload ?? [];

        return match ($this->type) {
            'created' => 'created this project'.($payload['step'] ?? null ? " in {$payload['step']}" : ''),
            'step_move' => 'moved it to '.($payload['to_step_name'] ?? 'another step'),
            'field_edit' => 'edited '.$this->joinNames(array_keys($payload['fields'] ?? [])),
            'owner_changed' => $this->describeOwnerChange($payload),
            'assignees_changed' => $this->describeSetChange($payload, 'assigned', 'unassigned'),
            'tags_changed' => $this->describeSetChange($payload, 'tagged', 'untagged'),
            'health_changed' => $this->describeHealthChange($payload),
            'stall_detected' => 'was flagged as stalled by the system',

            // ── checklist (FR-5.5) ──────────────────────────────────────────
            'task_created' => 'added the task “'.($payload['title'] ?? 'untitled').'”'
                .($payload['assignee'] ?? null ? ' for '.$payload['assignee'] : ''),
            'task_completed' => 'completed “'.($payload['title'] ?? 'a task').'”',
            'task_reopened' => 'reopened “'.($payload['title'] ?? 'a task').'”',
            'task_updated' => $this->describeTaskUpdate($payload),
            'task_deleted' => 'deleted the task “'.($payload['title'] ?? 'untitled').'”',

            // ── conversation ────────────────────────────────────────────────
            'comment' => 'commented'.($payload['mentioned'] ?? [] ? ', mentioning '.$this->joinNames($payload['mentioned']) : ''),
            'comment_edited' => 'edited a comment',
            'comment_deleted' => 'deleted a comment',

            // ── files ───────────────────────────────────────────────────────
            'attachment_added' => 'attached '.($payload['filename'] ?? 'a file')
                .($payload['task'] ?? null ? ' to “'.$payload['task'].'”' : ''),
            'attachment_removed' => 'removed '.($payload['filename'] ?? 'a file'),

            default => str_replace('_', ' ', $this->type),
        };
    }

    private function describeHealthChange(array $payload): string
    {
        $to = str_replace('_', ' ', $payload['to'] ?? 'unknown');
        $reason = $payload['reason'] ?? null;

        // The reason is the whole value of a manual flag — "stalled" without it is
        // just a colour, and the person reading the log a week later needs the why.
        return "set health to {$to}".($reason ? " — “{$reason}”" : '');
    }

    private function describeTaskUpdate(array $payload): string
    {
        $title = $payload['title'] ?? 'a task';
        $parts = [];

        if ($fields = $payload['fields'] ?? []) {
            $parts[] = 'changed the '.$this->joinNames($fields);
        }

        if (array_key_exists('assignee', $payload) && $payload['assignee'] !== null) {
            $parts[] = 'assigned it to '.$payload['assignee'];
        }

        return $parts
            ? implode(', and ', $parts)." on “{$title}”"
            : "updated “{$title}”";
    }

    private function describeOwnerChange(array $payload): string
    {
        $to = $payload['to'] ?? null;
        $from = $payload['from'] ?? null;

        if ($to === null) {
            return 'removed '.($from ?? 'the owner').' as owner';
        }

        return $from === null
            ? "made {$to} the owner"
            : "changed the owner from {$from} to {$to}";
    }

    private function describeSetChange(array $payload, string $addedVerb, string $removedVerb): string
    {
        $parts = [];

        if ($added = $payload['added'] ?? []) {
            $parts[] = $addedVerb.' '.$this->joinNames($added);
        }

        if ($removed = $payload['removed'] ?? []) {
            $parts[] = $removedVerb.' '.$this->joinNames($removed);
        }

        return $parts ? implode(', and ', $parts) : 'made no change';
    }

    /** @param  list<string>  $names */
    private function joinNames(array $names): string
    {
        $names = array_map(fn ($n) => str_replace('_', ' ', (string) $n), $names);

        if (count($names) <= 1) {
            return $names[0] ?? 'nothing';
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }
}
