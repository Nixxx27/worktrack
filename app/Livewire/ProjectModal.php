<?php

namespace App\Livewire;

use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use App\Services\Projects\ProjectService;
use App\Services\Projects\TagService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The add/edit-a-project dialog.
 *
 * One component serves both, because they are the same form over the same fields and
 * splitting them guarantees they drift: the edit form grows a field the create form
 * never gets, and the two disagree about what a project is.
 *
 * Everything here is presentation and validation. Authorization is re-checked by the
 * Gate on every action, and the domain rules that matter — tracker membership for
 * owners and assignees, due-date ordering — are re-asserted in ProjectService, so a
 * crafted Livewire payload that skips this form still cannot produce a bad row.
 */
class ProjectModal extends Component
{
    /** public_id of the tracker being added to. Null when the modal is closed. */
    public ?string $trackerId = null;

    /** public_id of the project being edited. Null means this is a creation. */
    public ?string $projectId = null;

    public bool $open = false;

    // ── form ────────────────────────────────────────────────────────────────────
    public string $name = '';

    public string $description = '';

    public string $startDate = '';

    public string $dueDate = '';

    public string $priority = 'normal';

    public ?int $ownerId = null;

    /** @var list<int> */
    public array $assignees = [];

    /** @var list<string> */
    public array $tags = [];

    public string $tagInput = '';

    #[On('project-modal:create')]
    public function openForCreate(string $tracker): void
    {
        Gate::authorize('project.create');

        $this->reset(['projectId', 'name', 'description', 'priority', 'assignees', 'tags', 'tagInput']);
        $this->resetValidation();

        $this->trackerId = $tracker;

        // Today, and a fortnight out. The fields are required, so an empty pair would
        // make the fast path — type a name, press save — impossible, which is the
        // whole friction NFR-U1 warns about. Prefilled and editable keeps both.
        $this->startDate = now()->toDateString();
        $this->dueDate = now()->addWeeks(2)->toDateString();

        // The creator, because that is right far more often than blank is, and a
        // project with nobody accountable is the one the watchlist cannot chase.
        $this->ownerId = auth()->id();

        $this->open = true;
    }

    #[On('project-modal:edit')]
    public function openForEdit(string $project): void
    {
        // Resolves through the visibility scope: a public_id from a tracker this user
        // cannot see simply does not exist here, so they get a 404 rather than a 403
        // that would confirm it does.
        $model = Project::where('public_id', $project)->firstOrFail();

        Gate::authorize('update', $model);

        $this->resetValidation();

        $this->trackerId = $model->tracker->public_id;
        $this->projectId = $model->public_id;

        $this->name = $model->name;
        $this->description = (string) $model->description;
        // Projects created before the dates were required — and any created through
        // the service's one-field path — have neither. The start date is recoverable
        // from a fact (the project entered the system on its created_at), so it is
        // prefilled. A due date is NOT recoverable: it is a commitment somebody made,
        // and inventing one here would put a date on the board that no human ever
        // agreed to and that every lateness figure would then be computed against.
        // So it is left blank, and the required rule makes editing an old card the
        // moment someone finally states it.
        $this->startDate = ($model->start_date ?? $model->created_at)->toDateString();
        $this->dueDate = $model->target_date?->toDateString() ?? '';
        $this->priority = $model->priority;
        $this->ownerId = $model->owner_user_id;
        $this->assignees = $model->assignees()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $this->tags = $model->tags()->pluck('tags.name')->all();
        $this->tagInput = '';

        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->resetValidation();
    }

    #[Computed]
    public function tracker(): ?Tracker
    {
        return $this->trackerId
            ? Tracker::where('public_id', $this->trackerId)->first()
            : null;
    }

    #[Computed]
    public function project(): ?Project
    {
        return $this->projectId
            ? Project::where('public_id', $this->projectId)->first()
            : null;
    }

    /**
     * The people who may be picked as owner or assignee.
     *
     * FR-4.3: members of THIS tracker only, and only active accounts — a suspended
     * user cannot see the board, so assigning them work would be assigning it to
     * nobody while the card looks covered.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function members()
    {
        $tracker = $this->tracker;

        if (! $tracker) {
            return collect();
        }

        return User::query()
            ->whereIn('id', fn ($q) => $q->select('user_id')
                ->from('tracker_members')
                ->where('tracker_id', $tracker->id))
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /** Existing tags in this tracker, offered as an autocomplete rather than a fixed list. */
    #[Computed]
    public function suggestedTags()
    {
        $tracker = $this->tracker;

        return $tracker
            ? app(TagService::class)->forTracker($tracker)->pluck('name')->all()
            : [];
    }

    /**
     * Commit whatever is in the tag box as a chip.
     *
     * Splitting on comma means pasting "urgent, vendor, q3" does the obvious thing.
     * De-duplication is case-insensitive here as well as in TagService, so the chip
     * list cannot show "Urgent" and "urgent" side by side before it is even saved.
     */
    public function addTag(): void
    {
        foreach (preg_split('/,/', $this->tagInput) ?: [] as $candidate) {
            $tag = trim(preg_replace('/\s+/u', ' ', $candidate) ?? '');

            if ($tag === '' || mb_strlen($tag) > 40) {
                continue;
            }

            $seen = array_map('mb_strtolower', $this->tags);

            if (! in_array(mb_strtolower($tag), $seen, true)) {
                $this->tags[] = $tag;
            }
        }

        $this->tagInput = '';
    }

    /** By index rather than by value, so a tag containing quotes is still removable. */
    public function removeTag(int $index): void
    {
        unset($this->tags[$index]);

        $this->tags = array_values($this->tags);
    }

    public function save(ProjectService $projects): void
    {
        $tracker = $this->tracker;
        abort_unless($tracker !== null, 404);

        // Anything still sitting in the tag box counts — nobody expects a word they
        // typed to vanish because they clicked Save instead of pressing Enter.
        if (trim($this->tagInput) !== '') {
            $this->addTag();
        }

        $data = $this->validate();

        $this->projectId === null
            ? $this->createProject($projects, $tracker, $data)
            : $this->updateProject($projects, $data);
    }

    /**
     * Owner and assignees are validated against the member list computed above rather
     * than with `exists:`, which would run outside the visibility scope and turn this
     * form into the existence oracle VERIFICATION.md authorization-5 describes.
     */
    protected function rules(): array
    {
        $memberIds = $this->members->pluck('id')->all();

        return [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],

            // Required by product decision, not by the schema — see ProjectService::create.
            'startDate' => ['required', 'date'],
            'dueDate' => ['required', 'date', 'after_or_equal:startDate'],

            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'ownerId' => ['required', 'integer', Rule::in($memberIds)],
            'assignees' => ['array', 'max:20'],
            'assignees.*' => ['integer', Rule::in($memberIds)],
            'tags' => ['array', 'max:12'],
            'tags.*' => ['string', 'max:40'],
        ];
    }

    protected function messages(): array
    {
        return [
            'dueDate.after_or_equal' => 'The due date cannot be before the start date.',
            'ownerId.required' => 'Pick an owner — someone has to be accountable for this.',
            'ownerId.in' => 'The owner must be a member of this tracker.',
            'assignees.*.in' => 'You can only assign people who are members of this tracker.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'name' => 'title',
            'startDate' => 'start date',
            'dueDate' => 'due date',
            'ownerId' => 'owner',
        ];
    }

    private function createProject(ProjectService $projects, Tracker $tracker, array $data): void
    {
        Gate::authorize('project.create');

        $project = $projects->create($tracker, $this->attributes($data), auth()->user());

        $this->open = false;

        $this->dispatch('project-saved', project: $project->public_id);
    }

    private function updateProject(ProjectService $projects, array $data): void
    {
        $project = $this->project;
        abort_unless($project !== null, 404);

        Gate::authorize('update', $project);

        $projects->update($project, $this->attributes($data), auth()->user());

        $this->open = false;
        unset($this->project);

        $this->dispatch('project-saved', project: $project->public_id);
    }

    /** Form field names to the service's attribute names, in one place. */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'] !== '' ? $data['description'] : null,
            'start_date' => $data['startDate'],
            'target_date' => $data['dueDate'],
            'priority' => $data['priority'],
            'owner_user_id' => $data['ownerId'],
            'assignees' => $data['assignees'] ?? [],
            'tags' => $data['tags'] ?? [],
        ];
    }

    public function render()
    {
        return view('livewire.project-modal');
    }
}
