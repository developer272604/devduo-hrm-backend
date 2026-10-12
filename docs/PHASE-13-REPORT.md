# Phase 13 — HR analytics and workforce intelligence

Status: lean reporting core implemented; **partial against the full master specification**. The recent three source metric additions need focused regression coverage.

## Delivered

- Version-1 metric catalog with owner, formula, unit, lineage, refresh cadence, supported dimensions and permission requirements.
- Thirteen metrics: as-of headcount, hires, leavers, period attrition, average tenure, approved overtime, absence rate, learning completions, consumed leave units, recruitment pipeline, finalized performance reviews, helpdesk SLA attainment, and published payroll totals with prior-period variance by currency.
- Headcount and tenure use effective-dated status history rather than today's employee status. Their department, location and legal-entity breakdowns use assignments effective at period end. Flows and other metrics currently support totals only.
- Each metric requires both analytics permission and its source-module permission. Current employee reach is applied before aggregation. Disabled source modules cannot be queried.
- Fixed minimum cohort of five, including small-event suppression for hires/leavers/attrition/absence. Entire breakdowns are withheld when any cell is small, preventing subtraction of displayed cells from a total.
- Attendance totals include confirmed approved/locked source days only. Learning completion periods convert workspace-local day boundaries to the application's database timestamp convention.
- Leave metrics sum posted consumption ledger entries by effective date. Recruitment outputs are stage aggregates without candidate identity. Performance uses finalized review facts. Helpdesk SLA output is aggregate only. Payroll totals require payroll reporting permission and employee scope, use a five employee threshold per currency and never combine currencies.
- On-demand refresh records with immutable result snapshots, source explanation, scope explanation, refresh timestamp and quality status.
- Missing historical status or overlapping effective assignments blocks affected reports instead of presenting a misleading result.
- Structured report builder, private saved reports, fresh permission checks on every rerun, audited aggregate CSV export with formula-cell escaping, and scenario sandbox.
- Scenario calculations expose baseline + assumed hires - assumed leavers. They do not change source records or assign employee risk scores.
- Module settings, sidebar entry and permission-aware metric choices.

## Boundaries

Employment counts are not unique-person counts or FTE. Current authorization scope governs historical access. Organization labels use current names, while assignment membership is historical. Source corrections affect future reruns; persisted refresh results record what was computed at that time, not a bitemporal source warehouse.

Reports are bounded to 10,000 scoped employments, 100 groups and 367 inclusive days. More expansive queries are rejected. Saved reports retain criteria, never cached authorization. Historical refresh snapshots are not exposed through an API that could bypass today's source permissions.

Headcount small cohorts and empty fact cohorts are suppressed, not displayed as zero. Ratios expose numerator/denominator only when the result is permitted. No currency totals are mixed or converted.

## Remaining full-spec work

- FTE, lawful diversity metrics, leave utilization rates, recruiter-specific funnel filters and more detailed ticket SLA distributions.
- Historically versioned organization names/hierarchy, legal-entity transfer reconciliation and bitemporal source correction handling.
- Incremental ETL/materialization, asynchronous backfill jobs, retry/failure monitoring and source-domain reconciliation at warehouse scale.
- Role-specific configurable dashboards, scheduled report delivery, authorized record drilldowns, async large exports and richer filtering/sorting.
- Broader workforce/cost scenarios, comparison stories and cross-module timeline.

The full Phase 13 gate is not met while those items remain.

## Verification and rollout

Analytics feature suite: 8 tests, including historical state, assignment dates, scoped suppression, permission revocation, source reconciliation, timezone boundaries, scenarios and exports. Combined backend run: **46 tests / 433 assertions passed**. Frontend automation and operations suites: **9 tests passed**.

Production frontend build passed with the existing large-chunk advisory.

Migrations were exercised only in isolated SQLite tests. Shared development migrations remain pending. Source database retention, stable application timezone configuration and scheduler deployment must be retained in operational setup.

Continuation update (2026-10-11): leave consumption, recruitment pipeline, finalized performance review, helpdesk SLA and payroll totals/variance metrics are in the catalog and aggregation service. Timestamp metrics use tenant-local start-inclusive/end-exclusive boundaries. PHP syntax checks passed and the frontend production build passed. The existing analytics-suite results above predate these five additions; they are not regression-verified yet. Shared MariaDB migrations remain unapplied.

