<?php

namespace App\Livewire;

use App\Models\Tracker;
use App\Services\Metrics\MetricsRepository;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * FR-8.5 — the owner's single pane of glass.
 *
 * Defaults to an all-trackers roll-up rather than making you visit each board in turn:
 * the whole reason this exists is to answer "where is everything stuck" in one look.
 * Filtering to one tracker is a choice, not a prerequisite.
 */
class Dashboard extends Component
{
    /** null = every tracker the viewer can see */
    public ?int $trackerFilter = null;

    #[Computed]
    public function trackers()
    {
        return Tracker::active()->orderBy('name')->get();
    }

    #[Computed]
    public function metrics(): array
    {
        $repo = app(MetricsRepository::class);
        $t = $this->trackerFilter;

        return [
            'aging' => $repo->aging(8, $t),
            'agingBuckets' => $repo->agingBuckets($t),
            'byStepType' => $repo->averageSecondsByStepType($t),
            'cycle' => $repo->cycleTime($t),
            'deadlines' => $repo->deadlines($t),
            'flow' => $repo->flowByMonth(6, $t),
            'inFlight' => $repo->inFlightAges($t),
            'onTime' => $repo->onTimeDelivery($t),
            'stalled' => $repo->stalled($t),
            'workload' => $repo->workload($t),
            'seesEverything' => $repo->seesEverything(),

            // Deliberately unfiltered and only rendered on the roll-up: a side-by-side
            // comparison of one tracker is not a comparison. See trackerScorecard().
            'scorecard' => $t === null ? $repo->trackerScorecard() : null,
        ];
    }

    public function updatedTrackerFilter(): void
    {
        unset($this->metrics);
    }

    public function render()
    {
        return view('livewire.dashboard');
    }
}
