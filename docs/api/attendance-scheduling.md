# Attendance scheduling API

Base path: `/api/v1` (session + CSRF, tenant-scoped, module `attendance`). UUIDs identify shifts, work weeks, calendars, rotations, rosters, swaps and employees. Dates are `Y-m-d`; clock times are `HH:MM` in the **shift's timezone**. Lists use Laravel pagination (`data`, `current_page`, `last_page`, `total`).

## Errors
| Status | Meaning |
| --- | --- |
| 403 | missing permission, or (swaps) not the approver |
| 404 | not found, other tenant, or employee outside your data scope (never 403 for scope) |
| 409 | `{code}`: `shift_in_use`, `roster_warnings` (resend with `acknowledge_warnings=true`), `roster_changed` (stale `expected_version`), `awaiting_counterpart`, `swap_closed`, `assignment_started`, `version_started`, `work_week_in_use`, `calendar_in_use`, `rotation_in_use` |
| 422 | validation: `{message, errors:{field:[text]}}`; or coded `{message, code, ...}`: `ambiguous_assignment`, `ambiguous_group_assignment`, `roster_conflicts` (`conflicts[]`), `swap_violates_rules` (`conflicts[]`), `nothing_to_publish` |
| 423 | attendance for that employee-date is locked (from the lock guard) |

Conflict object: `{employee_id, employee, date, code, rule, layer: global|country|tenant, severity: warning|action_required, level: flag|block, message}`. Codes: `overlap`, `insufficient_rest`, `outside_employment`, `invalid_location`, `duplicate_assignment`, `unavailable`, `max_daily_hours`, `max_weekly_hours`, `weekly_rest`, `overtime_limit`, `break_requirement`.

## Shifts  (read: attendance.view; write: attendance.policy.manage)
| Method | Path | Notes |
| --- | --- | --- |
| GET | `/shifts?search=&kind=&active_only=` | paginated |
| POST | `/shifts` | body: `name, code, kind(fixed\|flexible\|split), timezone, start_time, end_time, segments[{kind:work\|break, paid, start, end}], minimum_minutes, half_day_minutes, full_day_minutes, punch_window_before/after, flex_start_from/to, core_start/end, required_minutes, overtime_eligible, rules{PolicyRules keys + weekly_off_days[], allowed_location_ids[]}, color_token, active`. `crosses_midnight` and `duration_minutes` are derived. |
| GET/PATCH/DELETE | `/shifts/{uuid}` | PATCH changing timing of a shift in use returns 409 `shift_in_use` unless `confirm_in_use_change=true`; DELETE of a used shift is 409 (deactivate instead) |

## Work weeks
`GET /work-weeks`, `GET /work-weeks/{uuid}`, `POST /work-weeks` (`name, code, effective_from, preset(mon_fri\|mon_sat\|sun_thu\|mon_fri_sat_half)` or `days` = map weekday(1-7) -> `full\|half\|off\|custom` or `{type, minutes}`), `PATCH /work-weeks/{uuid}` (metadata; plan only before it starts), `POST /work-weeks/{uuid}/versions` (`effective_from` + new days: closes the previous version the day before), `DELETE`. Writes need `attendance.policy.manage`.

## Holiday calendars
`GET/POST /holiday-calendars`, `GET/PATCH/DELETE /holiday-calendars/{uuid}` (`?year=` on show), `POST .../copy` (`name, code, source_year?, target_year?`), `POST .../generate` (`year`; idempotent), `POST .../holidays` (`date, name, type(mandatory\|optional\|restricted), portion(full\|first_half\|second_half), description, recurrence{rule:fixed,month,day}\|{rule:nth_weekday,month,weekday,nth(-1=last)}`), `PATCH/DELETE .../holidays/{id}`.
`GET /employees/{uuid}/holidays?from=&to=` (own record, or attendance.team.view / roster scope) returns `[{date,name,type,portion,calendar,weekly_off}]` resolved through the employee's calendar assignments as of each date (Leave consumes this).

## Rotations
`GET/POST /shift-rotations`, `GET/PATCH/DELETE /shift-rotations/{uuid}`. Body: `name, code, cycle_length, days[]` (one entry per day: shift uuid or `null` for a day off). Day index on a date = `((daysBetween(anchor,date) mod N)+N) mod N + 1`.

## Assignments  (attendance.roster.manage)
Three kinds with identical shape: `/shift-assignments`, `/work-week-assignments`, `/holiday-calendar-assignments` (`GET` list with `target_type,target_id,active_on`; `POST`; `PATCH /{id}`; `DELETE /{id}`).
Body: `target_type` (employee, team, department, employee_group, business_unit, location, employee_type, legal_entity, country, tenant), `target_id` (employee id/uuid, org id, ISO country, omitted for tenant), `effective_from`, `effective_to?`, `priority`, `is_override`, `note`, and `shift_uuid` or `rotation_uuid + rotation_anchor` / `work_week_uuid` / `calendar_uuid`. `supersede=true` ends covering same-target rows the day before. Id in the path is the uuid for shift assignments, numeric otherwise.
Specificity (high to low): employee 100, team 80, department 70, employee_group 60, business_unit 50, location 40, employee_type 30, legal_entity 20, country 10, tenant 0; then override, priority, effective_from, id. Ambiguous configurations are rejected (422). Non-employee targets need workspace-wide scope. Started assignments can only be ended (`effective_to`).

## Rosters
| Method | Path | Permission |
| --- | --- | --- |
| GET/POST | `/rosters` (`name, start_date, end_date, scope_type, scope_id`) | attendance.roster.manage |
| GET/PATCH | `/rosters/{uuid}` | roster.manage |
| GET | `/rosters/{uuid}/grid?view=day\|week\|month&date=&from=&to=&search=&page=&per_page=&employee_uuids[]=` | roster.manage (rows limited to data scope). Cell: `{kind, source, shift{code,name,start,end,color}, holiday, state: draft\|published\|changed\|derived, draft{type,shift,reason}}` |
| POST | `/rosters/{uuid}/entries` `{employee_uuid, date, entry_type: shift\|off, shift_uuid, reason, expected_version?}` | reason required when the day is already published |
| POST | `/rosters/{uuid}/entries/clear`, `/entries/discard` | |
| POST | `/rosters/{uuid}/bulk-assign` `{employee_uuids[], from, to, weekdays?, entry_type, shift_uuid, preview=true, acknowledge_warnings}` | preview returns `{cells, conflicts, locked}` without writing |
| POST | `/rosters/{uuid}/copy` `{employee_uuids[], source_from, target_from, days}` | |
| POST | `/rosters/{uuid}/validate` | returns `{conflicts, blocking, count}` |
| POST | `/rosters/{uuid}/publish` `{acknowledge_warnings, expected_version?}` | attendance.roster.publish. 422 `roster_conflicts` for block-level, 409 `roster_warnings` otherwise |
| POST | `/rosters/{uuid}/unpublish` `{reason}` | roster.publish; published rows become `superseded` and return as drafts |
| GET | `/rosters/{uuid}/changes` | drafts that replace published rows |
| GET | `/rosters/{uuid}/history?employee_uuid=&date=` | `[{previous_shift,new_shift,changed_by,changed_at,reason,effective_date,status}]` |

## Shift swaps  (employee actions need attendance.view; decision needs attendance.approve)
`POST /shift-swaps` `{type: swap\|change\|alternate, work_date, counterpart_uuid (swap), to_shift_uuid (change/alternate), reason}` -> `GET /shift-swaps`, `GET /shift-swaps/{uuid}`, `POST .../respond {accept, comment}` (counterpart), `POST .../withdraw` (requester), `POST .../decision {approve, comment}` (approver or active delegate). Settings in `settings.attendance.swap`: `enabled, min_notice_hours (24), max_per_month (4), same_team_only, same_department_only (true), same_location_only`. Status: `pending -> counterpart_accepted -> approved | rejected | withdrawn`. Approval writes published roster entries for both employees atomically.

## Schedule views
* `GET /attendance/me/schedule?from=&to=` (<= 62 days): `{today, next, days[{date, kind, weekly_off, holiday, changed, location, shift{code,name,timezone,start,end,start_local,end_local,viewer_timezone,crosses_midnight}}]}`.
* `GET /attendance/team/schedule?from=&to=&page=` (attendance.team.view, data scope): `[{employee, days{date: CODE\|OFF\|HOL\|-}}]`.

## Compliance and impact
* `GET/PUT /attendance/compliance`: `{enforcement: flag\|block\|off, enabled, countries{XX:{enabled, rules{...}}}}`. Packs ship in `config/hrm_compliance.php` (illustrative, not legal advice); tenant overrides live in `compliance_rule_overrides`.
* `POST /attendance/impact-preview` `{type: shift_assignment\|roster_change\|holiday\|work_week, change:{...}}` -> `{affected_employees, dates_to_recalculate, requires_confirmation, message}`; nothing is written.
* `GET/POST/PATCH/DELETE /payroll-groups` (`name, code, cutoff_day, active`).
