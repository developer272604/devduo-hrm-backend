# Phase 4 - Advanced Leave & Absence: task plan and progress

Supersedes the inspection notes in `tasks/phase-4/progress.md`. Foundation reference: `docs/api/leave-foundation.md`.
Status legend: DONE, TODO, BLOCKED(dep). Updated 2026-10-11. Phase implementation is complete; remaining validation gates are in `docs/PHASE-4-REPORT.md`.

## Workstreams and exclusive file ownership

| WS | Scope (requirement groups) | Owns (create/edit exclusively) |
| --- | --- | --- |
| Foundation (done) | schema, models, LedgerService, BalanceService, contracts, permissions, route skeleton | `database/migrations/2026_10_12_*`, `app/Domain/Leave/{Models,Ledger,Contracts,Support,Locks}`, `Boot/LeaveFoundationBoot.php`, `tests/Feature/Leave/LedgerTest.php` |
| A | 3 types, 4 policies/versions/assignments/precedence, 5 cycles + eligibility rules, 9 restriction/document rule CRUD | `app/Domain/Leave/{Types,Policies,Cycles,Eligibility}/**`, `Boot/PolicyBoot.php`, `app/Http/Controllers/Leave/Policies/**`, `routes/leave/policies.php`, `tests/Feature/Leave/Policy*Test.php` |
| B | 2 ledger ops, 5 entitlements/accrual, 6 carry/expiry, 12 imports + bulk, adjustments (maker-checker), balances/ledger API | `app/Domain/Leave/{Accrual,CarryForward,Expiry,Adjustments,Imports}/**`, `Boot/AccrualBoot.php`, `app/Http/Controllers/Leave/Ledger/**`, `routes/leave/ledger.php`, `tests/Feature/Leave/{Accrual,Carry,Import}*Test.php`; may extend `Ledger/*` only by adding methods (coordinate with foundation owner) |
| C | 7 duration, 8 requests/approvals, 9 restrictions/conflicts, 10 comp-off/special, 14 attendance integration, 13 future-leave cancellation hooks | `app/Domain/Leave/{Duration,Requests,Restrictions,CompOff,Integration}/**`, `Boot/RequestsBoot.php`, `app/Http/Controllers/Leave/Requests/**`, `routes/leave/requests.php`, `tests/Feature/Leave/{Duration,Request,CompOff,Attendance}*Test.php` |
| D | 13 transfers/separation/rehire, 14 payroll contract + locks, 15 differentiators, 16 security/reports/exports, data quality, Employee 360/Insights providers | `app/Domain/Leave/{Operations,Reports,Explainer,Simulation,Transfers,Payroll,LockLifecycle}/**`, `Boot/OperationsBoot.php`, `app/Http/Controllers/Leave/Operations/**`, `routes/leave/operations.php`, `tests/Feature/Leave/{Report,Explainer,Simulat,Transfer,Payroll,Lock}*Test.php` |
| FE-1 | Employee + manager UX (My Leave, balances, ledger explanation, request, calendar, comp-off, approvals, team calendar) | `frontend/src/pages/leave/{my,team}/**`, `frontend/src/components/leave/{request,balance,calendar}/**`, `frontend/src/lib/leaveApi.js`, `frontend/src/routes/leaveRoutes.jsx` |
| FE-2 | HR UX (Operations Center, policies, cycles, accruals, adjustments, imports, locks, reports, simulator) | `frontend/src/pages/leave/hr/**`, `frontend/src/components/leave/hr/**`, `frontend/src/lib/leaveHrApi.js` |

Shared-file edits (one owner each, announce before editing): `ApproverResolver::KINDS`/`PERMISSION` add `leave` (C); `LeaveSettings::DEFAULTS` sections (A: requests/approvals, B: accrual/expiry/comp_off/balance, D: privacy/integration); router/nav registration of `leaveRoutes` in `Layout`/`main` (FE-1, one line); `LedgerPosting`/`LedgerService` changes (foundation owner only).

## Dependency-ordered tasks

| ID | Task | Scope / files | Acceptance tests | Status |
| --- | --- | --- | --- | --- |
| P04-T01 | Schema + permissions + module | migration `2026_10_12_000001`, grant migration `000002`, `WorkspaceProvisioner` leave.* keys + `leave` module | migrate/rollback/migrate on SQLite; permissions registered | DONE |
| P04-T02 | Models, settings, boot, route skeleton, docs | `Leave/Models/*`, `Support/{Units,LeaveSettings,LeaveEvents}`, `Boot/LeaveFoundationBoot`, `routes/leave*.php`, `ModulesServiceProvider`, `docs/api/leave-foundation.md` | boot binds services; module-gated route skeleton | DONE |
| P04-T03 | Ledger + balance + contracts | `Ledger/{LedgerService,BalanceService,...}`, `Contracts/**`, `Locks/DatabaseLeaveLockGuard` | `tests/Feature/Leave/LedgerTest.php` (idempotency, reversal, buckets, precision, no-overdraw, locks, tenancy) | DONE |
| P04-T04 | Leave types CRUD + categories/units/applicability (grp 3) | A | validation, tenant isolation, sensitive flags | DONE |
| P04-T05 | Policy + version lifecycle + publish immutability + diff (grp 4) | A | draft/published/scheduled/active/retired; no historical rewrite; checksum | DONE |
| P04-T06 | Assignments + `LeavePolicyResolver` precedence (grp 4) | A | deterministic precedence, as-of org data, country/entity rules | DONE |
| P04-T07 | Cycles + periods + eligibility (probation/notice/tenure) (grp 5) | A | calendar/fiscal/anniversary/custom period generation | DONE |
| P04-T08 | Account opening + balances/ledger read API (grp 2, 11) | B | account per employee/type/period, negative limit from policy, data-scoped reads | DONE |
| P04-T09 | Entitlement engine + accrual rules/runs (preview, run, retry, idempotent) (grp 5, 12) | B | tenure, proration, caps, negative, idempotent re-run, skipped/failed items | DONE |
| P04-T10 | Carry forward + expiry + notifications + consumption ordering (grp 6) | B | none/full/capped/percentage, expiry entries, ordering | DONE |
| P04-T11 | Adjustments (maker-checker) + bulk grants/debits + imports (grp 12) | B | opening balance and historical import, duplicates, retry, never overwrite ledger | DONE |
| P04-T12 | Duration engine (grp 7) | C | holiday/weekly-off/sandwich, half/quarter/hour, night shift, DST, cross-cycle, explainable | DONE |
| P04-T13 | Requests: submit/reserve/approve/reject/withdraw/cancel/partial/modify + My Work + actions history (grp 8) | C | reservation concurrency, approvals via Attendance resolver, ledger reversals | DONE |
| P04-T14 | Restrictions, conflicts, documents, medical privacy (grp 9, 16) | C | warn/block/escalate; statutory never auto-denied; medical hidden from managers | DONE |
| P04-T15 | Comp-off credits/claims/optional holidays/return-to-work/donation foundation (grp 10) | C | source-to-use trace, validity/expiry | DONE |
| P04-T16 | Real `LeaveDataProvider` + recalculation events (grp 14) | C | attendance day shows leave, approval triggers recalc, cancel restores | DONE |
| P04-T17 | Locks lifecycle + payroll contract lines + cutoff metadata (grp 14) | D | locked period blocks ledger posts, late-adjustment flag, no salary maths | DONE |
| P04-T18 | Transfers/separation/rehire (grp 13) | D | preserve/transfer/convert/expire, future leave cancellation | DONE |
| P04-T19 | Differentiators: Explainer, Sandbox, Entitlement Simulator, Impact Preview, Forecast, Policy Diff, Command Center, Confidence, Team Availability, Time Story, Data Quality (grp 15) | D | explainable from policy + ledger; simulations non-mutating | DONE |
| P04-T20 | Reports + CSV/XLSX export + saved views + Employee 360/Insights/Audit hooks (grp 11, 16, 17) | D | export permission, tenant scope | DONE |
| P04-T21 | Frontend employee/manager (grp 11) | FE-1 | responsive states, request preview, approvals | DONE |
| P04-T22 | Frontend HR (grp 11) | FE-2 | policies, accruals, imports, locks, reports | DONE |
| P04-T23 | Integration verification of the 19 groups (grp 19) | all | full suite, payroll contract, country-agnostic policy fixtures | DONE |

Parallelism: A starts immediately; B and C start against the foundation contracts with A's resolver stubbed in tests; D starts T17 immediately and T19/T20 after B/C land. FE-1/FE-2 code against `docs/api/leave-foundation.md` and the route groups above.

### HR employee lookup continuation (2026-10-11)
- Replaced raw employee UUID entry with the existing searchable EmployeePicker in comp-off grants, transfer/rehire previews, policy resolve checks, and policy assignment resolve checks.
- The selected UUID remains the submitted API value; the UI presents employee names and employee numbers.
- Validation: Phase 4 HR frontend tests passed (18 tests); production build passed with the existing large-bundle advisory.
- The bulk-actions UI gap from this item was closed in the continuation below.

### Tenant leave settings continuation (2026-10-11)
- Added permission-guarded `GET/PUT /api/v1/leave/settings` with strict field/range validation, effective defaults, partial nested updates, and tenant-row locking.
- Added a Settings page to the Leave Operations Center for request, approval, accrual, units, comp-off, privacy, restriction, and integration settings.
- Validation: backend settings tests passed (3 tests / 23 assertions); Phase 4 HR frontend suite passed (19 tests / 6 files); production build passed with the existing large-bundle advisory; Pint passed.
- The bulk-action endpoint coverage is recorded in the continuation below.

### Leave bulk-action management UI continuation (2026-10-11)
- Added a Leave Operations page listing actions returned by the registry, searchable employee selection (up to the action limit), action-specific handling parameters, per-employee preview, and a required confirmation before run.
- Any change to the action, parameters, or employee selection invalidates the previous preview. Run results replace the preview and are shown per employee.
- Validation: Phase 4 HR frontend suite passed (20 tests / 7 files); production build passed with the existing large-bundle advisory.
- Backend coverage: 3 feature tests / 18 assertions verify registry permissions, invalid action/params, cross-tenant hiding, preview is read-only, and successful run. Per-employee exception paths and finer-grained same-tenant DataScope filtering remain open.

### Consecutive leave limits continuation (2026-10-11)
- Policy `limits.max_consecutive` and configured `consecutive` restrictions now extend across contiguous pending/approved requests of the same leave type.
- Requests separated by a calendar-day gap do not extend the chain. For partially cancelled requests, only contiguous runs of active dates count; the cancelled dates break the chain. Existing same-request limits still run in DurationEngine; statutory limits continue to escalate.
- Validation: RequestRestriction, RequestFlow, and DurationEngine suites passed (22 tests / 173 assertions); Pint passed.
- At this point pending-request expiry, negative carry-forward, attendance provider, and People transfer-event behavior have focused coverage. Remaining validation gates are tracked in the phase report.

### Pending leave request expiry continuation (2026-10-11)
- Added tenant setting `requests.pending_expiry_days` (default 90, nullable to disable) to the validated Leave Settings API and HR settings form.
- The hourly maintenance command expires pending requests after the configured age, releases each live reservation, closes associated My Work tasks, records an `expire` history/audit entry, and notifies the requester. Expiry runs before escalation for the same request to avoid creating a new escalation for a request expiring in that maintenance pass.
- Validation: RequestFlow suite passed (10 tests / 100 assertions); Pint passed.
- These are implementation notes; current status and outstanding release validation are summarized in the phase report.

### Phase 4 completion (2026-10-11)
- Negative leave debt now carries forward as a signed ledger movement into the next period (enabled by default, configurable with `balance.carry_negative_forward`). Positive balances continue to follow the leave policy's carry rule.
- Added coverage for negative carry, rehire restoration, and the People transfer-event preview listener.
- Full Leave verification: backend 109 tests / 1081 assertions; frontend 39 tests; production build passed. The two new transfer/event tests also passed in a focused run (9 tests / 65 assertions). Pint passed.
- Phase 4 implementation is complete. Remaining production validation (MariaDB concurrency, browser review, country-law certification, export download and a few operational edge paths) is explicitly listed in `docs/PHASE-4-REPORT.md`.

All P04-T01 through P04-T23 are marked DONE for implementation. This does not certify country-specific statutory rules or live-database concurrency; those require the external review described in the completion report.
