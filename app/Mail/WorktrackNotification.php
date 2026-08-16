<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;

/**
 * One email carrying one recipient's outbox batch.
 *
 * ONE mailable for every event type, rather than one class per event, because the
 * digest case forces it: a digest is N events of MIXED type in a single message, so a
 * per-event mailable would need a sibling that renders all of them anyway — and then
 * every event exists in two renderers that can drift. Each event type contributes a
 * blade partial instead, and the same partial is used whether it arrives alone or in a
 * batch of nine.
 *
 * Deliberately NOT a ShouldQueue mailable. `notification_outbox` IS the queue, and
 * OutboxDispatcher explains at length why re-queueing through Laravel would reintroduce
 * the dual-write the outbox exists to remove.
 *
 * Renders from the payload SNAPSHOT only — never from a live model. A step change email
 * must describe the step names as they were when the card moved, not as someone renamed
 * them the following afternoon.
 */
class WorktrackNotification extends Mailable
{
    /**
     * @param  array<int,array{type:string,payload:array<string,mixed>,occurred_at:Carbon,link:string}>  $events
     */
    public function __construct(
        public User $recipient,
        public array $events,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        // `events` is NOT passed here, and cannot usefully be: Mailable::buildViewData()
        // collects public properties AFTER the with() data and overwrites it, so a
        // decorated copy under the same name is silently replaced by the raw property.
        // The view calls viewFor() itself instead — one source for the list, one for the
        // partial each entry renders through.
        return new Content(markdown: 'mail.notification', with: [
            'multiple' => count($this->events) > 1,
        ]);
    }

    /**
     * `project.moved` -> `mail.events.project-moved`, falling back to a generic block.
     *
     * The fallback is the point: OutboxWriter will grow event types (FR-7.4 names
     * several that have no writer yet), and a type with no partial must still deliver a
     * usable email rather than throwing inside the mailer and burning the row's retries
     * on a template that is never going to appear.
     */
    public static function viewFor(string $type): string
    {
        $candidate = 'mail.events.'.str_replace(['.', '_'], '-', $type);

        return View::exists($candidate) ? $candidate : 'mail.events.generic';
    }

    /**
     * Subject lines name the thing that happened, because that is what someone scanning
     * an inbox is deciding between. "Worktrack notification" would make every message in
     * the product indistinguishable from every other.
     */
    private function subjectLine(): string
    {
        if (count($this->events) !== 1) {
            return 'Worktrack — '.count($this->events).' updates';
        }

        $event = $this->events[0];
        $payload = $event['payload'];

        $project = $payload['project_name'] ?? 'a project';
        $tracker = isset($payload['tracker_name']) ? ' — '.$payload['tracker_name'] : '';

        return match ($event['type']) {
            'project.moved' => $project.' moved to '.($payload['to_step'] ?? 'a new step').$tracker,

            'task.assigned' => ($count = count($payload['tasks'] ?? [])) > 1
                ? $count.' tasks assigned to you on '.$project
                : 'Task assigned to you: '.($payload['task_title'] ?? $project),

            'comment.mentioned' => (($payload['mentions'][0]['by'] ?? null) ?: 'Someone')
                .' mentioned you on '.$project,

            'project.stalled' => 'Stalled: '.$project.$tracker,

            default => 'Worktrack update — '.$project,
        };
    }
}
