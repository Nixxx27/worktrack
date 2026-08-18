<?php

namespace App\Services\Attachments;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ActivityRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The R2 upload lifecycle (FR-6).
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * THE TRANSACTION BOUNDARY IS THE WHOLE DESIGN HERE.
 *
 * VERIFICATION.md attachments (a) records the version of this that held an InnoDB
 * transaction open across the multi-second network PUT to R2. Under any real upload
 * volume that pins row locks on `projects` for the duration of the slowest client's
 * connection, and a stalled upload becomes a stalled board for everyone.
 *
 * So the write is THREE separate steps, and never one transaction:
 *
 *   1. A short transaction inserts the row as 'pending'. Committed immediately.
 *   2. The PUT to R2 happens with NO transaction open.
 *   3. A short transaction promotes the row to 'available' and bumps the counter.
 *
 * The failure modes this produces are all recoverable and all visible, which is the
 * property FR-6.8 actually needs. A crash between 1 and 2 leaves a 'pending' row with
 * no object — reaped by `pending_ttl_minutes`, and never listed or signable because
 * only 'available' rows are. A crash between 2 and 3 leaves an orphaned object whose
 * key is recorded in a pending row, so it is findable rather than lost. What cannot
 * happen is the thing FR-6.8 forbids: a listed attachment with nothing behind it.
 * ═══════════════════════════════════════════════════════════════════════════════
 */
class AttachmentService
{
    public function __construct(private ActivityRecorder $activity) {}

    /**
     * Validate, store, and record a file.
     *
     * @param  Task|null  $task  attach to a checklist item rather than the card itself (FR-6.2)
     *
     * @throws AttachmentRejected when the file fails a policy check — before anything
     *                            is written to the database or the bucket
     */
    public function upload(Project $project, UploadedFile $file, User $actor, ?Task $task = null): Attachment
    {
        $mime = $this->sniff($file);

        $this->assertAllowed($file, $mime);

        if ($task !== null && $task->project_id !== $project->id) {
            // The composite FK would reject this anyway; the exception is readable.
            throw new AttachmentRejected('That task does not belong to this project.');
        }

        $objectKey = $this->objectKey($project, $file);

        // ── step 1: the pending row, in its own short transaction ──────────────
        $attachment = DB::transaction(fn () => Attachment::create([
            'tracker_id' => $project->tracker_id,
            'project_id' => $project->id,
            'task_id' => $task?->id,
            'object_key' => $objectKey,
            'disk' => $this->disk(),
            'original_filename' => $this->safeFilename($file),
            'extension' => $this->extension($file),
            'mime_type' => $mime,
            'size_bytes' => $file->getSize(),
            'status' => 'pending',
            'uploaded_by_user_id' => $actor->id,
        ]));

        // ── step 2: the network PUT, with NO transaction open ──────────────────
        try {
            Storage::disk($this->disk())->putFileAs(
                dirname($objectKey),
                $file,
                basename($objectKey),
                // Explicit, not inherited: an attachment that lands in a bucket
                // configured public-by-default would be readable without a signature,
                // which is the entire control FR-6.3 rests on.
                ['visibility' => 'private', 'ContentType' => $mime],
            );
        } catch (\Throwable $e) {
            // The row stays behind as 'failed' rather than being deleted, so a
            // repeated failure is visible to someone looking rather than being an
            // absence nobody can see. FR-6.8 wants a clear error, not a silent no-op.
            $attachment->forceFill(['status' => 'failed'])->save();

            Log::error('Attachment upload to R2 failed', [
                'attachment_id' => $attachment->id,
                'object_key' => $objectKey,
                'error' => $e->getMessage(),
            ]);

            throw new AttachmentRejected(
                'The file could not be stored. Nothing was attached — please try again.',
                previous: $e,
            );
        }

        // ── step 3: promote, in its own short transaction ──────────────────────
        return DB::transaction(function () use ($attachment, $project, $actor, $task) {
            $attachment->forceFill(['status' => 'available'])->save();

            $this->syncCount($project);

            // FR-4.7 counts an attachment upload as activity, so the stall clock resets.
            $this->activity->record($project, 'attachment_added', $actor, [
                'filename' => $attachment->original_filename,
                'size_bytes' => $attachment->size_bytes,
                'task' => $task?->title,
            ]);

            return $attachment->refresh();
        });
    }

    /**
     * FR-6.3/FR-6.4 — a short-lived signed URL, minted only after the caller has
     * already been authorized.
     *
     * This method does NOT authorize. It is called from a route that has already run
     * the policy, and keeping the check out of here means it cannot be accidentally
     * satisfied by a service call that skips it. What it does enforce is that only an
     * 'available' row is ever signable, so a pending or failed upload has no URL at all.
     *
     * @param  bool  $preview  ask for the object to be shown rather than saved. A
     *                         REQUEST, not an instruction: responseOverrides() decides
     *                         what the type actually permits.
     */
    public function temporaryUrl(Attachment $attachment, bool $preview = false): string
    {
        if ($attachment->status !== 'available') {
            throw new AttachmentRejected('That file is not available.');
        }

        // Clamped, because the value comes from the environment: a typo of 0 would
        // mint URLs that are already expired, and a typo of 6000 would leave a
        // shareable link alive for four days.
        $ttl = max(1, min(60, (int) config('attachments.temp_url_ttl_minutes', 1)));

        return Storage::disk($attachment->disk)->temporaryUrl(
            $attachment->object_key,
            now()->addMinutes($ttl),
            $this->responseOverrides($attachment, $preview),
        );
    }

    /**
     * What the signed URL asks the storage origin to do with the object when it is
     * fetched — the whole download-versus-preview decision, in one readable place.
     *
     * ═══════════════════════════════════════════════════════════════════════════════
     * THE CALLER ASKS; THIS METHOD DECIDES.
     *
     * $preview arrives from a route, which means it arrives from a URL, which means it
     * is user input. It can only ever WIDEN as far as the type allows: a request to
     * preview a .zip or an .svg produces the same forced download it always did,
     * because isPreviewable() consults an allowlist rather than the request.
     *
     * The three outcomes, and why each is what it is:
     *
     *   1. Not previewable → `attachment`, exactly as before. Unchanged behaviour for
     *      every type that is not on a list, which is the fail-closed direction.
     *   2. Previewable as itself → `inline` with the real type. Images, PDF, video and
     *      audio: formats a browser DISPLAYS and cannot run.
     *   3. Previewable as source → `inline`, with the type overridden to text/plain.
     *      This is what makes a .html or .js previewable at all. Serving one with its
     *      real type would execute it on the storage origin; as text/plain the reader
     *      sees the file and nothing runs. The app's own origin was never involved
     *      either way — objects are served from R2, not from here — so the override is
     *      protecting the storage domain's origin and the reader's browser, not a
     *      session that was never in reach.
     *
     * Every branch keeps `filename=`, because the object key is deliberately opaque
     * (FR-6.6) and a preview saved from the browser's own toolbar should still land as
     * the name the uploader chose.
     * ═══════════════════════════════════════════════════════════════════════════════
     *
     * @return array<string, string>
     */
    public function responseOverrides(Attachment $attachment, bool $preview = false): array
    {
        $inline = $preview && $attachment->isPreviewable();

        $overrides = [
            'ResponseContentDisposition' => ($inline ? 'inline' : 'attachment')
                .'; filename="'.addslashes($attachment->original_filename).'"',
        ];

        if ($inline && $attachment->previewsAsSource()) {
            $overrides['ResponseContentType'] = 'text/plain; charset=utf-8';
        }

        return $overrides;
    }

    /**
     * FR-6.7 — deleting must remove the object from R2, not just hide the row.
     *
     * Ordered so that an R2 outage leaves a RETRYABLE TOMBSTONE rather than an orphan:
     * the row moves to 'deleting' first, so it stops being listed and signable
     * immediately, and is only soft-deleted once R2 confirms. A row stuck in
     * 'deleting' is a work item for the reaper; an object with no row is invisible
     * and pays storage forever.
     */
    public function delete(Attachment $attachment, User $actor): void
    {
        $project = $attachment->project;

        DB::transaction(function () use ($attachment) {
            $attachment->forceFill(['status' => 'deleting'])->save();
        });

        try {
            Storage::disk($attachment->disk)->delete($attachment->object_key);
        } catch (\Throwable $e) {
            Log::error('Attachment delete from R2 failed; row left as deleting for retry', [
                'attachment_id' => $attachment->id,
                'object_key' => $attachment->object_key,
                'error' => $e->getMessage(),
            ]);

            throw new AttachmentRejected(
                'The file could not be removed from storage. It has been hidden and will be retried.',
                previous: $e,
            );
        }

        DB::transaction(function () use ($attachment, $project, $actor) {
            $attachment->forceFill(['purged_at' => now()])->save();
            $attachment->delete();

            $this->syncCount($project);

            // Not activity: removing a file is not progress on the work, and letting
            // it reset the stall clock would hand anyone a way to make a rotting
            // project look freshly worked on.
            $this->activity->record($project, 'attachment_removed', $actor, [
                'filename' => $attachment->original_filename,
            ], countsAsActivity: false);
        });
    }

    // ── policy ──────────────────────────────────────────────────────────────────

    /**
     * The MIME type according to the FILE'S OWN BYTES.
     *
     * NFR-S9. Never $file->getClientMimeType(), which is supplied by the browser and
     * is therefore supplied by whoever is uploading, and never the extension, which
     * is just part of a string. getMimeType() runs finfo over the temporary file.
     */
    private function sniff(UploadedFile $file): string
    {
        $mime = $file->getMimeType() ?: 'application/octet-stream';

        /*
         * finfo reports several plain-text formats generically. Accepting the sniffed
         * value alone would reject a .csv or .md the allowlist plainly permits, so the
         * extension is allowed to REFINE a text/plain result — never to widen a binary one.
         *
         * That direction is the safety property, and it is why this is a match on
         * text/plain rather than a general extension override. Every target below is a
         * text format, so the worst a wrong extension achieves is mislabelling one text
         * file as another. A .exe cannot reach this branch at all: its bytes sniff as a
         * binary type, so it never sees the map, and the filename gate in
         * assertAllowed() rejects it a second time regardless.
         *
         * YAML and DXF are here because libmagic has no signature for either — both are
         * genuinely just text — and DXF in particular would otherwise be an allowlisted
         * type that can never actually be uploaded.
         *
         * HTML is here for a narrower reason: libmagic recognises a whole DOCUMENT
         * (<!doctype html>, <html>) but reports a FRAGMENT — the "<div>…" a generated
         * component arrives as — as plain text. Without the refinement, whether a page
         * uploads depends on how it happens to start. It is also the one target that a
         * browser executes rather than merely displays, so the mislabelling above is
         * worth naming explicitly: a .txt named .html gets stored as text/html. That
         * changes nothing here, because downloads are forced to
         * Content-Disposition: attachment off the storage origin (see temporaryUrl and
         * the text/html note in config/attachments.php) and nothing in this app renders
         * an attachment inline.
         */
        if ($mime === 'text/plain') {
            $mime = match (strtolower($file->getClientOriginalExtension())) {
                'csv' => 'text/csv',
                'md', 'markdown' => 'text/markdown',
                'json' => 'application/json',
                'xml' => 'text/xml',
                'yaml', 'yml' => 'application/yaml',
                'dxf' => 'image/vnd.dxf',
                'eml' => 'message/rfc822',
                'html', 'htm' => 'text/html',
                'css' => 'text/css',
                'js' => 'text/javascript',
                default => 'text/plain',
            };
        }

        return $mime;
    }

    private function assertAllowed(UploadedFile $file, string $mime): void
    {
        $max = (int) config('attachments.max_bytes');

        if ($file->getSize() > $max) {
            throw new AttachmentRejected(sprintf(
                'That file is %s. The limit is %s.',
                $this->human($file->getSize()),
                $this->human($max),
            ));
        }

        if (! in_array($mime, config('attachments.allowed_mimes', []), true)) {
            throw new AttachmentRejected(
                "Files of type {$mime} can't be attached. Allowed: documents, images, video, "
                .'audio, email, text, web files and archives.'
            );
        }

        // The second gate, on the NAME. The sniffer sees whatever the bytes are; this
        // catches what the operating system will do when a colleague double-clicks the
        // downloaded filename. Every extension in the name is checked, so
        // "invoice.pdf.exe" is rejected on the .exe rather than passed on the .pdf.
        $parts = array_map('strtolower', array_slice(explode('.', $file->getClientOriginalName()), 1));
        $blocked = array_intersect($parts, config('attachments.blocked_extensions', []));

        if ($blocked !== []) {
            throw new AttachmentRejected(
                'Executable and script files are not allowed, including inside a compound '
                .'filename like "report.pdf.'.reset($blocked).'".'
            );
        }
    }

    /**
     * FR-6.6 — unguessable, and leaking nothing about the original filename.
     *
     * attachments/{tracker ulid}/{project ulid}/{YYYY}/{MM}/{ulid}-{32 hex}
     *
     * The random suffix is what makes it unguessable even to someone who knows both
     * public ids; the date segments keep bucket listings navigable for an operator.
     * The original filename lives only in the database column, and is reattached at
     * download time via Content-Disposition.
     */
    private function objectKey(Project $project, UploadedFile $file): string
    {
        return sprintf(
            'attachments/%s/%s/%s/%s-%s',
            $project->tracker->public_id,
            $project->public_id,
            now()->format('Y/m'),
            (string) Str::ulid(),
            bin2hex(random_bytes(16)),
        );
    }

    /**
     * The display filename, stripped of anything that makes it a path or a control
     * sequence. It is echoed in HTML and in a Content-Disposition header, and it is
     * entirely attacker-chosen.
     */
    private function safeFilename(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name) ?? '';
        $name = trim($name) ?: 'attachment';

        return mb_substr($name, 0, 255);
    }

    private function extension(UploadedFile $file): ?string
    {
        $ext = strtolower($file->getClientOriginalExtension());

        return $ext !== '' ? mb_substr($ext, 0, 20) : null;
    }

    private function disk(): string
    {
        return (string) config('attachments.disk', 'r2');
    }

    private function syncCount(Project $project): void
    {
        $project->forceFill([
            'attachments_count' => Attachment::where('project_id', $project->id)
                ->where('status', 'available')
                ->count(),
        ])->save();
    }

    private function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB'] as $unit) {
            if ($bytes < 1024) {
                return $unit === 'B' ? "{$bytes} B" : round($bytes, 1)." {$unit}";
            }

            $bytes /= 1024;
        }

        return round($bytes, 1).' GB';
    }
}
