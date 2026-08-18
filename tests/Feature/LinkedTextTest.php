<?php

use App\Authorization\AccessContext;
use App\Enums\UserRole;
use App\Livewire\ProjectDrawer;
use App\Models\User;
use App\Services\Projects\CommentService;
use App\Services\Projects\ProjectService;
use App\Services\Trackers\TrackerService;
use App\Support\Linkify;
use Livewire\Livewire;

/**
 * FR-4.5 — a link somebody pasted into a comment is a link you can click.
 *
 * The half of this that matters is not the anchor. It is that this is the ONLY place
 * in the application that emits HTML assembled from user input, so most of what
 * follows is about what cannot get out: the prose, the href and the label are escaped
 * as separate pieces and the anchor tags are the only markup Linkify writes.
 *
 * Written against Linkify directly AND through the drawer, because those are two
 * different failures. A helper that escapes correctly is worth nothing if the view
 * echoes it through the escaping path a second time — the reader then sees
 * `&lt;a href=...` where the link should be — and only a render test sees that.
 */
function linkedAs(User $user)
{
    app(AccessContext::class)->forUser($user);

    return Livewire::actingAs($user);
}

beforeEach(function () {
    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $this->tracker = app(TrackerService::class)->create(['name' => 'IT Technical'], $this->admin);
    $this->member = makeUser('joy@gmail.com', UserRole::Member);
    app(TrackerService::class)->addMember($this->tracker, $this->member, $this->admin);
});

describe('finding the links in what someone typed', function () {

    it('makes a pasted url clickable and leaves the rest of the sentence alone', function () {
        $html = Linkify::text('Spec is at https://docs.example.com/spec?tab=t.0 — please read it.')->toHtml();

        expect($html)
            ->toContain('<a href="https://docs.example.com/spec?tab=t.0" target="_blank" rel="noopener noreferrer nofollow">')
            ->toContain('Spec is at ')
            ->toContain('please read it.');
    });

    it('gives a bare host the scheme the browser would have assumed', function () {
        // And gives it https, not http: this is a link pasted in 2026, and the
        // downgrade would be ours rather than the author's.
        expect(Linkify::text('see www.example.com/quote')->toHtml())
            ->toContain('href="https://www.example.com/quote"')
            // The LABEL stays what was typed. Rewriting the visible text of something
            // a colleague pasted is how a link stops matching the one in their email.
            ->toContain('>www.example.com/quote</a>');
    });

    it('leaves the full stop that ended the sentence out of the link', function () {
        $html = Linkify::text('Filed at https://example.com/tickets/4821.')->toHtml();

        expect($html)
            ->toContain('href="https://example.com/tickets/4821"')
            ->toContain('</a>.');
    });

    /*
     * The other half of that rule, and the reason it is not simply "strip trailing
     * punctuation": SharePoint and Confluence both mint paths with brackets in them,
     * and a matched pair belongs to the URL.
     */
    it('keeps a bracket that the url itself opened', function () {
        expect(Linkify::text('https://wiki.example.com/Shared_(Docs)')->toHtml())
            ->toContain('href="https://wiki.example.com/Shared_(Docs)"');
    });

    it('closes a parenthesis it never opened', function () {
        $html = Linkify::text('(see https://example.com/a)')->toHtml();

        expect($html)
            ->toContain('href="https://example.com/a"')
            ->toContain('</a>)');
    });

    it('links every url in a comment, not just the first', function () {
        $html = Linkify::text("before https://a.example.com/1\nafter https://b.example.com/2")->toHtml();

        expect(substr_count($html, '<a href='))->toBe(2)
            ->and($html)->toContain('https://a.example.com/1')
            ->and($html)->toContain('https://b.example.com/2');
    });

    it('says nothing about a string that is only a scheme', function () {
        // "https://" on its own is a typo, not a destination.
        expect(Linkify::text('typed https:// and gave up')->toHtml())
            ->not->toContain('<a href');
    });

    it('leaves text with no url in it byte-for-byte alone', function () {
        expect(Linkify::text('Waiting on the vendor quote.')->toHtml())
            ->toBe('Waiting on the vendor quote.');
    });

    it('has nothing to say about an empty or missing body', function () {
        expect(Linkify::text(null)->toHtml())->toBe('')
            ->and(Linkify::text('')->toHtml())->toBe('');
    });
});

describe('what cannot get out of a comment', function () {

    it('escapes markup in the prose around a link', function () {
        $html = Linkify::text('<script>alert(1)</script> and https://example.com/a')->toHtml();

        expect($html)
            ->not->toContain('<script>')
            ->toContain('&lt;script&gt;')
            // The anchor still happens: escaping the prose must not cost the feature.
            ->toContain('href="https://example.com/a"');
    });

    it('escapes a url built to break out of the href attribute', function () {
        $html = Linkify::text('https://example.com/"onmouseover="alert(1)')->toHtml();

        expect($html)
            ->not->toContain('onmouseover="alert(1)"')
            ->toContain('&quot;onmouseover=&quot;');
    });

    /*
     * The reason the pattern requires a scheme it recognises rather than filtering
     * afterwards: these are not links that get rejected, they are text that is never
     * matched in the first place.
     */
    it('never makes a link out of a scheme that executes', function () {
        foreach (['javascript:alert(1)', 'data:text/html;base64,PHNjcmlwdD4=', 'vbscript:msgbox(1)'] as $hostile) {
            expect(Linkify::text("look at {$hostile}")->toHtml())->not->toContain('<a href');
        }
    });

    it('does not let an entity in the query string become part of the markup', function () {
        // Escape-then-match would have turned `&b=2` into `&amp;b=2` and then either
        // swallowed the entity into the href or stopped the URL at the ampersand.
        // Correct HTML, wrong link — which is why the split happens first.
        expect(Linkify::text('https://example.com/s?a=1&b=2')->toHtml())
            ->toContain('href="https://example.com/s?a=1&amp;b=2"')
            ->toContain('>https://example.com/s?a=1&amp;b=2</a>');
    });
});

describe('the screens that show free text', function () {

    it('renders a comment link as a link rather than as escaped markup', function () {
        $project = app(ProjectService::class)->create(
            $this->tracker,
            ['name' => 'Live Deployment'],
            $this->admin,
        );

        app(CommentService::class)->create(
            $project,
            'https://docs.example.com/document/d/1vXEge0O8/edit?tab=t.0',
            $this->member,
        );

        linkedAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $project->public_id)
            ->assertSee('<a href="https://docs.example.com/document/d/1vXEge0O8/edit?tab=t.0" target="_blank"', false);
    });

    it('renders a link in the card description too', function () {
        // Same class of text, entered in a different box. A rule that applied to one
        // and not the other would be a rule nobody could predict.
        $project = app(ProjectService::class)->create(
            $this->tracker,
            [
                'name' => 'Core switch replacement',
                'description' => 'Brief: https://docs.example.com/brief',
            ],
            $this->admin,
        );

        linkedAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $project->public_id)
            ->assertSee('href="https://docs.example.com/brief"', false);
    });

    it('does not render markup a comment author typed', function () {
        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'Firewall upgrade'], $this->admin);

        app(CommentService::class)->create($project, '<img src=x onerror=alert(1)>', $this->member);

        linkedAs($this->member)->test(ProjectDrawer::class)
            ->call('openFor', $project->public_id)
            ->assertDontSee('<img src=x', false)
            ->assertSee('&lt;img src=x', false);
    });
});
