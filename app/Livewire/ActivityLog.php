<?php

namespace App\Livewire;

use App\Authorization\Capability;
use App\Authorization\CapabilityMatrix;
use App\Models\ProjectActivity;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * FR-9 — the review screen for everything that happened.
 *
 * This screen is load-bearing now that the capability matrix lets any member move and
 * edit any card on their trackers (see CapabilityMatrix). The control that replaced
 * the lock is the record, and a record nobody can read is not a control.
 *
 * TWO SOURCES, deliberately not merged (DD-10):
 *
 *   project_activities — what happened to work. Tracker-scoped, so every approved
 *   user sees exactly their own trackers' rows and nothing else. Isolation comes from
 *   TrackerVisibilityScope on the model, NOT from a where() written here, so a filter
 *   added to this screen a year from now inherits it.
 *
 *   audit_logs — what happened to the SYSTEM: logins, break-glass attempts, role
 *   changes, tracker and step configuration. Admin-only, gated by audit.view. Merging
 *   the two would put admin-only rows in a table members read, which turns the NFR-S3
 *   boundary from a table-level grant into a row-level filter — the shape of code
 *   where an isolation bug hides.
 */
class ActivityLog extends Component
{
    use WithPagination;

    /** Filters live in the URL so a view can be pasted into a message (FR-3.6). */
    #[Url(as: 'tracker', except: '')]
    public string $trackerId = '';

    #[Url(as: 'type', except: '')]
    public string $type = '';

    #[Url(as: 'who', except: '')]
    public string $actorId = '';

    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Admin-only second tab. Ignored entirely for everyone else. */
    #[Url(as: 'view', except: 'work')]
    public string $view = 'work';

    public function updated(string $property): void
    {
        // Any filter change invalidates the page cursor: staying on page 4 of a
        // result set that now has one page shows an empty screen that reads as
        // "nothing happened" rather than "you are past the end".
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['trackerId', 'type', 'actorId', 'from', 'to', 'search']);
        $this->resetPage();
    }

    public function canSeeAuditLog(): bool
    {
        return CapabilityMatrix::allows(auth()->user(), Capability::ViewAuditLog);
    }

    #[Computed]
    public function trackers()
    {
        return Tracker::active()->orderBy('name')->get(['id', 'public_id', 'name']);
    }

    /**
     * People who appear in the visible log, for the "who" filter.
     *
     * Derived from the scoped activity query rather than from the users table, so the
     * dropdown cannot name someone whose only presence is in a tracker the viewer
     * cannot see — the same leak the tracker switcher had (VERIFICATION.md
     * authorization-1), one layer down.
     */
    #[Computed]
    public function actors()
    {
        $ids = ProjectActivity::query()
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        return User::whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }

    /** The distinct activity types actually present, so the filter never offers a dead option. */
    #[Computed]
    public function types()
    {
        return ProjectActivity::query()->distinct()->orderBy('type')->pluck('type');
    }

    #[Computed]
    public function activities()
    {
        $query = ProjectActivity::query()
            ->with(['project:id,public_id,name,tracker_id', 'user:id,name', 'tracker:id,name'])
            ->latest('created_at')
            ->latest('id');   // stable order for rows sharing a timestamp

        if ($this->trackerId !== '') {
            // Resolved through the scope: an unseen public_id yields no tracker and
            // therefore no rows, rather than an error confirming it exists.
            $tracker = Tracker::where('public_id', $this->trackerId)->first();

            $query->where('tracker_id', $tracker?->id ?? 0);
        }

        if ($this->type !== '') {
            $query->where('type', $this->type);
        }

        if ($this->actorId !== '') {
            $query->where('user_id', (int) $this->actorId);
        }

        if ($this->from !== '') {
            $query->where('created_at', '>=', $this->from.' 00:00:00');
        }

        if ($this->to !== '') {
            $query->where('created_at', '<=', $this->to.' 23:59:59');
        }

        if ($this->search !== '') {
            // whereHas rather than a join: the related query inherits the visibility
            // scope, so searching cannot reach a project the viewer cannot see even
            // if the activity row's own tracker_id were somehow wrong.
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';

            $query->whereHas('project', fn ($q) => $q->where('name', 'like', $term));
        }

        return $query->paginate(50);
    }

    /**
     * The admin tab. Left as a query-builder read because audit_logs has no model by
     * design: it is deliberately exempt from TrackerVisibilityScope (AUTHZ-18), and
     * giving it an Eloquent model invites someone to attach the scope "for
     * consistency" and silently hide break-glass rows, which carry no tracker at all.
     */
    #[Computed]
    public function auditEntries()
    {
        if (! $this->canSeeAuditLog()) {
            return collect();
        }

        $rows = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_user_id')
            ->select('audit_logs.*', 'users.name as actor_name')
            ->when($this->actorId !== '', fn ($q) => $q->where('actor_user_id', (int) $this->actorId))
            ->when($this->from !== '', fn ($q) => $q->where('audit_logs.created_at', '>=', $this->from.' 00:00:00'))
            ->when($this->to !== '', fn ($q) => $q->where('audit_logs.created_at', '<=', $this->to.' 23:59:59'))
            ->when($this->search !== '', fn ($q) => $q->where('audit_logs.action', 'like', '%'.$this->search.'%'))
            ->orderByDesc('audit_logs.created_at')
            ->orderByDesc('audit_logs.id')
            ->limit(200)
            ->get();

        return $rows->map(function ($row) {
            $row->context_decoded = $row->context ? json_decode($row->context, true) : [];

            return $row;
        });
    }

    public function render()
    {
        return view('livewire.activity-log');
    }
}
