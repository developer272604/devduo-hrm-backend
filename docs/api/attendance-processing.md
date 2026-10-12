# Attendance processing API

Base: `/api/v1`, session auth, tenant-scoped, module `attendance`. Errors: 401, 403 (permission), 404 (missing or out of data scope), 422 (`{message, errors:{field:[...]}}`, friendly text), 423 (locked period).
Times are UTC ISO-8601 (`...Z`); `date` values are logical attendance dates in the shift timezone.

## Policies (`attendance.policy.manage`; reads also `attendance.manage`)

| Method | Path | Notes |
|---|---|---|
| GET | `/attendance-policies` | list with versions |
| POST | `/attendance-policies` | `{name, code, description?, country?, rules?, effective_from?}` creates policy + draft v1 |
| GET/PATCH | `/attendance-policies/{uuid}` | PATCH `{name, description, country, active}`; GET includes `defaults` (full rule schema) |
| POST | `/attendance-policies/{uuid}/versions` | clone into a new draft: `{change_note (required), rules?, effective_from?, effective_to?, base_version?}` |
| GET/PATCH | `/attendance-policies/{uuid}/versions/{n}` | PATCH only drafts; published = 422 "Published versions cannot be edited" |
| POST | `.../versions/{n}/publish` | closes the previously published version at `effective_from - 1 day`; returns `{data, closed:[...], warnings}` |
| POST | `.../versions/{n}/retire` | returns `{data, warnings}` |
| GET/POST | `/attendance-policy-assignments` | `{policy (uuid), target_type, target_id, effective_from, effective_to?, priority?, is_override?}`; ambiguous same target/dates/priority = 422 |
| PATCH/DELETE | `/attendance-policy-assignments/{id}` | |
| GET | `/attendance-policies/resolve?employee={uuid}&date=` | `{policy, matched_by, considered[], explanation}` |
| POST | `/attendance-policies/simulate` (`attendance.simulate`) | `{rules? | version_id?, from, to (<=31 days), sample_size<=500, scope:{employee_uuids[]|department_id|location_id|tenant}}` -> `{data:{summary:{records,changed,unchanged,by_field,by_transition:[{from,to,count,message}],messages},examples:[<=20 {employee_uuid,date,differences:[{field,before,after}]}],writes:false}}`. Never writes. |

Ranking: employee 100, team 80, department 70, employee_group 60, business_unit 50, location 40, employee_type 30, legal_entity 20, country 10, tenant 0, then is_override, priority, effective_from, id; org attributes as of the date.

## Processing (`attendance.process`)

* `POST /attendance/process` `{scope:{employee_uuids[]|department_id|location_id|employee_group_id|legal_entity_id|payroll_group_id|tenant:true}, from, to?, dedupe_key?}` -> 202 `{data: run}`. Up to 200 employee-days run inline; larger runs are queued. An identical queued/processing run returns the existing run.
* `POST /attendance/process/preview` same body -> `{employees, dates, employee_days, requires_confirmation (>100 employee-days), message: "This change will recalculate attendance for 142 employees across 3 dates."}`
* `GET /attendance/process/runs`, `GET /attendance/process/runs/{uuid}` -> run: `{uuid, trigger, status: queued|processing|completed|failed|partial, scope, total_days, processed_days, failed_days, skipped_locked, started_at, finished_at, errors:[{code, kind: failure|attention|info, message, employee_count, day_count, employees:[<=20 {uuid, employee_number, name}]}]}`
  e.g. "We couldn't process attendance for 14 employees because their shift assignment is missing."
* CLI: `php artisan attendance:process --tenant= --from= --to=`, `php artisan attendance:process-nightly` (yesterday + `processing.nightly_recalculation_days` + today). Schedule it from `routes/console.php`.

## Exceptions (`attendance.manage` or `attendance.team.view`, data-scoped)

* `GET /attendance/exceptions?type=&severity=&status=&from=&to=&employee={uuid}&department=&location=&page=&per_page<=100` sorted by severity.
  Item: `{uuid, type, title, severity: info|warning|action_required|critical, status: open|acknowledged|resolved|dismissed|auto_resolved, attendance_date, source_rule, detected_at, resolution, priority_reason, details, employee}`.
* `POST /attendance/exceptions/{uuid}/{acknowledge|resolve|dismiss|reopen}` body `{resolution}` (required for resolve/dismiss). Recalculation never reopens resolved/dismissed items and auto-resolves ones no longer detected.
* Severity is deterministic: `critical` only for a finalization-blocking issue after the payroll cutoff; `action_required` missing/unpaired punch, no shift, no policy; `warning` late allowance exceeded, geofence; `info` overtime. `details.priority_reason` explains why.

## Locks

| Method | Path | Permission |
|---|---|---|
| GET | `/attendance/locks` | lock/unlock/manage |
| POST | `/attendance/locks/preview` `{scope_type: tenant|legal_entity|payroll_group|location|employee_group|employee, scope_id, start_date, end_date}` | `attendance.lock` -> `{employees, employee_days, unresolved:{...}, unresolved_total, requires_acknowledgement, conflicts[], can_lock, message}` |
| POST | `/attendance/locks/approve-days` same scope | `attendance.lock` marks confirmed days `approved` |
| POST | `/attendance/locks` scope + `{reason, acknowledge_unresolved?}` | `attendance.lock`; unresolved items need `acknowledge_unresolved=true`; same-scope overlap or narrower-inside-broader = 422 |
| POST | `/attendance/locks/{uuid}/unlock-request` `{reason}` | lock or unlock |
| POST | `/attendance/locks/{uuid}/unlock-approve` / `unlock-reject` | `attendance.unlock`, must be a different user than the requester |

Mutations on a locked day return HTTP 423 ("Attendance for 2026-09-30 is locked ... request an unlock").

## Read APIs (own record, or `attendance.team.view`/`attendance.manage` within data scope; out of scope = 404)

* `GET /attendance/daily?from=&to=|date=&search=&department=&location=&legal_entity=&shift_id=&status=&confidence=&exception_type=&manager={uuid}&source=&policy={uuid}&device={uuid}&sort=attendance_date|status|net_minutes|late_minutes|overtime_minutes|confidence|employee_number&direction=&per_page<=100` -> `{data:[day + employee], meta}`.
* `GET /attendance/me/days?month=YYYY-MM` -> `{data:{month, days:[{date,status,label,net_minutes,first_in,last_out,markers:[late|overtime|missing_punch|regularization_pending|holiday|weekly_off|leave]}]}}` (`label` honours tenant status labels).
* `GET /attendance/days/{employeeUuid}/{date}` -> day fields (`status, status_label, confidence, finalization, first_in, last_out, required/gross/break/net/late/early_exit/overtime/approved_overtime minutes, open_exceptions, engine_version, calculation_version`) + `policy` ("India General Attendance Policy v3"), `explanation` (`{headline, status, lines:[{label,value,rule?}], facts, actions}` from the latest trace), `exceptions[]`, `calculation_history[]`.
* `GET /attendance/days/{employeeUuid}/{date}/reconstruction` -> `{timezone, precise_location, timeline:[{at, local_time, day_label ("Next day"), display, kind: punch|calculation|regularization|adjustment|lock|event, label, detail, meta}]}` in the shift timezone. Punch `meta` has latitude/longitude/accuracy only for viewers with `attendance.location.view`; everyone else sees `geofence_status` only.
* `GET /attendance/days/{employeeUuid}/{date}/trace?version=` (`attendance.manage`) -> `{inputs, steps, result, explanation}`. `inputs` records policy version, rules hash, schedule, punches (with origin raw|regularization|adjustment), voided punches, on-duty, approved overtime, month-to-date, `input_hash`.

## Domain events and bulk actions

Events (`employee` classification): `AttendanceCalculated` (on material change), `AttendanceExceptionDetected` (once per new exception), `AttendanceLocked`, `AttendanceUnlocked`. Bulk actions registered: `recalculate` (attendance.process), `lock` (attendance.lock), `unlock` (attendance.unlock; requests unlock, maker-checker still applies).
