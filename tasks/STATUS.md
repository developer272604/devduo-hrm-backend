# Project status (single source of truth for handoff)

Update this file at the END of every task/phase. Keep it short and factual. Never write "passed" unless you ran it.

## Phase table
| Phase | Name | State | Report |
|---|---|---|---|
| 1 | Foundation | done | docs/PHASE-1-REPORT.md |
| 2 | People | done (known gaps) | docs/PHASE-2-3-REPORT.md |
| 3 | Attendance/Shifts/Time Office | done (known gaps) | docs/PHASE-2-3-REPORT.md |
| 4 | Leave | done (known gaps) | docs/PHASE-4-REPORT.md |
| 5 | Payroll | Partial: payroll core, configurable imports, reviewed benefit enrollment/termination, fixed benefit contribution calculation with effective-date proration, and adjustment reversals; Payroll suite 117 tests / 1036 assertions green. Formula/provider support, retro matrix, statutory validation and real bank formats remain | docs/PHASE-5-REPORT.md
| 6 | Recruitment | Partial: core plus scoped rehire, interview rescheduling, local expiry/reminders, candidate CSV import (backend+UI), private versioned resumes and offer-letter snapshots (backend). Careers/candidate portal and live providers remain | docs/PHASE-6-REPORT.md | docs/PHASE-6-REPORT.md |
| 7 | Lifecycle | Core backend/API/frontend present; partial against full spec; API/UI shapes aligned; targeted backend/frontend tests green | docs/PHASE-7-REPORT.md |
| 8 | Performance | Partial: backend domain/tests and lean goals, cycles, reviews, review responses and dashboard UI; broader admin/workflows deferred | docs/PHASE-8-REPORT.md |
| 9 | Learning | Partial: tenant-scoped catalog, self-enrollment, paths and self-skills API/UI; assessments, compliance and manager workflows deferred | docs/PHASE-9-REPORT.md |
| 10 | Employee Experience/Helpdesk | Partial: tenant-scoped HR tickets with private attachments, category default-owner routing, escalation and filtered queues; searchable/versioned knowledge; targeted announcements with private audience-gated attachments, onboarding and event campaigns; anonymous/confidential/identified surveys with consent, retention and thresholded aggregate exports; recognition; private milestone notices. Email, conditional routing, country privacy controls and broader analytics remain deferred | docs/PHASE-10-REPORT.md |
| 11 | Assets, Expenses and Travel | Partial: inventory/custody, claims/private receipts/independent approvals, external payment confirmation, travel/versioned itineraries, live exit clearance and scoped Employee 360 summaries | docs/PHASE-11-REPORT.md |
| 12 | Workflow and Automation | Partial: versioned workflows, scoped approvals, sixteen employee-linked event triggers with status filtering, schedule queue, simulation, recovery, delegation and one-hop deadline escalation; covered by current full backend/frontend regression runs | docs/PHASE-12-REPORT.md |
| 13 | HR Analytics | Partial: thirteen metrics, historical workforce facts, source scope, cohort suppression, quality checks, saved reports, CSV exports and scenarios; covered by current full backend/frontend regression runs | docs/PHASE-13-REPORT.md |
| 14 | Integrations and enterprise platform | Partial: tenant-scoped provider/connection metadata, explicit not-live diagnostics, and admin connection/history UI; adapters and sync runtime pending | docs/PHASE-14-REPORT.md |
| 15 | Advanced security and compliance | Partial: tenant-scoped access-review snapshots, audited outcomes, and reviewer UI; wider enterprise controls pending | docs/PHASE-15-REPORT.md |
| 16 | HRMS intelligence | Partial: read-only permission-filtered findings endpoint and findings UI over existing module providers | docs/PHASE-16-REPORT.md |

## Last verified results (re-run before trusting)
- Full backend suite before the latest fixed-benefit-feed change: **760 tests / 6795 assertions passed** (2026-10-11). Payroll feature/unit suite after that change: **117 tests / 1036 assertions passed**.
- Full frontend suite: **204 tests across 37 files passed** (2026-10-11), including the automation draft flow; its test fixture now supplies the required event type.
- Production frontend build passed (2026-10-11); Vite still reports a generated JavaScript chunk above the 500 kB advisory threshold.
- These repository-wide checks do not close deferred product, migration rollout, provider integration, or statutory certification gaps recorded in the phase reports.
- Phases 14â€“16 lean foundations: new PHP files passed `php -l`; Laravel registered the integrations, access-review and intelligence routes. No regression tests run. Enterprise migrations remain unapplied to shared DB.
- Latest Phase 12/13 run: Workflow + Analytics + Operations + Foundation, 46 tests / 433 assertions passed. Frontend automation + operations: 9 tests passed. New migrations ran only in isolated SQLite tests; shared development MariaDB remains unchanged.
- Production build passed after Phase 12/13 navigation, designer, recovery and analytics UI changes; the existing large-chunk advisory remains.
- Backend full suite last green: 535 tests / 4268 assertions (end of Phase 4). Phase 5 foundation run: 576 tests OK before later Phase 5 work; payroll-only 57 tests OK.
- Frontend last green: 127 tests, build OK (end of Phase 4).
- Migrations ran cleanly on dev MariaDB (127.0.0.1:3307) on 2026-10-10, including some Phase 5 workstream migrations (2026_10_15_A/B/C...). Later ones need `php artisan migrate`.
- Lifecycle backend feature folder: 16 tests / 220 assertions passed.
- Lifecycle frontend test: 11 tests passed. Vite required elevated access after esbuild hit a sandbox access denial; the targeted run then completed successfully.
- Payroll backend tests: 110 tests / 906 assertions passed; Payroll frontend tests: 22 passed.
- Phase 6 continuation (2026-10-11): Recruitment + Lifecycle backend 34 tests / 617 assertions passed; Recruitment frontend 13 passed; production build passed with existing chunk warning. Rehire, rescheduling, tenant filters and housekeeping added. Reminder migration ran only in isolated tests; shared MariaDB unchanged. Provider/legal gates: docs/PHASE-6-REPORT.md. Next: Phase 6 candidate import preview/commit.
- Frontend production build succeeded after final Performance and Learning UI changes; Vite reported a generated JavaScript chunk above the 500 kB advisory threshold.
- Phase 10: backend Experience suite 35 tests / 390 assertions passed; focused frontend suite 16 passed; 54 Experience routes; PHP syntax checks passed; production build succeeded with the existing large-chunk advisory. Phase 10 migrations are pending deployment.

## Mode

Continuation verification (2026-10-11): workflow/analytics PHP syntax passed; workflow route registration passed; frontend production build passed with the existing large-chunk advisory. These checks do not verify runtime behavior of the newest delegation/escalation and analytics metric code. No tests were run in this continuation. Shared MariaDB migrations were not applied.

Latest continuation adds status-specific employment triggers and the existing ProbationDue/DocumentExpiring source events, for sixteen employee-linked trigger types. PHP syntax and route registration passed before the final catalog-only additions; the frontend build passed before the latest catalog-only additions. No regression suite was run for the newest workflow changes.

Latest Phase 11 verification: backend Operations + Lifecycle + Experience permission migration regression passed, 28 tests / 346 assertions. Production frontend build passed with the existing large-chunk advisory. Phase 11 migrations remain unapplied to shared development MariaDB.

Latest Phase 14â€“16 continuation: added frontend pages for integration metadata/diagnostics/history/revocation, access-review campaign snapshots/decisions, and permission-filtered findings. Frontend production build passed with the existing large-chunk advisory. No tests were run; shared database migrations remain unapplied. Backend permissions remain authoritative.

Capability audit recorded in `docs/HRMS-CAPABILITY-AUDIT.md`. Phase 2 continuation added custom-field choice and scoped group membership UIs plus import row correction/revalidation. Sensitive identity/bank profile editing follows direct/request access, country schemas and audited reveal rules; EmployeeProfile suite: 11 passed; production build passed with the existing chunk advisory. Custom-field defaults now apply during normal hire/rehire; People config suite: 24 tests / 329 assertions. Generic local-disk file serving is disabled; sensitive records suite: 17 / 249 and notes privacy suite: 5 / 68 passed. Dotted-key behavior is covered; broader security and production storage review remain.

Phase 3 continuation: roster compliance now evaluates the configured maximum consecutive night shifts using effective local night windows; focused backend test passed (1 test, 4 assertions). Shared MariaDB remains unchanged.

Phase 3 contract check (2026-10-11): shift-assignment rows expose their public UUID in `id`; Time Office uses that same value for edit/delete requests. Other Time Office response-shape checks remain open.

Phase 3 test sweep (2026-10-11): Attendance backend feature suite passed (86 tests / 616 assertions); Attendance and Time Office frontend suites passed (29 tests). MariaDB/browser/load and provider integration checks remain open.

Phase 3 continuation (2026-10-11): added cross-date shift swaps using the existing `counterpart_date` schema field. Approval checks both current assignments, both lock dates and both compliance overlays, updates each employee on their own date and recalculates both days. Roster suite passed (11 tests / 98 assertions); Attendance frontend suite passed (11 tests); production build passed with the existing chunk advisory.

Phase 4 continuation (2026-10-11): replaced manual employee UUID entry with searchable employee pickers in comp-off grants, transfer/rehire previews, and policy resolution/assignment checks. HR leave frontend suite passed (18 tests); production build passed with the existing chunk advisory. Leave bulk-action UI and settings endpoint remain open.

Phase 4 continuation (2026-10-11): added tenant-scoped leave settings GET/PUT endpoints with validation, partial-update preservation, tenant locking, and an HR Operations settings page. Backend SettingsTest passed (3 tests / 23 assertions); Phase 4 HR frontend suite passed (19 tests / 6 files); production build passed with the existing chunk advisory; Pint passed.

Phase 4 continuation (2026-10-11): added the Leave Operations bulk-actions page with registry-driven action selection, employee lookup, per-person preview, explicit confirmation, and per-person run results. Phase 4 HR frontend suite passed (20 tests / 7 files); production build passed with the existing chunk advisory. BulkActionsTest passed (3 tests / 18 assertions), including permission checks and cross-tenant hiding; same-tenant DataScope and per-employee exception paths remain open.

Phase 4 continuation (2026-10-11): policy and configured consecutive-day limits now account for adjacent pending/approved requests of the same leave type, with partial-cancelled dates breaking the chain and statutory rules escalating for HR review. RequestRestriction, RequestFlow, and DurationEngine suites passed (22 tests / 173 assertions); Pint passed.

Phase 4 completion (2026-10-11): pending requests expire after 90 days by default (nullable disable); carry-forward now transfers negative balances as signed debt by default (tenant configurable). Added tests for rehire restoration and People transfer-event previews. Leave backend suite passed (109 tests / 1081 assertions), Leave frontend suite passed (39 tests), focused transfer/event suite passed (9 / 65), Pint passed, and production build passed. MariaDB concurrency, browser review, country-specific legal validation, and a few operational edge paths remain release validation items. Phase 5 is next.

Phase 5 continuation (2026-10-11): added compensation revision batch preview/submission, scoped batch history/details, and CSV/XLSX import preview/explicit commit UI with encrypted tenant-scoped source rows, partial validation, idempotency, and individual maker-checker revisions. `CompensationRevisionTest` passed (7 tests / 117 assertions); frontend payroll workspace tests passed (4), production build passed, and Pint passed. Broader Phase 5 tasks remain open.
Phase 5 continuation (2026-10-11): added payroll input CSV/XLSX preview, explicit commit, import history/detail and retry, plus a run-screen upload panel. Input imports retain encrypted source rows and link committed inputs to import provenance while preserving source approval gates. `PayrollInputImportTest` passed (1 test / 25 assertions); input mapping and source-specific edge coverage remain open.
Phase 5 continuation (2026-10-11): implemented core benefits plan setup, eligibility, open enrollment windows, employee self-service enrollment, independent approval tasks, encrypted contribution storage and a My Benefits page. Backend payroll input/end-to-end/benefit tests passed (3 tests / 160 assertions); frontend benefits/run tests passed (3) and build passed. Benefit payroll feeds and broader admin workflows remain.
Phase 5 continuation (2026-10-11): added the payroll Benefits Administration page for plan creation/activation, enrollment windows, and independent enrollment decisions. Admin UI regression test passed; benefit contribution feeds and enrollment lifecycle changes remain.
Phase 5 continuation (2026-10-11): payroll input imports now accept configurable canonical-field/header mapping with case/space normalization and retain encrypted original source rows. Focused tests passed (2 tests / 33 assertions); provider orchestration and source-specific rule coverage remain.
Phase 5 continuation (2026-10-11): benefit termination is employee initiated with an effective date and independent approval/rejection, audit and My Work task completion. `BenefitEnrollmentTest` passed (1 test / 27 assertions); migration adds termination requester/reviewer provenance.
Phase 5 continuation (2026-10-11): payroll adjustments now enforce independent review for every type; linked reversal records are idempotent and preserve original history and calculation semantics. `RunLifecycleTest` and `ProrationEngineTest` cover maker-checker and reversals.
Phase 5 continuation (2026-10-11): compensation CSV/XLSX imports now support configurable header mapping and retain encrypted source and canonical rows; `CompensationRevisionTest` passed (7 tests / 119 assertions).
Phase 5 regression update (2026-10-11): full Payroll feature + unit suites passed (117 tests / 1032 assertions); all Payroll frontend tests passed (25 tests / 8 files); production build passed with the existing >500 kB chunk advisory. Phase 5 remains partial: benefit payroll feeds, retro/import reconciliation, statutory/provider validation and the full integration matrix are still open.

Frontend Operations suite: 5 tests passed, including itinerary editing, asset acknowledgment, receipt upload and travel approval.
LEAN MODE (user request): ~1M tokens/phase. Core workflows + main screens only; list deferred items in each phase report.

## Next action
1. Continue Phase 5 payroll tasks from `tasks/phase-05/progress.md`; do not treat country packs as production-certified without expert sign-off.
2. Phase 4 code is complete; its remaining MariaDB, browser, and statutory review gates are recorded in `docs/PHASE-4-REPORT.md`.
3. Phases 2â€“3 and 6â€“16 remain partial as documented in their phase reports.

- Phase 6 continuation (2026-10-12): Recruitment + Lifecycle backend 51 tests / 871 assertions passed; Recruitment frontend 18 tests passed; production build passed with the existing chunk warning. New migrations `2026_10_26_000002_recruitment_imports` and `2026_10_27_000001_recruitment_documents` ran only in isolated tests; shared MariaDB unchanged. Resume/offer-document UI added (Recruitment frontend 29 tests passed). Configurable pipelines/SLAs and requisition versions added (backend 67 tests / 1113 assertions, frontend 44 tests, build passed). Next: P06-T12 (candidate portal after public-surface security review).

## Phase 6 latest continuation: pipeline defaults and snapshot correctness
- Completed P06-T12a: job-level default pipelines, requisition override/inheritance, saved pipeline UUID/version/stages in list payloads and paginated board lookup.
- Verification: Recruitment + Lifecycle backend 69 tests / 1135 assertions passed; Recruitment frontend 45 tests passed; production build passed with existing bundle warning; targeted Pint formatting completed.
- Migration 2026_10_29_000001_recruitment_job_pipeline ran only in isolated SQLite tests; shared MariaDB unchanged.
- Current next action supersedes the older Phase 5 next-action entry: P06-T12b public careers/application intake and candidate portal. Review plan: docs/RECRUITMENT-PUBLIC-SURFACE.md. Phase 6 remains partial; provider/legal gates remain in the report.

## Current Phase 6 handoff — public careers
P06-T12b implemented: explicit public external posting visibility, public careers/search/application form, encrypted isolated submissions, scoped recruiter review, duplicate refusal and retention cleanup. Recruitment + Lifecycle 72 tests / 1191 assertions passed; frontend 48 tests passed; build passed (existing chunk warning). Final disabled-module retention fix: CareersTest 3 tests / 57 assertions passed. Migration 2026_10_30_000001_recruitment_careers remains unapplied to shared MariaDB. Current next action: P06-T12c verified candidate identity/status portal and provider delivery; public resume upload/scanning remains pending. Phase 6 stays partial.

## Current Phase 6 handoff — verified candidate status portal
P06-T12c implemented locally: hashed expiring single-use browser-bound verification codes, encrypted queue mail jobs, isolated one-hour candidate sessions, own non-confidential status page and logout. Recruitment + Lifecycle 76 tests / 1245 assertions passed; frontend 50 tests passed; production build passed with existing chunk warning. Migration 2026_10_31_000001_recruitment_portal remains unapplied to shared MariaDB. Portal is default-off; requires validated mail transport, asynchronous queue worker and secure session deployment. Mail::fake was used, so delivery is unverified. Next P06-T12d: candidate self-service documents/withdrawal/offer response. Phase 6 remains partial.

## Current Phase 6 handoff — candidate withdrawal
P06-T12d completed locally: verified candidate withdrawal with confirmation/reason, ownership/state guards, candidate-authored history, offer/interview/task cancellation and idempotent retry. Recruitment + Lifecycle 77 tests / 1270 assertions passed; frontend 51 tests passed; production build passed with existing bundle warning. Migration 2026_11_01_000001_recruitment_candidate_actions remains unapplied to shared MariaDB. Next P06-T12e: explicit candidate-visible offer document release and accept/decline. Portal mail/queue/provider and legal gates remain. Phase 6 is partial.

## 2026-10-12 - Phase 7 feature continuation

User requested feature-first implementation with basic checks and deferred full testing, then Phase 8 without another approval. Phase 7 visual checklist editing, tenant-scoped named owners, prerequisite tasks, owner-only acknowledgments and rehire onboarding population selection are implemented. PHP syntax and frontend production build passed. Phase 7 remains partial; see docs/PHASE-7-REPORT.md for remaining work and deployment gates. Phase 6 offer releases/responses are implemented locally (focused portal 6/101); its broader gaps remain open. No shared database migration or external provider rollout occurred.

Basic checks after reminder timing: frontend production build passed (1817 modules, existing chunk-size warning). PHP syntax checks passed. Earlier onboarding smoke passed 5 tests / 61 assertions; reminder-specific check results are recorded in the Phase 7 progress log. Full regression remains deferred.

## Latest Phase 7 continuation - P07-T09 resignation requests

Implemented employee resignation submission, HR review/approval/rejection, pending withdrawal and reviewed withdrawal after approval, plus scoped My Work review tasks. Existing SeparationService drives offboarding and cancellation. Basic backend checks passed 2 tests / 27 assertions; frontend build passed. New migration 2026_11_04_000001_lifecycle_resignations.php remains unapplied to shared MariaDB. Full regression deferred at user request. Phase 7 remains partial: contract renewals, extended-absence returns, secure collection, bulk operations, conditional branches, exports and remaining clearance integrations. See Phase 7 report/progress. Phase 8 has not been advanced in this continuation.

P07-T09 final focused verification: 3 tests / 38 assertions passed; includes rejection/resubmission and People withdrawal/housekeeping reconciliation. Full testing remains deferred.

## Latest Phase 7 continuation - P07-T10 return to work

Implemented scoped HR return requests, independent approval/rejection, My Work reviews, status-history stale guards and absence_return checklist templates. Approval restores active/probation via People StatusService on the approval day. Basic tests and frontend build passed; see Phase 7 progress for final counts and remaining limitations. New migration 2026_11_05_000001_lifecycle_returns.php remains unapplied to shared MariaDB. Full regression deferred. Phase 7 remains partial; contract renewal is the next workflow gap, alongside secure collection/bulk/branches/exports/clearance work.
