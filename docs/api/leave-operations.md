# Leave operations (Phase 4, workstream D)

Locks lifecycle, payroll contract, transfers/separation/rehire, Explainer, Policy Sandbox / Entitlement Simulator / Impact Preview / Forecast / Policy Diff,
Confidence, Absence Command Center, Employee Time Story, reports + queued exports, bulk-actions registry, data quality, Action Center and Employee 360.

Base path: `/api/v1/leave` (module `leave`, session auth). Every endpoint is tenant scoped, permission gated and (for employee data) limited by the viewer's DataScope;
an employee outside the viewer's reach is `404`. Units are 4-decimal STRINGS (`"1.5000"`). There is **no money anywhere**.
Validation errors are Laravel `422 {message, errors}`. Locked periods answer `423`.

Code: `app/Domain/Leave/{LockLifecycle,Payroll,Transfers,Explainer,Simulation,Reports,Operations}`, controllers `app/Http/Controllers/Leave/Operations`, routes `routes/leave/operations.php`.
Schema: foundation tables + `leave_exports` (migration `2026_10_13_D00001_create_leave_exports`).

## 0. Tenant settings

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/settings` | `leave.manage` or `leave.policy.manage` |
| PUT | `/settings` `{<partial settings object>}` | `leave.manage` or `leave.policy.manage` |

The response is `{data: <effective settings>}` with defaults merged into tenant overrides. PUT accepts partial nested sections and preserves omitted keys and other tenant settings. Unknown keys and values outside supported ranges return `422`. The Leave Operations Settings page uses the same endpoints.

Balance settings include `balance.carry_negative_forward` (default `true`): period close transfers a negative available balance as a signed `carried` ledger entry into the next account. Set it to `false` to waive the debt at close. Positive carry remains controlled by each leave policy's `carry_forward` rule. `requests.pending_expiry_days` defaults to 90; setting it to `null` disables automatic expiry. The hourly request-maintenance job releases pending reservations and records/announces the expiry.

## 1. Locks (maker-checker unlock)

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/locks` | `leave.lock` or `leave.unlock` or `leave.manage` |
| POST | `/locks/preview` | `leave.lock` |
| POST | `/locks` | `leave.lock` |
| POST | `/locks/{uuid}/unlock-request` | `leave.lock` or `leave.unlock` |
| POST | `/locks/{uuid}/unlock-approve` | `leave.unlock` and a DIFFERENT user than the requester |
| POST | `/locks/{uuid}/unlock-reject` | `leave.unlock` |

Scope vocabulary = attendance: `scope_type` in `tenant | legal_entity | payroll_group | location | employee_group | employee`, `scope_id` (`*` for tenant; employee scope accepts the employee id or uuid), `start_date`, `end_date` (must be in the past), `reason`.

* `POST /locks/preview` -> `{scope:{scope_type,scope_id,start_date,end_date,label}, employees, employee_days, unresolved:{pending_requests,pending_adjustments}, unresolved_total, requires_acknowledgement, conflicts[], can_lock, message}`.
* `POST /locks` body adds `acknowledge_unresolved` (required `true` when `requires_acknowledgement`) and `coordinate_attendance` (also creates the matching attendance lock; needs `attendance.lock`). `201 {data: lock}`. `422` for same-scope overlap or a redundant narrower lock.
* Lock: `{uuid, scope_type, scope_id, scope_label, start_date, end_date, status: locked|unlocked, reason, locked_at, unlock_requested: bool, unlock_reason, unlocked_at, attendance_lock_uuid}`.
* Unlock: requester posts `{reason}` (`422` if blank or already requested); approver posts nothing (`422` when approver == requester); reject takes optional `{comment}` and clears the request.
* A locked date makes every ledger post for covered employees fail with `423` (LedgerService + `DatabaseLeaveLockGuard`). Events: `leave.lock.changed`. Audit: `locked/unlock_requested/unlock_rejected/unlocked`.

## 2. Payroll contract (for Phase 5)

References, unit totals and cutoff metadata only. `money` is always `null`. Pay classes: `paid | unpaid | statutory | lop | comp_off | encashment`.

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/payroll/cutoff?from&to` | `leave.manage` or `leave.reports.view` |
| GET | `/payroll/contract?from&to` | same |
| POST | `/payroll/contract/batches` `{from,to,batch_key?}` | `leave.manage` |
| GET | `/payroll/contract/batches/{key}` | `leave.manage` or `leave.reports.view` |

* Cutoff: `{configured, cutoff_day, cutoff_date, period_start, period_end, status: not_configured|upcoming|today|passed, source: leave_settings|attendance_settings}`. `cutoff_day` = `LeaveSettings integration.cutoff_day`, else the attendance payroll cutoff day; the cutoff date is that day of the month FOLLOWING the period end.
* Contract (live): `{period, cutoff, lines[], totals{pay_class: units}, employees, warnings[{key,count,message}], money:null}`. Line: `{employee_id, employee_uuid, employee_number, period_start, period_end, pay_class, leave_type_id, leave_type_code, units, request_id, segment_id:null, late_adjustment, cutoff_date, meta}`.
  Leave lines come from ACTIVE, charged segments of APPROVED requests (one line per request and pay class; `meta.request_uuid`, `meta.dates`, `meta.decided_on`). `late_adjustment=true` when the request was decided after the cutoff date. Encashment lines come from approved/posted settlement references (`meta.settlement_reference`, `meta.payroll_reference`).
  Warnings: `pending_requests`, `late_adjustments`, `period_not_locked`, `cutoff_not_configured`.
* Publish stores an immutable snapshot in `leave_payroll_lines` under `batch_key` (re-publishing the same key replaces that snapshot). `201 {batch_key, ...contract}`. Read a batch: `{batch_key, lines[], totals, money:null}`.
* PHP service for Phase 5: `App\Domain\Leave\Payroll\PayrollContract::{cutoff, preview, publish, batch}`.

## 3. Transfers, separation, rehire

Handles country/entity/location/employee-type changes, separation and rehire. Rules are DATA in the leave type rule document (policy rules win) or `leave_types.config`:
`transfer {mode: preserve|transfer|convert|expire}`, `encashment {on_separation, max_units}`, `separation {mode: expire|preserve}`, `rehire {restore: none|all|capped, cap}`.
Type conversion uses `leave_type_mappings` (ratio). Nothing is posted until a stored preview is applied; applying posts through `LedgerService` only (idempotent keys `transfer:{uuid}:{account}:{step}`).

The People domain events `EmployeeTransferred` (policy-relevant fields only), `EmployeeSeparated` and `EmployeeRehired` automatically create a PREVIEW and notify `leave.manage` users. Failures never break HR transactions.

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/transfers?status&trigger` | `leave.manage` |
| POST | `/transfers/preview` `{employee, trigger, effective_date, balance_mode?, previous_employee?}` | `leave.manage` |
| GET | `/transfers/{uuid}` | `leave.manage` |
| POST | `/transfers/{uuid}/apply` `{balance_mode?}` | `leave.manage` |
| POST | `/transfers/{uuid}/cancel` | `leave.manage` |
| GET / POST / DELETE | `/type-mappings`, `/type-mappings/{id}` `{from, to, ratio?, purpose?}` (codes) | `leave.manage` read; `leave.policy.manage` write |

`trigger`: `transfer | separation | rehire | policy_change` (`rehire` needs `previous_employee`). `balance_mode`: `preserve | transfer | convert | expire` (omit to follow the rules; separation default settles per rules: encash eligible units, expire the rest).
Plan (`data.plan`): `{organization:{before,after} (Organization Memory), trigger, effective_date, balance_mode, requested_mode, from_policy, to_policy, policy_changed, items[], future_leave[], warnings[], can_apply}`.
Item: `{account_uuid, leave_type_code, available, negative, statutory, actions[{kind: expire|encashment|move|restore, units, ...}], encashment?, restore?, final_accrual?, blocked, note}`.
Warnings: `negative_balance` (reported only; nothing is recovered), `unmapped_leave_type`, `future_leave`, `policy_resolver_unavailable`. Applying with future leave records the event `leave.transfer.future_leave_review` (request uuids) for workstream C's cancellation hooks.
Separation encashment creates a `leave_settlement_references` row (units only, status `approved`) and the `encashment` ledger entry.

## 4. Explainer, simulations, forecast, confidence, story

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/explain/balance?employee&leave_type&as_of&units` | `leave.view` (self) or team/manage/reports scope |
| GET | `/explain/requests/{uuid}` | same |
| POST | `/simulations/sandbox` | `leave.simulate` |
| POST | `/simulations/entitlement` | `leave.simulate` |
| POST | `/simulations/impact` | `leave.simulate` or `leave.request` (own) |
| POST | `/simulations/policy-diff` `{from,to}` (policy version ids) | `leave.simulate` or `leave.policy.manage` |
| GET | `/simulations`, `/simulations/{uuid}` | `leave.simulate` |
| GET | `/forecast?employee&leave_type&months` | `leave.view` (self) or scope |
| GET | `/confidence?employee` | same |
| GET | `/time-story?employee&from&to` | same |
| GET | `/command-center` | `leave.reports.view`, `leave.manage` or `leave.team.view` |

`employee` is an employee uuid (omit for your own). Self access needs `leave.view`.

* **Explain balance** -> `{employee_uuid, as_of, accounts[{account_uuid, leave_type, period, status, negative_limit, balance{opening,accrued,carried,adjusted,consumed,pending,scheduled,expired,encashed,entitled,available}, buckets{bucket:{units,entries[]}}, ledger[{... , sentence}], lots[], integrity{exact,database,consistent}, policy, entitlement, check?, summary}], engine_available, policy_resolver_available}`. `units=` adds `check{allowed, would_remain, sentence}`. Sensitive leave types are omitted for non-self viewers without `leave.medical.view`.
* **Explain request** -> `{request_uuid, leave_type, status, dates, requested_units, charged_units, segments_total, matches, segments[{date, units, charged, why, explanation}], ledger[], timeline[], sentence}`. A sensitive request is `404` for others without `leave.medical.view`.
* **Simulations are read-only**: the only write is one `leave_simulation_runs` row; every response carries `simulation_uuid` and `read_only:true`.
  * Sandbox body `{leave_type_id, rules | policy_version_id, employees[uuid<=200], period_start, period_end}` -> `{evaluator:'rule_document', live_engine_available, totals, rows[{employee_uuid, draft_entitlement, draft_lines, current_entitlement, difference, balance_at_period_end, carry_forward, lapsed}]}`. The draft is evaluated with the documented rule conventions (`entitlement`, `carry_forward`, `limits.max_balance`) and compared with the live `EntitlementEngine` when bound.
  * Entitlement `{employee, leave_type_id, period_start, period_end, accrual_from?, accrual_to?}` -> real engine output (`source:'entitlement_engine'`), else the rule document (`source:'rule_document'`), else a note that nothing can be simulated.
  * Impact `{employee?, leave_type_id, start_date, end_date, portion?, units?}` -> `{duration (DurationCalculator when bound), projected{available_on_start, charge, balance_after, sufficient}, team_overlap{count, people[] only for team.view/manage}, locked, verdict: ok|insufficient_balance|blocked_by_lock|unknown_charge}`.
  * Policy diff -> `{from, to, changes[{scope, path, change: added|removed|changed, from, to}], identical}`.
* **Forecast** -> per open account `points[{date, ledger_available, projected_accrual, projected_available}]` for each month end plus `expiring_lots[]`; accrual uses the engine when bound (else 0).
* **Confidence** -> `{level: confirmed|pending|review_required, label, meaning, reasons[{key, level, message, count?}]}` (ledger mismatch, below limit, stale/pending requests, pending adjustments, flagged requests). Deterministic, no score.
* **Time story** -> `{events[{date, kind: leave|decision|ledger|comp_off|lifecycle|lock, title, detail, ref, source}], total, truncated}`; no reasons; sensitive leave appears as "Leave".
* **Command center** -> `{as_of, employees_in_scope, away_today{count, by_type, people[], next_7_days[]}, approvals{pending, overdue, escalate_after_hours}, balances{negative_accounts, expiring_lots, expiring_units}, payroll{previous_period, cutoff, period_locked, unlock_requests_waiting}, confidence{...}, operations?}` (operations only for `leave.manage`/`leave.accrual.run`).

## 5. Reports and exports

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/reports` | `leave.reports.view` or `leave.manage` |
| GET | `/reports/{report}?page&per_page&sort&dir&<filters>` | same |
| POST | `/reports/{report}/export` `{format: csv|xlsx, <filters>}` | `leave.export` (and reports access) |
| GET | `/exports` | `leave.export` (own exports) |
| GET | `/exports/{uuid}/download` | `leave.export` (owner only; `410` once expired) |

Reports: `balances, ledger, requests, utilization, adjustments, expiring, comp_off, encashment, payroll_lines`. Filters: `q, employee, employee_number, department_id, location_id, legal_entity_id, manager, leave_type (code), status, from, to, pay_class, batch_key`.
Response `{data[], meta{report,title,page,per_page,total,last_page,sort,dir}}`; every row starts with `employee_uuid, employee_number, employee_name`. Balance columns come from the ledger (same numbers as My Leave). Reasons/documents are never reported; sensitive types show `Restricted` without `leave.medical.view`.
Export is queued (`202`): `{uuid, report, format, status: queued|processing|completed|failed|expired, row_count, expires_at, error, download}`. It is generated AS THE REQUESTING USER (scope and masking apply), is audited on create and download, formulas are neutralised in cells, and files are purged after 3 days (`leave:purge-exports`, hourly).

## 6. Bulk-actions registry

`App\Domain\Leave\Operations\Bulk\LeaveBulkActions` (singleton). Any workstream registers a `LeaveBulkAction` from its own Boot (`app(LeaveBulkActions::class)->register(new X)`).

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/bulk-actions` | `leave.manage` (lists the actions the user may run) |
| POST | `/bulk-actions/{key}/preview` `{employees[uuid<=500], params}` | `leave.manage` + the action's permission |
| POST | `/bulk-actions/{key}/run` | same |

Employees outside the viewer's data scope are silently dropped (`404` if none remain). Result rows: `{employee_uuid, employee_number, name, status: ok|warning|error|skip, reason?, detail?}`. Built-in: `prepare_handling` `{trigger: transfer|separation|policy_change, effective_date, balance_mode?}` (creates stored previews, applies nothing).

## 7. Data quality, Action Center, Employee 360

* QualityRule keys (category `leave`): `leave_ledger_mismatch`, `leave_negative_beyond_limit`, `leave_no_policy` (needs the policy resolver), `leave_stale_pending_request`, `leave_expired_lot_open`, `leave_separated_with_balance`, `leave_unlock_waiting`. Read-only, appear in the existing People data-quality screens.
* Action Center provider `leave` (domain `leave`): `requests_overdue`, `requests_pending`, `balance_below_limit`, `expiring_soon`, `unlock_waiting`, `payroll_cutoff_risk` (critical), `accrual_failures`; each has navigate actions (`/leave/approvals`, `/leave/reports/balances`, `/leave/reports/expiring`, `/leave/locks`, `/leave/accruals`).
* Employee 360 provider `leave`: sections `leave_balances`, `leave_upcoming`, `leave_recent`, `leave_confidence` (facts + source, no reasons).

## 8. Degradation when other workstreams have not landed

`LeavePolicyResolver`, `EntitlementEngine` and `DurationCalculator` are resolved lazily. Missing -> explicit flags in responses (`engine_available:false`, `policy_resolver_available:false`, `duration_engine_available:false`) and conservative behaviour (transfers default to preserve, sandbox/entitlement fall back to the rule document, forecast accrual 0, `leave_no_policy` yields nothing).
