# HRMS capability audit

Compared the user's checklist with `tasks/STATUS.md`, the phase reports, routes, and current frontend modules on 2026-10-11. “Present” means a usable core exists; it does not mean the master phase gate is complete or production-certified.

## Foundation

| Capability | State | Evidence / boundary |
|---|---|---|
| Secure authentication and session management | Present, with enterprise gaps | Login, password recovery/change, session auth and rate limiting exist. MFA/SSO/device/session-management controls remain deferred (Phase 15). |
| Multi-tenant HRM foundation | Present | Tenant-scoped APIs and workspace provisioning are used across domains; each new feature still needs isolation coverage. |
| Organization structure setup | Present | Organization hierarchy and setup screens exist; historical/bitemporal reporting and production DB rollout have limits. |
| Roles and permissions | Present | RBAC, data scopes and permission-aware navigation/API middleware exist. Field/action coverage varies by domain. |
| Company onboarding wizard | Present | Setup/onboarding routes and workspace configuration exist. Production provisioning/identity adapters remain pending. |
| Dashboard and app shell | Present | Responsive role-aware shell/dashboard exists; richer role-specific dashboards remain incomplete. |
| My Work task foundation | Present | Shared task contract is used by workflow and domain approvals. |
| Notifications, audit trail, settings | Present | Shared systems exist; delivery channels, retention and tamper-evidence remain limited. |
| Purple design system and responsive frontend | Present | Shared UI primitives and responsive patterns exist; phase reports still identify pages that need shape/browser validation. |

## Full HRMS checklist

| Area | State | What exists and what remains |
|---|---|---|
| Employee management | Present core; partial | Person, Employment and User are distinct; directory, Employee 360, org chart, effective-dated assignments, change requests, documents, imports/exports, editable/revalidated import rows, bulk preview, group membership UI, custom-field choice UI, country-aware sensitive identity/bank editors, and custom-field defaults during hire/rehire exist. Generic local-disk serving is disabled; invitation acceptance is hashed, expiring, single-use and throttled. Remaining: broader security/rollout and production storage review. |
| Core HR transactions | Present core; partial | Leave ledger/requests, attendance engine, shifts/rosters, cross-date shift swaps, Time Office, overtime/on-duty and consecutive-night roster compliance exist. Remaining: MySQL/MariaDB and production-operation verification, other policy edges, page/API response validation and integration reconciliation. |
| Payroll and compensation | Present core; partial | Payroll calculations, components, compensation revisions, payslips, approvals, reports, generic CSV payment batches, compensation/input CSV/XLSX preview and commit with configurable header mapping, and benefits enrollment/termination with basic plan administration and maker-checker exist. Fixed benefit contributions now feed payroll results with effective-date proration. Formula/provider support, statutory validation, complete retro/import reconciliation, PDF payslips and bank-specific outputs remain. |
| Recruitment and hiring | Present core; partial | Requisitions, candidate/application pipeline, interviews, scorecards, offers and handover exist. Candidate portal/career site, external channels, CV parsing, bulk import, offer documents and other items in Phase 6 remain. |
| Performance management | Present core; partial | Goals, cycles, reviews, responses and dashboard APIs exist. Calibration, finalization/appeals, 1:1s, development plans and broad admin/reporting UI remain. |
| Learning and development | Present core; partial | Catalog, self-enrollment, paths, sessions and self-skills exist. Manager assignment, completion/assessment/certificates, compliance and team reporting remain. |
| Benefits and compliance | Partial / mostly missing | Payroll country-pack framework and illustrative attendance packs exist, along with core benefit enrollment and basic plan administration. Legally validated country-specific rules do not. |
| Advanced self-service | Present across modules; partial | Employee leave, attendance, payroll, profile, learning, expenses/travel and lifecycle flows exist. Some approval, document and manager workflows remain incomplete. |
| Reporting and analytics | Present core; partial | Role/data-scope-aware analytics has thirteen metrics, exports, saved reports and scenarios. Scheduled delivery, richer dashboards, historical org names, async ETL and additional metrics remain. |
| HR documents and compliance | Partial | Employee document records, expiry actions, private attachments and some versioning/retention exist. Full retention policies, legal holds, evidence exports and end-to-end document approval are not complete. |
| Integrations | Foundation only | Connection metadata and explicitly non-live diagnostics exist. No live attendance/payroll/identity provider adapter or production campaign delivery is claimed. |
| Country/market readiness | Architecture only | Country configuration/frameworks and illustrative packs exist. Country packs, expert-validated legal rules and production certification remain. |

## Remaining work, in phase order

1. **Phase 2 People:** complete broader security/rollout and production storage review; identity/bank edit paths, hire/rehire custom defaults, private-note handling and invitation acceptance have been verified.
2. **Phase 3 Attendance:** verify MariaDB behavior and remaining UI/API response contracts; close remaining rule edges and real provider integrations only with provider specifications. Cross-date swaps and the current focused regression suites are complete.
3. **Phase 4 Leave:** core phase implementation is complete; MariaDB concurrency, browser review and country-specific legal validation remain release gates.
4. **Phase 5 Payroll:** formula/provider benefit support, provider orchestration and source/retro/adjustment reconciliation, country-pack validation, PDF and real bank formats.
5. **Phase 6 Recruitment:** career/candidate portal, live channels, bulk import, offer documents and configurable pipeline SLAs remain. Scoped rehire, interview rescheduling and fixed local expiry/stage/feedback reminders are implemented and tested; see `PHASE-6-REPORT.md` for deployment/provider/legal gates.
6. **Phase 7 Lifecycle:** probation/resignation/contract/return workflows, provisioning, task dependencies, bulk operations, reports and real clearance providers.
7. **Phase 8 Performance:** expose remaining backend workflows in UI and add finalization, calibration, 1:1 and development-plan flows.
8. **Phase 9 Learning:** manager/compliance assignment, completion/assessment/certification, taxonomy administration and reporting.
9. **Phase 10 Experience:** configured email, conditional routing, country privacy controls, Employee Voice themes and Experience Timeline.
10. **Phase 11 Operations:** approved-trip expense linkage and budget reconciliation.
11. **Phase 12 Workflow:** current regression suites pass; more trigger sources, shared approval routing, multi-hop escalation, action adapters and worker operations remain.
12. **Phase 13 Analytics:** current regression suites pass; additional metrics, historical organization data, async warehouse/report delivery and richer dashboards remain.
13. **Phase 14 Integrations:** live provider adapters, OAuth/SSO/SCIM, signed webhooks, sync/retry/dead-letter operations and integration validation.
14. **Phase 15 Security:** MFA/SSO policy, session lifecycle, privacy/legal-hold, key management, tamper-evident evidence, incidents and operational controls.
15. **Phase 16 Intelligence:** persisted evidence/provenance, deduplication/resolution, scenarios, simulators, feedback and governed evaluation.

The application is not a fully complete or production-certified HRMS while these items remain. Track each vertical slice in the corresponding phase progress file and update this audit when verified.
