# Worktrack

A self-hosted work tracker for teams that need to know **how long things have been sitting**.

Trello-shaped on the surface — boards, columns, drag-and-drop cards — but the point isn't the board. Every time a card moves, Worktrack writes a closed interval to a movement log, and every metric in the app is derived from that history rather than from a status field somebody remembered to update. Nobody is ever asked "how long has this been pending?"; the system already knows.

Built for an IT department running two domains at once (infrastructure/support and software development), but nothing in it is IT-specific.

**Stack:** Laravel 13 · PHP 8.3+ · Livewire 4 · Tailwind 4 · MySQL or SQLite · Google OAuth

---

## What it does

**Boards** — A **Tracker** is a board with its own steps and its own member list. **Steps** are the columns. **Projects** are the cards that move through them. **Tasks** are checklist items inside a card.

Each step is typed `intake`, `active`, or `terminal`. That typing is what makes cross-board reporting possible: your "Backlog" and my "Waiting on vendor" are both intake, so they aggregate even though the names differ.

**Timing intelligence, all derived**
- Days in current step, per card, worst-first
- Cycle time (first `active` → `terminal`) and lead time (created → `terminal`), with medians and trend
- Stalled watchlist — anything past its inactivity threshold, ranked by how long it's been idle
- Throughput per week/month, charted as arrivals *against* completions (twelve finished looks great until you see eighteen arrived)
- Active workload per person across every board, so you can see who's actually buried
- Deadline performance: what's overdue, what's due soon, on-time rate — always shown beside the count of work carrying no target date at all, because "zero overdue" and "nobody set a date" are different situations
- Aging distribution in fixed buckets, plus a per-tracker scorecard so a bad month belongs to a team, not the whole department

Every figure on the dashboard links straight to the card behind it.

**Access control, two layers**
- **Global role** — Admin / Manager / Member / Viewer — sets the ceiling on what someone can ever do
- **Tracker membership** — controls what they can see at all

Both must pass. A Manager who isn't a member of a board sees no trace of it: not in the switcher, not in search, not in dashboard totals. Enforced server-side on every request, including Livewire's shared endpoint — hiding a button is presentation, not permission.

**Everything else**
- Google sign-in with an admin approval gate (open signup, hard block until approved), plus an offline break-glass admin login
- File attachments with magic-byte MIME sniffing, an allowlist, a blocked-extension gate, and short-lived signed download URLs
- Email notifications with a coalescing window, a retry/backoff outbox, and a daily send cap
- Comments with @mentions, watchers, tags, health flags, project archival
- Audit log (actor, action, target, timestamp, IP) and a per-board activity feed anyone on the board can read

---

## Requirements

| | |
|---|---|
| PHP | 8.3 or newer, with `fileinfo` |
| Composer | 2.x |
| Node | 20+ (22 recommended) |
| Database | MySQL 8.0+ / MariaDB, or SQLite for a quick spin |
| Optional | S3-compatible object storage (Cloudflare R2) for attachments, SMTP for mail |

---

## Install

```bash
git clone https://github.com/Nixxx27/worktrack.git
cd worktrack

composer install
npm install

cp .env.example .env
php artisan key:generate
```

### Point it at a database

**SQLite** — nothing to set up, good for trying it out:

```bash
touch database/database.sqlite
```
```env
DB_CONNECTION=sqlite
```

**MySQL** — recommended for real use:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=worktrack
DB_USERNAME=root
DB_PASSWORD=
```

Then:

```bash
php artisan migrate
```

### Google sign-in

Sign-in is Google-only, so this is not optional. In [Google Cloud Console](https://console.cloud.google.com/apis/credentials): create an **OAuth 2.0 Client ID** of type *Web application*, and add your callback as an authorized redirect URI.

```env
GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback
```

Signup is open to any Google account by design — dev-team members often use personal Gmail — but a new account lands in `pending` and can see nothing at all until an admin approves it.

### Attachments

Default disk is `r2`. For local development, switch it to the local disk and skip the credentials entirely:

```env
ATTACHMENTS_DISK=local
```

For Cloudflare R2 (or any S3-compatible bucket):

```env
ATTACHMENTS_DISK=r2
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_BUCKET=worktrack
R2_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
R2_REGION=auto
```

Uploads are proxied through PHP so the bytes can be sniffed, which means `post_max_size` and `upload_max_filesize` in `php.ini` must exceed `ATTACHMENTS_MAX_BYTES` (default 48 MB). If they don't, oversized uploads die as a "Page Expired" instead of a readable error.

### Run it

```bash
composer dev
```

That runs the PHP server, queue worker, log tailer, and Vite together. Or separately:

```bash
php artisan serve
npm run dev
```

Using [Laravel Herd](https://herd.laravel.com/)? Skip `artisan serve` — the site is already at `http://worktrack.test`. Set `APP_URL` and `GOOGLE_REDIRECT_URI` to match.

### Create the first admin

There's no seeded admin account — a seeded credential is a known credential. Sign in with Google once so your user record exists, then promote it from the CLI:

```bash
php artisan worktrack:user first-admin you@example.com
```

From then on, user administration lives in the UI at `/admin/users`, though the CLI stays available so a broken UI never leaves the system unadministrable:

```bash
php artisan worktrack:user list
php artisan worktrack:user approve someone@example.com --role=member
php artisan worktrack:user suspend someone@example.com
php artisan worktrack:user role someone@example.com --role=manager
```

### Break-glass account (optional but recommended)

A local password login for when Google is down or an OAuth config change locks everyone out. It generates its own password, prints it once, and forces rotation after every use.

```bash
php artisan worktrack:break-glass create --email=admin@example.com
php artisan worktrack:break-glass rotate
```

Reachable at `/break-glass`. Disable it entirely with `WORKTRACK_BREAK_GLASS_ENABLED=false`, or restrict it:

```env
WORKTRACK_BREAK_GLASS_IPS=203.0.113.5,203.0.113.6
```

### Demo data (optional)

Fills a board with plausible projects so the dashboard shows something before real work accumulates. Requires an active admin, and refuses to run if any tracker already exists.

```bash
php artisan db:seed --class=DemoDataSeeder
```

---

## Production notes

Three things must be running or features silently do nothing:

**Scheduler** — stall detection and the notification drain live here. Without it, nothing is ever flagged as stalled.

```cron
* * * * * cd /path/to/worktrack && php artisan schedule:run >> /dev/null 2>&1
```

**Queue worker** — supervised, e.g. via Supervisor or `systemd`:

```bash
php artisan queue:work --queue=default --tries=3
```

**Mail** — notifications need real SMTP. `MAIL_MAILER=log` writes them to the log file instead, which is fine locally.

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=worktrack@example.com
```

Gmail enforces a daily cap, which is why `WORKTRACK_NOTIFY_DAILY_CAP` exists (default 400).

Then the usual:

```bash
npm run build
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Set `APP_ENV=production` and `APP_DEBUG=false`.

---

## Configuration

Everything below is optional — the defaults are sensible. Full annotated list in [`config/worktrack.php`](config/worktrack.php) and [`config/attachments.php`](config/attachments.php).

| Variable | Default | What it controls |
|---|---|---|
| `WORKTRACK_TIMEZONE` | `Asia/Manila` | Timezone for display and for scheduled runs |
| `WORKTRACK_STALL_DAYS` | `7` | Days of inactivity before a project is flagged stalled |
| `WORKTRACK_STALL_INTAKE_DAYS` | same as above | Separate, usually longer, fuse for intake steps |
| `WORKTRACK_COALESCE_MINUTES` | `5` | Window in which repeated moves of one card collapse into one email |
| `WORKTRACK_NOTIFY_DAILY_CAP` | `400` | Ceiling on outbox rows sent per day |
| `WORKTRACK_NOTIFY_MAX_ATTEMPTS` | `5` | Retries before a notification is marked permanently failed |
| `WORKTRACK_REMEMBER_ENABLED` | `true` | "Keep me signed in" on the login screen |
| `WORKTRACK_REMEMBER_DAYS` | `30` | How long that lasts |
| `WORKTRACK_SIGNUP_PER_IP_HOUR` | `3` | New accounts one IP may create per hour |
| `WORKTRACK_BREAK_GLASS_ENABLED` | `true` | Emergency password login at `/break-glass` |
| `WORKTRACK_BREAK_GLASS_IPS` | *(any)* | Comma-separated IP allowlist for it |
| `ATTACHMENTS_DISK` | `r2` | Filesystem disk for uploads |
| `ATTACHMENTS_MAX_BYTES` | `50331648` | Per-file size ceiling (48 MB) |
| `ATTACHMENTS_TEMP_URL_TTL` | `1` | Signed download URL lifetime, in minutes |

---

## Tests

```bash
composer test           # or: php artisan test
php artisan test --filter=Board
vendor/bin/pint         # code style
```

Pest, with the suite covering authorization boundaries, tracker isolation, the movement-history invariants the metrics depend on, and the auth lifecycle.

---

## A note on the design

Two things in here are deliberate and might otherwise look like bugs:

**Step type is editable, but history never is.** Changing a step from `intake` to `active` re-stamps the live state of every project currently sitting there, and leaves every past movement row exactly as recorded. A figure that moves because somebody edited a dropdown is not a measurement.

**Members can move and edit any card on their boards, not just their own.** The person who notices a ticket is finished is rarely its nominal owner, and "message the owner and ask them to drag it" is the out-of-band coordination this thing exists to eliminate. The compensating control is the record, not the restriction: every move, edit, and reassignment writes an attributed row that anyone on the board can read at `/activity`.

---

## License

MIT.
