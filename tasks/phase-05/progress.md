# Phase 5 — Global Payroll, Compensation & Benefits

**Goal:** deterministic, decimal-safe and auditable payroll from finalized People, Attendance and Leave inputs through reviewed results, payslips, payments and accounting outputs.

**Mode:** Lean-mode implementation: core workflows and main screens, with deferred product and release gates explicitly tracked. The master specification is `hrms_master_16_phases.md`, Phase 5. Do not mark complete until payroll is deterministic, legally validated for every enabled country, explainable, reconciled and securely approved.

## Workstream status

| ID | Scope | State | Remaining gate |
|---|---|---|---|
| P05-T01 | Global architecture, tenant/entity settings, country-pack/version contracts | Core | Country-specific legal validation |
| P05-T02 | Payroll groups, calendars, frequencies, periods, cutoffs and transitions | Core | Production DB/concurrency validation |
| P05-T03 | Versioned components, safe formulas, rounding, tax classification and mappings | Core | Broader rule/configuration coverage |
| P05-T04 | Compensation, effective dating, revisions, batch preview/review and imports | Core | Wider revision lifecycle and salary-letter workflows |
| P05-T05 | Payroll inputs, provenance, approval rules, imports and retries | Partial | Provider orchestration and source-specific reconciliation |
| P05-T06 | Decimal-safe deterministic employee calculation, traces and immutable results | Core | Broader retro/off-cycle and scale matrix |
| P05-T07 | Effective-dated statutory country packs and verification gates | Framework | Expert-validated content and country tests |
| P05-T08 | Loans, advances and recovery schedules | Core | Extended operational scenarios |
| P05-T09 | Run creation, collect, validate, calculate, review, lock and reopen | Core | Full operational/large-batch validation |
| P05-T10 | Compensation revision batches and compensation CSV/XLSX import | Partial | Broader end-to-end review/status scenarios |
| P05-T11 | Validation, readiness, variance and control totals | Core | Remaining domain-specific policies |
| P05-T12 | Payroll input collection/import, mapping, provenance, dedupe and retry | Partial | Provider orchestration and source-specific rules |
| P05-T13 | Payslips and corrections | Partial | PDF/layout support; current secure HTML print flow |
| P05-T14 | Payment batches and provider/bank output | Partial | Real bank formats and confirmation integrations |
| P05-T15 | Accounting mappings, journals and exports | Core | External ledger reconciliation |
| P05-T16 | Retro, arrears, adjustments and reversals | Partial | Cross-tax-year retro/reversal matrix |
| P05-T17 | Reports, explainer, difference, simulation and quality insight | Core | Broader reporting and operational coverage |
| P05-T18 | Employee 360, My Work, audit, notifications and integrations | Partial | Live provider adapters and delivery verification |
| P05-T19 | Benefits enrollment, fixed contributions and loans | Partial | Formula/provider contributions and broader reconciliation |
| P05-T20 | API/schema security, tenant isolation and private source records | Core | Production rollout and DB review |
| P05-T21 | Domain regression, precision, concurrency, integration and scale tests | Partial | Complete release/integration matrix |

“Core” means the lean-mode core is present and covered by targeted tests; it does not mean statutory or production certification.

## Delivered

- Payroll groups/calendars/periods, salary components and formulas, compensation records/revisions, payroll input collection, deterministic calculations, immutable results, approvals, locks, reopen, readiness, validation, explainer, differences, simulation, loans, tax declarations, payslips, payment batches, accounting journals, settlement and reports.
- Compensation revision batch preview/submission, scoped history/details and CSV/XLSX preview/commit. Header mappings are configurable; source and canonical rows are encrypted, row errors are isolated, and valid revisions use independent maker-checker approval.
- Payroll input CSV/XLSX preview, explicit commit, import history/detail/retry and configurable canonical-field-to-header mapping. Preview does not mutate inputs; commits retain encrypted source rows and source-based approvals/provenance.
- Benefits plan administration, eligibility, enrollment windows, employee self-service enrollment and termination requests, encrypted fixed contributions, independent decisions, My Work and audit history, plus employee/admin UI.
- Fixed benefit employee/employer contributions now enter regular payroll calculations as separately coded components, prorated by covered calendar days and carrying enrollment/plan/date provenance. Pending enrollments are excluded; effective termination dates bound historical contributions.
- All payroll adjustments require independent review. Approved adjustments can be reversed through linked, opposite-signed, independently reviewed records while preserving original history and calculation semantics.

## Verification

- Payroll backend feature/unit suites: **117 tests / 1036 assertions passed** after the fixed benefit contribution feed (2026-10-11).
- Payroll end-to-end payroll cycle test: **1 test / 121 assertions passed**, including mid-period benefit contribution proration (2026-10-11).
- Repository backend suite before the latest benefit-feed change: **760 tests / 6795 assertions passed** (2026-10-11).
- Repository frontend suite: **204 tests across 37 files passed**; automation fixture supplies the required event-type option (2026-10-11).
- Production frontend build passed with the existing >500 kB chunk advisory. Pint passed for changed PHP files.
- Tests use the configured test database. Shared MariaDB was not migrated or exercised in this continuation.

## Deferred/release gates

- Formula-based benefit contributions, live benefits/payroll provider integration and broader benefit-change/reconciliation scenarios.
- Provider orchestration for attendance, leave and variable inputs; source-specific imports and full source/adjustment reconciliation.
- Cross-tax-year retro/arrears and reversal scenarios, including late attendance/leave and off-cycle reconciliation.
- Country-pack legal content, statutory filings and expert review. Samples/framework are not certified and must not be represented as legally validated.
- Real country/bank-specific payment formats and external confirmation providers; current output is generic CSV.
- PDF payslips; current payslips are secure HTML print views.
- Production MariaDB concurrency, browser review, large-batch/load checks and provider specifications.

## Continuation log (2026-10-11)

- Compensation revision batches and mapped CSV/XLSX import added with maker-checker revision handling.
- Payroll input import preview/commit/history/retry and configurable header mapping added with encrypted source provenance.
- Benefits plan, enrollment, termination review and administration UI added; fixed employee/employer contribution results now flow to payroll with effective-date proration.
- Payroll adjustment maker-checker and linked reversal flow added; retro across tax years remains open.
- Full backend and frontend repository regressions passed before the latest benefit-feed change; the focused Payroll suite passed after it.

## Next action

Continue Phase 5 with formula/provider benefit contributions and source/provider reconciliation, then expand the retro/import scenario matrix. Keep country-law, real bank/provider and production database gates open until their specifications and external verification exist. Do not mark Phase 5 complete based only on green automated tests.
