<?php

use App\Authorization\SystemContext;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Projects\CommentService;
use App\Services\Projects\ProjectService;
use App\Services\Trackers\TrackerService;
use Illuminate\Support\Facades\DB;

/**
 * FR-4.5 / FR-7.4 — naming a colleague in a comment and having them hear about it.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS FILE EXISTS TO STOP HAPPENING AGAIN.
 *
 * The mention matcher shipped requiring a member's WHOLE name to appear at the front
 * of what was typed, so "@jonathan" resolved to nobody while "@Jonathan Cruz" worked.
 * Every test that covered it interpolated `{$user->name}` into the comment body, which
 * meant the entire suite only ever exercised the one spelling real people never use.
 * The feature was completely broken in production and completely green in CI.
 *
 * So the cases below are written as LITERAL TEXT wherever the point is how somebody
 * typed something. A test that builds its input out of the same value it asserts
 * against cannot see a matcher that only accepts its own output.
 * ═══════════════════════════════════════════════════════════════════════════════
 */
/** @return array<int, string> the names a comment on the shared project would notify */
function named(string $body): array
{
    return app(CommentService::class)
        ->resolveMentions(test()->project, $body)
        ->pluck('name')
        ->all();
}

function renameTo(User $user, string $name): User
{
    SystemContext::run(fn () => $user->update(['name' => $name]));

    return $user->fresh();
}

beforeEach(function () {
    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $svc = app(TrackerService::class);
    $this->tracker = $svc->create(['name' => 'IT Technical'], $this->admin);
    $this->other = $svc->create(['name' => 'Systems Development'], $this->admin);

    $this->member = makeUser('joy@gmail.com', UserRole::Member);
    $this->colleague = renameTo(makeUser('jonathan@gmail.com', UserRole::Member), 'Jonathan Cruz');
    $this->outsider = renameTo(makeUser('sam@gmail.com', UserRole::Member), 'Sam Villar');

    $svc->addMember($this->tracker, $this->member, $this->admin);
    $svc->addMember($this->tracker, $this->colleague, $this->admin);
    $svc->addMember($this->other, $this->outsider, $this->admin);

    $this->project = app(ProjectService::class)->create(
        $this->tracker,
        ['name' => 'Davao CCTV installation'],
        $this->admin,
    );
});

describe('reading a name out of what somebody typed', function () {

    it('finds a colleague from their first name alone', function () {
        // THE bug. Nobody types a colleague's full legal name into a comment box, and
        // for as long as this returned nothing the feature had no working spelling.
        expect(named('@jonathan'))->toBe(['Jonathan Cruz']);
    });

    it('finds them with a sentence after the name', function () {
        expect(named('@jonathan can you confirm the installation window?'))->toBe(['Jonathan Cruz']);
    });

    it('finds them through punctuation that belongs to the sentence', function () {
        expect(named('Hi @Jonathan, please review the scope'))->toBe(['Jonathan Cruz']);
    });

    it('still finds them from the full name, typed or inserted by the picker', function () {
        expect(named('@Jonathan Cruz can you confirm?'))->toBe(['Jonathan Cruz']);
    });

    it('ignores case and accents in either direction', function () {
        renameTo($this->colleague, 'José Rizal');

        // The keyboard most comments are typed on does not have é on it, and the name
        // as the admin registered it does.
        expect(named('@jose can you look'))->toBe(['José Rizal'])
            ->and(named('@JOSÉ RIZAL please look'))->toBe(['José Rizal']);
    });

    it('finds a name longer than the four words the old matcher could hold', function () {
        renameTo($this->colleague, 'Ma. Krystal Gail Mission Reyes');

        // Five tokens. The previous capture stopped at four, so the candidate was
        // strictly shorter than the name and no reading could ever equal it — this
        // member was unmentionable by any spelling at all.
        expect(named('@Ma. Krystal Gail Mission Reyes please confirm'))->toBe(['Ma. Krystal Gail Mission Reyes'])
            ->and(named('@krystal please confirm'))->toBe(['Ma. Krystal Gail Mission Reyes']);
    });

    it('names nobody when the first name could be either of two colleagues', function () {
        $second = renameTo(makeUser('jon@gmail.com', UserRole::Member), 'Jonathan Reyes');
        app(TrackerService::class)->addMember($this->tracker, $second, $this->admin);

        // Mailing the wrong colleague is worse than mailing none, and the miss is not
        // silent — the mention renders unhighlighted, so the author can see it and say
        // which Jonathan they meant.
        expect(named('@jonathan please look'))->toBe([])
            ->and(named('@jonathan cruz please look'))->toBe(['Jonathan Cruz'])
            ->and(named('@Jonathan Reyes please look'))->toBe(['Jonathan Reyes']);
    });

    it('prefers the longest reading that names somebody', function () {
        $longer = renameTo(makeUser('jc@gmail.com', UserRole::Member), 'Jonathan Cruz Santos');
        app(TrackerService::class)->addMember($this->tracker, $longer, $this->admin);

        // "@Jonathan Cruz" is exactly one member's name, so it goes there rather than
        // to the longer one it is also a prefix of.
        expect(named('@Jonathan Cruz please look'))->toBe(['Jonathan Cruz'])
            ->and(named('@Jonathan Cruz Santos please look'))->toBe(['Jonathan Cruz Santos'])
            // And "@jonathan" alone is now ambiguous between the two.
            ->and(named('@jonathan please look'))->toBe([]);
    });

    it('does not stop at a shorter reading once a longer one has named somebody', function () {
        $longer = renameTo(makeUser('jc@gmail.com', UserRole::Member), 'Jonathan Cruz Santos');
        app(TrackerService::class)->addMember($this->tracker, $longer, $this->admin);

        // The old matcher answered "Jonathan Cruz" here, because it only ever asked
        // whether a member's name was at the FRONT of what was typed. The author wrote
        // more than that on purpose.
        expect(named('@Jonathan Cruz Santos, can you confirm?'))->toBe(['Jonathan Cruz Santos']);
    });

    it('reads an email address as an email address', function () {
        renameTo($this->colleague, 'Example.com');

        // The @ in an address follows a letter, and a signature block at the foot of a
        // comment must not mail whoever happens to be registered under a domain-shaped
        // display name.
        expect(named('reach me at joy@example.com any time'))->toBe([])
            ->and(named('reach me at @example.com'))->toBe(['Example.com']);
    });

    it('never resolves a mention to somebody outside the tracker', function () {
        // Otherwise the mention box is an existence oracle for the whole user table,
        // one @ at a time — and the looser matching makes it a cheaper one.
        expect(named('@sam please look'))->toBe([])
            ->and(named('@Sam Villar please look'))->toBe([]);
    });

    it('never resolves a mention to a suspended member', function () {
        SystemContext::run(fn () => $this->colleague->update(['status' => 'suspended']));

        expect(named('@jonathan please look'))->toBe([]);
    });

    it('names each person once however many times they are mentioned', function () {
        expect(named('@jonathan and again @Jonathan Cruz and once more @jonathan'))
            ->toBe(['Jonathan Cruz']);
    });
});

describe('what the comment does with a name it found', function () {

    it('queues one notification for a colleague named by first name only', function () {
        // The end-to-end shape of the original bug: before the fix this comment
        // resolved nobody, wrote no mention row and sent no mail, with nothing
        // anywhere reporting that it had not.
        $comment = app(CommentService::class)->create(
            $this->project,
            '@jonathan can you confirm the installation window?',
            $this->member,
        );

        expect($comment->mentions()->pluck('users.name')->all())->toBe(['Jonathan Cruz'])
            ->and(DB::table('notification_outbox')
                ->where('event_type', 'comment.mentioned')
                ->pluck('user_id')->all())->toBe([$this->colleague->id]);
    });

    it('notifies somebody first named by an edit', function () {
        $svc = app(CommentService::class);
        $comment = $svc->create($this->project, 'Vendor quote is in.', $this->member);

        $svc->update($comment, '@jonathan the vendor quote is in.', $this->member);

        expect($comment->fresh()->mentions()->pluck('users.name')->all())->toBe(['Jonathan Cruz'])
            ->and(DB::table('notification_outbox')
                ->where('event_type', 'comment.mentioned')
                ->pluck('user_id')->all())->toBe([$this->colleague->id]);
    });
});
