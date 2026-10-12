# Phase 9 — Learning and Skills

**Status: partial; core plus assignment, compliance, assessments, certificates, skills administration and dashboards are now routed.** The repository previously contained Phase 9 migrations and partial catalog/skill services without an HTTP API or frontend. This continuation connects a first employee and catalog-manager flow end to end.

## Implemented in this continuation

- Tenant-scoped, permission-gated Learning API routes for course catalog, course detail and versioned create/edit; course publishing/retirement; path listing/creation; session listing/scheduling; employee enrollment and learning history; skills catalog, proficiency levels, self skill profile/history and role gap analysis.
- Employees see published courses; catalog managers may see drafts. Self-enrollment rejects duplicate active/completed entries and unmet prerequisites, serializes concurrent enrollment through a course row lock, and applies the session waitlist/capacity policy. Approval-required courses are recorded as pending.
- Browser navigation and screens for catalog browse/publish, self-enrollment, learning history, path progress and self-declared skills/role gaps.
- Focused regression tests cover course visibility, publish/enroll, duplicate prevention, prerequisite enforcement and UI enrollment/skill declaration.

## Implemented in the completion pass

Backend (all tenant-scoped, permission + DataScope checked, audited): manager/HR assignment of courses and paths to employees or groups with due dates; approval decisions with My Work routing and maker-checker; session attendance and completion; compliance rules (designation/department/legal entity/country, recurrence, escalation) with idempotent `learning:compliance-run`; assessments with server-side scoring, pass mark, attempt limits and no answer-key exposure; certificates with expiry, renewal chain, idempotent `learning:certificate-reminders` and printable HTML; skill categories/skills/proficiency scale, role requirements, manager validation with evidence and gap views; team dashboard, L&D command center, decimal-safe cost totals and masked CSV export; Employee 360 section, Action Center insights and data-quality rules (`LearningBoot`).

Frontend (`LearningManagePages.jsx`, routes in `learningRoutes.jsx`): Team learning, Compliance, Assessment taking, Certificates, Team skills, Skills admin and Dashboard screens.

Migrations: `2026_10_29_000001_learning_assessment_compliance_completion` (attempt limits, attempt numbering, rule legal entity, certificate course link), `2026_10_29_000002_grant_manager_learning_enrol_manage`. No new permission keys were needed. Shared one-line edits: `ModulesServiceProvider` boot directory, `WorkspaceProvisioner::MANAGER`, two `Schedule::command` lines in `routes/console.php`.

## Verification (actually run)

- `cd backend && php vendor/phpunit/phpunit/phpunit tests/Feature/Learning --colors=never` — **22 tests, 278 assertions passed**; run together with `tests/Feature/Performance/LearningCoreTest.php`: 24 tests / 298 assertions passed.
- A targeted subset of People/Foundation tests filtered on Insight/ActionCenter/Quality/360/Permission/Provision/Access — 33 tests passed (confirms the new registrations did not break shared registries). The full backend suite was **not** run in this pass.
- `cd frontend && npm test -- --run src/pages/learning` — **9 tests passed** (2 existing + 7 new).
- `cd frontend && npm run build` — succeeded (Vite >500 kB chunk advisory only).
- Not done: browser testing; applying the migrations to the dev MariaDB (the Phase 9 ones and `2026_10_29_*` are still pending).

## Deferred

Training requests and budget administration endpoints, course feedback, development-plan linkage, waitlist auto-promotion on cancellation and a session-cancel endpoint, content-provider integrations, UI for authoring assessments/certification links on a course (API exists), per-employee time zones for due-date calculation (tenant time zone is used). Training rules are tenant-configured data; nothing here asserts legal compliance.

## Next

Phase 10 inspection found no implementation. Begin its HR helpdesk foundation; see `docs/PHASE-10-REPORT.md`.
