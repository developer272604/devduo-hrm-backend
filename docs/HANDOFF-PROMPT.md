# Handoff prompt (paste this whole file into the next coding agent)

You are continuing a multi-phase build of an enterprise HRMS ("Devduo HRM") in this repo. Another agent (Claude Code) built Phases 1-4 and started Phase 5, then stopped. Your job: resume exactly where it stopped, finish phases in order, and keep `tasks/STATUS.md` accurate so any later agent can resume the same way.

## 0. First 10 minutes (do this before writing code)
1. Read `tasks/STATUS.md` (current status), `docs/ARCHITECTURE.md`, `docs/HRMS-MASTER-16-PHASES.md` (scope of all 16 phases + shared rules), then the CURRENT phase's `tasks/phase-NN/progress.md` and `docs/api/*.md` for already-built areas.
2. For the current phase, inspect the code and classify each requirement as implemented / partial / missing / blocked. Do NOT rebuild what exists. Trust code and tests, not prose.
3. Run the real test suites to learn the true state (commands below). Report actual results; never claim a pass you did not run.

## 1. Product and stack
- Multi-tenant, multi-country SaaS HRMS. Backend: Laravel 12 REST (`backend/`), PHP 8.2. Frontend: React 19 + Vite + Tailwind 4 (`frontend/`), JavaScript. DB: MySQL/MariaDB (tests use in-memory SQLite).
- Frontend rules: Tailwind classes only (no custom CSS, no inline `style`), brand color `#7c46f1` via `brand-*` tokens, `dark:` variants, responsive (tables on desktop, cards on mobile), accessible, loading/empty/error/403 states. Reuse `src/components/ResponsiveList.jsx`, `Access.jsx`, `src/components/timeoffice/*` (CrudPanel, dialogs, RuleEditor), `src/lib/api.js` (ApiError has `.status/.code/.data/.errors`; supports `headers`, FormData, `download()`).
- Backend architecture: modular monolith. Each domain under `backend/app/Domain/<Name>/` (Models, Services, Contracts, Boot). `ModulesServiceProvider` auto-loads `app/Domain/<Name>/Boot/*Boot.php` (static `register/boot`). Routes under `backend/routes/<domain>.php` + `routes/<domain>/*.php`, API is under `/api/v1/...`, session + CSRF (`web` middleware). Look at Attendance, Leave, Payroll for the pattern.
- Identity model: Person (people) vs Employment (employees, UUIDs) vs User (auth only). Never treat Employee as User.

## 2. Non-negotiable rules
- Tenant isolation: `tenant_id` on every table, `TenantRecord` global scope; out-of-scope/cross-tenant records return 404. In tests, tenant context is cleared after each HTTP call: call `$this->context($tenantId)` before direct model queries.
- Authorization on every endpoint: permission key (`WorkspaceProvisioner::PERMISSIONS`) + `DataScope` + field-level `FieldAccess`/`FieldRegistry`. New permissions need a grant migration for existing tenants.
- Money/units: decimal-safe only (`App\Domain\Payroll\Support\Money/Decimal`, `Leave\Support\Units`); never floats. Time: explicit timezone, DST-safe.
- Immutability: ledgers, punches, payroll results are append-only; correct with reversing entries/new versions. Policies/rules are effective-dated and versioned; no historical rewrites.
- Reuse shared infrastructure: My Work (`Work/TaskService`), Notifications, Audit, DomainEvents (`DomainEvents::record`), approvals (`Attendance\Requests\ApproverResolver`), imports/exports (`app/Support/Spreadsheet`), registries (`QualityRule`, `InsightProvider`, `Employee360`, bulk actions). Never build a second approval/task engine.
- No country-specific law hard-coded. Statutory content = configurable, effective-dated pack data flagged "requires expert validation". Never claim legal compliance.
- Sensitive data (compensation, bank, identity, medical, applicant): encrypt/mask, log access, export only with permission.
- Background work: queued, idempotent, retry-safe.
- Never edit already-applied migrations; add new migration files (`2026_MM_DD_<seq>_...`). Never point tests at the dev DB.
- No new composer/npm dependencies without need (payslips = HTML print view, no PDF lib).

## 3. How to run things (Windows, from repo root)
- Start dev servers: `powershell ./start-dev.ps1` (DB 127.0.0.1:3307, API :8000, frontend http://127.0.0.1:5173). Dev login: workspace `devduo`, `admin@devduo.test`, `DevduoDemo2026!`.
- Dev DB migrate: `cd backend && php artisan migrate --force`. Demo seed: `php artisan db:seed --class=DemoSeeder` and `PeopleDemoSeeder`.
- Backend tests (slow, ~5-13 min full): `cd backend && php vendor/phpunit/phpunit/phpunit --colors=never` ; targeted: `... phpunit tests/Feature/Payroll`. (`--no-ansi` is unsupported.)
- Frontend: `cd frontend && npm test` and `npm run build`.
- Route list: `cd backend && CACHE_STORE=array DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan route:list --path=api/v1 --json`.
- Pitfalls seen: OneDrive may mark created dirs read-only (`attrib -R`); heredoc quoting in Git Bash breaks long scripts (write files instead); PHP 8.2 forbids positional args after spread; recursive CTEs must precede UNION; SQLite returns floats/ints for decimals (normalize).

## 4. Workflow per phase (LEAN MODE — user wants speed)
Budget about 1M tokens per phase. Do the core of each phase, not the whole spec; list deferred items in the report.
1. Inspect + classify (section 0). Write/refresh `tasks/phase-NN/progress.md` (task list P<NN>-T01.., scope, files, acceptance tests, status).
2. Foundation first: ONE migration per phase for all tables (tenant_id, FKs, indexes, uuid public ids), models, permissions + grant migration, settings, contracts/interfaces, route skeletons, Boot class, `docs/api/<phase>-foundation.md`, tests. Run targeted tests.
3. Then build backend workstreams (if you can run sub-agents, run 2-4 in parallel with EXCLUSIVE file ownership; otherwise sequentially): domain services + controllers + routes + `docs/api/<area>.md` + feature tests (happy path, authorization, tenant isolation, one edge case each).
4. Integrate with earlier phases through contracts (Employee 360, Action Center insights, data-quality rules, My Work, Notifications, Audit). Mark deeper features "deferred".
5. Frontend: only the main screens against the REAL routes/docs (check `route:list`), one route group file `src/routes/<phase>Routes.jsx` registered with one line in `main.jsx` and `Layout.jsx`; vitest tests with mocked `api` for key flows.
6. Verify: targeted tests, then full backend suite once at the end of the phase, `npm test`, `npm run build`. Fix failures you caused; report unrelated ones.
7. Write `docs/PHASE-NN-REPORT.md` (done / partial / blocked, files, migrations, endpoints, tests ACTUALLY run with results, open items, deferred list), update `tasks/STATUS.md`, then start the next phase. Do not skip ahead; do not leave a phase half-registered.

## 5. Status protocol (so the next agent can resume)
- After every meaningful step update `tasks/STATUS.md` (phase table row, "Last verified results", "Next action").
- If you hit a rate/usage limit mid-work, the last thing you do is update STATUS.md "Next action" with the exact file/task you were on.
- Final message of each phase uses this format:
```
Task: P<NN> — <name>
Status: done | partial | blocked
Changed: <paths>
Implemented: <behaviors/endpoints/UI>
Tests: <commands + actual pass/fail>
Security/tenant checks: <results>
Issues: <remaining>
Next: <task/phase ID>
```

## 6. Current state to resume from (also in tasks/STATUS.md — re-verify by running tests)
- Done: Phases 1-4 (reports in `docs/`). Last green: backend 535 tests, frontend 127 tests + build.
- Phase 5 (Payroll) IN PROGRESS: foundation done (41 tables, Money/Decimal, immutable ResultStore, 44 `payroll.*` permissions, contracts PayrollInputProvider/FormulaEvaluator/StatutoryPack/PaymentFormat/AccountingMapper/CompensationResolver/PackResolver/EmployeeCalculator, adapters for Attendance+Leave payroll contracts). Four backend workstreams (A config/compensation/country-pack framework; B inputs/run engine/calculator; C payslips/self-service/loans/declarations/payments/accounting/F&F; D readiness/command center/explainer/simulator/reports/data quality/security) were restarted in lean mode and may be partially complete. Migrations `2026_10_15_A/B/C00001_*` already applied to dev DB. Payroll frontend: NOT started. See `tasks/phase-05/progress.md` for ownership and task IDs T06-T31.
- Phases 6-16: not started (Recruitment has only a tiny foundation). Order and scope: `docs/HRMS-MASTER-16-PHASES.md`. Detailed specs exist only for phases 1-3, 5, 6 (`docs/PHASE-*-SPECIFICATION.txt`, `docs/hrms-phases-02-to-06.md`); for 7-16 use the master file.

## 7. Known open items worth fixing when convenient
Frontend response shapes for several Phase 3/4 screens were coded from docs (verify against live responses); no browser testing done; migrations verified on MariaDB only via manual dev migrate (suite runs on SQLite); security review pending (private disk exposure, audit entries naming private notes, public invitation-accept route); employee picker missing in HR leave screens.

Start now: read `tasks/STATUS.md`, run the Phase 5 payroll tests, finish Phase 5 core, then continue to Phase 6 and onward in order.
