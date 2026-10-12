# Leave ledger operations (Phase 4, workstream B)

Accounts, entitlement/accrual engine, accrual runs, carry-forward, expiry, balance and ledger reads, adjustments (maker-checker), bulk grants/debits and imports.
Base path `/api/v1/leave` (module `leave`). Code: `backend/app/Domain/Leave/{Accrual,CarryForward,Expiry,Adjustments,Imports}`, controllers `App\Http\Controllers\Leave\Ledger`, routes `routes/leave/ledger.php`.
All ids in URLs and bodies are UUIDs. Units are 4-decimal strings (`"12.5000"`). Cross-tenant or out-of-scope records are `404`. Validation errors are `422`; ledger refusals carry a message (`insufficient_balance` 422, `idempotency_conflict` 409, period locked 423).

## 1. Engine and policy data

`EntitlementEngine` (bound to `Accrual\EntitlementCalculator`) is pure. Rules come from `LeavePolicyResolver::rulesFor()` (type rule document), with `leave_accrual_rules` as fallback.

`rules.entitlement`: `basis` annual|monthly|weekly|daily|pay_period|anniversary|manual, `amount` per occurrence, `timing` start|end, `tenure_bands[{from_months,to_months,amount}]`, `start_after_days`, `waiting` defer|forfeit, `max_per_period`, `pay_period{length_days,anchor}`,
`proration{joiner,leaver: daily|full|none|cutoff, joiner_cutoff_day, leaver_cutoff_day, probation: accrue|defer|none, rounding: none|nearest|up|down + _half|_whole}`.
`rules.limits`: `max_balance`, `negative_limit`, `advance_leave` (borrow up to the period entitlement). `rules.cycle` (cycle code or `{kind,start_month,start_day,length_months}`; default calendar year; anniversary supported).
`rules.expiry{valid_days|valid_months|at_period_end}`, `rules.carry_forward{mode none|full|capped|percentage, cap, percent, rounding, valid_days|valid_months}`, `rules.consumption_order` earliest_expiring|current_first|carried_first|oldest_first.
Transfers: the policy is resolved as of each occurrence date; annual rules are split at the policy-change date and prorated by days.
Idempotency: credited occurrence dates are stored in ledger `meta.occurrences`; overlapping runs never double-credit.

`ConsumptionPlanner::allocate($account, $units, $leaveDate, $order)` (for workstream C) returns `allocations[]` to pass to `LedgerService`.

## 2. Balances and ledger reads

| Method and path | Permission | Notes |
| --- | --- | --- |
| `GET me/balances?as_of=&all=1&include=trace&leave_type=` | leave.view | own employee |
| `GET employees/{employee}/balances` (same query) | leave.view (self) / leave.team.view / leave.manage / leave.adjust / leave.reports.view within data scope | |
| `GET team/balances?q=&leave_type=&as_of=&page=&per_page=` | leave.team.view / leave.manage / leave.reports.view | paginated by employee: `data[{employee,balances[]}]`, `meta` |
| `GET accounts/{account}` | as above | balance + trace (entry uuids per bucket) |
| `GET accounts/{account}/ledger?as_of=` | as above | statement with running balance, `explain` text, links to run/request/adjustment/import, `integrity` check |
| `GET ledger/entries/{entry}` | as above | full calculation (`calculation` = ledger meta), lots drawn from / consumed by |
| `POST accounts` `{employee, leave_type, date?}` | leave.manage | opens (or returns) the account for the period |
| `POST entitlements/calculate` `{employee, leave_type, mode: entitlement|accrual, from, to}` | leave.accrual.run / leave.manage | read-only traceable calculation: `units`, `lines[{label,units,detail}]`, `periods[]` |
| `GET me/expiries`, `GET expiries` | leave.view / leave.manage, team.view, accrual.run | upcoming expiring lots |

Balance item: `{account{id,status,period_start,period_end,negative_limit,max_balance}, leave_type{id,code,name,kind,unit,color,is_statutory,is_sensitive}, balance{opening,accrued,carried,adjusted,consumed,pending,scheduled,expired,encashed,entitled,available,as_of}, expiring[{entry_id,units,expires_on}], advance?{borrowed,expected_future_accrual,shortfall,covered,negative_limit}, trace?}`.
Sensitive (medical) types are omitted for everyone except the employee and `leave.medical.view`.

## 3. Accrual runs (leave.accrual.run)

| Path | Body / result |
| --- | --- |
| `POST accrual-runs/preview` | `{from,to,leave_types?[],employees?[]}` -> `data` run (status `preview`), no ledger writes; items via `GET accrual-runs/{run}/items?status=` |
| `POST accrual-runs` | same body, `queue?: true`. 201 created+executed; 200 `{duplicate:true}` if that run key already completed; 202 when queued |
| `POST accrual-runs/{run}/execute` / `POST accrual-runs/{run}/retry` | execute a preview / reprocess failed items only |
| `GET accrual-runs`, `GET accrual-runs/{run}` | `{id,run_key,mode,status,window,counts{total,posted,skipped,failed},error,...}`; statuses preview queued running completed partial failed |
| `GET accrual-runs/{run}/errors[?format=csv]` | failed and skipped items with reasons |

Item: `{employee,leave_type,status preview|posted|skipped|failed,units,reason,calculation}`. Nightly: `php artisan leave:accrue-nightly [--tenant=] [--sync]` (scheduled 02:00) and `leave:process-expiry` (02:30: expiry, alerts, and carry-forward when `accrual.auto_carry_forward`).

## 4. Carry-forward and expiry (leave.accrual.run)

* `POST carry-forward/preview` / `POST carry-forward/run` `{as_of?,leave_types?,employees?}` -> `{run_key,status,items[{status,mode,employee,leave_type,eligible_units,carried_units,forfeited_units,valid_until,calculation}]}`. Item status `skipped` with `calculation.reason = open_reservations` retries on the next run. `GET carry-forward?run_key=`.
* `POST expiries/process` `{as_of?,dry_run?}` -> `{expired,units,already_used,cancelled,alerts?}`.
Alerts use `LeaveSettings expiry.notify_days_before` (in-app notification + `leave.balance.expiring` event, once per threshold).

## 5. Adjustments (maker-checker) and bulk

| Path | Permission |
| --- | --- |
| `GET adjustments?status=&bulk_key=` | leave.adjust / leave.adjust.approve (data scope) |
| `POST adjustments` `{employee,leave_type,units,kind: adjustment|grant|debit,effective_date?,expires_on?,reason}` | leave.adjust (creates `pending` + My Work task for approvers) |
| `POST adjustments/{adjustment}/approve` `{note?}` / `.../reject` | leave.adjust.approve; approver must differ from requester (403) unless `balance.maker_checker=false`; approval posts one `adjustment` entry (409 if already decided) |
| `POST adjustments/bulk/preview` `{employees[],leave_type,units,kind,reason,...}` | leave.adjust: `rows[{current_available,delta,projected_available,issues,will_apply}]`, `summary` |
| `POST adjustments/bulk` (+`idempotency_key`) | leave.adjust: pending adjustments sharing `bulk_key`; repeat key returns `duplicate:true` |
| `POST adjustments/bulk/{bulkKey}/approve` / `/reject` | leave.adjust.approve |

## 6. Imports (leave.import)

Same flow as attendance imports. Kinds `opening_balance` (columns employee_number|work_email, leave_type_code, units, as_of, expires_on, note) and `historical_requests` (employee_number|work_email, leave_type_code, start_date, end_date, units, reason, external_ref). CSV or XLSX.

`GET imports/template?kind=&format=` , `GET imports`, `POST imports` (multipart `file`,`kind`; returns suggested mapping + sample), `GET imports/{id}`, `PUT imports/{id}/mapping` `{mapping,options{date_format},validate?}`, `POST imports/{id}/validate` (preview), `GET imports/{id}/rows?status=`, `POST imports/{id}/commit` (202, queued), `POST imports/{id}/retry`, `GET imports/{id}/errors` (CSV).
Import `status`: uploaded mapped validating validated queued importing completed completed_with_errors failed. Row `status`: valid warning error duplicate imported.
Opening balance is one ledger credit keyed per employee/type/period: the same value again is a duplicate, a different value is an error (use an adjustment). Historical requests become approved requests whose days are posted as consumption entries.

## 7. Settings added (`LeaveSettings`)
`accrual.nightly_enabled` (true), `accrual.auto_carry_forward` (false), `balance.maker_checker` (true).
