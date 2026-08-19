<?php

namespace App\Livewire;

use App\Enums\ProjectHealth;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\Attachments\AttachmentRejected;
use App\Services\Attachments\AttachmentService;
use App\Services\Projects\CommentService;
use App\Services\Projects\HealthService;
use App\Services\Projects\ProjectService;
use App\Services\Projects\TagService;
use App\Services\Projects\TaskService;
use App\Services\Projects\WatcherService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The card detail panel — FR-4.5.
 *
 * "Opening a card shows details, tasks, attachments, comments, and full movement
 * history." Everything on this screen already existed in the schema and had no way in.
 *
 * Every action re-checks its own policy on the request that performs it. The view
 * hides controls the user cannot use, but that is presentation: a crafted Livewire
 * call reaches the same authorize() as a click.
 */
class ProjectDrawer extends Component
{
    use WithFileUploads;

    /**
     * Matches ProjectModal's `tags` rule. Enforced in both places because the two
     * are separate seams onto the same relation, and a limit only one of them knows
     * about is a limit that does not exist.
     */
    private const MAX_LABELS = 12;

    /**
     * Three tabs, and each one holds a LIST of things.
     *
     * There used to be a fourth, "Details", and it was the odd one out: a page of
     * static fields sitting next to tabs of collections, four of whose six fields
     * were already on screen in the summary strip above it. What was genuinely only
     * there — the description, the priority, the health control — has moved out to
     * where it is always visible. Nothing about a card now costs a click to read.
     */
    private const TABS = ['tasks', 'files', 'history'];

    public ?string $projectId = null;

    public bool $open = false;

    /** tasks | files | history — comments are the right rail, not a tab. */
    public string $tab = 'tasks';

    /**
     * Whether the health control is showing under the status pill in the header.
     *
     * Closed by default and anchored to the pill, because the pill is where the
     * health is already read: changing a value somewhere other than where it is
     * displayed is how people stop believing the two are the same thing.
     */
    public bool $healthEditor = false;

    // ── labels ──────────────────────────────────────────────────────────────────
    public bool $labelPicker = false;

    public string $labelSearch = '';

    /**
     * The swatch chosen for the label about to be created, or null for "whatever
     * the rotation would have given it" — which is what the picker shows selected,
     * so the preview is honest before anyone touches a swatch.
     */
    public ?string $labelColor = null;

    /** Which existing label has its palette open, if any. One at a time. */
    public ?int $recoloringTagId = null;

    /**
     * Whether the right rail shows the activity trail alongside the comments.
     *
     * ON by default, and remembered. This started OFF on the reasoning that the trail
     * is generated while comments are written, so the trail always wins on volume and
     * buries the remarks that carry the actual state of the work. True, but it costs
     * the wrong thing: what a card has BEEN THROUGH — reprioritised, reassigned,
     * retagged, rescheduled — is most of why anyone opens the drawer, and a default that
     * hides it makes the common reading a click away while protecting a minority of noisy
     * cards. The noisiest source is no longer in here anyway: step moves belong to the
     * History tab now (FEED_HIDDEN_TYPES), which is what makes on-by-default cheap.
     *
     * #[Session] is what makes the default survivable in both directions. Whichever
     * way a person sets it, it stays set across reloads instead of springing back on
     * every refresh, so anyone who does find the trail noisy turns it off once rather
     * than once per page load. It rides the session rather than a users column: it is
     * a view preference, not a fact about the person, and it is not worth a migration.
     * The cost is that it resets after the session expires — the toggle is right
     * there, and the default is now the one most people want anyway.
     */
    #[Session('drawer.activity')]
    public bool $showActivity = true;

    // ── task composer ───────────────────────────────────────────────────────────
    public string $taskTitle = '';

    public ?int $taskAssignee = null;

    public string $taskDue = '';

    // ── comment composer ────────────────────────────────────────────────────────
    public string $commentBody = '';

    public ?int $editingCommentId = null;

    public string $editingCommentBody = '';

    // ── health ──────────────────────────────────────────────────────────────────
    public string $healthValue = '';

    public string $healthReason = '';

    /**
     * The files Livewire has finished staging in temporary local storage and that have
     * not yet been committed to R2.
     *
     * An ARRAY, and normally holding exactly one element. The uploader in the view
     * sends one file per request and this hook drains the array on each one, so the
     * "unlimited" in unlimited attachments is not a batch that grows without bound in
     * memory — it is a queue on the client feeding a one-at-a-time server loop.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $uploads = [];

    /**
     * Per-file rejection messages, kept as a list rather than a field error.
     *
     * A batch is not pass-or-fail. Selecting twelve files where one is a .exe must
     * attach eleven and say precisely which one was refused and why — addError() on a
     * single 'upload' key cannot express that, and a batch that fails wholesale because
     * of one bad file is how people go back to emailing attachments.
     *
     * @var array<int, string>
     */
    public array $uploadErrors = [];

    /** Enough to show what went wrong in a large batch without growing unbounded. */
    private const MAX_UPLOAD_ERRORS = 20;

    /**
     * FR-8.11 — open on first paint when the board was reached by a deep link.
     *
     * Lenient where openFor() is strict. openFor() answers a click on a card that is
     * on screen, so anything unresolvable there is a bug worth a 404; this answers a
     * URL that may have been bookmarked weeks ago, and a project since archived or
     * moved out of the reader's trackers should land them on the board rather than on
     * an error page. Silence here is not a swallowed failure — the drawer simply
     * stays shut, and the board behind it is the correct fallback.
     */
    public function mount(?string $project = null): void
    {
        if ($project === null) {
            return;
        }

        $model = Project::where('public_id', $project)->first();

        if ($model && Gate::allows('view', $model)) {
            $this->openFor($project);
        }
    }

    #[On('project-drawer:open')]
    public function openFor(string $project): void
    {
        // Resolves through the visibility scope, so a public_id from an invisible
        // tracker does not exist here — a 404, never a 403 that would confirm it does.
        $model = Project::where('public_id', $project)->firstOrFail();

        Gate::authorize('view', $model);

        $this->reset(['taskTitle', 'taskAssignee', 'taskDue', 'commentBody', 'editingCommentId', 'editingCommentBody', 'uploads', 'uploadErrors', 'labelPicker', 'labelSearch', 'healthEditor']);
        $this->resetValidation();

        $this->projectId = $model->public_id;
        $this->healthValue = $model->health->value;
        $this->healthReason = (string) $model->health_reason;
        $this->tab = 'tasks';
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->resetValidation();

        // Lets the board drop ?project= from the URL, so a refresh does not reopen a
        // drawer that was deliberately dismissed.
        $this->dispatch('project-drawer:closed');
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true)
            ? $tab
            : 'tasks';
    }

    public function toggleActivity(): void
    {
        $this->showActivity = ! $this->showActivity;

        unset($this->feed);
    }

    #[Computed]
    public function project(): ?Project
    {
        return $this->projectId
            ? Project::with(['owner:id,name', 'assignees:id,name', 'tags', 'step:id,name,type'])
                ->where('public_id', $this->projectId)
                ->first()
            : null;
    }

    #[Computed]
    public function tasks()
    {
        $project = $this->project;

        return $project
            ? Task::where('project_id', $project->id)
                ->with('assignee:id,name')
                ->orderBy('position')->orderBy('id')->get()
            : collect();
    }

    #[Computed]
    public function attachments()
    {
        $project = $this->project;

        // Only 'available' rows are ever listed (FR-6.8): a pending upload that died
        // mid-flight must not appear as a file somebody can try to open.
        return $project
            ? Attachment::where('project_id', $project->id)
                ->available()
                ->with('uploader:id,name')
                ->latest('id')->get()
            : collect();
    }

    #[Computed]
    public function comments()
    {
        $project = $this->project;

        // `mentions` is eager-loaded because every comment is rendered through it: the
        // snapshot of who was actually notified is what decides which @ in the body gets
        // highlighted, so a lazy relation here would be one query per comment on a
        // thread that is usually the longest thing on the screen.
        return $project
            ? Comment::where('project_id', $project->id)
                ->with(['author:id,name', 'mentions'])
                ->orderBy('created_at')->orderBy('id')->get()
            : collect();
    }

    /**
     * The names the @ picker offers, straight from the matcher's own member list.
     *
     * Deliberately not assembled here. A picker that builds its own list is a picker
     * that can offer somebody the resolver will not match — the original bug with a
     * menu in front of it — so this asks CommentService the same question a posted
     * comment asks it.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function mentionNames()
    {
        $project = $this->project;

        return $project
            ? app(CommentService::class)->mentionable($project)->pluck('name')->values()
            : collect();
    }

    /** FR-4.5 — the full movement history, which is never edited by users (FR-4.8). */
    #[Computed]
    public function movements()
    {
        $project = $this->project;

        return $project
            ? $project->movements()
                ->with(['fromStep:id,name', 'toStep:id,name', 'movedBy:id,name'])
                ->orderByDesc('seq')->get()
            : collect();
    }

    /**
     * Activity types the trail never renders, because this screen already says it better.
     *
     * 'comment' and 'comment_edited' are dropped because the comment itself is right there
     * in the same column: one remark would appear twice, once as itself and once as a line
     * reporting that it happened. 'comment_deleted' STAYS — with the comment gone, that row
     * is the only remaining trace that something was said and then removed.
     *
     * 'created' and 'step_move' go for the same reason against a different panel. The
     * History tab renders every one of them from project_step_movements, with the
     * origin step and how long the previous one held the project; the trail's version has
     * neither, so the two were the same events told twice and worse on this side. Only the
     * DISPLAY was doubled — the tables stay separate on purpose: project_step_movements is
     * the source of truth for every metric (FR-4.8) with a duration the database generates,
     * while this table is a narrative that also carries edits, tags, tasks and files.
     */
    private const FEED_HIDDEN_TYPES = ['comment', 'comment_edited', 'created', 'step_move'];

    /**
     * The trail, newest first, already narrowed to what the column will show.
     *
     * The filter belongs in SQL rather than after the fetch so the 50-row budget buys 50
     * lines that actually render. Rejecting in PHP would let a card dragged across the
     * board thirty times spend most of that budget on step_move rows and then throw them
     * away, leaving a trail that looks mysteriously short.
     */
    #[Computed]
    public function activities()
    {
        $project = $this->project;

        return $project
            ? $project->activities()
                ->whereNotIn('type', self::FEED_HIDDEN_TYPES)
                ->with('user:id,name')
                ->latest('id')->limit(50)->get()
            : collect();
    }

    /**
     * Comments and activity as ONE reverse-chronological column.
     *
     * Nothing is filtered here: activities() has already dropped the types this screen
     * shows elsewhere (see FEED_HIDDEN_TYPES), so one place decides what the trail says.
     *
     * @return Collection<int, array{kind: string, key: string, at: Carbon, comment?: Comment, entry?: ProjectActivity}>
     */
    #[Computed]
    public function feed()
    {
        $items = $this->comments->map(fn (Comment $comment) => [
            'kind' => 'comment',
            'key' => 'comment-'.$comment->id,
            'at' => $comment->created_at,
            'comment' => $comment,
        ]);

        if ($this->showActivity) {
            $items = $items->concat(
                $this->activities
                    ->map(fn ($entry) => [
                        'kind' => 'activity',
                        'key' => 'activity-'.$entry->id,
                        'at' => $entry->created_at,
                        'entry' => $entry,
                    ])
            );
        }

        return $items->sortByDesc(fn (array $item) => $item['at']->getTimestamp())->values();
    }

    /**
     * Every label defined in this tracker, for the picker.
     *
     * Filtered in PHP rather than SQL because the list is per-tracker and small, and
     * because the same case-insensitive comparison has to decide both what the picker
     * shows and whether "Create" is offered — two spellings of that test is how you
     * get a Create button that produces a duplicate of a tag already on the list.
     *
     * @return Collection<int, Tag>
     */
    #[Computed]
    public function trackerTags()
    {
        $project = $this->project;

        if (! $project) {
            return collect();
        }

        $tags = app(TagService::class)->forTracker($project->tracker);
        $needle = mb_strtolower(trim($this->labelSearch));

        return $needle === ''
            ? $tags
            : $tags->filter(fn (Tag $tag) => str_contains(mb_strtolower($tag->name), $needle))->values();
    }

    /**
     * The swatches, name => hex, for the picker.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function palette(): array
    {
        return TagService::PALETTE;
    }

    /**
     * The colour the label about to be created will actually get.
     *
     * Falls back to the rotation rather than to a fixed default, so the swatch shown
     * selected before anyone clicks is the colour the label would really receive —
     * a preview that lies about the untouched case is worse than none.
     */
    #[Computed]
    public function newLabelColor(): ?string
    {
        if ($this->labelColor !== null) {
            return $this->labelColor;
        }

        $project = $this->project;

        return $project ? app(TagService::class)->nextColor($project->tracker) : null;
    }

    /** Whether what is typed in the picker is a label that does not exist yet. */
    #[Computed]
    public function canCreateLabel(): bool
    {
        $typed = mb_strtolower(trim($this->labelSearch));

        if ($typed === '' || mb_strlen($typed) > 40) {
            return false;
        }

        $project = $this->project;

        return $project !== null
            && ! app(TagService::class)->forTracker($project->tracker)
                ->contains(fn (Tag $tag) => mb_strtolower($tag->name) === $typed);
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function members()
    {
        $project = $this->project;

        if (! $project) {
            return collect();
        }

        return User::query()
            ->whereIn('id', fn ($q) => $q->select('user_id')
                ->from('tracker_members')
                ->where('tracker_id', $project->tracker_id))
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function isWatching(): bool
    {
        $project = $this->project;

        return $project
            ? app(WatcherService::class)->isWatching($project, auth()->user())
            : false;
    }

    // ── labels ──────────────────────────────────────────────────────────────────

    /**
     * FR-4.2, from the card rather than from the edit dialog.
     *
     * Labelling is a one-second decision made while reading a card, and routing it
     * through the full edit form meant re-submitting the title, dates, owner and
     * assignees to add the word "urgent" — so in practice cards went untagged and
     * the tag filter described a board nobody was maintaining.
     *
     * Every one of these still goes through ProjectService::update rather than
     * touching the pivot directly, so the tags_changed activity row, the stall-clock
     * reset and the tracker-membership checks all happen exactly as they do from the
     * dialog. This is a second door onto the same room, not a second room.
     */
    public function openLabelPicker(): void
    {
        Gate::authorize('update', $this->requireProject());

        $this->reset(['labelSearch', 'labelColor', 'recoloringTagId']);
        $this->resetValidation();
        $this->labelPicker = true;
    }

    public function closeLabelPicker(): void
    {
        $this->labelPicker = false;
        $this->reset(['labelSearch', 'labelColor', 'recoloringTagId']);
        $this->resetValidation();
    }

    /** Pick the colour the label about to be created will carry. */
    public function chooseLabelColor(string $color): void
    {
        Gate::authorize('update', $this->requireProject());

        $this->validateColor($color);

        $this->labelColor = $color;
        $this->recoloringTagId = null;
    }

    /** Open (or close) the palette against one existing label. */
    public function toggleRecolor(int $tagId): void
    {
        Gate::authorize('update', $this->requireProject());

        $this->recoloringTagId = $this->recoloringTagId === $tagId ? null : $tagId;
        $this->resetValidation('labelSearch');
    }

    /**
     * Repaint an existing label, everywhere.
     *
     * Gated on updating THIS project rather than on administering the tracker,
     * which is the same call the rest of this picker makes and the same trade the
     * board makes everywhere else: anyone who can act on the board may, and the
     * activity trail says who did. Colour is a reading aid, not a permission — and
     * a palette only an Admin could reach would leave the six default colours in
     * place on every board that has no Admin sitting in it.
     */
    public function recolorLabel(int $tagId, string $color, TagService $tags): void
    {
        $project = $this->requireProject();

        Gate::authorize('update', $project);

        $this->validateColor($color);

        // Same scoping as toggleLabel: a tag id from a tracker this user can also
        // see is still not a label reachable from THIS card.
        $tag = Tag::whereKey($tagId)->where('tracker_id', $project->tracker_id)->firstOrFail();

        $tags->recolor($tag, $color);

        $this->recoloringTagId = null;

        $this->refreshProject();
    }

    public function toggleLabel(int $tagId, ProjectService $projects): void
    {
        $project = $this->requireProject();

        Gate::authorize('update', $project);

        // Scoped to the project's own tracker: a tag id from a tracker this user can
        // also see is still not a label that may be attached to THIS card.
        $tag = Tag::whereKey($tagId)->where('tracker_id', $project->tracker_id)->firstOrFail();

        $current = $project->tags->pluck('name');
        $attached = $this->contains($current, $tag->name);

        $next = $attached
            ? $current->reject(fn (string $name) => $this->same($name, $tag->name))->values()
            : $current->push($tag->name);

        if (! $attached && $next->count() > self::MAX_LABELS) {
            $this->addError('labelSearch', 'A card can carry at most '.self::MAX_LABELS.' labels.');

            return;
        }

        $projects->update($project, ['tags' => $next->all()], auth()->user());

        $this->refreshProject();
    }

    /** Create a label in this tracker and put it on this card in one action. */
    public function createLabel(ProjectService $projects, TagService $tags): void
    {
        $project = $this->requireProject();

        Gate::authorize('update', $project);

        $this->validate(
            ['labelSearch' => ['required', 'string', 'max:40']],
            attributes: ['labelSearch' => 'label'],
        );

        $current = $project->tags->pluck('name');

        if ($this->contains($current, $this->labelSearch)) {
            $this->reset(['labelSearch', 'labelColor']);

            return;
        }

        if ($current->count() + 1 > self::MAX_LABELS) {
            $this->addError('labelSearch', 'A card can carry at most '.self::MAX_LABELS.' labels.');

            return;
        }

        // Two steps, and deliberately so. define() is the only path a chosen colour
        // travels, and it normalises and de-duplicates case-insensitively — so a label
        // differing only in casing from an existing one resolves to that existing row
        // rather than creating a near-twin the filter list then has to carry.
        //
        // The attach still goes through ProjectService::update by NAME, exactly as
        // before, so the tags_changed activity row and the stall clock behave the same
        // whether the label was created here or picked off the list.
        $tag = $tags->define($project->tracker, $this->labelSearch, $this->labelColor, auth()->user());

        if ($tag === null) {
            return;
        }

        $projects->update($project, ['tags' => $current->push($tag->name)->all()], auth()->user());

        $this->reset(['labelSearch', 'labelColor']);

        $this->refreshProject();
    }

    /**
     * The × on a chip. Deliberately NOT an alias for toggleLabel: a stale page whose
     * label was already removed by someone else would otherwise put it back.
     */
    public function removeLabel(int $tagId, ProjectService $projects): void
    {
        $project = $this->requireProject();

        Gate::authorize('update', $project);

        $tag = Tag::whereKey($tagId)->where('tracker_id', $project->tracker_id)->firstOrFail();

        $next = $project->tags->pluck('name')
            ->reject(fn (string $name) => $this->same($name, $tag->name))
            ->values();

        $projects->update($project, ['tags' => $next->all()], auth()->user());

        $this->refreshProject();
    }

    // ── tasks ───────────────────────────────────────────────────────────────────

    public function addTask(TaskService $tasks): void
    {
        $project = $this->requireProject();

        Gate::authorize('createTask', $project);

        $this->validate([
            'taskTitle' => ['required', 'string', 'max:200'],
            'taskAssignee' => ['nullable', 'integer', Rule::in($this->members->pluck('id'))],
            'taskDue' => ['nullable', 'date'],
        ], attributes: ['taskTitle' => 'task', 'taskAssignee' => 'assignee', 'taskDue' => 'due date']);

        $tasks->create($project, [
            'title' => $this->taskTitle,
            'assignee_user_id' => $this->taskAssignee,
            'due_date' => $this->taskDue !== '' ? $this->taskDue : null,
        ], auth()->user());

        $this->reset(['taskTitle', 'taskAssignee', 'taskDue']);
        $this->refreshProject();
    }

    public function toggleTask(int $taskId, TaskService $tasks): void
    {
        $task = $this->findTask($taskId);

        Gate::authorize('update', $task);

        $tasks->setDone($task, ! $task->is_done, auth()->user());

        $this->refreshProject();
    }

    public function moveTask(int $taskId, string $direction, TaskService $tasks): void
    {
        $task = $this->findTask($taskId);

        Gate::authorize('update', $task);

        $tasks->move($task, $direction, auth()->user());

        unset($this->tasks);
    }

    public function deleteTask(int $taskId, TaskService $tasks): void
    {
        $task = $this->findTask($taskId);

        Gate::authorize('delete', $task);

        $tasks->delete($task, auth()->user());

        $this->refreshProject();
    }

    // ── comments ────────────────────────────────────────────────────────────────

    public function addComment(CommentService $comments): void
    {
        $project = $this->requireProject();

        Gate::authorize('createComment', $project);

        $this->validate([
            'commentBody' => ['required', 'string', 'max:5000'],
        ], attributes: ['commentBody' => 'comment']);

        $comments->create($project, $this->commentBody, auth()->user());

        $this->commentBody = '';
        $this->refreshProject();
    }

    public function startEditingComment(int $commentId): void
    {
        $comment = $this->findComment($commentId);

        Gate::authorize('update', $comment);

        $this->editingCommentId = $comment->id;
        $this->editingCommentBody = $comment->body;
    }

    public function saveComment(CommentService $comments): void
    {
        $comment = $this->findComment((int) $this->editingCommentId);

        Gate::authorize('update', $comment);

        $this->validate([
            'editingCommentBody' => ['required', 'string', 'max:5000'],
        ], attributes: ['editingCommentBody' => 'comment']);

        $comments->update($comment, $this->editingCommentBody, auth()->user());

        $this->reset(['editingCommentId', 'editingCommentBody']);
        $this->refreshProject();
    }

    public function cancelEditingComment(): void
    {
        $this->reset(['editingCommentId', 'editingCommentBody']);
        $this->resetValidation();
    }

    public function deleteComment(int $commentId, CommentService $comments): void
    {
        $comment = $this->findComment($commentId);

        Gate::authorize('delete', $comment);

        $comments->delete($comment, auth()->user());

        $this->refreshProject();
    }

    // ── attachments ─────────────────────────────────────────────────────────────

    /**
     * Fires as soon as Livewire finishes staging a file in temporary local storage.
     *
     * Committing here rather than behind an "Upload" button is what makes the file
     * count genuinely unlimited. The alternative — accumulate the whole selection, then
     * commit it in one action — puts N sequential multi-second R2 PUTs inside a single
     * request, so a large batch dies on max_execution_time partway through and leaves
     * the rest of the selection silently unattached. One file per request has no such
     * ceiling: fifty files are fifty short requests, each independently recoverable.
     */
    public function updatedUploads(): void
    {
        $this->commitUploads(app(AttachmentService::class));
    }

    /**
     * Drain the staged queue into R2.
     *
     * Public because it is the seam this feature is tested through, and because the
     * updated-hook above is a Livewire detail rather than the operation itself.
     */
    public function commitUploads(AttachmentService $attachments): void
    {
        $staged = array_values(array_filter(
            $this->uploads,
            fn ($file) => $file instanceof TemporaryUploadedFile,
        ));

        // Nothing to do on the empty update that _removeUpload also triggers.
        if ($staged === []) {
            $this->uploads = [];

            return;
        }

        $project = $this->requireProject();

        // Re-checked on the request that actually writes, not on the one that opened
        // the drawer: a Viewer who reaches this method directly is refused here.
        Gate::authorize('uploadAttachment', $project);

        $maxKb = (int) ceil(((int) config('attachments.max_bytes')) / 1024);

        foreach ($staged as $file) {
            $name = $file->getClientOriginalName();

            /*
             * Validated PER FILE with a standalone validator rather than $this->validate().
             * A batch is not pass-or-fail — one oversized file must not discard the
             * eleven good ones beside it — and $this->validate() throws, which would
             * abandon the rest of the loop.
             *
             * This is a courtesy check, not the enforcement: AttachmentService re-checks
             * size, sniffed MIME and the filename, because a Livewire call is only one
             * way into that service.
             */
            $validator = Validator::make(
                ['file' => $file],
                ['file' => ['required', 'file', "max:{$maxKb}"]],
                attributes: ['file' => 'file'],
            );

            if ($validator->fails()) {
                $this->rejectUpload($name, $validator->errors()->first('file'));

                $file->delete();

                continue;
            }

            try {
                $attachments->upload($project, $file, auth()->user());
            } catch (AttachmentRejected $e) {
                // The message is written to be read by the person who chose the file.
                $this->rejectUpload($name, $e->getMessage());
            }

            // Frees the staged copy immediately rather than waiting for Livewire's
            // 24-hour sweep. At 48 MB a file and no cap on how many, a batch left in
            // livewire-tmp is real disk on the app server — the thing FR-6.1 is about.
            $file->delete();
        }

        $this->uploads = [];

        $this->refreshProject();
    }

    public function clearUploadErrors(): void
    {
        $this->uploadErrors = [];
    }

    public function deleteFile(int $attachmentId, AttachmentService $attachments): void
    {
        $attachment = Attachment::whereKey($attachmentId)->firstOrFail();

        Gate::authorize('delete', $attachment);

        try {
            $attachments->delete($attachment, auth()->user());
        } catch (AttachmentRejected $e) {
            $this->rejectUpload($attachment->original_filename, $e->getMessage());
        }

        $this->refreshProject();
    }

    /**
     * Record a per-file failure, named so the user knows WHICH file in a batch of forty.
     *
     * Capped, and capped by dropping the OLDEST: a batch where every file is refused
     * for the same reason should still show the reason, and the tail is more likely to
     * be the part the user is still watching.
     */
    private function rejectUpload(string $filename, string $message): void
    {
        $this->uploadErrors[] = $filename.' — '.$message;

        if (count($this->uploadErrors) > self::MAX_UPLOAD_ERRORS) {
            $this->uploadErrors = array_slice($this->uploadErrors, -self::MAX_UPLOAD_ERRORS);
        }
    }

    // ── health & watching ───────────────────────────────────────────────────────

    public function openHealthEditor(): void
    {
        $project = $this->requireProject();

        Gate::authorize('setHealth', $project);

        // Re-read rather than trusting whatever was typed and abandoned last time:
        // the popover has to open showing what the card actually says.
        $this->healthValue = $project->health->value;
        $this->healthReason = (string) $project->health_reason;
        $this->resetValidation(['healthValue', 'healthReason']);

        $this->healthEditor = true;
    }

    public function closeHealthEditor(): void
    {
        $this->healthEditor = false;
        $this->resetValidation(['healthValue', 'healthReason']);
    }

    public function saveHealth(HealthService $health): void
    {
        $project = $this->requireProject();

        Gate::authorize('setHealth', $project);

        $this->validate([
            'healthValue' => ['required', Rule::enum(ProjectHealth::class)],
            'healthReason' => ['nullable', 'string', 'max:500'],
        ], attributes: ['healthValue' => 'health', 'healthReason' => 'reason']);

        $health->set($project, ProjectHealth::from($this->healthValue), $this->healthReason, auth()->user());

        $this->healthEditor = false;

        $this->refreshProject();
    }

    public function toggleWatch(WatcherService $watchers): void
    {
        $project = $this->requireProject();

        Gate::authorize('watch', $project);

        $watchers->toggle($project, auth()->user());

        unset($this->isWatching);
    }

    public function edit(): void
    {
        // Hands off to the create/edit dialog rather than duplicating that form here.
        $this->dispatch('project-modal:edit', project: $this->projectId);
    }

    /**
     * FR-4.9 — take the card off the board, keeping every row behind it.
     *
     * The drawer deliberately stays OPEN afterwards. The card has just vanished from the
     * board behind it, and closing on top of that would leave someone who mis-clicked
     * with a project they can no longer see and no obvious way back — the archived
     * banner and its Restore button are the undo, so they have to still be on screen.
     */
    public function archiveProject(ProjectService $projects): void
    {
        $project = $this->project;

        abort_unless($project !== null, 404);

        Gate::authorize('archive', $project);

        $projects->archive($project, auth()->user());

        unset($this->project, $this->activities, $this->feed);
        $this->dispatch('board:refresh');
    }

    public function restoreProject(ProjectService $projects): void
    {
        $project = $this->project;

        abort_unless($project !== null, 404);

        // The same capability in both directions: whoever may remove work from every
        // report may put it back.
        Gate::authorize('archive', $project);

        $projects->restore($project, auth()->user());

        unset($this->project, $this->activities, $this->feed);
        $this->dispatch('board:refresh');
    }

    #[On('project-saved')]
    public function onProjectSaved(): void
    {
        $this->refreshProject();
    }

    // ── internals ───────────────────────────────────────────────────────────────

    private function requireProject(): Project
    {
        $project = $this->project;

        abort_unless($project !== null, 404);

        return $project;
    }

    /** Scoped lookups: a task or comment in an invisible tracker simply does not resolve. */
    private function findTask(int $id): Task
    {
        return Task::whereKey($id)->where('project_id', $this->requireProject()->id)->firstOrFail();
    }

    private function findComment(int $id): Comment
    {
        return Comment::whereKey($id)->where('project_id', $this->requireProject()->id)->firstOrFail();
    }

    /**
     * Label comparison, in one place.
     *
     * Case-insensitive to match TagService: "Urgent" and "urgent" are the same label
     * there, so a picker that treated them as different would show a tick against a
     * row the card does not have — or offer to create one it already does.
     */
    private function same(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    /**
     * A colour must be one the palette offers.
     *
     * Refused with a 422 rather than an inline message: the swatches are the only way
     * to produce one of these, so anything else arrived from a crafted request, and
     * the value would otherwise be written into a `style` attribute on every chip.
     */
    private function validateColor(string $color): void
    {
        abort_unless(in_array($color, TagService::colors(), true), 422);
    }

    /** @param  Collection<int, string>  $names */
    private function contains(Collection $names, string $needle): bool
    {
        return $names->contains(fn (string $name) => $this->same($name, $needle));
    }

    /**
     * Forget every cached read AND tell the board.
     *
     * The card front shows task progress, attachment count and health, so an action in
     * here changes what the board behind it should be showing. Without the dispatch,
     * ticking a task updates 3/8 in the drawer and leaves the card reading 2/8 until
     * the page is reloaded.
     *
     * A DIFFERENT event name from the 'project-saved' this component listens for.
     * Livewire delivers a dispatch to every listener including the sender, so reusing
     * the name here would have this method re-enter itself on every action.
     */
    private function refreshProject(): void
    {
        unset(
            $this->project, $this->tasks, $this->attachments, $this->comments,
            $this->movements, $this->activities, $this->feed, $this->isWatching,
            $this->trackerTags, $this->canCreateLabel, $this->newLabelColor,
        );

        $this->dispatch('board:refresh');
    }

    public function render()
    {
        return view('livewire.project-drawer');
    }
}
