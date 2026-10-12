# Phase 4 — Advanced Leave & Absence Management — completion report

Status: **implementation complete**. Backend and frontend suites pass. Production validation remains for MariaDB concurrency, browser responsiveness, and country-specific statutory rules.

## Verification (run by the lead)
- Backend Leave suite: 109 tests, 1081 assertions passed; includes 7 end-to-end scenarios using real bindings. Two later focused transfer/event tests also passed (9 tests / 65 assertions for that suite).
- Frontend Leave suite: 39 tests passed (9 files); production build succeeded with the existing large-chunk advisory.
- Migrations: leave migrations migrate → rollback → migrate verified on a temporary SQLite file. **Not verified on MariaDB/MySQL** (row locks, `FOR UPDATE` behaviour untested).

## Delivered
- Schema: 37 `leave_*` tables (`2026_10_12_000001`, `_000002`, `2026_10_13_B00001`, `_D00001`).
- Ledger-first core: immutable decimal-safe ledger, idempotent posting, reversals, lot allocation/trace, derived balance buckets, concurrency-safe reservation, locks.
- A: types, effective-dated policies/versions (draft/scheduled/active/retired), deterministic assignment precedence, cycles, eligibility, document/restriction rules, optional-holiday pools, policy difference viewer.
- B: entitlement/accrual engine, idempotent accrual runs (preview/run/retry, nightly job), carry-forward and expiry with consumption ordering, adjustments with maker-checker, CSV/XLSX imports, balance/ledger APIs.
- C: duration engine (half/quarter/hourly, sandwich, cross-midnight, DST), requests (preview, submit, withdraw, cancel/partial, modify), approvals via the shared resolver + My Work, restrictions, comp-off, team calendar/availability, real `LeaveDataProvider` feeding Attendance with recalculation.
- D: lock lifecycle (maker-checker unlock), payroll contract (references and cutoff metadata only; no salary math), transfer/separation/rehire plans, Explainer, Policy Sandbox, Entitlement Simulator, Impact Preview, Forecast, Confidence, Absence Command Center, Time Story, reports + queued exports, data-quality rules, Action Center and Employee 360 providers.
- Frontend: employee/manager screens (My Leave, request flow, comp-off, team calendar, approvals) and HR Operations Center (policies, accruals, adjustments, carry-forward, imports, locks, transfers, payroll contract, simulators, reports, tenant leave settings, bulk actions).
- Docs: `docs/api/leave-{foundation,policies,ledger,requests,operations,rule-keys}.md`, `tasks/phase-04/progress.md`.

## Integration fixes made in the integration pass
Rule-key mismatches between workstreams (tenure bands `from_days`/`from_months`, `consumption_order` aliases, `advance_leave` flag, D's keys rejected by the validator) fixed; request ledger now honours `consumption_order`. Canonical keys in `leave-rule-keys.md`.

## Remaining validation and documented limitations
1. MariaDB/MySQL row-lock and concurrent reservation behavior has not been verified against a live database; the current migration and test verification uses SQLite.
2. No browser-based visual/responsive review has been performed. Component tests and the production build pass.
3. Country rules are configuration; statutory entitlements and privacy rules need expert validation for every enabled jurisdiction before production.
4. The policy sandbox evaluator remains an approximation for draft policy rules; published policies use the real engine. Live browser/API response-shape checks and queued export download checks remain deployment validation items.
5. Negative balances now carry as signed debt into the next period by default. Tenant setting `balance.carry_negative_forward` can disable this; positive carry remains governed by each policy's carry rule.
6. Pending requests now expire after `requests.pending_expiry_days` (default 90 days; `null` disables expiry). The hourly maintenance job releases reservations, closes tasks, records history/audit and notifies the employee.
7. Bulk operations require per-employee review; unusual same-tenant scope and per-employee exception paths have limited automated coverage. Default leave-type seeding remains an admin endpoint operation.
8. The end-to-end Leave suite is slow (~7.5 minutes) but passed.

## Phase 5 readiness
Payroll contract endpoints (`/api/v1/leave/payroll/*`) and attendance payroll readiness are in place for Phase 5 consumption.
