# HRMS MASTER DEVELOPMENT SPECIFICATION — ALL 16 PHASES

> **Claude Code–optimized reference | React + Vite + JavaScript · Laravel REST API · MySQL**
>
> Product: multi-tenant, multi-country, enterprise-capable SaaS HRMS, Keka-class ambition. This document is the **scope reference**, not an instruction to implement all phases in one session.

## 0. How Claude Code must use this file

1. Work on **one assigned task** within **one phase** at a time. Never start the next task or phase automatically.
2. Before implementation, inspect existing code and classify requirements as implemented, partial, missing, or blocked. Do not rebuild completed functionality.
3. Break each phase into dependency-ordered tasks with an explicit scope, affected files, acceptance criteria, tests, and completion status; track in `tasks/phase-XX/progress.md`.
4. Read only the relevant section of this document and necessary shared rules. Do not repeatedly ingest the entire file during coding.
5. For each task: inspect relevant files → short plan → implement smallest complete vertical slice → targeted tests → fix regressions → concise report → stop.
6. **Never claim completion** without implementation and verification. A foundation/adapter is not a live integration, and configurable country-pack architecture is not certified legal compliance.
7. Keep output concise. No repetitive summaries, unnecessary repo-wide searches, broad dependency changes, speculative refactors, or unrelated test suites.
8. If a prerequisite is missing, implement only the minimum prerequisite or mark the task blocked; do not invent placeholder functionality presented as complete.

### Shared technology and architecture

- Frontend: React, Vite, JavaScript; responsive, accessible UI with restrained purple design system; reuse existing buttons, forms, tables, filters, modals, drawers, calendars, toasts, loading/empty/error states, app shell.
- Backend: Laravel versioned REST API; domain/application services, policies, validation, jobs, events, transactions; modular monolith, not premature microservices.
- Database: MySQL, normalized tenant-scoped schema, FKs, indexes, unique constraints, effective dates, soft deletion/retention where appropriate, audit references, idempotency.
- Employee/Employment/Person are distinct from authentication User. Reuse Phase 1 organization, auth, RBAC, data scopes, My Work, approvals, notifications, audit, settings, domain events.
- Multi-country from day one: legal entity, jurisdiction, country packs, location, locale, timezone/DST, currency, calendars, effective-dated rules. **Never assume India-specific rules globally.**
- Server-side tenant isolation and field/action/data-scope authorization on every query, endpoint, job, file, search and export. Encrypt/mask sensitive identity, bank, medical, compensation and applicant data; log sensitive access.
- Preserve immutable source events, ledger entries, approved actions and historical rule versions; correct with reversing entries/versioned adjustments rather than silent overwrites.
- Financial values use decimal-safe arithmetic; time calculations use explicit timezone and shift-date semantics.
- Integrate all domains with **Employee 360, Organization Memory, HR Data Quality, shared Action & Intelligence Layer, My Work, Notifications and Audit**; never create duplicate approval/task engines.
- Use queued, idempotent and retry-safe background jobs for large calculations, imports, exports, notifications and processing. Avoid N+1 queries, paginate, handle concurrency.
- Every user-facing workflow includes permissions, validation, actionable errors, accessibility, desktop/tablet/mobile adaptations, and meaningful empty/processing/failure states.
- Country-specific statutory, privacy, hiring and tax requirements require expert validation and effective-dated tests before production enablement.

### Standard delivery contract for **every phase**

Implement (as relevant): domain entities/schema, services/rules, authorization, REST APIs, frontend workflows, imports/exports, reports, events/integrations, settings, audit, edge cases, security, tests, migration and rollback notes. Provide completion report: tasks done/partial/blocked, files and migrations, endpoints/screens, tests actually run, unresolved defects, integration status, and next task. **Stop after the assigned task or phase.**

---

# PHASE 1 — PRODUCTION FOUNDATION & DESIGN SYSTEM

**Goal:** Production-ready shared SaaS foundation; do not start domain modules.

- Clean React/Vite/JavaScript frontend, Laravel REST backend, MySQL setup, environment management, migrations, CI/test conventions, API client, error handling, logging, queues, modular domain structure.
- Auth: login/logout, forgot/reset/change password, secure sessions, remember-me, rate limiting, lockout; architecture for MFA, Google/Microsoft SSO, SAML/OIDC.
- Multi-tenancy: Tenant, Legal Entity, Country, Location, Business Unit, Department, Team, Cost Center, Designation, Position, Grade, Level, Employee Type; tenant-aware onboarding/company wizard.
- RBAC and scopes: permission groups, admin UI, entity/action/field permissions, employee self/team/department/location/entity scopes; server enforcement.
- Restrained purple UI system: Button, Input, Select, Multi-select, Textarea, Checkbox, Radio, Switch, Date/Range/Time Pickers, Badge, Avatar, Tooltip, Dropdown, Popover, Modal, Drawer, Tabs, Accordion, Breadcrumb, Pagination, Skeleton, Empty/Error States, Toast, Confirmation Dialog, Data Table, Filter Bar, Search, Stat Card, Activity Timeline.
- App shell: desktop sidebar, header, responsive navigation, page header, breadcrumbs, notifications, profile, quick actions, permission-aware nested navigation, global search placeholder.
- Role-aware dashboard: greeting, My Work, quick actions, today's attendance placeholder, people away placeholder, events, announcements, approvals, alerts.
- Shared My Work task contract: source module/entity, assignee, status, priority, due date, action URL, metadata, filters; notifications, audit, settings, domain events, approval integration foundation.
- Tests: authentication, authorization, tenant isolation, responsive shell, reusable UI, errors, setup wizard.
- **Gate:** Other phases can build on stable auth, tenancy, design, organization, task, notification and audit foundations. **STOP.**

---

# PHASE 2 — CORE PEOPLE & EMPLOYEE MANAGEMENT

**Goal:** Authoritative employee/person/employment system and Employee 360.

1. **People schema:** Person, Employment, Employee Profile, Employee Number, Employment Period, Employment Assignment, Job, Position, Manager Relationship, Organization Assignment, Contact, Address, Emergency Contact, Dependent, Identity Document, Bank Reference, Work Authorization, Education, Experience, Certification, Skill, Employee Document, Notes, Tags, Custom Fields; reuse Phase 1 organization tables. Person ≠ Employment ≠ User.
2. **Employee lifecycle foundation:** draft/create/invite, guided employee wizard, configurable collision-safe numbering, active/inactive/probation/confirmed/suspended/separated states, effective-dated promotion/transfer/manager/job/grade changes, multiple employment periods, separation/rehire foundations and history (full workflows deferred to Phase 7).
3. **Directory:** list/card, secure advanced search, filters/sorting, saved views, bulk operations, data-scope-aware CSV/XLSX exports, duplicate detection.
4. **Employee 360:** overview, job, personal, contact, employment, documents, education, experience, skills, certifications, dependents, emergency contacts, notes, timeline, custom fields; future module slots; completeness, contextual actions, permission-based visibility.
5. **Organization:** org chart, manager/direct reports, historical reporting, effective-dated department/location/entity/team/cost-center assignments, vacancy context, hierarchy cycle/conflict validation.
6. **Employee self-service:** view/update permitted fields, photo, contacts, address, emergency contact, documents; approval-based change requests, change history, notifications.
7. **Documents/security:** categorized versioned files, verification, expiration/reminders, country-specific requirements, secure storage, retention, field masking and audit; bank/identity/medical sensitivity.
8. **Custom fields:** tenant-configurable text/number/date/select/multi-select/boolean, validation, conditional required/visibility, country/entity scope, authorized search/export.
9. **Imports:** CSV/XLSX mapping, preview, validation, employee matching, duplicates, error-row export, retries, audit; secure bulk changes and invitations.
10. **Differentiators:** Employee 360; People Action Center (probation, expiry, missing fields); Organization Memory (historical manager/policy/employment state); Contextual HR actions; HR Data Quality Engine (duplicates, incomplete profiles, invalid assignments).
11. **API groups:** `/api/v1/people`, `/employees`, `/employments`, `/assignments`, `/org-chart`, `/documents`, `/profile-change-requests`, `/custom-fields`, `/imports`, `/history`; role/data-scope enforcement.
12. **Tests:** person/user distinction, numbering collisions, permissions, sensitive data, org cycles, effective dates, future/backdated changes, transfers, separation/rehire, duplicate import, documents, ESS, responsive UI.

**Gate:** Employee and employment history are reliable authoritative inputs for all later modules. **STOP PHASE 2.**

---

# PHASE 3 — ATTENDANCE, SHIFTS & TIME OFFICE

**Goal:** Deterministic, timezone-safe, explainable attendance from raw events to locked payroll-ready results.

1. **Separate layers:** immutable raw punch/device events → calculated attendance → approved/finalized attendance; retain calculation version and trace.
2. **Calendar/time:** UTC timestamps, tenant/location/employee/device/shift timezones, DST, cross-midnight logical shift dates, effective-dated work weeks, weekly offs, full/half/custom days, legal entity/location/employee holiday calendars, optional holidays.
3. **Shifts:** fixed/flexible/split/night/rotating, segments, core hours, start windows, breaks, grace, full/half thresholds, rest rules, policy precedence, effective-dated assignment, rotations.
4. **Rosters:** draft/publish/version, daily/weekly/monthly view, bulk/copy, rotations, minimum rest and coverage conflicts, shift swaps/change approvals, notifications, employee upcoming shifts.
5. **Collection:** secure web/mobile check-in/out, kiosk, biometric/RFID/device adapter foundation, CSV/XLSX/API ingestion, external event IDs, employee-device mapping, sync runs, dedupe, idempotency, anti-replay, geofence/IP/device rules, privacy-controlled location, remote/hybrid attendance. No continuous employee tracking.
6. **Engine:** first/last punch, paired events, gross/net work, paid/unpaid breaks, late/early, missing punch, shift mismatch, half/full day, present/absent, weekly off/holiday, on-duty/remote, OT, exceptions; configurable versioned policy rules and transparent trace.
7. **Corrections:** regularization requests, manual adjustments, attachments/reasons, centralized manager/HR approvals, maker-checker, rejection/withdrawal, before/after history, recalculation and audit.
8. **Overtime:** pre/post shift, rest/holiday work, eligibility, minimums/caps/rounding, requested/approved vs calculated OT, on-duty and remote requests, comp-off eligibility event; **no salary calculations**.
9. **Processing:** on-punch/scheduled/manual/import recalc, bulk queues, retries, partial failures, concurrency, period cutoffs, lock/unlock with approval, historical reproducibility and normalized payroll output.
10. **UX:** employee today/punch/calendar/regularization/OT/on-duty/roster; manager team attendance/roster/exceptions/approvals; HR Time Office Command Center, devices, imports, policies, processing, locks, reports, saved views/exports.
11. **Differentiators:** Attendance Explainer, Rule Trace, Day Reconstruction, Attendance Confidence (data quality not performance), Time Office Command Center, Attendance Health Engine, Smart Exception Prioritization, Policy Simulator foundation, Impact Preview, Payroll Readiness.
12. **Integrations:** Employee 360, My Work, Notifications, Audit, Organization Memory; Phase 4 leave-aware segments and Phase 5 payroll input contracts.
13. **API groups:** `/api/v1/attendance` (me/team/daily/events/punches/regularizations/overtime/on-duty/exceptions/process/locks/devices/imports/reports), `/shifts`, `/shift-assignments`, `/shift-rotations`, `/rosters`, `/work-weeks`, `/holiday-calendars`, `/attendance-policies`.
14. **Schema:** work_weeks/days, holiday_calendars/holidays/assignments, shifts/segments/assignments/rotations, rosters/entries, attendance_policies/versions/assignments, events/days/exceptions/adjustments/regularizations/actions, overtime/on-duty, devices/mappings/sync_runs, imports/rows, processing_runs/locks/traces, geofences.
15. **Tests:** DST, night/split/rotating shifts, out-of-order/duplicate/missing punches, break/grace/late rules, holiday/off overlaps, roster conflicts, approvals/recalc, devices/import, lock and cutoff, 10k–50k employee batch, tenant scopes, responsive states.

**Gate:** Each attendance result is reconstructible and safely finalized for Leave/Payroll. **STOP PHASE 3.**

---

# PHASE 4 — ADVANCED LEAVE & ABSENCE MANAGEMENT

**Goal:** Ledger-first, policy-driven, explainable leave engine integrated with attendance and payroll contracts.

1. **Domain:** Leave Type/Category/Policy/Version/Assignment/Cycle/Account/Ledger/Balance/Entitlement/Accrual/Request/Segment/Approval/Adjustment/Carry Forward/Expiry/Comp-Off/Restriction/Eligibility/Conflict/Settlement Reference/Lock.
2. **Ledger:** immutable decimal-safe transactions for opening, accrued, reserved, consumed, cancellation/reversal, adjustment, carried-forward, expired, comp-off and encashment; balance views show opening/accrued/carried/consumed/pending/scheduled/expired/encashed/available with traceable calculations.
3. **Types:** annual/casual/sick/medical/parental/maternity/paternity/bereavement/marriage/study/sabbatical/unpaid/comp-off/optional holiday/custom; paid/unpaid/statutory/company categories; days/half/quarter/hours; configurable country/legal-entity applicability, evidence, limits, eligibility, approvals.
4. **Policies:** effective-dated draft/published/scheduled/active/retired versions; employee/group/department/location/grade/entity/country assignments and deterministic precedence; no historical rewrites.
5. **Cycles & entitlements:** calendar/fiscal/anniversary/custom; annual/monthly/weekly/daily/pay-period/anniversary/manual grants; tenure, joiner/leaver/transfer proration, probation, notice, advance leave, negative balances, maximum accumulation and future accrual reconciliation.
6. **Carry/expiry:** none/full/capped/percentage, separate carry validity, expiry transactions, consumption ordering (earliest-expiring/current-first/configurable), caps, alerts.
7. **Duration:** effective roster/shift/workweek/holiday/weekly off/timezone, cross-midnight, half/quarter/hourly, sandwich rules, min/max consecutive leave, advance notice, backdated/future limits, explainable charge.
8. **Requests:** date/time, reason, attachments, delegate/handover, projected balance, reservations, conflicts, approval chains/conditional escalation/delegation, withdraw/reject/cancel/partial cancel/controlled modification, auditable history.
9. **Restrictions:** overlapping requests, attendance/on-duty/roster conflicts, team staffing/blackout dates, document requirements, employment eligibility; warning/block/escalate per policy, never blindly deny statutory entitlements.
10. **Comp-off:** earned from approved OT/rest-day/holiday work or authorized credit, claim/approval, validity, expiry, source-to-use trace; optional/floating holidays; statutory long absence/return-to-work foundation; leave donation optional architecture; encashment **units/reference only**.
11. **UX:** employee My Leave/balances/ledger/forecast/request/calendar/history/comp-off; manager approvals/team calendar/who's away/capacity; HR Leave Operations Center/policies/cycles/accruals/adjustments/imports/locks/reports/exports.
12. **Processing/import:** idempotent scheduled/manual accrual, preview/run/error/retry, cycle carry-forward, opening balance and historical request CSV/XLSX imports, bulk grants/debits with preview, approvals and audit.
13. **Transfers:** policy change on country/entity/location/type, preserve/convert/transfer/expire, leave-type mapping, separation accrual/encashment eligibility/negative balances, future leave and rehire restoration rules.
14. **Integration:** approved leave normalized to Phase 3 attendance and triggers recalc; Phase 5 paid/unpaid/LOP/statutory/comp-off/encashment references and cutoff metadata; coordinated locks, no salary calculation.
15. **Differentiators:** Leave Explainer, Policy Sandbox, Entitlement Simulator, Request Impact Preview, Leave Forecast, Policy Difference Viewer, Absence Command Center, Leave Confidence, Team Availability Service, Employee Time Story, HR Data Quality/Action Center.
16. **Security:** medical attachments restricted, purpose-based visibility, retention, secure imports/exports, tenant/data scopes; Employee 360/My Work/Notifications/Audit/Organization Memory/calendar-adapter foundation.
17. **API groups:** `/api/v1/leave` (me/balances/requests/team/calendar/types/policies/versions/assignments/ledger/accrual-runs/adjustments/carry-forward/comp-off/imports/locks/reports/simulations).
18. **Schema:** leave_types, policies/versions/assignments, cycles, accounts, ledger_entries, requests/segments/actions, accrual_rules/runs/items, carry_forwards, expiries, comp_off_credits/claims, restrictions, document_rules, locks, imports/rows, simulation_runs.
19. **Tests:** accrual idempotency, tenure/proration, caps/negative, carry/expiry, sandwich/holiday/off, hourly/night/DST/cross-cycle, concurrency/reservation, approvals/cancellation, comp-off, transfer/separation/rehire, cutoff/locks, attendance recalculation, payroll contract, country rules, medical privacy.

**Gate:** All balances and duration outcomes explainable from policy + ledger; attendance/payroll interfaces safe. **STOP PHASE 4.**

---

# PHASE 5 — GLOBAL PAYROLL, COMPENSATION & BENEFITS

**Goal:** Decimal-safe, auditable payroll from finalized People/Time/Leave inputs to payslips, payment instructions and accounting outputs.

1. **Architecture:** global calculation engine + effective-dated country statutory packs + tenant/entity settings; country pack/version records, legal validation before enablement. Payroll Group, Calendar, Period, Run, Employee Result, Component, Structure, Compensation, Revision, Input, Adjustment, Tax, Contribution, Benefit, Loan, Advance, Retro, Arrear, Payslip, Payment, Journal, Settlement, Lock.
2. **Groups/calendars:** legal entity/country/currency/worker group, weekly/biweekly/semi-monthly/monthly/four-weekly/custom, cutoffs (attendance/leave/variable), approvals/payment dates, off-cycle, period states.
3. **Components:** fixed/variable earnings, deductions, employee/employer contributions, taxes, benefits, reimbursements, informational; versioned safe formulas (no arbitrary code), taxable classification, rounding, caps, LOP/proration, visibility, accounting mapping.
4. **Compensation:** salary structures, base/gross/annual/hourly/daily rates, currency, effective-dated components, revision/increment/promotion/market correction, future/backdated changes, history, mass revision preview/approval, salary-letter template foundation.
5. **Inputs:** employee/employment, finalized attendance/payable days/OT, leave/LOP/encashment reference, bonus/commission/incentive/shift allowance, loan/advance, benefits, reimbursement, imported/manual/API; each input has provenance, period, approval.
6. **Engine:** joiner/leaver/mid-period proration, payable days, earnings/deductions, taxes/contributions, gross/net, retro/arrears/reversals, country rounding, off-cycle and settlement; immutable traces, versioned formulas/packs, reproducible runs, decimal arithmetic.
7. **Country packs:** taxes, social insurance, pension/provident, local taxes, mandatory benefits, statutory IDs, declarations/proofs, limits, reporting, tax-year and rule versioning; require verified country tests.
8. **Benefits/loans:** plans/providers, eligibility/enrollment, employer/employee cost, effective dates, loan principal/interest/installments/outstanding, advance recovery; no banking product.
9. **Run flow:** readiness → collect → validate → calculate → exceptions/variance → maker-checker approve → lock → outputs → payment → close; idempotent batches, retries, partial failures, authorized reopen with audit.
10. **Validation:** missing salary/bank/statutory data/group, overlapping compensation, duplicate inputs, unfinalized attendance/leave, formula failure, unexpected zero/negative net, pay variance, currency mismatch, statutory failures; info/warn/block severity.
11. **Reconciliation:** employee and group gross/deductions/net, payment instructions, accounting journals; detect mismatch before release; compare current/prior and draft/approved runs.
12. **Payslips:** tenant/country layouts, earnings/deductions/contributions/YTD, masked bank, secure employee history/download, versioned corrections and restricted access.
13. **Payments:** provider/country bank file adapters, generated-by/version/totals, statuses not-prepared/prepared/submitted/processing/paid/failed/partial; never claim payment without confirmation.
14. **Accounting:** component-to-GL, legal entity, department/cost center/location/project foundation, journal export/API and reconciliation.
15. **Settlement:** final salary, unused leave encashment, notice pay, bonus, loan/advance/benefit recovery, country-specific rules, approval and itemized explanation.
16. **UX:** employee payslips/compensation/benefits/loans; payroll team Payroll Command Center/readiness/input/exceptions/variance/runs/locks; finance restricted bank/accounting views; reports/secure imports/exports.
17. **Differentiators:** Payroll Explainer (net→component→formula→input→source), Difference Viewer, Payroll Simulator, Salary Revision Impact Preview, Payroll Readiness Map, deterministic Payroll Confidence, Payroll Change Story, Control Totals, Compensation Insight foundation.
18. **Integrations:** Employee 360, My Work, Notifications, Audit, Organization Memory, HR Data Quality/Action Layer, Phase 3/4 inputs, Phase 6 offer compensation; privacy, maker-checker, field permissions, encryption, export audits.
19. **API groups:** `/api/v1/payroll` (groups/calendars/periods/runs/inputs/adjustments/validations/payments/payslips/settlements/country-packs/simulations/reports), `/api/v1/compensation` (structures/components/revisions), `/api/v1/benefits`.
20. **Schema:** payroll_country_packs/versions, groups/calendars/periods/runs/run_employees, salary_components/versions/structures, employee_compensations/components/revisions, payroll_inputs/adjustments/calculation_results/result_components/validation_issues/locks, payslips, payment_batches/instructions/bank_file_exports, accounting_mappings/exports, loans/installments/advances, benefit_plans/enrollments, settlement_runs, simulation_runs.
21. **Tests:** tenant/field access, safe formula, precision/rounding, country pack versions, pay frequencies, joiner/leaver, retro across tax year, midperiod revisions, LOP/OT, zero/negative net, loans/benefits, lock/reopen, bank/accounting reconciliation, payslip corrections, transfer, settlement, off-cycle and large batches.

**Gate:** Payroll is deterministic, legally validated for each enabled country, explainable, reconciled and securely approved. **STOP PHASE 5.**

---

# PHASE 6 — RECRUITMENT, ATS & HIRING

**Goal:** Complete requisition → application → interview → offer → pre-joiner → employee conversion without duplicate person records.

1. **Separate entities:** Job Definition/Version, Position/Headcount, Requisition/Approval, Posting, Candidate, Application, Source, Pipeline/Stage/History, Assessment, Interview/Panel/Scorecard/Feedback, Offer/Version/Approval, Consent, Talent Pool, Referral, Agency, Pre-Joiner, Conversion.
2. **Demand/requisition:** new/replacement/backfill/contract/intern, openings, budget reference, manager/recruiter/team, grade/entity/location, target date, conditional approvals, draft/approved/open/on-hold/filled/closed, filled/open/frozen headcount.
3. **Job library:** role/family/function, grade/level, JD responsibilities/skills/experience/education, versions, internal/external postings, work mode, employment type.
4. **Career site:** tenant branding, job list/detail/search/filters, responsive accessible apply, consent/privacy notice, safe uploads, rate limiting/anti-spam.
5. **Candidate:** contact/resume versions/parsed-vs-verified fields, experience/education/skills/certifications/portfolio/work authorization/location/notice/compensation expectations, tags/notes, duplicate detection with review; one candidate → multiple applications.
6. **Applications:** configurable questions and lawful knockout checks, sources/original attribution, recruiter/team, status, history, rejection reasons, withdrawal, confidential jobs.
7. **Pipelines:** configurable stages/requirements/SLAs, Kanban and bulk move, transition validation, stage history, aging and stuck candidates; stage separate from final status.
8. **Sourcing:** employee referrals/reward references, agencies/contacts/submissions/restricted portal foundation, direct/job-board/social/campus/import, consent-aware talent pools, tags and rediscovery.
9. **Screening/assessments:** recruiter notes, eligibility, availability, internal/external assessment adapters, technical/case/questionnaire, results and source provenance.
10. **Interviews:** phone/video/in-person/panel, timezone-safe scheduling/rescheduling/cancellation, Google/Microsoft calendar adapter foundation, kits/question bank, scorecards/competencies/rating scales, independent confidential feedback and deadlines.
11. **Structured hiring:** comparable job-related criteria, human decision records, candidate comparison, no automated rejection based on inferred/protected traits, no opaque fit scores.
12. **Communications:** application/interview/reminder/rejection/offer templates, localized branding/safe variables, message history, candidate portal foundation for status/docs/interview/offer.
13. **Offers:** compensation linked to Phase 5, legal entity/role/position/grade/join date/conditions, conditional approval, versions/negotiations, branded letters, e-sign adapter foundation, draft/pending/approved/sent/viewed/accepted/declined/expired/withdrawn.
14. **Checks/pre-joining:** candidate consent, background-check provider reference/status (not autonomous decisions), secure document collection, joining readiness, pre-joiner foundation (full onboarding in Phase 7).
15. **Conversion:** validate accepted offer, position, manager, entity, employment type, joining, compensation, documents; check existing/current/former/internal employee; create/link Person, Employment, Employee Profile, assignment, compensation and invitation without duplicates; preserve recruitment history with access controls.
16. **UX:** recruiter dashboards/pipelines/bulk actions; hiring manager focused approvals/reviews/interviews; candidate mobile careers/portal; agency restricted scope; saved views and exports.
17. **Analytics:** applications, open roles, stage conversion/time, time to screen/offer/hire, requisition aging, source performance, offer acceptance; precise metric definitions.
18. **Differentiators:** Talent Acquisition Command Center, Hiring Explainer, Pipeline Health, Hiring Readiness, Candidate Journey, Hiring Impact Preview, Talent Memory (retention-aware), Internal Talent foundation, Recruitment Data Quality.
19. **Privacy:** notice versions, consent purpose/timestamp, configurable country retention, deletion/anonymization, protected data separation, restricted notes/files, candidate access/export controls; AI assistance only as reviewed drafts/summaries/questions, no opaque ranking or automatic decisions.
20. **Integrations:** Phase 2 People/positions, Phase 5 compensation, Phase 7 onboarding contract, Employee 360 (restricted history), My Work, Notifications, Audit, Organization Memory, Action Layer, domain events.
21. **API groups:** `/api/v1/recruitment` (requisitions/jobs/positions/postings/candidates/applications/pipelines/interviews/feedback/assessments/offers/talent-pools/referrals/agencies/imports/reports), `/api/v1/careers/jobs`, `/applications`.
22. **Schema:** job_definitions/versions, requisitions/approvals, postings, pipelines/stages, candidates/contacts/documents/experience/education/skills, applications/answers/stage_history, sources, talent_pools/members, referrals, agencies/submissions, assessments/candidate_assessments, interviews/panel/scorecards/feedback, offers/versions/approvals, candidate_consents/communications/imports/conversions.
23. **Tests:** multi-application, duplicate/rehire/internal candidate, confidential job, approval, pipeline history, interview timezone, feedback privacy, offer versions/expiry/withdrawal, consent/retention, agency scope, career abuse, conversion and compensation integration.

**Gate:** Hiring flows end-to-end with human accountability and no duplicate employee master. **STOP PHASE 6.**

---

# PHASE 7 — ONBOARDING, OFFBOARDING & EMPLOYEE LIFECYCLE

**Goal:** Orchestrate joining, internal moves and separation using shared workflows, tasks and People records.

1. **Lifecycle cases:** pre-boarding, onboarding, probation/confirmation, transfer, promotion, role/location/legal-entity change, contract renewal, extended absence return, resignation, termination, retirement, exit, rehire; effective-dated case states and approvals.
2. **Templates:** country/entity/role/department/worker-type-specific checklist templates with versions, relative due dates, owners, dependencies, mandatory vs optional tasks, conditional branches and SLA/escalations.
3. **Preboarding:** Phase 6 accepted-offer handoff, joining confirmation, secure forms, identity/bank/tax/document collection, consent, digital acknowledgment, employee record reconciliation and duplicate prevention.
4. **Onboarding:** HR, IT, facilities, manager, finance and employee task ownership; welcome communications, orientation, buddy, equipment/account requests, policy acknowledgments, training assignments as Phase 9 integration placeholders, completion gates.
5. **Provisioning:** IT ticket/identity-provider integration adapters, access requests and deprovisioning; never claim accounts provisioned without integration confirmation.
6. **Probation:** start/end, review reminders, extensions, confirmation decisions, effective-dated employment updates, approvals, audit.
7. **Internal changes:** manager/location/role/grade/entity changes, transfer approvals, policy/payroll/leave/attendance impact preview, tasks, controlled effective-date propagation and history.
8. **Resignation:** employee/HR initiated, notice calculation via configured rules, proposed/approved last day, withdrawal/extension, handover, exit approvals and sensitive case access.
9. **Offboarding:** equipment recovery, access revocation, documents, knowledge transfer, exit interview, compliance tasks, benefits/payroll/leave finalization and Phase 5 settlement references.
10. **Rehire:** link previous Person, new Employment period, document revalidation, policy/benefit reinstatement rules, controlled provisioning.
11. **UX:** employee joining/exit portal, manager checklist, HR Lifecycle Command Center, task timeline, progress, blockers, overdue items, templates, bulk assignments and reports.
12. **Differentiators:** Lifecycle Readiness, Joining Risk/Blocker Explainer, Day-One Readiness, Exit Clearance Map, Lifecycle Timeline, Cross-Module Impact Preview, Organization Memory.
13. **Data/API:** lifecycle_cases, case_events, templates/versions, checklist_tasks/dependencies, acknowledgments, handovers, exit_interviews, clearance_items, provisioning_requests; `/api/v1/lifecycle/*`, `/onboarding/*`, `/offboarding/*`.
14. **Controls/tests:** tenant/RBAC, private exit notes, approvals, deadline shifts, duplicate joining, delayed joining, no-show, manager change, rehire, retroactive transfers, partial provisioning, payroll/leave locks, country notices, task retries and audit.

**Gate:** Joining and exit are coordinated, verifiable, and integrated with People, ATS, Time, Leave and Payroll. **STOP PHASE 7.**

---

# PHASE 8 — PERFORMANCE, GOALS, OKRs & KPIs

**Goal:** Fair, configurable performance cycles, goals, feedback and development planning.

1. **Core entities:** Performance Cycle, Template, Goal/OKR/KPI, Key Result, Goal Assignment, Check-in, Review, Review Section, Competency, Rating Scale, Self Review, Manager Review, Peer/360 Feedback, Calibration, Development Plan, Acknowledgment, Appeal.
2. **Cycles:** annual/half-year/quarterly/custom, draft/publish/open/close, employee/department/country applicability, effective-date rules, probation/transfer/leaver handling.
3. **Goals:** individual/team/organization alignment, weighted goals, measurable targets, progress/check-ins, evidence, approvals, rollups, versioning, cascading, dependencies and ownership changes.
4. **Review templates:** competencies, values, goals, rating/weight rules, self/manager/skip-level/peer/360, comments, evidence, mandatory sections and configurable anonymity.
5. **Workflow:** nomination, launch, reminders, self review, manager review, calibration, sign-off, acknowledgment, appeal/reopen with audit; reuse My Work and approvals.
6. **Feedback:** continuous feedback, recognition, 1:1 meeting notes with appropriate confidentiality, development suggestions; avoid surveillance or ungrounded scoring.
7. **Calibration:** role-restricted review distribution, justification, controlled changes, history, fairness checks, bias warnings; human decisions required.
8. **Development:** individual development plans, skill gaps, actions, milestones, mentoring and Phase 9 learning references.
9. **Analytics:** cycle completion, goal progress, review distributions, calibration changes, overdue actions, team-level trends with privacy thresholds.
10. **Differentiators:** Performance Story, Goal Alignment Map, Review Readiness, Explainable Rating Trace, Fairness/Consistency Checks, Development Action Center.
11. **Data/API:** performance_cycles/templates, goals/key_results/checkins, competencies/scales, reviews/sections/feedback, calibration_sessions/changes, development_plans, appeals; `/api/v1/performance/*`, `/goals/*`.
12. **Tests:** rating math/weights, goal changes, transfers, multi-manager, 360 confidentiality, calibration permissions, cycle close/reopen, reviewer changes, tenant/data scopes, mobile review and historical records.

**Gate:** Transparent, permission-aware, configurable performance process; no automated employment decisions. **STOP PHASE 8.**

---

# PHASE 9 — LEARNING, DEVELOPMENT & SKILLS MANAGEMENT

**Goal:** Learning administration, skills taxonomy, course delivery and employee growth linked to performance.

1. **Core:** Skill Taxonomy, Skill Level, Employee Skill/Evidence, Course, Module, Lesson, Learning Path, Cohort, Session, Enrollment, Assignment, Completion, Assessment, Certification, Renewal, Instructor, Provider, Training Budget Reference.
2. **Catalog:** searchable categorized courses, online/classroom/blended, prerequisites, duration, language, localization, content/files/video link adapters, versioning and accessibility.
3. **Learning paths:** mandatory/optional, role/grade/location/country/skill targeting, sequence/dependencies, due dates, renewals, exemptions and equivalency.
4. **Enrollment:** self-enroll, manager/HR assignment, approval, capacity/waitlist, session scheduling, attendance and cancellation, progress tracking.
5. **Assessment:** quizzes, assignments, pass thresholds, attempts, grading, completion certificates and verified evidence; no insecure client-only scoring.
6. **Compliance learning:** mandatory policies/training, recurring certification, expiration reminders, historical completion and audit.
7. **Skills:** self/manager/verified assessments, proficiency rubric, evidence, expiration, skill gap, role profiles, career/goal links; avoid opaque skill scores.
8. **Development:** Phase 8 plan linkage, recommended learning from explicit rules, mentoring/coaching foundation, internal opportunities hooks.
9. **UX:** employee learning hub, manager team learning, L&D command center, catalog/content/session/assignment admin, progress and reporting.
10. **Differentiators:** Skills Passport, Learning Readiness, Certification Risk Map, Development Journey, Skill-to-Role Gap Explainer.
11. **Data/API:** skills/levels/evidence, courses/versions/modules/lessons, paths/steps, sessions/cohorts, enrollments/assignments, assessments/attempts, certifications/renewals; `/api/v1/learning/*`, `/skills/*`.
12. **Tests:** prerequisite cycles, enrollment capacity, concurrent attempts, completion rules, certification expiry, role changes, content permissions, data scopes, accessibility, retention.

**Gate:** Learning and skills history is verifiable, role-aware and connected to employee development. **STOP PHASE 9.**

---

# PHASE 10 — EMPLOYEE EXPERIENCE, ENGAGEMENT & HR HELPDESK

**Goal:** Privacy-conscious employee communication, listening, recognition and HR service delivery.

1. **Announcements:** targeted by role/location/entity, scheduled/published/expired, acknowledgments, attachments, pinned messages, read metrics and access scopes.
2. **Surveys:** engagement/pulse/onboarding/exit/custom, question bank, scales, anonymous/confidential/identified modes, eligibility, scheduling, reminders, consent and retention.
3. **Privacy:** minimum response thresholds, no individual re-identification from small groups, separated sensitive survey responses, country privacy controls and secure exports.
4. **Recognition:** peer/manager appreciation, values tags, approval/moderation, feed, opt-outs, reward reference only (not monetary payroll by default).
5. **HR helpdesk:** service catalog, ticket categories, priority, SLA, intake, routing, assignment, comments, attachments, internal/private notes, escalation, resolution, reopen, satisfaction feedback.
6. **Knowledge base:** searchable HR policies/FAQs/articles, localized versions, approval/publishing, visibility, effective dates, feedback, related ticket deflection.
7. **Employee communication:** birthdays/work anniversaries where permitted, events, campaigns, onboarding communications, notification preferences and channel integrations foundation.
8. **UX:** employee experience hub, surveys, recognition, service portal, HR service command center, admin templates, manager permitted insights.
9. **Analytics:** response/participation, trends with privacy thresholds, ticket backlog/aging/SLA, service satisfaction, knowledge effectiveness; no invasive employee sentiment scoring.
10. **Differentiators:** Employee Voice with Privacy Guardrails, HR Service Health, Actionable Engagement Themes, Employee Experience Timeline, Service Resolution Explainer.
11. **Data/API:** announcements/audiences/acknowledgments, surveys/questions/responses/campaigns, recognitions, service_categories/tickets/actions/SLAs, knowledge_articles/versions; `/api/v1/experience/*`, `/surveys/*`, `/hr-service/*`.
12. **Tests:** anonymous survey privacy, small-group suppression, service data scope, sensitive ticket notes, escalation timing, notification preferences, moderation, tenant isolation.

**Gate:** Employee communications and HR services operate securely with meaningful privacy protections. **STOP PHASE 10.**

---

# PHASE 11 — ASSETS, EXPENSES & TRAVEL MANAGEMENT

**Goal:** Track employee assets and reimbursement/travel operations without duplicating Payroll or Accounting.

1. **Asset registry:** category, identifier/serial, model, purchase/warranty references, condition, location, custodian, stock, status, depreciation reference only if not implementing accounting.
2. **Lifecycle:** procure/import, inventory, assign, acknowledge, transfer, repair, loss/damage, return, disposal, audit and handover; Phase 7 joining/exit hooks.
3. **Expenses:** categories, policy versions, currency, receipt/document, mileage/per-diem, spend limits, tax classification reference, submission, split allocation, approval, rejection, audit and reimbursements.
4. **Expense rules:** country/entity/grade/location eligibility, receipt thresholds, duplicate receipt detection, policy exceptions, fraud review signals with human decision.
5. **Travel:** request, itinerary, purpose, destinations, dates/timezones, budget, estimated cost, policy eligibility, approvals, bookings/provider references, advances, post-trip expense reconciliation.
6. **Multi-currency:** explicit transaction and reimbursement currencies, dated exchange rates/source, rounding and approval of overrides.
7. **Payment interface:** approved reimbursement/advance outputs to Phase 5 payroll or external AP; prevent double payment, reconcile statuses, never claim payment without confirmation.
8. **UX:** employee assets/claims/trips, manager approvals, HR/finance operations consoles, receipt upload, expense reports, asset inventory and reconciliation.
9. **Differentiators:** Asset Chain of Custody, Expense Policy Explainer, Claim Readiness, Travel Budget Impact Preview, Exit Asset Clearance, Duplicate Claim Review.
10. **Data/API:** assets/categories/assignments/movements/maintenance, expense_policies/claims/lines/receipts/approvals, travel_requests/itineraries/advances, reimbursement_batches; `/api/v1/assets/*`, `/expenses/*`, `/travel/*`.
11. **Tests:** lost/damaged assets, duplicate claims, receipt security, currencies, approval limits, multi-leg travel, late expenses, transfer/exit, payroll/AP duplicate payment protection, tenant scopes.

**Gate:** Asset custody and expense/travel approvals reconcile to employees and finance interfaces. **STOP PHASE 11.**

---

# PHASE 12 — ADVANCED WORKFLOW & AUTOMATION ENGINE

**Goal:** Extend the existing shared My Work/approval/events foundation into one configurable, auditable workflow engine.

1. **Model:** Workflow Definition/Version, Trigger, Condition, Step, Action, Assignment, Instance, Task, Approval, Timer, Escalation, Webhook, Execution Log, Retry, Dead-Letter, Simulation.
2. **Triggers:** domain event, date/time, schedule, status transition, threshold, manual initiation, API; use explicit tenant/source scope and idempotency.
3. **Designer:** visual or structured builder, drafts/published versions, step validation, conditions, branching, sequential/parallel approvals, dynamic assignee, delegation, fallback, deadlines, cancellation.
4. **Actions:** create/update task, notify, approval, field update via authorized domain service, generate document, webhook, integration action; whitelisted secure action catalog, not arbitrary tenant code.
5. **Execution:** event-driven queues, exactly-once effect where feasible via idempotency, retries/backoff, timeout, failure recovery, pause/resume/cancel, replay safeguards, immutable logs.
6. **Governance:** permission checks at configuration and execution, tenant/data scope, secrets vault integration, rate limits, approval of high-impact workflow changes, version history.
7. **Simulation:** dry-run with sample events and preview affected records/actions; no production side effects.
8. **Monitoring:** active/failed/stuck instances, SLA, dead-letter queue, audit timeline, execution trace and remediation tools.
9. **Migration:** map earlier module-specific approval/task hooks to the central engine without breaking existing cases; no duplicate approval infrastructure.
10. **Differentiators:** Workflow Explainer, Automation Impact Preview, Failure Recovery Center, Policy-Aware Approval Routing, Cross-Module Action Chain.
11. **Data/API:** workflow_definitions/versions/triggers/steps, instances/executions, tasks, timers, webhooks, failures; `/api/v1/workflows/*`, `/automations/*`.
12. **Tests:** concurrent approvals, parallel branches, loops/cycle prevention, retries, duplicate events, permission changes mid-run, version change, failed webhook, cross-tenant leakage, escalation and dry-run purity.

**Gate:** All modules can orchestrate work through one reliable, explainable shared engine. **STOP PHASE 12.**

---

# PHASE 13 — HR ANALYTICS & WORKFORCE INTELLIGENCE

**Goal:** Trusted workforce metrics and decision support grounded in auditable cross-module data.

1. **Metric catalog:** standardized definitions, ownership, version, numerator/denominator, dimensions, effective dates, access scope, refresh cadence, lineage.
2. **Core metrics:** headcount/FTE, hires/leavers/attrition, tenure, diversity only where lawful/appropriate, absenteeism, overtime, leave utilization, payroll totals/variance, hiring funnel, learning completion, performance cycle status, HR ticket SLAs.
3. **Temporal correctness:** as-of headcount, historical org hierarchy, policy/assignment versions, legal-entity transfers, period snapshots, timezone/currency handling; no current-state joins for historical facts.
4. **Pipelines:** event-driven/ETL materialization, incremental refresh, backfills, data quality checks, retries, reconciliation to source domains, refresh timestamps.
5. **Dashboards:** executive, HR, manager, payroll, recruiter, country/legal-entity, configurable filters, saved views, drilldown, exports and scheduled delivery.
6. **Report builder:** authorized dimensions/metrics, group/filter/sort, visualization, preview, row limits, saved reports, permissions and async export.
7. **Privacy:** minimum cohort thresholds, sensitive attribute handling, aggregate permissions, suppression of small groups, audit of exports and queries.
8. **Forecasting:** transparent scenario/assumption-based workforce planning (headcount, attrition, absence, cost), confidence/limitations, no unsupported predictive employee risk scores.
9. **Differentiators:** Metric Explainer, Workforce Change Story, Insight-to-Action links, Readiness/Quality indicators, Scenario Sandbox, Cross-Module Timeline.
10. **Data/API:** metric_definitions/versions, snapshots, report_definitions, dashboard_configs, refresh_runs, scenario_runs, access_logs; `/api/v1/analytics/*`, `/reports/*`.
11. **Tests:** source reconciliation, historical/as-of metrics, data-scope aggregation, cohort suppression, country/currency/timezone, large datasets, refresh lag, failed backfills, exports.

**Gate:** Analytics are explainable, permission-safe, historically accurate and reconciled to source modules. **STOP PHASE 13.**

---

# PHASE 14 — INTEGRATIONS & ENTERPRISE PLATFORM

**Goal:** Secure, manageable ecosystem connectivity without hard-coding vendors into domains.

1. **Integration platform:** Provider, Connection, Credential Reference, Scope, Mapping, Sync Job, Webhook Subscription, Event Delivery, Error/Retry, Rate Limit, Health Status and Audit.
2. **Identity:** Google/Microsoft SSO, SAML/OIDC, SCIM provisioning/deprovisioning adapters, directory mapping, least-privilege and idempotent user matching.
3. **HR/payroll/accounting:** finance GL, banks/payment providers, payroll external providers, benefits, tax/reporting interfaces, ATS job boards, background checks, LMS, calendars, document signing, messaging and ITSM adapters.
4. **Developer APIs:** documented versioned REST, tenant-scoped API credentials, scopes, pagination, idempotency keys, webhooks with signatures, replay protection, rate limiting and deprecation policy.
5. **Data sync:** inbound/outbound field mappings, transformations, dry-run, conflict resolution, source-of-truth rules, cursor/checkpoint, retries, dead-letter and manual reconciliation.
6. **Security:** OAuth/token lifecycle, encryption, secret rotation, permission grants, tenant isolation, credential redaction, webhook signature verification, IP/allowlist optional, connection revocation.
7. **Admin UX:** integration catalog, connection setup, health, logs, mapping, test connection, sync history, errors, retry and revoke.
8. **Differentiators:** Integration Health Center, Sync Conflict Explainer, Data Lineage, Change Impact Preview, Self-Service Connector Diagnostics.
9. **Data/API:** integrations/providers/connections/scopes/mappings, sync_runs/items, webhook_subscriptions/deliveries, api_credentials, connector_errors; `/api/v1/integrations/*`, `/developer/*`.
10. **Tests:** provider outage, expired token, duplicate/out-of-order webhook, retries, idempotency, mapping changes, PII masking, tenant leaks, rate limits, revoked permissions, partial sync.

**Gate:** External connectivity is secure, observable, resilient and modular; unconnected adapters are clearly marked not live. **STOP PHASE 14.**

---

# PHASE 15 — ADVANCED SECURITY, COMPLIANCE & ENTERPRISE CONTROLS

**Goal:** Enterprise governance hardening across the completed platform; build on, not replace, security foundations.

1. **Identity/security:** MFA enforcement, SSO/SAML/OIDC policy, session/device management, suspicious access alerts, password/session controls, SCIM lifecycle, break-glass admin with strong audit.
2. **Authorization:** fine-grained RBAC/ABAC/data scopes, field-level and row-level access, delegation, separation of duties, just-in-time elevated access where appropriate, periodic access reviews.
3. **Tenant governance:** isolation testing, data residency configuration/architecture, legal-entity segregation, tenant lifecycle, export/migration/deletion, backup/restore isolation.
4. **Privacy:** data classification, consent/lawful-purpose metadata, retention schedules, legal hold, data subject access/deletion/correction workflows, country-specific compliance packs and approval.
5. **Audit:** tamper-evident audit trail architecture, sensitive record reads, change attribution, before/after, retention, search/export, incident reconstruction.
6. **Encryption:** at-rest/in-transit, key management/rotation, secrets, secure file storage and signed URLs; tokenization/masking for bank/identity data.
7. **Operational controls:** security events, alerting, incident response runbooks, vulnerability management, dependency scanning, secure CI/CD, backup/restore drills, disaster recovery, availability targets, monitoring.
8. **Financial/governance:** maker-checker, payroll/payment dual control, privileged exports, workflow policy changes, statutory country-pack approvals, compliance evidence and exceptions.
9. **Enterprise UX:** Security & Compliance Center, access review, audit explorer, retention policies, privacy requests, risk/issues, integration access.
10. **Differentiators:** Permission Explainer, Compliance Evidence Map, Policy Drift Detection, Access Risk Review, Historical Decision Reconstruction.
11. **Data/API:** access_reviews/grants, security_events, privacy_requests, retention_policies, legal_holds, audit_exports, compliance_evidence, control_tests, incident_records; `/api/v1/security/*`, `/compliance/*`.
12. **Tests:** horizontal/vertical privilege escalation, cross-tenant access, payroll/medical privacy, revoked users, delegated permissions, export restrictions, deletion/hold conflicts, key rotation, backup restore, audit integrity, incident simulations.

**Gate:** Enterprise controls have evidence, tests and clearly documented jurisdictional limitations; no unsupported compliance certification claims. **STOP PHASE 15.**

---

# PHASE 16 — HRMS INTELLIGENCE & UNIQUE VALUE LAYER

**Goal:** Unify previously built explainability, action centers and operational intelligence into a differentiated, trustworthy cross-module experience.

1. **Unified intelligence architecture:** Source Event/Fact, Finding, Rule/Version, Evidence, Explanation, Impact, Recommendation, Permission, Action, Resolution, Feedback and Provenance; consume authoritative domain services, never invent facts.
2. **Employee 360+**: permission-aware cross-module timeline across People, Time, Leave, Payroll, Recruiting, Lifecycle, Performance, Learning, Experience, Assets; contextual navigation and historical state.
3. **Organization Memory:** reconstruct as-of organization, policy, compensation, manager, approval and employee journey; effective-dated joins, audit provenance and privacy controls.
4. **HR Action & Intelligence Center:** consolidate issues from all modules; deduplicate related findings, owner/priority/severity, source evidence, impact, recommended action, deep link, status, resolution and SLA.
5. **Explainability framework:** attendance, leave, payroll, policy, workflow, hiring, learning and analytics explainers with calculation trace and version; distinguish facts, forecasts, assumptions and suggestions.
6. **Cross-module scenarios:** new hire readiness, payroll close readiness, leave/attendance conflicts, lifecycle transfer impact, exit clearance, compliance/document expiry, workforce staffing scenarios; preview without mutation.
7. **Policy simulators:** sandbox changes to leave, attendance, payroll/compensation, workflows and organization structure; compare baseline/proposed and affected records; require explicit publish/approval.
8. **Readiness/Confidence:** deterministic status labels (Ready, Pending, Review Required, Blocked) from defined data completeness/control rules; never opaque employee ratings.
9. **Optional AI assistance:** grounded summarization, natural-language navigation/report query, draft communication, suggested workflow actions; cite internal evidence, human confirmation for changes, access-aware retrieval, prompt-injection defenses, opt-in where required.
10. **AI governance:** no autonomous hiring/firing/pay decisions, protected-trait inference, medical inference, employee surveillance scoring or fabricated confidence; logging, evaluation, privacy, model/provider isolation and disable controls.
11. **Unified UX:** command center by role, smart search, employee/time/payroll/hiring stories, contextual action drawer, notification prioritization, resolution history, mobile essential actions.
12. **Analytics/feedback:** action resolution, false-positive reporting, rules precision, processing health, explainability usage; avoid using employee behavior as covert performance scoring.
13. **Data/API:** intelligence_findings/evidence/explanations/rules/versions/actions/resolutions/simulations/feedback, knowledge references; `/api/v1/intelligence/*`, `/explain/*`, `/scenarios/*`.
14. **Tests:** source reconciliation, data lineage, permission redaction, stale data, cross-module event ordering, duplicate findings, simulator purity, explainable outputs, AI hallucination/prompt-injection handling, human approval, load and mobile UX.

**Gate:** Intelligence is useful, explainable, auditable, permission-safe and does not override domain truth. **STOP PHASE 16.**

---

# CROSS-PHASE DEPENDENCY MAP

| Phase | Consumes | Produces for later phases |
|---|---|---|
| 1 Foundation | — | Tenant, auth, org, RBAC, design, My Work, audit |
| 2 People | 1 | Employee, employment, manager, assignments, Employee 360 |
| 3 Time | 1–2 | Attendance, shifts, OT, payroll-ready time |
| 4 Leave | 1–3 | Leave ledger, approved absence, LOP/encashment references |
| 5 Payroll | 1–4 | Compensation, payroll, payment/accounting, settlement |
| 6 Recruitment | 1–2, 5 | Requisitions, candidates, offers, pre-joiners |
| 7 Lifecycle | 1–6 | On/offboarding, internal moves, clearances |
| 8 Performance | 1–2, 7 | Goals, reviews, development plans |
| 9 Learning | 1–2, 8 | Courses, skills, certifications |
| 10 Experience | 1–2, 7 | Surveys, recognition, HR service |
| 11 Assets/Expenses | 1–2, 5, 7 | Custody, claims, travel, reimbursement outputs |
| 12 Workflow | All earlier | Central automation and orchestration |
| 13 Analytics | All earlier | Trusted cross-module metrics and reports |
| 14 Integrations | All earlier | Provider adapters, API/webhook platform |
| 15 Security | All earlier | Enterprise controls, compliance evidence |
| 16 Intelligence | All earlier | Unified explainability, action and scenarios |

**Note:** Foundational hooks for future workflows, analytics, integrations and intelligence may exist in earlier phases; their **full engines** are delivered in Phases 12–16. Never rebuild shared capabilities.

## Suggested task naming and tracking

`P02-T01`, `P02-T02`, … `P16-TNN`. Each task file includes: scope, dependencies, impacted domain, required tables/APIs/UI, explicit acceptance tests, out-of-scope, completion state. Mark `done` only after targeted tests pass and manual verification where applicable.

## Required task completion report (concise)

```
Task: PXX-TYY — <name>
Status: done | partial | blocked
Changed: <paths>
Implemented: <behaviors/endpoints/UI>
Tests: <commands + actual pass/fail>
Security/tenant checks: <results>
Issues: <remaining>
Next: <task ID only>
STOP.
```

## Strict final instruction

**This single file is the 16-phase scope reference. It does not authorize implementing all phases at once.** For each Claude Code session, select **one task** and only its necessary dependencies. Preserve all listed requirements as backlog items; do not treat condensed wording as permission to skip functionality. Confirm completion through code and tests, not prose.
