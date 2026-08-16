<?php

/*
 * Attachment policy — FR-6.
 *
 * Everything an upload is checked against lives here rather than being scattered
 * through the service, so "what may be uploaded" can be read and changed in one place.
 */

return [

    // FR-6.1 — R2 in production. Overridable per environment so tests can point at a
    // fake disk without the service knowing the difference.
    'disk' => env('ATTACHMENTS_DISK', 'r2'),

    /*
     * FR-6.5 — a hard ceiling, in bytes, on ONE file. The number of files per card is
     * deliberately unlimited; this is the only size gate.
     *
     * 48 MB rather than a round 50, and the missing 2 MB is the whole reason this is a
     * comment. The upload is proxied through PHP (see the NFR-S9 note in
     * AttachmentService::sniff — the bytes must be on local disk for finfo to read
     * them), so it is bounded by `post_max_size`, which is 50M on this install. A
     * multipart request carries the file PLUS its boundary headers, the CSRF token and
     * Livewire's own fields, so a file at exactly the ini limit produces a request
     * OVER it. PHP then discards the entire body — $_POST is empty, so the CSRF field
     * is missing too, and the user gets "Page Expired" instead of anything about size.
     * That is the FR-6.8 clarity failure recorded in VERIFICATION.md (post-max-size),
     * and the headroom is what keeps the app's own limit the one that speaks first.
     *
     * Raising this past ~48 MB therefore requires raising post_max_size AND
     * upload_max_filesize in php.ini to match, or the limit is a lie the user
     * discovers as a 419.
     */
    'max_bytes' => (int) env('ATTACHMENTS_MAX_BYTES', 48 * 1024 * 1024),

    /*
     * FR-6.5 — an ALLOWLIST of MIME types, sniffed from the file's magic bytes and
     * never taken from the extension or the browser-supplied Content-Type (NFR-S9).
     *
     * A denylist is the wrong shape here: it fails open on every type nobody thought
     * of, and the one class of file that must never land in this bucket — anything
     * executable — has too many representations to enumerate. An allowlist fails
     * closed, which is the direction an upload endpoint should fail.
     */
    'allowed_mimes' => [
        // documents
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/rtf',

        // images — the screenshot case, which is most of the real traffic
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/tiff',
        'image/svg+xml',

        // text, logs and structured data. The last four arrive as text/plain from
        // libmagic and are recovered by extension in AttachmentService::sniff.
        'text/plain',
        'text/csv',
        'text/markdown',
        'application/json',
        'text/xml',
        'application/xml',
        'application/yaml',

        /*
         * Email, saved out of Outlook. A forwarded vendor reply IS the artifact in a
         * procurement or incident thread, and without this the file goes back to being
         * pasted into a comment where the headers are lost.
         *
         * application/CDFV2 is the legacy OLE container libmagic reports for .msg —
         * and also for .msi. That is safe here only because it is safe TWICE: the
         * blocked_extensions gate below rejects .msi on the filename regardless of what
         * the bytes sniff as, and .doc/.xls above are already the same container.
         */
        'message/rfc822',
        'application/vnd.ms-outlook',
        'application/CDFV2',

        /*
         * Video and audio — a screen recording of a fault is often the only artifact
         * that survives an intermittent problem, and a 40 MB clip now fits under
         * max_bytes. Both IANA names and the x- variants libmagic actually emits are
         * listed, because a type absent from this list is rejected outright.
         */
        'video/mp4',
        'video/quicktime',
        'video/webm',
        'video/x-msvideo',
        'video/x-matroska',
        'audio/mpeg',
        'audio/wav',
        'audio/x-wav',
        'audio/mp4',
        'audio/x-m4a',
        'audio/ogg',

        // design and CAD source. HEIC is here because it is what an unconfigured
        // iPhone produces, and a rejected site photo just becomes an emailed one.
        'image/heic',
        'image/heif',
        'image/vnd.adobe.photoshop',
        'image/vnd.dwg',
        'image/vnd.dxf',

        // archives. Deliberately included — a packaged log bundle is a normal
        // attachment — and deliberately NOT extended to self-extracting formats.
        'application/zip',
        'application/gzip',
        'application/x-tar',
        'application/x-7z-compressed',

        /*
         * NOT listed, and the omission is the point: application/octet-stream. It is
         * what libmagic returns when it recognises NOTHING, so allowing it would turn
         * this allowlist into a denylist for every format nobody thought of — the
         * exact fail-open shape the comment above rejects.
         */
    ],

    /*
     * A second gate on the FILENAME, applied after the MIME allowlist.
     *
     * Belt and braces on purpose. A .exe renamed to .pdf is caught by the sniffer; a
     * genuinely benign-typed file *named* invoice.pdf.exe is not — the sniffer sees
     * whatever the bytes are, and the danger is what the operating system does when a
     * colleague double-clicks the downloaded name. FR-6.5 says reject executables and
     * scripts outright, so both halves are checked.
     */
    'blocked_extensions' => [
        'exe', 'com', 'bat', 'cmd', 'msi', 'scr', 'pif', 'cpl', 'jar', 'app', 'dmg',
        'sh', 'bash', 'zsh', 'ps1', 'psm1', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh',
        'hta', 'reg', 'dll', 'sys', 'lnk', 'iso', 'img', 'php', 'phtml', 'py', 'rb', 'pl',
    ],

    /*
     * FR-6.3 — signed-URL lifetime in MINUTES, clamped to [1, 60] in code.
     *
     * 1 is deliberate: the URL is minted fresh behind an authorizing route, redirected
     * to immediately, and never rendered into HTML or email. R2 validates the
     * signature when the request starts, so a short window does not truncate a slow
     * download of a large file.
     */
    'temp_url_ttl_minutes' => (int) env('ATTACHMENTS_TEMP_URL_TTL', 1),

    // How long a row may sit in 'pending' before the reaper treats it as an upload
    // that died mid-flight and cleans it up. Generous enough to survive a slow
    // connection on a 25 MB file.
    'pending_ttl_minutes' => (int) env('ATTACHMENTS_PENDING_TTL', 60),
];
