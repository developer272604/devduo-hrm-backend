# Phase 10 — Employee Experience, Engagement & HR Helpdesk

**Status: partial.** Tenant-scoped helpdesk workflows with private attachments, a searchable versioned HR knowledge base, targeted employee announcements with private audience-gated attachments and onboarding communications, event campaigns with private RSVP, anonymous/confidential/identified survey campaigns with eligibility/consent/retention controls, thresholded aggregate exports and preference-aware invitations/reminders, separately permissioned identified-response access, peer recognition with moderation and recipient opt-outs, and private birthday/work-anniversary notices are implemented.

## Implemented

- Tenant-scoped service categories with a default catalog per tenant and optional target response hours.
- Service categories can route new requests to a configured active same-tenant HR service owner; requests retain category SLA targets.
- Employee ticket intake, own ticket history, public conversation, status/priority/assignee API fields and SLA due/first-response timestamps.
- Manager ticket reads constrained by their `experience.view` data scope; tenant-wide HR service roles may triage tickets.
- Private comments are returned only to users with `experience.ticket.manage` and data-scope access to that employee. Employees and scoped managers cannot post private notes.
- Status transition checks, same-tenant active assignee validation, and audit events for ticket create/update/replies/private notes.
- Employee portal and HR service desk routes for ticket lists, intake, replies, HR-only private notes, and resolve/close actions.
- Searchable, localized, versioned HR knowledge articles with draft/publish permissions, employee/HR visibility and effective dates.
- Ticket intake suggestions from published employee articles, based on request subject and description.
- Announcements support tenant-wide or role/designation, location and legal-entity audiences, scheduled start and expiry, pinned ordering, acknowledgments and privacy-thresholded read metrics. Target audiences use effective assignment at the scheduled publish date.
- Per-user read and acknowledgment timestamps, repeat-safe tracking, acknowledgment-required validation, and audit events.
- Employee announcements, read, and acknowledgment screens in the Experience portal.
- Anonymous 1–5 rating surveys with configurable open/close dates, one response per employee, and a minimum response threshold of at least five.
- Survey answers are stored only as aggregate rating counts, separate from response-completion records; no individual answer row or response timestamp is retained. Results and each rating bucket below the threshold are suppressed.
- Peer/manager recognition supports fixed values tags, moderation before feed publication, recipient opt-outs, working-coworker lookup and non-monetary reward references.
- Requesters can reopen resolved/closed tickets and submit one post-resolution satisfaction rating.
- Ticket attachments are stored on a private disk, limited to five files of at most 5 MB in supported document/image formats, and downloaded only after the requester or a scoped ticket viewer passes ticket visibility checks.
- HR service staff can manually escalate active tickets with a private reason; priority rises one step up to urgent, with escalation history visible only to ticket managers in scope.
- An hourly tenant-aware job escalates SLA-breached unresolved tickets once, raises them to urgent, and sends generic notifications to eligible service managers/assignees. Repeated runs do not duplicate the escalation or notification.
- The HR service queue can filter visible tickets by status, priority, assignee/unassigned state and SLA overdue state.
- Employees can configure in-app/email preferences for announcements, surveys, recognition and events. In-app delivery preferences govern announcements and approved-recognition alerts; required-acknowledgment announcements remain delivered. Email preferences are stored as an integration foundation, with no email delivery configured.
- A five-minute tenant-aware dispatcher sends scheduled announcement notifications to the published audience and stores per-user sent/suppressed receipts, making retries idempotent.
- An hourly tenant-aware survey dispatcher sends generic invitations to eligible active employees, respects the survey in-app preference, and caps optional reminders. Delivery receipts are separate from aggregate answers and are not exposed to managers; reminders stop after submission, close, or retention expiry.
- Survey managers can maintain workspace-scoped reusable question prompts and rating scales from 1 to 10 with optional labels. Campaign questions snapshot their prompt and scale so later catalog edits do not rewrite a live or draft survey; submitted values are checked against each configured scale and analytics remain thresholded aggregates.
- A daily tenant-aware milestone job sends birthday and work-anniversary notices only to the matching employee, respects the events in-app preference, and records idempotent send/suppression receipts.
- Event campaigns extend announcements with tenant-local start/end times and location or meeting link. Employees can privately submit or replace an RSVP before the event; manager metrics return only aggregate RSVP counts and suppress each response bucket below five.
- Onboarding communications can target employees whose start date falls within a configurable 1–90 day window at the scheduled publish date. The eligible employee set is snapshotted at publication, including preboarding employees; it does not expose a manager-facing recipient list beyond the existing audience snapshot behavior.
- Announcement managers can attach up to five private PDF/image/Word/Excel files of 5 MB each to a draft. Downloads require a published, currently visible announcement, so targeted audience checks gate access; upload and access events are audited.
- Tenant-wide HR service health reports backlog, oldest open age, overdue SLAs, and satisfaction only after five ratings.
- Employees can give yes/no knowledge feedback; HR sees effectiveness only after five responses, with each small helpfulness bucket separately suppressed.
- Survey managers can configure engagement, pulse, onboarding, exit or custom campaigns, restrict eligibility to legal entity/location/designation, require explicit consent and set a retention end date. A daily tenant-aware purge command deletes expired response aggregates, participation records and survey questions; existing campaigns receive a six-month retention backfill.
- Survey response modes include anonymous aggregate counts, encrypted confidential answers without participant links, and encrypted identified answers linked to participant completion. The two non-anonymous modes require consent. Minimum thresholds and per-rating bucket suppression apply to all aggregate results and CSV exports. Named responses require a dedicated permission, are audited and are unavailable after retention expires; HR administrator roles do not receive this permission by default.

## Verification

- Backend Experience feature suite: **35 tests, 390 assertions passed**.
- Focused Experience frontend tests: **16 passed**.
- `php artisan route:list --path=experience`: **54 routes registered** (including existing employee experience routes).
- PHP syntax checks passed for the survey service and controller. Frontend production build succeeded; Vite reports a generated JavaScript chunk above the 500 kB advisory threshold.

## Deferred

Email channel delivery, conditional/category-rule routing, free-text survey questions, country-specific privacy controls and broader analytics/differentiators remain deferred. Remaining privacy controls and operational tasks are tracked in `tasks/phase-10/progress.md`.
## Database rollout

The Phase 10 migrations have not been applied to the shared development MariaDB. Tests use the isolated test database. Apply pending project migrations before using these APIs against that database.

## Next

Continue Phase 10 with remaining communications and helpdesk differentiators, including email delivery, country-specific privacy controls, conditional routing, Employee Voice themes and Experience Timeline.










