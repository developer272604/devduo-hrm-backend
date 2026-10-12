# Recruitment API (Phase 6 core)

Base: `/api/v1/recruitment`, session + CSRF, module `recruitment`, tenant-scoped. Cross-tenant or out-of-hiring-scope records return 404.
Scope (`HiringScope`): tenant-wide permission holders, or the recruiter / hiring manager / creator of the requisition, or (view only) a panelist of an interview on the application. Confidential requisitions need `recruitment.confidential` or a direct role on them.
Lists are paginated (`data`, `current_page`, `last_page`, `total`). Money is a decimal string. Dates `Y-m-d`; interview instants are returned as ISO 8601 UTC.

## Permissions
`recruitment.view`, `.manage`, `.approve` (requisitions), `.confidential`, `.offer.manage`, `.offer.approve`, `.handover`, `.reports.view`, `.privacy.manage`. Grant migration `2026_10_16_000002` gives the new keys to full-access system roles and the default HR administrator.
Maker-checker: requisition and offer approvers must differ from the creator.

## Endpoints
| Method + path | Permission | Notes |
| --- | --- | --- |
| GET jobs / POST jobs | view / manage | versioned job definition (`code`, `title`, `description`) |
| GET requisitions | view | adds `filled_count`, `budget_amount`, `budget_currency`, `department_id`, `position_id` |
| POST requisitions | manage | `job_uuid, legal_entity_id, recruiter_id, hiring_manager_id, openings, target_date, kind, reason, idempotency_key` + optional `position_id, department_id, location_id, budget_amount, budget_currency, confidential`. If the position's `metadata.headcount` is set, committed openings may not exceed it (422). Creates review tasks for approvers. |
| POST requisitions/{uuid}/decision | approve | `{decision: open\|rejected}`; creator cannot decide (403). Opening creates a sourcing task + notification for the recruiter. |
| POST requisitions/{uuid}/close | manage | pending/open -> closed; closes published postings |
| GET postings, POST postings | view / manage | `requisition_uuid, title, description, channel(internal\|external\|referral), location_text?, closes_on?` |
| POST postings/{uuid}/publish, /close | manage | publish needs an open requisition; goes through the neutral `CandidateChannelAdapter` (null by default) |
| GET candidates | view | email masked unless `recruitment.manage` |
| POST candidates | manage | consent + retention mandatory; phone/email encrypted; 409 on same email hash; response `possible_duplicates` lists same-name candidate uuids in scope |
| POST candidates/{uuid}/anonymize | privacy.manage | after retention, or `erasure_request: true`; not for hired applicants |
| GET applications, POST applications, POST applications/{uuid}/transition, GET applications/{uuid}/history | view / manage | stages `applied, screening, assessment, interview, offer`; closing statuses `rejected, withdrawn`; handover sets `hired` |
| GET interviews (`application_uuid?`), POST interviews | view / manage | `application_uuid, round, starts_at (Y-m-d\TH:i local), ends_at, timezone, mode, location_text, panelist_ids[]`; panelist overlap -> 422; creates feedback tasks + notifications |
| POST interviews/{uuid}/reschedule | manage | Local `starts_at, ends_at`, `timezone`, `expected_starts_at` (original ISO instant), `reason`. Future scheduled interviews only; preserves panel/tasks; stale/state conflicts 409, invalid time/overlap 422; in-app panel notices and audit. |
| POST interviews/{uuid}/cancel | manage | Completes feedback tasks and notifies panel in-app. |
| POST interviews/{uuid}/scorecard | view (panelist only) | `rating 1-5, recommendation strong_yes\|yes\|no\|strong_no, strengths?, concerns?`; once per panelist; opens at interview start; text encrypted |
| GET interviews/{uuid}/scorecards | view | panelists see only their own; `recruitment.manage` in scope sees all submitted (audited) |
| GET offers, POST offers | offer.manage or offer.approve / offer.manage | create needs an active application at the `offer` stage; `application_uuid, job_title, start_date, expires_on?, compensation_reference?, currency?, notes?, benchmark_employee_uuid?`. New version supersedes open ones. |
| POST offers/{uuid}/{submit,approve,reject,send,accept,decline,withdraw} | offer.manage (approve/reject: offer.approve) | `reject` needs `note`; illegal transitions 409 |
| POST offers/{uuid}/handover | handover (+ people.manage) | accepted offer -> Person + Employment via People `EmployeeService` (`source: recruitment`). Body: `assignment{department_id, designation_id, employee_type_id, location_id, legal_entity_id?, position_id?, grade_id?, level_id?, work_mode?}`, `employee_number?`, `work_email?`, `worker_type?`, `probation_applicable?`, `date_of_birth?`, `manager_uuid?`, `confirm_duplicates?`. Returns `{employee_uuid, employee_number, status}` (201). Idempotent per offer; 409 `possible_duplicate` if a matching person exists; 409 when the requisition has no headcount left. |
| GET analytics | reports.view | `requisitions{by_status, openings, filled, overdue}`, `pipeline{by_stage, by_status, by_source}`, `time_to_fill{filled_requisitions, average_days}`, `offers{by_status, acceptance_rate}` |

## Compensation
Offers store a **reference** only (encrypted text, optional currency). `benchmark_employee_uuid` resolves Payroll's `CompensationResolver` (when bound) and requires `payroll.compensation.view` + data scope; only a structure id / currency / frequency label is stored, never amounts.

## Cross-module
My Work tasks: requisition review, sourcing, offer review, interview feedback, handover (completed when resolved). Notifications category `recruitment`. Audit module `recruitment`. DomainEvent `RecruitmentHired` on the employee timeline. Employee 360 section `recruitment_source`. Action Center insights `recruitment.*`. Data-quality rules: `recruitment_retention_expired`, `recruitment_stale_requisition`, `recruitment_hired_without_employee`, `recruitment_stalled_application`.

## Deferred
Email/calendar and job-board adapters (interface only), CV parsing, candidate self-service/careers page, referral rewards, assessment tests, interview kits/templates, bulk import, offer documents. (Configurable pipeline SLAs and requisition versioning: see the P06-T11 section.) Full onboarding lives in Phase 7; internal-employee conversion is separate from former-employee rehire.

## Rehire handover and housekeeping

Handover additionally accepts `previous_employee_uuid`, `rehire_identity_confirmed` (required accepted value when previous employee is provided), and `override_reason?`. Requires `people.rehire` in addition to existing permissions and applies that permission's employee scope. People's rehire service reuses the Person and validates former/latest employment and eligibility. Existing accepted-offer, headcount and idempotency rules apply.

`recruitment:housekeeping {--tenant=}` runs hourly. Open offers and published local postings expire after their UTC expiry date; accepted offers are retained. In-app reminders run once per 30-day waiting stage and once per overdue panel feedback after 24 hours, excluding closed/privacy-restricted records and inactive/unauthorized recipients. Requires the recruitment reminders migration. Fixed defaults do not constitute configurable SLAs or external delivery.

## Candidate CSV import (P06-T08)
Permission `recruitment.manage`. Two steps; nothing is written until the explicit commit.
- `POST /api/v1/recruitment/imports/candidates` — multipart `file` (UTF-8 CSV, <=1 MB, <=500 rows). Headers must match the candidate template exactly: `name,email,phone(optional),source,privacy_notice_version,consent_reference,consented_at,retention_until`; unknown or duplicate columns are refused (422). Returns `201 {uuid, rows:[{row,name,email,errors[]}], expires_at}`. Rows with validation errors (including consent/retention rules) or an email that repeats in the file or already exists are flagged and will be skipped.
- `POST /api/v1/recruitment/imports/candidates/{uuid}/commit` — body `{confirmed:true}` (required). Only the creator can commit (others get 404); a preview expires after 24 h (410). Valid rows are created through `CandidateService` (same consent, duplicate and audit rules as single create); invalid rows are skipped. Returns `{uuid, rows:[{row,status:imported|skipped,candidate_uuid,errors[]}]}`. Re-committing returns the stored receipt without creating anything. Source rows (personal data) are encrypted at rest and discarded at commit.

## Private resumes and offer documents (P06-T09, backend only)

Migration `2026_10_27_000001_recruitment_documents` adds `recruitment_resumes`, `recruitment_offer_templates`, `recruitment_offer_documents`. No new permissions: resume read uses `recruitment.view` (HiringScope candidate scope, includes panelists), upload/remove use `recruitment.manage`, templates/regeneration use `recruitment.offer.manage`, reading offer documents also allows `recruitment.offer.approve`. Out-of-scope or cross-tenant records return 404.

### Resumes
- `GET /recruitment/candidates/{uuid}/resumes` list versions (no storage paths are ever returned).
- `POST /recruitment/candidates/{uuid}/resumes` multipart `file`; pdf, doc, docx only, max 5 MB, extension and real content type (finfo + container check) must match, double/executable extensions rejected, max 20 versions. Stored on the private disk `hrm_private` under `recruitment/{tenant}/{candidate uuid}/{random}.{ext}`; client name is display metadata only. Content is never parsed. Each upload is a new version; nothing is overwritten. 409 if the candidate was anonymized.
- `GET /recruitment/candidates/{uuid}/resumes/{version}/download` authorized streaming (`attachment`, `nosniff`, `no-store`); audited as `resume.downloaded`.
- `DELETE /recruitment/candidates/{uuid}/resumes/{version}` deletes the file, keeps a metadata row (`resume.removed`).
- Candidate anonymization deletes all files. `RecruitmentHousekeeping::run()` also purges files and letter snapshots of anonymized or retention-expired candidates (hired applicants are retained through the employee record), idempotently; counts `resumes_purged`, `offer_documents_purged`; audit `candidate.files_purged`.

### Offer letter templates and documents
- `GET /recruitment/offer-templates` / `POST /recruitment/offer-templates` `{code, name, body}`. Each POST creates the next version for that `code` and retires the previous one. Body is restricted HTML (p, br, strong, em, b, i, u, ul, ol, li, h1-h3; attributes stripped, any other markup is escaped to text). Merge fields (only these, unknown fields are rejected): `candidate_name, job_title, start_date, valid_until, company_name, offer_version, currency, compensation_reference`. Rendering is token substitution of HTML-escaped values; no expressions or code.
- Approving an offer (and sending it, if none exists yet) creates an immutable document snapshot when the workspace has an active template; otherwise the workflow is unchanged. Content is stored encrypted.
- `GET /recruitment/offers/{uuid}/documents` list; `POST /recruitment/offers/{uuid}/documents` `{template_uuid?}` regenerates as a new version (approved or sent offers only; earlier versions are never changed); `GET /recruitment/offers/{uuid}/documents/{version}/print` HTML print view (CSP `default-src 'none'`, audited as `offer_document.viewed`).
- `compensation_reference` shows the offer's compensation reference only to holders of `payroll.compensation.view`; everyone else sees "[Compensation details are shared separately]". Evaluated at view time, so snapshots stay safe to list.
- Not included: e-signature, email delivery (record "sent" manually as before), PDF generation, resume content parsing, antivirus scanning (no hook exists in the codebase).

## Configurable pipelines, stage SLAs and requisition versions (P06-T11, backend)

Migrations `2026_10_28_000001_recruitment_pipelines_versions` (tables `recruitment_pipelines`, `recruitment_pipeline_stages`, `recruitment_requisition_versions`; requisition columns `pipeline_code`, `pipeline_id`, `version`) and `2026_10_28_000002_grant_recruitment_pipeline_permission`. New permission `recruitment.pipeline.manage` (full-access roles and HR administrator via the grant migration). Reading needs `recruitment.view`.

### Pipelines
A pipeline version is a tenant-defined ordered list of stages. Every tenant gets a lazily created `DEFAULT` pipeline equal to the former fixed stages (`applied, screening, assessment, interview, offer`, no SLA), so nothing changes until a tenant configures one.
- `GET pipelines`, `GET pipelines/{uuid}`, `GET pipelines/{uuid}/versions` (all versions of the code, newest first).
- `POST pipelines` (`recruitment.pipeline.manage`) `{code (A-Z0-9_), name, allow_skip?, feedback_reminder_hours? (default 24), stages[{code (a-z0-9_, <=24), label, kind: active|offer|hired|rejected, sla_hours? (1-8760), reminder_recipients? [recruiter|hiring_manager]}]}` creates version 1 (409 if the code exists).
- Rules: 2-20 stages, unique codes, first stage `active`, exactly one `offer` stage, `hired`/`rejected` stages (max one each, no SLA) must be last. Violations 422.
- `PUT pipelines/{uuid}` edits in place only while no requisition has used the version; otherwise 409 (immutable). `POST pipelines/{uuid}/versions` creates the next version from the active version (previous version becomes `retired`, default flag carries over); `POST pipelines/{uuid}/default` sets the tenant default; `DELETE pipelines/{uuid}` only for unused, non-default versions.
- A requisition snapshots a pipeline version when it is opened (`POST requisitions` accepts optional `pipeline_code`; otherwise the default). Requisitions that are already open keep the version they run on when the default changes. Applications start at the pipeline's first stage.
- Stage moves validate against the requisition's pipeline: unknown stage 422; forward by exactly one stage, or any later stage if `allow_skip`; no backward moves; `hired`/`rejected` stages are not reachable by a plain move (hiring is the offer/handover flow, closing keeps the final stage); history is append-only. Offers require the application to be in the stage of kind `offer`.

### SLAs and reminders
`recruitment:housekeeping` now uses the per-stage `sla_hours` (fallback: 720 h = the former 30 days) and the stage's `reminder_recipients` (default recruiter). Still once per application+stage (`stage_reminded_at`, reset on every move), skipped for closed/anonymized/retention-expired records, closed requisitions, inactive or unauthorized recipients, and tenants without the module. Overdue panel feedback is reminded after the pipeline's `feedback_reminder_hours` (default 24) instead of a fixed day. In-app only.
`GET analytics` adds `sla{by_stage{<stage>{active,breached}}, breached_total, average_hours_in_stage{<stage>: "h.d"}}`: breaches among active applications (effective SLA incl. fallback), averages over completed stage visits as integer-computed one-decimal strings.

### Requisition versions
- `POST requisitions/{uuid}/amend` (`recruitment.manage`) any of `openings, budget_amount, budget_currency, department_id, location_id, job_uuid, target_date` plus required `reason` and optional `expected_version` (409 if stale). Statuses `pending`/`open` only.
- Each change creates version N+1 with `before`/`after` snapshots, `changed_fields`, reason and actor. For an open requisition, an increase in openings, budget amount (compared exactly in minor units) or a currency change on an existing budget is `pending`: the live requisition is not touched until an independent approver decides; other changes (decreases, department, location, job, date) apply immediately (`applied`). Amending a still-pending requisition applies immediately (initial approval still outstanding). One pending amendment at a time; openings cannot fall below `filled_count`; position headcount caps are re-checked on increases.
- `POST requisitions/{uuid}/versions/{version}/decision` (`recruitment.approve`) `{decision: approved|rejected}`; the amendment creator cannot decide (403). Review tasks are created for approvers and completed on decision.
- `GET requisitions/{uuid}/versions` history (newest first, HiringScope). Applications, offers and `filled_count` are never modified by an amendment. Audit: `pipeline.*`, `requisition.amended|amendment_requested|amendment_approved|amendment_rejected`.
- Deferred: job-level default pipeline, per-stage hired-count automation, UI.

## Job pipeline defaults and requisition snapshots

POST jobs accepts optional `default_pipeline_code` (active, same-tenant pipeline code). A new job code version stores its own default; existing versions are unchanged. GET jobs returns this field. POST requisitions inherits the selected job version's default when `pipeline_code` is blank; a supplied code overrides it. Pipeline version binding still occurs when the requisition opens.

GET requisitions includes nullable `pipeline_uuid`, `pipeline_version`, and ordered `pipeline_stages` (`code`, `label`, `kind`, `sla_hours`) from the saved pipeline. Boards must prefer these stages over the currently active pipeline version. Deploy `2026_10_29_000001_recruitment_job_pipeline.php` before using job defaults.

## Public careers and application review

Public (web CSRF middleware, separate rate limits):
- GET `/api/v1/careers/{workspace}/jobs?q=&page=`: workspace slug, allowlisted public job data and notice; `company`, paginated `jobs`. Requires Recruitment enabled and explicit public external posting visibility, open/non-confidential requisition, remaining headcount, valid closing date.
- POST `/api/v1/careers/{workspace}/jobs/{uuid}/apply`: `name`, `email`, optional `phone`, accepted `consent`, matching `notice_version`. Generic 202; no candidate ID or duplicate disclosure. Stale notice 409, hidden posting 404. Creates encrypted submission only.

Authenticated, `recruitment.manage` plus hiring scope:
- PUT `postings/{uuid}/public`: boolean `public_enabled`; enabling requires `privacy_notice`, `notice_version`, `retention_days` (1–730 technical bounds, not legal certification). Only published external non-confidential/open postings. Notice text changes require a new version. Returns `careers_path`.
- GET `submissions`: pending unexpired scoped inbox including submitted notice and consent timestamp; access audited.
- POST `submissions/{uuid}/review`: `decision` accept/reject; accepting requires true `identity_reviewed`. Transactionally uses CandidateService and HiringService; existing candidates cause 409 without merge. Reviewed/expired submissions 409. Submission source PII is cleared after review.

Housekeeping purges expired submissions, including disabled recruitment tenants. Migration: `2026_10_30_000001_recruitment_careers.php`. No candidate authentication, public resume uploads, email delivery or automated candidate decisions are provided by these endpoints.

## Candidate identity and status portal

Public routes under `/api/v1/careers/{workspace}/portal`, with web sessions/CSRF:
- POST `/code`: email; generic 202 message for eligible/unknown addresses. Rate limit 5/minute. Eligible candidate receives a browser-bound 64-character verification code through an encrypted queued email job. No secret returned by API.
- POST `/verify`: email and code. Rate limit 10/minute. Invalid/expired/wrong-browser/replayed code 422. Consumes challenge and rotates session; stores candidate-only access expiring in one hour. Does not log into the employee guard.
- GET base portal path: candidate name and own non-confidential application `job_title`, public `status` and `submitted_at`. Checks tenant/email hash/anonymization/retention/session expiry; 401 without valid candidate access. No internal notes, feedback or compensation. Cache-Control no-store.
- POST `/logout`: forgets candidate access/browser binding and rotates session; 204. Does not grant or revoke employee roles.

Portal flag: `RECRUITMENT_CANDIDATE_PORTAL_ENABLED` (false by default). Disabled returns 503. In production `/code` also requires a delivering transport and an asynchronous queue; log/array mail and sync/null queues are refused. Codes expire after 15 minutes. New same-browser requests invalidate earlier challenges. Queue job rechecks eligibility before sending and sanitizes delivery errors. Cleanup runs through recruitment:housekeeping even when the recruitment module is disabled.

Migration: `2026_10_31_000001_recruitment_portal.php`. Production email/queue delivery and security deployment review remain unverified. Candidate documents, withdrawal and offer response are not part of these status-only endpoints.

## Candidate withdrawal
POST `/api/v1/careers/{workspace}/portal/applications/{uuid}/withdraw`: candidate session required; `confirmed` accepted value and `reason` required (max 2000). Own non-confidential active application only. Accepted offer or employee conversion blocks withdrawal (409); foreign application 404. Cancels open offers and scheduled interviews, completes related My Work tasks, notifies recruiter/panel and records candidate actor in stage history plus audit. Repeat withdrawal returns Withdrawn without repeated side effects. Web CSRF and portal-action rate limit apply. Portal status now includes application `uuid` and `can_withdraw`. Migration `2026_11_01_000001_recruitment_candidate_actions.php` preserves separate candidate versus employee author provenance.
