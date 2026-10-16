# Lifecycle: onboarding, offboarding, internal moves (Phase 7 core)

Base: `/api/v1/lifecycle`, module `lifecycle`, session + CSRF. Out-of-scope or cross-tenant records return 404. Lists are paginated Laravel pages.

## Permissions
`lifecycle.view`, `lifecycle.manage`, `lifecycle.templates.manage`, `lifecycle.moves.manage`, `lifecycle.moves.approve`, `lifecycle.clearance.manage`, `lifecycle.exit.view` (confidential), `lifecycle.alumni.manage`, `lifecycle.self` (default for Manager / Team member). Case, move and alumni access is also limited by data scope (`DataScope`).

## Templates (versioned)
| Method | Path | Notes |
| --- | --- | --- |
| GET | `templates?kind=` | templates with versions and tasks |
| POST | `templates` | `{kind: onboarding\|offboarding\|transfer\|promotion, name, conditions?, tasks[]}` creates v1 (draft) |
| GET | `templates/{uuid}` | |
| POST | `templates/{uuid}/versions` | next draft version; `{conditions?, tasks[]}` |
| POST | `template-versions/{uuid}/publish` | publishes; retires the previously published version; 409 if not draft |

Task: `{title, group_name?, description?, owner_type: manager\|hr\|it\|finance\|employee\|user, owner_user_id?, due_anchor: joining\|last_day\|effective, due_offset_days (-365..365), mandatory, clearance_dept?, requires_document}`.
Conditions (all optional, all must match): `country` (legal entity country), `legal_entity_id`, `worker_type`, `separation_type`. The most specific published version wins; ties go to the newest. Published versions are immutable; existing cases keep the tasks they were generated with.

## Cases and tasks
- `GET cases?kind=&status=`, `GET cases/{uuid}` (tasks, readiness), `POST cases {employee_uuid, kind: onboarding|offboarding, anchor_date?}`. One open case per employee and kind (idempotent).
- Case payloads return the employee as `{uuid, name, employee_number, status}` under `employee`; readiness is nested under `readiness`. The preboarder list returns `{employee_uuid, name, joining_date, case}`, where `case` is null or a case summary with nested readiness.
- `POST cases/{uuid}/tasks` ad-hoc task, `POST cases/{uuid}/reschedule {anchor_date}` (delayed joining: pending due dates shift), `POST cases/{uuid}/cancel {reason}`.
- `GET me/tasks`, `POST tasks/{uuid}/complete {note?, document_ref?, outcome?: done|skipped}`. Owner, or `lifecycle.manage` / `lifecycle.clearance.manage` (clearance items) in scope. `requires_document` tasks need `document_ref` (422 `document_required`); mandatory tasks cannot be skipped. A case completes when all mandatory tasks are done.
- Every task with an owner also appears in My Work (`source_module=lifecycle`, `source_entity=task:{uuid}`).
- Owners: `manager` = primary manager as of the anchor date, `employee` = the person's login, others = the named user or the first active `lifecycle.manage` holder. Unowned tasks are flagged by a data-quality rule.
- Readiness: `{total, done, percent, overdue, mandatory_pending, day_one_ready (onboarding), blockers[]}`.

## Triggers (domain events, no direct module calls)
- `RecruitmentHired` (Phase 6 handover) -> onboarding case anchored on the start date.
- `SeparationInitiated` -> offboarding case anchored on the last working date (+ pending confidential exit interview, retention `settings.lifecycle.exit_retention_years`, default 3).
- `SeparationWithdrawn` -> open offboarding case cancelled. `EmployeeSeparated` -> alumni record (People's separation listener already deactivates the login and closes assignments).

## Dashboard
`GET dashboard` returns `open_onboarding`, `open_offboarding`, `tasks_pending`, `tasks_overdue`, `blockers_by_owner`, `joining_soon`, `joining_not_ready`, and `pending_moves`. `GET preboarders` returns pre-joiner status and day-one readiness.

## Internal moves (maker-checker)
- `POST moves {employee_uuid, kind: transfer|promotion|manager_change|location_change, changes, effective_date, reason?}`; `changes` accepts assignment dimension ids (per kind) and `manager_uuid`. Response includes `impact` (leave / payroll / attendance / approvals / retroactive-period notes).
- `POST moves/{uuid}/decision {decision: approve|reject, note?}`: the requester cannot decide (403); already decided -> 409. Approved moves dated today or earlier apply immediately; future-dated moves apply from `lifecycle:housekeeping`.
- Move responses include nested `employee: {uuid, name}` and the compatibility fields `employee_uuid` / `employee_name`. `can_decide` is true only for an in-scope user with `lifecycle.moves.approve` who is not the requester; the decision endpoint still enforces these rules. Users with approval-only access receive a list constrained by their approval data scope.
- Applying uses People `AssignmentService::change` / `ReportingService::assign` (effective-dated, history kept), which emit `EmployeeTransferred` / `EmployeePromoted` for Leave (existing `PeopleEventListener`) and Payroll to consume. `Contracts\MoveGuard` (null implementation) lets attendance/leave/payroll veto retroactive moves. A transfer/promotion checklist case is generated if a published template exists.
- `GET moves`, `POST moves/{uuid}/cancel`.

## Offboarding
- `GET cases/{uuid}/clearance` -> `{cleared, departments[], blockers[]}`. Blockers are pending mandatory clearance tasks plus `Contracts\ClearanceProvider` implementations (assets, expenses, loans, leave, payroll are null providers until Phases 8-11; register real ones in `ClearanceRegistry`).
- `POST cases/{uuid}/settlement {currency, daily_rate?, salary_days?}` (`lifecycle.clearance.manage`): 409 `clearance_pending` with `blockers` until cleared; then calls `Contracts\SettlementInitiator` (default: Payroll `SettlementService::create`, which enforces its own permission and idempotency). Idempotent per case.
- Exit interview: `GET/PUT me/exit-interview` (own, `lifecycle.self`; answers encrypted, single submission), `GET cases/{uuid}/exit-interview` (`lifecycle.exit.view`, access audited). Case payloads never include answers. Answers are purged after `retention_until` by `lifecycle:housekeeping`.
- Alumni: `GET alumni`, `PUT alumni/{uuid}/eligibility {rehire_eligible, note?}` also updates People's separation record, which the existing rehire flow reads.

## Jobs (idempotent, per tenant)
`lifecycle:remind` (daily 07:00): reminders for tasks due within 2 days, escalation of overdue tasks to `lifecycle.manage` holders (once per task via `reminded_at` / `escalated_at`). `lifecycle:housekeeping` (daily 00:30): apply due approved moves, purge expired exit data.

## Registries
Action Center (`lifecycle.my_overdue`, `overdue`, `clearance_blocked`, `moves_pending`), Employee 360 "Lifecycle" section (facts only), data-quality rules (`lifecycle_preboarder_without_checklist`, `lifecycle_leaver_without_checklist`, `lifecycle_task_without_owner`, `lifecycle_separated_without_alumni`).

## Deferred
Task dependencies and conditional branches inside a template, contract renewal / extended-absence return cases, probation cases via templates, provisioning-request adapters, acknowledgments, secure form collection (only `document_ref` hook), bulk case assignment, SLA configuration per task, reports/export, production provisioning integrations, and real cross-module clearance providers.

## 2026-10-12 checklist dependencies and acknowledgments

- Template task input/output adds `depends_on` (array of one-based earlier task positions) and optional `acknowledgment_text` (up to 5000 characters). Forward/self references are rejected. Published templates remain immutable; generated cases copy dependencies to their own task instances.
- Template task output now includes `group_name` and `owner_user_id` for faithful version editing. Named owners must be active users in the current workspace. `GET task-owners?q=...` requires `lifecycle.templates.manage`, returns at most 50 active workspace users with id/name, and supports name search.
- Task responses expose `blocked_by` (pending prerequisite UUID/title pairs), `acknowledgment_text` and `acknowledged_at`. Completion returns 409 until prerequisites are done or skipped. A statement-bearing task requires `acknowledged: true`, outcome `done`, and the assigned owner as actor; another actor receives 403 even with management permission.
- Template conditions add boolean `is_rehire`; true matches employment_sequence greater than one. EmployeeRehired generates onboarding with source `rehire` and existing open-case idempotency. First-employment/rehire selection is available in the template editor.
- These dependency/acknowledgment settings currently apply to template-generated tasks, not ad-hoc task creation. An acknowledgment is a recorded statement, not a certified electronic signature.
- Corrections to the earlier Deferred section: task prerequisites and acknowledgments now exist. Operations registers asset/expense clearance providers; loans/leave/payroll remain fallback providers. Conditional branches, task SLAs, secure collection, bulk assignment, exports and production provisioning remain pending.

Template task timing: `remind_before_days` accepts 0-365 (default 2); `escalate_after_days` accepts 1-365 (default 1). Both are copied into case tasks. `lifecycle:remind` sends the reminder once during the configured pre-due window and escalates once when the configured number of calendar days overdue is reached. Existing rescheduling resets notification stamps. This supersedes the fixed two-day window above; advanced SLA routing/business calendars remain deferred.

## Resignation requests

Under `/api/v1/lifecycle`:
- `GET me/resignation-employments` returns own eligible employments; `GET me/resignations` returns own paginated request history. Both require lifecycle.self.
- `POST me/resignations {employee_uuid, requested_last_day, reason, confirmed:true}` requires lifecycle.self; only own eligible employment, date on/after today and joining, no active request/separation. Does not change employment status.
- `POST me/resignations/{uuid}/withdraw {reason, confirmed:true}` withdraws pending requests immediately; approved requests become withdrawal_pending while the linked separation remains open.
- `GET resignations` requires people.separate and applies its employee data scope. Responses include encrypted-at-rest reason/note fields for authorized viewers, can_decide and can_withdraw. Lists are paginated at 25 records.
- `POST resignations/{uuid}/decision {decision:approve|reject, expected_status:pending|withdrawal_pending, note}` requires people.separate with data scope, an independent reviewer, and matching current state. Approval starts the existing SeparationService/offboarding process; approved withdrawal reverses the linked open separation. A stale decision is 409. Original decision/withdrawal state changes are audited; the response exposes the latest decision note.
- Proposed dates cannot be changed through approval; reject and request resubmission when necessary. Completed separations cannot be withdrawn here. Direct People separation completion/withdrawal is reflected in response status; housekeeping closes obsolete My Work reviews.
- Reviewer work tasks contain generic text and link to the scoped review queue. Advanced notice-policy calculation, substitute approvers and country-specific legal acceptance are not implemented.

## Return from extended absence

All routes require people.manage, its employee data scope, and the Lifecycle module:
- GET /api/v1/lifecycle/returns/employees?q=... : up to 50 own-scope employees currently on long_leave, optional name search.
- GET /api/v1/lifecycle/returns : paginated requests with can_decide and case_uuid.
- POST /api/v1/lifecycle/returns {employee_uuid,note} : request return; duplicate pending requests refused. Does not change employment status.
- POST /api/v1/lifecycle/returns/{uuid}/decision {decision:approve|reject,note,confirmed:true} : independent reviewer only. Approval verifies the original status-history snapshot and matching published absence_return template, returns the employee on today's employee-local date via StatusService, and generates a checklist. Missing template -> 422; stale/already decided -> 409. Reject stale requests before submitting replacements.
- Reviewer tasks are scoped and completed on decision. Request and decision notes are encrypted. Medical details should not be collected in operational notes. Approval does not adjust leave balances or payroll, certify medical fitness, or schedule a future return.
