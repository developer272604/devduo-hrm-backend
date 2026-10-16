# HRMS — COMPACT MASTER SPECIFICATION
## PHASES 2–6 | CLAUDE CODE OPTIMIZED

### PROJECT CONTEXT

Build on the existing Phase 1 production foundation.

**Technology**
- Frontend: React + Vite + JavaScript
- Backend: Laravel REST API
- Database: MySQL
- Architecture: API-first modular monolith
- Product: Multi-tenant, multi-country SaaS HRMS
- Reference ambition: Keka-class enterprise HRMS
- UI: Premium, restrained purple design system, responsive

**Core principle:** All requirements in this document are mandatory within their respective phase unless explicitly marked as a foundation, future integration, or optional configuration.

Do not omit functionality merely because multiple related requirements have been consolidated into one section.

---

# GLOBAL DEVELOPMENT RULES — ALL PHASES

These rules apply to every task in Phases 2–6.

1. Continue existing code; do not recreate Phase 1 foundations.
2. Build only the requested phase and assigned implementation task.
3. Reuse authentication, tenant isolation, RBAC, permissions, data scopes, UI primitives, app shell, notifications, My Work, audit, domain events and settings.
4. Enforce tenant isolation and server-side authorization on every applicable API, query, background job and export.
5. Use domain/application services; avoid giant controllers and business logic in React components.
6. Use versioned REST APIs, validation, consistent errors, pagination, filters and sorting.
7. Use normalized MySQL schemas, foreign keys, indexes, transactions, unique constraints and idempotency where required.
8. Preserve effective-dated history, rule versions, approvals, corrections and audit trails.
9. Use Laravel queues/jobs for imports, exports, recalculation, bulk processing and expensive operations.
10. Support country, legal entity, location, timezone, currency, locale and country-specific rules without hard-coding Indian assumptions globally.
11. Keep employee-sensitive, medical, identity, bank, compensation, candidate and location data appropriately classified, masked, encrypted and permission-restricted.
12. Reuse the centralized approval/My Work architecture; do not create separate task or approval engines inside modules.
13. Integrate modules through defined services, events and contracts rather than duplicating calculations or creating circular dependencies.
14. Provide responsive desktop/tablet/mobile UX with loading, skeleton, empty, error, validation, forbidden, locked, processing and partial-failure states.
15. Preserve the restrained purple design system; avoid excessive gradients, decorative graphs, generic AI styling and fake dashboards.
16. Add relevant backend, frontend, integration, authorization, tenant-isolation and edge-case tests.
17. Run targeted verification, fix failures introduced by the task and report actual results.
18. Do not silently change working code or expand scope.
19. Financial calculations must use decimal-safe arithmetic; dates/times must use explicit timezone-aware logic.
20. Every phase must connect appropriately to Employee 360, Organization Memory, HR Data Quality, My Work, Notifications and the shared HR Action & Intelligence Layer.

### EXECUTION CONTRACT

Implement each phase in small, dependency-ordered vertical slices:

1. Domain schema and models
2. Business rules and services
3. Authorization and REST APIs
4. Frontend pages and reusable UI
5. Events and cross-module integrations
6. Edge cases, tests and verification

For each assigned task:

- Inspect only relevant existing files.
- Write a short plan.
- Implement the complete assigned scope.
- Run targeted tests.
- Report files changed, functionality completed, tests and remaining issues.
- Stop.

Do not implement an entire phase in one execution unless explicitly instructed.

---

# PHASE 2 — CORE PEOPLE & EMPLOYEE MANAGEMENT

## Objective

Create the authoritative employee/person/employment system supporting multi-country organizations, historical changes, employee self-service and secure HR administration.

**Critical:** Person, Employee/Employment and User are separate concepts. A person may have employment history without a login account; do not use the authentication user record as the employee master.

## P2.1 — People Data Architecture

Implement normalized, tenant-scoped entities for:

- Person and personal identity
- Employment and employment periods
- Employee profile and employee number
- Employment assignment and employment status
- Job, designation, position, grade and level
- Department, business unit, team, cost center and location assignment
- Manager/reporting relationships
- Employee type and worker classification
- Personal/work contact information
- Addresses
- Emergency contacts
- Dependents and family information
- Identity documents and country-specific identifiers
- Bank-account references
- Work authorization, visas and permits
- Education, experience, certifications and skills
- Employee documents and document categories
- Notes, tags and custom fields
- Employment events, changes and history

Reuse organizational entities already built in Phase 1. Do not duplicate their tables.

## P2.2 — Employee Lifecycle Foundations

Support:

- Draft and active employee records
- Guided employee creation
- Quick employee creation
- Employee numbering with configurable formats and collision protection
- Employment start, probation, confirmation and status changes
- Active, inactive, suspended, separated and other configurable statuses
- Future-dated and backdated employment changes
- Transfers, promotions, reporting changes and grade changes
- Multiple employment periods
- Separation foundation
- Rehire and historical employment preservation
- Employment-date validation and conflict detection

Do not implement the full onboarding/offboarding workflow reserved for Phase 7.

## P2.3 — Employee Directory

Build:

- Employee directory with list/card views
- Search by name, number, designation, department and other permitted fields
- Advanced filters and sorting
- Saved views where supported
- Bulk selection/actions
- Organization/data-scope filtering
- Secure CSV/XLSX export
- Mobile-friendly employee directory

Respect field-level permissions in search results and exports.

## P2.4 — Employee 360 Profile

Create a permission-aware employee profile with:

Overview, Job, Employment, Personal, Contact, Documents, Education, Experience, Skills, Certifications, Dependents, Emergency Contacts, Notes, Timeline and relevant custom fields.

Prepare extensible tabs/sections for Attendance, Leave, Payroll, Performance and other future modules without implementing those modules in Phase 2.

Include:

- Profile completeness
- Contextual actions
- Historical assignments
- Employment timeline
- Effective-dated changes
- Missing-information indicators
- Sensitive-field masking
- Role-specific views

## P2.5 — Organization & Reporting

Implement:

- Organization chart
- Manager/direct-report relationships
- Historical reporting lines
- Effective-dated organizational assignments
- Transfers across departments, locations, legal entities and teams
- Position/manager validation
- Reporting-line cycle prevention
- Vacant position and reporting context where available

## P2.6 — Employee Self-Service

Employees can view and update permitted information.

Support:

- Personal profile
- Contact information
- Emergency contacts
- Address changes
- Profile photo
- Selected documents
- Profile-change requests
- Approval-based changes where configured
- Status/history of submitted changes

Never permit unauthorized direct edits to sensitive HR-controlled fields.

## P2.7 — Employee Documents

Implement:

- Secure upload/download
- Document categories
- Country-specific document requirements
- Expiry dates and reminders
- Verification status
- Versioning
- Restricted access
- Retention rules
- Audit
- Secure file storage
- Expiring/missing document detection

## P2.8 — Custom Fields

Support tenant-configurable employee fields:

Text, number, date, select, multiselect, boolean and other supported primitives.

Include:

- Validation
- Visibility
- Required rules
- Permission control
- Country/legal-entity applicability
- Search/filter compatibility where appropriate

Do not allow custom fields to bypass security or tenant isolation.

## P2.9 — Imports & Bulk Operations

Support:

- CSV/XLSX employee import
- Template download
- Field mapping
- Preview
- Validation
- Duplicate detection
- Error-row export
- Partial success
- Retry
- Import history
- Bulk employee updates
- Employee invitations
- Secure exports

Use background jobs for large operations.

## P2.10 — People Intelligence

Implement:

**Employee 360:** Authoritative contextual employee view.

**People Action Center:** Expiring documents, probation due, incomplete profiles, pending changes and actionable HR tasks.

**Organization Memory:** Historical organization, manager, job and employee-state reconstruction.

**Contextual HR:** Actions available from relevant employee screens.

**HR Data Quality Engine:** Missing data, duplicates, invalid organizational references, expired documents, effective-date conflicts and inconsistent records.

Use deterministic findings and direct resolution actions.

## P2.11 — Settings, APIs & Integrations

Provide People settings for:

Employee types, numbering, fields, document categories, employment statuses, country-specific requirements, profile visibility and change permissions.

Provide versioned REST APIs for employees, persons, employment, assignments, directory, organization chart, documents, profile changes, custom fields, imports and history.

Integrate My Work, Notifications, Audit, Dashboard, Employee 360 and domain events.

## P2.12 — Verification

Test:

- Person ≠ Employee ≠ User
- Tenant isolation and data scopes
- Employee creation and numbering
- Profile editing and approval
- Effective-dated assignments
- Reporting history and cycle prevention
- Transfers, separation and rehire
- Documents and sensitive permissions
- Imports, duplicates and bulk actions
- Country-specific fields
- Employee 360 and Action Center
- Responsive UI and all error states

**Completion gate:** The People domain is the reliable source of truth for all later HRMS modules.

**STOP AFTER PHASE 2.**

---

# PHASE 3 — ATTENDANCE, SHIFTS & TIME OFFICE

## Objective

Build a deterministic, explainable, timezone-safe attendance system with shifts, rosters, raw punches, attendance calculations, regularization, overtime, devices, locks and payroll-ready output.

## P3.1 — Attendance Architecture

Separate three layers:

1. Immutable raw attendance events
2. Calculated attendance results
3. Approved/finalized attendance records

Support:

Attendance Policy, Policy Version, Work Week, Shift, Shift Segment, Assignment, Rotation, Roster, Holiday, Punch, Break, Exception, Adjustment, Regularization, Overtime, On Duty, Device, Geofence, Processing Run, Calculation Trace and Attendance Lock.

Never rewrite raw punches to make attendance appear correct.

## P3.2 — Timezone & Calendar

Support:

- Tenant, employee, location, device and shift timezones
- DST transitions
- Cross-midnight attendance dates
- Effective-dated work weeks
- Full/half/custom working days
- Weekly offs
- Country/region/location/legal-entity holiday calendars
- Mandatory, optional and half-day holidays
- Employee holiday-calendar assignments
- Holiday/weekly-off interaction

Use server-authoritative timestamps and explicit timezone conversion.

## P3.3 — Shift Engine

Support:

- Fixed, flexible, rotating, split and overnight shifts
- Multiple work segments
- Shift start/end and expected duration
- Flexible start/end windows and core hours
- Grace periods
- Break rules
- Full/half-day thresholds
- Punch windows
- Overtime eligibility
- Rest periods
- Effective-dated shift assignment
- Employee/group/department/location assignment priority
- Shift rotations and custom cycles

## P3.4 — Rostering

Implement:

- Daily/weekly/monthly roster
- Draft/published states
- Bulk assignment
- Copy/rotation
- Shift change
- Version history
- Roster conflict detection
- Minimum-rest validation
- Shift swap/change requests
- Employee upcoming-shift view
- Manager roster administration
- Publication notifications

## P3.5 — Attendance Collection

Support:

- Web check-in/out
- Mobile-ready APIs
- Kiosk/device foundation
- Biometric/RFID/face-terminal adapter architecture
- Generic API and CSV/XLSX punch imports
- Device employee mapping
- Device sync runs and status
- IN/OUT/UNKNOWN punch direction
- Duplicate detection
- External event IDs
- Idempotent ingestion
- Source verification
- IP/device restrictions
- Geofence rules
- Remote/hybrid attendance
- Privacy-controlled location capture
- Anti-replay/integrity signals

Do not continuously track employee locations.

## P3.6 — Calculation Engine

Calculate:

- Assigned shift
- Attendance date
- First In/Last Out
- Gross hours
- Paid/unpaid breaks
- Net hours
- Late arrival
- Early departure
- Missing/unpaired punches
- Insufficient hours
- Full/half day
- Present/absent
- Holiday/weekly off
- On Duty/Remote Work
- Overtime
- Attendance exceptions

Support policy precedence, rule versions, effective dates, recalculation and human-readable calculation traces.

## P3.7 — Exceptions & Regularization

Detect:

Late, early exit, missing IN/OUT, invalid punch sequence, shift mismatch, excessive break, outside geofence, unscheduled work and unresolved attendance.

Provide:

- Exception severity and status
- Employee regularization requests
- Requested punch corrections
- Reasons/attachments
- Manager/HR approval
- Manual HR adjustments
- Maker-checker for sensitive operations
- Before/after history
- Approved recalculation
- Rejection/withdrawal
- Audit and notifications

## P3.8 — Overtime, On Duty & Remote Work

Support:

- Pre/post-shift overtime
- Holiday/weekend/rest-day overtime
- Minimums, rounding, caps and eligibility
- OT pre-approval and post-approval
- Approved vs calculated OT
- On Duty requests and approvals
- Field work, client visits and official travel context
- Remote-work policy and approval context
- Comp-off eligibility events for Phase 4

Do not calculate payroll payments in Phase 3.

## P3.9 — Processing & Finalization

Support:

- On-punch, scheduled, manual and import-triggered processing
- Employee/date and bulk recalculation
- Background jobs
- Processing progress
- Retry/partial failure
- Concurrency protection
- Calculation versioning
- Period cutoffs
- Attendance locks
- Controlled unlock/correction
- Historical integrity
- Finalized payroll-ready summaries

## P3.10 — User Interfaces

**Employee:** Today, check-in/out, shift, hours, attendance calendar, history, regularization, overtime, on-duty and upcoming roster.

**Manager:** Team attendance, who's in/away/late, exceptions, approvals, rosters and overtime.

**HR:** Time Office Command Center, policies, shifts, rosters, devices, imports, exceptions, regularizations, processing, reports and locks.

Support daily/monthly views, advanced filters, saved views and CSV/XLSX exports.

## P3.11 — Differentiators

Implement:

- **Attendance Explainer:** Why an attendance result was calculated
- **Rule Trace:** Policies, punches, breaks, shifts and versions used
- **Day Reconstruction:** Chronological events and corrections
- **Attendance Confidence:** Confirmed/Pending/Review Required data state
- **Time Office Action Center:** Actionable operational issues
- **Policy Simulator:** Safe preview foundation for policy changes
- **Impact Preview:** Affected employees/days before recalculation
- **Payroll Readiness:** Finalization and unresolved issue indicators
- **Attendance Health Engine:** Missing policies, shifts, sync failures and invalid assignments

These are operational features, not employee performance scores.

## P3.12 — Integration

Extend Employee 360, My Work, Notifications, Audit and Organization Memory.

Expose attendance summaries for Payroll and normalized leave-aware contracts for Phase 4.

Use events for punches, calculations, exceptions, approvals, roster changes, locks and device syncs.

## P3.13 — Verification

Test:

- Night, split, flexible and rotating shifts
- Midnight/DST/timezone boundaries
- Holiday/weekly-off overlaps
- Duplicate and out-of-order punches
- Missing punches
- Break calculations
- Grace/late/early rules
- Regularization and recalculation
- Overtime and On Duty
- Device import and mapping
- Roster changes
- Policy versioning
- Attendance locks
- Concurrent processing
- Tenant/data-scope security
- Large-volume processing
- Employee/manager/admin responsive screens

**Completion gate:** Attendance is reproducible, explainable, secure and safely consumable by Leave and Payroll.

**STOP AFTER PHASE 3.**

---

# PHASE 4 — ADVANCED LEAVE & ABSENCE MANAGEMENT

## Objective

Create a ledger-based, policy-driven, multi-country leave system with entitlements, accruals, carry-forward, comp-off, approvals, leave calculations, employee/manager UX and Attendance/Payroll integration.

## P4.1 — Leave Domain

Implement:

Leave Type, Category, Policy, Version, Assignment, Cycle, Account, Ledger, Balance, Entitlement, Accrual, Request, Segment, Approval, Adjustment, Carry Forward, Expiry, Comp-Off, Encashment Reference, Restriction, Eligibility, Conflict, Settlement Reference and Lock.

**Mandatory:** Leave balances derive from auditable ledger transactions, never an arbitrary editable balance field.

## P4.2 — Leave Types & Policies

Support configurable:

- Annual, casual, sick, parental, maternity, paternity, medical, bereavement, marriage, study, sabbatical, unpaid, comp-off, optional holiday and custom leave
- Paid/unpaid/statutory/company-provided classifications
- Days, half-days, quarter-days and hours
- Balance tracking
- Entitlement
- Accrual
- Carry-forward
- Expiry
- Minimum/maximum request
- Negative balance
- Probation/notice-period eligibility
- Attachment/reason rules
- Approval requirements
- Country/legal-entity applicability

Use effective-dated, versioned policies with deterministic employee/group assignment precedence.

## P4.3 — Leave Cycles & Entitlements

Support:

- Calendar, financial, anniversary and custom cycles
- Annual/monthly/weekly/daily/pay-period grants
- Anniversary grants
- Manual grants
- Tenure-based entitlements
- Joiner/leaver proration
- Employment transfer
- Probation restrictions
- Notice-period rules
- Maximum accumulation
- Future/advance leave
- Negative balance limits
- Country-specific eligibility

## P4.4 — Leave Ledger & Accrual

Record immutable, traceable transactions for:

Opening balance, accrual, consumption, reservation, cancellation/reversal, adjustment, carry-forward, expiry, comp-off and encashment.

Support:

- Scheduled accrual
- Manual accrual
- Idempotent processing
- Accrual run history
- Preview
- Skipped/failed employees
- Retry
- Balance reconciliation
- Decimal-safe units
- Concurrent request protection

## P4.5 — Carry Forward & Expiry

Support:

- None/full/capped/percentage carry-forward
- Separate carried-forward validity
- Consumption ordering
- Expiry ledger entries
- Accumulation caps
- Expiry notifications
- Cycle transitions

## P4.6 — Leave Duration Engine

Calculate duration using:

- Work weeks
- Shifts/rosters
- Holidays
- Weekly offs
- Timezone
- Partial-day/hourly rules
- Cross-midnight shifts
- Sandwich rules
- Policy version
- Effective-dated employee assignment

Support minimum/maximum consecutive leave, mandatory continuous leave, notice periods, backdated requests and future booking limits.

Explain each charged leave unit.

## P4.7 — Leave Requests & Approval

Support:

- Full, half, quarter and hourly leave
- Date/time selection
- Reason
- Attachments
- Delegate/handover information
- Eligibility checks
- Balance reservation
- Projected balance
- Conflict warnings
- Conditional approval
- Multi-step approval
- Delegation
- Rejection
- Withdrawal
- Cancellation
- Partial cancellation
- Controlled modification
- Before/after history

Use centralized My Work approvals.

## P4.8 — Restrictions & Conflicts

Detect:

- Overlapping leave
- Attendance/On Duty conflicts
- Holiday/weekly-off issues
- Employment-date conflicts
- Roster conflicts
- Team availability risks
- Restricted/blackout dates
- Excessive concurrent absence
- Insufficient staffing
- Missing required documents
- Policy ineligibility

Support warning, block or escalation according to configurable rules.

Do not automatically deny protected/statutory leave merely due to staffing warnings.

## P4.9 — Comp-Off & Special Leave

Implement:

- Credits from approved OT, holiday/rest-day work or authorized adjustments
- Comp-off claims and approval
- Validity and expiry
- Credit-to-consumption traceability
- Optional/floating holiday selection pools
- Unpaid leave
- Long-duration/statutory leave
- Return-to-work foundation
- Leave donation foundation where permitted
- Encashment references for Payroll

Do not calculate encashment money in Phase 4.

## P4.10 — Employee, Manager & HR UX

**Employee:** My Leave, balance cards, ledger explanation, request, calendar, upcoming leave, history, comp-off, expiry and forecast.

**Manager:** Approvals, team calendar, who's away, staffing context and conflicts.

**HR:** Leave Operations Center, policies, cycles, accruals, carry-forward, expiry, imports, balance adjustments, conflicts, locks and reports.

Provide saved views and secure CSV/XLSX export.

## P4.11 — Imports & Bulk Operations

Support:

- Opening balance migration
- Historical leave imports
- Employee/leave-type mapping
- Preview/validation
- Duplicate detection
- Error rows
- Retry
- Bulk credit/debit/grant
- Background processing
- Approval and audit for sensitive adjustments

Never overwrite ledger history.

## P4.12 — Transfers, Separation & Rehire

Support policy transitions across countries, entities, locations and employee types.

Define:

- Old/new policy dates
- Balance preserve/transfer/convert/expire
- Leave-type conversion
- Separation accrual
- Unused balance
- Encashment eligibility reference
- Negative balance
- Future leave cancellation
- Rehire restoration rules

## P4.13 — Attendance & Payroll Integration

Approved leave updates Attendance through normalized segments and recalculation events.

Provide Payroll-ready:

- Paid leave
- Unpaid leave
- Statutory leave
- LOP
- Comp-off
- Encashment reference
- Cutoff/late adjustment metadata

Support leave locks and coordinated attendance cutoffs.

Do not calculate salaries.

## P4.14 — Differentiators

Implement:

- **Leave Explainer:** Balance, duration, eligibility, expiry and policy reasons
- **Policy Sandbox:** Safe policy-change simulation
- **Entitlement Simulator:** Proration and eligibility previews
- **Request Impact Preview:** Balance, duration and team impact
- **Leave Forecast:** Expected future balance
- **Policy Difference Viewer:** Rule-version comparison
- **Absence Command Center:** Actionable HR operations
- **Leave Confidence:** Confirmed/Pending/Review Required
- **Team Availability Service:** Leave + roster + holidays
- **Employee Time Story:** Unified attendance/leave context
- **Leave Data Quality Engine:** Policy, ledger, balance and integration problems

## P4.15 — Security & Integration

Protect sensitive medical/reason documents and limit manager visibility.

Integrate Employee 360, My Work, Notifications, Audit, Organization Memory and HR Action & Intelligence.

Prepare future calendar adapters and automation events.

## P4.16 — Verification

Test:

- Accrual and idempotency
- Proration and tenure
- Carry-forward/expiry
- Caps and negative balance
- Sandwich rules
- Holiday/weekly-off interaction
- Hourly/partial leave
- Night shifts
- Cross-cycle requests
- Approval/cancellation
- Balance reservation/concurrency
- Comp-off
- Transfers, separation and rehire
- Attendance recalculation
- Payroll outputs
- Locks
- Country policies
- Sensitive permissions
- Imports
- Responsive UX

**Completion gate:** Leave is ledger-based, explainable, historically auditable and integrated with Attendance and future Payroll.

**STOP AFTER PHASE 4.**

---

# PHASE 5 — GLOBAL PAYROLL, COMPENSATION & BENEFITS

## Objective

Build a secure, financially accurate, multi-country payroll engine that consumes finalized employee, attendance and leave data, calculates compensation and statutory amounts, generates payslips and prepares payment/accounting outputs.

## P5.1 — Payroll Architecture

Separate:

1. Global payroll engine
2. Versioned country payroll packs
3. Tenant/legal-entity configuration

Core entities:

Payroll Group, Calendar, Period, Run, Employee Result, Country Pack, Salary Structure, Component, Compensation, Revision, Input, Earning, Deduction, Contribution, Tax, Benefit, Adjustment, Arrear, Retro, Loan, Advance, Payslip, Payment, Accounting Export, Settlement and Lock.

Do not hard-code country-specific tax or contribution rules globally.

Country-pack architecture alone is not proof that a country is legally supported. Every launched country pack requires verified rules and country-specific tests.

## P5.2 — Payroll Groups & Calendars

Support:

- Country/legal entity
- Currency
- Employee category
- Weekly, biweekly, semi-monthly, monthly, four-weekly and custom frequencies
- Payroll periods
- Attendance/leave/variable-pay cutoffs
- Approval/payment dates
- Upcoming, input-open, processing, validation, approved, locked, paid and closed states
- Off-cycle payroll

## P5.3 — Salary Components & Structures

Support:

- Fixed/variable earnings
- Deductions
- Taxes
- Employee/employer contributions
- Benefits
- Reimbursements
- Informational components
- Formula rules
- Minimum/maximum
- Rounding
- Proration
- Tax classification
- Payslip visibility
- Accounting mapping
- Effective dates and versions

Create safe expression evaluation; never execute arbitrary tenant-provided code.

## P5.4 — Employee Compensation

Support:

- Base, gross, annual, daily and hourly pay
- Salary structures
- Currency and pay frequency
- Effective-dated compensation components
- Revision, increment, promotion and correction
- Future/backdated revisions
- Compensation history
- Bulk salary revision
- Revision approval
- Salary letter foundation
- Secure permissions

## P5.5 — Payroll Input Engine

Consume:

- Employee/employment data
- Attendance/payable days
- Paid/unpaid leave
- LOP
- Approved overtime
- Bonuses
- Commissions
- Incentives
- Shift allowances
- Loans/advances
- Benefits
- Reimbursements
- Manual/API/imported adjustments

Every input retains its source, period and approval state.

## P5.6 — Payroll Calculation

Implement deterministic, versioned calculations for:

- Payable days
- Joiner/leaver proration
- Mid-period salary revisions
- Earnings
- Deductions
- Employer/employee contributions
- Taxes
- Benefits
- Gross
- Net
- Retroactive pay
- Arrears
- Reversals
- Country-specific rounding
- Off-cycle payments

Use decimal-safe money and preserve calculation traces.

## P5.7 — Country Statutory Packs

Support versioned country-specific:

- Tax
- Social insurance
- Pension/provident contributions
- Local taxes
- Mandatory insurance
- Employee identifiers
- Tax declarations
- Proof/approval requirements
- Statutory validation
- Reports
- Effective-date changes

Keep country packs independently testable.

## P5.8 — Benefits, Loans & Advances

Support:

- Benefit plans/providers
- Eligibility
- Employee/employer contributions
- Enrollment foundation
- Effective dates
- Loans
- Interest configuration
- Installments
- Recovery schedules
- Outstanding balance
- Salary advances
- Payroll recovery

Do not turn this into a banking product.

## P5.9 — Payroll Run Workflow

Implement:

Readiness → Collect Inputs → Validate → Calculate → Review Exceptions → Compare → Approve → Lock → Generate Outputs → Payment → Close.

Support:

- Background calculation
- Partial employee failures
- Retry
- Recalculation
- Approval
- Maker-checker
- Controlled reopen
- Audit
- Period locks

## P5.10 — Validation & Reconciliation

Detect:

- Missing compensation
- Missing payroll group
- Missing bank/statutory identifiers
- Duplicate inputs
- Formula failures
- Unfinalized attendance/leave
- Negative/zero net pay
- Unexpected variance
- Invalid tax calculation
- Currency mismatch
- Overlapping compensation
- Payment mismatch

Reconcile employee totals, payroll totals, payment instructions and accounting outputs.

## P5.11 — Payslips & Payments

Implement:

- Secure branded payslips
- Country-specific layouts
- Current/YTD values
- Earnings/deductions
- Employer contributions where appropriate
- Masked bank information
- Versioned corrections
- Employee download/history
- Bank/payment instruction adapters
- Bank-file generation
- Payment batches/status
- Failed/partial payment handling

Never mark payments successful without confirmed status.

## P5.12 — Accounting & Cost Allocation

Support:

- Salary component to GL mapping
- Legal entity
- Department
- Cost center
- Location
- Project allocation foundation
- Journal generation
- Accounting export/API foundation
- Reconciliation

## P5.13 — Full & Final Settlement

Calculate configured settlement components:

- Final salary
- Leave encashment
- Notice pay
- Bonus
- Recovery
- Loan/advance
- Benefits
- Other country-specific adjustments

Provide settlement approval, explanation and audit.

## P5.14 — Employee, HR & Finance UX

**Employee:** Payslips, compensation summary, benefits and loans.

**HR/Payroll:** Payroll Command Center, readiness, inputs, exceptions, variance, processing, approvals, locks and reports.

**Finance:** Authorized payment preparation, reconciliation, bank files and accounting exports.

Use role-appropriate views; do not expose sensitive pay data by default.

## P5.15 — Differentiators

Implement:

- **Payroll Explainer:** Net → component → formula → input → source
- **Payroll Difference Viewer:** Current vs previous pay and drivers
- **Payroll Simulator:** Safe scenario calculations
- **Salary Revision Preview:** Effective/retro impact
- **Payroll Readiness Map:** Group/entity readiness
- **Payroll Confidence:** Ready/Review Required/Blocked/Processing
- **Payroll Change Story:** Historical events and approvals
- **Payroll Control Totals:** Reconciliation and completeness
- **Compensation Insight Foundation:** Bands/equity metrics for future analytics

No opaque compensation or employee scoring.

## P5.16 — Imports, Security & Integration

Support secure CSV/XLSX inputs, compensation migration, large exports and background jobs.

Require strict payroll RBAC, encryption, audit, secure downloads, export controls, maker-checker and data scopes.

Integrate Employee 360, My Work, Notifications, Organization Memory, Data Quality and Action & Intelligence.

## P5.17 — Verification

Test:

- Country-pack/version resolution
- Formula safety
- Decimal precision
- Multiple pay frequencies
- Joiner/leaver proration
- Salary revision
- Retro/arrears
- Attendance/LOP/OT
- Benefits and loans
- Tax/contribution calculations
- Zero/negative pay
- Payroll validation
- Lock/reopen
- Payslip correction
- Payment reconciliation
- Settlement
- Country transfer
- Off-cycle pay
- Tenant isolation
- Compensation permissions
- Bulk processing

**Completion gate:** Payroll results are financially reproducible, explainable, reconciled, securely approved and ready for payment/accounting integration.

**STOP AFTER PHASE 5.**

---

# PHASE 6 — RECRUITMENT, ATS & HIRING

## Objective

Build an enterprise Applicant Tracking System covering requisition to candidate-to-employee conversion, with structured hiring, candidate experience, privacy, analytics and HRMS integration.

## P6.1 — Recruitment Architecture

Keep separate:

- Job definition
- Position/headcount
- Requisition
- Job posting
- Candidate
- Application
- Pipeline/stage
- Interview
- Assessment
- Offer
- Pre-joiner
- Employee conversion

A candidate can apply to multiple jobs. Do not duplicate their identity for every application.

## P6.2 — Workforce Requisitions

Support:

- New headcount
- Replacement/backfill
- Contract/intern roles
- Requested openings
- Position
- Department
- Location/legal entity
- Hiring manager
- Recruiter
- Budget reference
- Priority
- Target date
- Approval
- Draft/open/on-hold/filled/closed states
- Approved, filled, open and frozen headcount tracking

Reuse Phase 2 positions and Phase 1 approval architecture.

## P6.3 — Job Library & Postings

Implement:

- Job definitions
- Job families/functions
- Designation/level/grade
- Responsibilities
- Required/preferred skills
- Experience/education
- Job-description versioning
- Internal/external postings
- Work mode
- Employment type
- Posting lifecycle

## P6.4 — Career Site

Build tenant-branded careers pages with:

- Logo/theme/company content
- Job search
- Department/location/work-mode filters
- Job details
- Application forms
- Mobile UX
- Privacy notice
- Consent
- Accessibility
- Spam/rate-limit protection

## P6.5 — Candidate Profiles

Support:

- Identity and contact
- Resume and versions
- Experience
- Education
- Skills
- Certifications
- Portfolio
- Work authorization
- Location preferences
- Notice period
- Compensation expectations where permitted
- Tags
- Notes
- Documents
- Source
- Consent

Implement controlled duplicate detection and merge review.

## P6.6 — Applications

Support:

- Multiple applications per candidate
- Application date/source
- Screening questions
- Configurable form fields
- Attachments
- Knockout criteria based on lawful job-related requirements
- Application history
- Recruiter assignment
- Status
- Withdrawal
- Rejection reason

## P6.7 — Pipelines & Stage Management

Implement:

- Configurable pipelines
- Applied/screening/assessment/interview/offer/hired stages
- Stage requirements
- Transition validation
- Kanban board
- Bulk movement
- Stage history
- Time in stage
- SLA reminders
- Stuck candidate detection
- Application status separate from stage

## P6.8 — Sourcing, Referrals & Agencies

Support:

- Career-site source
- Referral
- Agency
- Job board
- Direct sourcing
- Social
- Campus
- Imports
- Original and latest attribution
- Employee referral submission/status
- Referral reward reference
- Agency profiles/contacts
- Agency submissions
- Restricted agency portal foundation
- Talent pools and rediscovery

## P6.9 — Screening & Assessments

Support:

- Recruiter screening
- Structured notes
- Eligibility
- Availability
- Assessment types
- Questionnaires
- Technical tests
- External assessment adapters
- Assessment status/results
- Secure candidate documents

## P6.10 — Interviews

Implement:

- Phone/video/in-person/panel interviews
- Interview scheduling
- Timezone-aware date/time
- Interviewer assignment
- Meeting/location information
- Calendar adapter foundation
- Reschedule/cancel
- Interview kits
- Question banks
- Scorecards
- Configurable criteria/rating scales
- Independent feedback
- Feedback deadlines
- Confidentiality

## P6.11 — Structured Hiring

Support consistent job-related evaluation.

Provide:

- Candidate comparison
- Strengths/concerns
- Interview feedback
- Decision history
- Job-related evidence
- Human-controlled hiring decisions

Do not use opaque automated rejection or protected-characteristic inference.

## P6.12 — Candidate Communication

Support:

- Application confirmation
- Interview invitation
- Reminder
- Reschedule
- Rejection
- Offer
- Custom messages
- Localized templates
- Safe variables
- Communication history
- Candidate portal foundation

## P6.13 — Offer Management

Implement:

- Offer draft
- Role/position/legal entity
- Location
- Designation/grade
- Compensation/currency
- Joining date
- Conditions
- Approval
- Versioning
- Negotiation history
- Letter templates
- E-signature adapter foundation
- Sent/viewed/accepted/declined/expired/withdrawn states

Reuse Phase 5 compensation structures where applicable.

## P6.14 — Background Checks & Pre-Joining

Support:

- Consent
- Provider adapter foundation
- Verification status
- Review-required state
- Secure document collection
- Joining details
- Pre-joiner status
- Required-document tracking
- Offer-to-joining readiness

Full onboarding workflow belongs to Phase 7.

## P6.15 — Candidate-to-Employee Conversion

Implement controlled conversion into Phase 2 People records.

Reuse data to create/link:

- Person
- Employment
- Employee profile
- Position/organization assignment
- Manager
- Employee type
- Compensation
- Joining details
- Documents
- User invitation where appropriate

Provide conversion preview, validation and duplicate/former/current employee detection.

Preserve recruitment history with proper privacy boundaries.

## P6.16 — Internal Hiring

Support current employees applying to internal positions without creating duplicate Person records.

Prepare future internal mobility and talent marketplace integration.

## P6.17 — Recruiter, Manager & Candidate UX

**Recruiter:** Open jobs, pipeline, candidates, interviews, feedback, offers, talent pools, saved views and bulk actions.

**Hiring Manager:** My roles, candidate review, interviews, feedback, approvals and progress.

**Candidate:** Search, apply, upload, interview confirmation, application status and offer response where enabled.

**Agency:** Only permitted jobs/candidates.

## P6.18 — Recruitment Intelligence

Implement:

- **Talent Acquisition Command Center:** Open roles, bottlenecks, offers and upcoming hires
- **Hiring Explainer:** Why candidate/application is waiting
- **Pipeline Health:** Stage delays and overdue actions
- **Hiring Readiness:** Offer, documents, position and conversion checks
- **Candidate Journey:** Chronological application history
- **Hiring Impact Preview:** Resulting employee/position/payroll configuration
- **Talent Memory:** Consent-aware talent pool/history
- **Internal Talent Foundation:** Future mobility
- **Recruitment Data Quality:** Missing recruiters, overdue feedback, duplicate candidates, consent/retention issues

## P6.19 — Recruitment Analytics

Support:

- Open roles
- Applications
- Pipeline conversion
- Time to screen
- Time in stage
- Time to offer
- Time to hire
- Offer acceptance
- Source performance
- Requisition aging
- Recruiter/hiring-team operational workload

Define metrics consistently.

## P6.20 — Privacy & Security

Implement:

- Candidate consent
- Privacy notice versions
- Purpose and retention
- Deletion/anonymization workflows
- Country-specific privacy requirements
- Confidential jobs
- Hiring-team data scopes
- Sensitive notes
- Secure resumes/documents
- Export restrictions
- Audit
- Public endpoint abuse protection

Do not retain candidate data indefinitely without a valid basis.

## P6.21 — AI Assistance Foundation

Prepare controlled adapters for:

- JD drafting
- Resume summaries
- Candidate search
- Interview question suggestions
- Communication drafts
- Recruiter summaries

AI must be advisory and reviewable.

Do not autonomously reject candidates, infer protected characteristics or present unsupported matching scores as fact.

## P6.22 — Integration

Integrate:

- Phase 2 People/Positions
- Phase 5 Compensation
- My Work
- Notifications
- Audit
- Organization Memory
- HR Data Quality
- Action & Intelligence
- Future Phase 7 Onboarding

Create domain events for requisitions, applications, interviews, feedback, offers, acceptance and employee conversion.

## P6.23 — Verification

Test:

- Tenant isolation
- Requisition approvals
- Confidential jobs
- Candidate/application separation
- Duplicate detection
- Multiple applications
- Pipeline history
- Interview timezone
- Feedback permissions
- Offer versioning
- Compensation integration
- Consent/retention
- Agency/referral scope
- Internal candidates
- Former employees
- Candidate conversion
- Offer cancellation/expiry
- Joining-date changes
- Career-site security
- Responsive UX

**Completion gate:** The complete hiring journey works from requisition to employee conversion without duplicate employee entry or disconnected systems.

**STOP AFTER PHASE 6.**

---

# CROSS-PHASE ACCEPTANCE MATRIX

| Area | Phase 2 | Phase 3 | Phase 4 | Phase 5 | Phase 6 |
|---|---|---|---|---|---|
| Authoritative People Data | Build | Consume | Consume | Consume | Create/link on hire |
| Time & Attendance | Future hook | Build | Integrate | Consume | Joining readiness |
| Leave & Absence | Future hook | Contract | Build | Consume | Joining readiness |
| Payroll & Compensation | Data foundation | Output | Output | Build | Offer integration |
| Recruitment | Employee foundation | — | — | Compensation contract | Build |
| Employee 360 | Build | Extend | Extend | Extend | Controlled hiring context |
| My Work / Approvals | Reuse | Reuse | Reuse | Reuse | Reuse |
| Audit / History | Mandatory | Mandatory | Mandatory | Mandatory | Mandatory |
| Country Configuration | Mandatory | Mandatory | Mandatory | Mandatory | Mandatory |
| Action & Intelligence | People | Time | Leave | Payroll | Hiring |
| Responsive UX | Mandatory | Mandatory | Mandatory | Mandatory | Mandatory |
| Tenant Isolation | Mandatory | Mandatory | Mandatory | Mandatory | Mandatory |

# REQUIRED PHASE COMPLETION REPORT

At the end of each phase, report:

1. Implemented modules and capabilities
2. Database tables and migrations
3. Backend services and APIs
4. Frontend pages and components
5. Permissions and security
6. Cross-module integrations
7. Tests executed and results
8. Known defects and limitations
9. Deferred features and reasons
10. Acceptance criteria passed/failed
11. Recommended next task

Do not claim a phase complete if mandatory acceptance criteria are unimplemented or unverified.

# FINAL EXECUTION INSTRUCTION

This document defines the complete functional scope for Phases 2–6.

It is a reference specification, NOT an instruction to implement all five phases immediately.

When given a task ID:

- Read only its relevant phase/section and dependencies.
- Implement that task completely.
- Preserve all related business/security rules.
- Verify with targeted tests.
- Record progress.
- Stop.

Do not skip requirements.
Do not implement unrelated modules.
Do not start another phase automatically.