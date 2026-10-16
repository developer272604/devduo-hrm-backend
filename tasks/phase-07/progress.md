# Phase 7 - Onboarding, Offboarding and Employee Lifecycle

Status: partial. Updated 2026-10-12. See `docs/PHASE-7-REPORT.md` and `docs/api/lifecycle-onboarding.md`.

| ID | Work | Status |
| --- | --- | --- |
| P07-T01 | Inspect lifecycle scope | Done; refreshed against current code |
| P07-T02 | Move API/UI payload and approver scope alignment | Implemented; earlier targeted tests passed |
| P07-T03 | Core Lifecycle regression | Historical: backend 16/220; frontend 11; full rerun deferred |
| P07-T04 | Reconcile remaining scope | Updated; full phase remains partial |
| P07-T05 | Visual checklist editor and workspace owner lookup | Implemented; production build passed |
| P07-T06 | Snapshot prerequisites and owner acknowledgments | Implemented; focused smoke result below |
| P07-T07 | Rehire onboarding trigger and population conditions | Implemented; syntax/build checked; dedicated regression deferred |

Existing People probation confirm/extend is reused. Asset/expense clearance adapters exist in Operations; other clearance integrations still need work.

Next: resignation initiation/withdrawal, contract renewal, extended-absence returns, secure collection, bulk assignment, conditional branches, exports. External provisioning and legal review remain gates. Full tests deferred by user request; implementation status does not imply production validation.

Latest basic verification: `php artisan test tests/Feature/Lifecycle/OnboardingTest.php` passed 5 tests / 61 assertions, including prerequisite blocking and owner acknowledgment enforcement. Frontend build passed. Full regression and dedicated rehire/browser validation remain deferred.

P07-T08: Per-template-task reminder and escalation timing implemented and copied into generated cases. Defaults preserve two-day reminders and next-day escalation. Advanced escalation routing/business-day rules remain deferred.

Final focused check after timing changes: `php artisan test tests/Feature/Lifecycle --filter="prerequisites_and_owner|configured_reminder|escalation_and_reminders"` passed 3 tests / 29 assertions. Covers configured due-day reminders, delayed escalation, retry idempotency and prerequisite/acknowledgment enforcement. Full suite remains deferred.

## P07-T09 - Employee resignation requests (2026-10-12)

Implemented employee submission with confirmation and proposed last working date, an HR review queue, approval/rejection, direct withdrawal of pending requests, and reviewed withdrawal after approval. Uses existing SeparationService so approval starts notice period/offboarding and accepted withdrawal cancels open offboarding cases. Self-approval is prohibited even for HR administrators. Ownership, tenant scoping, people.separate data scope, transaction locks and expected-state guards are enforced. Duplicate active requests/separations are refused. Reasons and decision notes are encrypted on the request; audit records contain state metadata, not sensitive text.

Scoped HR reviewers receive My Work tasks; resolving a review closes them. The list reflects direct People separation completion/withdrawal, and lifecycle housekeeping clears obsolete review tasks. Employee and reviewer routes are available under Lifecycle navigation. No shared database migration was run.

Basic verification: ResignationTest 2 tests / 27 assertions passed (ownership, self-decision, confirmation, duplicate/stale guards, approval/offboarding, withdrawal and task closure); frontend build passed (1818 modules, existing bundle warning). Full regression, browser tests, cross-tenant/concurrency matrix and deployment verification remain deferred by request.

Remaining Phase 7: contract renewal, extended-absence return workflows, secure collection, bulk assignment, conditional checklist branches, reports/exports and remaining clearance integrations. Phase 7 remains partial. Phase 8 transition is authorized once remaining Phase 7 implementation is complete; no confirmation is needed.

Final P07-T09 check: ResignationTest passed 3 tests / 38 assertions, adding rejection/resubmission and direct People withdrawal/housekeeping reconciliation. PHP syntax and diff whitespace checks passed. Full regression remains deferred.

## P07-T10 - Return to work (2026-10-12)

Implemented HR return requests for employees on long_leave, scoped searchable employee selection, independent approval/rejection and a dedicated absence_return checklist kind. Approval records today's employee-local date, uses People StatusService to restore active/probation, and requires a matching published checklist. Case tasks copy the published settings; each request has its own case source, avoiding reuse across separate absences on the same day. Employment status-history snapshots reject stale approvals even if an employee leaves and later re-enters long_leave. Rejection remains available for stale requests.

Reviewers receive scoped My Work tasks, closed on decision. Operational notes are encrypted; state metadata is audited. Frontend return queue and template type are available under Lifecycle. Permission: people.manage with employee data scope; requester and subject cannot approve their own request.

Basic checks: ReturnTest passed 2 tests / 15 assertions before final case-source/task-closure assertions. Frontend build passed (1819 modules; existing chunk warning). Full regression, browser/concurrency checks and provider acceptance remain deferred. Migration 2026_11_05_000001_lifecycle_returns.php is isolated-test only; shared MariaDB remains unchanged.

Remaining: contract renewals, secure collection, bulk assignment, branches, reports/exports, clearance integrations. Return workflow still excludes future/backdated scheduling, medical clearance evidence, automatic leave entitlement/payroll adjustment and substitute approvers. Full Phase 7 remains partial.

Final P07-T10 focused verification: ReturnTest passed 2 tests / 18 assertions, including case-source storage bounds and reviewer-task closure. Full regression remains deferred.
