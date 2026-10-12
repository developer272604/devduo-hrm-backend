# Leave requests, approvals, comp-off, team availability (Phase 4, workstream C)

Base: `/api/v1/leave` (module `leave`). Auth: session. Every endpoint is tenant-isolated, permission-gated and data-scoped; anything the viewer may not see is **404** (never 403) except the permission middleware's 403. Public ids are UUIDs. Units are 4-dp decimal **strings** (days; hours for `unit: hour` types). Code: `app/Domain/Leave/{Duration,Requests,Restrictions,CompOff,Integration}`.

Errors: validation = 422 `{message, errors}`. A request that cannot proceed = 422 `{message, errors:{request:[...]}, conflicts:[...], duration:{...}}`. Ledger refusals keep their status (422 `insufficient_balance`, 423 locked period, 409).

## Request body (preview / submit / modify)

| Field | Notes |
| --- | --- |
| `leave_type` | leave type uuid (required) |
| `start_date`, `end_date` | `YYYY-MM-DD` (end defaults to start) |
| `portion` | `full_day` (default) `first_half` `second_half` `quarter` `hours`; allowed set depends on the type unit (day: full only; half_day: +halves; quarter_day: +quarter; hour: all) or `limits.allowed_portions` |
| `start_time`, `end_time` | `HH:MM` local, for `hours`; end earlier than start = crosses midnight |
| `timezone` | optional IANA zone (default: shift/employee zone) |
| `reason`, `handover_notes`, `delegate_employee` (employee uuid) | optional (reason required when setting `requests.require_reason`) |
| `employee` | employee uuid: submit on someone's behalf (`leave.manage`, data scope) |
| `documents[]` | multipart files (5 MB, content-checked, stored privately) |
| header `Idempotency-Key` | replay returns the same request (200, `duplicate:true`) |

## Endpoints

| Method + path | Permission | Purpose |
| --- | --- | --- |
| `POST requests/preview` | `leave.request` | **Impact preview**, writes nothing: `{can_submit, duration{valid,charged_units,calendar_units,segments[],explanation[],warnings[],errors[]}, conflicts[], balance{tracked,accounts[{available_before,requested,projected,negative_limit,sufficient}]}, approval{roles,steps,escalation_reasons,first_approvers[]}, documents[], team_impact[{date,team_size,away_already,available_after}], statutory}`. Add `modifies: <request uuid>` to preview a modification. |
| `POST requests` | `leave.request` | Submit. 201 `{data: detail}`. Reserves balance per charged day (ledger `pending`), creates My Work task(s) for the approver(s), records conflicts. |
| `GET requests?scope=mine\|team\|all&status=&from=&to=&employee=&page=&per_page=` | `leave.view\|leave.team.view\|leave.manage` | List. `team` needs `leave.team.view`, `all` needs `leave.manage`; both limited to data scope. |
| `GET requests/{uuid}` | view/team.view/approve/manage | Detail: segments (per-day explanation), `explanation[]`, `approval{roles,escalated}`, `conflicts[]`, `history[]` (every action), `documents[]`, `ledger[]` (+ source lots; owner/HR only), `children[]` (modifications). |
| `POST requests/{uuid}/withdraw` | `leave.request` | Pending only; releases all holds. |
| `POST requests/{uuid}/cancel` `{dates?[], reason?}` | `leave.request\|approve\|manage` | Cancel everything or only `dates` (partial). Pending: holds released. Approved: consumption reversed, Attendance recalculated. Employee cancels own future days; past days, or cancelling for someone else, need HR / manager and a `reason`. |
| `POST requests/{uuid}/modify` | `leave.request` | Controlled modification of **approved** leave: creates a NEW pending request (`modification_of`); the original stays valid until the new one is approved, then it becomes `modified` and is reversed in the same transaction. |
| `POST requests/{uuid}/documents` (multipart `file`, `kind?`) | `leave.request\|manage` | Attach evidence later (resolves "submit later" document warnings). |
| `GET requests/{uuid}/documents/{docUuid}` | view... | Download. Sensitive documents: 404 unless the viewer may see medical detail. Every sensitive view is audited. |
| `GET approvals` | `leave.approve\|manage` | Inbox: pending requests this user can decide now (delegates included). |
| `POST requests/{uuid}/approve` `{comment?}` | `leave.approve\|manage` | Approve the current step; the last step commits the ledger (`scheduled` for future days, `consumed` for past days) and tells Attendance. |
| `POST requests/{uuid}/reject` `{comment}` | same | Comment required; holds released. |
| `GET comp-off?scope=mine\|team&status=` | `leave.view\|leave.compoff.manage` | Comp-off credits with `remaining` and `claims[]`. |
| `POST comp-off` `{source_type, source_id?, work_date?, units?, note?, employee?}` | `leave.request\|compoff.manage` | Earn: `overtime`/`holiday_work`/`rest_day_work` derive units and date from an **approved** overtime request (`source_id` = overtime uuid; minutes / minutes_per_day); `authorized` (HR, `leave.compoff.manage`) grants directly with `units`. One credit per source. |
| `POST comp-off/{uuid}/approve` / `reject` | `leave.approve\|compoff.manage` | Approve posts a ledger credit lot (`comp_off_credit`) valid `comp_off.default_validity_days` from the work date (`expires_on`). |
| `POST comp-off/{uuid}/claim` (leave request body) | `leave.request` | Spend a credit through an ordinary leave request (type = the credit's); refused beyond `remaining` or after `expires_on`. |
| `GET comp-off/{uuid}/trace` | view/compoff.manage | Source-to-use: `{source{type,id,work_date}, units, expires_on, uses[{request,status,date,units}]}`. |
| `GET calendar/me?from&to` | `leave.view` | Own leave days + own holidays (max 62 days). |
| `GET team/calendar?from&to&include_pending&employees[]` | `leave.team.view` | Who is away: one entry per request `{request, employee, type{name,color}, status pending\|approved, start_date, end_date, dates[], portion}`. Sensitive types appear as "Leave". |
| `GET team/availability?date` | `leave.team.view` | `{headcount, away, available, available_percent, away_list[], holidays[]}` for the viewer's scope. |

Request summary fields: `uuid, employee, type{uuid,name,code,color,sensitive}, status (pending|approved|rejected|withdrawn|cancelled|partially_cancelled|modified), start_date, end_date, start_time, end_time, portion, unit, requested_units, charged_units, projected_balance, reason, reason_hidden, delegate, handover_notes, current_step, total_steps, current_approver, confidence, warnings[], submitted_at, decided_at, decided_by, modification_of, can_decide, can_withdraw, can_cancel, can_modify`.

Conflict `kind`: `overlap` (always block) `attendance` `on_duty` `roster` `availability` `eligibility` `blackout` `staffing` `document` `balance` `notice` `consecutive`; `severity`: `info | warning | escalate | block`.

## Behaviour

* **Duration** (`Leave\Contracts\DurationCalculator` -> `Duration\DurationEngine`): day facts from Attendance `ScheduleResolver` (roster, shift, work week, holiday calendar, employment, timezone as of each date). Weekly offs and holidays are not charged; **sandwich** (`rules.sandwich {enabled, apply_to[], count_holidays, count_weekly_offs}`) charges them when they lie between leave days of the request or touch neighbouring pending/approved leave; half-day holidays charge only the other half; `calendar_days` types count every day. Hourly leave counts real elapsed time (DST aware, flagged) and splits across midnight (unless inside one night shift). Limits come from `rules.limits.{min_units,max_units,min_consecutive,max_consecutive,notice_days,backdate_days,future_days,allowed_portions,day_counting}` (tenant `requests.*` settings as fallback). **Statutory** types never fail on these: the violation becomes an `escalate` warning and HR joins the approval.
* **Approvals**: `Leave\Requests\LeaveApprovers` implements `LeaveApproverResolver` on top of Attendance `ApproverResolver` kind `leave` (-> `leave.approve`); steps `rules.approvals.steps` (`manager`, `hr`), extra HR step when a conflict escalates, the request is longer than `approvals.hr_above_units`, or `approvals.hr_for_sensitive`. Active delegations apply. Self-approval and approval by the person who raised it on someone's behalf are impossible. After `approvals.escalate_after_hours` (command `leave:maintain-requests`, hourly) HR is also asked (once).
* **Ledger**: one `pending` reservation per charged day (key `req:{uuid}:seg:{id}:reserve`), commit with source-lot allocations, reversal on reject/withdraw/cancel. Locked periods refuse (423).
* **Attendance**: `Integration\LedgerLeaveDataProvider` replaces the null provider (approved, active, charged segments only; no reasons/types). Approve/cancel record `leave.coverage.changed` (uuids + dates) and call `AttendanceRecalculator::recalculateDay` after commit. `LeaveUnavailabilityProvider` feeds roster planning ("Approved leave").
* **Medical privacy**: sensitive types hide type name, reason, documents and history comments from everyone but the employee, `leave.medical.view` holders in scope, and managers only if `privacy.managers_see_medical` / `managers_see_reason` is on. My Work titles and events never name a sensitive type or reason.
* **Restrictions** (`leave_restrictions`): `kind` `blackout`, `max_concurrent {max, group_by}`, `min_staffing {min_available|min_percent, group_by}`, `notice {days}`, `consecutive {max_days}`, `eligibility {min_tenure_days}`; `action` warn|block|escalate; `exempt_statutory` + `restrictions.never_block_statutory` downgrade a block on statutory leave to `escalate`. Eligibility (`rules.eligibility`) and `leave_document_rules` are evaluated at request time.
