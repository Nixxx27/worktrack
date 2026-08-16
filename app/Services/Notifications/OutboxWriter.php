<?php

namespace App\Services\Notifications;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectStepMovement;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes notification intent into the outbox — always inside the caller's
 * transaction, never after it.
 *
 * The outbox exists because three FR-7 requirements are unsatisfiable with Laravel's
 * jobs table alone: coalescing repeated moves into one email (FR-7.8), per-user
 * digest batching (FR-7.7), and admin-visible permanent failures (FR-7.6 / FR-10.5,
 * where a serialized failed_jobs blob cannot be summarised per user or event).
 */
class OutboxWriter
{
    /**
     * Queue a step-change notification for everyone entitled to it.
     *
     * Content is snapshotted so a later rename cannot rewrite an already-sent email.
     * AUTHORIZATION is deliberately NOT snapshotted — the dispatcher re-asserts
     * membership and account status immediately before sending. See the note on
     * resolveRecipients().
     */
    public function queueStepChange(
        Project $project,
        ?ProjectStepMovement $from,
        ProjectStepMovement $to,
        ?User $actor = null,
    ): int {
        $payload = [
            'project_id' => $project->id,
            'project_public_id' => $project->public_id,
            'project_name' => $project->name,
            'tracker_id' => $project->tracker_id,
            'tracker_name' => $project->tracker?->name,
            'from_step' => $from?->toStep?->name,
            'to_step' => $to->toStep?->name,
            'moved_by' => $actor?->name,

            // FR-7.2 explicitly wants "how long it sat in the previous step" in the
            // email. Reading it here — from the row we just closed — avoids a lookback
            // query at send time and pins the number as it was at the moment of the move.
            'seconds_in_previous_step' => $from?->duration_seconds,
        ];

        $queued = 0;

        foreach ($this->resolveRecipients($project, $actor) as $recipient) {
            $queued += $this->write($recipient, 'project.moved', $project, $payload) ? 1 : 0;
        }

        return $queued;
    }

    /**
     * FR-5.4 — assigning a task emails the assignee.
     *
     * Deliberately NOT sent to the D3 recipient set. A step change is news for
     * everyone watching the project; a task assignment is a direct request of one
     * person, and copying the owner, every assignee, every watcher and every admin on
     * it would train all of them to filter the whole notification stream out.
     *
     * The recipient still passes the same two gates as any other recipient: they must
     * be an active account, and they must still be a member of the tracker. Membership
     * was checked when the assignment was made, but the dispatcher re-asserts it at
     * send time anyway (VERIFICATION.md data-model-2) — a digest can go out 24 hours
     * later, and by then the assignment may have outlived the membership.
     */
    public function queueTaskAssigned(Project $project, Task $task, ?User $actor = null): int
    {
        $assignee = $task->assignee_user_id ? User::find($task->assignee_user_id) : null;

        if ($assignee === null || ! $assignee->isActive() || $assignee->id === $actor?->id) {
            return 0;
        }

        $isMember = DB::table('tracker_members')
            ->where('tracker_id', $project->tracker_id)
            ->where('user_id', $assignee->id)
            ->exists();

        if (! $isMember && $assignee->role !== UserRole::Admin) {
            return 0;
        }

        return $this->write($assignee, 'task.assigned', $project, [
            'project_id' => $project->id,
            'project_public_id' => $project->public_id,
            'project_name' => $project->name,
            'tracker_id' => $project->tracker_id,
            'tracker_name' => $project->tracker?->name,
            'task_id' => $task->id,
            'task_title' => $task->title,
            'due_date' => $task->due_date?->toDateString(),
            'assigned_by' => $actor?->name,
        ]) ? 1 : 0;
    }

    /**
     * FR-7.4 — someone named you in a comment.
     *
     * Like a task assignment and unlike a step change, this goes to ONE person: being
     * named is a direct address, and broadcasting it to the whole watcher set would
     * make the mention meaningless as a signal.
     */
    public function queueCommentMention(Project $project, Comment $comment, User $recipient, ?User $author = null): int
    {
        if (! $recipient->isActive() || $recipient->id === $author?->id) {
            return 0;
        }

        $isMember = DB::table('tracker_members')
            ->where('tracker_id', $project->tracker_id)
            ->where('user_id', $recipient->id)
            ->exists();

        if (! $isMember && $recipient->role !== UserRole::Admin) {
            return 0;
        }

        return $this->write($recipient, 'comment.mentioned', $project, [
            'project_id' => $project->id,
            'project_public_id' => $project->public_id,
            'project_name' => $project->name,
            'tracker_id' => $project->tracker_id,
            'tracker_name' => $project->tracker?->name,
            'mentions' => [[
                'comment_id' => $comment->id,
                'by' => $author?->name,
                // The excerpt is a CONTENT snapshot, so a later edit cannot rewrite an
                // email that has already been sent.
                'excerpt' => Str::limit($comment->body, 200),
            ]],
        ]) ? 1 : 0;
    }

    public function queueProjectStalled(Project $project): int
    {
        $payload = [
            'project_id' => $project->id,
            'project_public_id' => $project->public_id,
            'project_name' => $project->name,
            'tracker_id' => $project->tracker_id,
            'tracker_name' => $project->tracker?->name,
            'idle_since' => $project->last_activity_at?->toIso8601String(),
        ];

        $queued = 0;

        foreach ($this->resolveRecipients($project, null) as $recipient) {
            $queued += $this->write($recipient, 'project.stalled', $project, $payload) ? 1 : 0;
        }

        return $queued;
    }

    /**
     * D3 — owner, assignees, watchers, plus ALL admins. Not the whole user base.
     *
     * Every recipient is filtered against tracker membership (FR-7.3). This matters
     * more than it looks: notification jobs run under SystemContext, which is a full
     * scoping bypass, so mail is exactly the kind of job that can render one tracker's
     * contents to someone who cannot see it. Admins are the deliberate exception —
     * they see every tracker by design (D9).
     *
     * The actor is excluded: nobody needs an email telling them what they just did.
     *
     * @return array<int,User>
     */
    private function resolveRecipients(Project $project, ?User $actor): array
    {
        $memberIds = DB::table('tracker_members')
            ->where('tracker_id', $project->tracker_id)
            ->pluck('user_id');

        $interested = collect([$project->owner_user_id])
            ->merge(DB::table('project_assignees')->where('project_id', $project->id)->pluck('user_id'))
            ->merge(DB::table('project_watchers')->where('project_id', $project->id)->pluck('user_id'))
            ->filter()
            ->unique()
            // A non-member cannot be "interested" — if they were removed from the
            // tracker, they lose the notification with the visibility.
            ->intersect($memberIds);

        $admins = User::where('role', UserRole::Admin)
            ->where('status', UserStatus::Active)
            ->pluck('id');

        $ids = $interested->merge($admins)->unique()->reject(fn ($id) => $id === $actor?->id);

        if ($ids->isEmpty()) {
            return [];
        }

        return User::whereIn('id', $ids)
            ->where('status', UserStatus::Active)
            // FR-7.7 per-tracker mute. In its own table rather than a column on
            // tracker_members, because admins have no membership row for most trackers
            // and would otherwise have no way to mute the ones flooding them.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('user_tracker_mutes')
                ->whereColumn('user_tracker_mutes.user_id', 'users.id')
                ->where('user_tracker_mutes.tracker_id', $project->tracker_id))
            ->get()
            ->all();
    }

    /**
     * Insert, or COALESCE into an existing pending row.
     *
     * FR-7.8: three drags of the same card inside the window must produce one email
     * describing the net move, not three. The database enforces it with a unique index
     * on a generated column that is non-NULL only while status='pending', so the second
     * write collides and is merged here rather than inserted.
     *
     * Merging keeps the ORIGINAL from_step and the LATEST to_step, so the email
     * describes the net movement — which is what a reader actually wants, and is why
     * Laravel's ShouldBeUnique is not sufficient: it discards later payloads instead of
     * merging them.
     */
    private function write(User $recipient, string $eventType, Project $project, array $payload): bool
    {
        $prefs = DB::table('notification_preferences')->where('user_id', $recipient->id)->first();

        // Digest defaults ON for admins. They receive every step change in every
        // tracker, so they are the only people guaranteed to drown — the release valve
        // should be their default rather than something they discover after the Gmail
        // cap is hit.
        $mode = $prefs->delivery_mode
            ?? ($recipient->role === UserRole::Admin ? 'digest' : 'immediate');

        $availableAt = $mode === 'digest'
            ? $this->nextDigestSlot($recipient, (int) ($prefs->digest_hour ?? 8))
            : now()->addMinutes((int) config('worktrack.notifications.coalesce_minutes', 5));

        $row = [
            'user_id' => $recipient->id,
            'tracker_id' => $project->tracker_id,
            'project_id' => $project->id,
            'event_type' => $eventType,
            'payload' => json_encode($payload),
            'delivery_mode' => $mode,
            'status' => 'pending',
            'available_at' => $availableAt,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            DB::table('notification_outbox')->insert($row);

            return true;
        } catch (UniqueConstraintViolationException) {
            $existing = DB::table('notification_outbox')
                ->where('user_id', $recipient->id)
                ->where('event_type', $eventType)
                ->where('project_id', $project->id)
                ->where('status', 'pending')
                ->first();

            if ($existing === null) {
                return false;   // raced with the dispatcher; it already went out
            }

            $previous = json_decode($existing->payload, true) ?: [];
            $merged = $this->merge($eventType, $previous, $payload);

            DB::table('notification_outbox')->where('id', $existing->id)->update([
                'payload' => json_encode($merged),
                'updated_at' => now(),
                // available_at is NOT pushed back: a card dragged repeatedly must not be
                // able to defer its own notification indefinitely.
            ]);

            return false;
        }
    }

    /**
     * How two pending events for the same (user, type, project) become one.
     *
     * The coalesce key is deliberately coarse — user:event_type:project — so what
     * "merging" MEANS has to depend on the event, and getting that wrong loses
     * information silently rather than loudly.
     *
     * PUBLIC because OutboxDispatcher needs the identical rules on the way back OUT:
     * requeueing a failed row can collide with a newer pending row for the same key, and
     * resolving that by any other rule would mean two places in the system disagreeing
     * about what one email is supposed to contain.
     *
     * @param  array<string, mixed>  $previous  the pending row's payload
     * @param  array<string, mixed>  $incoming  the event that just collided with it
     * @return array<string, mixed>
     */
    public function merge(string $eventType, array $previous, array $incoming): array
    {
        $merged = $incoming;
        $merged['coalesced'] = ($previous['coalesced'] ?? 1) + 1;

        return match ($eventType) {
            // FR-7.8 — keep where it started, take where it ended up, so the email
            // describes the NET move rather than the last twitch of someone tidying.
            'project.moved' => [
                ...$merged,
                'from_step' => $previous['from_step'] ?? $incoming['from_step'] ?? null,
            ],

            // Task assignments ACCUMULATE. Taking only the latest, as a step move
            // does, would mean being handed two tasks in one afternoon and only ever
            // hearing about the second — the first would be assigned, visible on the
            // board, and never announced to the person expected to do it. The email
            // lists them instead.
            'task.assigned' => [
                ...$merged,
                'tasks' => array_values(array_unique([
                    ...($previous['tasks'] ?? array_filter([$previous['task_title'] ?? null])),
                    ...array_filter([$incoming['task_title'] ?? null]),
                ])),
            ],

            // Mentions accumulate for the same reason, and carry an excerpt each so
            // the digest is readable without opening every card.
            'comment.mentioned' => [
                ...$merged,
                'mentions' => [
                    ...($previous['mentions'] ?? []),
                    ...($incoming['mentions'] ?? []),
                ],
            ],

            // Everything else: latest wins. project.stalled is the motivating case —
            // two stall notices for one project say exactly the same thing.
            default => $merged,
        };
    }

    private function nextDigestSlot(User $recipient, int $hour): Carbon
    {
        $tz = $recipient->timezone ?: config('worktrack.default_timezone');
        $slot = now($tz)->setTime($hour, 0);

        if ($slot->isPast()) {
            $slot->addDay();
        }

        return $slot->utc();
    }
}
