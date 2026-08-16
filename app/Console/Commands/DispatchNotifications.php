<?php

namespace App\Console\Commands;

use App\Services\Notifications\OutboxDispatcher;
use Illuminate\Console\Command;

/**
 * FR-7 — the process that actually sends the mail.
 *
 * Deliberately NOT wrapped in SystemContext, unlike the stall sweep. This command is not
 * tracker-agnostic work: every email it sends belongs to one recipient, and the
 * dispatcher opens a UserContext per recipient for exactly that reason. Wrapping the
 * whole run in the all-trackers bypass would hand a mail view cross-tracker visibility
 * while it renders content for a single member (authorization-2).
 *
 * PRODUCTION REQUIREMENT: nothing is sent unless `php artisan schedule:run` is invoked
 * every minute (OQ10). If it is not, the outbox fills silently — which is the NFR-R1
 * failure mode, and why `--health` prints the counts an admin needs to see it happening.
 */
class DispatchNotifications extends Command
{
    protected $signature = 'worktrack:dispatch-notifications
                            {--dry-run : report what is due without claiming or sending anything}
                            {--health : print the outbox counts (FR-10.5) and exit}
                            {--limit= : recipients to drain this run, overriding the configured batch}';

    protected $description = 'Send everything due in the notification outbox';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        if ($this->option('health')) {
            return $this->reportHealth($dispatcher);
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $report = $dispatcher->run((bool) $this->option('dry-run'), $limit);

        if ($this->option('dry-run')) {
            $this->warn("  DRY RUN — nothing claimed or sent. {$report['emails']} email(s) "
                ."would go to {$report['recipients']} recipient(s).");

            if ($report['cap_reached']) {
                $this->error('  The daily send cap is already reached — a real run would send nothing.');
            }

            return self::SUCCESS;
        }

        $this->line("  {$report['emails']} email(s) sent to {$report['recipients']} recipient(s), "
            ."covering {$report['sent']} outbox row(s).");

        if ($report['reclaimed'] > 0) {
            // Worth surfacing every time rather than only in verbose output: a run that
            // routinely reclaims rows means something is dying mid-send, and the
            // at-least-once trade means those recipients may be seeing duplicates.
            $this->warn("  {$report['reclaimed']} row(s) reclaimed from a previous run that died mid-send.");
        }

        if ($report['suppressed'] > 0) {
            $this->line("  {$report['suppressed']} row(s) suppressed — recipient no longer entitled, or muted.");
        }

        if ($report['requeued'] > 0) {
            $this->warn("  {$report['requeued']} row(s) failed and will retry with backoff.");
        }

        if ($report['failed'] > 0) {
            $this->error("  {$report['failed']} row(s) permanently failed after exhausting retries (FR-7.6).");
        }

        if ($report['cap_reached']) {
            $this->error('  Daily send cap reached. Remaining rows stay pending and will show '
                .'in --health until volume drops or the cap is raised.');
        }

        return self::SUCCESS;
    }

    private function reportHealth(OutboxDispatcher $dispatcher): int
    {
        $health = $dispatcher->health();

        $this->table(
            ['Metric', 'Count'],
            collect($health)->map(fn ($value, $key) => [
                str_replace('_', ' ', $key),
                $value,
            ])->values()->all(),
        );

        if ($health['failed'] > 0) {
            $this->error('  Permanently failed notifications are present. FR-7.6 requires these '
                .'be visible to an admin rather than silently dropped.');
        }

        if ($health['due'] > 0) {
            $this->warn("  {$health['due']} row(s) are due right now. If this number only grows, "
                .'the scheduler is not running (NFR-R1).');
        }

        return self::SUCCESS;
    }
}
