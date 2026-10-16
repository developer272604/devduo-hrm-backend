# Experience API — helpdesk and knowledge base

Authenticated and tenant-scoped under `/api/v1/experience`; all routes require the `experience` module. Ticket visibility uses the employee's own record or assigned `experience.view` data scope. Tenant-wide HR service roles may manage tickets across the tenant; scoped ticket managers are constrained by their `experience.ticket.manage` data scope.

## Service catalog and tickets

- `GET service-categories` — active request categories. A small default set is initialized per tenant if none exists.
- `POST service-categories` (`experience.category.manage`) — `{code,name,description?,target_hours?,default_assignee_id?}`; category code is unique per tenant. The optional default owner must be an active user in this tenant with ticket-management access; new tickets in the category route to that owner.
- `GET tickets?status=&category_uuid=&priority=&assignee=&sla_overdue=&per_page=` — paginated tickets visible to the viewer. HR queues can filter by status, priority, a user ID or `assignee=unassigned`, and open SLA breaches with `sla_overdue=true`.
- `GET tickets/assignees` (`experience.ticket.manage`) — active users holding the ticket-management permission in the current tenant, for service queue filters.
- `POST tickets` (`experience.self`) — `{category_uuid,subject,description}` creates a normal-priority open ticket for the signed-in employee; category target hours set the SLA due timestamp when configured.
- `GET tickets/{uuid}` — ticket and conversation. Employees and scoped managers receive public comments only. Only users with `experience.ticket.manage` and data-scope access to that employee receive private notes.
- `POST tickets/{uuid}/attachments` — multipart `file` upload; PDF, PNG/JPEG, Word and XLSX files up to 5 MB, at most five per ticket. Files stay on a private disk.
- `GET tickets/{uuid}/attachments/{attachment_uuid}` — downloads a file only after the caller passes the ticket's normal visibility checks. Cross-ticket and cross-tenant attachment UUIDs return not found.
- `POST tickets/{uuid}/comments` — `{body,visibility?}`; `visibility` is `public` by default. Only HR ticket managers with scoped ticket access may post `private` notes. Closed tickets reject comments. Only a public reply from a user with `experience.ticket.manage` and scoped ticket access counts as the first HR response.
- `PATCH tickets/{uuid}` (`experience.ticket.manage`) — `{status?,priority?,assignee_id?,resolution?}`. Allowed transitions: open → in progress/waiting employee/resolved; in progress → waiting employee/resolved; waiting employee → in progress/resolved; resolved → closed/in progress. Assignees must be active workspace users.
- `POST tickets/{uuid}/reopen` (`experience.self`) — the requester may reopen a resolved or closed ticket; it returns to `in_progress` and clears the prior resolution.
- `POST tickets/{uuid}/feedback` (`experience.self`) — the requester may submit one `{rating:1..5,comment?}` after resolution or closure.
- `POST tickets/{uuid}/escalate` (`experience.ticket.manage`) — `{reason}` escalates an active ticket, raises priority one step (up to urgent), creates private escalation history and notifies eligible service owners.
- `GET service-health` (`experience.analytics.view`) — tenant-wide backlog, oldest open age and overdue SLA counts. Satisfaction count/average is suppressed until five feedback responses exist.

The hourly `experience:escalate-sla-tickets` command creates one idempotent SLA-breach escalation per overdue ticket, sets priority to urgent and notifies active tenant-wide ticket managers and any assigned scoped service owner. Notifications contain no employee or ticket details. A ticket's escalation reasons and history are returned only to ticket managers with access to that employee.

## Knowledge base

- `GET knowledge?search=&locale=&per_page=` — searchable title, summary and body. Employees see only effective published articles marked `employee`; knowledge managers can search drafts and HR-only articles too.
- `GET knowledge/{uuid}` — article detail under the same visibility rules.
- `POST knowledge` (`experience.knowledge.manage`) — `{article_key?,locale,title,summary?,body,visibility?,effective_from?,effective_until?}` creates a draft and increments the version for that article key and locale. Omitting `article_key` derives one from the title.
- `POST knowledge/{uuid}/publish` (`experience.knowledge.manage`) — publishes a draft and records the publisher. A current version supersedes the previous published version; a future-effective version leaves the current version visible until its effective date.
- Ticket intake uses the same search API to suggest relevant published employee articles based on the subject and description. Article text is rendered as escaped plain text in the UI.
- `POST knowledge/{uuid}/feedback` (`experience.self`) — `{helpful:boolean}` stores one replaceable response per user/article for visible articles; the detail payload indicates only whether the current viewer responded.
- `GET knowledge/{uuid}/feedback-results` (`experience.analytics.view`) — aggregate helpful/not-helpful counts; all counts are suppressed below five total responses and each bucket below five is individually suppressed.

Audit events are recorded for ticket creation, ticket updates, replies, private notes, article creation and publication. Cross-tenant UUIDs resolve to not found.

## Announcements

- `GET announcements?per_page=` — employees see published, non-expired announcements whose publish date has arrived and whose audience includes them; announcement managers can also see drafts and scheduled/expired announcements. Pinned posts sort first. Each result includes the viewer's `read_at` and `acknowledged_at` timestamps.
- `GET announcements/audience-options` (`experience.announcement.manage`) — active legal entities, locations and designations for audience targeting (tenant-local numeric IDs).
- `POST announcements` (`experience.announcement.manage`) — `{title,body,communication_type?,event_starts_at?,event_ends_at?,event_location?,acknowledgment_required?,publish_at?,expires_at?,pinned?,audience?}` creates a draft. `communication_type` is `announcement` (default), `event`, or `onboarding`. Event campaigns require a start time and can include an end time and location/link. `audience` can include `legal_entity_ids`, `location_ids`, `designation_ids`, and `new_hires_within_days` (1–90); organization dimensions combine with AND, while values within a dimension combine with OR. Onboarding campaigns require the new-hire window and snapshot employees with a start date in that window (including preboarding employees); other empty filters target the whole workspace.
- `POST announcements/{uuid}/publish` (`experience.announcement.manage`) — publishes a draft; a future `publish_at` keeps it hidden from employees until that date.
- `POST announcements/{uuid}/attachments` (`experience.announcement.manage`) — adds a PDF, PNG/JPG, Word or XLSX file to a draft announcement, up to five files of 5 MB each. Uploads stop once the announcement is published.
- `GET announcements/{uuid}/attachments/{attachment}` — downloads only after the announcement is published and visible to the requesting employee. Files are stored privately and accesses are audited.
- `POST announcements/{uuid}/read` — records the signed-in employee's read timestamp. Repeated requests preserve the first timestamp.
- `POST announcements/{uuid}/acknowledge` — records both read and acknowledgment timestamps when acknowledgment is required. Repeated requests preserve the first timestamps; announcements without required acknowledgment return validation error.
- `GET announcements/{uuid}/metrics` (`experience.analytics.view`) — audience size, reads and acknowledgments. Metrics are suppressed for audiences below five and read/acknowledgment counts below five are individually suppressed.
- `PUT announcements/{uuid}/rsvp` (`experience.self`) — event invitees submit `{response:"attending"|"maybe"|"declined"}` before an event starts. Each employee can replace their own response; individual RSVP identities are never returned. Event metrics add per-response totals, suppressing each bucket below five.

Announcements, audience snapshots, tracking rows, event RSVPs and attachments are tenant-scoped. Targeted audiences are snapshotted using the effective assignment on the scheduled publish date. Creation, publication, reading, acknowledgment, RSVP and attachment access are audit logged.

`GET communication-preferences` and `PUT communication-preferences` (`experience.self`) read/update per-user `channels` settings for `announcements`, `surveys`, `recognition`, and `events`; each category supports `in_app` and `email` booleans. In-app defaults on and email defaults off. The employee Preferences screen explains that email values are stored for future connectors; this deployment does not send email.

The every-five-minute `experience:deliver-announcements` command delivers a generic in-app update when a published communication reaches its scheduled date. It uses the audience snapshot (or current working employees for tenant-wide posts), respects optional announcement preferences (event preferences for event campaigns), and records a per-user sent/suppressed receipt for retry safety. Required-acknowledgment notices bypass optional preferences. Approved recognition alerts use the recognition in-app preference. Survey invitations/reminders use the survey in-app preference. The daily `experience:send-milestones` command sends private birthday/work-anniversary notifications only to the matching employee and respects the events preference; it never publishes dates to coworkers. Email transport remains pending.

## Surveys

- `GET surveys?per_page=` — employees see published surveys only while their open/close dates allow responses and they match eligibility filters. Survey managers can also see drafts and scheduled surveys. Each item indicates only whether the current viewer submitted.
- `GET surveys/audience-options` (`experience.survey.manage`) — active legal entities, locations and designations for campaign eligibility.
- `GET survey-catalog/questions` and `POST survey-catalog/questions` (`experience.survey.manage`) list or add reusable `{prompt,category?}` rating prompts, scoped to the current workspace.
- `GET survey-catalog/scales` and `POST survey-catalog/scales` (`experience.survey.manage`) list or add rating scales `{code,name,minimum_value,maximum_value,labels?}`. Values range from 1 to 10; labels, when supplied, must contain one label per value. A built-in `FIVE` agreement scale is provisioned lazily for each workspace.
- `POST surveys` (`experience.survey.manage`) — accepts `{title,description?,campaign_type?,response_mode?,opens_at?,closes_at?,retention_until,requires_consent?,eligibility?,minimum_responses?,reminder_interval_days?,max_reminders?,scale_code?,questions:[{prompt?|bank_question_uuid?,scale_code?}]}`. Response modes are `anonymous` (default), `confidential`, and `identified`; the latter two require explicit consent. Each question must use a saved bank prompt or a new prompt. Campaign types are `engagement`, `pulse`, `onboarding`, `exit`, and `custom`. Eligibility filters use `legal_entity_ids`, `location_ids`, and `designation_ids`; dimensions combine with AND and values in each dimension with OR. Retention must extend beyond the close date. Reminders are optional, one to thirty days apart, with at most five per campaign. The minimum is at least 5 (default 5); a campaign may contain up to ten questions. The selected scale and prompt are copied into the draft, so later catalog changes do not alter an existing campaign.
- `POST surveys/{uuid}/publish` (`experience.survey.manage`) — publishes a draft.
- `POST surveys/{uuid}/responses` (`experience.self`) — `{answers:{question_uuid:value},consent?:true}` submits one rating for every question; each value must fit that question's configured scale. Employees can submit once; campaigns requiring consent reject responses without `consent:true`.
- `GET surveys/{uuid}/results` (`experience.survey.manage`) — returns no response count or distributions until the minimum is met. Once met, each rating bucket below the minimum is separately suppressed. Expired campaigns return `{retention_expired:true}`.
- `GET surveys/{uuid}/export` (`experience.survey.manage`) — downloads the same thresholded aggregate distributions as CSV, with formula-leading text neutralized, private/no-store headers and an audit event.
- `GET surveys/{uuid}/identified-responses` (`experience.survey.identifiable.view`) — returns employee names and individual answers only for identified surveys, after the minimum threshold. This is a separately permissioned, tenant-wide, audited disclosure. Workspace administrators receive this permission; HR administrator roles do not by default. Expired data cannot be read or exported.

Anonymous answers are stored as aggregate rating counts without individual answer records or response timestamps. Confidential answers are encrypted and stored without participant IDs; identified answers are encrypted and linked to a separate completion record. All modes use a separate completion table to prevent duplicate submissions and retain consent state. Confidential and identified aggregate results still apply minimum and per-bucket suppression. Reusable questions and scales are tenant-scoped catalogs; campaign questions hold a prompt/scale snapshot. A separate invitation-delivery table tracks invite/reminder attempts and is never exposed through manager APIs or joined to answer aggregates. The hourly `experience:deliver-surveys` command sends preference-aware invitations and reminders, stops after submission/close/retention, and respects the configured reminder cap. `experience:purge-survey-data` runs daily in tenant context and removes expired answer storage, participation rows and campaign question prompts.

## Recognition

- `GET recognitions` — employees receive the approved feed with recipients who opted out omitted; users with `experience.recognition.manage` receive the pending moderation queue.
- `GET recognitions/recipients?search=` (`experience.self`) — returns up to 25 working coworkers matching the name, excluding the current employee and employees who opted out.
- `POST recognitions` (`experience.self`) — `{recipient_uuid,message,value_tags,reward_reference?}` submits a recognition for moderation. Tags are `teamwork`, `customer_care`, `ownership`, `innovation`, and `inclusion`. Reward references are non-monetary references only.
- `POST recognitions/{uuid}/moderate` (`experience.recognition.manage`) — `{decision:"approved"|"rejected"}`; only pending submissions can be moderated.
- `GET recognitions/preference` and `PUT recognitions/preference {opted_out}` (`experience.self`) — get or update the signed-in employee's recipient opt-out preference.

Submitted recognition is hidden until approved. Employee opt-out prevents new recognition and removes approved recognition from the feed. All records and preferences are tenant-scoped; create, moderation and preference changes are audited.

Recognition remains a minimal internal feed: approval/rejection and opt-out are supported, while full award lifecycle, employee-sender identity controls, notification delivery and reward integrations are deferred.

## Deferred

Email transport, country-specific survey privacy controls, free-text survey questions, conditional/category-rule routing and broader service analytics remain outside this slice. Category default-owner routing is supported.
