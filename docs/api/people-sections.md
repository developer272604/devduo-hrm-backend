# People profile sections API

Base: `/api/v1`. Session + CSRF like the rest of the API. `{uuid}` is the employee uuid. Record ids are uuids, except skills and tags (catalog ids). Responses never contain sequential ids for personal records.

## Common behaviour

**Reads** (`people.view` at the route): subject out of the viewer's data scope or another tenant gives `404`. Reachable subject but a section the viewer may not see gives `403 {"message":"You do not have access to this section of the profile."}`.

**Writes**: decided by `FieldAccess::editGroup`:
- `direct` applies the change (HR with `people.manage` + scope always; employees per tenant `edit_rules`, defaults: contact/emergency/education/experience/skill/certification direct, dependent request).
- `request` returns `409 {"message","code":"change_request_required","section":"<group>"}` and nothing is written (the change-request workflow is built elsewhere).
- `none` returns `403`.

Authorization is evaluated before validation. Validation errors are `422 {"message","errors":{field:[sentence]}}`. List responses: `{"data":[...],"viewer":{"edit":"direct|request|none", ...}}`, at most 200 rows, no pagination. `DELETE` returns `{"deleted":true}`. Every mutation is audited (`resource = employee:{uuid}`, no sensitive values for contacts/emergency/dependents/salary) and records a domain event.

## Per-employee sections

Each has `GET /employees/{uuid}/<segment>`, `POST` (201), `PATCH /<segment>/{id}` (partial update, 200), `DELETE /<segment>/{id}`.

| Segment | View rule (FieldAccess) | Edit group | Event classification |
| --- | --- | --- | --- |
| `contacts` | `contact.records` (self or `people.personal.view`) | contact | employee |
| `emergency-contacts` | `emergency.records` | emergency | employee |
| `dependents` | `dependent.records` | dependent | employee |
| `education` | `education.records` (public internal) | education | public_internal |
| `experience` | `experience.records` (public internal) | experience | public_internal |
| `certifications` | `certification.records` (employee class) | certification | employee |
| `skills` | `skill.records` (public internal) | skill | public_internal |

### Fields

- **contacts**: `kind` (email|phone, create only), `label` (work|personal|home|mobile|other), `value`, `country_code` (e.g. `+91`), `is_primary`, `effective_from`, `effective_to`. Exactly one current primary per kind (the first of a kind is primary; setting `is_primary:true` demotes the other; un-setting the only primary is 422; end-dating or deleting the primary promotes another). Duplicate current values are rejected. Response adds `verified`, `is_current`. Prefer end-dating (`effective_to`) over DELETE.
- **emergency-contacts**: `name`, `relationship`, `phone`, `alternate_phone`, `email`, `address`, `is_primary` (exactly one primary).
- **dependents**: `name`, `relationship`, `date_of_birth`, `gender`, `phone`, `email`, `status` (active|inactive), `effective_from`, `effective_to`, `document_uuid`, `benefit_eligibility` (flat key/value metadata only). End-date via PATCH `effective_to` (sets status inactive when in the past); DELETE only for mistaken entries.
- **education**: `institution`, `qualification`, `field_of_study`, `start_date`, `end_date`, `grade`, `country` (2 letters), `document_uuid`, `notes`. Response has `verification {status, verified_by, verified_at, note}`. Editing verified facts resets verification to `unverified`.
- **experience**: `employer`, `job_title`, `location`, `start_date` (not future), `end_date`, `responsibilities`, `last_salary`, `last_salary_currency`, `reason_for_leaving`, `document_uuid`. List response adds `total_experience {years, months}` (overlapping and back-to-back periods merged; open-ended jobs run to today) and `viewer.salary` (`full|masked|hidden`). `last_salary` is encrypted at rest: `full` returns the number, `masked` returns `"••••••"`, `hidden` omits `last_salary` and `last_salary_currency`. `reason_for_leaving` is returned only to the person or HR with personal view. Writing salary fields needs full salary view (or being the person themself), else 403.
- **certifications**: `name`, `issuer`, `issue_date`, `expiry_date` (not before issue), `credential_id`, `credential_url` (http/https), `document_uuid`. Response adds `expiry_status` (no_expiry|valid|expiring_soon (60 days)|expired) and `verification`.
- **skills** (`/employees/{uuid}/skills[/{skillId}]`, skill catalog id in the path): `POST` body `skill_id`, `proficiency` (beginner|intermediate|advanced|expert), `years_experience`, `last_used`, `certification_uuid`. Unique per person and skill; only active catalog skills can be added. Response `{skill:{id,name,category,active}, proficiency, years_experience, last_used, certification_uuid, certification_name, verification}` (certification fields only if the viewer can see certifications).

`document_uuid` must be a document of the same person (422 otherwise) and is only echoed to the person or viewers who can see documents.

### Verification

`POST /employees/{uuid}/education/{id}/verify`, `/certifications/{id}/verify`, `/skills/{skillId}/verify`. Permission `people.documents.verify` (scope applies; cannot verify yourself). Body `{status: verified|rejected|unverified, note?: string(500)}`. Returns the updated record.

## Notes

`GET /employees/{uuid}/notes` (`people.view`; optional `visibility`, `category`) returns only the notes the viewer may read plus `viewer.creatable_visibilities`. `POST` (`people.notes.manage`, 201), `PATCH /notes/{id}`, `DELETE /notes/{id}` (soft delete). Body: `visibility` (general_hr|manager|private_hr|employee_visible), `body` (<=10000), `category`, `mentions` (employee uuids, in tenant, max 20), `attachments` (document uuids of this person, max 10).

| Visibility | Who can read |
| --- | --- |
| general_hr | `people.notes.manage` |
| manager | the subject's line manager, or `people.notes.manage` |
| private_hr | only `people.notes.private` |
| employee_visible | the subject, plus anyone who can read another level |

Unreadable notes are filtered out of queries: no counts, and a direct request returns `404` (never 403). Only the author may edit or delete (others get 403 for readable notes, 404 for hidden ones). Creating `private_hr` needs `people.notes.private`. Note shape: `{uuid, visibility, category, body, author:{name}, is_mine, mentions:[{uuid,name}], attachments:[uuid], created_at, updated_at}`. Event classification: general_hr hr_confidential, manager manager_sensitive, employee_visible employee, private_hr system_restricted (never on a timeline).

## Tags

| Method and path | Permission | Notes |
| --- | --- | --- |
| `GET /employees/{uuid}/tags` | `people.view` | `{data:[tag]}`; tags with a `required_permission` the viewer lacks are omitted |
| `POST /employees/{uuid}/tags/{tagId}` | `people.manage` + tag's `required_permission` | 201 new, 200 already assigned; inactive tag 422; returns `{data:[...]}` |
| `DELETE /employees/{uuid}/tags/{tagId}` | same | 404 if not assigned |

## Catalogs

| Method and path | Permission | Body / query |
| --- | --- | --- |
| `GET /people/skills` | `people.view` | `q`, `category`, `include_inactive`, `per_page` (5..100). Paginated `{data,current_page,last_page,total,...}` |
| `POST /people/skills` | `people.settings` | `name` (unique, case-insensitive), `category`, `active` |
| `PATCH /people/skills/{id}` | `people.settings` | same fields; `active:false` deactivates (no delete) |
| `GET /people/tags` | `people.view` | `q`, `include_inactive`, `per_page`; items `{id,name,color_token,description,active,required_permission (settings holders only),assignable}` |
| `POST /people/tags` | `people.settings` | `name`, `color_token` (slate, gray, red, orange, amber, yellow, lime, green, emerald, teal, cyan, sky, blue, indigo, violet, purple, fuchsia, pink, rose), `description`, `required_permission` (a permission key), `active` |
| `PATCH /people/tags/{id}` | `people.settings` | same fields |

## Error codes

`401` unauthenticated; `403` no permission, hidden section, or forbidden edit; `404` out of scope, other tenant, or hidden note; `405`/`404` malformed ids; `409 change_request_required`; `422` validation or business rule.
