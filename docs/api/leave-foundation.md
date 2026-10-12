# Leave foundation (Phase 4, P04-T01..T03)

Shared base every leave workstream builds on. Code: `backend/app/Domain/Leave/**`; schema:
`database/migrations/2026_10_12_000001_create_leave_domain.php`; permissions grant: `2026_10_12_000002_grant_leave_permissions.php`.

## 1. Non-negotiables

* **No stored balance.** Every balance is derived from `leave_ledger_entries` (`BalanceService`). There is no editable balance column anywhere.
* **Employee is not User.** Leave rows reference `employees.id`; users appear only as actors (`created_by`, `decided_by`).
* **No country law in code.** Country/entity/grade applicability, statutory flags, proration and caps are policy DATA (`leave_policy_versions.rules`, `leave_policy_type_rules.rules`, `leave_types.config`, assignments).
* **No new engines.** Approvals = Attendance `ApproverResolver` + My Work `TaskService`; events = `DomainEvents`; audit = `Audit::record`; locks reuse the attendance scope logic.
* **Tenancy.** Every table has `tenant_id`; models extend `TenantRecord`; aggregate roots expose `uuid` (`HasPublicId`). Cross-tenant access is 404.
* **History never rewritten.** Published policy versions are immutable; requests, segments and ledger rows store the `policy_version_id` used.

## 2. Tables (all `leave_` prefixed; models in `App\Domain\Leave\Models`)

| Group | Tables |
| --- | --- |
| Catalogue (A) | `leave_types`, `leave_policies`, `leave_policy_versions`, `leave_policy_type_rules`, `leave_policy_assignments`, `leave_cycles`, `leave_cycle_periods`, `leave_optional_holiday_pools`, `leave_type_mappings` |
| Ledger (B) | `leave_accounts`, **`leave_ledger_entries`** (append-only), `leave_ledger_allocations`, `leave_accrual_rules`, `leave_accrual_runs`, `leave_accrual_items`, `leave_carry_forwards`, `leave_expiries`, `leave_adjustments`, `leave_imports`, `leave_import_rows` |
| Requests (C) | `leave_requests`, `leave_request_segments`, `leave_request_actions`, `leave_request_documents`, `leave_conflicts`, `leave_restrictions`, `leave_document_rules`, `leave_comp_off_credits`, `leave_comp_off_claims`, `leave_return_to_work`, `leave_donations` |
| Operations (D) | `leave_locks`, `leave_simulation_runs`, `leave_transfers`, `leave_settlement_references`, `leave_payroll_lines` |

Spec names map as: accrual_rules/runs/items -> `leave_accrual_*`, carry_forwards -> `leave_carry_forwards`, expiries -> `leave_expiries`, comp_off_credits/claims -> `leave_comp_off_*`, restrictions -> `leave_restrictions`, document_rules -> `leave_document_rules`, locks -> `leave_locks`, imports/rows -> `leave_imports`/`leave_import_rows`, simulation_runs -> `leave_simulation_runs`.

Units are `decimal(14,4)`; they come back from Eloquent as strings (SQLite may return floats): always pass them through `Units::normalize()`. Money is never stored (encashment = units + reference only).

## 3. Ledger rules (`LedgerService`, the only writer)

* Entry = signed `units` (credit > 0, debit < 0, never 0, max 4 decimals), a `bucket`, an `entry_type`, `effective_date`, optional `expires_on` (credit lots), `policy_version_id`, `meta` (calculation trace).
* Buckets: `opening | accrued | carried | adjusted | consumed | pending | scheduled | expired | encashed`.
  Entry types: `opening accrual reservation reservation_release consumption settlement reversal adjustment carry_forward_out carry_forward_in expiry comp_off_credit encashment transfer_out transfer_in donation_out donation_in`.
* **Idempotent:** unique `(tenant_id, idempotency_key)`. Same key + same posting => original entry returned (`PostResult::$duplicate`); same key + different posting => 409 `idempotency_conflict`. Key convention: `<domain>:<natural id>[:<step>]`, e.g. `accrual:{run_key}:{employee}:{type}`, `req:{uuid}:reserve`.
* **Immutable:** model refuses update/delete. Corrections = `reverse()` (exact opposite, same bucket, `reverses_entry_id`, once per entry, a reversal cannot be reversed) or a new `adjustment`.
* **Lifecycle of a request:** `reserve()` (pending, -n, refuses overdraw) -> `commitReservation(.., 'scheduled'|'consumed')` (reverses the hold, posts the consumption) -> `settleScheduled()` when the date passes. Reject/withdraw = `reverse(reservation)`. Cancel after approval = `reverse(consumption)`.
* **Concurrency:** each post runs in a transaction holding `SELECT ... FOR UPDATE` on the `leave_accounts` row, recomputes `available` under the lock, then inserts. Unique indexes are the backstop (a lost race resolves to the winner's row / `already_reversed`). Never write ledger rows any other way and never check balance outside `post()`.
* **Overdraw:** `enforceAvailable` debit is refused when `available + units < -negative_limit` (`leave_accounts.negative_limit`, copied from the policy when the account is opened). Error `insufficient_balance` (422).
* **Locks:** `LeaveLockGuard::assertUnlocked(employee, effective_date)` runs before every post (423). `ignoreLock` is for system postings only (never user actions).
* **Closed accounts** refuse postings (409) unless `allowClosed` (expiry, carry-out, reversals during close).
* **Lots / traceability:** credits are lots. `allocations` on a debit record which lots it consumed (`leave_ledger_allocations`); `BalanceService::lots()` returns lots with `remaining`, earliest-expiring first (workstream B re-sorts for current-first / configurable ordering). Reversing a debit releases its allocations.
* **Dates:** grants (credits in opening/accrued/carried/adjusted) count in `balance()` only once `effective_date <= asOf`; debits and releases always count. `balance($acct, $date, strictDates: true)` is the forecast view (everything effective on or before the date). A reversal of a grant counts exactly when the grant counts.

### Balance (`BalanceService::balance()` -> `Balance`)

`entitled = opening + accrued + carried + adjusted`; `available = entitled - consumed - pending - scheduled - expired - encashed` (== sum of all counted signed entries). Debit buckets are shown as positive magnitudes. `trace[bucket]` lists the entry ids behind every number. Also: `forEmployee()`, `statement()` (ledger with running available), `lots()`, `verify()` (exact bcmath sum vs database SUM).

## 4. Contracts (`App\Domain\Leave\Contracts`)

| Contract | Owner | Notes |
| --- | --- | --- |
| `LeavePolicyResolver` | A | `resolve(employee, date)` and `rulesFor(employee, typeId, date)`; same precedence as attendance `PolicyResolver`; org data as of the date |
| `EntitlementEngine` | B | `entitlementFor()`, `accrualFor()` -> `{units, lines[explanation], skipped?}`; pure, never writes |
| `DurationCalculator` + `Dto\DurationQuery/DurationResult/DurationSegment` | C | request -> segments + explainable charge; side-effect free |
| `LeaveApproverResolver` | C | MUST delegate to `Attendance\Requests\ApproverResolver` (add kind `leave` => `leave.approve`) and `Work\TaskService`; no new approval engine |
| `LeaveLockGuard` | foundation (D extends) | bound to `DatabaseLeaveLockGuard`, reuses attendance scope matching |
| `Attendance\Contracts\LeaveDataProvider` (existing) | C | see below |

### Binding the real `LeaveDataProvider` (workstream C)

Attendance's `LeaveBoot` does `bindIf(LeaveDataProvider, NullLeaveDataProvider)`. C creates `App\Domain\Leave\Integration\LedgerLeaveDataProvider` and, in its OWN `app/Domain/Leave/Boot/<Name>Boot.php`, `register()`: `$app->bind(LeaveDataProvider::class, LedgerLeaveDataProvider::class)`. Boot hooks run in class-name order, so `App\Domain\Leave\Boot\*` runs after `App\Domain\Attendance\Boot\LeaveBoot` and wins. Until then the Null provider stays bound. The provider reads only active segments of approved requests (batch-efficient `coverageForRange`), returns minutes/portion/opaque reference/paid flag, never reasons or medical data. On approve/cancel, record `LeaveEvents::COVERAGE_CHANGED` (employee + dates) and trigger recalculation through `AttendanceRecalculator`.

### Rule document (`rules` JSON; keys are conventions, validate in workstream A)

Policy-wide (`leave_policy_versions.rules`): `sandwich {enabled, apply_to[], count_holidays, count_weekly_offs}`, `approvals {steps[], escalate_after_hours}`, `notice_days`, `backdate_days`, `future_days`.
Per type (`leave_policy_type_rules.rules`): `unit`, `entitlement {basis: annual|monthly|..., amount, tenure_bands[], proration{...}}`, `eligibility {probation_days, notice_period, employee_types[], countries[], legal_entities[], min_tenure_days}`, `limits {min_units, max_units, max_consecutive, min_consecutive, advance_leave, negative_limit, max_balance}`, `carry_forward {mode: none|full|capped|percentage, cap, percent, valid_days}`, `expiry {...}`, `consumption_order`, `documents[]`, `statutory`, `pay_class`.

## 5. Permissions (`WorkspaceProvisioner::PERMISSIONS`, group "Leave")

`leave.view`, `leave.request`, `leave.team.view`, `leave.approve`, `leave.manage`, `leave.policy.manage`, `leave.adjust`, `leave.adjust.approve` (maker-checker), `leave.accrual.run`, `leave.import`, `leave.export`, `leave.lock`, `leave.unlock`, `leave.reports.view`, `leave.simulate`, `leave.medical.view`, `leave.compoff.manage`.

Defaults: Workspace administrator and HR administrator = all; Manager = view, request, team.view, approve, reports.view; Team member = view, request. `leave.medical.view` is never given to Manager/Team member. The `leave` module is added to `WorkspaceProvisioner::MODULES` and enabled for existing tenants by the grant migration.

## 6. Conventions for workstreams

* Routes: only in your `routes/leave/<file>.php` (inside `/api/v1/leave`, `module:leave`), add `middleware('permission:...')` per route; controllers in `App\Http\Controllers\Leave\<Area>\`; no logic in controllers.
* Bindings/hooks: one `app/Domain/Leave/Boot/<YourArea>Boot.php`, never edit another workstream's Boot.
* Settings: `LeaveSettings::get('requests.backdate_days')` (tenants.settings.leave); add keys to `LeaveSettings::DEFAULTS` only via the owner of that section (see progress.md).
* Events: `LeaveEvents::*` through `DomainEvents::record`; payloads carry uuids only, never reasons/medical text.
* Medical/sensitive: `leave_types.is_sensitive`, `leave_requests.is_sensitive`, documents `is_sensitive` => require `leave.medical.view`; managers never see reasons unless `LeaveSettings privacy.managers_see_reason`.
* Money: none. Encashment/settlement = units + reference.
* Tests: `tests/Feature/Leave/<Area>*Test.php` using `PeopleFixtures`; do not edit `LedgerTest.php` except to add cases for new ledger behaviour (owner B).

## 7. Endpoints

None yet. `routes/leave.php` mounts `/api/v1/leave` behind `module:leave` and includes `policies`, `ledger`, `requests`, `operations` (all empty skeletons).
