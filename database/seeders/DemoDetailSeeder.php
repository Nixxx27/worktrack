<?php

namespace Database\Seeders;

use App\Authorization\SystemContext;
use App\Enums\ProjectHealth;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use App\Services\Projects\CommentService;
use App\Services\Projects\HealthService;
use App\Services\Projects\ProjectService;
use App\Services\Projects\TaskService;
use App\Services\Trackers\TrackerService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * OPT-IN card detail for demo projects, so an opened card has something in it.
 *
 *     php artisan db:seed --class=DemoDetailSeeder
 *
 * DemoDataSeeder builds the SHAPE of a board — trackers, columns, a spread of ages
 * and health states — which is what the dashboard reads. It deliberately leaves the
 * cards themselves bare, and that is invisible until you open one: the drawer shows
 * dates, members, labels, a checklist and a conversation, and on demo data every one
 * of those is empty. This fills them.
 *
 * Split from DemoDataSeeder rather than folded into it because that seeder refuses to
 * run once trackers exist — correctly, it must never invent projects on top of real
 * work. But by the time you notice the cards are empty you have already run it, and a
 * seeder whose only fix is "wipe your database" is one you do not run. This one goes
 * the other way round: it needs projects to already be there, and adds to them.
 *
 * Re-runnable. A project that already carries detail is skipped rather than doubled,
 * so running it twice does not produce sixteen checklist items and two conversations.
 */
class DemoDetailSeeder extends Seeder
{
    /**
     * Demo teammates. Real-looking names because initials are the avatar — "AB" and
     * "CD" on every card tells you nothing about whether the grouping reads correctly.
     */
    private const PEOPLE = [
        ['Avery Stone', 'avery.stone@example.test', UserRole::Manager],
        ['Jordan Blake', 'jordan.blake@example.test', UserRole::Member],
        ['Riley Novak', 'riley.novak@example.test', UserRole::Member],
        ['Casey Moore', 'casey.moore@example.test', UserRole::Member],
        ['Sam Okafor', 'sam.okafor@example.test', UserRole::Viewer],
    ];

    public function run(): void
    {
        $admin = User::where('role', UserRole::Admin)->where('status', UserStatus::Active)->first();

        if ($admin === null) {
            $this->command->error('No active admin. Run: php artisan worktrack:user first-admin <email>');

            return;
        }

        if (SystemContext::run(fn () => Project::count()) === 0) {
            $this->command->warn('No projects to detail. Run: php artisan db:seed --class=DemoDataSeeder');

            return;
        }

        SystemContext::run(function () use ($admin) {
            $people = $this->people($admin);
            $detailed = 0;

            foreach (Project::with('tracker')->orderBy('id')->get() as $index => $project) {
                // Idempotence check on dates rather than on a flag: dates are the first
                // thing this seeder writes and the thing the drawer leads with, so a
                // project that has them has been through here (or been edited by hand,
                // in which case leaving it alone is also the right answer).
                if ($project->start_date !== null || $project->target_date !== null) {
                    continue;
                }

                $this->detail($project, $people, $admin, $index);
                $detailed++;
            }

            $this->command->info("Card detail added to {$detailed} project(s).");
        });

        $this->command->line('  Open any card on /board — dates, members, labels, checklist and comments.');
        $this->command->line('  Demo teammates sign in with password: password');
    }

    /**
     * The demo teammates, created once and made members of every tracker.
     *
     * Membership is not decoration here: ProjectService refuses an owner or assignee
     * who is not a member of the project's tracker, and TaskService refuses the same
     * for a task assignee. Without this step every write below would throw.
     *
     * @return list<User>
     */
    private function people(User $admin): array
    {
        $trackers = app(TrackerService::class);
        $people = [];

        foreach (self::PEOPLE as [$name, $email, $role]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,

                    // Explicit, not defaulted: chk_users_auth_provider requires a
                    // password on a local account and forbids one on a Google account,
                    // so the pair has to be set together or the row is rejected.
                    'auth_provider' => 'local',
                    'password' => Hash::make('password'),

                    'email_verified_at' => now(),
                    'role' => $role,
                    'status' => UserStatus::Active,
                    'approved_at' => now(),
                    'approved_by_user_id' => $admin->id,
                ],
            );

            $people[] = $user;
        }

        foreach (Tracker::all() as $tracker) {
            $trackers->addMember($tracker, $admin, $admin);

            foreach ($people as $person) {
                $trackers->addMember($tracker, $person, $admin);
            }
        }

        return $people;
    }

    /**
     * Fill one card.
     *
     * Everything goes through the services rather than straight to the models, so the
     * demo board carries a real activity trail — the drawer's right-hand rail is one
     * of the things being demonstrated, and a card whose history is empty demonstrates
     * the opposite of what it should.
     *
     * @param  list<User>  $people
     */
    private function detail(Project $project, array $people, User $admin, int $index): void
    {
        $projects = app(ProjectService::class);
        $tasks = app(TaskService::class);
        $comments = app(CommentService::class);
        $health = app(HealthService::class);

        // Rotating rather than random: a fixed seed means the screenshot you take today
        // and the one you take after re-seeding show the same board, and "did that
        // change because of my edit?" stays an answerable question.
        $owner = $people[$index % count($people)];
        $second = $people[($index + 2) % count($people)];
        $third = $people[($index + 3) % count($people)];

        // A deliberate spread, because the date UI has four distinct appearances and a
        // demo that only ever shows one of them hides the other three: comfortably
        // ahead, due this week, overdue (red), and never agreed at all.
        [$startOffset, $dueOffset] = match ($index % 4) {
            0 => [-30, 21],     // running, plenty of room
            1 => [-14, 3],      // due this week
            2 => [-45, -6],     // overdue — the case the red styling exists for
            default => [-9, null], // no due date agreed yet
        };

        $projects->update($project, [
            'description' => $this->description($project->name),
            'start_date' => now()->addDays($startOffset)->toDateString(),
            'target_date' => $dueOffset === null ? null : now()->addDays($dueOffset)->toDateString(),
            'priority' => ['low', 'normal', 'high', 'urgent'][$index % 4],
            'owner_user_id' => $owner->id,

            // Card three onward carries two assignees so the overlapping-initials
            // treatment on the card front is actually exercised.
            'assignees' => $index % 3 === 0
                ? [$second->id]
                : [$second->id, $third->id],

            'tags' => $this->tags($index),
        ], $admin);

        $this->checklist($project->fresh(), $tasks, $owner, $second, $index);

        // One card in three is left without a conversation. An empty rail is a state
        // the drawer has to render well too, and seeding every card hides it.
        if ($index % 3 !== 2) {
            $comments->create(
                $project->fresh(),
                "Kicked this off with {$second->name}. Scope is agreed, waiting on the vendor quote before we commit to the date.",
                $owner,
            );

            $comments->create(
                $project->fresh(),
                "Thanks @{$owner->name} — I'll pick up the testing once the quote lands.",
                $second,
            );
        }

        // The reason is the point of the flag. A card marked At Risk with no reason is
        // exactly the uninformative state FR-4.6 exists to prevent, so the demo should
        // not model it.
        if (in_array($project->health, [ProjectHealth::AtRisk, ProjectHealth::Stalled, ProjectHealth::OnHold], true)) {
            $health->set($project->fresh(), $project->health, match ($project->health) {
                ProjectHealth::AtRisk => 'Vendor quote is a week late and the window is tightening.',
                ProjectHealth::Stalled => 'Blocked on budget approval — nothing moves until finance signs.',
                default => 'Paused until the new fiscal year opens.',
            }, $admin);
        }
    }

    private function checklist(Project $project, TaskService $tasks, User $owner, User $helper, int $index): void
    {
        $items = [
            ['Scope and requirements agreed', $owner, -20, true],
            ['Vendor quotes gathered', $helper, -12, true],
            ['Budget approved', $owner, -5, $index % 2 === 0],
            ['Change window booked', $helper, 4, false],
            ['Rollback plan written', $owner, 6, false],
            ['Handover to support', $helper, 12, false],
        ];

        // A varying number of items, so the card-front counter shows a range of
        // fractions rather than 6/6 on every card in the column.
        foreach (array_slice($items, 0, 3 + ($index % 4)) as [$title, $assignee, $dueOffset, $done]) {
            $task = $tasks->create($project, [
                'title' => $title,
                'assignee_user_id' => $assignee->id,
                'due_date' => now()->addDays($dueOffset)->toDateString(),
            ], $assignee);

            if ($done) {
                $tasks->setDone($task, true, $assignee);
            }
        }
    }

    /** @return list<string> */
    private function tags(int $index): array
    {
        // Drawn from a small pool with overlap, because the board filter is only worth
        // looking at when a label appears on more than one card.
        $pool = [
            ['infrastructure', 'q3'],
            ['security', 'compliance'],
            ['q3', 'vendor'],
            ['infrastructure', 'urgent', 'q3'],
            ['reporting'],
            ['security'],
        ];

        return $pool[$index % count($pool)];
    }

    private function description(string $name): string
    {
        return "{$name}.\n\n"
            .'Raised after the quarterly review flagged this as a gap. The work is scoped '
            .'in the attached brief; the dates below are the agreed window with the vendor, '
            .'not an estimate. Anything that moves the due date needs a note here first.';
    }
}
