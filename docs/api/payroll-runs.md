# Payroll runs API (Phase 5, workstream B)

Base `/api/v1/payroll` (module `payroll`, session auth). Code: `backend/app/Domain/Payroll/{Inputs,Engine,Runs,Locks,Validation}`, controllers `Http/Controllers/Payroll/Runs`, routes `routes/payroll/runs.php`.
All money is a decimal STRING (4 dp in results). Tenant isolation is global; data scope applies to employees on every list/read (out-of-scope = 404). Amount-bearing reads are audited and need `payroll.results.view`.
Errors: 422 validation sentence, 403 maker-checker/permission, 404, 409 state conflict, **423 run/period locked** (`run_locked`).

## Lifecycle
Run `status`: `draft -> readiness -> inputs -> validating -> calculating -> review -> approval -> approved -> locked -> outputs -> payment -> closed`, plus `reopened`, `cancelled`. Create leaves the run in `inputs` (stage 2). `calculate` is queued (202), chunked (50 employees), idempotent; poll `progress` until `status = review`. Period state follows the run (processing, validation, approval, approved, locked, reopened).

## Endpoints
| Method path | Permission | Notes |
| --- | --- | --- |
| GET `runs?status&period_uuid&sort&dir&per_page` | `payroll.view` | paginated list |
| POST `runs/preview` | `payroll.run` | `{period_uuid, run_type?, employee_uuids?}` -> counts (employees, joiners, leavers, transfers, out_of_scope, skipped) |
| POST `runs` | `payroll.run` | header `Idempotency-Key` (uuid) required. Body `{period_uuid, run_type: regular|off_cycle|correction, off_cycle_reason?, parent_run_uuid? (correction), employee_uuids? (required for off_cycle/correction), notes?}`. 201 new / 200 replay / 409 (second regular run for the period, key reuse with other body) |
| GET `runs/{uuid}` | `payroll.view` | run, `readiness`, `validation` summary, `progress`, `can{}`; `control_totals` only with `payroll.results.view` |
| GET `runs/{uuid}/employees` | `payroll.view` | roster with `status` (pending, calculated, warning, failed, excluded, held), `snapshot` (pay_from, pay_to, joiner, leaver, transfer_in/out); `result` summary only with `payroll.results.view` |
| PATCH `runs/{uuid}/employees/{employeeUuid}` | `payroll.run` | `{action: excluded|held|include, reason}` (reason required for excluded/held) |
| POST `runs/{uuid}/collect-inputs` | `payroll.run` | pulls attendance + leave through the input adapters -> `{created, updated, skipped, complete, warnings[]}` |
| POST `runs/{uuid}/calculate` | `payroll.run` | 202; 423 if approved/locked; also used to recalculate |
| GET `runs/{uuid}/progress` | `payroll.view` | `{status, pass, total, done, failed, finished_at}` |
| POST `runs/{uuid}/validate` | `payroll.run` | refresh control totals + findings |
| GET `runs/{uuid}/validations?severity&status` | `payroll.view` | `{summary{open,blocking,critical,warnings}, data[{uuid, code, severity, message, status, employee_uuid, details, action}]}` |
| POST `validations/{issueUuid}/waive` | `payroll.approve` | `{note}`; critical findings cannot be waived |
| GET `runs/{uuid}/totals` | `payroll.results.view` | `{stored, live}`: employees, gross, total_deductions, total_taxes, reimbursements, employer_contributions, net, payment_total, employer_cost, `reconciled` |
| GET `runs/{uuid}/results/{employeeUuid}` | `payroll.results.view` | `current` (totals + `components[]` with formula/version/origin/source_ref/trace, `trace`) and `versions[]` |
| POST `runs/{uuid}/results/{employeeUuid}/verify` | `payroll.results.view` | re-calculation proof `{reproducible, intact, checksum, recomputed}` |
| POST `runs/{uuid}/submit` | `payroll.run` | review -> approval; 422 while blocking/critical findings are open |
| POST `runs/{uuid}/approve` / `reject` | `payroll.approve` | maker-checker: not the run creator, not the submitter (setting `approvals.run_checker`); reject needs `comment` |
| POST `runs/{uuid}/lock` | `payroll.lock` | approved -> locked; verifies result checksums; marks inputs consumed, adjustments applied |
| POST `runs/{uuid}/reopen-request` | `payroll.unlock` | `{reason}` (>= 10 chars) |
| POST `runs/{uuid}/reopen-decision` | `payroll.unlock.approve` | `{decision: approved|rejected, comment?}`; requester cannot decide; approval -> `reopened`; later changes are logged in `payroll_locks.post_lock_changes` |
| POST `runs/{uuid}/cancel` | `payroll.run` | `{reason}`; only before calculation passes review |
| GET `inputs?status&source&period_uuid` | `payroll.inputs.manage\|approve` | |
| POST `inputs` | `payroll.inputs.manage` | `{employee_uuid, period_uuid, source: variable_pay|bonus|commission|reimbursement|overtime|adjustment|manual|api, kind, component_code?, amount?, quantity?, source_ref?}`; duplicates are idempotent; sources in `inputs.require_approval_for` start `pending`; 423 if the period run is approved/locked |
| POST `inputs/imports/preview` | `payroll.inputs.manage` | multipart `{file (.csv/.xlsx, <=10MB), period_uuid, source, mapping?}`; mapping is an optional `mapping[field]=header` array for `employee_number`, `kind`, `component_code`, `amount`, or `quantity`; default headers are auto-detected. Header matches ignore case and normalize spaces to underscores. Employee number, kind, and amount or quantity are required; 10,000 row maximum. Original source rows remain encrypted and preview creates no payroll inputs |
| POST `inputs/imports/{uuid}/commit` | `payroll.inputs.manage` | Explicitly commits valid rows, preserving input source approval policy and linking each input to its import and source row; repeat commit is idempotent |
| POST `inputs/imports/{uuid}/retry` | `payroll.inputs.manage` | Retries failed rows from a committed import using stable source references |
| GET `inputs/imports` / `inputs/imports/{uuid}` | `payroll.inputs.manage` | Recent import history and row outcomes; encrypted raw source values are never returned |
| POST `inputs/{uuid}/decision` | `payroll.inputs.approve` | `{decision, reason?}`; creator cannot approve |
| DELETE `inputs/{uuid}` | `payroll.inputs.manage` | void; 423 once consumed by a locked run |
| GET/POST `adjustments`, POST `adjustments/{uuid}/decision`, POST `adjustments/{uuid}/reverse` | `payroll.adjust` / `payroll.adjust.approve` | Create needs `Idempotency-Key`; `{employee_uuid, kind: arrear|retro|correction|manual_net|recovery, settlement_period_uuid, origin_period_uuid?, component_code?, amount (signed: + pays, - recovers), reason, trace?}`. A reversal needs `Idempotency-Key` and `{settlement_period_uuid, reason}`; it creates an independently approved opposite signed adjustment linked by `reverses_id`, then marks the original `reversed`. Approved adjustments are picked up by the run of the settlement period |

## Behaviour
* Roster: employees whose `payroll_group` reference (Phase 3 `employee_policy_references`) covers the period and who are employed in it; each gets a pay window (joiner, leaver, transfer).
* Payable days: attendance `payable_days` (capped at employment days) wins; otherwise employment days minus `leave_lop`/`leave_unpaid`. Components prorate by `none | calendar_days | working_days | payable_days`; mid-period compensation revisions prorate per segment. Overtime = hourly rate x approved hours x multiplier. Everything is traced.
* Results only through `ResultStore` (versioned, checksum, immutable; identical recalculation = same version). Correction runs store only the difference to the parent run's result (parent untouched).
* Statutory lines come from the `StatutoryPack` of the published pack version resolved by `PackResolver` (A); without a published pack no statutory lines are produced.
* Validation codes: `unapproved_input`, `input_not_final`, `attendance_missing`, `missing_result`, `no_compensation` (blocking), `calculation_error` (critical), `negative_net`, `zero_pay`, `variance_high`, `overtime_without_rate`, `totals_not_reconciled` (critical).

## Calculator request (pure `EmployeeCalculator::calculate`)
`{run_employee_id, employee_id, group_id, period_start, period_end, currency, run_type, engine_version, rounding{mode}, days_basis?, employment{start,end}, compensation, segments[{from,to,compensation}], inputs[{id,source,kind,component_code,quantity,amount,source_ref,meta}], adjustments[{id,kind,component_code,amount,trace}], loan_installments[{id,amount}], benefit_enrollments[{enrollment_uuid,plan_code,plan_name,component_code,employee_contribution,employer_contribution,effective_from,effective_to}], statutory{handler,country,rules,validation_rules,pack_version_id,pack_version,tax_year,periods_in_year,period_index,ytd,employee,declaration}|null, overtime{multiplier,component_code}, overrides{lop_days?,bonus?}}`. Fixed benefit contributions are prorated by covered calendar days in the pay period and emitted as employee/employer contribution components with enrollment provenance. Compensation lines: `{component_id, component_code, component_version, kind, name, amount, formula, sequence, meta{proration, attendance_dependent, lop_dependent, min_amount, max_amount, rounding_*, tax_classification, statutory_mapping, payslip_visible, gl_code}}`. The full request is stored in the result's encrypted `input_snapshot`, which is what makes `verify` possible.
