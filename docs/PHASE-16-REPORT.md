# Phase 16 - HRMS intelligence and unique value layer

Status: **partial against the master specification; the core deterministic layer is implemented**. No external AI/LLM is called anywhere.
API reference: `docs/api/intelligence.md`.

## Delivered
- Intelligence Center: stored, permission- and scope-filtered findings from all registered InsightProviders; severity, evidence/action links, owner, due date, lifecycle (open/acknowledged/snoozed/resolved/dismissed with reasons), stable-fingerprint dedupe, reopen/auto-resolve and full history.
- Explain-this: inputs, rule, threshold, as-of, data scope applied, suppressed information (stored with every finding).
- Action layer: My Work task via `TaskService::createOnce`, module action links in evidence, bulk acknowledge, linked tasks completed when a finding is resolved or dismissed.
- Scenarios: read-only orchestration of leave policy sandbox, leave forecast, payroll simulator and a headcount plan; always rolled back; runs stored.
- Employee Time Story endpoint and Employee 360 "Intelligence" panel limited by the viewer's data scope.
- Risk indicators (cohort level, tenant opt-in, small-cohort suppression, human-review wording, no sensitive attributes).
- `intelligence:scan` command (idempotent, per tenant), scheduled daily; audit entries for scans, status changes, scenarios, settings, time story and indicator views.
- Permissions `intelligence.manage`, `intelligence.scenarios.run`, `intelligence.risk_signals.view`, `intelligence.settings.manage` (WorkspaceProvisioner + grant migration).
- Frontend: Intelligence Center page (findings, explain drawer, status dialogs with reasons, bulk acknowledge, scenarios, indicators).

## Files
Backend `app/Domain/Intelligence/**`, `Http/Controllers/Intelligence/CenterController.php`, `Console/Commands/IntelligenceScan.php`, `routes/intelligence.php`, migrations `2026_11_03_000001/2`, one-line edits in `WorkspaceProvisioner`, `ModulesServiceProvider` (Intelligence Boot dir) and `routes/console.php`. Tests `tests/Feature/Intelligence/IntelligenceCenterTest.php`. Frontend `pages/intelligence/IntelligenceCenterPage.jsx` (+ test) and `routes/enterpriseRoutes.jsx`.

## Tests actually run
See `tasks/phase-16/progress.md`.

## Deferred / not done
- Performance-cycle and recruitment scenarios (no existing simulator to orchestrate); the headcount plan is simple arithmetic.
- Finding owner reassignment (needs a scope re-check), feedback analytics, role command centers, smart search, governed optional AI assistance.
- Per-employee risk drill-down (intentionally not provided).
- UI for Time Story, stored scenario run detail and leave/payroll scenario parameter forms (API supports them).
- Leave sandbox and payroll scenario kinds are covered by the rollback guarantee test through a rogue source, not by end-to-end runs against seeded leave/payroll data.
- Full backend suite was not re-run for this slice; no browser testing.
