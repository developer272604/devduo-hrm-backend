# Phase 4 — Leave & Absence inspection

Inspected: 2026-10-09. Status: **not implemented as an active domain**. Inspection only; no application implementation changed.

## Specification limitation

`CLAUDE.md` and `docs/hrms-phases-02-to-06.md` were not found in the workspace (including hidden/ignored files); checked ancestor CLAUDE.md locations were also absent. This is a **provisional** checklist against the user's previously supplied Phase 4 master specification: `C:/Users/vilva/.codex/attachments/7cfa1f39-ddc7-40f7-9485-197af4aad1e5/Pasted text.txt` (sections 1–129). Reconcile with the requested documents when available before implementation.

## Completed reusable foundations

- [x] Session authentication, tenant scoping, public UUIDs, RBAC/data scopes, audit, domain events, notifications and My Work infrastructure exist under `backend/app/Domain` and `backend/routes/web.php`.
- [x] People employee/employment lifecycle, effective assignments, organization memory and extension registries exist. Reuse these rather than creating another employee model.
- [x] Attendance scheduling, holiday resolution, processing, recalculation and lock infrastructure exist under `Domain/Attendance`. `Processing/InputBuilder.php` already consumes `LeaveDataProvider`.
- [x] Frontend shared controls and Tailwind theme exist. Reuse the purple `#7c46f1` theme and light/dark modes.

These are dependencies, **not completed end-to-end Phase 4 features**.

## Partial requirements and evidence

| Requirement | Existing implementation | Remaining gap |
| --- | --- | --- |
| Leave access/security (§109,114) | `Access/WorkspaceProvisioner.php`: leave.view/manage/approve | Granular leave permissions, server policies, scope/privacy enforcement and secure medical documents |
| Attendance integration (§70–71,124) | `Attendance/Contracts/LeaveDataProvider.php`, consumer in InputBuilder, full/half-day engine tests | `Boot/LeaveBoot.php` binds `NullLeaveDataProvider`; no real approved segments or leave-triggered recalculation |
| Scheduling/availability (§48,125) | ScheduleResolver, HolidayService, on-duty conflict check; `ScheduleBoot.php` binds NullUnavailabilityProvider | Real leave unavailability, conflict and team capacity calculations |
| Payroll/people-away (§72–74,97) | Attendance PayrollReadiness, TeamAttendance and dashboard consume the provider | Real leave data, statutory/comp-off/encashment outputs, leave locks and cutoff corrections; main dashboard still shows absence placeholder |
| Approval/My Work (§40–42,57,102) | Attendance ApproverResolver, delegation and request services; shared work infrastructure | Reusable cross-domain approval integration and leave lifecycle; do not create a disconnected approval engine |
| Historical policy context (§98) | People policy references and memory; InsightsTest covers a leave_policy reference | Actual leave policy versions, historical ledger/balance and approval snapshots |

Archived code in `docs/legacy-time-slice` is explicitly pre-specification reference material with known defects. The old `2026_10_06_000001` migration creates some leave tables, but `2026_10_07_000001_create_people_domain.php` retires those tables. They are not evidence of a working current leave module. `ModulesServiceProvider` discovers only People and Attendance boot directories; active routes include no leave route group. No active Leave domain, leave pages or dedicated Phase 4 test suite was found.

## Dependency-ordered remaining checklist

All items below are missing unless identified as partial above. Each implementation task must include its relevant tests; task 13 is final integration verification, not deferred unit testing.

- [ ] **0. Reconcile source instructions:** obtain/read the named CLAUDE.md and combined specification, then reconcile this provisional scope.
- [ ] **1. Register the Leave domain and establish persistence/security foundations** (§1–7,109–110,114,128). Add module boot/route wiring, scoped permissions, tenant-owned UUID models and normalized migrations for types, policies/versions/assignments, cycles, accounts and immutable decimal-safe ledger entries. Include foreign keys, unique/idempotency constraints, source/audit references and tenant-isolation tests. Preserve existing application data; do not reactivate the archived slice.
- [ ] **2. Policy resolution and eligibility** (depends 1; §8–12,15,20–23,33–39,50,108). Effective-dated version lifecycle and deterministic assignment precedence; country/entity/group rules, configurable units, cycles, tenure, probation/notice restrictions, document requirements and historical rule references.
- [ ] **3. Ledger, balance and reservation engine** (depends 1–2; §2–4,19–21,55–56,75–76,88–89,115,119). Append-only credits/debits/reversals, opening and bulk adjustments, pending/scheduled/available categories, caps/negative/advance balances, transactional locking and idempotency. Trace each balance component; never use editable balance numbers.
- [ ] **4. Duration, impact and conflict engine** (depends 2–3; §24–39,48–50,84–85,120,125). Reuse shifts/rosters/holidays; handle half/quarter/hour units, night shifts, timezone/DST, cross-cycle/version boundaries, sandwich/weekly-off rules, employment dates, team availability and explainable previews.
- [ ] **5. Requests and shared approval integration** (depends 3–4; §24,37–47,57,88–89,99–103,114). Submission/reservation, conditional approvers/delegation, secure attachments, My Work, notifications, rejection/withdrawal, partial cancellation and controlled modification; ledger reversals, action history and privacy-aware events.
- [ ] **6. Attendance, payroll-output and lock integration** (depends 5; §66,70–74,124). Real LeaveDataProvider and UnavailabilityProvider, normalized approved segments, approval/cancellation recalculation, paid/unpaid/statutory/comp-off outputs, encashment references, cutoff handling and audited corrections coordinated with attendance locks. No monetary payroll processing.
- [ ] **7. Entitlement automation** (depends 2–3,6; §12–19,79–81,87). Deterministic accrual/proration, tenure and joining/separation rules, carry-forward, expiring credit lots/consumption order, idempotent scheduled jobs with run/item history, failure/retry visibility, non-mutating previews and labelled forecasts.
- [ ] **8. Extended absence and employment transitions** (depends 5–7; §59–69,90–93). Comp-off earning/claim/approval/expiry/consumption tied to attendance/OT; optional holidays; donation and encashment foundations; statutory/long absence and return-to-work; policy/country transfers, conversion, separation and rehire with employment-period traceability.
- [ ] **9. Operational imports, reports and simulations** (depends 3–8; §75–86,104–107,121,126). CSV/XLSX opening/history imports with mapping, validation, preview, duplicates, retries/history; queued scoped exports, saved views, liability units, read-only entitlement/policy sandbox and policy difference/impact confirmation.
- [ ] **10. Shared action/intelligence integration** (depends 5–9; §94–103,122–127). Register leave quality rules and actionable findings, Employee 360, people-away dashboard, organization memory, confidence states, expiry reminders and absence command center using common registries, tasks/events and permission-aware direct links.
- [ ] **11. Employee and manager UI** (depends corresponding APIs in 3–8,10; §51–57,112–113,119–120,124–125). My Leave, explainable balance/ledger, request impact preview, history, withdrawal/cancellation, comp-off, calendars/team availability and My Work approvals. Tailwind only; shared themed dropdowns, light/dark, mobile views and accessible keyboard/error states.
- [ ] **12. HR/admin UI** (depends 2,7–10; §58,75–87,108,112–113,121–123,126). Policy/type/cycle/settings management, difference viewer, assignments, accrual runs/previews, adjustments, import/export, reports, locks, simulator and actionable absence operations center.
- [ ] **13. Completion verification and report** (depends all; §111,116–118,129). Security/privacy and integration tests; ledger concurrency and retry tests; leap/DST/night-shift/cross-year cases, expiry while pending, changing manager/policy, transfer/rehire and cancellation under lock. Validate mobile/desktop/light/dark/permissions and queued/batched performance at large scale. Document verified results, limitations and Phase 5 readiness; do not begin Phase 5.

## Existing tests and failures

- Related backend run: `C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --filter 'StatusAndRulesTest|OperationsTest'` — **63 passed, 344 assertions** (SQLite in memory). The filter includes People and Attendance OperationsTest classes.
- Coverage includes full/half-day attendance with supplied leave data and payroll/dashboard provider integration. These use fixtures/fake providers; they do not prove an actual leave request or ledger lifecycle.
- No active dedicated Phase 4 backend/frontend tests found. Archived `docs/legacy-time-slice/backend/tests/TimeTest.php` is outside the active suite and is not acceptance evidence.
- Full backend: `C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit` — **417 passed, 3,179 assertions**, SQLite in memory, 122.648 seconds. No backend failures; the previously reported final `TestCase::run()` override is not blocking this suite.
- Full frontend: `npm.cmd test -- --reporter=dot` — **75 passed, 9 failed**, 10 test files passed and 3 failed (84 tests total). Failing files: `src/pages/timeoffice/__tests__/roster.test.jsx`, `policySimulator.test.jsx`, and `responsive.test.jsx`. Observed errors include missing mocks for `/v1/rosters` and `/v1/attendance-policies/simulate`, followed by missing expected rendered results. Root causes beyond these observed errors are not fully diagnosed; do not assume all nine failures are test-only defects.
- Frontend execution initially hit PowerShell's npm.ps1 restriction and then sandboxed esbuild configuration access; `npm.cmd` with approved execution outside the sandbox ran the suite successfully to the failure report above. No application code or tests were fixed.
- Browser E2E and MariaDB were not rerun during this inspection. Existing shared frontend failures require triage before declaring a clean Phase 4 integration baseline.

## First implementation task

After resolving the missing instruction documents, start **task 1: register the Leave domain and add the tenant-safe core schema/models and isolation tests**. Existing infrastructure should be reused; implementing leave screens first would have no active domain/API behind them.
