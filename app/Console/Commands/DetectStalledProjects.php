<?php

namespace App\Console\Commands;

use App\Authorization\SystemContext;
use App\Enums\HealthSource;
use App\Enums\ProjectHealth;
use App\Enums\StepType;
use App\Models\Project;
use App\Services\Notifications\OutboxWriter;
use App\Services\Projects\ActivityRecorder;
use App\Services\Projects\StallThresholdResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-4.6 / FR-8.3 / US-4 — the job that makes "nothing quietly rots" true.
 *
 * This is the product's central promise: staff log work and the system notices on its
 * own when something stops moving. Everything else on the dashboard is derived from
 * history; this is the one place the system forms an opinion.
 *
 * Runs under SystemContext because it is genuinely tracker-agnostic — it must sweep
 * every tracker, and there is no user on whose behalf it acts.
 */
class DetectStalledProjects extends Command
{
    protected $signature = 'worktrack:detect-stalled
                            {--dry-run : report what would change without writing anything}';

    protected $description = 'Flag projects with no activity past their stall threshold';

    public function handle(
        StallThresholdResolver $thresholds,
        ActivityRecorder $activity,
        OutboxWriter $outbox,
    ): int {
        $dryRun = (bool) $this->option('dry-run');

        // ═══════════════════════════════════════════════════════════════════════════
        // The ENTIRE body runs inside SystemContext, not just the candidate query.
        //
        // The first version wrapped only the query, then touched $project->tracker and
        // $project->fresh() in the loop below — both scoped reads, both outside any
        // bound context, both throwing MissingAccessContextException. The guard caught
        // it on the first real run against seeded data.
        //
        // That is the hazard VERIFICATION.md authorization-2 describes: background work
        // touching scoped models with no context. It is easy to "fix" by wrapping only
        // the line that threw, which leaves the next lazy relation load to fail later,
        // somewhere less obvious. Wrapping the whole unit of work is the honest fix.
        // ═══════════════════════════════════════════════════════════════════════════
        return SystemContext::run(fn () => $this->sweep($thresholds, $activity, $outbox, $dryRun));
    }

    private function sweep(
        StallThresholdResolver $thresholds,
        ActivityRecorder $activity,
        OutboxWriter $outbox,
        bool $dryRun,
    ): int {
        // Candidates, narrowed in SQL as far as it goes. The per-project threshold has
        // to be resolved in PHP because it depends on tracker and department overrides,
        // so the predicate itself cannot be pushed down.
        $candidates = Project::query()
            ->whereNull('archived_at')
            // FR-4.6 — On Hold is always deliberate and SUPPRESSES auto-stall. A planned
            // pause is not rot, and flagging it would make the watchlist untrustworthy.
            ->where('health', '!=', ProjectHealth::OnHold)
            // Terminal work cannot stall: it is finished. Without this the watchlist
            // fills with completed projects nobody will ever touch again.
            ->where('current_step_type', '!=', StepType::Terminal)
            ->whereHas('tracker', fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('last_activity_at')
            // Eager-loaded so the reporting loop below performs no lazy relation reads
            // — the exact thing that blew up when only the query was context-wrapped.
            ->with('tracker:id,name')
            ->get();

        $flagged = 0;
        $notified = 0;
        $rows = [];

        foreach ($candidates as $project) {
            $days = (int) $thresholds->forProject($project);

            // Elapsed SECONDS, not a count of calendar days (M-D18): "7 days idle" must
            // mean 7×86400, otherwise a project touched at 23:00 counts as idle a day
            // later at 00:01 and the threshold silently becomes 6 days.
            $idleSeconds = $project->last_activity_at->diffInSeconds(now());

            if ($idleSeconds < $days * 86400) {
                continue;
            }

            $alreadyStalled = $project->health === ProjectHealth::Stalled;
            $rows[] = [
                $project->tracker?->name,
                Str::limit($project->name, 38),
                intdiv($idleSeconds, 86400).'d',
                $days.'d',
                $alreadyStalled ? 'already' : 'NEW',
            ];

            if ($dryRun) {
                continue;
            }

            if (! $alreadyStalled) {
                DB::transaction(function () use ($project, $activity, $idleSeconds, &$flagged) {
                    $project->forceFill([
                        'health' => ProjectHealth::Stalled,
                        'health_source' => HealthSource::Auto,
                        'health_set_at' => now(),
                        'health_reason' => 'No activity for '.intdiv($idleSeconds, 86400).' days',
                    ])->save();

                    // Recorded in the feed but explicitly NOT as activity — counting the
                    // stall job's own write would immediately un-stall everything it just
                    // flagged, and the detector would never appear to do anything.
                    $activity->record($project, 'stall_detected', null, [
                        'idle_days' => intdiv($idleSeconds, 86400),
                    ], countsAsActivity: false);

                    $flagged++;
                });
            }

            // One email per stall EPISODE, not one per night for six weeks. Cleared
            // whenever health leaves 'stalled', so a re-stall notifies again.
            if ($project->fresh()->stall_notified_at === null) {
                DB::transaction(function () use ($project, $outbox, &$notified) {
                    $outbox->queueProjectStalled($project->fresh());
                    $project->forceFill(['stall_notified_at' => now()])->save();
                    $notified++;
                });
            }
        }

        if ($rows !== []) {
            $this->table(['Tracker', 'Project', 'Idle', 'Threshold', 'State'], $rows);
        }

        $this->newLine();

        if ($dryRun) {
            // FR-13 risk register: the FIRST real run will flag every pre-existing and
            // imported project at once and email about all of them against the Gmail cap.
            // Sizing that blast before it ships is the whole reason --dry-run exists.
            $this->warn('  DRY RUN — nothing written. '.count($rows).' project(s) are past their threshold.');
        } else {
            $this->info("  {$flagged} newly stalled, {$notified} notification batch(es) queued, ".count($candidates).' candidate(s) examined.');
        }

        return self::SUCCESS;
    }
}
