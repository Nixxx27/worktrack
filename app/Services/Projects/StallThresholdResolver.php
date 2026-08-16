<?php

namespace App\Services\Projects;

use App\Enums\StepType;
use Illuminate\Support\Facades\DB;

/**
 * Resolves how many idle days make a project "stalled".
 *
 * Precedence is TRACKER > DEPARTMENT > GLOBAL (DD-18 / M-D12). FR-10.2 requires all
 * three levels but never states the order, and the order matters concretely: a
 * project in the "2026 Infrastructure" tracker carrying department "Networking"
 * resolves to the tracker's fuse, not Networking's. Tracker wins because the tracker
 * is the container whose workflow sets the pace, and because a project's department
 * may be inherited from the tracker default rather than chosen deliberately.
 *
 * Intake steps resolve through a parallel chain that falls back to the active one:
 * a backlog item sitting untouched for a week is normal, whereas work in progress
 * sitting untouched for a week is not.
 */
class StallThresholdResolver
{
    /** @var array<string,int> memoised per request — the nightly job asks thousands of times */
    private array $cache = [];

    public function forProject(object $project): int
    {
        $isIntake = ($project->current_step_type instanceof StepType
            ? $project->current_step_type
            : StepType::tryFrom((string) $project->current_step_type)) === StepType::Intake;

        $key = "{$project->tracker_id}:{$project->department_id}:".($isIntake ? 'i' : 'a');

        return $this->cache[$key] ??= $this->resolve(
            (int) $project->tracker_id,
            $project->department_id ? (int) $project->department_id : null,
            $isIntake,
        );
    }

    public function resolve(int $trackerId, ?int $departmentId, bool $isIntake): int
    {
        $tracker = DB::table('trackers')
            ->where('id', $trackerId)
            ->first(['stall_threshold_days', 'stall_intake_threshold_days']);

        if ($isIntake && $tracker?->stall_intake_threshold_days !== null) {
            return (int) $tracker->stall_intake_threshold_days;
        }

        if ($tracker?->stall_threshold_days !== null) {
            return (int) $tracker->stall_threshold_days;
        }

        if ($departmentId !== null) {
            $dept = DB::table('departments')->where('id', $departmentId)->value('stall_threshold_days');

            if ($dept !== null) {
                return (int) $dept;
            }
        }

        if ($isIntake && config('worktrack.stall.intake_threshold_days') !== null) {
            return (int) config('worktrack.stall.intake_threshold_days');
        }

        return (int) config('worktrack.stall.threshold_days', 7);
    }
}
