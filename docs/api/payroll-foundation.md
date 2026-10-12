# Payroll foundation (Phase 5, P05-T01..T05)

Shared base every payroll workstream builds on. Code: `backend/app/Domain/Payroll/**`; schema: `database/migrations/2026_10_11_000001_create_payroll_foundation.php`
(pre-existing, untouched) extended by `2026_10_14_000001_create_payroll_engine_schema.php`; permission grants: `2026_10_14_000002_grant_payroll_permissions.php`.
Plan, classification and ownership: `tasks/phase-05/progress.md`.

## 1. Non-negotiables

* **Decimal only.** Money and quantities are decimal strings (`Support\Decimal`, `Support\Money`, `Support\Rounding`, Brick Math). Floats are rejected (`InvalidArgumentException`), never converted. Rounding is always explicit (mode, scale, optional increment); the only implicit scale is `Money::INTERNAL_SCALE` (6) for intermediates.
* **Currencies are never mixed.** `Money` throws `CurrencyMismatch`; cross-currency needs a recorded rate (`currency_exchange_rates`). Payroll uses the group currency.
* **Results are immutable.** `payroll_calculation_results` and `payroll_result_components` are append-only (model-enforced; only `is_current` / `superseded_by_id` bookkeeping may change). A recalculation or correction is a new version. Only `Contracts\ResultStore` writes them.
* **Rules are versioned data.** `payroll_country_pack_versions` (published = immutable), `salary_components` rows (code + version), `salary_structures` (code + version). Results store the exact ids/versions/formula text used. History is never recalculated with a newer rule except by a controlled correction run.
* **No country law in the core.** Statutory behaviour = pack-version rules JSON interpreted by the `declarative` handler, or a registered `StatutoryPack` class. The foundation registers none.
* **Employee is not User.** Payroll rows reference `employees.id`; users appear only as actors. All tables are tenant-owned (`TenantRecord`), aggregate roots expose `uuid`. Cross-tenant access is 404.
* **Reuse, do not rebuild.** Approvals = Attendance `ApproverResolver` + My Work `TaskService` (+ `payroll_approvals` records); events = `DomainEvents::record` with `Support\PayrollEvents` (payloads: uuids and facts, never money/bank data); audit = `Audit::record`; insights = `InsightProvider`; data quality = `QualityRule`; Employee 360 = `Employee360Provider`.

## 2. Tables (models in `App\Domain\Payroll\Models`)

Spec name -> table: `payroll_country_packs`, `payroll_country_pack_versions`, `payroll_groups` (extended), `payroll_calendars`, `payroll_periods` (+ `payroll_period_transitions`), `salary_components` (= salary_component_versions), `salary_structures`, `salary_structure_components`, `employee_compensations` (extended), `compensation_lines` (= employee_compensation_components, extended), `compensation_revisions` (+ `compensation_revision_batches`), `payroll_runs`, `payroll_run_employees`, `payroll_imports`/`payroll_import_rows`, `payroll_inputs`, `payroll_adjustments`, `payroll_calculation_results` + `payroll_result_components` (= the payroll ledger), `payroll_validation_issues`, `payroll_locks`, `payroll_approvals`, `payslips`, `payment_batches`, `payment_instructions`, `bank_file_exports`, `accounting_mappings`, `accounting_exports` (+ `accounting_export_lines`), `employee_loans` + `loan_installments`, `employee_advances`, `benefit_plans` (+ `benefit_enrollment_windows`), `employee_benefit_enrollments`, `payroll_tax_declarations` (+ proofs), `settlement_runs` (+ `settlement_items`), `payroll_simulation_runs`, `payroll_exports`, `payroll_statutory_filings`, `currency_exchange_rates`.

Conventions: result/instruction/journal amounts `decimal(18,4)` (models cast `decimal:4`, so Eloquent returns strings); quantities `decimal(18,6)`; compensation figures, payslip snapshots, beneficiary data, declarations, traces and simulation results are **encrypted** columns (model casts). Raw `DB::table()` reads on SQLite return int/float: use `Decimal::fromDb($v, $scale)`.

State vocabularies and legal transitions: `Support\PayrollStates` (`PERIOD_TRANSITIONS`, `RUN_TRANSITIONS`, `RUN_STAGES`, severities `information|warning|blocking|critical`, payment statuses). Always `assertTransition()` and audit; period moves also insert a `payroll_period_transitions` row.

## 3. Result contract (`Contracts\ResultStore`, impl `Results\ResultStore`)

`record(ResultDraft)`:
* Component amounts are positive magnitudes at most at the currency exponent (an unrounded value is **refused**, so the engine must round explicitly); the `kind` gives direction.
* Totals are derived, never supplied: `gross` = earnings; `total_deductions` = deduction + employee_contribution; `total_taxes` = tax; `reimbursements`; `benefits`; `employer_contributions`; **`net = gross + reimbursements - total_deductions - total_taxes`**. `ResultStore::totals()` is static so simulators use identical rules.
* Idempotent by checksum (sha256 over canonical inputs, components, rule versions, engine version). Identical draft => the current result. Changed draft => version + 1, previous marked superseded.
* Refuses new versions when the run is `approved|locked|outputs|payment|closed|cancelled` (HTTP 423, reason `run_locked`) unless `controlledCorrection: true`.
* `verify($result)` recomputes the checksum from stored rows (reproducibility / tamper check).
* One employee's failure is carried in the draft (`errors[]`, status `failed`) and never throws for the run.

## 4. Contracts and registries

| Contract | Owner | Bound / registered |
| --- | --- | --- |
| `PayrollInputProvider` (`collect(PayrollInputQuery): PayrollInputBatch`) | adapters: foundation; more: B | `PayrollInputRegistry` (singleton). Registered here: `attendance` -> `AttendancePayrollInputProvider` (Phase 3 payroll output; needs an actor with attendance scope; kinds `payable_days, present_days, absent_days, holiday_days, weekly_off_days`, plus source `overtime` kind `approved_overtime_minutes`), `leave` -> `LeavePayrollInputProvider` (Phase 4 `PayrollContract`; kinds `leave_paid, leave_unpaid, leave_statutory, leave_lop, leave_comp_off, leave_encashment`; optional `$batchKey` reads the published immutable batch). Lines: decimal-string `quantity`, `unit`, `reference` (provenance), `isFinal`, `lateAdjustment`; `dedupeKey()` for `payroll_inputs.dedupe_key`. Batches carry `complete` + warnings for readiness. Attendance and leave are never recomputed here |
| `FormulaEvaluator` (`evaluate`, `validate`, `references`) | A extends grammar | bound to `Calculation\FormulaEngine` over `Calculation\Formula` (no eval; `+ - * / ( ) MIN MAX`; variables decimal strings; result carries a trace) |
| `StatutoryPack` (`calculate(StatutoryContext): StatutoryResult`, `validate`, `identifiers`, `reports`) | A | `StatutoryPackRegistry` (empty; A registers `declarative`) |
| `PaymentFormat` (`validate`, `generate(PaymentBatchData): GeneratedFile`) | C | `PaymentFormatRegistry`; registered: `generic_csv` (neutral reference format, formula-injection safe) |
| `AccountingMapper` (`map(AccountingContext): JournalLine[]`, `export`) | C | `AccountingMapperRegistry` (empty) |
| `CompensationResolver`, `PackResolver` | A | interface only; A binds in `ConfigBoot` |
| `EmployeeCalculator` (pure; returns `ResultDraft`) | B | interface only; B binds in `RunBoot`; D's simulator calls the same method |
| `ResultStore` | foundation | singleton bound in `FoundationBoot` |

Boot: `ModulesServiceProvider` already loads `Domain/Payroll/Boot/*Boot.php` in name order. Existing `PayrollBoot` (Employee 360) and new `FoundationBoot` exist; A/B/C/D add `ConfigBoot`, `RunBoot`, `OutputsBoot`, `OperationsBoot`. Never register into another workstream's Boot.

## 5. Permissions (`WorkspaceProvisioner::PERMISSIONS`, group "Payroll")

Admin/operations: `payroll.configure`, `payroll.country_pack.manage`, `payroll.country_pack.publish`, `payroll.compensation.view|manage|approve|mass|reveal`, `payroll.view`, `payroll.inputs.manage|approve|import`, `payroll.run`, `payroll.approve`, `payroll.lock`, `payroll.unlock`, `payroll.unlock.approve`, `payroll.adjust`, `payroll.adjust.approve`, `payroll.results.view`, `payroll.explain`, `payroll.simulate`, `payroll.payslips.manage`, `payroll.payments.prepare|approve|confirm`, `payroll.accounting.manage|export`, `payroll.statutory.reports`, `payroll.reports.view`, `payroll.export`, `payroll.loans.manage|approve`, `payroll.benefits.manage`, `payroll.settlement.manage|approve`, `payroll.tax_declaration.review`, `payroll.quality.view`.
Self-service (`WorkspaceProvisioner::PAYROLL_SELF`): `payroll.payslips.view_own`, `payroll.compensation.view_own`, `payroll.loans.view_own`, `payroll.benefits.view_own`, `payroll.benefits.enroll_own`, `payroll.tax_declaration.submit`.

Defaults: Workspace administrator = all. HR administrator, Manager, Team member = **only** the self-service keys (HR is no longer a payroll administrator by default; managers never see salary by default). Custom roles are untouched. Maker-checker pairs: compensation manage/approve, adjust/adjust.approve, unlock/unlock.approve, payments.prepare/payments.approve, run/approve, settlement.manage/approve, loans.manage/approve; the checker must be a different user. Data scope applies on top (`DataScope::constrain` with the permission key).

Field classification: `FieldRegistry` group `compensation` (self edit `none`): `compensation.base_pay|gross_reference|annual_compensation|hourly_rate|daily_rate|components|history`, `payroll.net_pay`, `payroll.result`, `payslip.snapshot`, `loan.records`, `advance.records`, `benefit.enrollment`, `tax_declaration.records` - all `highly_sensitive` (masked unless the viewer holds the reveal permission and, for payroll, `payroll.compensation.reveal` with purpose - implemented by D).

## 6. Settings (`Support\PayrollSettings`, `tenants.settings.payroll`)

Sections: `calculation, approvals, inputs, validation, privacy, payslips, payments, accounting, currency, exports` (see class docblock). `PayrollSettings::get('approvals.run_checker')`. Defaults are conservative (negative net blocks, maker-checker on, managers do not see compensation, amounts masked in notifications).

## 7. Routes (all behind `module:payroll` + `permission:` per route)

| File | Prefix | Owner |
| --- | --- | --- |
| `routes/payroll/config.php` | `/api/v1/payroll` | A (contains the pre-existing groups, components, employees, compensations, decision routes) |
| `routes/payroll/runs.php` | `/api/v1/payroll` | B |
| `routes/payroll/outputs.php` | `/api/v1/payroll` | C |
| `routes/payroll/self.php` | `/api/v1/payroll/me` (C mounts `me`) | C |
| `routes/payroll/operations.php` | `/api/v1/payroll` | D (contains pre-existing `readiness` placeholder and `simulate`) |
| `routes/payroll/compensation.php` | `/api/v1/compensation` | A |
| `routes/payroll/benefits.php` | `/api/v1/benefits` | C |

Pre-existing endpoints (unchanged paths and shapes): `GET|POST groups`, `GET|POST components`, `GET employees`, `GET|POST compensations`, `POST compensations/{uuid}/decision`, `POST simulate`, `GET readiness` (static BLOCKED placeholder until D).

## 8. Conventions for workstreams

* Controllers thin, in `App\Http\Controllers\Payroll\<Area>`; business rules in `Domain/Payroll/<Area>`. Lists paginated (<= 100), whitelisted sort keys, user-facing validation sentences.
* Mutations in `DB::transaction`; lock the run / employee row; client-visible creates take an `Idempotency-Key` (`idempotency_key` + `request_hash` columns exist where retries matter).
* Sensitive reads call `Audit::record('...viewed', 'payroll', ...)`; never put amounts in notifications, events, logs or error messages.
* Heavy work (calculation, imports, exports, payslip generation) = queued, idempotent, tenant-carrying jobs in `TenantRunner`; chunk employees; retry per employee.
* Tests: `tests/Concerns/PayrollFixtures.php` (`payrollGroup`, `payrollPeriod`, `payrollRun`, `runEmployee`, `sampleComponents`) with `PeopleFixtures`; remember to call `$this->context($tenantId)` after HTTP requests.
