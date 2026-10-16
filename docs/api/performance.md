# Performance, Goals, OKRs and KPIs (Phase 8 core)

Base: `/api/v1/performance` (session + CSRF, `module:performance`). Every record is tenant scoped (cross-tenant ids return 404). Public ids are UUIDs. Decimals are returned as strings.
Authorization = permission key + DataScope + relationship (self / manager) + visibility rules. Nothing here changes pay, grade or employment: scores are inputs for human decisions.

## Permissions
`performance.self` (own goals, check-ins, reviews, feedback, 1:1, plans; Team member + Manager), `performance.view` (read people in scope; Manager, HR), `performance.goals.manage`, `performance.goals.approve`
(Manager, HR), `performance.reviews.manage`, `performance.reports.view` (Manager, HR), `performance.cycles.manage`, `performance.calibrate`, `performance.calibrate.approve`, `performance.pip.manage`,
`performance.outcomes.view` (HR, admin), `performance.feedback.reveal` (workspace admin only). Grant migration: `2026_10_18_000002_grant_performance_permissions.php`.

## Goals / OKRs / KPIs
| Method | Path | Notes |
| --- | --- | --- |
| GET | `goals` | filters `level,status,kind,owner_uuid,cycle_uuid,mine`; only goals visible to the viewer (owner/creator, `company` visibility, same department for `team`, or data scope of `performance.view`) |
| POST | `goals` | `level` company\|department\|team\|individual, `kind` goal\|objective\|kpi, `parent_uuid` (must be a higher level), `weight`, dates, `target_value` etc. Own individual goals start as `draft`; goals set by a manager/HR for someone in scope, and company/department/team goals, are `active` |
| GET/PATCH | `goals/{uuid}` | detail with key results and check-in history; editing measurable fields bumps `version` and sends self-owned goals back to `pending_approval` |
| POST | `goals/{uuid}/submit` / `decision` | owner submits; approver (`performance.goals.approve`, in scope, never the owner) `{decision: approve\|reject, note}` (note required to reject); routed through the approver resolver as a My Work task |
| POST | `goals/{uuid}/cascade` | `{owner_uuids[], weight?}` creates aligned individual child goals; idempotent per (parent, owner) |
| POST | `goals/{uuid}/key-results` | `{title, weight, start_value, target_value, direction}` |
| POST | `goals/{uuid}/checkins` | append-only; `{key_result_uuid?, value?, progress?, confidence, note}`; response has `progress_before/after` |
| POST | `goals/{uuid}/complete` / `cancel` | |
| GET | `goals/{uuid}/alignment` | ancestors + visible aligned tree (goal alignment map) |

Progress: key result = clamp((current-start)/(target-start)) 0..100 (works for decreasing targets); goal = weighted average of key results, else of active aligned child goals, else metric (KPI) or manual check-in; propagates to ancestors. All arithmetic is decimal (Brick), scale 2 half-up.

## Configuration
`GET/POST rating-scales` (`{code,name,min,max,labels[{value,label}]}`), `GET/POST competencies`, `GET/POST templates` (`sections[{key,title,type,weight,mandatory}]`, `reviewer_weights{manager,self,peer,skip}` must total 100),
`GET/POST cycles`, `GET cycles/{uuid}`, `POST cycles/{uuid}/publish`, `GET cycles/{uuid}/eligibility` (preview), `POST cycles/{uuid}/launch` (idempotent; adds only newly eligible people).
Cycle `eligibility`: `legal_entity_ids, department_ids, employee_type_ids, location_ids` (effective-dated assignment as of the reference date), `min_tenure_days`, `exclude_probation`. `settings`: `min_peer_responses` (default 1), `peer_anonymous` (default true), `share_peer_feedback`.
Status: draft -> published -> open -> calibration -> closed.

## Reviews
`GET reviews`, `GET reviews/{uuid}` (privacy filtered, see below; includes the visible template sections, scale and active competency choices needed for an assigned reviewer to respond), `POST reviews/{uuid}/peers {employee_uuids[]}` (subject, their manager or HR; max 8), `POST reviews/{uuid}/manager {manager_uuid}` (HR; before submission),
`POST review-responses/{uuid}/submit {items[{section,ref,label?,weight?,rating,comment?}], comments}` (assigned reviewer only), `POST reviews/{uuid}/acknowledge`.
Scoring (`ScoreEngine`): item ratings -> section average (item weights) -> response score (section weights) -> review score (reviewer-type weights, renormalized over submitted types; peers counted only when `min_peer_responses` is met).
The full `trace` is returned to managers/HR. Ratings must sit inside the scale; mandatory sections are enforced.
Visibility: the employee sees their own response; after finalization also the result and manager response (peers only if `share_peer_feedback`). Managers see self/manager/peer responses of people in scope; peers are anonymous (names only with `performance.feedback.reveal`)
and hidden below the minimum count; skip-level input is visible to HR and its author. Reviewers see only their own response.

## Calibration (maker-checker)
`POST cycles/{uuid}/calibration/start`, `GET cycles/{uuid}/calibration` (distribution, fairness warnings as prompts, history), `POST reviews/{uuid}/calibration-changes {to_score, justification}`,
`POST calibration-changes/{uuid}/decision {decision, note}` (a different user with `performance.calibrate.approve`), `POST cycles/{uuid}/finalize` (blocked while changes are pending; finalizes reviews with a submitted manager review; others are listed in `not_ready`).
Finalized reviews are immutable (model guard + `snapshot` of trace, template, scale and calibration history); only the employee acknowledgement fields can change. Audit: `review.rating_change_proposed`, `review.rating_changed`, `review.finalized`.

## Feedback, 1:1, development, PIP
`POST feedback`, `GET feedback/received|given`, `GET employees/{uuid}/feedback` (recipient, giver; managers only when shared with `recipient_manager`; not HR by default).
`POST one-on-ones`, `GET one-on-ones` (own journal: sessions you authored plus shared sessions about you, with `employee`), `GET employees/{uuid}/one-on-ones` (employee + manager chain author; `private_notes` only to the author; encrypted at rest).
`GET/POST development-plans`, `POST development-plans/{uuid}/actions`, `PATCH development-actions/{uuid}` (links to goals, skills, learning references).
PIP (`performance.pip.manage`, encrypted, every read/write audited): `GET pips`, `GET pips/mine` (employee, once active), `POST pips`, `GET pips/{uuid}`, `POST pips/{uuid}/activate`, `POST pips/{uuid}/checkpoints/{cuuid}`, `POST pips/{uuid}/complete`. Outcomes are recorded by a human; nothing is automatic.

## Dashboard and outcomes
`GET dashboard` (`performance.reports.view`; scoped to the viewer; completion, late responses and `late_list` [employee, reviewer_type, due_on, days_late, review_uuid; reviewer identity never listed], distribution suppressed under 3 scored reviews, calibration counts, goal status).
`GET outcomes?cycle_uuid=&as_of=` (`performance.outcomes.view`) and the PHP contract `App\Domain\Performance\Contracts\PerformanceOutcomeProvider` (`outcomesFor`, `latest`) expose finalized review outcomes only, for Phase 5 compensation revision and Phase 7 promotions.

## Integration
Action Center (`PerformanceInsightProvider`), Employee 360 section (facts only: latest finalized rating, active goals; never PIP/feedback/notes), data-quality rules (`performance_*`), My Work tasks + notifications, DomainEvents (`GoalCreated`, `PerformanceCycleOpened`, `PerformanceReviewSubmitted`, `PerformanceReviewFinalized`, `PerformancePlanActivated`).
Scheduler: `performance:remind` daily 07:30 (idempotent via `reminded_at` and `createOnce`).

## Deferred
Appeals and cycle reopen, acknowledgement SLA reminders, goal dependencies and ownership transfer, goal evidence uploads, recognition feed, mentoring, team-level trend analytics, bulk nomination UI, per-field `FieldAccess` registry entries for review text, and deeper frontend administration/workflows (see `docs/PHASE-8-REPORT.md`).


