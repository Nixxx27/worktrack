<?php

use App\Authorization\AccessContext;
use App\Authorization\SystemContext;
use App\Enums\ProjectHealth;
use App\Enums\UserRole;
use App\Livewire\ProjectDrawer;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Step;
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
use App\Services\Trackers\TrackerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;

/**
 * FR-4.5 — the card detail drawer, and the five services behind it.
 *
 * HARNESS NOTE, as in BoardTest: Livewire::test() does not run the web middleware, so
 * the access context is bound explicitly or these tests exercise visibility that
 * production never computes.
 */
function drawerAs(User $user)
{
    app(AccessContext::class)->forUser($user);

    return Livewire::actingAs($user);
}

/**
 * An upload whose MIME type is read from its BYTES, which UploadedFile::fake() cannot do.
 *
 * Illuminate\Http\Testing\File::getMimeType() returns `MimeType::from($this->name)` — the
 * type is guessed from the FILENAME and the file's contents are never inspected. That is
 * fine for tests about size or naming, and useless for tests about sniffing: a fake named
 * "x.pdf" claims to be a PDF whatever is inside it, so it would pass an allowlist check
 * that the real finfo path would fail.
 *
 * AttachmentService::sniff() is the NFR-S9 control and it runs finfo over the real file, so
 * anything asserting what the sniffer does has to hand it real bytes. This writes them to
 * a temp file and wraps it as a genuine UploadedFile ($test: true).
 */
function sniffableUpload(string $originalName, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'worktrack-sniff');

    file_put_contents($path, $bytes);

    return new UploadedFile($path, $originalName, null, null, true);
}

beforeEach(function () {
    // Every attachment test writes to a faked disk rather than the real bucket. The
    // service reads its disk from config, so this swap is the only difference.
    Storage::fake('r2');
    config()->set('attachments.disk', 'r2');

    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $svc = app(TrackerService::class);
    $this->itTracker = $svc->create(['name' => 'IT Technical'], $this->admin);
    $this->sdTracker = $svc->create(['name' => 'Systems Development'], $this->admin);

    $this->member = makeUser('joy@gmail.com', UserRole::Member);
    $this->colleague = makeUser('rico@gmail.com', UserRole::Member);
    $this->viewer = makeUser('ella@gmail.com', UserRole::Viewer);
    $this->outsider = makeUser('sam@gmail.com', UserRole::Member);

    foreach ([$this->member, $this->colleague, $this->viewer] as $u) {
        $svc->addMember($this->itTracker, $u, $this->admin);
    }
    $svc->addMember($this->sdTracker, $this->outsider, $this->admin);

    $this->project = app(ProjectService::class)->create(
        $this->itTracker,
        ['name' => 'Firewall upgrade'],
        $this->admin,
    );
});

describe('opening the card', function () {

    it('shows the card to any member of its tracker', function () {
        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->assertSet('open', true)
            ->assertSee('Firewall upgrade');
    });

    it('cannot be opened for a project in an invisible tracker', function () {
        $foreign = SystemContext::run(fn () => app(ProjectService::class)
            ->create($this->sdTracker, ['name' => 'Payroll phase 2'], $this->admin));

        // Not found, not forbidden: a 403 would confirm the project exists.
        expect(fn () => drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $foreign->public_id))
            ->toThrow(ModelNotFoundException::class);
    });

    it('opens read-only for a viewer', function () {
        drawerAs($this->viewer)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->assertSet('open', true)
            ->assertSee('Firewall upgrade');
    });
});

describe('what the card says before you click anything', function () {

    /*
     * There used to be a "Details" tab, and it was where the description, the priority
     * and the health control lived. Four of its six fields were already on screen in
     * the strip above it, so the one thing the tab actually added was a click between
     * a reader and the description — the answer to "what is this and why".
     *
     * These tests hold the fix in place. They assert against the DEFAULT tab: if any
     * of this drifts back behind a setTab() call, they fail.
     */
    it('shows the description, priority and health reason on open', function () {
        $project = app(ProjectService::class)->create(
            $this->itTracker,
            [
                'name' => 'Core switch replacement',
                'description' => 'Raised after the quarterly review flagged this as a gap.',
                'priority' => 'urgent',
            ],
            $this->admin,
        );

        app(HealthService::class)->set($project, ProjectHealth::AtRisk, 'Vendor has gone quiet', $this->member);

        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $project->public_id)
            ->assertSet('tab', 'tasks')
            ->assertSee('Raised after the quarterly review flagged this as a gap.')
            ->assertSee('urgent')
            ->assertSee('Vendor has gone quiet');
    });

    it('has no details tab to fall into', function () {
        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('setTab', 'details')
            ->assertSet('tab', 'tasks');
    });

    it('opens the health control with what the card currently says', function () {
        app(HealthService::class)->set($this->project, ProjectHealth::Stalled, 'Waiting on procurement', $this->member);

        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            // Typed and abandoned — reopening must not offer it back as if it were saved.
            ->set('healthReason', 'half-written note')
            ->call('openHealthEditor')
            ->assertSet('healthEditor', true)
            ->assertSet('healthValue', 'stalled')
            ->assertSet('healthReason', 'Waiting on procurement');
    });

    it('closes the health control once the flag is set', function () {
        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('openHealthEditor')
            ->set('healthValue', 'at_risk')
            ->set('healthReason', 'Vendor slipped the window')
            ->call('saveHealth')
            ->assertHasNoErrors()
            ->assertSet('healthEditor', false);

        expect($this->project->fresh()->health)->toBe(ProjectHealth::AtRisk);
    });

    it('refuses to open the health control for a viewer', function () {
        drawerAs($this->viewer)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('openHealthEditor')
            ->assertForbidden();
    });
});

describe('the checklist (FR-5)', function () {

    it('adds a task and moves the card counter with it', function () {
        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->set('taskTitle', 'Confirm the maintenance window')
            ->set('taskAssignee', $this->colleague->id)
            ->call('addTask')
            ->assertHasNoErrors();

        $fresh = $this->project->fresh();

        // The 3/8 on the card front is these two columns and nothing else (FR-4.4).
        expect($fresh->tasks_total)->toBe(1)
            ->and($fresh->tasks_done)->toBe(0);
    });

    it('ticks a task and rolls the completion up to the card', function () {
        $task = app(TaskService::class)->create($this->project, ['title' => 'Patch the firmware'], $this->member);

        app(TaskService::class)->setDone($task, true, $this->colleague);

        $fresh = $this->project->fresh();

        expect($fresh->tasks_done)->toBe(1)
            ->and($fresh->tasks_total)->toBe(1)
            ->and($task->fresh()->completed_by_user_id)->toBe($this->colleague->id)
            // The CHECK constraint requires is_done and completed_at to move together.
            ->and($task->fresh()->completed_at)->not->toBeNull();
    });

    it('clears completed_at when a task is reopened', function () {
        $task = app(TaskService::class)->create($this->project, ['title' => 'Patch'], $this->member);
        app(TaskService::class)->setDone($task, true, $this->member);
        app(TaskService::class)->setDone($task->fresh(), false, $this->member);

        expect($task->fresh()->completed_at)->toBeNull()
            ->and($this->project->fresh()->tasks_done)->toBe(0);
    });

    it('keeps the counters correct after a delete', function () {
        $svc = app(TaskService::class);
        $a = $svc->create($this->project, ['title' => 'A'], $this->member);
        $svc->create($this->project, ['title' => 'B'], $this->member);
        $svc->setDone($a, true, $this->member);

        expect($this->project->fresh()->tasks_total)->toBe(2)
            ->and($this->project->fresh()->tasks_done)->toBe(1);

        // The migration records a live incident where a stale counter left a card
        // reading 3/8 while seven tasks existed, and the next completion would have
        // violated the tasks_done <= tasks_total CHECK.
        $svc->delete($a->fresh(), $this->member);

        expect($this->project->fresh()->tasks_total)->toBe(1)
            ->and($this->project->fresh()->tasks_done)->toBe(0);
    });

    it('refuses to assign a task to someone outside the tracker', function () {
        // FR-5.4. Unlike project_assignees this is NOT backed by a composite FK — the
        // cascading one hard-deleted task rows — so this check IS the enforcement.
        expect(fn () => app(TaskService::class)->create(
            $this->project,
            ['title' => 'Crafted', 'assignee_user_id' => $this->outsider->id],
            $this->member,
        ))->toThrow(InvalidArgumentException::class);
    });

    it('does not treat reordering as activity', function () {
        $svc = app(TaskService::class);
        $a = $svc->create($this->project, ['title' => 'A'], $this->member);
        $svc->create($this->project, ['title' => 'B'], $this->member);

        $clock = $this->project->fresh()->last_activity_at;
        $rows = DB::table('project_activities')->where('project_id', $this->project->id)->count();

        $this->travel(5)->seconds();
        $svc->move($a->fresh(), 'down', $this->member);

        // Tidying a checklist is not progress, and letting it reset the stall clock
        // would be a one-click way to make a rotting project look worked on.
        expect($this->project->fresh()->last_activity_at->eq($clock))->toBeTrue()
            ->and(DB::table('project_activities')->where('project_id', $this->project->id)->count())->toBe($rows);
    });

    it('refuses a viewer', function () {
        drawerAs($this->viewer)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->set('taskTitle', 'Should not exist')
            ->call('addTask')
            ->assertForbidden();

        expect(Task::withoutGlobalScopes()->count())->toBe(0);
    });
});

describe('comments and @mentions', function () {

    it('posts a comment and counts it on the card', function () {
        app(CommentService::class)->create($this->project, 'Waiting on the vendor quote.', $this->member);

        expect($this->project->fresh()->comments_count)->toBe(1);
    });

    it('resolves a mention to a tracker member', function () {
        $comment = app(CommentService::class)->create(
            $this->project,
            "@{$this->colleague->name} can you confirm the window?",
            $this->member,
        );

        expect($comment->mentions()->pluck('users.id')->all())->toBe([$this->colleague->id]);
    });

    it('never resolves a mention to someone outside the tracker', function () {
        // Otherwise the mention box becomes an existence oracle for the whole user
        // table, one @ at a time.
        $comment = app(CommentService::class)->create(
            $this->project,
            "@{$this->outsider->name} please look",
            $this->member,
        );

        expect($comment->mentions()->count())->toBe(0);
    });

    it('prefers the longest matching name', function () {
        $long = makeUser('mc@gmail.com', UserRole::Member);
        SystemContext::run(fn () => $long->update(['name' => $this->colleague->name.' Santos']));
        app(TrackerService::class)->addMember($this->itTracker, $long, $this->admin);

        $comment = app(CommentService::class)->create(
            $this->project,
            "@{$long->name} please look",
            $this->member,
        );

        // "@Rico" and "@Rico Santos" are both plausible prefixes; only the member list
        // can say which was meant, and the more specific one has to win.
        expect($comment->mentions()->pluck('users.id')->all())->toBe([$long->id]);
    });

    it('queues one notification per mentioned person, never to the author', function () {
        app(CommentService::class)->create(
            $this->project,
            "@{$this->colleague->name} and @{$this->member->name} — see above",
            $this->member,
        );

        $queued = DB::table('notification_outbox')->where('event_type', 'comment.mentioned')->pluck('user_id');

        expect($queued->all())->toBe([$this->colleague->id]);
    });

    it('accumulates a second mention rather than replacing the first', function () {
        // The outbox coalesce key is user:event:project, so two mentions of the same
        // person on one project collide. Taking only the latest would mean being named
        // twice and hearing about it once.
        $svc = app(CommentService::class);
        $svc->create($this->project, "@{$this->colleague->name} first", $this->member);
        $svc->create($this->project, "@{$this->colleague->name} second", $this->member);

        $row = DB::table('notification_outbox')
            ->where('event_type', 'comment.mentioned')
            ->where('user_id', $this->colleague->id)
            ->first();

        expect(DB::table('notification_outbox')->where('event_type', 'comment.mentioned')->count())->toBe(1)
            ->and(json_decode($row->payload, true)['mentions'])->toHaveCount(2);
    });

    it('lets only the author edit their own comment', function () {
        $comment = app(CommentService::class)->create($this->project, 'Mine', $this->member);

        expect($this->member->can('update', $comment))->toBeTrue()
            // Not even an admin: an admin who needs it gone can delete it, which is
            // visible, whereas a silent edit is not.
            ->and($this->admin->can('update', $comment))->toBeFalse()
            ->and($this->colleague->can('update', $comment))->toBeFalse();
    });

    it('lets a manager delete someone else\'s comment but a member only their own', function () {
        $comment = app(CommentService::class)->create($this->project, 'Theirs', $this->member);

        expect($this->colleague->can('delete', $comment))->toBeFalse()
            ->and($this->admin->can('delete', $comment))->toBeTrue()
            ->and($this->member->can('delete', $comment))->toBeTrue();
    });

    it('marks an edited comment as edited', function () {
        $comment = app(CommentService::class)->create($this->project, 'First draft', $this->member);

        app(CommentService::class)->update($comment, 'Second draft', $this->member);

        expect($comment->fresh()->edited_at)->not->toBeNull();
    });

    it('does not let deleting a comment reset the stall clock', function () {
        $comment = app(CommentService::class)->create($this->project, 'Oops', $this->member);
        $clock = $this->project->fresh()->last_activity_at;

        $this->travel(10)->seconds();
        app(CommentService::class)->delete($comment, $this->member);

        expect($this->project->fresh()->last_activity_at->eq($clock))->toBeTrue()
            ->and($this->project->fresh()->comments_count)->toBe(0);
    });
});

describe('attachments on R2 (FR-6)', function () {

    it('stores the file and promotes the row to available', function () {
        $attachment = app(AttachmentService::class)->upload(
            $this->project,
            UploadedFile::fake()->image('rack.jpg'),
            $this->member,
        );

        expect($attachment->status)->toBe('available')
            ->and($this->project->fresh()->attachments_count)->toBe(1);

        Storage::disk('r2')->assertExists($attachment->object_key);
    });

    it('gives the object an unguessable key that leaks no filename', function () {
        // FR-6.6 — the display name lives only in the database column and is
        // reattached at download time via Content-Disposition.
        $attachment = app(AttachmentService::class)->upload(
            $this->project,
            UploadedFile::fake()->create('Q3 vendor contract.pdf', 10, 'application/pdf'),
            $this->member,
        );

        expect($attachment->object_key)->not->toContain('vendor')
            ->and($attachment->object_key)->not->toContain('contract')
            ->and($attachment->object_key)->toStartWith('attachments/'.$this->itTracker->public_id)
            ->and($attachment->original_filename)->toBe('Q3 vendor contract.pdf');
    });

    it('rejects a file over the size limit without writing anything', function () {
        config()->set('attachments.max_bytes', 1024);

        expect(fn () => app(AttachmentService::class)->upload(
            $this->project,
            UploadedFile::fake()->create('huge.pdf', 50, 'application/pdf'),
            $this->member,
        ))->toThrow(AttachmentRejected::class);

        expect(Attachment::withoutGlobalScopes()->count())->toBe(0);
    });

    it('rejects an executable even when it is disguised inside a compound filename', function () {
        // The sniffer sees whatever the bytes are; this catches what the operating
        // system does when a colleague double-clicks the downloaded name.
        expect(fn () => app(AttachmentService::class)->upload(
            $this->project,
            UploadedFile::fake()->createWithContent('invoice.pdf.exe', 'plain text pretending'),
            $this->member,
        ))->toThrow(AttachmentRejected::class);

        expect(Attachment::withoutGlobalScopes()->count())->toBe(0);
    });

    it('rejects a type that is not on the allowlist', function () {
        expect(fn () => app(AttachmentService::class)->upload(
            $this->project,
            UploadedFile::fake()->create('firmware.bin', 4, 'application/x-msdownload'),
            $this->member,
        ))->toThrow(AttachmentRejected::class);
    });

    it('removes the object from storage on delete, not just the row', function () {
        // FR-6.7 — a listing that merely hides the row leaves the object paid for and
        // reachable by anyone who ever held a signed URL.
        $attachment = app(AttachmentService::class)->upload(
            $this->project,
            UploadedFile::fake()->image('screenshot.png'),
            $this->member,
        );
        $key = $attachment->object_key;

        app(AttachmentService::class)->delete($attachment, $this->member);

        Storage::disk('r2')->assertMissing($key);
        expect($this->project->fresh()->attachments_count)->toBe(0);
    });

    it('never lists or signs anything that is not available', function () {
        $attachment = app(AttachmentService::class)->upload(
            $this->project,
            UploadedFile::fake()->image('a.png'),
            $this->member,
        );

        SystemContext::run(fn () => $attachment->forceFill(['status' => 'pending'])->save());

        expect(fn () => app(AttachmentService::class)->temporaryUrl($attachment->fresh()))
            ->toThrow(AttachmentRejected::class)
            ->and($this->member->can('download', $attachment->fresh()))->toBeFalse();
    });

    it('refuses a download to someone outside the tracker, as not found', function () {
        $attachment = app(AttachmentService::class)->upload(
            $this->project,
            UploadedFile::fake()->image('a.png'),
            $this->member,
        );

        $response = Gate::forUser($this->outsider)
            ->inspect('download', $attachment);

        expect($response->allowed())->toBeFalse()
            ->and($response->status())->toBe(404);
    });

    it('lets a viewer download but never upload', function () {
        // FR-4.5 would be meaningless if a member of a tracker could not open its
        // files; the matrix denies Viewers only upload.
        $attachment = app(AttachmentService::class)->upload(
            $this->project,
            UploadedFile::fake()->image('a.png'),
            $this->member,
        );

        expect($this->viewer->can('download', $attachment))->toBeTrue()
            ->and($this->viewer->can('uploadAttachment', $this->project))->toBeFalse();
    });

    it('lets any member of the tracker attach a file to anyone\'s card', function () {
        // VERIFICATION.md attachments-11: restricting upload to the owner sent a
        // network engineer back to email, and the artifact never joined the record.
        expect($this->colleague->can('uploadAttachment', $this->project))->toBeTrue();
    });

    it('only lets the uploader or a manager delete a file', function () {
        $attachment = app(AttachmentService::class)->upload(
            $this->project,
            UploadedFile::fake()->image('a.png'),
            $this->member,
        );

        expect($this->member->can('delete', $attachment))->toBeTrue()
            ->and($this->colleague->can('delete', $attachment))->toBeFalse()
            ->and($this->admin->can('delete', $attachment))->toBeTrue();
    });

    it('redirects a download through the authorizing route', function () {
        $attachment = app(AttachmentService::class)->upload(
            $this->project,
            UploadedFile::fake()->image('a.png'),
            $this->member,
        );

        resetContext();

        $this->actingAs($this->member)
            ->get(route('attachments.download', $attachment->public_id))
            ->assertRedirect();
    });

    it('404s a download for a project in an invisible tracker', function () {
        $foreign = SystemContext::run(function () {
            $p = app(ProjectService::class)->create($this->sdTracker, ['name' => 'Payroll'], $this->admin);

            return app(AttachmentService::class)->upload($p, UploadedFile::fake()->image('b.png'), $this->admin);
        });

        resetContext();

        // Route-model binding resolves through the scope, so the record never binds.
        $this->actingAs($this->member)
            ->get(route('attachments.download', $foreign->public_id))
            ->assertNotFound();
    });

    /**
     * The types added alongside multi-file upload, and specifically the awkward ones.
     *
     * .json carries its own libmagic signature. .yaml and .dxf carry NONE — they are
     * genuinely just text — so they reach the allowlist only because sniff() may refine
     * a text/plain result by extension. Without that refinement they would be types
     * listed as allowed that can never actually be uploaded, which is the worst of both.
     */
    it('accepts the widened allowlist, including types that sniff as plain text', function () {
        $cases = [
            'inventory.json' => '{"switches":42}',
            'deploy.yaml' => "replicas: 2\nimage: nginx\n",
            'floorplan.dxf' => "0\nSECTION\n2\nHEADER\n0\nENDSEC\n",
            'vendor-reply.eml' => "From: a@b.com\nTo: c@d.com\nSubject: RMA\n\nApproved.\n",
        ];

        foreach ($cases as $filename => $contents) {
            $attachment = app(AttachmentService::class)->upload(
                $this->project,
                // Real bytes, so this exercises finfo rather than the filename.
                sniffableUpload($filename, $contents),
                $this->member,
            );

            expect($attachment->status)->toBe('available');

            Storage::disk('r2')->assertExists($attachment->object_key);
        }

        expect($this->project->fresh()->attachments_count)->toBe(count($cases));
    });

    /**
     * HTML, which arrives as a whole page from an AI tool and as a bare fragment from a
     * component generator — and which libmagic reads differently in each case.
     *
     * Both are asserted because only one of them exercises the allowlist directly: the
     * document carries a signature and sniffs as text/html, while the fragment sniffs as
     * text/plain and reaches the allowlist only through the extension refinement in
     * sniff(). Testing just the document would leave "whether the upload works depends on
     * whether the file starts with <!doctype>" undetected.
     */
    it('accepts html whether it sniffs as a document or as plain text', function () {
        $cases = [
            'report.html' => "<!DOCTYPE html>\n<html><head><title>Q3</title></head><body><h1>Q3</h1></body></html>\n",
            'widget.html' => "<div class=\"card\">\n  <p>fragment only</p>\n</div>\n",
        ];

        foreach ($cases as $filename => $contents) {
            $file = sniffableUpload($filename, $contents);

            $attachment = app(AttachmentService::class)->upload($this->project, $file, $this->member);

            expect($attachment->status)->toBe('available')
                ->and($attachment->mime_type)->toBe('text/html');

            Storage::disk('r2')->assertExists($attachment->object_key);
        }

        // Guards the test itself: if libmagic ever grows a signature for the fragment,
        // this case stops covering the refinement and the pairing above is meaningless.
        expect(sniffableUpload('widget.html', $cases['widget.html'])->getMimeType())->toBe('text/plain');
    });

    /**
     * The other half of a page split across files. These two travel opposite paths for
     * the same reason as the HTML pair above: libmagic has a signature for JavaScript
     * and none at all for CSS, so .css reaches the allowlist only via the refinement.
     */
    it('accepts js and css, whether or not libmagic knows the format', function () {
        $script = app(AttachmentService::class)->upload(
            $this->project,
            sniffableUpload('app.js', "const x = 1;\nfunction go(){ console.log(x) }\nexport default go;\n"),
            $this->member,
        );

        // Not a single expected value: libmagic reports application/javascript on some
        // builds and the IANA text/javascript on others, and both are allowlisted
        // precisely because the app cannot depend on which one the host emits.
        expect($script->status)->toBe('available')
            ->and($script->mime_type)->toBeIn(['application/javascript', 'text/javascript']);

        $style = app(AttachmentService::class)->upload(
            $this->project,
            sniffableUpload('theme.css', "body { color: #111; margin: 0 }\n.card { display: flex }\n"),
            $this->member,
        );

        expect($style->status)->toBe('available')
            ->and($style->mime_type)->toBe('text/css');
    });

    /**
     * Allowing web files narrowed the FR-6.5 script rule to scripts the SYSTEM executes.
     * It must not have dissolved it. Every format here is still refused on the FILENAME,
     * whatever its bytes sniff as — and all four of these sniff as innocent text, which
     * is exactly why the second gate exists.
     */
    it('still rejects system and script-host files after web files became allowed', function () {
        $cases = [
            'deploy.sh' => "#!/bin/bash\nrm -rf /tmp/build\n",
            'run.ps1' => "Get-Process | Stop-Process\n",
            'macro.vbs' => "Set s = CreateObject(\"WScript.Shell\")\n",
            'invoice.pdf.exe' => "harmless looking text\n",
        ];

        foreach ($cases as $filename => $contents) {
            expect(fn () => app(AttachmentService::class)->upload(
                $this->project,
                sniffableUpload($filename, $contents),
                $this->member,
            ))->toThrow(AttachmentRejected::class);
        }

        expect(Attachment::withoutGlobalScopes()->count())->toBe(0);
    });

    /**
     * The refinement runs in ONE direction only, and that is the safety property.
     *
     * A .pdf extension on a file whose bytes are an executable must not be talked into
     * an allowed type — sniff() may only narrow a text/plain result, never reinterpret a
     * binary one. If this ever inverts, the extension becomes the authority and the
     * entire allowlist is decided by whoever names the file.
     */
    it('never lets the extension widen a binary sniff', function () {
        // A DOS/PE header. libmagic reads this as an executable no matter what the file
        // is called, and .pdf is on the allowlist — so if the extension were ever
        // allowed to override a binary sniff, this exact file is what gets through.
        $disguised = sniffableUpload('report.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00");

        // Guards the test itself: if this ever reports application/pdf, the harness has
        // stopped sniffing and the assertion below would pass for the wrong reason.
        expect($disguised->getMimeType())->not->toBe('application/pdf');

        expect(fn () => app(AttachmentService::class)->upload($this->project, $disguised, $this->member))
            ->toThrow(AttachmentRejected::class);

        expect(Attachment::withoutGlobalScopes()->count())->toBe(0);
    });
});

/**
 * The LIVEWIRE seam, which the tests above deliberately do not touch.
 *
 * Every attachment test above calls AttachmentService directly, which is why the view
 * spent a while bound to a `$upload` property that no longer existed and a
 * `uploadFile()` method that was never written: the service was covered, the path a
 * person actually uses was not. These tests drive commitUploads — the seam its own
 * docblock nominates — so the component's contract with the view is pinned.
 */
describe('uploading through the drawer', function () {

    it('commits a staged file and clears the queue', function () {
        $component = drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->set('uploads', [UploadedFile::fake()->image('rack.png')])
            ->assertHasNoErrors();

        // Set fires updatedUploads, which commits — so by here it is already done.
        expect($this->project->fresh()->attachments_count)->toBe(1);

        $component->assertSet('uploads', []);
    });

    /**
     * The whole reason the property is an array drained one element at a time. A batch
     * must not be pass-or-fail: the eleven good files attach, and the refused one is
     * named rather than silently dropped.
     */
    it('attaches the good files in a batch and names only the refused one', function () {
        $component = drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->set('uploads', [
                UploadedFile::fake()->image('good-one.png'),
                UploadedFile::fake()->createWithContent('invoice.pdf.exe', 'plain text pretending'),
                UploadedFile::fake()->image('good-two.png'),
            ]);

        expect($this->project->fresh()->attachments_count)->toBe(2);

        $errors = $component->get('uploadErrors');

        expect($errors)->toHaveCount(1)
            ->and($errors[0])->toContain('invoice.pdf.exe');
    });

    it('reports an oversized file rather than throwing out the request', function () {
        config()->set('attachments.max_bytes', 1024);

        $component = drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->set('uploads', [UploadedFile::fake()->create('huge.pdf', 500, 'application/pdf')])
            ->assertHasNoErrors();

        expect($this->project->fresh()->attachments_count)->toBe(0)
            ->and($component->get('uploadErrors'))->toHaveCount(1);
    });

    it('caps how many rejection messages it keeps', function () {
        config()->set('attachments.max_bytes', 1024);

        $tooMany = collect(range(1, 25))
            ->map(fn (int $i) => UploadedFile::fake()->create("huge-{$i}.pdf", 500, 'application/pdf'))
            ->all();

        $component = drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->set('uploads', $tooMany);

        // Bounded, and keeping the LAST ones: an unbounded list is a payload that grows
        // with the batch, and the newest rejections are the ones still on screen.
        expect($component->get('uploadErrors'))->toHaveCount(20)
            ->and($component->get('uploadErrors')[19])->toContain('huge-25.pdf');
    });

    it('lets the errors be dismissed', function () {
        config()->set('attachments.max_bytes', 1024);

        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->set('uploads', [UploadedFile::fake()->create('huge.pdf', 500, 'application/pdf')])
            ->call('clearUploadErrors')
            ->assertSet('uploadErrors', []);
    });

    /**
     * Opening a different card must not inherit the previous one's rejections — the
     * reset list in openFor is what does this, and it is exactly the list that went
     * stale when $upload became $uploads.
     */
    it('does not carry rejections from one card to the next', function () {
        config()->set('attachments.max_bytes', 1024);

        $other = app(ProjectService::class)->create($this->itTracker, ['name' => 'Second card'], $this->admin);

        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->set('uploads', [UploadedFile::fake()->create('huge.pdf', 500, 'application/pdf')])
            ->call('openFor', $other->public_id)
            ->assertSet('uploadErrors', [])
            ->assertSet('uploads', []);
    });

    /**
     * Authorization is re-checked on the request that WRITES, not on the one that
     * opened the drawer. A Viewer may open a card and may download from it, and must
     * still be refused here.
     */
    it('refuses a viewer at the commit, not merely in the view', function () {
        drawerAs($this->viewer)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->set('uploads', [UploadedFile::fake()->image('rack.png')])
            ->assertForbidden();

        expect($this->project->fresh()->attachments_count)->toBe(0);
    });

    it('does nothing on the empty update that removing a file triggers', function () {
        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->set('uploads', [])
            ->assertHasNoErrors()
            ->assertSet('uploads', []);

        expect($this->project->fresh()->attachments_count)->toBe(0);
    });

    it('offers the uploader to a member and withholds it from a viewer', function () {
        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('setTab', 'files')
            ->assertSee('Attach files');

        drawerAs($this->viewer)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('setTab', 'files')
            ->assertDontSee('Attach files');
    });

    /**
     * "Unlimited" as an assertion rather than an absence.
     *
     * There is no per-card cap anywhere in the upload path, and this is the test that
     * fails if someone adds one — a count check is exactly the kind of well-meant
     * guard that gets introduced later and is invisible until a card hits it.
     */
    it('puts no ceiling on how many files one card carries', function () {
        $many = collect(range(1, 30))
            ->map(fn (int $i) => UploadedFile::fake()->image("screenshot-{$i}.png"))
            ->all();

        $component = drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->set('uploads', $many);

        expect($this->project->fresh()->attachments_count)->toBe(30)
            ->and($component->get('uploadErrors'))->toBe([])
            ->and(Attachment::where('project_id', $this->project->id)->available()->count())->toBe(30);
    });

    /**
     * The two limits that must agree, and did not.
     *
     * Livewire enforces its OWN rule when staging a file, and its default is 12 MB.
     * config/attachments.php has to duplicate ATTACHMENTS_MAX_BYTES to set it, because a
     * config file cannot read another config file — so the duplication is real and this
     * is what stops it drifting. Left unaligned, every file between 12 MB and the app's
     * own limit is refused by the framework, with the framework's generic wording, for a
     * file the application plainly permits: an FR-6.8 clarity failure that looks like a
     * broken uploader rather than a size limit.
     */
    it('lets Livewire stage a file as large as the application allows', function () {
        $appMaxKb = (int) ceil(((int) config('attachments.max_bytes')) / 1024);

        expect(FileUploadConfiguration::rules())->toContain("max:{$appMaxKb}");
    });

    /**
     * The staging disk must be local, and not because of preference.
     *
     * AttachmentService::sniff() runs finfo over the staged temporary file to read its
     * magic bytes (NFR-S9). A temp file staged into a bucket cannot be sniffed, so an S3
     * staging disk silently disables the content-type check the whole allowlist rests
     * on — and Livewire additionally refuses multiple-file uploads on that driver.
     */
    it('stages uploads on local disk so their bytes can be sniffed', function () {
        expect(FileUploadConfiguration::isUsingS3())->toBeFalse();
    });
});

describe('health flags and watching', function () {

    it('records a manual health flag with its reason and marks it manual', function () {
        app(HealthService::class)->set($this->project, ProjectHealth::AtRisk, 'Vendor has gone quiet', $this->member);

        $fresh = $this->project->fresh();

        expect($fresh->health)->toBe(ProjectHealth::AtRisk)
            // Manual, so tonight's detector cannot overwrite a human decision.
            ->and($fresh->health_source->value)->toBe('manual')
            ->and($fresh->health_reason)->toBe('Vendor has gone quiet')
            ->and($fresh->health_set_by_user_id)->toBe($this->member->id);
    });

    it('does not let flagging a project as stalled reset its own stall clock', function () {
        $clock = $this->project->fresh()->last_activity_at;

        $this->travel(10)->seconds();
        app(HealthService::class)->set($this->project, ProjectHealth::Stalled, 'Nobody has touched this', $this->member);

        // Otherwise the act of reporting the problem erases the evidence for it.
        expect($this->project->fresh()->last_activity_at->eq($clock))->toBeTrue();
    });

    it('refuses a viewer', function () {
        expect($this->viewer->can('setHealth', $this->project))->toBeFalse();
    });

    it('watches and unwatches without writing to the activity feed', function () {
        $watchers = app(WatcherService::class);
        $rows = DB::table('project_activities')->where('project_id', $this->project->id)->count();

        expect($watchers->toggle($this->project, $this->colleague))->toBeTrue()
            ->and($watchers->isWatching($this->project, $this->colleague))->toBeTrue()
            ->and($watchers->toggle($this->project, $this->colleague))->toBeFalse()
            ->and($watchers->isWatching($this->project, $this->colleague))->toBeFalse()
            // Watching is a private preference about your own inbox. Publishing it
            // would make people think twice about doing it.
            ->and(DB::table('project_activities')->where('project_id', $this->project->id)->count())->toBe($rows);
    });

    it('lets a viewer watch, because it only changes their own inbox', function () {
        expect($this->viewer->can('watch', $this->project))->toBeTrue();
    });
});

describe('everything in the drawer reaches the activity log', function () {

    it('records tasks, comments and files as attributed rows', function () {
        $task = app(TaskService::class)->create($this->project, ['title' => 'Patch'], $this->member);
        app(TaskService::class)->setDone($task, true, $this->colleague);
        app(CommentService::class)->create($this->project, 'Done and verified.', $this->colleague);
        app(AttachmentService::class)->upload($this->project, UploadedFile::fake()->image('proof.png'), $this->member);

        $rows = DB::table('project_activities')
            ->where('project_id', $this->project->id)
            ->pluck('user_id', 'type');

        expect($rows->keys())->toContain('task_created', 'task_completed', 'comment', 'attachment_added')
            ->and($rows['task_completed'])->toBe($this->colleague->id)
            ->and($rows['attachment_added'])->toBe($this->member->id);
    });

    it('renders each one as a readable sentence', function () {
        $task = app(TaskService::class)->create($this->project, ['title' => 'Patch the firmware'], $this->member);
        app(TaskService::class)->setDone($task, true, $this->member);
        app(HealthService::class)->set($this->project, ProjectHealth::AtRisk, 'Vendor quiet', $this->member);

        $described = ProjectActivity::where('project_id', $this->project->id)
            ->get()
            ->map(fn ($a) => $a->describe());

        expect($described)->toContain('added the task “Patch the firmware”')
            ->and($described)->toContain('completed “Patch the firmware”')
            ->and($described)->toContain('set health to at risk — “Vendor quiet”');
    });
});

describe('every tab renders', function () {

    // Only the default tab is exercised by the tests above, so a Blade error in the
    // history or files panel would ship undetected — the view is never compiled until
    // somebody clicks it. This walks all three with real content in each.
    it('renders each panel with content in it', function () {
        $task = app(TaskService::class)->create($this->project, ['title' => 'Patch the firmware'], $this->member);
        app(TaskService::class)->setDone($task, true, $this->member);
        app(CommentService::class)->create($this->project, 'Vendor confirmed the window.', $this->colleague);
        app(AttachmentService::class)->upload($this->project, UploadedFile::fake()->image('rack.png'), $this->member);
        app(HealthService::class)->set($this->project, ProjectHealth::AtRisk, 'Vendor quiet', $this->member);

        $step = SystemContext::run(fn () => Step::where('tracker_id', $this->itTracker->id)
            ->orderByDesc('position')->firstOrFail());
        app(ProjectService::class)->move($this->project, $step, $this->colleague);

        $drawer = drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id);

        $drawer->call('setTab', 'tasks')->assertSee('Patch the firmware');
        $drawer->call('setTab', 'files')->assertSee('rack.png');
        $drawer->call('setTab', 'history')->assertSee($step->name);
    });

    it('falls back to the checklist for an unknown tab', function () {
        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('setTab', '../../etc/passwd')
            ->assertSet('tab', 'tasks');
    });

    // Comments used to be the fifth tab. They are now a permanent right-hand column,
    // so they must be on screen no matter which tab the left side is showing —
    // otherwise the move quietly hid the conversation behind the checklist.
    it('shows the conversation alongside every tab', function () {
        app(CommentService::class)->create($this->project, 'Vendor confirmed the window.', $this->colleague);

        $drawer = drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id);

        foreach (['tasks', 'files', 'history'] as $tab) {
            $drawer->call('setTab', $tab)
                ->assertSee('Comments and activity')
                ->assertSee('Vendor confirmed the window.');
        }
    });
});

describe('the comments and activity rail', function () {

    it('interleaves comments with the activity trail', function () {
        app(CommentService::class)->create($this->project, 'Vendor confirmed the window.', $this->colleague);

        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->assertSee('Vendor confirmed the window.')
            ->assertSee('created this project');
    });

    // A comment writes a 'comment' activity row as well as the comment itself. If the
    // feed carried both, every remark would appear twice — once as itself and once as
    // a line announcing it.
    it('does not repeat a comment as an activity line', function () {
        app(CommentService::class)->create($this->project, 'Vendor confirmed the window.', $this->colleague);

        $feed = drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->instance()->feed;

        expect($feed->where('kind', 'comment'))->toHaveCount(1)
            ->and($feed->where('kind', 'activity')->pluck('entry')->pluck('type'))
            ->not->toContain('comment');
    });

    // What a card has been through is most of why anyone opens the drawer, so the rail
    // opens on the whole record and hiding it is the thing you ask for.
    it('opens with the trail already showing', function () {
        app(CommentService::class)->create($this->project, 'Vendor confirmed the window.', $this->colleague);

        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->assertSet('showActivity', true)
            ->assertSee('Vendor confirmed the window.')
            ->assertSee('created this project');
    });

    it('still collapses to the comments alone when asked', function () {
        app(CommentService::class)->create($this->project, 'Vendor confirmed the window.', $this->colleague);

        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('toggleActivity')
            ->assertSet('showActivity', false)
            ->assertSee('Vendor confirmed the window.')
            ->assertDontSee('created this project');
    });

    // The point of persisting it. A default nobody can escape without re-escaping it on
    // every refresh is not a preference, and the noisy-card case the old default was
    // protecting is exactly the one that needs the choice to hold.
    it('remembers the trail was hidden, across a fresh page load', function () {
        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('toggleActivity')
            ->assertSet('showActivity', false);

        // A second mount is what a refresh does: new component, same session.
        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->assertSet('showActivity', false)
            ->assertDontSee('created this project');
    });

    it('remembers the trail was shown again, across a fresh page load', function () {
        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('toggleActivity')
            ->call('toggleActivity')
            ->assertSet('showActivity', true);

        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->assertSet('showActivity', true)
            ->assertSee('created this project');
    });
});

describe('labels on the card', function () {

    it('creates a label from the card and attaches it', function () {
        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('openLabelPicker')
            ->set('labelSearch', 'Sprint 106')
            ->call('createLabel')
            ->assertSet('labelSearch', '');

        expect($this->project->fresh()->tags->pluck('name')->all())->toBe(['Sprint 106']);
    });

    it('records the change as an attributed activity row', function () {
        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->set('labelSearch', 'Bug')
            ->call('createLabel');

        $row = ProjectActivity::where('project_id', $this->project->id)
            ->where('type', 'tags_changed')->latest('id')->firstOrFail();

        expect($row->user_id)->toBe($this->member->id)
            ->and($row->describe())->toBe('tagged Bug');
    });

    it('toggles an existing tracker label on and back off', function () {
        $tag = app(TagService::class)->resolve($this->itTracker, ['Improvements'], $this->admin)->first();

        $drawer = drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id);

        $drawer->call('toggleLabel', $tag->id);
        expect($this->project->fresh()->tags)->toHaveCount(1);

        $drawer->call('toggleLabel', $tag->id);
        expect($this->project->fresh()->tags)->toHaveCount(0);
    });

    it('keeps a label that differs only in casing from becoming a second row', function () {
        app(TagService::class)->resolve($this->itTracker, ['Urgent'], $this->admin);

        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->set('labelSearch', 'URGENT')
            ->call('createLabel');

        expect(DB::table('tags')->where('tracker_id', $this->itTracker->id)->count())->toBe(1)
            ->and($this->project->fresh()->tags->pluck('name')->all())->toBe(['Urgent']);
    });

    it('caps a card at twelve labels', function () {
        $names = collect(range(1, 12))->map(fn ($n) => "label-{$n}")->all();
        app(ProjectService::class)->update($this->project, ['tags' => $names], $this->admin);

        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->set('labelSearch', 'one-too-many')
            ->call('createLabel')
            ->assertHasErrors('labelSearch');

        expect($this->project->fresh()->tags)->toHaveCount(12);
    });

    // FR-4.2 tags are tracker-owned. The picker only ever lists this tracker's, but
    // the id arrives from the browser, so the id itself is re-checked.
    it('refuses a tag id belonging to another tracker', function () {
        $foreign = SystemContext::run(fn () => app(TagService::class)
            ->resolve($this->sdTracker, ['Elsewhere'], $this->admin)->first());

        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('toggleLabel', $foreign->id);
    })->throws(ModelNotFoundException::class);

    it('refuses a viewer', function () {
        $tag = app(TagService::class)->resolve($this->itTracker, ['Improvements'], $this->admin)->first();

        drawerAs($this->viewer)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('toggleLabel', $tag->id)
            ->assertForbidden();

        expect($this->project->fresh()->tags)->toHaveCount(0);
    });
});

describe('label colours', function () {

    it('creates a label in the colour that was picked', function () {
        $chosen = TagService::PALETTE['Magenta'];

        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('openLabelPicker')
            ->set('labelSearch', 'Vendor')
            ->call('chooseLabelColor', $chosen)
            ->assertSet('labelColor', $chosen)
            ->call('createLabel')
            ->assertSet('labelColor', null);

        expect($this->project->fresh()->tags->first()->color)->toBe($chosen);
    });

    // The swatch shown selected before anyone clicks has to be the colour the label
    // would really get, or the preview lies about the case nobody touches.
    it('previews the rotation colour when no swatch has been picked', function () {
        $drawer = drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->set('labelSearch', 'Rollout');

        expect($drawer->get('newLabelColor'))->toBe(app(TagService::class)->nextColor($this->itTracker));

        $drawer->call('createLabel');

        expect($this->project->fresh()->tags->first()->color)
            ->toBe(TagService::colors()[0]);
    });

    // Typing a name that already exists is how someone RE-USES a label. Repainting
    // every card carrying it because a swatch happened to be selected is not that.
    it('leaves an existing label its colour when the name is typed again', function () {
        $tag = app(TagService::class)->resolve($this->itTracker, ['Urgent'], $this->admin)->first();

        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->set('labelSearch', 'URGENT')
            ->call('chooseLabelColor', TagService::PALETTE['Moss'])
            ->call('createLabel');

        expect($tag->fresh()->color)->toBe($tag->color)
            ->and(DB::table('tags')->where('tracker_id', $this->itTracker->id)->count())->toBe(1);
    });

    it('repaints an existing label everywhere it appears', function () {
        $tag = app(TagService::class)->resolve($this->itTracker, ['Reporting'], $this->admin)->first();
        app(ProjectService::class)->update($this->project, ['tags' => ['Reporting']], $this->admin);

        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('toggleRecolor', $tag->id)
            ->assertSet('recoloringTagId', $tag->id)
            ->call('recolorLabel', $tag->id, TagService::PALETTE['Ocean'])
            ->assertSet('recoloringTagId', null);

        expect($tag->fresh()->color)->toBe(TagService::PALETTE['Ocean'])
            ->and($this->project->fresh()->tags->first()->color)->toBe(TagService::PALETTE['Ocean']);
    });

    // The value lands inside a style attribute on every chip, so anything that did
    // not come off a swatch is refused rather than stored.
    it('refuses a colour that is not in the palette', function () {
        $tag = app(TagService::class)->resolve($this->itTracker, ['Reporting'], $this->admin)->first();
        $before = $tag->color;

        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('recolorLabel', $tag->id, '#000000; background-image: url(x)')
            ->assertStatus(422);

        expect($tag->fresh()->color)->toBe($before);
    });

    it('refuses to recolour a tag belonging to another tracker', function () {
        $foreign = SystemContext::run(fn () => app(TagService::class)
            ->resolve($this->sdTracker, ['Elsewhere'], $this->admin)->first());

        drawerAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('recolorLabel', $foreign->id, TagService::PALETTE['Red']);
    })->throws(ModelNotFoundException::class);

    it('refuses a viewer', function () {
        $tag = app(TagService::class)->resolve($this->itTracker, ['Reporting'], $this->admin)->first();
        $before = $tag->color;

        drawerAs($this->viewer)->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('recolorLabel', $tag->id, TagService::PALETTE['Red'])
            ->assertForbidden();

        expect($tag->fresh()->color)->toBe($before);
    });

    // Every swatch carries white uppercase chip text. One pale colour in the list and
    // the board has a label nobody can read, so the ratio is asserted, not eyeballed.
    it('keeps every palette colour dark enough for white chip text', function () {
        $luminance = function (string $hex): float {
            $channel = function (int $c): float {
                $s = $c / 255;

                return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
            };

            [$r, $g, $b] = array_map('hexdec', str_split(ltrim($hex, '#'), 2));

            return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
        };

        foreach (TagService::PALETTE as $name => $hex) {
            $contrast = 1.05 / ($luminance($hex) + 0.05);

            expect($contrast)->toBeGreaterThan(4.5, "{$name} ({$hex}) is too pale for white text");
        }
    });
});
