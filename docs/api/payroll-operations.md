# Payroll operations (Phase 5, workstream D)

Base: `/api/v1/payroll` (module `payroll`, session auth). Code: `backend/app/Domain/Payroll/{Readiness,Operations,Explainer,Simulation,Reports,Security,Quality}`, controller `Http/Controllers/Payroll/Operations/OperationsController`, routes `routes/payroll/operations.php`, migration `2026_10_15_d00001_create_payroll_operations`.

General rules: tenant isolation everywhere (other tenants get 404); data scope applies per permission; amounts are decimal strings (never floats) at the currency exponent; results are read from stored immutable results only; nothing here mutates payroll (the only writes are simulation records, export records and the append-only sensitive-access log).

## Purpose-based access

Endpoints that reveal figures (explainer, differences, simulations of compensation, exports, Employee 360 compensation) need a **purpose** when settings `privacy.reveal_requires_purpose` is on (default). Send query/body fields `purpose_code` (one of `payroll_processing, compensation_review, approval, audit, employee_query, investigation, other`) and, for `other`, `purpose_note` (>= 10 chars). Missing purpose -> `422` with `errors.purpose_code`. `GET sensitive-access/purposes` lists them. Every reveal/export/download is written to `payroll_sensitive_access_logs` and the audit log (never with amounts).

## Readiness (replaces the static placeholder)

`GET readiness` - permission `payroll.configure|payroll.compensation.view|payroll.view|payroll.run|payroll.results.view`.
* No params: tenant overview `{state: ready|review_required|blocked|processing, status: "READY"|..., rule, groups: [{group, period, state, employees_expected, employees_ready, percent_ready, blocking, warning}], issues: [{code, message, severity, group, period}]}`. No active group -> `blocked` + issue `no_payroll_groups`.
* `?group={uuid}[&period={uuid}]`: one group. Without `period` the period containing today (else latest open) is used. Shape: `{group, period, run, state, status, rule, employees_expected, employees_ready, percent_ready, completeness{conditions_resolved, conditions_total}, counts{blocking, warning, critical, information}, skipped_modules[], checks[]}`.
* Check: `{key, label, severity: information|warning|blocking|critical, state: open|resolved, count, unit, affected_employees, sample[{employee_uuid, employee_number, name, count}], why, action{label, kind:"navigate", href}}`.
* Keys: `payroll_period, attendance_processed, attendance_finalized, attendance_locked, attendance_exceptions, leave_finalized, leave_locked, leave_batch, compensation_missing, compensation_incomplete, currency_conflict, compensation_pending, bank_missing, pack_attached|pack_enabled|pack_version|pack_verified|statutory_identifiers, inputs_pending, adjustments_pending, payroll_exceptions_blocking, payroll_exceptions_critical, cutoff_risk`.
* State rule (no score): BLOCKED if any open blocking/critical check; else PROCESSING if the period's run is calculating/validating; else REVIEW_REQUIRED if any open warning or a source module is disabled (`skipped_modules`); else READY.

`GET readiness/map?by=payroll_group|legal_entity|location|department` -> `{by, rule, data:[{id, name, state, employees, ready_employees, percent, blockers:[{key, count|label}]}]}`; percent = employees without a blocking item / expected employees.

## Command center

`GET command-center` (same permission set as readiness) -> `{as_of, readiness (overview), processing{active_runs[{uuid, group, period, run_type, status, stage, employees_total, employees_failed, control_totals}], by_status, failed_calculations}, blocking_errors{count, action}, warnings{count, action}, pending_approvals{payroll{subject_type: n}, compensation, action}, variance_alerts, payments{status: n}, locks{locked, reopen_requested}, off_cycle_runs[], money_visible}`. `control_totals` = `{currency, employee_count, failed_count, gross_total, deduction_total, employer_cost, net_total, payment_total|null, reconciliation{payment_batches, net_equals_payment, difference}}`; it is `null` and `money_visible=false` without `payroll.results.view`. Employer cost = gross + reimbursements + employer contributions + benefits.

## Explainer and differences

* `GET explain/employees/{employeeUuid}[?run={uuid}]` - `payroll.explain`, purpose. Built from the stored current result: `{employee, run, result{version,status,currency,checksum,engine_version}, totals, equation, reconciles, reproducible, answers{why_gross[], why_net, deductions[], attendance_leave_inputs}, payable_days, working_days, components[{code,name,kind,direction,amount,quantity,formula,formula_version,component_version,origin,source{ref,input},trace}], inputs, trace, rules{country_pack_version_id, compensation_id, structure_id, formulas[]}, warnings, errors}`. `reconciles` = components add up to the stored net; `reproducible` = ResultStore checksum verification.
* `GET difference/employees/{employeeUuid}[?period=&against_period=]` - `payroll.explain`, purpose. Employee vs previous period: `{current_period, previous_period, has_previous, currency, current_net, previous_net, difference, drivers[{driver, effect}], components[{code,name,kind,previous,current,effect_on_net,driver}], reconciles}`. Drivers: Salary Revision, Bonus, Overtime, LOP, Tax Change, Arrear, Loan Recovery, New Joiner, Other (derived from stored component origin / input provenance); effects sum to the net difference.
* `GET difference/runs/{runUuid}[?against={runUuid}&mode=run|recalculation&limit=25]` - `payroll.results.view`, purpose. `mode=run` (default): vs `against` or the previous run of the group (current vs previous, draft vs approved by passing `against`); `mode=recalculation`: each employee's current version vs the version it superseded. Returns totals, `drivers`, `new_joiners`, `leavers`, `top_movers[]` (per employee difference + drivers), `reconciles`, `truncated` (cap 5000 employees).

## Simulations (what-if, read-only)

* `POST simulate` - `payroll.simulate` - unchanged formula sandbox `{expression, variables, scale}`.
* `POST simulations` - `payroll.simulate` - `{kind: salary_revision|bonus|lop, employees: [uuid] (<=100), params: {percent}|{amount}|{days}, title?}` -> `201 {uuid, kind, engine: "employee_calculator"|"estimate", limitations[], mutates_live_payroll:false, masked, currency, employees[{employee_uuid, name, baseline{gross,net}, scenario{gross,net}, difference{gross,net}, net_change_percent, negative_net, note}], skipped[], employee_count, employees_with_issues, totals{baseline_net, scenario_net, difference_net, difference_gross}}`. Uses `Contracts\EmployeeCalculator` when bound (workstream B) with `overrides`, otherwise an estimate from the stored components (statutory amounts not re-evaluated, stated in `limitations`). Without `payroll.compensation.reveal` all amounts are `null` and `masked=true`.
* `GET simulations`, `GET simulations/{uuid}` - own simulations (result stored encrypted).

## Reports and exports

* `GET reports` -> catalogue (`register`, `variance`). `GET reports/{report}?run={uuid}[&against=&page=&per_page<=100]` - `payroll.reports.view` -> `{report, data[], meta{page,per_page,total}, amounts_masked}`. Amount columns are `"***"` without `payroll.results.view`.
* `POST reports/{report}/export` - `payroll.export` - `{format: csv|xlsx, run, against?, purpose_code...}` -> `202` export record. The queued, idempotent job runs as the requesting user (scope and masking identical to the screen). `GET exports` (own), `GET exports/{uuid}/download` (owner only; `410` when expired; retention `exports.retention_days`). Export row: `{uuid, report, format, status: queued|running|ready|failed, row_count, masked, expires_at, error, download}`. Exports and downloads are audited and logged.

## Security

* `GET sensitive-access` - `payroll.configure` - paginated log `{actor, employee_uuid, data_type, action, purpose_code, purpose_note, revealed, surface, record_ref, created_at}` (no amounts).
* Action Center (`domain: payroll`): readiness-derived insights (`payroll.<check>.<group>`), `payroll.failed_calculation`, `payroll.payment_failure`, `payroll.large_variance`; each has `title, count, description (why), recommendation, actions[], sample[]`.
* Data quality rules (category `payroll`, hr_confidential): `payroll_missing_group, payroll_overlapping_compensation, payroll_currency_conflict, payroll_missing_component, payroll_formula_error, payroll_invalid_bank, payroll_duplicate_input, payroll_orphan_adjustment`.
* Employee 360 sections (`payroll`): `compensation` (masked unless `payroll.compensation.reveal` + purpose; `masked`, `purpose_required` flags), `compensation_history`, `payroll_status`, `payslips`, `benefits_loans` (no figures), each gated by its own permission and data scope.
