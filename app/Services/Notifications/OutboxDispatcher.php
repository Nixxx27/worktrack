<?php

namespace App\Services\Notifications;

use App\Authorization\UserContext;
use App\Enums\UserRole;
use App\Mail\WorktrackNotification;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use stdClass;
use Throwable;

/**
 * Drains the notification outbox — the half of FR-7 that turns intent into mail.
 *
 * OutboxWriter records WHAT should be sent, inside the transaction that caused it.
 * This class decides WHETHER it may still be sent, batches it, and sends it. Until it
 * existed, every step change, approval, mention and stall notice accumulated in
 * `notification_outbox` for ever and no user ever received anything.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * WHY THE MAIL IS SENT SYNCHRONOUSLY HERE, AND NOT PUSHED ONTO THE QUEUE.
 *
 * FR-7.5 ("all mail is queued, never sent inline during a web request") is satisfied
 * by the outbox itself: the web request writes a row and returns. This dispatcher is a
 * scheduled console process, not a web request, so handing each row to Laravel's queue
 * as well would buy nothing and cost two things.
 *
 * It would reintroduce a dual-write. The row's status and the queued job would be two
 * records of one intent, and a crash between them loses or duplicates mail with no
 * single place to look — which is exactly the failure the outbox was introduced to
 * remove (VERIFICATION.md data-model-3).
 *
 * And it would put rendering inside Laravel's own SendQueuedMailable, which
 * application code cannot wrap in an AccessContext (see UserContext's docblock). Any
 * mail view touching a scoped relation would then throw inside the framework's job
 * rather than here, where the context is bound and the failure is recorded on the row.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * ONE EMAIL PER RECIPIENT PER RUN — including for `immediate` recipients.
 *
 * FR-7.7 reads as though `immediate` means one message per event. It does not here:
 * `delivery_mode` decides WHEN a row becomes due (coalesce window vs digest hour), and
 * this class then sends everything one person is owed at that moment as a single mail.
 *
 * The stall sweep is what settles it. A nightly run flags twelve idle projects, and the
 * owner of nine of them is one person: per-event sending puts nine near-identical
 * emails in their inbox inside a second, against a Gmail cap that ARCHITECTURE.md §8
 * already names as the notification design's central risk. Nine messages are also
 * strictly worse to read than one list of nine.
 * ═══════════════════════════════════════════════════════════════════════════════
 */
class OutboxDispatcher
{
    /** @var array<int,string|null> memoized tracker id => public_id, for deep links */
    private array $trackerPublicIds = [];

    public function __construct(private OutboxWriter $writer) {}

    /**
     * Send everything currently due.
     *
     * @return array{reclaimed:int,recipients:int,emails:int,sent:int,suppressed:int,failed:int,requeued:int,cap_reached:bool}
     */
    public function run(bool $dryRun = false, ?int $recipientLimit = null): array
    {
        $report = [
            'reclaimed' => 0,
            'recipients' => 0,
            'emails' => 0,
            'sent' => 0,
            'suppressed' => 0,
            'failed' => 0,
            'requeued' => 0,
            'cap_reached' => false,
        ];

        if ($dryRun) {
            // Nothing is claimed, nothing is reclaimed, no status moves: the whole point
            // is to size the blast before the first real run, exactly as
            // `worktrack:detect-stalled --dry-run` exists to size the first stall sweep.
            $report['recipients'] = count($this->dueRecipientIds($recipientLimit ?? $this->batchSize()));
            $report['emails'] = $report['recipients'];
            $report['cap_reached'] = $this->sentToday() >= $this->dailyCap();

            return $report;
        }

        $report['reclaimed'] = $this->reclaimStuck();

        if ($this->sentToday() >= $this->dailyCap()) {
            $report['cap_reached'] = true;

            // Loud, because the alternative is mail quietly stopping — NFR-R1's
            // failure mode. The rows stay pending and keep showing up in the
            // FR-10.5 health counts until someone raises the cap or volume drops.
            Log::warning('Worktrack notification daily send cap reached; outbox left pending.', [
                'cap' => $this->dailyCap(),
                'sent_today' => $this->sentToday(),
            ]);

            return $report;
        }

        foreach ($this->dueRecipientIds($recipientLimit ?? $this->batchSize()) as $userId) {
            $report['recipients']++;

            $rows = $this->claimFor($userId);

            if ($rows->isEmpty()) {
                continue;   // another worker got there first
            }

            $recipient = User::find($userId);

            if ($recipient === null) {
                // users are never deleted (AUTH-D27), so this is a broken invariant
                // rather than an expected branch — close the rows, don't loop on them.
                $rows->each(fn ($row) => $this->suppress($row, 'no_such_user'));
                $report['suppressed'] += $rows->count();

                continue;
            }

            [$sendable, $suppressed] = $this->partitionByAuthorization($rows, $recipient);
            $report['suppressed'] += $suppressed;

            if ($sendable->isEmpty()) {
                continue;
            }

            try {
                $this->deliver($recipient, $sendable);

                $sendable->each(fn ($row) => $this->markSent($row));
                $report['emails']++;
                $report['sent'] += $sendable->count();
            } catch (Throwable $e) {
                foreach ($sendable as $row) {
                    $this->recordFailure($row, $e) === 'failed'
                        ? $report['failed']++
                        : $report['requeued']++;
                }
            }

            if ($this->sentToday() >= $this->dailyCap()) {
                $report['cap_reached'] = true;
                break;
            }
        }

        return $report;
    }

    /**
     * FR-10.5 — the counts the admin queue-health screen reads.
     *
     * @return array<string,int>
     */
    public function health(): array
    {
        $byStatus = DB::table('notification_outbox')
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending' => (int) ($byStatus['pending'] ?? 0),
            'due' => (int) DB::table('notification_outbox')
                ->where('status', 'pending')->where('available_at', '<=', now())->count(),
            'sending' => (int) ($byStatus['sending'] ?? 0),
            'failed' => (int) ($byStatus['failed'] ?? 0),
            'suppressed' => (int) ($byStatus['suppressed'] ?? 0),
            'sent_today' => $this->sentToday(),
            'daily_cap' => $this->dailyCap(),
        ];
    }

    /**
     * Rows abandoned in 'sending' by a process that died mid-send.
     *
     * Their coalesce_key is NULL while they sit there — the generated column is only
     * non-NULL for pending rows — so they are invisible to both the poller AND to
     * coalescing, and would otherwise never be looked at again by anything.
     */
    private function reclaimStuck(): int
    {
        $cutoff = now()->subMinutes((int) config('worktrack.notifications.reclaim_after_minutes', 15));

        $stuck = DB::table('notification_outbox')
            ->where('status', 'sending')
            ->where('updated_at', '<', $cutoff)
            ->get();

        foreach ($stuck as $row) {
            $this->requeue($row, now(), (int) $row->attempts, 'reclaimed after being stuck in sending');
        }

        return $stuck->count();
    }

    /**
     * Recipients with something due, oldest waiting first.
     *
     * Grouped by user rather than paged by row, because the unit of work below is a
     * whole person's mail: paging by row would split one recipient's digest across two
     * runs and send them two emails describing one batch.
     *
     * @return array<int,int>
     */
    private function dueRecipientIds(int $limit): array
    {
        return DB::table('notification_outbox')
            ->where('status', 'pending')
            ->where('available_at', '<=', now())
            ->groupBy('user_id')
            ->orderByRaw('MIN(available_at)')
            ->limit($limit)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Take ownership of everything due for one recipient.
     *
     * SKIP LOCKED so two workers (or an overlapping schedule) drain different
     * recipients instead of one waiting on the other's row locks. The transition to
     * 'sending' is what makes the claim visible: it NULLs the generated coalesce_key,
     * so a new event arriving for the same project now opens a fresh pending row rather
     * than merging into one already on its way out of the door.
     *
     * @return Collection<int,stdClass>
     */
    private function claimFor(int $userId): Collection
    {
        return DB::transaction(function () use ($userId) {
            $rows = DB::table('notification_outbox')
                ->where('user_id', $userId)
                ->where('status', 'pending')
                ->where('available_at', '<=', now())
                ->orderBy('available_at')
                ->orderBy('id')
                ->lock('for update skip locked')
                ->get();

            if ($rows->isEmpty()) {
                return $rows;
            }

            DB::table('notification_outbox')
                ->whereIn('id', $rows->pluck('id'))
                ->update(['status' => 'sending', 'updated_at' => now()]);

            return $rows;
        });
    }

    /**
     * VERIFICATION.md data-model-2 — re-assert authorization immediately before send.
     *
     * Recipients were resolved when the event happened; a digest row can be up to 24
     * hours older than its delivery. In that window someone can be removed from the
     * tracker, suspended, or mute it. Content is a snapshot on purpose. Authorization
     * must never be.
     *
     * @param  Collection<int,stdClass>  $rows
     * @return array{0:Collection<int,stdClass>,1:int}
     */
    private function partitionByAuthorization(Collection $rows, User $recipient): array
    {
        $prefs = DB::table('notification_preferences')->where('user_id', $recipient->id)->first();
        $mutedEvents = $prefs?->muted_events ? (json_decode($prefs->muted_events, true) ?: []) : [];

        $suppressed = 0;

        $sendable = $rows->filter(function (stdClass $row) use ($recipient, $mutedEvents, &$suppressed) {
            $reason = $this->suppressionReason($row, $recipient, $mutedEvents);

            if ($reason === null) {
                return true;
            }

            $this->suppress($row, $reason);
            $suppressed++;

            return false;
        })->values();

        return [$sendable, $suppressed];
    }

    /**
     * @param  array<int,string>  $mutedEvents
     */
    private function suppressionReason(stdClass $row, User $recipient, array $mutedEvents): ?string
    {
        if (! $recipient->isActive()) {
            return 'not_active';
        }

        if (in_array($row->event_type, $mutedEvents, true)) {
            return 'muted';
        }

        // Signup and approval mail carries no tracker, so there is no membership to
        // re-assert — and requiring one would make exactly the mail a brand-new user
        // needs unsendable.
        if ($row->tracker_id === null) {
            return null;
        }

        $isMember = DB::table('tracker_members')
            ->where('tracker_id', $row->tracker_id)
            ->where('user_id', $recipient->id)
            ->exists();

        // Admins are the deliberate exception (D9/FR-7.3): they receive mail about
        // trackers they hold no membership row for, which is also why the per-tracker
        // mute below cannot live on tracker_members.
        if (! $isMember && $recipient->role !== UserRole::Admin) {
            return 'not_member';
        }

        $muted = DB::table('user_tracker_mutes')
            ->where('user_id', $recipient->id)
            ->where('tracker_id', $row->tracker_id)
            ->exists();

        return $muted ? 'muted' : null;
    }

    /**
     * Render and send one recipient's batch.
     *
     * Bound to the RECIPIENT's context, never SystemContext: this is per-user work, and
     * the bypass would hand a mail view cross-tracker visibility while it renders
     * content for one member (UserContext docblock, authorization-2). The views read
     * only the payload snapshot, so nothing here should need a scoped query — the
     * binding is what keeps that true when someone later adds one.
     *
     * The Mail FACADE rather than an injected Mailer, so the mailer is resolved at send
     * time: Mail::fake() in a test and a runtime transport swap both take effect.
     *
     * @param  Collection<int,stdClass>  $rows
     */
    private function deliver(User $recipient, Collection $rows): void
    {
        $timezone = $recipient->timezone ?: config('worktrack.default_timezone');

        $events = $rows->map(fn (stdClass $row) => [
            'type' => $row->event_type,
            'payload' => json_decode($row->payload, true) ?: [],
            // NFR-U5 — rendered in the reader's own timezone, not UTC.
            'occurred_at' => Carbon::parse($row->created_at)->setTimezone($timezone),
            'link' => $this->linkFor($row),
        ])->all();

        UserContext::runAs($recipient, function () use ($recipient, $events) {
            Mail::to($recipient->email, $recipient->name)
                ->send(new WorktrackNotification($recipient, $events));
        });
    }

    /**
     * FR-7.2 — "a direct link". Deep-links the card, not just the board: an email that
     * lands you on a board of forty cards has not taken you to the one it is about.
     */
    private function linkFor(stdClass $row): string
    {
        $payload = json_decode($row->payload, true) ?: [];
        $params = [];

        if ($row->tracker_id !== null) {
            $publicId = $this->trackerPublicId((int) $row->tracker_id);

            if ($publicId !== null) {
                $params['tracker'] = $publicId;
            }
        }

        if (! empty($payload['project_public_id'])) {
            $params['project'] = $payload['project_public_id'];
        }

        return route('board', $params);
    }

    /**
     * Read raw, deliberately: Tracker is scoped, and this runs before the recipient's
     * context is bound. Membership was re-asserted in suppressionReason() before we got
     * here, so the authorization has already happened — this is only fetching the
     * identifier that goes in the URL.
     */
    private function trackerPublicId(int $trackerId): ?string
    {
        return $this->trackerPublicIds[$trackerId] ??= DB::table('trackers')
            ->where('id', $trackerId)
            ->value('public_id');
    }

    private function markSent(stdClass $row): void
    {
        DB::table('notification_outbox')->where('id', $row->id)->update([
            'status' => 'sent',
            'sent_at' => now(),
            'attempts' => $row->attempts + 1,
            'updated_at' => now(),
        ]);
    }

    private function suppress(stdClass $row, string $reason): void
    {
        DB::table('notification_outbox')->where('id', $row->id)->update([
            'status' => 'suppressed',
            'suppressed_reason' => $reason,
            'updated_at' => now(),
        ]);
    }

    /**
     * FR-7.6 — retry with backoff, and when retries run out, fail LOUDLY and durably.
     *
     * @return string 'failed' once retries are exhausted, otherwise 'requeued'
     */
    private function recordFailure(stdClass $row, Throwable $e): string
    {
        $attempts = (int) $row->attempts + 1;
        $error = trim(class_basename($e).': '.$e->getMessage());

        if ($attempts >= (int) config('worktrack.notifications.max_attempts', 5)) {
            DB::table('notification_outbox')->where('id', $row->id)->update([
                'status' => 'failed',
                'attempts' => $attempts,
                'last_error' => $error,
                'updated_at' => now(),
            ]);

            // A permanently failed notification must be visible to admins rather than
            // silently dropped. The row is the record; the log is for whoever is
            // watching the process when it happens.
            Log::error('Worktrack notification permanently failed.', [
                'outbox_id' => $row->id,
                'user_id' => $row->user_id,
                'event_type' => $row->event_type,
                'attempts' => $attempts,
                'error' => $error,
            ]);

            return 'failed';
        }

        $this->requeue($row, $this->backoffFrom($attempts), $attempts, $error);

        return 'requeued';
    }

    private function backoffFrom(int $attempts): Carbon
    {
        $base = (int) config('worktrack.notifications.backoff_minutes', 5);
        $cap = (int) config('worktrack.notifications.backoff_cap_minutes', 720);

        return now()->addMinutes(min($base * (2 ** ($attempts - 1)), $cap));
    }

    /**
     * Put a claimed row back in the queue.
     *
     * The collision here is not hypothetical. coalesce_key is generated only while a row
     * is pending, so returning this row to 'pending' recomputes it — and if a NEW event
     * for the same (user, event_type, project) arrived while we held this one in
     * 'sending', that newer row already owns the key and the UPDATE violates
     * uk_outbox_coalesce.
     *
     * Marking this row failed would be wrong: its content would be lost even though a
     * perfectly good pending row exists for the same thing. So it is merged into the
     * live row using the same rules the writer applies to a fresh collision — keeping,
     * for a step move, the step it originally came from — and this row is closed as
     * superseded. The email still says everything both rows knew.
     */
    private function requeue(stdClass $row, Carbon $availableAt, int $attempts, string $error): void
    {
        try {
            DB::table('notification_outbox')->where('id', $row->id)->update([
                'status' => 'pending',
                'available_at' => $availableAt,
                'attempts' => $attempts,
                'last_error' => $error,
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $live = DB::table('notification_outbox')
                ->where('user_id', $row->user_id)
                ->where('event_type', $row->event_type)
                ->when(
                    $row->project_id === null,
                    fn ($q) => $q->whereNull('project_id'),
                    fn ($q) => $q->where('project_id', $row->project_id),
                )
                ->where('status', 'pending')
                ->first();

            if ($live === null) {
                // Raced with something that has since moved the other row on. Nothing
                // to merge into and no key left to collide with, so record the failure
                // rather than looping.
                DB::table('notification_outbox')->where('id', $row->id)->update([
                    'status' => 'failed',
                    'attempts' => $attempts,
                    'last_error' => $error.' (requeue collided with a row that then vanished)',
                    'updated_at' => now(),
                ]);

                return;
            }

            // $row is the OLDER event and $live the newer, which is the order
            // merge() expects: previous first, incoming second.
            $merged = $this->writer->merge(
                $row->event_type,
                json_decode($row->payload, true) ?: [],
                json_decode($live->payload, true) ?: [],
            );

            DB::table('notification_outbox')->where('id', $live->id)->update([
                'payload' => json_encode($merged),
                'updated_at' => now(),
            ]);

            $this->suppress($row, 'superseded');
        }
    }

    private function sentToday(): int
    {
        $startOfDay = Carbon::now(config('worktrack.default_timezone'))->startOfDay()->utc();

        return DB::table('notification_outbox')
            ->where('status', 'sent')
            ->where('sent_at', '>=', $startOfDay)
            ->count();
    }

    private function dailyCap(): int
    {
        return (int) config('worktrack.notifications.daily_send_cap', 400);
    }

    private function batchSize(): int
    {
        return (int) config('worktrack.notifications.batch_recipients', 50);
    }
}
