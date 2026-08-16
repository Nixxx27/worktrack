<?php

namespace Database\Seeders;

use App\Authorization\SystemContext;
use App\Enums\HealthSource;
use App\Enums\ProjectHealth;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Project;
use App\Models\Step;
use App\Models\Tracker;
use App\Models\User;
use App\Services\Projects\MovementRecorder;
use App\Services\Projects\ProjectService;
use App\Services\Trackers\TrackerService;
use Illuminate\Database\Seeder;

/**
 * OPT-IN demo data, so the dashboard shows something meaningful before real work
 * accumulates. Run explicitly:
 *
 *     php artisan db:seed --class=DemoDataSeeder
 *
 * Deliberately NOT wired into DatabaseSeeder: `migrate --seed` must never silently
 * invent projects in a real installation. Refuses to run if trackers already exist.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', UserRole::Admin)->where('status', UserStatus::Active)->first();

        if ($admin === null) {
            $this->command->error('No active admin. Run: php artisan worktrack:user first-admin <email>');

            return;
        }

        if (SystemContext::run(fn () => Tracker::count()) > 0) {
            $this->command->warn('Trackers already exist — refusing to add demo data on top of real work.');

            return;
        }

        SystemContext::run(function () use ($admin) {
            $trackers = app(TrackerService::class);
            $projects = app(ProjectService::class);
            $movements = app(MovementRecorder::class);

            $it = $trackers->create([
                'name' => 'IT Technical',
                'description' => 'Networking, infrastructure and support',
            ], $admin);

            $sd = $trackers->create([
                'name' => 'Systems Development',
                'description' => 'Internal applications',
            ], $admin);

            $step = fn ($tracker, $name) => Step::where('tracker_id', $tracker->id)->where('name', $name)->firstOrFail();

            // Spread of ages and states so every dashboard panel has real content:
            // something stalled, something finished, something just started.
            $plan = [
                [$it, 'Core switch replacement — Alabang', 'In Progress', 23, 'stalled'],
                [$it, 'Firewall rule audit Q3', 'In Progress', 6, null],
                [$it, 'Branch VPN failover testing', 'In Progress', 12, 'at_risk'],
                [$it, 'Replace ageing UPS units', 'Backlog', 41, 'on_hold'],
                [$it, 'Wi-Fi survey — 3rd floor', 'New', 3, null],
                [$it, 'Email SPF/DKIM hardening', 'Done', 9, null],
                [$it, 'CCTV storage expansion', 'Backlog', 15, null],
                [$sd, 'Payroll system — phase 2', 'Build', 31, 'stalled'],
                [$sd, 'Customer portal rebuild', 'UAT', 8, null],
                [$sd, 'Mobile check-in app', 'Spec', 19, 'at_risk'],
                [$sd, 'Reporting API v2', 'Backlog', 5, null],
                [$sd, 'Helpdesk ticket sync', 'Released', 4, null],
            ];

            // Systems Development gets its own vocabulary, which is the whole point of
            // per-tracker steps — and why cross-tracker reporting groups by step TYPE.
            foreach ([['Spec', 'active', 1], ['Build', 'active', 2], ['UAT', 'active', 3], ['Released', 'terminal', 4]] as [$name, $type, $pos]) {
                Step::firstOrCreate(
                    ['tracker_id' => $sd->id, 'name' => $name],
                    ['type' => $type, 'position' => $pos],
                );
            }
            Step::where('tracker_id', $sd->id)->whereIn('name', ['New', 'In Progress', 'Done'])
                ->update(['archived_at' => now()]);

            foreach ($plan as [$tracker, $name, $stepName, $ageDays, $health]) {
                $project = $projects->create($tracker, ['name' => $name], $admin);

                Project::whereKey($project->id)->update(['created_at' => now()->subDays($ageDays + 4)]);

                if ($stepName !== 'Backlog') {
                    $movements->recordMove($project->fresh(), $step($tracker, $stepName), $admin);
                }

                $fresh = $project->fresh();
                $fresh->forceFill([
                    'current_step_entered_at' => now()->subDays($ageDays),
                    'last_activity_at' => now()->subDays($ageDays),
                    'health' => $health ? ProjectHealth::from($health) : ProjectHealth::OnTrack,
                    'health_source' => $health === 'on_hold' ? HealthSource::Manual : HealthSource::Auto,
                ])->save();
            }
        });

        $this->command->info('Demo data created: 2 trackers, 12 projects with a spread of ages and states.');
        $this->command->line('  Try: /dashboard  ·  /board');
    }
}
