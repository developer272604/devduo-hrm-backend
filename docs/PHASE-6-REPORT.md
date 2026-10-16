# Phase 6 — Recruitment, ATS & Hiring

**Status: partial against the full master specification. Updated 2026-10-11.**

## Implemented

Existing core: requisitions/headcount, jobs/postings, candidate consent/retention and duplicates, scoped pipelines/history, interviews/independent scorecards, versioned maker-checker offers, analytics, People conversion, recruiter UI and shared audit/task/notification integrations.

This continuation adds:
- Existing-person rehire handover with former-employee picker and explicit identity confirmation. Requires People manage/rehire permissions and rehire scope; existing separation, latest-employment and eligibility rules apply. Overrides require a reason. Reuses the Person and increments employment sequence. Accepted-offer/headcount/idempotency/onboarding behavior is preserved.
- Interview rescheduling with reason, expected original start, future/state checks, panel conflict detection and stale-edit protection. UTC storage is now read as UTC. Panel/tasks are preserved; rescheduling and cancellation notify panelists in-app and are audited.
- Tenant filters on panel selection and recruitment notification holders.
- Hourly local housekeeping: open offers and published postings expire after their UTC expiry date; accepted offers remain accepted. Expiry closes offer-review tasks. Applications waiting 30 days receive one recruiter reminder per stage; missing feedback receives one panelist reminder after 24 hours. Stage transitions reset reminders. Closed applications, anonymized/expired-retention candidates and inactive/unauthorized recipients are skipped. Disabled recruitment tenants are skipped. Repeat runs do not resend completed reminders.

## Verification

- Backend: `php vendor/phpunit/phpunit/phpunit tests/Feature/Recruitment tests/Feature/Lifecycle` — **67 tests, 1113 assertions passed** (rerun after candidate import, resume/offer-document and pipeline/SLA/version work).
- Frontend: `npm test -- --run src/pages/recruitment` — **44 tests passed** (includes candidate import, resume/offer-document and pipeline/version UI). Production build passed.
- Production build passed; existing JavaScript bundle warning above 500 kB remains.
- Pint formatted changed PHP files; final backend tests ran afterward.
- Tests cover rehire identity/permission/scope/eligibility/idempotency, interview conflicts/stale edits/tenant checks, expiry boundaries, accepted-offer preservation, reminder idempotency/privacy/closed-state suppression, disabled modules and Lifecycle integration.
- Vite tests/build required approved sandbox escalation for filesystem access. Browser, live providers and production database concurrency were not verified.

## Candidate CSV import (added)
Tenant-scoped, creator-owned preview then explicit commit (`docs/api/recruitment.md`). Reuses `CandidateService` validation (consent reference/date, privacy notice, retention <= 2 years), per-row duplicate detection (in-file and existing candidates by blind-index email hash), audit entries, a 24 h preview expiry, and discards source rows after commit. Limits: 500 rows, 1 MB, fixed template headers, no column mapping, no update-on-duplicate (duplicates are skipped for separate review). Tests: `tests/Feature/Recruitment/CandidateImportTest.php` (5 tests / 29 assertions) cover preview-without-writes, confirmation requirement, partial import, duplicate handling, bad headers, permission, creator ownership and expiry. Migration `2026_10_26_000002_recruitment_imports.php` was exercised only in isolated test databases.

## Private resumes and offer documents (added, backend)
- Resumes: pdf/doc/docx up to 5 MB validated by extension, real content type and container (shared People `FileInspector`); random names on the private `hrm_private` disk; versioned (max 20) per candidate; downloads stream as attachments through authorized, audited endpoints scoped by `HiringScope`; no content parsing; no antivirus hook exists in the codebase (gate below). Anonymization and retention housekeeping delete files and letter snapshots (repeat-safe, hired candidates skipped).
- Offer documents: versioned restricted-HTML templates with 8 allowed merge fields (escaped substitution, no code execution). Approving an offer creates an encrypted immutable snapshot when an active template exists; sending creates one only if none exists; regeneration appends versions. The print view has a strict CSP and is audited. Compensation text shows only to `payroll.compensation.view` holders at view time.
- Tests: `ResumeTest` (5) and `OfferDocumentTest` (7). Migration `2026_10_27_000001_recruitment_documents.php` ran only in isolated SQLite tests; shared MariaDB was not migrated.
- UI (P06-T10): resumes panel on the candidate profile, offer-documents modal with print view (opens via authenticated fetch into a blob window, so the server CSP header does not apply to it; content is server-escaped), and an offer-template admin page. Frontend recruitment suite: 29 tests passed.
- Not built: antivirus scanning, e-signature, email delivery, PDF generation, resume parsing, template edit/retire endpoints, candidate/public portal.

## Configurable pipelines, SLAs and requisition versions (added, P06-T11)
- Pipelines: tenant-defined versioned stage lists (kind active|offer|hired|rejected, per-stage SLA hours and reminder recipients, pipeline-level forward-skip flag). A default pipeline matches the earlier fixed stages; a version is immutable once a requisition uses it (409 -> create new version). Moves validate against the requisition's pipeline (no backward moves; hired/rejected unreachable by plain moves). Assignment is per requisition via `pipeline_code` (no job-level default yet).
- SLAs: reminders use per-stage `sla_hours` (fallback to the old 30-day default), once per application and stage, with the earlier suppressions; feedback reminder window is `feedback_reminder_hours` on the pipeline. Analytics `sla` reports breaches and average hours per stage.
- Requisition versions: amendments create numbered versions with before/after, reason and actor; higher openings, higher budget or a currency change on an open requisition need a different approver before going live; filled counts, applications and offers are untouched.
- UI: pipelines admin with stage editor and frozen-version flow, pipeline selector, Amend dialog and Versions drawer with approval, pipeline-driven board columns, SLA analytics card.
- Verification: Recruitment + Lifecycle backend 67 tests / 1113 assertions; Recruitment frontend 44 tests; production build passed. Migrations `2026_10_28_000001/2` ran only on SQLite.
- Pipeline version follow-up resolved: requisition payloads now include `pipeline_uuid`, `pipeline_version` and `pipeline_stages` from the saved tenant-scoped relationship. The board loads all requisition pages and handles lookup failures. Actor-name display and the session-based approve/reject UI remain follow-ups.

## Latest continuation — job defaults and pipeline snapshot fix

- Job versions accept optional `default_pipeline_code`, validated against an active pipeline in the current tenant. Requisitions inherit it when their own pipeline is blank; explicit requisition selection overrides it. Existing requisitions keep their saved pipeline version and stages.
- New job form exposes the default pipeline; the requisition form explains inheritance.
- Verification: `php artisan test tests/Feature/Recruitment tests/Feature/Lifecycle` passed **69 tests / 1135 assertions**; Recruitment frontend passed **45 tests**; production build passed with the existing chunk warning. Targeted PHP files formatted with Pint before the regression run.
- Migration `2026_10_29_000001_recruitment_job_pipeline.php` exercised only in isolated SQLite tests. Shared MariaDB remains unchanged.

## Deployment and external gates

- Apply `2026_10_26_000001_recruitment_reminders.php` through normal deployment before housekeeping or stage transitions. Migration ran only in isolated tests; shared MariaDB was not migrated.
- Enable Laravel scheduler for `recruitment:housekeeping {--tenant=}`. Production database locking and scheduler operation require deployment verification. Pipeline SLAs are configurable; the legacy defaults remain fallback values.
- Calendar, email, job-board, e-sign and background-check integrations require implemented adapters, credentials and provider acceptance tests. In-app notices/local posting expiry do not deliver candidate messages or remove external adverts.
- Country-specific consent purpose, retention, recruitment fairness, background checks and offer terms require qualified legal/privacy review. Local tests do not certify compliance.

## Remaining product gaps and next work

Career site/candidate portal and extended profiles/resume parsing; broader conditional approval policies; application questions and bulk actions; talent pools/referrals/agencies; assessments/kits; candidate communications/history; e-sign; internal-employee conversion and fuller readiness/compensation/invitation integration; broader analytics/saved views.

Next practical Phase 6 work (P06-T12b): public careers/application intake and candidate portal using the review plan in `docs/RECRUITMENT-PUBLIC-SURFACE.md`. Job defaults are complete. Provider-gated items (calendar, email, job boards, e-sign, background checks, antivirus) cannot be verified locally. Phase 6 is not complete.

## Latest continuation — public careers and reviewed application intake

- `/careers/:workspace` provides public job search, paging, notice display and application submission. Existing postings remain private by default. Only explicitly enabled external postings on open, non-confidential requisitions with unfilled headcount and unexpired posting dates are listed.
- Recruiters use `/recruitment/careers` to enable/disable public visibility, supply the approved notice/version and retention period, and review an encrypted submission inbox. Acceptance requires explicit identity/consent review and creates the candidate/application transactionally. Duplicate email matches are refused for separate review; anonymous submissions never disclose or link existing candidates.
- Public submission responses are generic. Requests remain under web CSRF middleware; listing and application rate limits have separate keys. Consent notice snapshots and timestamps are recorded in encrypted staging data. Source details are removed on review; expired submissions are purged by housekeeping even when Recruitment is disabled.
- Verified: Recruitment + Lifecycle **72 tests / 1191 assertions passed**; Recruitment frontend **48 tests passed**; production build passed with the existing bundle warning. After the final disabled-module cleanup adjustment, CareersTest passed **3 tests / 57 assertions**. Public UI and recruiter review tests passed. CSRF route configuration was inspected; live browser/CSRF deployment verification remains open.
- Migration `2026_10_30_000001_recruitment_careers.php` was exercised in isolated tests only. Shared MariaDB remains unchanged. Deploy migration and enable scheduler before use. Publication is a recruiter action, not automatic rollout.
- Still pending: verified candidate accounts/status portal, email verification/delivery, public resume uploads with quarantine/scanning, candidate self-service offer decisions, and the other Phase 6 gaps above. The careers form explicitly does not create an account. Next is P06-T12c identity verification and portal; provider delivery and country-specific notice/retention approval remain gates.

## Latest continuation — verified candidate status portal

- `/careers/:workspace/portal` requests an email code, verifies it, displays the candidate's own non-confidential application titles/statuses, and signs out. Candidate access is separate from employee authentication; no User account or employee role is created.
- Codes use 256-bit randomness; only hashes and browser binding are stored in the challenge table. Codes expire in 15 minutes, are single-use and bound to the browser that requested them. A new code replaces prior challenges for that candidate/browser. Sessions rotate on verification, expire after one hour, and check tenant, current email hash, anonymization and retention on every status request. Responses are no-store; status views and verification are audited with an explicit Candidate actor.
- Email request responses are generic for known/unknown email addresses. Transport delivery runs asynchronously in an encrypted queue job with bounded retries and checks that the challenge, tenant/module and candidate remain eligible. Transport errors are sanitized. Codes never appear in API responses; production log/array transports and sync/null queues are refused.
- `RECRUITMENT_CANDIDATE_PORTAL_ENABLED=false` by default. Deployment requires the new migration, a delivering mail transport, asynchronous queue worker, HTTPS/secure session cookies and provider delivery verification before enabling the flag. No live email was sent in these tests. Queue latency, delivery failure/retry, public abuse/timing and browser CSRF behavior still need deployment validation.
- Verification: `php artisan test tests/Feature/Recruitment tests/Feature/Lifecycle` **76 tests / 1245 assertions passed**; Recruitment frontend **50 tests passed**; production build passed with existing bundle warning. Pint formatted new/changed portal PHP before regression. Tests cover generic responses, hash storage, replay/expiry/browser/tenant restrictions, confidential/other-candidate exclusion, retention, logout, session expiry and no employee login. Mail was faked with synchronous job execution for local tests; this is not live queue/provider acceptance evidence.
- Migration `2026_10_31_000001_recruitment_portal.php` ran only in isolated tests. Expired challenges are purged even if Recruitment is disabled. Shared MariaDB remains unchanged.
- Next P06-T12d: candidate self-service documents, withdrawal and offer response with explicit ownership/state/audit rules. Public uploads/scanning, wider communications and other Phase 6 gaps remain. Phase 6 is still partial.

## Latest continuation — candidate withdrawal

- Verified candidates can withdraw only their own non-confidential active applications, with an explicit confirmation and reason. Accepted offers, converted/hired applications and other closed states require recruiter handling. Ownership and session/tenant/retention checks run on the server; other candidates' application IDs return 404.
- Transaction locks cover offers and application state. Withdrawal cancels open offers and scheduled interviews, completes offer/feedback tasks, notifies affected panelists/recruiter, and appends candidate-authored stage history and audit provenance. Retrying an already withdrawn application does not duplicate history or notices.
- Migration `2026_11_01_000001_recruitment_candidate_actions.php` permits nullable employee actors and adds a candidate actor FK. Rollback refuses to discard candidate-authored history. Applied only in isolated tests; shared MariaDB unchanged.
- Verification: Recruitment + Lifecycle **77 tests / 1270 assertions passed**; Recruitment frontend **51 tests passed**; production build passed with existing bundle warning. Targeted PHP formatted with Pint. Coverage includes ownership, explicit confirmation, accepted-offer protection, open-offer withdrawal, interview/task cancellation, candidate provenance and retry behavior. Production concurrent-lock testing remains a deployment gate.
- Next P06-T12e: explicit candidate release of offer documents and candidate accept/decline actions. Existing snapshots may contain internal compensation references; they are not exposed automatically. Offer responses, document delivery, public upload/scanning and broader Phase 6 gaps remain pending.

## 2026-10-12 continuation - offer release and response

P06-T12e is implemented locally: independent approvers explicitly release encrypted, immutable candidate-visible plain-text offer versions. Verified candidates can accept or decline their own eligible released offers with explicit confirmation and version checks. Responses are audited and repeat-safe; internal compensation snapshots are not automatically exposed. Expired retention purges released content.

Focused CandidatePortalTest passed 6 tests / 101 assertions in the preceding implementation session. Full regression and frontend interaction validation of this addition are deferred at the user's request. Migration `2026_11_02_000001_recruitment_offer_releases.php` was exercised only in isolated tests. Phase 6 remains partial: public uploads/scanning, assessments, talent pools/referrals/agencies, extended profiles, internal conversion, communication integrations and broader analytics remain. Mail/queue, provider acceptance, production locking and country-specific legal validation remain external gates. This entry supersedes earlier notes calling P06-T12e the next unimplemented task.
