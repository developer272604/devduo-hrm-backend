# Leave policies, types, cycles and rule configuration (Phase 4, workstream A)

Base path `/api/v1/leave` (module `leave`). Code: `backend/app/Domain/Leave/{Types,Policies,Cycles,Eligibility}`,
controllers `App\Http\Controllers\Leave\Policies`, routes `backend/routes/leave/policies.php`, binding `Boot/PolicyBoot.php`.
Read `leave-foundation.md` first (units are 4-dp strings, Employee is not User, no country law in code).

Conventions: every route is tenant scoped (another tenant's uuid is `404`), requires the listed permission, and returns `{data: ...}`.
Validation errors are `422` Laravel style (`errors.<field>`). Policy writes are audited (`Audit::record`, module `leave`).
"Manage" = `leave.policy.manage` or `leave.manage`.

## 1. Leave types

| Method and path | Permission | Notes |
| --- | --- | --- |
| `GET types?active_only=1&kind=` | leave.view / leave.request / manage | ordered by `sort_order`, `name` |
| `GET types/meta` | same | enums for forms: `kinds, categories, units, defaults, config_keys, type_rule_keys, policy_rule_keys, carry_forward_modes, consumption_orders, pay_classes` |
| `POST types` | manage | create (201) |
| `GET/PATCH/DELETE types/{uuid}` | read / manage / manage | |
| `POST types/seed-defaults` | manage | idempotent country-neutral starters: ANNUAL, CASUAL, SICK, UNPAID, COMPOFF, OPTIONAL (no entitlement) |

Type object: `{uuid, name, code (upper-case, unique per tenant), kind, category, unit, tracks_balance, is_statutory, is_sensitive, color, sort_order, config, description, active}`.

* `kind`: annual casual sick medical parental maternity paternity bereavement marriage study sabbatical unpaid comp_off optional_holiday custom.
* `category`: paid unpaid statutory company (statutory defaults `is_statutory=true`; kind `medical` defaults `is_sensitive=true`).
* `unit`: day half_day quarter_day hour.
* `config` keys (all optional): `countries[]` (ISO-2), `legal_entities[]`, `employee_types[]` (ids), `evidence {required, after_units}`, `limits`, `half_day_allowed`, `notes`, `holiday_pool`.
* Once a type is used by a policy, account, request, ledger entry, document or restriction rule, `code`, `unit` and `tracks_balance` are frozen (`422`) and the type cannot be deleted (deactivate with `active:false`).

## 2. Policies and versions

A policy is a named container; behaviour lives in **versions** (effective dated). Version `status`:
`draft` (editable) -> `scheduled` (published, starts in the future) -> `active` (in effect) -> `retired`.
Published versions (`immutable: true`) never change: only `status` and `effective_to` move (when a neighbour is published/retired), and `checksum`
(sha256 of canonical policy rules + type rules) proves it (`integrity_ok`). Statuses are re-derived from dates whenever policies are listed/viewed.

| Method and path | Permission | Notes |
| --- | --- | --- |
| `GET policies` | manage | with version summaries |
| `POST policies` | manage | body `{name, code, description?, country?, rules?, type_rules?, effective_from?, effective_to?, change_note?}` creates policy + draft v1 |
| `GET/PATCH policies/{uuid}` | manage | PATCH: `name, description, country, active` |
| `POST policies/{uuid}/versions` | manage | new draft; `change_note` required; copies the base version (latest or `base_version`) unless `rules`/`type_rules` are sent |
| `GET policies/{uuid}/versions/{n}` | manage | full detail: `rules`, `type_rules[]`, `checksum`, `integrity_ok` |
| `PATCH policies/{uuid}/versions/{n}` | manage | draft only: `rules, type_rules (replaces all), effective_from, effective_to, change_note` |
| `DELETE policies/{uuid}/versions/{n}` | manage | draft only |
| `PUT policies/{uuid}/versions/{n}/type-rules/{typeUuidOrCode}` | manage | draft only, body `{rules:{...}}` |
| `DELETE .../type-rules/{typeUuidOrCode}` | manage | draft only |
| `POST .../publish` | manage | validates, closes the previous version the day before, sets `active` or `scheduled`; response `{data, closed[], warnings[]}` |
| `POST .../schedule` | manage | body `{effective_from, effective_to?}` must be after today; then publishes (`scheduled`) |
| `POST .../retire` | manage | ends an active version today, or cancels a never-started scheduled one (the earlier version regains its range); `{data, warnings[]}` |
| `GET policies/{uuid}/diff?from=1&to=2[&against=<other policy uuid>]` | manage | Policy Difference Viewer |

`type_rules` input: `[{type: "<uuid or code>", rules: {...}}]`. Publish rules: at least one type rule; types active; every `cycle` code exists;
no other live version starts on the same date; no overlap; a back-dated `effective_from` is refused if leave accounts/requests already exist under the
policy on or after that date (no historical rewrite).

### Rule documents (validated; unknown keys are 422)

Policy-wide (`rules`): `sandwich {enabled, apply_to[], count_holidays, count_weekly_offs}`, `approvals {steps[], escalate_after_hours}`, `notice_days`, `backdate_days`, `future_days`, `cycle` (cycle code), `restrictions`, `notes`.

Per type (`type_rules[].rules`): `unit`, `entitlement {basis, amount, tenure_bands[{from_days, amount}]}`, `accrual`, `eligibility {probation_days, min_tenure_days, max_tenure_days, requires_confirmation, block_during_notice, employee_types[], legal_entities[], countries[], worker_types[]}`,
`limits {min_units, max_units, min_consecutive, max_consecutive, advance_leave, negative_limit, max_balance}`, `carry_forward {mode none|full|capped|percentage, cap, percent, valid_days}`, `expiry`, `consumption_order` (fifo expiry_first current_first carried_first),
`documents[]`, `statutory`, `pay_class` (paid unpaid statutory lop comp_off), `approvals`, `cycle`, `negative_balance`, `restrictions`, `half_day`, `notice_days`, `backdate_days`, `future_days`, `optional_holidays`, `notes`.
All unit amounts must be non-negative with at most 4 decimals (send strings).

### Diff response

`{from:{policy,policy_name,version,status,effective_from,effective_to,checksum}, to:{...}, meta_changes:[{field,before,after}], changes:[{section ("policy" or type code), section_label, path, change: added|removed|changed|type_added|type_removed, before, after}], summary:{total,added,removed,changed}, identical}`. Numeric values compare by value (`12` equals `12.0000`).

## 3. Assignments and resolution

Assignments bind a policy to a target with effective dates (same vocabulary and ranking as attendance): `target_type` in employee team department employee_group business_unit location employee_type legal_entity country tenant.
Precedence: specificity (employee 100 ... tenant 0), `is_override`, `priority`, `effective_from`, `id`. Org attributes are read as of the date. A winner whose policy is inactive or has no version covering the date is skipped (explained).

| Method and path | Permission |
| --- | --- |
| `GET policy-assignments?policy=<uuid>` | manage |
| `POST policy-assignments` | manage; `{policy, target_type, target_id?, effective_from, effective_to?, priority?, is_override?, note?}`; employee `target_id` accepts uuid or id; country is ISO-2; same target + dates + override + priority is rejected as ambiguous |
| `PATCH/DELETE policy-assignments/{uuid}` | manage |
| `GET policies/resolve?employee=<uuid>&date=YYYY-MM-DD[&leave_type=<uuid or code>]` | manage or `leave.view`, **employee must be inside the caller's data scope** (else 404) |

Resolve response: `{employee, date, policy:{uuid,name,version,version_uuid}|null, matched_by, ambiguous, considered:[{assignment_id, policy, target_type, target_id, rank, is_override, priority, outcome: selected|not_used|skipped, reason}], explanation, leave_type?:{uuid,code,granted,reason,rules,eligibility}, types?}`.
`rules` is the policy-wide document overlaid by the type document (type keys win). Backend contract: `App\Domain\Leave\Contracts\LeavePolicyResolver` is bound to `Policies\PolicyResolver` (`resolve()`, `rulesFor()`); the concrete class also offers `resolveWithTrace()` and `explainType()` (reason when not granted) and `versionOn()`. `rulesFor` returns an extra `eligibility` result and `policy_rules`/`type_rules`.

## 4. Eligibility

`GET eligibility/check?employee=&leave_type=&date=` (manage or `leave.view`, data scoped) returns `{granted, reason, eligibility:{eligible, eligible_from, checks[{check,passed,detail}], reasons[]}}`.
Two layers: *applicability* (countries / legal entities / employee types from the type `config` and the rule `eligibility`; failing = the type is not granted, `rulesFor` returns null) and *time based* eligibility (probation_days and min_tenure_days counted from `joined_on`, `requires_confirmation`, `block_during_notice` (employee has a future/current `separated_on`), `worker_types`). All thresholds are policy data.

## 5. Cycles

| Method and path | Permission |
| --- | --- |
| `GET/POST cycles`, `GET/PATCH cycles/{uuid}` | manage |
| `GET cycles/{uuid}/periods` | manage |
| `POST cycles/{uuid}/periods/generate` `{from,to}` | manage; idempotent, never touches existing periods (`{created, existing, periods[]}`) |
| `GET cycles/{uuid}/period-for?date=&employee=<uuid>|anchor=` | manage or leave.view (employee data scoped) |

Cycle: `{uuid,name,code,kind,start_month,start_day,length_months,config,active,locked}`. `kind`: `calendar` (Jan 1, 12 months), `fiscal` (start month/day, 12 months, label `FY 2026-27`), `anniversary` (per employee from joining date; periods are not pre-generated, use `period-for`), `custom` (any `length_months` 1..60 from start month/day, series anchored at `config.anchor_year`, default 2000). Month-end clamping (29 Feb -> 28 Feb). `code` is create-only; once periods exist the shape fields are frozen (`locked: true`). PHP helper: `Leave\Cycles\CycleCalculator::periodFor()/periodsBetween()` and `CycleService::periodFor()` for the accrual/account code.

## 6. Restriction rules, document rules, optional holiday pools (configuration only; enforcement is workstream C)

| Method and path | Permission |
| --- | --- |
| `GET/POST restriction-rules`, `PATCH/DELETE restriction-rules/{uuid}` | manage |
| `GET/POST document-rules`, `PATCH/DELETE document-rules/{id}` | manage |
| `GET/POST optional-holiday-pools`, `PATCH/DELETE optional-holiday-pools/{uuid}` | manage |

* Restriction: `{name, kind (blackout max_concurrent min_staffing notice consecutive eligibility backdate future_limit), action (warn|block|escalate), exempt_statutory (default true), target_type/target_id, leave_type (uuid/code, optional), start_date, end_date, params{}, active}`. `blackout` needs both dates; `max_concurrent` needs `params.max`; `min_staffing` needs `params.min_percent`.
* Document rule: `{leave_type, policy_version? (uuid, draft versions only), required_after_units, document_kind, mandatory, is_sensitive (defaults to the type), submit_within_days, retention_days, description, active}`.
* Optional pool: `{name, year, max_selection, leave_type?, holidays:[{date,name?,holiday_uuid?}], applicability?, active}`; dates must lie in `year`, be unique, `1 <= max_selection <= count`; name unique per year.

## 7. Domain events and audit

Publish records `leave.policy.published` (payload: version uuid; HR confidential). All create/update/delete/publish/retire actions are audited under module `leave`.
