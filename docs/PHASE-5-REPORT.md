# Phase 5 — Global Payroll, Compensation & Benefits

**Status: partial; lean-mode payroll core is implemented.** Payroll run operations and the main employee/finance workflows exist, but important benefit, statutory, integration, and full specification gates remain.

## Implemented core

- Payroll groups, calendars, pay periods, transitions, component/formula configuration, salary structures, employee compensation, revisions, and country-pack framework.
- Compensation revision batches, scoped batch history/details, and compensation CSV/XLSX preview/commit with configurable header mapping and partial validation.
- Input collection, attendance/leave adapters, manual inputs, payroll-input CSV/XLSX preview/commit/history/retry with configurable header mapping, payable-day proration, deterministic employee calculation, versioned immutable results, run approvals/locks/reopen, readiness, command center, explanations, differences, and simulations.
- Loans/advances, tax declarations, payslips, payment batches with generic CSV, accounting journals, settlement, reports/exports, data-quality and Employee 360 integrations, and sensitive-access logging.
- Benefits plans, fixed contribution rules, eligibility, enrollment windows, employee self-service enrollment and termination requests, independent review and encrypted contributions; fixed employee/employer contributions now flow into payroll results with effective-date proration and traceable provenance; My Benefits and Benefits Administration pages. All payroll adjustments require an independent reviewer, and reversal adjustments preserve the original record.
- Employee self-service and payroll-team frontend screens are registered and have focused tests.

## Verification

- `cd backend && php artisan test tests/Feature/Payroll tests/Unit/Payroll` — **117 tests, 1036 assertions passed** (2026-10-11).
- `cd frontend && npm.cmd test -- --run src/pages/payroll` — **25 tests across 8 files passed** (2026-10-11).
- `cd frontend && npm.cmd run build` — **succeeded**. Vite reports the generated JavaScript chunk exceeds 500 kB.
- Tests use the project's configured test database. A migration was exercised by the benefits feature test. No production MariaDB concurrency, statutory validation, or bank integration was performed.

- Repository-wide regression (2026-10-11): backend 760 tests / 6795 assertions passed; frontend 204 tests across 37 files passed; production build succeeded.

## Deferred or limited

- Fixed benefit contributions flow directly into payroll calculation results for regular runs with effective-date proration. Formula contributions, provider integrations, payroll-input reconciliation, and broader benefit-change scenarios remain.
- Payroll input imports support configurable header mapping; provider orchestration and complete source/adjustment reconciliation remain. Full retro across tax years remains.
- Manual adjustments require an independent reviewer and approved/applied adjustments can be reversed through a linked, maker-checked record. The broad retro-across-tax-years matrix remains unverified.
- Country-pack rules are configurable framework/sample data only; no country's statutory content is certified or enabled as legally validated.
- The only bank output is the neutral `generic_csv` adapter. Real bank formats need provider specifications.
- Payslips are secure HTML print views; no PDF dependency is installed.
- The full repository suites passed before the latest fixed-benefit-feed change; the Payroll feature/unit suite passed afterward (117 tests / 1036 assertions). The full Phase 5 domain integration edge-case matrix and production database/provider validation remain open.

## Next

Continue Phase 5 from `tasks/phase-05/progress.md`, prioritizing formula/provider benefit support and unresolved retro/import reconciliation. Do not mark the phase complete until its integration matrix and external validation gates are addressed.
