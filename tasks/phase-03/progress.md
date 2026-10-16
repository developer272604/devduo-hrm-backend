# Phase 3 progress

| Work item | Status |
|---|---|
| Immutable punch/calculation/finalization layers, timezone-safe attendance, shifts, rosters and Time Office | Core implemented; full phase gate remains open |
| `max_consecutive_nights` roster compliance check | Implemented against effective local night windows; focused backend test passed (1 test, 4 assertions) |
| Different-date shift swaps | Implemented: requester and counterpart dates are validated and approved independently, with shift-change detection, lock/compliance checks and per-date recalculation; focused roster tests 11 / 98 assertions, Attendance frontend tests 11 passed, production build passed |
| MariaDB/MySQL concurrency and migration validation | Pending; shared development database was not changed |
| Attendance regression verification | Backend Attendance suite passed (86 tests, 616 assertions); frontend Attendance and Time Office suites passed (29 tests). These are focused feature suites, not MariaDB/browser/load tests. |
| Remaining Phase 3 open items | Shift-assignment identity contract verified: API exposes public UUIDs as `id`, and the editor submits that UUID. Other untested UI/API contracts, MariaDB/browser/load verification, production operations, and leave/payroll provider reconciliation remain |

See `../../docs/PHASE-2-3-REPORT.md` and `../../docs/HRMS-CAPABILITY-AUDIT.md`.
