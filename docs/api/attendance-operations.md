# Attendance operations and insights API

Base: `/api/v1`, tenant scoped, module `attendance`. Out-of-scope employees are never disclosed (empty/404). Page size <= 100. No coordinates are ever returned.

| Endpoint | Permission | Notes |
|---|---|---|
| GET `attendance/settings[/{section}]`, PUT `attendance/settings/{section}` | attendance.policy.manage or settings.manage | sections: methods{enabled[]}, location, status_labels{labels{code:label}}, regularization, cutoffs, notifications, punch, processing, compliance, swap. Strict (unknown keys 422), merge-preserving, audited with before/after |
| GET `attendance/me/summary` | attendance.view | today (status, shift, worked so far, last punch, expected, confidence), open exceptions, pending requests, month counts |
| GET `attendance/team/today?date=` | attendance.team.view | in, absent, late, on leave (LeaveDataProvider), remote/on duty, missing punches, pending regularizations |
| GET `attendance/team/summary?from&to&page&per_page` | attendance.team.view | per-employee aggregates + totals |
| GET `attendance/command-center?date=` | attendance.manage / process / reports.view | sections each `{title,count,sample,action}`: processing_health, scheduled_today, checked_in, missing_punches, late_arrivals, unscheduled_today, unscheduled_tomorrow, roster_gaps, device_sync, pending_regularizations, overtime_awaiting_approval, attendance_locks (with payroll cutoff). processing_health and device_sync only for manage/process |
| GET `attendance/roster-gaps?date=` | manage / roster.manage / reports.view | employees with no shift |
| GET `attendance/payroll-readiness?payroll_group&from&to` | manage / process / reports.view | conditions: attendance_processed, exceptions_remaining (action_required/critical only), approvals_pending, attendance_locked, overtime_approved, missing_policies, missing_shifts. `READY` only if all counts are 0, else `NOT READY`. `employee_days.resolved_percent` is shown beside `unresolved_employees` |
| GET `attendance/payroll-output?payroll_group&from&to&include_open&page` | same | per employee: payable/present/absent/half/weekly off/holiday days, approved leave (LeaveDataProvider), unpaid-time placeholder, approved OT minutes, late/early counts, confidence (CONFIRMED / REVIEW REQUIRED / PENDING). Finalized (approved/locked) days only unless `include_open=1` (flagged `INCLUDES OPEN DATA - not final`). No salary maths |
| GET `attendance/reports`, GET `attendance/reports/{report}` | attendance.reports.view | reports: daily, monthly, late_arrival, early_departure, missing_punch, overtime, shift, summary, exceptions, regularization, on_duty, roster, device_punch. Filters: q, employee, employee_number, department_id, location_id, legal_entity_id, shift_id, from, to, status, exception_type, manager, source, policy_id, device_id; `sort` (whitelist), `dir`, `page`, `per_page` |
| POST `attendance/reports/{report}/exports` {format: csv|xlsx, ...filters} | attendance.export | queued job, 202; audited. GET `attendance/exports` history; GET `attendance/exports/{uuid}/download` (expires after 3 days, audited) |
| GET/POST `attendance/views`, PUT/DELETE `attendance/views/{uuid}` | reports.view / manage | saved views (table saved_views, module attendance): filters + columns + sort only; sharing needs attendance.manage |
| GET `attendance/search?q=` | attendance.* | employees (scoped), shifts, departments, locations, policies, devices with links |
| GET `attendance/dashboard` | attendance.view / team.view | own status; team in/out counts; people away via LeaveDataProvider |
| GET `attendance/health` | attendance.manage / process | processing failures, device sync, import failures, queue failures, abnormal durations; friendly text only |

## Registries
- Action Center `attendance` provider keys: attendance.payroll_cutoff_risk (critical when cutoff passed, action_required in the 3 days before), missing_punches (action_required), no_shift_tomorrow, devices_not_synced, processing_failures, repeated_lateness (warning), regularizations_pending, overtime_pending, overtime_approved (info). Deterministic; no scoring.
- Quality rules: attendance_no_policy, _no_shift, _invalid_shift_assignment, _overlapping_shift_assignments, _device_mapping_missing, _suspicious_timezone, _excessive_duplicates, _unresolved_missing_punch, _not_processed, _unlocked_near_cutoff, _holiday_calendar_missing. Read-only.
- Employee 360 section provider `attendance`.

## Conventions
Payroll cutoff day D: on day D of a month the PREVIOUS month must be finalized (payroll group `cutoff_day` wins over `cutoffs.payroll_cutoff_day`). Confidence is data completeness, not performance.

Schedules: `attendance:purge-exports` hourly (registered by OperationsBoot); `schema` data-quality scan via the People scan job.
