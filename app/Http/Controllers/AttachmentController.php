<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\Attachments\AttachmentRejected;
use App\Services\Attachments\AttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * FR-6.3 / FR-6.4 — the only way to reach a file.
 *
 * The signed URL is minted PER CLICK, behind this authorization check, and is never
 * rendered into a page or an email. That is the property the whole control rests on:
 * once minted, a signed URL is a bearer token that works for anyone holding it, and a
 * page is exactly the place URLs get copied out of.
 *
 * The route is deliberately a redirect rather than a proxy. Proxying would put every
 * download through PHP and hold a worker for the length of a 25 MB transfer, and the
 * short TTL (default one minute) plus per-click minting keeps the exposure window
 * smaller than the one a long-lived proxied session would have anyway.
 *
 * TWO ACTIONS, ONE GATE. Preview and download differ only in what the signed URL asks
 * the storage origin to do with the bytes — they are the same read of the same object
 * by the same person, so they share `download` rather than growing a second ability
 * that could drift out of step with it. Which types may actually be shown inline is
 * decided in AttachmentService::responseOverrides() against an allowlist, never by the
 * URL that was clicked; hitting the preview route with a .zip returns the same forced
 * download the other route would.
 */
class AttachmentController extends Controller
{
    public function __construct(private AttachmentService $attachments) {}

    public function download(Attachment $attachment): RedirectResponse
    {
        return $this->serve($attachment, preview: false);
    }

    public function preview(Attachment $attachment): RedirectResponse
    {
        return $this->serve($attachment, preview: true);
    }

    private function serve(Attachment $attachment, bool $preview): RedirectResponse
    {
        // Route-model binding resolves through TrackerVisibilityScope, so a public_id
        // from an invisible tracker 404s before this line — the policy below is the
        // second gate, not the first.
        Gate::authorize('download', $attachment);

        try {
            return redirect()->away($this->attachments->temporaryUrl($attachment, $preview));
        } catch (AttachmentRejected) {
            // A row that is pending, failed or mid-delete. Indistinguishable from a
            // file that never existed, on purpose.
            abort(404);
        }
    }
}
