# hic-bootcamp-scheduler

Live event scheduling dashboard for the HIC Innovation Bootcamp, with agenda management, countdown timers, SMS notifications, and AI timing advice. Built with PHP and SQLite.

# Publication security notes

This is a standalone PHP 8.2+/SQLite bootcamp scheduling app. Public pages live in `index.php` and `timer.php`; organizer actions in `control.php`; scheduling and auditing in `lib/app.php`; SMS/OpenAI clients in `lib/integrations.php`; reminder processing in `cron.php`; and signed inbound commands in `webhook.php`. There is no Composer or npm build requirement.

**Organizer access is currently open.** `control.php` does not enforce a login, and `admin_password_hash` is not checked. Before deploying publicly, protect organizer access at the web server or implement authentication. Access to the dashboard permits schedule edits and use of configured paid integrations. Older login descriptions below are historical and do not describe the current code.

For GitHub, upload a clean source checkout or the prepared source archive, never the deployed folder wholesale. Keep `config.php`, environment files, runtime databases, logs, backups, server credentials, and generated screenshots private. `.gitignore` excludes these files; it cannot remove files already committed. The deployment mounted here has no readable Git history, so prior commits have not been audited. If an earlier repository contains credentials, rotate them and remove them from its history before publishing.

Integration keys are read at runtime from environment variables and existing server configuration; the referenced server paths are not credential values. No integration credential source files are included in the publication archive. Configure independent credentials on a new server. Create a writable `var/` directory and deny HTTP access to it, `config*.php`, `lib/`, and `tests/`.

Validate with `php tests/run.php`. Browser tests include historical production URLs and some mutate event state: review their targets and use a disposable database before running them. `tests/webhook.py` requires explicit local test credentials and a disposable database.

---

# Dedicated HIC subdomain deployment

- Public website: https://hic.emmanuelhaggai.com/
- Public large timer: https://hic.emmanuelhaggai.com/timer
- Organizer dashboard: https://hic.emmanuelhaggai.com/control
- Project/document root: `/var/www/hic.emmanuelhaggai.com`
- Apache vhosts: `/etc/apache2/sites-available/hic.emmanuelhaggai.com.conf` and `hic.emmanuelhaggai.com-le-ssl.conf`.
- TLS: Let's Encrypt, `/etc/letsencrypt/live/hic.emmanuelhaggai.com/`, expires February 14, 2027. Existing Certbot timer handles renewal; a domain-specific deploy hook reloads Apache after renewal.
- The public homepage is read-only: all three day schedules, announcements and a fixed countdown to November 17, 2026, 08:00 EAT (05:00 UTC). The counter stops at zero after departure rather than rolling into another day. Server time drives the display. Organizers continue using the separate control page without a password, per the prior explicit preference.
- Runtime data physically resides in the new `var/` directory. The old runtime path is a compatibility symlink to the same data, not a second database. All original and revised schedule times, logs, topics, notices, contacts in configuration and messaging settings were preserved.
- The www-data HIC cron now runs `/var/www/hic.emmanuelhaggai.com/cron.php` every minute. Other cron jobs were preserved.
- Old `/hic-bootcamp/` links redirect to the subdomain. Explicit old `index.php` links map to the organizer page; timer and other paths keep their filenames.
- SQLite backup before cutover: `/tmp/hic-migration-20261116.sqlite` (root-readable). The previous source files were retained at the old path behind redirects for rollback.
- Africa's Talking and OpenAI integration settings continue to use the existing DairyOS/Apache server-side credential sources.
- Tests: `php tests/run.php`; `tests/public-site-browser.cjs` uses Playwright for read-only production checks. Runtime/config/lib/tests directories are protected by Apache rules.

The sections below retain earlier implementation notes and history; old URLs and filesystem paths in that history have been superseded by this deployment.

# Access update

Organizer dashboard controls and AI advice are open without a password, as explicitly requested. CSRF checks remain in place. Anyone with the URL can change the agenda. Signed provider webhooks still validate signatures and organizer phone numbers.

# HIC Innovation Bootcamp

Standalone PHP 8.2+ app at `/hic-bootcamp/`. Public agenda, password-protected organizer controls, live clock, proposed schedule simulator, actual timing report, audit history, signed phone commands, and idempotent reminder queue. Includes all 21 supplied agenda entries, Africa/Nairobi timezone. Day 2 is provisionally November 17, 2026, pending organizer confirmation. The original PDF was not available in this workspace; data follows the supplied transcription.

## Configured on this server

- Live URL: https://emmanuelhaggai.com/hic-bootcamp/
- Direct Africa's Talking SMS is enabled; recipients and organizers are stored only in the excluded local configuration.
- SMS credentials are read from the existing Apache environment/DairyOS configuration, without copying API keys to this repository. Configuration sources are read as data; DairyOS application code is never bootstrapped.
- OpenAI Responses API advice uses DairyOS's key/model settings. Organizer-only dashboard questions and authorized `ASK question` commands have no write capability. No farm records are sent to OpenAI by this app; only bootcamp agenda and timing context is supplied, with `store: false`.
- A www-data cron runs this app every minute; the site's other cron jobs were preserved.
- No organizer password is required. The previous generated password has been removed.
- All live sessions remain unstarted; headless tests used a separate database.
- A requested test SMS received provider Success with a message ID; handset delivery is not confirmed. See tests/QA.md.

## Setup on another server

1. PHP needs PDO SQLite and cURL. This first version uses SQLite rather than MySQL so no shared-site database credentials are required. Its SQL is SQLite-specific; MySQL migration is future work.
2. Make `var/` writable by the PHP worker and cron user (normally www-data). Keep the rest of the app read-only to that worker. SQLite initializes on first request. Back up the database with SQLite's backup API/CLI, rather than copying a live WAL database alone.
3. Copy `config.example.php` to `config.php`. Generate a password hash using `php -r 'echo password_hash(readline("Password: "), PASSWORD_DEFAULT), PHP_EOL;'` and set `admin_password_hash`. Use a unique strong password. Configure organizer E.164 numbers separately from opted-in notification recipients. Config files, lib, tests, and runtime storage are denied by Apache rules; equivalents are required for other web servers. Never expose the database or credentials. The root site already enforces HTTPS.
4. Dashboard: `https://emmanuelhaggai.com/hic-bootcamp/`. Public readers can simulate; only signed-in organizers can mutate the agenda. Sessions never automatically complete or advance. Pausing suppresses warnings; resuming preserves the scheduled end. Extend explicitly if paused time should move the schedule.
5. Add cron as the same account that owns the writable database:

   `* * * * * /usr/bin/php /var/www/emmanuelhaggai.com/hic-bootcamp/cron.php >> /var/www/emmanuelhaggai.com/hic-bootcamp/var/cron.log 2>&1`

   Cron must be installed on a new server (it is installed here). The scheduler emits upcoming/start, active ten-minute/end, capped five-minute overtime (12 warnings), and daily summary alerts. It uses a 90-second due window, deliberately skipping stale messages after downtime. It does not infer actual starts from the printed agenda. The optional five-minute facilitator alert and subscriptions are not implemented. Notifications currently go to the whole configured opt-in audience.

## Messaging relay contract

This app directly supports Africa's Talking SMS when `sms_enabled` is true. Meta/WhatsApp requires a relay. Supply your existing WhatsApp/SMS gateway adapter at HTTPS `relay_url`, with `relay_token`. The relay is responsible for provider credentials, opt-in enforcement, WhatsApp template/window rules, sender configuration, and SMS fallback. If neither direct SMS nor a relay is enabled, nothing is sent externally.

Outbound: POST JSON `{"to":"+254...","message":"...","reference":"123"}` with Bearer authorization and stable `Idempotency-Key: hic-123`. Return 2xx JSON `{"id":"provider-message-id"}` on acceptance. The relay **must** persistently deduplicate idempotency keys, including ambiguous network retries. Acceptance is distinct from delivery. The direct SMS adapter accepts Africa's Talking's Success recipient result with a message ID; network ambiguity becomes `unknown`, requiring manual reconciliation rather than an unsafe automatic retry. Direct SMS does not use the relay retry loop. Retryable network, 429 and 5xx failures use exponential backoff, maximum five attempts. A process crash in `sending` requires operator reconciliation against the relay before resetting to pending. Nonretryable errors stay failed. Current notification state is stored in `notifications`; a delivery-management UI is future work.

Inbound: relay forwards POST JSON to `/hic-bootcamp/webhook.php`. Send headers `X-HIC-Timestamp` (Unix seconds) and `X-HIC-Signature` (hex HMAC SHA-256 of `timestamp + "." + raw JSON body`, keyed by `webhook_secret`). Requests expire after five minutes. This custom relay signature is **not** a Meta webhook signature; the relay must verify native provider signatures first and authenticate the true sender. Keep the secret at least 32 random bytes.

Commands: `{"id":"stable-provider-inbound-id","from":"+254...","text":"STATUS"}`. IDs are durably claimed before execution so provider retries cannot repeat mutations. A crash during processing is left for manual reconciliation. The response is `{"reply":"..."}`; the relay delivers that reply to the requesting sender. Participants may request STATUS, AGENDA, REPORT; other commands, including `ASK question` for AI advice, require the configured organizer allowlist. START/NEXT starts the earliest pending session if none is active; DONE never silently moves later sessions. EXTEND accepts signed minutes and uses the protected policy on phones. Dashboard shift adjustments require explicit approval and may move lunch/closing. SKIP applies to a selected pending dashboard session. Phone SKIP requires no active session and is not currently supported without a selected target.

Delivery callbacks use the same signature: `{"type":"delivery","reference":"123","status":"delivered"}` or `failed`, optionally `error`. They update accepted/sending entries only.

## Scope and operations

- Original and revised times stay separate. Transactions serialize changes; dashboard revision checks reject stale submissions. Breaks and daily closing are protected by default; conflicts require an explicit alternative. The engine never silently shortens speakers.
- The simulator is a local preview of persisted current schedule; the server recalculates and validates every applied change. Negative EXTEND shortens the selected session but does not pull later sessions earlier.
- The audit table stores complete before/after snapshots, actor and timestamp. Reports show actual starts/ends and end variance; automatic PDF reporting, contact management, facilitator editing/reordering, postponement/MOVE, and UNDO are future work. Correct agenda data in `lib/agenda.php` **before first initialization**; editing this seed after initialization does not modify the database.
- Organizer login is session-based with CSRF checks and a 10-attempt/15-minute IP limit. Put organizer access behind an additional server access policy if needed. Secrets are never displayed by the dashboard. Read-only endpoints disclose event information intentionally.
- Review failed/sending notifications, cron logs and backups during operation. Deployment-specific setup and the requested integration checks are recorded above.

## Validation

`php tests/run.php` uses a disposable SQLite database. `php -l` checks syntax. No framework or Composer install is needed.

## Delivery receipts and inbound phone commands

The direct SMS sender is active. Native Africa's Talking incoming SMS and receipt callbacks are not registered automatically, since a shared DairyOS account must retain its existing webhook routing. A gateway adapter must forward verified events using this app's signed contract. Signed requests require `X-HIC-Timestamp` (Unix seconds) and `X-HIC-Signature` (hex HMAC-SHA256 of `timestamp + "." + raw JSON`, using `webhook_secret`). Delivery JSON is `{"type":"delivery","reference":"local-notification-id","id":"provider-message-id","status":"delivered"}` (or `failed`). An adapter can look up the local notification by provider ID in this app's database when the provider callback omits the local reference.

Until callback routing is configured, outbound SMS works but handset delivery stays `accepted`, and phone commands do not arrive directly from the provider. The signed endpoint and command engine are ready and tested. WhatsApp template approvals, sender provisioning and recipient subscriptions remain provider setup work.

## Skip and switch during the event

Use **Switch here · skip current** on a pending session to atomically mark the current active/paused session skipped and start the chosen session now. Scheduled times stay intact; actual start/stop timestamps and both state transitions are audited. The previous session's pending reminders are cancelled. Use **Skip current** to stop tracking without starting another session. Signed organizer commands also support `SWITCH session-id` and `SKIP` for the current session. Closed sessions cannot be selected by SWITCH.

`SWAP session-id` exchanges the active session's slot with another non-protected session on the same day. A moved skipped session is restored to pending, its old actual tracking remains in audit history, and original timetable fields are retained. Pending reminders for both slots are cancelled and regenerated for their new revisions.

## Public fullscreen timer

Open https://emmanuelhaggai.com/hic-bootcamp/timer.php for a read-only presentation screen. Click Fullscreen or press F; Esc exits. It polls public, minimal session state every five seconds, follows organizer changes, shows next session, pauses at the recorded pause instant, and turns amber for the last ten minutes and red in overtime. It never completes or starts sessions. The display uses the server clock, flags sync failures, and requests a screen wake lock in supported browsers when entering fullscreen. No SMS recipients, credentials or audit data are returned by its state endpoint.

## Public fullscreen timer

Open https://emmanuelhaggai.com/hic-bootcamp/timer.php or **Public timer** in the dashboard navigation. This display has no schedule controls, login, contact details or messaging activity. It polls a read-only, no-cache state endpoint every five seconds, uses server time for its countdown, freezes the display on PAUSE, and shows overtime until an organizer confirms completion. It never starts or completes sessions itself. Use Fullscreen or F to enter/exit fullscreen, or Esc to exit. Supported browsers request a screen wake lock; device/browser settings can still affect sleep. Connection failures are shown while the last known agenda remains visible.

`tests/timer-browser.cjs` verifies headless fullscreen, warning, pause, overtime, no-active-session, desktop viewport fit and mobile layout. Simulated states are intercepted only inside the test browser, without changing the production agenda.

## Notices and reported late starts

The **Send a bootcamp notice** form queues an organizer message to configured opted-in recipients. Notices have a 1000-character limit, CSRF protection, and an audit entry. Cron delivers them through the selected channel. The scheduling engine supports `LATE minutes` for the active session to correct the recorded actual start relative to its revised slot (0–240 minutes, never a future actual start). Recording lateness preserves scheduled end, breaks and closing times; timing changes still use explicit approved adjustments.

### Public timer notifications

The latest three dashboard notices appear in the public timer's notifications banner, including in fullscreen. Notices refresh with the five-second state feed and expire after one hour. Public data includes notice text and posted time, with no recipient phone numbers, provider IDs, or actor details. The tea notice was published without sending a duplicate SMS. Long notices are visually limited to three lines for the latest item and two lines for older items so the presentation remains usable.

### Timed tea breaks

`BREAK 15` pauses the active session and starts a durable 15-minute tea countdown on the public timer. Cron queues one return-to-room message at the end. The public display shows break overtime until the organizer resumes, completes, skips or switches the paused session. Resume closes the break; no session automatically restarts and agenda end times remain unchanged. Actual break start/end times are retained in the breaks table.

## Edit session times and manage notifications

Each agenda row has **Edit times** with start/end inputs in EAT. Save checks the revision, validates a positive same-day duration, rejects overlaps with other open sessions, preserves original/actual timings, cancels obsolete pending reminders for that session and queues a revised-timing SMS. Other session times do not move automatically.

Under **Send a bootcamp notice**, use **Add notification** to publish on the public timer. SMS is optional via **Also send to configured SMS recipients**. **Manage notifications** lists the latest 20 notices with **Save notification** and **Remove notification**. Edits refresh the one-hour display window; removals disappear from the timer within five seconds. Original notice text stays in the audit history. Edits/removals cancel any still-pending notice SMS; messages already accepted or delivered cannot be recalled. Editing does not send another SMS automatically.

`tests/edit-controls-browser.cjs` verifies timing edits, overlap rejection, and notification add/edit/remove propagation in a separate SQLite instance; it sends no external messages.

### Auto pushing (prepared; activation requires reviewed policy)

The organizer desk provides an explicit policy form and preview. Auto pushing is currently off. Only `scheduled` sessions on the active session's day can move; durations and original fields are preserved, and completed/skipped sessions are untouched. The protected policy rejects any plan that moves a protected break or exceeds the original closing time. The shift policy permits later breaks/closing only when explicitly approved. Including earlier pending activities is a separate policy choice. Cron reapplies an enabled policy every minute while a session is active, including overtime; this never starts or completes another session automatically. Tea breaks suspend the minute-by-minute push. All applied changes are audited and obsolete pending reminders are cancelled. Disabling retains the last revised agenda.

The activation proposed on November 16 includes seven pending activities, with lunch at 16:28 and closing at 19:28 EAT. Automatic approval review rejected enabling that broad policy without explicit approval for those effects. The preview and controls were implemented and tested without activating it.

### Direct editing and going live

Each session's **Edit times** form now keeps its duration when the start changes (uncheck **Keep duration** to retain the end). Enter an optional live topic, choose the overlap policy, then use **Save times** or **Save and go live**. A live edit corrects both the revised start and the displayed actual start. Going live uses the supplied start/end, closes any break, and completes the previous active session. The explicit shift policy requires approval for affected pending sessions and any break/closing-time changes. Earlier sessions that already ended before the selected start, skipped items, and completed items are not shifted. A regular Start/Switch starts now and preserves the full allocated duration instead of retaining an expired end time.

On November 16, Design Thinking is the live topic under Digital Innovation Approaches, 15:00–16:00 EAT. The user approved moving Foundations of Data for Social Good to 16:00–17:00. This was a one-time approved change; the broader automatic policy remains separately controlled.

`tests/design-thinking-browser.cjs` covers retained duration, supplied-time go-live, approved overlap shifting, and actual-start propagation using a disposable database.

## Synchronized departure displays

The default large-screen timer at `/timer.php` now displays the same field-departure countdown as the public homepage. Both obtain the target from `fieldDepartureAt()` and use the shared `assets/countdown.js` calculation with server time. The target is November 17, 2026, 08:00 EAT; counters stop at zero after the deadline. No agenda status or session timings are changed by this display mode. Existing session timing is available at `/timer.php?mode=session`; the default `timer.php?state=1` feed remains the organizer's live session feed. Departure-mode polling explicitly requests `display=departure`.

`tests/countdown-sync-browser.cjs` verified the same target and matching counters within one second, fullscreen, 1920px/390px layout, post-departure zero behavior, preserved session mode, and no browser errors.

## Automatic frontend updates without page reloads

The public homepage and large timer now check `display-version.php` every five seconds while visible. Revisions use content hashes of each PHP template, stylesheet, view script and shared countdown script. On a change, the updater fetches the new markup and stylesheet, replaces the display contents in place and remounts the view script. The browser document and fullscreen root remain intact; the selected agenda day, scroll position and running countdown are preserved. Old polling intervals and event listeners are disposed before replacement. Failed updates restore the prior working view and retry on the next check. Normal schedule and notice updates continue through the existing data feed.

Browsers that were already open before this updater was installed need one initial refresh to load it. Subsequent supported template/CSS/view-script changes need no manual refresh. Changes to the updater infrastructure itself may still require a refresh.

`tests/live-updates-browser.cjs` mutates only a disposable `/tmp/hic-hot-test` copy and verifies template/style/script updates, unchanged document identity, preserved fullscreen, one polling runtime, public day selection, and failure rollback/retry. Production countdown synchronization and PHP scheduling regressions also passed after this change.


## Clean URLs

Apache `.htaccess` whitelists these public routes:

| URL | Purpose |
| --- | --- |
| `/` | Public bootcamp homepage |
| `/agenda` | Public homepage with Day 1–3 agenda tabs |
| `/timer` | Fullscreen departure countdown |
| `/timer/session` | Original live session/break timer |
| `/control` | Organizer dashboard |
| `/api/agenda` | Public agenda and departure JSON |
| `/api/timer` | Session JSON; add `?display=departure` for departure mode |
| `/api/display-version` | Automatic frontend revision checks |
| `/webhook` | Signed incoming commands/delivery callbacks |

Old page `.php` URLs and unnecessary route trailing slashes use 308 redirects. Query strings and POST methods are preserved. Legacy webhook POSTs remain directly supported to avoid breaking messaging gateways that do not follow redirects. Only whitelisted endpoints are rewritten; config, lib, tests, cron and SQLite protections remain. Assets and background endpoints use root-relative clean paths, including on `/timer/session`. The original subdirectory redirects directly to the clean timer/control paths.

`tests/clean-urls-browser.cjs` checks all views, polling, fullscreen, query-preserving redirects, webhook compatibility and denied private files without modifying live data.

## Public programme design review

The public page was revised on November 16 after reviewing NN/g research on AI prototyping and GOV.UK content guidance. It now uses the actual event name, a compact field-departure notice, clearer day navigation and an agenda that exposes responsible persons and durations without horizontal scrolling on mobile. Promotional slogans, decorative statistics and the large closing marketing section were removed. Research links and verification details are in `tests/design-review.md`; production desktop/mobile images are under `tests/artifacts/programme-redesign-*`. `tests/design-review-browser.cjs` verifies the live programme without mutating schedule data.
