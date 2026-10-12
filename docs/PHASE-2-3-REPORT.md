# Phase 2 (People) and Phase 3 (Attendance, Shifts & Time Office) — completion report

Status: **done with known gaps** (see "Open items"). Verified by running the suites, not by browser testing.

## Verification (last run)
- Backend: `php vendor/phpunit/phpunit/phpunit --colors=never` → 417 tests, 3179 assertions, OK (before the final lifecycle/frontend reconcile edits; rerun before release).
- Frontend: `npm test` 90 tests pass; `npm run build` succeeds (500 kB chunk warning only).
- Not done: live-backend/browser run, MariaDB/MySQL migration run (migrations validated on SQLite only), load testing beyond query-count assertions.

## Phase 2 — People
- Person / Employment / User separation, effective-dated assignments and reporting lines, ~45 tables (`2026_10_07_000001_create_people_domain.php`).
- Field-level access (classification, masking, edit rules), data scopes, directory privacy, custom fields and groups, personal sections, records (documents, bank, identity, country), notes/tags, timeline, Employee 360, org chart, data quality, action center.
- Operations: import/export, bulk actions with preview, saved views.
- Lifecycle: change requests with approval, invitations, separation/rehire/retention/anonymize, probation actions.
- API docs: `docs/api/people-*.md`.

## Phase 3 — Attendance, Shifts & Time Office
- Three layers: immutable punches → deterministic calculation with traces → locks/finalization. Engine `1.0.0`, explainer, simulator, DST-safe time handling.
- Capture: web/mobile punch, device API (token auth), geofences, privacy controls, imports.
- Scheduling: shifts (fixed/flexible/split/cross-midnight), rotations, work weeks, holiday calendars, versioned rosters, swaps, compliance packs (illustrative, not legal advice).
- Policies (versioned, effective-dated), processing runs (idempotent, queued), exceptions, locks with maker-checker unlock.
- Requests: regularization, overtime, on-duty, adjustments, delegation, bulk.
- Operations: command center, payroll readiness, reports/exports, settings.
- Frontend: employee attendance pages, team view, Time Office console (14 pages).
- API docs: `docs/api/attendance-*.md`, `docs/attendance-engine.md`.

## Open items
1. Migrations untested on MariaDB/MySQL; dev DB needs `php artisan migrate`.
2. Production ops: queue worker, scheduler (`attendance:process-nightly` 01:30, `attendance:expire-requests` 01:00, `attendance:send-reminders` every 15 min, people:* jobs), disks `hrm_imports`, `hrm_exports`, `hrm_private`, `attendance_requests_private`.
3. Security review: generic local-disk temporary serving is disabled because its root contains app-private files; HR documents remain on the dedicated private disk and are streamed through authorized controllers. Focused storage assertion passed as part of RecordsSensitiveTest (17 tests, 249 assertions). Private HR note bodies are absent from audit/domain-event payloads and private notes are system-restricted/off timelines (Notes suite: 5 tests, 68 assertions). Public invitation acceptance was reviewed: 48-character random token, SHA-256 stored hash, expiry and single-use transaction, generic invalid-token response, and per-IP/per-token throttles. Broader security review and production storage-provider review remain.
4. Shared-file integration still to do: custom-field defaults/values in `EmployeeService`/profile, completeness using custom required fields, dotted keys in `PeopleSettings::get`, bank/identity encryption path on change-request apply (verify).
5. Frontend: custom-field single/multi-select choice-option administration and group membership management are implemented in People settings. Import problem rows can now be edited and revalidated before commit. Identity/bank profile forms now follow the server edit mode, use country schemas, keep identity values blank during edits, require audited reveal before loading bank secrets, and support approval requests and retirement/removal. Focused profile suite passed (11 tests); production build passed with the existing large-chunk advisory. Shift-assignment identity is consistent: the API presents the public UUID as `id`, and the editor uses that value for update/delete. Other Time Office pages still need response-shape verification.
6. `max_consecutive_nights` is now evaluated against the effective country/tenant night window and reports the first shift beyond the configured consecutive limit. Shift swaps can exchange different scheduled dates; the colleague's date and the requester's date are revalidated, lock-checked, compliance-checked, and applied independently at approval. Focused roster suite passed (11 tests, 98 assertions) and Attendance frontend suite passed (11 tests); production build passed with the existing large-chunk advisory. Leave and payroll integration points remain contracts only (`LeaveDataProvider` null impl).
7. Statutory/country rules require expert validation before production use.

Continuation (2026-10-11): added a permission-gated choice-option administration view for single-select and multi-select employee custom fields. It supports adding options and activating/deactivating existing values; deactivation preserves stored employee values. Frontend production build passed with the existing large-chunk advisory. No targeted People test was run for this UI slice.

Continuation (2026-10-11): added group membership management to People settings, including scoped employee search, effective membership dates, bulk add/update and removal. Backend data-scope enforcement remains authoritative. Frontend production build passed with the existing large-chunk advisory; no targeted People UI test was run.

Continuation (2026-10-11): added import problem-row editing, using the existing tenant-scoped PATCH API, followed by whole-import revalidation before commit. Focused People admin frontend suite passed (15 tests); production build passed with the existing large-chunk advisory.

Phase 3 continuation (2026-10-11): roster conflict detection now evaluates `max_consecutive_nights` using UTC interval overlap against the effective local night window, including DST-safe conversion and a boundary buffer. A focused roster test passed (1 test, 4 assertions). Shared database migrations were not applied.

Phase 2 continuation (2026-10-11): implemented sensitive identity and bank edit forms using tenant country configuration and existing direct/change-request API contracts. Identity numbers are not prefilled; bank details are only loaded into the editor after an explicit reveal request, which is audited by the backend. Identity removal and bank retirement follow the service lifecycle. Focused EmployeeProfile suite: 11 tests passed. Production frontend build passed; Vite emitted the existing chunk-size advisory. No shared database changes.

Phase 2 continuation (2026-10-11): normal employee creation and rehire now apply valid configured custom-field defaults after the initial assignment is written, so country/employee-type-scoped definitions resolve correctly. The People config suite passed (24 tests, 329 assertions); no database migration was needed.

Phase 2 security continuation (2026-10-11): disabled Laravel's generic local-disk serving, which could generate signed URLs rooted over application-private storage. Dedicated HR document storage remains private and is served only through authorized application streams. Verified that private-note content is not copied to audits/events and that public invitation acceptance uses hashed, expiring, single-use tokens plus IP/token rate limits. RecordsSensitive suite: 17 tests / 249 assertions; Notes suite: 5 tests / 68 assertions. Production storage adapter and broader security review are still pending.

Phase 3 continuation verification (2026-10-11): the full Attendance backend feature folder passed (86 tests, 616 assertions), and Attendance/Time Office frontend suites passed (29 tests). Source and tests confirm the shift-assignment API presents public UUIDs as `id`, which the Time Office editor uses for updates. These checks do not cover MariaDB, browser workflows, load, every Time Office screen, or external leave/payroll providers.

Phase 3 continuation (2026-10-11): extended shift swaps to support the colleague's shift on a different date. Approval revalidates that each published shift is unchanged, verifies locks/conflicts on both dates, updates each person's corresponding date, and recalculates both days. Focused roster suite passed (11 tests / 98 assertions); Attendance frontend suite passed (11 tests); production build passed with the existing large-chunk advisory. No shared DB migration was needed; `counterpart_date` was already present in the schema.

## Phase 4 readiness
Contracts ready: `LeaveDataProvider`, `AttendanceRecalculator`, `LockGuard`, holiday feed `HolidayService::forEmployee`, `UnavailabilityProvider`.
