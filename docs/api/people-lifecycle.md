# People lifecycle API

Base: `/api/v1`, session + CSRF, module `people`. `{uuid}` is an employee uuid unless stated. Out-of-scope subjects are `404`, missing permission `403`, validation `422 {"message","errors"}`.

## Profile change requests

When a direct endpoint answers `409 {"code":"change_request_required","section"}`, the employee submits a request instead.

| Method and path | Who | Notes |
| --- | --- | --- |
| `POST /employees/{uuid}/change-requests` | the employee (self only) | 201 |
| `GET /employees/{uuid}/change-requests` | the employee, or approvers in scope | newest first, max 100 |
| `GET /people/change-requests` | everyone (own); approvers (`people.changes.approve`) also see requests in their data scope | `status`, `mine=1`, `employee_uuid`, `per_page`; paginated |
| `GET /people/change-requests/{uuid}` | requester, the employee, approvers in scope | 404 otherwise |
| `POST /people/change-requests/{uuid}/approve` | approver, `{comment?}` | applies the change atomically |
| `POST /people/change-requests/{uuid}/reject` | approver, `{comment}` required | nothing is applied |
| `POST /people/change-requests/{uuid}/cancel` | the requester, while pending | |

Body of submit: `section` (`personal|contact|address|emergency|dependent|bank|identity|custom`), `operation` (`create|update|delete`), `target_id` (record uuid for update/delete), `requested_value` (object), `effective_date` (informational), `comments`.

- `requested_value` is validated with the rules of the direct endpoint of that section (errors come back as `requested_value.<field>`). `personal` is an update of person fields (not `country`/`timezone`) the employee may request; `custom` is `{values:{key:value}, effective_from?}`; `delete` on bank retires the account. Bank details and identity numbers get their country checks at submit time, so bad requests never reach an approver.
- One pending request per section, operation and target (422 otherwise). A requester must pass the section's self-edit rule (`none` gives 403).
- Old and requested values are stored encrypted. Audit entries, events, tasks and notifications contain only the section, operation and request uuid.
- Approver: `people.changes.approve` and data scope over the employee, never the requester or the employee; bank requests also need `people.bank.manage`, identity requests `people.identity.manage` (others get 404 for those requests and do not see them in lists).
- Approve/reject are idempotent: repeating the same decision returns the request (200); the opposite decision gives `409`. Concurrent decisions are serialized by a row lock; if applying fails (for example a validation error) the request stays pending.
- Response: `{uuid, employee:{uuid,name,employee_number}, section, operation, target_id, status, fields, old_value, requested_value, masked, requested_by, requested_at, effective_date, comments, decided_by, decided_at, decision_comment, can_decide, can_cancel}`. For `bank` and `identity` values are masked (`••••9012`) in lists and details unless the viewer holds `people.sensitive.reveal` (details only).
- Side effects: My Work task per approver (`source_module=people`, `source_entity=change_request:{uuid}`, completed on decision or cancel), notifications to approvers and to the requester, events `ProfileChangeRequested|Approved|Rejected`.

## Employee invitations (`people.invite`)

`GET /employees/{uuid}/invitations`, `POST /employees/{uuid}/invitations {email?}` (201; defaults to the work, then personal email), `POST /people/invitations/{uuid}/resend` (new token, expiry restarts, old link dies), `POST /people/invitations/{uuid}/cancel`.

Rules: employee not separated/inactive/archived, person has no login yet, email not used by any account, one pending invitation per employee. Expiry: setting `invitations.expiry_days` (default 7). The token is random, only its SHA-256 hash is stored, and it is never returned by the API; it is delivered by email (`<frontend>/accept-invitation?token=...`). Invitation response: `{uuid,email,status(pending|accepted|cancelled|expired),expires_at,last_sent_at,resend_count,accepted_at}`.

Bulk action `send_invitation` (needs `people.bulk` and `people.invite`) invites a selection; ineligible employees are skipped in the preview with a reason.

### Public acceptance (the only unauthenticated lifecycle route)

`POST /api/v1/public/invitations/accept {token, password, password_confirmation}` (web middleware, so CSRF applies; throttled 10/min per IP and 5/min per token). Password policy: 12+ characters with letters and numbers. The user is created in the invitation's workspace with the Team member role and linked to the existing person (no person or employment is created). Every failure (unknown, used, cancelled, expired or replaced token) is the same `422 {"errors":{"token":[...]}}`.

## Probation (HR with `people.manage` in scope, or the line manager; never oneself)

`POST /employees/{uuid}/probation/confirm {confirmation_date?, comments?}` moves `probation` to `active`, sets `confirmation_date` (event `ProbationConfirmed`). `POST /employees/{uuid}/probation/extend {new_end_date, reason}` moves `probation_end` later and increments `probation_extensions` (event `ProbationExtended`, reason in the payload). Both complete the open `probation:{uuid}:*` My Work tasks. Response: `{uuid,status,probation_end,confirmation_date,probation_extensions}`.

## Separation and rehire

| Method and path | Permission | Notes |
| --- | --- | --- |
| `GET /employees/{uuid}/separations` | `people.separate` | history |
| `POST /employees/{uuid}/separation` | `people.separate` | `{type(resignation|termination|retirement|contract_end|absconding|other), last_working_date, reason_code?, reason?, rehire_eligible?, comments?}`; moves to `notice_period` through StatusService and sets `separated_on` |
| `POST /employees/{uuid}/separation/complete` | `people.separate` | on or after the last working date; moves to `separated` |
| `POST /employees/{uuid}/separation/withdraw` | `people.separate` | only while in notice; restores `active`/`probation` |
| `POST /employees/{uuid}/rehire` | `people.rehire` | `{employment:{joined_on,worker_type,...}, assignment?, manager_id?, override_reason?}`; 201 `{uuid,employee_number,status,employment_sequence}` |

Whenever an employee becomes `separated` (this endpoint, the status endpoint, bulk, or the nightly lifecycle job) the following runs once: open assignments and reporting relationships (as employee or manager) are end-dated at the last working date, the separation record becomes `completed`, the linked login is deactivated (sessions invalidated) unless the person has another working employment, and `EmployeeSeparated` is recorded. Rehire creates a new employment (`employment_sequence+1`, `rehire_of_id`, original joining date kept) for the same person through `EmployeeService::create`, reactivates the login and records `EmployeeRehired`. It needs `rehire_eligible = true` on the latest completed separation, otherwise `override_reason` is required (422 `override_reason`). The new joining date must be after the previous last working date.

## Retention, legal hold, anonymization

`GET /employees/{uuid}/retention` (`people.separate`), `PUT /employees/{uuid}/retention {legal_hold?, retention_until?, reason?}` and `POST /employees/{uuid}/anonymize {confirm:true, reason}` (both `people.settings`). Returns `{legal_hold, retention_until, anonymized_at}`. Anonymization is refused with `409 {"code":"legal_hold"}` or `409 {"code":"retention_active"}`, and with 422 while any employment is still current. It strips direct identifiers from the person, clears work email/phone, archives the employments and deactivates the login. Nothing is physically deleted; section records are left for a later retention job.
