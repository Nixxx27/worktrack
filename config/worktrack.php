<?php

return [

    // NFR-U5 — all timestamps render in the organisation's local time. Durations are
    // always computed in UTC seconds; only calendar boundaries use this.
    'default_timezone' => env('WORKTRACK_TIMEZONE', 'Asia/Manila'),

    // Shown under the wordmark on the sign-in screen. Set it to your team or
    // department so the login page belongs to the people using it.
    'org_label' => env('WORKTRACK_ORG_LABEL', 'Project & workflow tracking'),

    'signup' => [
        // Counts ACCOUNT CREATIONS per IP, not requests. Signup is open to any Google
        // account by design, so this is what keeps the public approval queue usable.
        'per_ip_per_hour' => (int) env('WORKTRACK_SIGNUP_PER_IP_HOUR', 3),
        'per_ip_per_day' => (int) env('WORKTRACK_SIGNUP_PER_IP_DAY', 10),
    ],

    'auth' => [
        // FR-1.11 — "keep me signed in" on the Google sign-in screen.
        //
        // This reverses AUTH-D10, which disabled remember-me because a recaller cookie
        // re-authenticates without a `sessions` row and would therefore survive the
        // revocation sweep behind FR-1.7. That hole is now closed at its source:
        // SessionRevoker cycles `remember_token`, which invalidates every outstanding
        // recaller cookie for the account in the same transaction that deletes its
        // sessions. See SessionRevoker for why that is sufficient.
        'remember_enabled' => (bool) env('WORKTRACK_REMEMBER_ENABLED', true),

        // Laravel's own default is 400 days. That is a sensible figure for a consumer
        // product and a poor one for a tool whose access model assumes an administrator
        // can end someone's access; a shorter ceiling bounds how long a stolen laptop
        // stays useful without making the feature pointless.
        'remember_days' => (int) env('WORKTRACK_REMEMBER_DAYS', 30),

        // Default state of the checkbox. Checked, because every user is a member of one
        // IT team on their own work machine, and the friction this feature exists to
        // remove is not removed by a box people must find and tick each time.
        'remember_default' => (bool) env('WORKTRACK_REMEMBER_DEFAULT', true),
    ],

    'break_glass' => [
        'enabled' => (bool) env('WORKTRACK_BREAK_GLASS_ENABLED', true),

        // Failures per hour across all IPs that trigger an admin alert. This is an
        // ALERT threshold, never a block — see BreakGlassAuthenticator for why a
        // blocking global cap let an attacker hold the emergency door shut.
        'alert_threshold' => (int) env('WORKTRACK_BREAK_GLASS_ALERT', 20),

        // Upper bound on the progressive delay applied to FAILED attempts. Raises the
        // cost of guessing without ever hard-denying a correct credential. Set to 0 in
        // the test environment so the flood regression test stays fast.
        'failure_delay_cap_ms' => (int) env('WORKTRACK_BREAK_GLASS_DELAY_CAP_MS', 1500),

        // Optional IP allowlist. Empty means any IP, which is the right default if
        // access may be needed from home during an outage — a deliberate trade.
        'ip_allowlist' => array_filter(explode(',', (string) env('WORKTRACK_BREAK_GLASS_IPS', ''))),
    ],

    'notifications' => [
        // FR-7.8 — repeated moves of one card inside this window collapse into a
        // single email describing the net movement, rather than three describing each
        // twitch of someone tidying the board.
        'coalesce_minutes' => (int) env('WORKTRACK_COALESCE_MINUTES', 5),

        // ── dispatcher (OutboxDispatcher) ───────────────────────────────────────
        // Recipients drained per run, not rows: the dispatcher batches everything one
        // person is owed into one email, so this is the real bound on messages per run.
        'batch_recipients' => (int) env('WORKTRACK_NOTIFY_BATCH', 50),

        // FR-7.6 — retries with backoff, then a PERMANENT failure that stays visible
        // rather than being silently dropped.
        'max_attempts' => (int) env('WORKTRACK_NOTIFY_MAX_ATTEMPTS', 5),
        'backoff_minutes' => (int) env('WORKTRACK_NOTIFY_BACKOFF_MINUTES', 5),
        'backoff_cap_minutes' => (int) env('WORKTRACK_NOTIFY_BACKOFF_CAP', 720),

        // A row left in 'sending' is invisible to the poller for ever, which is the one
        // failure mode this table exists to prevent. Anything held longer than this is
        // assumed to belong to a process that died mid-send and is returned to the queue.
        // The trade is at-least-once delivery: a crash between send and status update
        // re-sends. A duplicate email is strictly better than a lost one.
        'reclaim_after_minutes' => (int) env('WORKTRACK_NOTIFY_RECLAIM_MINUTES', 15),

        // Gmail SMTP enforces a daily cap (~500–2,000 depending on account type). This
        // guard counts outbox ROWS sent today, which over-counts emails because a digest
        // of eight rows is one message — deliberately conservative, since stopping early
        // leaves rows pending and visible in the FR-10.5 health counts, whereas stopping
        // late means Google refusing mail we have already marked sent.
        'daily_send_cap' => (int) env('WORKTRACK_NOTIFY_DAILY_CAP', 400),
    ],

    'stall' => [
        // FR-10.2 global default; tracker and department overrides win in that order.
        'threshold_days' => (int) env('WORKTRACK_STALL_DAYS', 7),
        // Backlog usually deserves a longer fuse than work in progress. Null = same.
        'intake_threshold_days' => env('WORKTRACK_STALL_INTAKE_DAYS') !== null
            ? (int) env('WORKTRACK_STALL_INTAKE_DAYS')
            : null,
    ],

];
