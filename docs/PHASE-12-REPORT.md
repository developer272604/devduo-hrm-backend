# Phase 12 — Workflow and automation engine

Status: lean core implemented and verified; **partial against the full master specification**.

## Delivered

- Structured designer with draft revisions, stale-edit rejection, independently published immutable versions and published history. Existing instances retain their version. Designers may configure up to ten deadline escalation recipients and a second deadline.
- Bounded sequential steps, parallel all/any assignments, worker-type conditions, manager routing with a missing-manager fallback, task completion, approvals, notifications and wait timers.
- Manual/API starts with tenant-wide idempotency keys. Reusing a key with a different subject, definition or initiator is rejected.
- Sixteen employee-linked event triggers and one-time/repeating schedules. Employment-status triggers can filter on the target status. Published event watermarks exclude earlier events. Durable database delivery queue with source deduplication, bounded polling, exponential retry delays and a dead-letter state.
- Shared My Work tasks and notifications, not a second inbox. Workflow tasks cannot be manually completed through the generic task API. Native module approval cases continue using their existing services.
- Row-locked decisions, initiator and assignee permission checks, no self-approval, scope checks at execution, pending assignment delegation, one bounded escalation hop, pause/resume/cancel and bounded step retry.
- Immutable execution trace, audit entries, failed-instance filters, automatic delivery recovery with a recorded reason.
- Dry-run preview resolves routing and conditions without creating instances, tasks, notifications or execution logs.
- Module switch, permissions, sidebar navigation and four supporting workflow screens/sections.

## Operational contract

Run Laravel's scheduler: `workflows:advance` is scheduled every minute. It processes at most 200 source events per definition, 100 due deliveries per tenant and 200 due instances per tenant per invocation. Missed recurring slots coalesce into one delivery; the next interval is measured from processing time. Published-version changes supersede queued deliveries from an older version.

Automatic workflows execute under the publisher's current permissions. Manual workflows execute under the initiator's current permissions. Simulation previews the current caller's routing; a different automatic publisher can produce a different authorization result. Approval deadlines continue during pauses, so resuming an overdue approval sends it to recovery. Missing manager fallback is used only if no manager login resolves.

Step retries are explicitly requested by an administrator; after three unsuccessful retries an instance reaches dead-letter and can be cancelled. Delivery authorization/source failures retry automatically after 2, 4, 8 and 16 minutes, stopping after the fifth failed attempt. An authorized administrator can requeue a failed delivery with a reason after correcting its cause. Unexpected database failures roll back the delivery transaction and remain available for a later scheduler run.

Times follow the application's configured database timestamp convention. Explicit schedule offsets are converted to that convention before comparison. Tenant-local dates govern manager relationship resolution.

## Remaining full-spec work

- Status/threshold triggers and adapters for source modules that do not yet emit a supported employee-linked event.
- Migration of existing module-specific approvals into a shared configurable routing contract. Existing approvals have intentionally not been silently replaced.
- Richer conditional branches and configurable multi-level escalation. Current escalation supports one published hop; delegation is per assignment and checks current independent approval scope.
- Authorized domain-update, document generation, outbound webhook and integration actions, secrets references and associated network failure tests.
- High-volume queue-worker deployment, rate-limit tuning, operational alerting, and true multi-connection concurrency validation on MariaDB.
- Visual graph editor and richer SLA dashboards. Current designer is structured and acyclic by construction.

The full Phase 12 gate is not met while those items remain.

## Verification and rollout

Workflow feature suite: 9 tests. Combined Workflow, Analytics, Operations and Foundation run: **46 tests / 433 assertions passed**. Frontend automation and operations suites: **9 tests passed**.

Production frontend build passed; Vite retains the existing large JavaScript chunk advisory.

New migrations: `2026_10_22_000001`, `2026_10_23_000001`, `000002`, `000003`. They ran in isolated SQLite tests, not shared development MariaDB. The shared permission migration enables workflows and analytics for existing tenants and grants the default-role permissions. Production concurrency and deployment remain separate rollout checks.

Continuation update (2026-10-11): assignment delegation and one bounded deadline-escalation hop are now implemented, including independent scope/approval checks, task reassignment, trace events and the instance UI. PHP syntax and route registration checks passed; the frontend production build passed. The earlier feature-suite results above predate these additions, so delegation/escalation still need focused regression coverage. Shared MariaDB migrations remain unapplied.

Next continuation (2026-10-11): the event trigger allowlist and designer now expose twelve existing employee-linked event types across People, Recruitment, Leave, Attendance and Performance. Approval decisions remain owned by their module services; broader approval migration is still deferred. PHP syntax, workflow route registration and the frontend production build passed; the build reports the existing large-chunk advisory. New event-trigger changes have not yet received regression coverage.

Status-trigger continuation (2026-10-11): added EmployeeActivated and EmploymentStatusChanged to the event catalog. Status-change triggers require a target status and the queue filters against the event's `to` value. PHP syntax and workflow route checks passed; frontend production build passed with the existing large-chunk advisory. Regression coverage remains outstanding.

Lifecycle-trigger continuation (2026-10-11): added the existing ProbationDue and DocumentExpiring employee events to the trigger catalog, bringing it to sixteen supported event types. Their payloads are not copied into workflow instances; the queue uses only the employee reference for scoped execution.

