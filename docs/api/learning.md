# Learning API (Phase 9 lean core)

Authenticated and tenant-scoped under `/api/v1/learning`; all routes require the `learning` module. Tenant ownership comes from the server. Course drafts are only returned to catalog managers; employees see published catalog entries.

## Catalog and paths

- `GET courses?search=&delivery=&per_page=` — paginated course summaries. `GET courses/{uuid}` — course detail including prerequisites, granted skills and versions.
- `POST courses` / `PATCH courses/{uuid}` — catalog manager fields: code, title, description, delivery (`internal|external|online|classroom`), provider, duration, cost, currency, capacity, approval flag, pass/attendance thresholds, certification code, skill grants and prerequisite UUIDs.
- `POST courses/{uuid}/status {status: published|retired}` — publish or retire.
- `GET paths` — published paths with current employee progress; managers also see drafts. `POST paths {name,description?,items:[{course_uuid,required?}]}` — create a path.
- `GET courses/{uuid}/sessions`; `POST courses/{uuid}/sessions {code,starts_on,ends_on,location?,instructor?,capacity?,waitlist_enabled?}` — list or schedule sessions.

## Employee learning and skills

- `POST courses/{uuid}/enrol {session_uuid?}` — self-enrol. The API rejects duplicate active/completed enrolments and unmet prerequisites. Approval-required courses enter `pending_approval`; a full session enters the waitlist when enabled.
- `GET my` — current employee's enrolments and course summaries.
- `GET skills`, `GET skill-levels`, `GET my/skills` — active skill list, proficiency scale, self profile/history and current-role gap analysis.
- `POST my/skills {skill_uuid,level,note?}` — self-declare proficiency. Self declarations remain unverified; managers validate through the domain service when that workflow is exposed.

## Access

Routes apply the `learning.*` permissions and tenant scope. Self-service endpoints use the employee record linked to the signed-in person; cross-tenant course and session IDs resolve as not found. Course creation, editing, publication, session scheduling and path creation require `learning.catalog.manage`.

## Assignment, enrolment decisions, attendance, completion (`learning.enrol.manage` / `learning.enrol.approve`)

- `GET lookups` — pickers: employees in scope, employee groups (`id`), designations, departments, legal entities.
- `GET enrolments?status=&course_uuid=&employee_uuid=` — enrolments of people in the viewer's data scope (paginated).
- `POST assign/course {course_uuid, employee_uuids[]?, group_id?, session_uuid?, due_on?}` and `POST assign/path {path_uuid, employee_uuids[]?, group_id?, due_on?}` — returns `{assigned:[uuid], skipped:[{employee_uuid, reason}]}`. Any employee outside the caller's scope makes the call 404; duplicates and unmet prerequisites are skipped; session capacity/waitlist rules match self-enrolment; past due dates are rejected.
- `POST enrolments/{uuid}/decision {decision: approve|reject, note?}` — approval-required courses. The requester and the employee can never decide; only the routed approver (primary manager/delegate, else HR) or a tenant-wide approver. Reject needs a reason. Self-enrolment now creates a My Work task for the routed approvers; the decision closes it.
- `POST enrolments/{uuid}/attendance {session_date, present}` — updates `attendance_pct`. `POST enrolments/{uuid}/complete {score?, passed?}` — enforces minimum attendance and pass mark, grants course skills (`source=training`), closes matching compliance assignments and issues the certificate. Nobody records their own completion.

## Compliance training (`learning.assign.manage`; reading status needs `learning.view`)

- `GET|POST compliance/rules`, `PATCH compliance/rules/{uuid}` — `{name, course_uuid, designation_id?, department_id?, legal_entity_id?, country?, due_days, recurrence_months?, escalation_days, active}`.
- `POST compliance/run` — same work as the scheduled command `learning:compliance-run`: assigns due courses (unique rule+employee+cycle, so re-runs add nothing) and escalates overdue items.
- `GET compliance/status?status=` (per-employee overdue/due_soon/in_progress/compliant plus summary), `GET compliance/assignments`, `POST compliance/assignments/{uuid}/waive {reason}`, `GET my/assignments`.
- Overdue handling: My Work task + notification for the employee (notice at most once a day); after `escalation_days` a one-time My Work task and notification for the manager (HR fallback).

## Assessments

- `POST|GET courses/{uuid}/assessments` (`learning.catalog.manage`) — author view includes the answer key: `{title, pass_percent?, max_attempts? (null = unlimited, default 3), questions:[{text, options[], correct[] (indexes), points?}]}`.
- `GET courses/{uuid}/assessment` (`learning.self`, needs an enrolment) — taker view; **never contains the answer key**. `POST assessments/{uuid}/attempts {answers:{q1:[1],q2:[0,2]}}` — server-side all-or-nothing scoring per question, decimal percent, attempt limit enforced under an enrolment row lock (422 once exhausted). The response returns per-question correct/incorrect only after submission, `attempts_remaining`, and `course_completed` (a pass completes the enrolment unless minimum attendance is still unmet, in which case `note` explains).

## Certificates

- `GET|POST certifications`, `PATCH certifications/{uuid}` — `{code, name, issuer?, validity_months?, renewal_course_uuid?, reminder_days[] (default 60,30,7), active}`. A course links to one by `certification_code`.
- Issued on completion/pass (idempotent per enrolment); a new issue supersedes earlier ones (`renewed`). `GET my/certificates`, `GET certificates?status=` (scoped by `learning.view`), `GET certificates/{uuid}/print` (printable HTML, escaped, self or in-scope viewers).
- `learning:certificate-reminders` (daily): marks expired certificates once, sends one reminder per threshold crossed (`last_reminder_days`), My Work renewal task via `createOnce`.

## Skills administration and validation

- `GET|POST skill-categories`, `PATCH skill-categories/{uuid}`; `POST skills`, `PATCH skills/{uuid}` (`GET skills?include_inactive=1` for administrators); `PUT skill-levels {levels:[{rank,name,description?}]}` (ranks contiguous from 1, a level in use cannot be removed) — all `learning.skills.manage`.
- `GET|PUT skill-requirements`, `DELETE skill-requirements/{uuid}` — designation skill requirements.
- `POST employees/{uuid}/skills/{skill_uuid}/validate {level, evidence:{note (min 5), kind?, reference?}}` (`learning.skills.validate`; scope-checked, never your own skills), `GET employees/{uuid}/skills/{skill_uuid}/evidence`, `GET employees/{uuid}/skills` (self or `learning.view`), `GET employees/{uuid}/skill-gap`, `GET skill-gaps` (team aggregate).

## Dashboards and export

- `GET dashboard` (`learning.reports.view` or `learning.view`) — team/tenant scope per DataScope: enrolment counts, completions, pending approvals, overdue, compliance %, expiring certificates, top skill gaps, cost per currency (decimal-safe strings; `cost:null, cost_masked:true` without `learning.reports.view`).
- `GET command-center` — organization-wide (`learning.reports.view` with tenant-wide scope): adds catalog health, popular courses, upcoming sessions with fill, budgets vs committed spend.
- `GET reports/export` (`learning.reports.export`) — CSV; cost column is `***` unless the exporter has tenant-wide scope; formula-leading cells are neutralised; the export is audited.

## Integrations

Employee 360 "Learning" section (facts only), Action Center insights (`learning.my_overdue`, `team_overdue`, `certs_expiring`, `enrolments_pending`) and data-quality rules (`learning_rule_course_unavailable`, `learning_assignment_inactive_employee`, `learning_requirement_inactive_skill`) are registered through `LearningBoot`. Every endpoint (reads of other people's data included) writes an audit entry. The `Manager` role now holds `learning.enrol.manage` (grant migration `2026_10_29_000002`), limited by its direct-reports data scope.

## Still deferred

Training requests/budget administration endpoints (tables exist; budgets are only read in the command center), course feedback, development-plan linkage, waitlist auto-promotion on cancellation and a session-cancel endpoint, content-provider integrations, course-form UI for certification/assessment authoring (API only).
