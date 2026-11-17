# Verification inventory

Headless Chromium tests use a separate SQLite database (`/tmp/hic-browser-test.sqlite`) and localhost PHP instance. No browser test action changes the live agenda or delivers queued test notifications.

- Public agenda: Day 1/2/3 show 9/5/7 entries; provisional Day 2 date is visible.
- Simulator: protected lunch rejects overlap; approved shift shows revised lunch and session times.
- Login: wrong password rejected; correct password enables controls; logout hides them.
- Session controls: START updates live timer; second active session rejected; PAUSE/RESUME updates state; DONE records actual end; SKIP records skipped state.
- Approved adjustment persists revised timing; original timing remains in records.
- Report and audit appear after organizer changes.
- AI advisor: live OpenAI response identifies Day 3's first session from the agenda.
- Desktop 1440px and mobile 390px screenshots reviewed; mobile has no page-level horizontal overflow. The agenda table intentionally scrolls horizontally on narrow screens.
- Browser JavaScript errors: zero.
- Signed webhook: valid read-only status, unauthenticated denial, organizer permissions, duplicate inbound command ID, stale timestamp, delivered status callback.
- PHP regression checks: 21 seed entries, protected breaks and day-end conflicts, stale revision rejection, one active session, actual completion, audit records, notification deduplication.
- Apache production checks: dashboard HTTP 200; configuration and SQLite downloads HTTP 403.

The persistent `js_repl` tool required by the interactive Playwright skill was unavailable. Tests used a standalone Playwright driver with existing headless Chromium instead.

## Live integration checks (November 16, 2026)

One requested SMS sent to a configured private test recipient through DairyOS's Africa's Talking account: HTTP 201, recipient status Success, provider ID `provider-message-id-redacted`. This verifies acceptance, not handset delivery. No second SMS was sent when normalizing the provider response's success format.

OpenAI Responses API live checks passed in CLI and through the dashboard; model came from DairyOS configuration. AI has no schedule mutation tools.

To repeat browser tests, install Playwright in a temporary tool directory, start the localhost PHP server with HIC_DB pointing to a fresh disposable SQLite path, and run `browser.cjs` with HIC_PLAYWRIGHT_MODULE pointing at that installation. Set HIC_TEST_PASSWORD_FILE to a root-readable file containing the organizer password. Do not run against the production database.
