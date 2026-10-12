# Phase 7 - Onboarding, Offboarding and Employee Lifecycle

Status: partial. Updated 2026-10-12. Feature implementation first; full regression and browser testing deferred at the user's request.

## Implemented

- Versioned templates and generated cases, checklist ownership, My Work, readiness, reminders, rescheduling and cancellation.
- Separation/offboarding, encrypted exit interviews, settlement handoff, alumni and rehire eligibility.
- Internal moves with maker-checker approval and effective dates; lifecycle dashboard and employee views.
- Visual checklist editor with named owner search, task grouping, due offsets, mandatory/document flags, prerequisites and acknowledgment statements.
- Prerequisites refer to earlier template tasks, preventing dependency cycles. Cases snapshot prerequisites as instance references. The server rejects completion while prerequisites remain pending.
- Acknowledgments require explicit confirmation by the assigned owner. HR cannot acknowledge on another person's behalf. Statement and acknowledgment timestamp are retained on the case task.
- Rehire events generate onboarding cases. Templates can target first employment, returning employees or both; existing conditions and named owners survive new-version creation.
- Existing People ProbationService already provides confirmation/extension with scoped permissions and self-decision protection. Template-driven probation cases remain separate pending work.
- Operations registers real asset and expense clearance providers. Loans, leave and payroll still use fallback providers in the Lifecycle registry; they must not be represented as verified clearance integrations.

## Basic verification in this continuation

- PHP syntax checks passed for changed lifecycle services, controller, boot registration, routes and migration.
- Frontend production build passed (1817 modules); existing large-chunk warning remains.
- Existing onboarding smoke: 4 tests / 49 assertions passed. A focused prerequisite/acknowledgment smoke test was added; see progress for its latest result.
- Full regression, frontend interaction tests, rehire event integration, concurrency and deployed database/provider verification are deferred. Earlier report test counts are historical, not validation of these changes.

## Schema and deployment

New migration: `2026_11_03_000001_lifecycle_task_dependencies.php`. It stores dependencies and acknowledgment statements on template and case tasks, plus acknowledgment timestamps. Exercised only on isolated SQLite test databases; shared MariaDB has not been migrated.

## Remaining local scope

- Resignation initiation/withdrawal, contract renewal and extended-absence return workflows.
- Secure document/form collection beyond document references; bulk assignment, conditional branches inside templates, reports/exports.
- Template-driven probation cases and complete cross-module clearance integration.
- Dedicated frontend and broader regression coverage for the new features (deferred by request).

## External gates

Production account provisioning requires adapters, credentials and provider acceptance checks. Acknowledgment recording is not e-signature certification. Employment notices, consent, retention and legal acceptance require qualified country-specific validation.

Phase 7 is not complete. Continue remaining Phase 7 features before treating the Phase 8 transition as phase completion; no additional permission is needed for that transition.

## Configurable reminder timing

Checklist tasks now snapshot `remind_before_days` (0-365, default 2) and `escalate_after_days` (1-365, default 1). The editor exposes both values. Daily lifecycle reminders apply these windows and retain existing once-per-task stamps; rescheduling clears the stamps as before. This closes basic configurable reminder timing, while escalation routing, business-day calendars and advanced SLA policies remain open. This continuation's migration also includes the timing columns; it has not been deployed to shared MariaDB.

Basic onboarding smoke including the new prerequisite/acknowledgment guard passed 5 tests / 61 assertions. Final frontend build after configurable reminder timing passed (1817 modules; existing chunk warning).

Final focused timing/acknowledgment smoke: 3 tests / 29 assertions passed, including configurable windows and repeat-safe escalation. Full regression remains deferred by request.

## Latest continuation - resignation requests

Employee self-service resignation and withdrawal, HR review, scoped My Work tasks, and existing separation/offboarding integration are now implemented (P07-T09). A request alone does not change employee status. HR approval uses the proposed last day; passed dates require a new request. An approved resignation requires HR approval to withdraw. These are configurable-product workflow choices, not country-specific legal certification; notice entitlements, acceptance requirements and local employment policy still require validation.

New schema: `2026_11_04_000001_lifecycle_resignations.php`; exercised only in isolated tests. Reasons, withdrawal reasons and latest decision notes are encrypted in the request; separation records receive a request reference. State changes are audited without those sensitive texts. Decision endpoints require people.separate and its employee data scope; self-approval is prohibited. Direct People completion/withdrawal is reflected in the list; scheduled housekeeping closes stale review tasks.

Verified locally: 2 focused tests / 27 assertions passed; frontend production build passed (existing chunk warning). Full regression/browser/concurrency verification is deferred. Remaining requests without an available in-scope reviewer remain in the HR queue; automatic substitute/delegated approval and notifications on reviewer-role changes are not implemented. Resignation initiation/withdrawal is no longer entirely missing; advanced policy/notice negotiation, notification history and retention-policy configuration remain gaps.

Final resignation verification: 3 tests / 38 assertions passed after adding direct People withdrawal reconciliation and rejection/resubmission coverage. This supersedes the earlier 2-test count for P07-T09. No production database migration performed.

## Latest continuation - return from extended absence

P07-T10 adds scoped HR return requests, independent approval/rejection, My Work reviews and a published absence_return checklist. Approval transitions long_leave to active (or unconfirmed probation) through People StatusService on the approval day in the employee's timezone. Stale status histories, duplicate pending requests and self-approval are blocked. Matching published checklist is required, and each approved request creates its own case. Encrypted operational notes are not included in audit metadata.

Migration: 2026_11_05_000001_lifecycle_returns.php; no shared MariaDB migration. Focused tests and production build passed; final test count is recorded in progress. Full regression/browser/concurrency tests deferred per user instruction. Return initiation is now implemented; future/backdated return scheduling, medical-evidence collection, leave/payroll adjustments and country-specific policy validation remain open. Contract renewal and the other Phase 7 gaps remain pending. Phase 8 is not advanced by this change.

Final return-to-work basic verification: 2 tests / 18 assertions passed. Frontend build passed. Full validation remains deferred.
