# Intelligence Center API (Phase 16)

Base: `/api/v1/intelligence` (module `intelligence`). Session + CSRF. **No external AI/LLM calls; every output is deterministic and explainable.**

## Permissions
| Key | Grants | Default roles |
|---|---|---|
| `intelligence.view` | Read own stored findings, explanations, settings, time story | Admin, HR, Manager, Team member |
| `intelligence.manage` | Scan, acknowledge, snooze, resolve, dismiss, due date, create My Work task, bulk acknowledge | Admin, HR, Manager, Team member |
| `intelligence.scenarios.run` | Run/read what-if scenarios | Admin, HR, Manager |
| `intelligence.risk_signals.view` | Cohort-level indicators for human review | Admin, HR, Manager |
| `intelligence.settings.manage` | Enable risk signals, set the small-cohort minimum | Admin, HR |

Grant migration: `2026_11_03_000002_grant_intelligence_center_permissions`. Tables: `2026_11_03_000001_create_intelligence_center`.

## Model
Findings are produced from the registered `InsightProvider`s (Action Center) **through the viewer's own permissions and data scope**, then stored per audience user. A finding is only readable by its audience (others get 404). Fingerprint = sha256(tenant, audience user, insight key): scans update the same row (dedupe across runs). Lifecycle: `open`, `acknowledged`, `snoozed`, `resolved`, `dismissed`.
- resolved -> open on next detection (occurrence count +1); dismissed -> open only if severity escalates; snoozed -> open after `snoozed_until`; open/acknowledged/snoozed findings no longer detected -> `resolved` ("No longer detected"). Every change is written to `intelligence_finding_events` (history) and to the audit log.
- snoozed/resolved/dismissed require a reason; snoozed needs a future `snoozed_until`. Invalid transitions return 422.
- Owner = audience user (reassignment deferred: it needs a scope re-check). Due date defaults by severity (critical 2d, action_required 5d, warning 14d) and is editable.

## Endpoints
- `GET findings` live (not stored) Action Center pass-through, kept for compatibility.
- `GET center?status=active|all|open|...&domain=&severity=` stored findings (sorted by severity, count).
- `POST center/scan` refresh own findings now. Returns `summary` (created/updated/reopened/auto_resolved/unsnoozed) and the list.
- `GET center/{uuid}` finding + history. `GET center/{uuid}/explain` Explain-this.
- `POST center/{uuid}/status` `{status, reason?, snoozed_until?}`. `POST center/{uuid}/due` `{due_on}`.
- `POST center/{uuid}/task` creates one My Work task (`TaskService::createOnce`, source `intelligence`, entity `finding:<uuid>`); repeat calls return `created:false`. Resolving/dismissing completes the task. Evidence `actions[]` carries the module's existing action link (use it instead of a task if preferred).
- `POST center/bulk-acknowledge` `{uuids[<=100]}` returns `{acknowledged, skipped}` (own open findings only).
- `GET scenarios` kinds + own run history. `POST scenarios` `{kind, params, title?}` returns 201. `GET scenarios/{uuid}` own run.
- `GET risk-signals`; `GET/PUT settings` `{risk_signals_enabled, min_cohort (3..100)}`.
- `GET employees/{uuid}/time-story` dated facts (joined, probation, confirmation, status changes, approved leave spans without type or reason, approved overtime). 404 outside the viewer's data scope.

### Explain-this (`explain`)
`inputs` (insight key, matching records, sample shown, provider inputs), `rule`, `threshold`, `as_of` (timestamp, tenant date, timezone), `scope` (viewer data scopes and how providers apply them), `suppressed` (records beyond the sample, note, engine rules), `deterministic: true`. Providers may add optional `rule`, `threshold`, `inputs` keys to their insight arrays; otherwise a generic rule text is used.

### Scenarios (read-only)
Kinds: `leave_policy_sandbox` (needs `leave.simulate`; params `employee_uuids, leave_type_id, rules|policy_version_id, period_start, period_end`), `leave_forecast` (`leave.simulate`; `employee_uuid, months, leave_type_id?`), `payroll_simulation` (`payroll.simulate`; `simulation_kind, params, employee_uuids`), `headcount_plan` (`months, hires_per_month, exits_per_month`; scoped current headcount). The owning module's simulator runs for the viewer inside a transaction that is **always rolled back**, so nothing (including the simulator's own run log) is written to those domains; only an `intelligence_scenario_runs` row is stored afterwards. Responses include `read_only:true, writes_rolled_back:true`. Other modules can add sources via `ScenarioService::register(kind, permission, callable)`.

### Risk signals (deterministic, disabled by default)
Indicators: joined < 12 months, in or extended probation, 3+ approved leave requests in 90 days, 40h+ approved overtime in 90 days, active performance improvement plan. Output is **cohort (department) level only**; groups smaller than `min_cohort` are merged into "Other (small groups combined)" when that is large enough, otherwise withheld; counts between 1 and `min_cohort - 1` appear as `<N`. No sensitive attributes, leave types or reasons are read. Every response carries the human-review notice: indicators are not predictions, scores or decisions. Viewing is audited.

## Scheduling
`php artisan intelligence:scan [--tenant=ID]` scans every active user holding `intelligence.view` in tenants with the `intelligence` module; idempotent (fingerprints). Scheduled daily 04:30 in `routes/console.php`.

## Employee 360
Section `intelligence` (order 95) lists only the viewer's own open findings that name the employee (from evidence samples) plus a time-story link; omitted when empty or when the employee is outside the viewer's scope.
