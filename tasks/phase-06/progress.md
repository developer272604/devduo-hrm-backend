# Phase 6 — Recruitment, ATS & Hiring

Scope: Phase 6 in `hrms_master_16_phases.md`. API: `docs/api/recruitment.md`. Evidence/gates: `docs/PHASE-6-REPORT.md`.

**Current classification: partial. Updated 2026-10-11.**

| ID | Work | Status |
| --- | --- | --- |
| P06-T01 | Inspect core workflows, scope and privacy | Done |
| P06-T02 | Verify backend and recruiter UI | Done: Recruitment + Lifecycle 34 tests / 617 assertions; frontend 13 tests; production build passed |
| P06-T03 | Update status, API contract and report | Done |
| P06-T04 | Existing-person rehire with identity confirmation, People scope and eligibility | Done; backend/frontend tests |
| P06-T05 | Interview rescheduling, UTC interpretation, conflict/stale checks and panel notices | Done; backend/frontend tests |
| P06-T06 | Tenant-filter panel selection and notification holders | Done; regression tests |
| P06-T07 | Local offer/posting expiry and repeat-safe stage/feedback reminders | Done; command, migration and expiry/privacy/idempotency tests |
| P06-T08 | Candidate import preview/commit with consent and duplicate review | Done: backend `CandidateImportTest` 5 tests / 29 assertions; Recruitment + Lifecycle 39 tests / 646 assertions; UI see report |
| P06-T09 | Private resumes and offer letter documents (backend) | Done: Recruitment + Lifecycle 51 tests / 871 assertions. Resume/offer-document UI and the career/candidate portal remain (P06-T10) |
| P06-T10 | Resume panel, offer-document modal/print view and offer-template admin UI | Done: Recruitment frontend 29 tests; production build passed |
| P06-T11 | Configurable pipelines/SLAs and requisition versions (backend + UI) | Done: Recruitment + Lifecycle 67 tests / 1113 assertions; Recruitment frontend 44 tests; production build passed |
| P06-T12a | Job-level default pipeline and version-correct requisition board | Done: inherited defaults with explicit override, tenant checks, saved pipeline stages in API, paginated requisition loading; backend 69 tests / 1135 assertions, frontend 45 tests, build passed |
| P06-T12b | Public careers listings, application intake and recruiter review | Done locally: explicit public visibility, notice/version/retention controls, encrypted submission inbox, reviewed conversion, duplicate refusal, rate limits and expiry cleanup |
| P06-T12c | Verified candidate login/status portal | Done locally: hashed single-use browser-bound codes, encrypted email queue jobs, separate expiring candidate sessions, own non-confidential application status; disabled until mail/queue configuration is validated |
| P06-T12d | Candidate withdrawal | Done locally: explicit confirmation/reason, ownership/state checks, candidate-authored history, pending offer/interview cancellation, task completion and repeat-safe outcome |
| P06-T12e | Candidate-visible offer documents and offer response | Next; internal document snapshots need explicit candidate-release rules. Public uploads require quarantine/scanning. Provider delivery, deployment and legal validation remain external gates |

Other gaps: candidate self-service documents/actions, public resume uploads, assessments/kits, talent pools/referrals/agencies, internal conversion, communications, extended profiles/parsing and broader analytics. Verified candidate status portal, public careers/intake, configurable pipelines/SLAs, requisition versions and job default pipelines are implemented locally.

Migration ran only in isolated tests. Deploy the reminder migration and scheduler, then verify MariaDB locking. Provider adapters/credentials/acceptance tests and country-specific legal/privacy review remain gates. See report for details.

Continue with P06-T12e candidate-visible offer documents and offer response. Recruitment + Lifecycle: 77 tests / 1270 assertions; frontend: 51 tests. New migrations through `2026_11_01_000001_recruitment_candidate_actions.php` ran only in isolated tests. Portal email used Mail::fake; production mail/queue delivery is unverified. Do not label the full phase complete.

2026-10-12: P06-T12e implemented locally (explicit candidate offer releases and accept/decline). Focused portal check: 6 tests / 101 assertions passed. Full validation deferred by user request. Prior NEXT designation is superseded; remaining product/provider/legal gaps in the Phase 6 report still apply. Continued to Phase 7 under the user's explicit instruction; Phase 6 is not declared complete.
