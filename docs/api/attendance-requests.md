# Attendance requests and approvals API

Base: `/api/v1/attendance`. All ids are public UUIDs. Employees are always resolved inside the caller's data scope (out of scope = 404). Submit endpoints accept an `Idempotency-Key` header. Times entered by users are wall-clock in the shift/employee zone and stored UTC; responses return ISO-8601 UTC.

| Method and path | Permission | Purpose |
|---|---|---|
| GET/POST `regularizations` | attendance.view | List (own, or `scope=team`) / submit `{date,type,requested_in,requested_out,reason,comments,attachment(file<=5MB)}`; optional `employee` uuid for HR |
| GET `regularizations/{uuid}` (+`/attachment`) | attendance.view | Detail with step history |
| POST `regularizations/{uuid}/approve` or `reject` | approver (ApproverResolver) | `{comment}` (required on reject) |
| POST `regularizations/{uuid}/withdraw` | submitter | |
| GET/POST `overtime`, POST `overtime/{uuid}/approve` `{approved_minutes?}` / `reject` / `withdraw` | attendance.view / attendance.overtime.approve | modes pre_approval, post_work; partial approval; approved <= calculated for post_work |
| GET/POST `on-duty`, POST `on-duty/{uuid}/approve|reject|withdraw`, GET `.../attachment` | attendance.view / attendance.approve | types client_visit, field_work, training, official_travel, remote_work |
| GET/POST `adjustments`, POST `adjustments/{uuid}/approve|reject|revert` | attendance.adjust / attendance.adjust.approve | types add_punch, void_punch (`event_uuid`), status_override; sensitive ones need a different checker |
| GET/POST `delegations`, PUT/DELETE `delegations/{uuid}` | attendance.approve, overtime.approve or manage | `{delegate_user (employee uuid), starts_on, ends_on, kinds[]}`; DELETE revokes |
| GET `approvals?kind=regularization|overtime|on_duty|shift_swap|adjustment&status=` | any approve permission | Items the caller can decide |
| GET `bulk/actions` | attendance.bulk | Registered actions |
| POST `bulk/preview` `{action,params,targets[]|filters}` | attendance.bulk + action permission | Per-target ok/skipped/error, `affected`, `confirmation_token` (15 min) |
| POST `bulk/apply` `{token}` | same | Per-target transactions; >100 targets queued (202). GET `bulk/runs/{uuid}` for status |

Bulk targets: `{employee: uuid, date}` (regularize, mark_on_duty) or `{exception: uuid}` (resolve_exception). The token binds user, tenant, action, target-set hash and params hash, and is single use.

## Services
`ApproverResolver::forEmployee(Employee, kind, date, step, exclude=[])`, `candidates(...)`, `canDecide(User, Employee, kind, date, step, exclude=[])`, `onBehalfOf(...)`.

## Commands (recommended schedule; not registered)
- `attendance:expire-requests` daily 01:00
- `attendance:send-reminders` every 15 minutes

Settings read: `regularization.window_days|max_per_month`, `cutoffs.regularization_days_after|manager_approval_days|overtime_window_days`, `notifications.quiet_hours {start,end}`, `adjustments.sensitive_days_back` (default 7).

## Processor contract
Approved regularizations, applied/approved adjustments (`add_punch`, `void_punch`, `status_override`; reversals have type `reversal` with status applied and mark the original `reverted`), approved on-duty rows and approved overtime are read by the processor; this module calls `AttendanceRecalculator::recalculateDay` after commit and `LockGuard::assertUnlocked` before changes (only when bound).
