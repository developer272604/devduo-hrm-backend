# Attendance calculation engine (contract)

Namespace `App\Domain\Attendance\Engine`. Entry point: `AttendanceCalculator::calculate(CalculationInput): CalculationResult` (static; also callable on an instance). `AttendanceCalculator::ENGINE_VERSION` = `1.0.0`.

## Guarantees
- Pure: no DB, facades, clock (`input->now`), randomness or static mutable state. Same input => byte-identical `toArray()`, `steps` and `explanation`; punch input order is irrelevant (sorted by instant, then id).
- Never invents a time. Missing punches are only filled when `missing_punch.auto_fill=true` AND `auto_fill_rule` is `shift_end` (fills a missing OUT) or `shift_start` (fills a missing IN). The result is flagged `auto_filled`, a step says "auto-filled by approved rule", and an info exception is raised.
- All arithmetic uses UTC instants from the DTOs (DST nights are 7/9 real hours). Minutes are whole minutes (seconds truncated).
- Severity is never `critical`. Integrity wording is neutral ("Attendance integrity review required").

## Versioning
Processor stores `ENGINE_VERSION` with every result. MAJOR = can change status/minutes for already-valid inputs; MINOR = new optional behaviour/rule/trace detail; PATCH = bug fix/wording. Bump in the same commit as the behaviour change and pin it with a test. Stored results are only changed by an explicit, audited recalculation.

## Inputs
`CalculationInput`: schedule (`DaySchedule`), merged `rules` (an empty array, or no policyVersionId and no policyLabel, means NO POLICY: `PolicyRules::DEFAULTS` are used, `no_policy` raised, confidence review_required), punches, `now`, onDuty, leave, monthToDate, approvedOvertimeMinutes (explanation only), remoteApproved, employeeTypeOvertimeEligible, policyLabel/VersionId, previousDayEndUtc (ISO string), compliance.

DTO addition (optional, backwards compatible): `DaySchedule::$holidayWindowAdjusted` (bool, default false). When `holidayPortion` is first_half/second_half and this is false, the engine shifts the expected start (first_half holiday) or end (second_half holiday) by half the scheduled span; `requiredMinutes` is trusted as given (the resolver should already supply the reduced value). Set true if the resolver already shifted start/end.

## Steps (in order; each pushes `{step, rule, inputs, outcome}`)
1. `policy` – policy used / defaults.
2. `schedule` – expected window, required/half/min minutes, accepted window, day-finished. Accepted punch window = `schedule.windowStart..windowEnd` (inclusive) on working days (fallback: local 00:00 to next day 06:00); on non-working days always local `date 00:00` to `date+1 06:00` (shift-timezone). `leave` and half-day-holiday sub-steps adjust required minutes and the expected window (a leave/holiday half removes that half of the scheduled span).
3. `punches.normalize` – sort; drop same-direction punches within `duplicate_window_seconds` of the previously KEPT same-direction punch (earliest kept, dropped ids recorded); different directions or farther apart are distinct and never removed; punches outside the window => `unexpected_punch` (info) and not used. Geofence (`outside`/`outside_geofence`) => `outside_geofence` warning + review (suppressed on approved remote/on-duty days). Failed/rejected verification, or unverified/missing/pending when `require_photo` => `integrity_review` + review.
4. `pairing` – strategies: `strict` (declared IN/OUT trusted; UNKNOWN = opposite of previous resolved, first = IN), `alternating` (declared ignored; IN,OUT,IN..), `first_last` (first IN, last OUT; intermediates ignored, or used as breaks if `breaks.mode=punch` and their count is even). An IN while one is open => `duplicate_in` (earlier kept); OUT with nothing open => `missing_in` (if first) else `duplicate_out`; open IN at end => `missing_out`. Sessions longer than `max_session_minutes` are capped (flag `session_capped`, `shift_mismatch` info, review).
5. `missing_punch` – deferral while the day is unfinished (open IN stays pending) or auto-fill.
6. `hours` – gross = union of sessions + punched gaps + on-duty intervals (capped at 1440). `breaks` – see below. `flexible.core_hours`, `split.segments` (per-segment attendance; gaps between work segments are never breaks).
7. `late`, `late.allowance`, `flexible.window`, `early_exit`, `early_exit.allowance`.
8. `overtime`. 9. `status`. 10. `compliance.*`. 11. `result`.

## Rule semantics
- Late: delay = first_in - expected start. `beyond_grace`: max(0, delay - grace). `from_start`: whole delay if delay > grace else 0. (09:00 shift, grace 10: 09:08 => 0; 09:18 => 8 / 18.) Early exit identical on expected_end - last_out. Early exit is not evaluated while the day is unfinished or an OUT is missing.
- Allowances: occurrence number = `monthToDate.late_count + 1`. `allowed_occurrences_per_month` set: within limit => `late_arrival` info; exceeded => warning and `flags.late_counts_as_occurrence=true`. `escalate_after_occurrences` reached or `monthly_threshold_minutes` exceeded (monthToDate.late_minutes + today) => action_required. No allowance configured => warning, flag false. `half_day_after_minutes`: delay-from-start (ungraced) greater than it => `flags.half_day_by_late`, a full day becomes `half_day`. `penalty_reference`/`consequence_reference` are copied into steps/exception details, never interpreted. Early: same with `early_count`, `threshold_minutes` => action_required.
- Breaks: `punch` deducts punched gaps minus overlap with scheduled PAID break segments (`breaks.paid=true` deducts nothing). `fixed`: deduct the schedule's UNPAID break segments if any, else `fixed_minutes` (no double deduction); nothing without sessions. `auto_deduct`: punched gaps if any, else `auto_deduct_minutes` when gross >= `auto_deduct_after_minutes`. `none`: nothing. `excessive_break_minutes` / `max_breaks` => `excessive_break` (info). Net = max(0, gross - break).
- Flexible: effective start = clamp(first_in, flexStartFrom, flexStartTo); expected end = effective start + required + scheduled unpaid break (or fixed minutes in fixed mode); lateness measured from `flexStartTo` (nothing inside the window). Core hours: presence must cover the whole core window when `present_requires_core_hours`; otherwise a would-be full day becomes `half_day` with `shift_mismatch`.
- Split: late from the first work start, early from the last work end; a missed segment with other attendance => `shift_mismatch`.
- Overtime (per type: raw -> flag -> grace (raw <= grace => 0) -> minus threshold -> minimum -> rounding down/nearest(half up)/up -> running cap `maximum_minutes_per_day`; order pre_shift, post_shift, weekly_off, holiday, rest_day). Working day: pre_shift = presence before expected start (flex: before flexStartFrom), post_shift = presence after expected end. Non-working day: whole net minutes as weekly_off / holiday / rest_day (not_scheduled). Disabled when `overtime.enabled` off, employee type or schedule ineligible. `overtime` info exception when total > 0 and `requires_approval`. Approved OT only appears in the explanation.
- Compliance (flags only): `min_rest_minutes` vs `previousDayEndUtc` -> `rest_violation`; `max_daily_minutes` vs gross -> `rest_violation` (details.check tells which).

## Status decision table (first match wins)
| Condition | Status | Confidence |
|---|---|---|
| Non-working, no work | weekly_off / holiday / not_scheduled | confirmed |
| Non-working, worked, work allowed | present (+`unscheduled_work` info, OT type) | per data |
| Non-working, worked, `weekend|holiday.work_allowed=false` | keeps day status, `unscheduled_work` warning | per data |
| Leave covers full day | leave | confirmed |
| On-duty covers shift | on_duty (remote_work if all intervals are type remote_work) | confirmed |
| Unresolved pairing anomalies | exactly one missing_in/out => missing_punch (or incomplete if `mark_status=incomplete`); anything else => incomplete | review_required |
| No punches | pending if day unfinished and `future_days_pending`, else absent | pending / confirmed |
| Open session, day unfinished | pending | pending |
| net < `minimum_minutes` | absent | |
| net >= required | present (remote_work when `remoteApproved`); core hours unmet or half_day_by_late => half_day | |
| net < required, day unfinished | pending | pending |
| net >= half-day | half_day (+`insufficient_hours`) | |
| else | absent (+`insufficient_hours`) | |
Confidence: review_required if any missing/unpaired, geofence, verification, no_shift, no_policy, capped session; pending if status pending; otherwise confirmed.

## Exception catalogue (severity default)
late_arrival (warning; info within allowance; action_required on escalation), early_departure (warning/info/action_required), missing_in / missing_out (action_required; info when auto-filled), insufficient_hours (warning), unexpected_punch (info), outside_geofence (warning), unscheduled_work (info; warning if not allowed), excessive_break (info), overtime (info), shift_mismatch (warning), no_shift (action_required), no_policy (action_required), unpaired_punch (action_required), integrity_review (warning), rest_violation (warning). Order: severity desc, type, rule, creation order.

## Helpers
`Explainer::explain` (called by the calculator; lines: Scheduled shift, Required full/half day, First in/Last out, Time at work/Unpaid break, Net work, Late/Arrival, Early exit, Overtime, Policy, Result; plus facts and actions), `Explainer::dayReconstruction($events, $tz, $attendanceDate)`, `Simulator::compare/summarize`, `Pairer`, `PunchNormalizer`, `BreakCalculator`, `OvertimeCalculator`, `Intervals`, `Session`, `Pairing`, `CalculationRun` (internal).

## Processor notes
- `monthToDate`: counts/minutes of late and early occurrences on earlier dates of the same month (the result's `late_minutes>0` / `early_exit_minutes>0` days, using recalculated values).
- `previousDayEndUtc`: ISO UTC of the previous day's last_out (or scheduled end if none).
- Persist `ENGINE_VERSION`, `calculated` and `approved` overtime separately.
- Employee-level `remoteApproved` should only be true for approved remote days.

## Known limitations
- Split-shift status uses total net minutes versus required minutes (no per-segment pay logic). Fixed-mode break deduction applies once per day, regardless of which segment.
- A holiday/leave half removes half of the scheduled span from the expected window; unequal halves must be supplied by the resolver via `holidayWindowAdjusted=true`.
- Partial on-duty is counted as presence minutes (no late/early judgement against it). Full on-duty suppresses late/early/overtime.
- Overnight on-duty/leave across two logical dates is not split.
- Late/early for a missing OUT or in-progress day is deferred, not estimated.
