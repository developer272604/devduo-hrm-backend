# Workflow and analytics API

All routes are under authenticated tenant sessions, CSRF and the existing API throttle. Module switches and endpoint permissions are checked server-side.

## Workflows — /api/v1/workflows

- GET/POST definitions: paginated definition list or create {name,spec}.
- GET/PUT definitions/{uuid}: definition plus published history, or edit {name,spec,revision}. Stale revisions return 409.
- POST definitions/{uuid}/publish: {revision}; a different tenant-scoped workflows.publish administrator must publish.
- GET options: up to 100 scoped employee numbers and active workflow participants. Optional search matches employee number.
- POST simulate: {spec,employee_uuid}; returns step routing, conditions and blockers, with side_effects=false.
- POST instances: {definition_uuid,employee_uuid,idempotency_key}; requires tenant-scoped workflows.run and People access. Duplicate identical requests return the existing instance.
- GET instances: paginated accessible instances; optional status filter.
- GET instances/{uuid}: current step, assignments and immutable trace.
- POST instances/{uuid}/decide: {decision:approved|rejected,reason}. Only a currently eligible pending assignee can decide.
- GET instances/{uuid}/delegates: only eligible users who have not participated in the step.
- POST instances/{uuid}/delegate: {from_user_id,to_user_id,reason}; requester must own the pending assignment or hold tenant-wide workflow management permission.
- POST instances/{uuid}/control: {action:pause|resume|cancel|retry,reason}; tenant-scoped manager only.
- GET deliveries: paginated pending/retrying/dead-letter delivery monitor.
- POST deliveries/{id}/retry: {reason}; tenant-scoped manager requeues a failed delivery.

A spec contains steps (1–25) and an optional trigger. Each step has title, action (approval/task/notify/wait), assignment (fixed/manager), assignees (array of up to 10 tenant user IDs), mode (all/any), timeout_hours (1–8760), optional worker_type and optional escalation_assignees/escalation_hours. One escalation hop occurs after the primary deadline; targets are rechecked for current independent access and cannot have participated already. Unknown step fields and arbitrary action names are rejected; loop edges are not supported.

Trigger types:
- manual (default).
- event with one of the employee-linked types exposed by `GET /api/v1/workflows/options` (`event_types`); only events after publication are eligible.
- `EmploymentStatusChanged` requires `trigger.status` (one of the People employment statuses) and starts only when the event's new `to` status matches it.
- schedule with employee_uuid, at (ISO timestamp with explicit offset) and optional interval_hours (0 for once, 1–8760 to repeat).

All task effects occur in the same transaction as workflow state. No arbitrary tenant code, database mutations, financial transfers or outbound URLs are executable actions.

## Analytics — /api/v1/analytics

- GET metrics: metric definitions allowed by current source permissions and enabled modules.
- POST query: {metric,from,to,dimension}; returns aggregates, suppression state, formula, lineage, refresh timestamp, quality and limitations.
- GET/POST reports: list own saved reports or save {name,spec}.
- POST reports/{uuid}/run: reruns own report using current permissions and current source data.
- DELETE reports/{uuid}: delete own saved criteria.
- GET/POST export: same criteria as query; requires analytics.export and analytics.view plus source permission; audited no-store CSV.
- POST scenarios: {from,to,hires,leavers}; an unsuppressed headcount baseline is required.

Metrics: headcount, hires, leavers, attrition, tenure, overtime, absence, learning_completions, leave_utilization, recruitment_pipeline, performance_status, ticket_sla, payroll_totals.
Dimensions: total; headcount/tenure also allow department, location, legal_entity. There are no arbitrary SQL expressions or sensitive demographic dimensions.

Bounds: at most 367 inclusive days ending no later than tenant-local today, 10,000 scoped employments, 100 groups, and a cohort threshold of five. Suppressed values/numerators/denominators are null. A quality-blocked result has no rows and explains the source repair needed.

Published metric definitions are code-owned version 1. Saved criteria are not permission grants. Internal result snapshots are immutable and not publicly retrievable without a new query.

