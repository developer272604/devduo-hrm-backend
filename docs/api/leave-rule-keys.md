# Leave rule documents: canonical key list (P04-T23)

One vocabulary for `leave_policy_versions.rules` (policy-wide) and `leave_policy_type_rules.rules` (per type). The **gate** is `Policies\RuleValidator`
(unknown keys are 422), so every consumer must read exactly these keys. The resolved document handed to B, C and D is `LeavePolicyResolver::rulesFor()['rules']`
= policy-wide keys overlaid (shallow, type wins) by the type document.

Units are strings with at most 4 decimals. Nothing here is country law: all values are data.

## Policy-wide keys

| Key | Shape | Read by |
| --- | --- | --- |
| `sandwich` | `{enabled, apply_to[codes], count_holidays, count_weekly_offs}` | C `RuleSet::sandwich` / DurationEngine |
| `approvals` | `{steps[manager,hr], escalate_after_hours, hr_above_units, hr_for_sensitive}` (also allowed per type) | C `RequestPlanner`, `DecisionService` |
| `notice_days`, `backdate_days`, `future_days` | int days (also allowed per type; `limits.*` wins in C) | C DurationEngine (fallback: `LeaveSettings requests.*`) |
| `cycle` | cycle code (string; B also accepts an inline `{kind,start_month,start_day,length_months}`) | B `PeriodResolver` |
| `restrictions`, `notes` | free documentation/config, not evaluated by engines (restriction rules live in `leave_restrictions`) | none |

## Per-type keys

| Key | Shape | Read by |
| --- | --- | --- |
| `unit` | `day half_day quarter_day hour` | C (type unit wins) |
| `entitlement` | `{basis annual\|monthly\|weekly\|daily\|pay_period\|anniversary\|manual, amount, timing start\|end, tenure_bands[], start_after_days, waiting, max_per_period, pay_period{}, proration{joiner,leaver,joiner_cutoff_day,leaver_cutoff_day,probation,rounding}}` | B `RuleReader`; D `RuleEvaluator` (draft sandbox only) |
| `entitlement.tenure_bands[]` | `{from_days, to_days?, amount}` (canonical). `from_months`/`to_months` accepted. B converts days to months at 30.4375; D accepts also `min_days/min_months/min_years` | A validator, B, D |
| `accrual` | reserved, not read (accrual is authored in `entitlement`; legacy fallback is `leave_accrual_rules`) | none |
| `eligibility` | `{probation_days, min_tenure_days, max_tenure_days, requires_confirmation, block_during_notice, employee_types[ids], legal_entities[ids], countries[ISO-2], worker_types[]}` | A `EligibilityEvaluator` (rulesFor null when not applicable); C `RestrictionEngine` (probation, tenure, types, entities, countries); B `start_after_days` falls back to `min_tenure_days` |
| `limits` | `{min_units, max_units, min_consecutive, max_consecutive, negative_limit, max_balance, advance_leave (bool), notice_days, backdate_days, future_days, allowed_portions[], day_counting}` | C (all request limits); B (`negative_limit`, `max_balance`, `advance_leave`) |

`limits.max_consecutive` counts the calendar span of the request plus any contiguous pending or approved requests for the same employee and leave type. A gap breaks the chain; dates removed by partial cancellation are excluded. For statutory leave, exceeding the limit is escalated for review rather than automatically refused.
| `negative_balance` | units; fallback for `limits.negative_limit` (B) | B `RuleReader` |
| `carry_forward` | `{mode none\|full\|capped\|percentage, cap, percent, rounding, valid_days, valid_months}` | B `CarryForwardService`; D `RuleEvaluator` |
| `expiry` | `{valid_days \| valid_months \| at_period_end}` | B `RuleReader::expiryFor` |
| `consumption_order` | canonical `earliest_expiring current_first carried_first oldest_first`; aliases `expiry_first` = earliest_expiring, `fifo` = oldest_first (`ConsumptionPlanner::normalizeOrder`) | B `ConsumptionPlanner`; C `RequestLedger` (allocates lots through it) |
| `pay_class` | `paid unpaid statutory lop comp_off` (D payroll adds `encashment` on settlement lines, never in rules) | C segment pay class, D `PayrollContract` |
| `statutory` | bool; statutory types are never auto-denied by C | C |
| `documents[]`, `half_day`, `optional_holidays` | configuration lists (document rules and pools are also CRUD tables) | C (evidence), none for the others |
| `transfer` | `{mode preserve\|transfer\|convert\|expire}` | D `TransferService` |
| `encashment` | `{on_separation, max_units}` | D `TransferService` |
| `separation` | `{mode expire\|preserve}` | D |
| `rehire` | `{restore none\|all\|capped, cap}` | D |

## Mismatches fixed in T23

| Mismatch | Fix (owning file) |
| --- | --- |
| A rejected D's `transfer`, `encashment`, `separation`, `rehire` rule keys as unknown (so D's documented rules could not be authored through the API) | `Policies/RuleValidator.php`: keys added to `TYPE_KEYS`, shape and `transfer.mode` validated |
| Tenure bands: A required `from_days`, B read only `from_months` (every A-authored band became month 0, so the last band always won), D read `min_days/months/years` | `Accrual/RuleReader.php` accepts `from_days/to_days` (and months); `Simulation/RuleEvaluator.php` accepts `from_days/from_months`; A also accepts `from_months` |
| `consumption_order`: A allowed `fifo expiry_first current_first carried_first`, B allowed `earliest_expiring current_first carried_first oldest_first` (A-valid `fifo`/`expiry_first` silently fell back to default in B) | `RuleValidator::CONSUMPTION` accepts both sets; `ConsumptionPlanner::normalizeOrder` maps the aliases; `RuleReader` normalises |
| `limits.advance_leave`: A required a number, B treats it as a flag (`true` was rejected, `"0.0000"` read as truthy) | `RuleValidator` accepts a bool (number still allowed); `RuleReader::flag` treats numbers above zero as on |
| `negative_balance` (A) was ignored by B | `RuleReader` falls back to it when `limits.negative_limit` is absent |
| C `RequestLedger` ignored `consumption_order` (always earliest-expiring) and did not use B's `ConsumptionPlanner` | `Requests/RequestLedger.php` allocates through `ConsumptionPlanner` with the rule's order |

Known, left as-is: the explicit-version path in C `RuleSet::load` merges with `array_replace_recursive` while A's `rulesFor` merges shallowly (type key replaces the whole policy key, e.g. `approvals`); only differs when both levels define the same object key.
