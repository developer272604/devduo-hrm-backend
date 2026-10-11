<?php

namespace App\Domain\Attendance\Engine;

use App\Domain\Attendance\Support\AttendanceStatus;
use App\Domain\Attendance\Support\ExceptionType;
use App\Domain\Attendance\Support\PolicyRules;
use App\Domain\Attendance\Support\Severity;
use App\Domain\Attendance\Support\TimeZones;
use Carbon\CarbonImmutable;

/**
 * INTERNAL: one execution of the calculation for one CalculationInput. Created per call by AttendanceCalculator
 * (no shared or static mutable state). The public contract and the versioning rules live in AttendanceCalculator.
 */
final class CalculationRun
{
    private const FAR = 4102444800; // 2100-01-01, "infinity" for open-ended windows

    private array $steps = [];

    private array $exceptions = [];

    private int $seq = 0;

    private array $flags = [
        'late_counts_as_occurrence' => false, 'early_counts_as_occurrence' => false, 'half_day_by_late' => false, 'auto_filled' => false,
        'no_policy_defaults' => false, 'core_hours_not_met' => false, 'session_capped' => false, 'rest_violation' => false, 'max_daily_exceeded' => false,
        'unscheduled_work' => false, 'leave_partial' => false, 'holiday_half' => false, 'late_occurrence_number' => 0, 'early_occurrence_number' => 0,
        'day_finished' => false,
    ];

    private array $rules;

    private DaySchedule $sch;

    private bool $noPolicy = false;

    private bool $working;

    /** @var array<int,Segment> */
    private array $workSegs = [];

    /** @var array<int,Segment> */
    private array $breakSegs = [];

    private ?int $S = null;

    private ?int $E = null;

    private ?int $flexFrom = null;

    private ?int $flexTo = null;

    private bool $isFlex = false;

    private int $requiredBase = 0;

    private int $required = 0;

    private int $half = 0;

    private ?int $minimum = null;

    private bool $leaveFull = false;

    private bool $finished = false;

    private bool $pendingDay = false;

    private ?int $dayEnd = null;

    private CarbonImmutable $winStart;

    private CarbonImmutable $winEnd;

    /** @var array<int,Punch> */
    private array $kept = [];

    /** @var array<int,Session> */
    private array $sessions = [];

    /** @var array<int,array{kind:string,punch:Punch}> */
    private array $anomalies = [];

    private ?Punch $openPunch = null;

    private array $onDutyIv = [];

    private array $onDutyTypes = [];

    private bool $fullOnDuty = false;

    private array $presenceIv = [];

    private int $gross = 0;

    private int $break = 0;

    private int $net = 0;

    private bool $coreMet = true;

    private ?int $firstInTs = null;

    private ?int $lastOutTs = null;

    private ?int $arrivalTs = null;

    private ?int $effectiveStart = null;

    private int $late = 0;

    private int $early = 0;

    private int $delayFromStart = 0;

    private array $ot = ['total' => 0, 'breakdown' => [], 'per_type' => []];

    private string $status = AttendanceStatus::ABSENT;

    private bool $review = false;

    private bool $missingPunch = false;

    private string $workMode = 'onsite';

    public function __construct(private readonly CalculationInput $in) {}

    public function execute(): CalculationResult
    {
        $this->prepareRules();
        $this->prepareSchedule();
        $this->normalizePunches();
        $this->checkVerification();
        $this->pairPunches();
        $this->measure();
        $this->lateAndEarly();
        $this->overtime();
        $this->decide();
        $this->compliance();
        $this->finalChecks();

        $confidence = $this->confidence();
        $this->step('result', 'status', ['status' => $this->status, 'confidence' => $confidence], ['status' => $this->status]);

        $result = $this->build($confidence, []);
        $explanation = Explainer::explain($this->in, $result->toArray() + ['steps' => $this->steps]);

        return $this->build($confidence, $explanation);
    }

    // ------------------------------------------------------------------ preparation

    private function prepareRules(): void
    {
        $this->noPolicy = $this->in->rules === [] || ($this->in->policyVersionId === null && $this->in->policyLabel === null);
        $this->rules = PolicyRules::merge($this->in->rules);
        $this->step('policy', 'policy', ['policy_version_id' => $this->in->policyVersionId, 'policy_label' => $this->in->policyLabel, 'rules_supplied' => $this->in->rules !== []],
            ['using' => $this->noPolicy ? 'defaults' : 'policy']);
        if ($this->noPolicy) {
            $this->flags['no_policy_defaults'] = true;
            $this->addException(ExceptionType::NO_POLICY, 'policy', ['detail' => 'No attendance policy applies; default rules were used.']);
        }
    }

    private function prepareSchedule(): void
    {
        $sch = $this->sch = $this->in->schedule;
        $r = $this->rules;
        $this->working = $sch->isWorkingDay();
        $tz = $sch->timezone;

        $segs = $sch->segments;
        usort($segs, fn (Segment $a, Segment $b) => $a->start->getTimestamp() <=> $b->start->getTimestamp());
        $this->workSegs = array_values(array_filter($segs, fn (Segment $s) => $s->kind === 'work'));
        $this->breakSegs = array_values(array_filter($segs, fn (Segment $s) => $s->kind === 'break'));

        $dayStart = TimeZones::localToUtc($sch->date, '00:00', $tz);
        $spillEnd = TimeZones::localToUtc($sch->date, '06:00', $tz, 1);
        $nextMidnight = TimeZones::localToUtc($sch->date, '00:00', $tz, 1);

        if ($this->working) {
            $this->S = $sch->start?->getTimestamp() ?? ($this->workSegs[0]->start->getTimestamp() ?? null);
            $this->E = $sch->end?->getTimestamp() ?? ($this->workSegs !== [] ? end($this->workSegs)->end->getTimestamp() : null);
            $this->winStart = $sch->windowStart ?? $dayStart;
            $this->winEnd = $sch->windowEnd ?? $spillEnd;
            $this->requiredBase = $sch->requiredMinutes > 0 ? $sch->requiredMinutes : (int) $r['hours']['full_day_minutes'];
            $this->required = $this->requiredBase;
            $this->minimum = $sch->minimumMinutes ?? $r['hours']['minimum_minutes'];
            $this->dayEnd = $this->E ?? $this->winEnd->getTimestamp();
            $this->isFlex = $sch->shiftKind === 'flexible';
            if ($this->isFlex) {
                $this->flexFrom = $sch->flexStartFrom?->getTimestamp() ?? $this->S;
                $this->flexTo = $sch->flexStartTo?->getTimestamp() ?? $this->flexFrom;
                $this->S ??= $this->flexFrom;
            }
            if ($sch->shiftId === null) {
                $this->addException(ExceptionType::NO_SHIFT, 'schedule.shift', ['detail' => 'The day is a working day but no shift is assigned.']);
                $this->review = true;
            }
            $this->applyHolidayPortion();
            $this->applyLeave();
        } else {
            $this->winStart = $dayStart;
            $this->winEnd = $spillEnd;
            $this->dayEnd = $nextMidnight->getTimestamp();
            if ($this->in->leave !== null) {
                $this->step('leave', 'leave', ['leave' => $this->in->leave], ['ignored' => 'The day is not a working day.']);
            }
        }

        $baseHalf = $sch->halfDayMinutes ?? (int) $r['hours']['half_day_minutes'];
        $this->half = ($this->required < $this->requiredBase || $baseHalf >= $this->required) ? intdiv($this->required, 2) : $baseHalf;
        $this->finished = $this->in->now->getTimestamp() >= $this->dayEnd;
        $this->pendingDay = ! $this->finished && (bool) $r['status']['future_days_pending'];
        $this->flags['day_finished'] = $this->finished;

        $this->step('schedule', 'schedule', [
            'date' => $sch->date, 'timezone' => $tz, 'kind' => $sch->kind, 'shift_id' => $sch->shiftId, 'shift_code' => $sch->shiftCode, 'shift_kind' => $sch->shiftKind,
            'source' => $sch->source, 'holiday' => $sch->holidayName, 'holiday_portion' => $sch->holidayPortion, 'roster_entry_id' => $sch->rosterEntryId,
            'assignment_id' => $sch->assignmentId, 'holiday_calendar_id' => $sch->holidayCalendarId, 'notes' => $sch->notes,
        ], [
            'start' => $this->iso($this->S), 'end' => $this->iso($this->E), 'required_minutes' => $this->required, 'full_day_minutes' => $this->requiredBase, 'half_day_minutes' => $this->half,
            'minimum_minutes' => $this->minimum, 'window_start' => $this->iso($this->winStart->getTimestamp()), 'window_end' => $this->iso($this->winEnd->getTimestamp()),
            'day_finished' => $this->finished, 'day_end' => $this->iso($this->dayEnd),
        ]);
    }

    private function applyHolidayPortion(): void
    {
        $portion = $this->sch->holidayPortion;
        if (! in_array($portion, ['first_half', 'second_half'], true)) {
            return;
        }
        $this->flags['holiday_half'] = true;
        $shifted = false;
        if (! $this->sch->holidayWindowAdjusted && $this->S !== null && $this->E !== null) {
            $this->shiftExpectedWindow($portion === 'first_half' ? 'first_half' : 'second_half');
            $shifted = true;
        }
        $this->step('holiday.half_day', 'schedule.holiday_portion', ['portion' => $portion, 'window_already_adjusted' => $this->sch->holidayWindowAdjusted], ['window_shifted' => $shifted, 'start' => $this->iso($this->S), 'end' => $this->iso($this->E), 'required_minutes' => $this->required]);
    }

    /** Removes the given half (first_half | second_half) of the scheduled span from the EXPECTED window. */
    private function shiftExpectedWindow(string $coveredHalf): void
    {
        if ($this->S === null || $this->E === null) {
            return;
        }
        $half = intdiv(intdiv($this->E - $this->S, 2), 60) * 60;
        if ($coveredHalf === 'first_half') {
            $this->S += $half;
            if ($this->isFlex) {
                $this->flexFrom = ($this->flexFrom ?? $this->S) + $half;
                $this->flexTo = ($this->flexTo ?? $this->S) + $half;
            }
        } else {
            $this->E -= $half;
        }
    }

    private function applyLeave(): void
    {
        $leave = $this->in->leave;
        if ($leave === null) {
            return;
        }
        $portion = (string) ($leave['portion'] ?? 'full');
        $minutes = (int) ($leave['minutes'] ?? 0);
        if ($portion === 'full' || ($minutes > 0 && $minutes >= $this->requiredBase)) {
            $this->leaveFull = true;
            $this->step('leave', 'leave.coverage', ['leave' => $leave], ['coverage' => 'full', 'required_minutes' => 0]);

            return;
        }
        if ($minutes <= 0) {
            $minutes = intdiv($this->requiredBase, 2);
        }
        $this->required = max(0, $this->requiredBase - $minutes);
        $this->flags['leave_partial'] = true;
        $this->shiftExpectedWindow($portion === 'first_half' ? 'first_half' : 'second_half');
        $this->step('leave', 'leave.coverage', ['leave' => $leave], ['coverage' => 'partial', 'portion' => $portion, 'covered_minutes' => $minutes, 'required_minutes' => $this->required, 'start' => $this->iso($this->S), 'end' => $this->iso($this->E)]);
        if ($this->required === 0) {
            $this->leaveFull = true;
        }
    }

    // ------------------------------------------------------------------ punches

    private function normalizePunches(): void
    {
        $dup = (int) $this->rules['pairing']['duplicate_window_seconds'];
        $n = PunchNormalizer::normalize($this->in->punches, $dup, $this->winStart, $this->winEnd);
        $this->kept = $n['kept'];

        $this->step('punches.normalize', 'pairing.duplicate_window_seconds', [
            'received' => count($n['sorted']), 'duplicate_window_seconds' => $dup,
            'window_start' => $this->iso($this->winStart->getTimestamp()), 'window_end' => $this->iso($this->winEnd->getTimestamp()),
        ], [
            'kept' => array_map(fn (Punch $p) => $p->id, $n['kept']), 'duplicates_dropped' => $n['duplicates'], 'outside_window' => array_map(fn (Punch $p) => $p->id, $n['outside']),
        ]);

        if ($n['outside'] !== []) {
            $this->addException(ExceptionType::UNEXPECTED_PUNCH, 'schedule.window', [
                'punch_ids' => array_map(fn (Punch $p) => $p->id, $n['outside']), 'window_start' => $this->iso($this->winStart->getTimestamp()), 'window_end' => $this->iso($this->winEnd->getTimestamp()),
            ]);
        }

        $outsideGeo = [];
        foreach ($this->kept as $p) {
            if (in_array($p->geofenceStatus, ['outside', 'outside_geofence'], true)) {
                $outsideGeo[] = $p->id;
            }
        }
        $remoteContext = $this->in->remoteApproved || $this->in->onDuty !== [];
        if ($outsideGeo !== []) {
            if ($remoteContext) {
                $this->step('geofence', 'punch_restrictions.geofence', ['outside_punch_ids' => $outsideGeo], ['ignored' => 'approved remote work or on-duty day']);
            } else {
                $this->addException(ExceptionType::OUTSIDE_GEOFENCE, 'punch_restrictions.geofence', ['punch_ids' => $outsideGeo]);
                $this->review = true;
                $this->step('geofence', 'punch_restrictions.geofence', ['outside_punch_ids' => $outsideGeo, 'geofence' => $this->rules['punch_restrictions']['geofence']], ['review_required' => true]);
            }
        }
    }

    private function checkVerification(): void
    {
        $requirePhoto = (bool) $this->rules['punch_restrictions']['require_photo'];
        $flagged = [];
        foreach ($this->kept as $p) {
            $v = $p->verificationStatus;
            if (in_array($v, ['failed', 'rejected'], true) || ($requirePhoto && in_array($v, ['unverified', 'missing', 'pending'], true))) {
                $flagged[] = $p->id;
            }
        }
        if ($flagged !== []) {
            $this->addException(ExceptionType::INTEGRITY_REVIEW, 'punch_restrictions.require_photo', ['punch_ids' => $flagged, 'detail' => 'Attendance integrity review required.']);
            $this->review = true;
            $this->step('verification', 'punch_restrictions.require_photo', ['require_photo' => $requirePhoto, 'punch_ids' => $flagged], ['review_required' => true]);
        }
    }

    private function pairPunches(): void
    {
        $pr = $this->rules['pairing'];
        $pairing = Pairer::pair($this->kept, (string) $pr['strategy'], (string) $this->rules['breaks']['mode'], (int) $pr['max_session_minutes']);
        $this->sessions = $pairing->sessions;
        $this->anomalies = $pairing->anomalies;

        $this->step('pairing', 'pairing.strategy', ['strategy' => $pr['strategy'], 'punches' => $pairing->resolved, 'max_session_minutes' => $pr['max_session_minutes']], [
            'sessions' => array_map(fn (Session $s) => $s->toArray(), $pairing->sessions),
            'anomalies' => array_map(fn ($a) => ['kind' => $a['kind'], 'punch_id' => $a['punch']->id], $pairing->anomalies), 'ignored_punch_ids' => $pairing->ignored,
        ]);

        foreach ($this->sessions as $s) {
            if ($s->capped) {
                $this->flags['session_capped'] = true;
                $this->addException(ExceptionType::SHIFT_MISMATCH, 'pairing.max_session_minutes', ['detail' => 'A session was longer than the maximum allowed and was capped.', 'in' => $s->in->id, 'out' => $s->out->id, 'cap_minutes' => $pr['max_session_minutes']]);
                $this->review = true;
            }
        }

        $this->deferOrFillMissing();
        usort($this->sessions, fn (Session $a, Session $b) => $a->in->at->getTimestamp() <=> $b->in->at->getTimestamp());
    }

    private function deferOrFillMissing(): void
    {
        $mp = $this->rules['missing_punch'];
        $fillRule = ! empty($mp['auto_fill']) ? ($mp['auto_fill_rule'] ?? null) : null;
        $remaining = [];
        foreach ($this->anomalies as $a) {
            $kind = $a['kind'];
            $punch = $a['punch'];
            if ($kind === 'missing_out' && $this->pendingDay) {
                $this->openPunch = $punch;
                $this->step('missing_punch', 'status.future_days_pending', ['in' => $punch->id], ['deferred' => 'The day is not finished; the OUT punch may still arrive.']);

                continue;
            }
            if ($fillRule === 'shift_end' && $kind === 'missing_out' && $this->E !== null && $this->E > $punch->at->getTimestamp()) {
                $virtual = new Punch('autofill:shift_end', CarbonImmutable::createFromTimestampUTC($this->E), 'OUT', 'system', 'autofill');
                $this->sessions[] = new Session($punch, $virtual, $this->E - $punch->at->getTimestamp(), true);
                $this->markFilled('missing_out', 'shift_end', $punch, $virtual);

                continue;
            }
            if ($fillRule === 'shift_start' && $kind === 'missing_in' && $this->S !== null && $this->S < $punch->at->getTimestamp()) {
                $virtual = new Punch('autofill:shift_start', CarbonImmutable::createFromTimestampUTC($this->S), 'IN', 'system', 'autofill');
                $this->sessions[] = new Session($virtual, $punch, $punch->at->getTimestamp() - $this->S, true);
                $this->markFilled('missing_in', 'shift_start', $punch, $virtual);

                continue;
            }
            $remaining[] = $a;
        }
        $this->anomalies = $remaining;

        foreach ($this->anomalies as $a) {
            $kind = $a['kind'];
            $type = match ($kind) {
                'missing_out' => ExceptionType::MISSING_OUT,
                'missing_in' => ExceptionType::MISSING_IN,
                default => ExceptionType::UNPAIRED_PUNCH,
            };
            $rule = in_array($kind, ['missing_in', 'missing_out'], true) ? 'missing_punch.auto_fill' : 'pairing.strategy';
            $this->addException($type, $rule, ['punch_id' => $a['punch']->id, 'kind' => $kind, 'detail' => 'No time was invented; a correction (regularization) is needed.']);
            if (in_array($kind, ['missing_in', 'missing_out'], true)) {
                $this->missingPunch = true;
            }
        }
        if ($this->anomalies !== []) {
            $this->review = true;
        }
    }

    private function markFilled(string $kind, string $rule, Punch $real, Punch $virtual): void
    {
        $this->flags['auto_filled'] = true;
        $this->step('missing_punch', 'missing_punch.auto_fill_rule', ['kind' => $kind, 'rule' => $rule, 'real_punch' => $real->id], ['auto_filled' => 'auto-filled by approved rule', 'time' => $virtual->at->utc()->toIso8601String()]);
        $this->addException($kind === 'missing_out' ? ExceptionType::MISSING_OUT : ExceptionType::MISSING_IN, 'missing_punch.auto_fill', ['detail' => 'Auto-filled by approved rule '.$rule.'.', 'filled_time' => $virtual->at->utc()->toIso8601String()], Severity::INFO);
    }

    // ------------------------------------------------------------------ measurement

    private function measure(): void
    {
        $sessionIv = array_map(fn (Session $s) => $s->interval(), $this->sessions);

        // gaps between consecutive sessions (candidate breaks), minus space between split-shift work segments
        $interSeg = [];
        for ($i = 0; $i < count($this->workSegs) - 1; $i++) {
            $interSeg[] = [$this->workSegs[$i]->end->getTimestamp(), $this->workSegs[$i + 1]->start->getTimestamp()];
        }
        $gapIv = [];
        $gapCount = 0;
        for ($i = 0; $i < count($this->sessions) - 1; $i++) {
            $gap = [$this->sessions[$i]->in->at->getTimestamp() + $this->sessions[$i]->seconds, $this->sessions[$i + 1]->in->at->getTimestamp()];
            if ($gap[1] <= $gap[0]) {
                continue;
            }
            $parts = Intervals::subtract([$gap], $interSeg);
            if ($parts !== []) {
                $gapCount++;
                foreach ($parts as $p) {
                    $gapIv[] = $p;
                }
            }
        }

        $od = [];
        foreach ($this->in->onDuty as $o) {
            $od[] = [max($o['start']->getTimestamp(), $this->winStart->getTimestamp()), min($o['end']->getTimestamp(), $this->winEnd->getTimestamp())];
        }
        $this->onDutyIv = Intervals::union($od);
        $this->detectOnDuty();

        $this->presenceIv = Intervals::union(array_merge($sessionIv, $gapIv, $this->onDutyIv));
        $this->gross = min(1440, intdiv(Intervals::seconds($this->presenceIv), 60));

        $paidIv = [];
        $schedUnpaid = 0;
        foreach ($this->breakSegs as $b) {
            if ($b->paid) {
                $paidIv[] = [$b->start->getTimestamp(), $b->end->getTimestamp()];
            } else {
                $schedUnpaid += $b->minutes();
            }
        }
        $brk = BreakCalculator::compute($gapIv, $paidIv, $schedUnpaid, $this->rules['breaks'], $this->gross, $this->sessions !== []);
        $this->break = min($brk['minutes'], $this->gross);
        $brk['count'] = $gapCount;
        $this->net = max(0, $this->gross - $this->break);

        $ins = array_map(fn (Session $s) => $s->in->at->getTimestamp(), $this->sessions);
        if ($this->openPunch !== null) {
            $ins[] = $this->openPunch->at->getTimestamp();
        }
        foreach ($this->anomalies as $a) {
            if ($a['kind'] === 'missing_out') {
                $ins[] = $a['punch']->at->getTimestamp();
            }
        }
        $this->firstInTs = $ins === [] ? null : min($ins);
        $this->lastOutTs = $this->sessions === [] ? null : max(array_map(fn (Session $s) => $s->out->at->getTimestamp(), $this->sessions));

        $this->step('hours', 'hours.full_day_minutes', [
            'sessions' => array_map(fn (Session $s) => $s->toArray(), $this->sessions), 'on_duty_minutes' => intdiv(Intervals::seconds($this->onDutyIv), 60),
        ], ['gross_minutes' => $this->gross, 'first_in' => $this->iso($this->firstInTs), 'last_out' => $this->iso($this->lastOutTs)]);
        $this->step('breaks', 'breaks.mode', ['mode' => $brk['mode'], 'rules' => $this->rules['breaks'], 'gross_minutes' => $this->gross, 'punched_gaps' => array_map(fn ($g) => [$this->iso($g[0]), $this->iso($g[1])], $gapIv)], [
            'break_minutes' => $this->break, 'method' => $brk['method'], 'break_count' => $gapCount, 'punched_break_minutes' => $brk['punched_minutes'], 'scheduled_unpaid_minutes' => $brk['scheduled_unpaid_minutes'], 'net_minutes' => $this->net,
        ]);

        $br = $this->rules['breaks'];
        if ($brk['mode'] !== 'none') {
            if ($br['excessive_break_minutes'] !== null && $brk['punched_minutes'] > (int) $br['excessive_break_minutes']) {
                $this->addException(ExceptionType::EXCESSIVE_BREAK, 'breaks.excessive_break_minutes', ['break_minutes' => $brk['punched_minutes'], 'allowed_minutes' => (int) $br['excessive_break_minutes']]);
            } elseif ($br['max_breaks'] !== null && $gapCount > (int) $br['max_breaks']) {
                $this->addException(ExceptionType::EXCESSIVE_BREAK, 'breaks.max_breaks', ['break_count' => $gapCount, 'allowed_breaks' => (int) $br['max_breaks']]);
            }
        }

        $this->evaluateCoreHours();
        $this->evaluateSegments($sessionIv);
    }

    private function detectOnDuty(): void
    {
        $types = [];
        foreach ($this->in->onDuty as $o) {
            $types[] = (string) ($o['type'] ?? 'on_duty');
        }
        $this->onDutyTypes = $types;
        if (! $this->working || $this->S === null || $this->E === null || $this->onDutyIv === []) {
            return;
        }
        $span = $this->E - $this->S;
        $covered = Intervals::overlap($this->onDutyIv, [[$this->S, $this->E]]);
        $this->fullOnDuty = $span > 0 && ($covered >= $span || ($this->required > 0 && intdiv($covered, 60) >= $this->required));
    }

    private function evaluateCoreHours(): void
    {
        $s = $this->sch;
        if (! $this->isFlex || $s->coreStart === null || $s->coreEnd === null || ! $this->rules['status']['present_requires_core_hours'] || $this->presenceIv === []) {
            return;
        }
        $core = [[$s->coreStart->getTimestamp(), $s->coreEnd->getTimestamp()]];
        $covered = Intervals::overlap($this->presenceIv, $core);
        $need = $core[0][1] - $core[0][0];
        $this->coreMet = $covered >= $need;
        $this->step('flexible.core_hours', 'status.present_requires_core_hours', ['core_start' => $this->iso($core[0][0]), 'core_end' => $this->iso($core[0][1])], ['covered_minutes' => intdiv($covered, 60), 'core_minutes' => intdiv($need, 60), 'met' => $this->coreMet]);
    }

    private function evaluateSegments(array $sessionIv): void
    {
        if (count($this->workSegs) < 2) {
            return;
        }
        $report = [];
        $missed = [];
        foreach ($this->workSegs as $i => $seg) {
            $iv = [[$seg->start->getTimestamp(), $seg->end->getTimestamp()]];
            $attended = intdiv(Intervals::overlap(array_merge($sessionIv, $this->onDutyIv), $iv), 60);
            $scheduled = $seg->minutes();
            $state = $attended <= 0 ? 'missed' : ($attended >= $scheduled ? 'full' : 'partial');
            if ($state === 'missed') {
                $missed[] = $i + 1;
            }
            $report[] = ['segment' => $i + 1, 'start' => $this->iso($iv[0][0]), 'end' => $this->iso($iv[0][1]), 'scheduled_minutes' => $scheduled, 'attended_minutes' => $attended, 'state' => $state];
        }
        $this->step('split.segments', 'schedule.segments', ['segment_count' => count($this->workSegs)], ['segments' => $report, 'gap_between_segments' => 'not counted as break']);
        if ($missed !== [] && $this->sessions !== [] && ! $this->pendingDay) {
            $this->addException(ExceptionType::SHIFT_MISMATCH, 'schedule.segments', ['segments_not_attended' => $missed, 'detail' => 'Part of the split shift has no attendance.']);
        }
    }

    // ------------------------------------------------------------------ late / early

    private function lateAndEarly(): void
    {
        if (! $this->working || $this->leaveFull || $this->fullOnDuty || $this->S === null) {
            return;
        }
        $lateRules = $this->rules['late'];
        $earlyRules = $this->rules['early_exit'];
        $this->arrivalTs = $this->firstInTs;
        $refStart = $this->S;

        if ($this->isFlex && $this->arrivalTs !== null) {
            $from = $this->flexFrom ?? $this->S;
            $to = $this->flexTo ?? $from;
            $this->effectiveStart = min(max($this->arrivalTs, $from), $to);
            $refStart = $to;
            $allowance = $this->flexAllowance();
            $this->E = $this->effectiveStart + ($this->required + $allowance) * 60;
            $this->step('flexible.window', 'schedule.flex_start', [
                'flex_start_from' => $this->iso($from), 'flex_start_to' => $this->iso($to), 'arrival' => $this->iso($this->arrivalTs), 'required_minutes' => $this->required, 'unpaid_break_allowance_minutes' => $allowance,
            ], ['effective_start' => $this->iso($this->effectiveStart), 'expected_end' => $this->iso($this->E), 'late_reference' => $this->iso($refStart)]);
        }

        if ($this->arrivalTs !== null) {
            $delay = $this->arrivalTs - $refStart;
            $this->delayFromStart = $delay > 0 ? intdiv($delay, 60) : 0;
            $this->late = self::lateness($delay, (int) $lateRules['grace_minutes'], (string) $lateRules['grace_mode']);
            $this->step('late', 'late.grace_minutes', [
                'arrival' => $this->iso($this->arrivalTs), 'expected_start' => $this->iso($refStart), 'grace_minutes' => (int) $lateRules['grace_minutes'], 'grace_mode' => $lateRules['grace_mode'], 'delay_minutes' => $this->delayFromStart,
                'penalty_reference' => $lateRules['penalty_reference'],
            ], ['late_minutes' => $this->late]);
            if ($this->late > 0) {
                $this->lateExceptions($lateRules);
            }
        }

        $departure = $this->lastOutTs;
        $open = $this->openPunch !== null || array_filter($this->anomalies, fn ($a) => $a['kind'] === 'missing_out') !== [];
        if ($departure !== null && $this->E !== null && ! $open) {
            if ($this->pendingDay) {
                $this->step('early_exit', 'early_exit.grace_minutes', ['departure' => $this->iso($departure)], ['deferred' => 'The day is not finished.']);
            } else {
                $this->early = self::lateness($this->E - $departure, (int) $earlyRules['grace_minutes'], (string) $earlyRules['grace_mode']);
                $this->step('early_exit', 'early_exit.grace_minutes', [
                    'departure' => $this->iso($departure), 'expected_end' => $this->iso($this->E), 'grace_minutes' => (int) $earlyRules['grace_minutes'], 'grace_mode' => $earlyRules['grace_mode'],
                    'consequence_reference' => $earlyRules['consequence_reference'],
                ], ['early_exit_minutes' => $this->early]);
                if ($this->early > 0) {
                    $this->earlyExceptions($earlyRules);
                }
            }
        }
    }

    private function flexAllowance(): int
    {
        $sched = 0;
        foreach ($this->breakSegs as $b) {
            if (! $b->paid) {
                $sched += $b->minutes();
            }
        }
        if ($sched > 0) {
            return $sched;
        }

        return $this->rules['breaks']['mode'] === 'fixed' && ! $this->rules['breaks']['paid'] ? max(0, (int) $this->rules['breaks']['fixed_minutes']) : 0;
    }

    /** beyond_grace: minutes after the grace; from_start: whole delay once the grace is exceeded; within grace (<=) => 0. */
    public static function lateness(int $delaySeconds, int $graceMinutes, string $mode): int
    {
        if ($delaySeconds <= 0) {
            return 0;
        }
        $g = $graceMinutes * 60;
        if ($mode === 'from_start') {
            return $delaySeconds > $g ? intdiv($delaySeconds, 60) : 0;
        }

        return max(0, intdiv($delaySeconds - $g, 60));
    }

    private function lateExceptions(array $rule): void
    {
        $mtd = $this->in->monthToDate;
        $occurrence = (int) ($mtd['late_count'] ?? 0) + 1;
        $allowed = $rule['allowed_occurrences_per_month'];
        $exceeded = $allowed !== null && $occurrence > (int) $allowed;
        $escalate = $rule['escalate_after_occurrences'] !== null && $occurrence >= (int) $rule['escalate_after_occurrences'];
        $monthlyExceeded = $rule['monthly_threshold_minutes'] !== null && ((int) ($mtd['late_minutes'] ?? 0) + $this->late) > (int) $rule['monthly_threshold_minutes'];
        $this->flags['late_counts_as_occurrence'] = $exceeded;
        $this->flags['late_occurrence_number'] = $occurrence;
        $severity = $allowed !== null && ! $exceeded ? Severity::INFO : Severity::WARNING;
        if ($escalate || $monthlyExceeded) {
            $severity = Severity::ACTION_REQUIRED;
        }
        $this->step('late.allowance', 'late.allowed_occurrences_per_month', [
            'occurrence_number' => $occurrence, 'allowed_occurrences' => $allowed, 'month_to_date' => $mtd, 'escalate_after_occurrences' => $rule['escalate_after_occurrences'], 'monthly_threshold_minutes' => $rule['monthly_threshold_minutes'],
        ], ['exceeded_allowance' => $exceeded, 'escalated' => $escalate, 'monthly_threshold_exceeded' => $monthlyExceeded, 'severity' => $severity, 'penalty_reference' => $rule['penalty_reference']]);
        $this->addException(ExceptionType::LATE_ARRIVAL, 'late.grace_minutes', ['late_minutes' => $this->late, 'grace_minutes' => (int) $rule['grace_minutes'], 'grace_mode' => $rule['grace_mode'], 'occurrence_number' => $occurrence, 'penalty_reference' => $rule['penalty_reference']], $severity);

        $halfAfter = $rule['half_day_after_minutes'];
        if ($halfAfter !== null && $this->delayFromStart > (int) $halfAfter) {
            $this->flags['half_day_by_late'] = true;
        }
    }

    private function earlyExceptions(array $rule): void
    {
        $occurrence = (int) ($this->in->monthToDate['early_count'] ?? 0) + 1;
        $allowed = $rule['allowed_occurrences_per_month'];
        $exceeded = $allowed !== null && $occurrence > (int) $allowed;
        $this->flags['early_counts_as_occurrence'] = $exceeded;
        $this->flags['early_occurrence_number'] = $occurrence;
        $severity = $allowed !== null && ! $exceeded ? Severity::INFO : Severity::WARNING;
        if ($rule['threshold_minutes'] !== null && $this->early > (int) $rule['threshold_minutes']) {
            $severity = Severity::ACTION_REQUIRED;
        }
        $this->step('early_exit.allowance', 'early_exit.allowed_occurrences_per_month', ['occurrence_number' => $occurrence, 'allowed_occurrences' => $allowed, 'threshold_minutes' => $rule['threshold_minutes']], ['exceeded_allowance' => $exceeded, 'severity' => $severity, 'consequence_reference' => $rule['consequence_reference']]);
        $this->addException(ExceptionType::EARLY_DEPARTURE, 'early_exit.grace_minutes', ['early_exit_minutes' => $this->early, 'grace_minutes' => (int) $rule['grace_minutes'], 'grace_mode' => $rule['grace_mode'], 'occurrence_number' => $occurrence, 'consequence_reference' => $rule['consequence_reference']], $severity);
    }

    // ------------------------------------------------------------------ overtime

    private function overtime(): void
    {
        $o = $this->rules['overtime'];
        $raw = array_fill_keys(OvertimeCalculator::TYPES, 0);
        $sessionIv = array_map(fn (Session $s) => $s->interval(), $this->sessions);

        $reason = null;
        if (empty($o['enabled'])) {
            $reason = 'overtime.enabled is off';
        } elseif (! $this->in->employeeTypeOvertimeEligible) {
            $reason = 'employee type is not overtime eligible';
        } elseif (! $this->sch->overtimeEligible) {
            $reason = 'the shift/schedule is not overtime eligible';
        }

        if ($this->working) {
            if (! $this->leaveFull && ! $this->fullOnDuty && $this->S !== null && $this->E !== null) {
                $start = $this->isFlex ? ($this->flexFrom ?? $this->S) : $this->S;
                $raw['pre_shift'] = intdiv(Intervals::overlap($sessionIv, [[0, $start]]), 60);
                $raw['post_shift'] = intdiv(Intervals::overlap($sessionIv, [[$this->E, self::FAR]]), 60);
            }
        } elseif ($this->sessions !== [] && $this->nonWorkingWorkAllowed()) {
            $type = match ($this->sch->kind) {
                'weekly_off' => 'weekly_off', 'holiday' => 'holiday', default => 'rest_day',
            };
            $raw[$type] = $this->net;
        }

        $res = $reason === null ? OvertimeCalculator::compute($raw, $o) : ['total' => 0, 'breakdown' => array_fill_keys(OvertimeCalculator::TYPES, 0), 'per_type' => []];
        $this->ot = $res;
        $this->step('overtime', 'overtime.enabled', ['raw_minutes' => $raw, 'rules' => $o, 'approved_minutes' => $this->in->approvedOvertimeMinutes], [
            'overtime_minutes' => $res['total'], 'breakdown' => $res['breakdown'], 'per_type' => $res['per_type'], 'ineligible_reason' => $reason,
        ]);
        if ($res['total'] > 0 && ! empty($o['requires_approval'])) {
            $this->addException(ExceptionType::OVERTIME, 'overtime.requires_approval', ['overtime_minutes' => $res['total'], 'approved_minutes' => $this->in->approvedOvertimeMinutes, 'breakdown' => $res['breakdown']]);
        }
    }

    private function nonWorkingWorkAllowed(): bool
    {
        return match ($this->sch->kind) {
            'weekly_off' => (bool) $this->rules['weekend']['work_allowed'],
            'holiday' => (bool) $this->rules['holiday']['work_allowed'],
            default => true,
        };
    }

    // ------------------------------------------------------------------ status

    private function decide(): void
    {
        $reason = '';
        if ($this->working) {
            $this->decideWorking($reason);
        } else {
            $this->decideNonWorking($reason);
        }
        $this->workMode = $this->determineWorkMode();
    }

    private function decideNonWorking(string &$reason): void
    {
        $kind = $this->sch->kind;
        $kindStatus = in_array($kind, [AttendanceStatus::WEEKLY_OFF, AttendanceStatus::HOLIDAY, AttendanceStatus::NOT_SCHEDULED], true) ? $kind : AttendanceStatus::NOT_SCHEDULED;
        $hasPunches = $this->kept !== [];
        $odMinutes = intdiv(Intervals::seconds($this->onDutyIv), 60);

        if ($hasPunches && $this->anomalies !== []) {
            $this->status = $this->classifyAnomalies();
            $reason = 'unresolved punches on a non-working day';
        } elseif ($this->openPunch !== null) {
            $this->status = AttendanceStatus::PENDING;
            $reason = 'open session while the day is not finished';
        } elseif ($this->net > 0 || $odMinutes > 0) {
            if ($this->nonWorkingWorkAllowed()) {
                $this->flags['unscheduled_work'] = true;
                $this->addException(ExceptionType::UNSCHEDULED_WORK, 'schedule.kind', ['day_kind' => $kind, 'net_minutes' => $this->net, 'holiday' => $this->sch->holidayName]);
                $this->status = $this->sessions === [] ? $this->dutyStatus() : AttendanceStatus::PRESENT;
                $reason = 'work on a non-working day';
            } else {
                $this->status = $kindStatus;
                $this->addException(ExceptionType::UNSCHEDULED_WORK, 'schedule.kind', ['day_kind' => $kind, 'detail' => 'Work on this day is not allowed by the policy; the day keeps its non-working status.'], Severity::WARNING);
                $reason = 'work not allowed by policy';
            }
        } else {
            $this->status = $kindStatus;
            $reason = 'no work expected and none recorded';
        }
        $this->step('status', 'schedule.kind', ['day_kind' => $kind, 'net_minutes' => $this->net, 'has_punches' => $hasPunches], ['status' => $this->status, 'reason' => $reason]);
    }

    private function decideWorking(string &$reason): void
    {
        $hasPunches = $this->kept !== [];
        $hasWork = $hasPunches || $this->onDutyIv !== [];
        $why = '';
        if ($this->leaveFull) {
            $this->status = AttendanceStatus::LEAVE;
            $why = 'leave covers the full day';
        } elseif ($this->fullOnDuty) {
            $this->status = $this->dutyStatus();
            $why = 'approved on-duty/remote interval covers the full shift';
        } elseif ($this->anomalies !== []) {
            $this->status = $this->classifyAnomalies();
            $why = 'unresolved pairing problem';
        } elseif (! $hasWork) {
            $this->status = $this->pendingDay ? AttendanceStatus::PENDING : AttendanceStatus::ABSENT;
            $why = $this->pendingDay ? 'no punches yet and the day is not finished' : 'no punches on a working day';
        } elseif ($this->openPunch !== null) {
            $this->status = AttendanceStatus::PENDING;
            $why = 'open session while the day is not finished';
        } else {
            $this->status = $this->statusFromHours($why);
        }
        $reason = $why;
        $this->step('status', 'hours.full_day_minutes', [
            'net_minutes' => $this->net, 'required_minutes' => $this->required, 'half_day_minutes' => $this->half, 'minimum_minutes' => $this->minimum, 'late_minutes' => $this->late,
            'has_punches' => $hasPunches, 'day_finished' => $this->finished,
        ], ['status' => $this->status, 'reason' => $why]);
    }

    private function statusFromHours(string &$why): string
    {
        $net = $this->net;
        $remote = $this->in->remoteApproved;
        if ($this->minimum !== null && $net < $this->minimum) {
            $why = 'net work is below the minimum hours floor';
            $this->insufficient();

            return AttendanceStatus::ABSENT;
        }
        if ($net >= $this->required) {
            $status = $remote ? AttendanceStatus::REMOTE_WORK : AttendanceStatus::PRESENT;
            $why = 'net work meets the required full-day minutes';
            if (! $this->pendingDay && ! $this->coreMet) {
                $this->flags['core_hours_not_met'] = true;
                $this->addException(ExceptionType::SHIFT_MISMATCH, 'status.present_requires_core_hours', ['detail' => 'Core hours were not fully covered.']);
                $why = 'core hours were not covered';

                return AttendanceStatus::HALF_DAY;
            }
            if ($this->flags['half_day_by_late']) {
                $why = 'arrival was later than the half-day-after-late limit';

                return AttendanceStatus::HALF_DAY;
            }

            return $status;
        }
        if ($this->pendingDay) {
            $why = 'net work is below the requirement but the day is not finished';

            return AttendanceStatus::PENDING;
        }
        $this->insufficient();
        if ($net >= $this->half) {
            $why = 'net work meets the half-day minutes but not the full day';

            return AttendanceStatus::HALF_DAY;
        }
        $why = 'net work is below the half-day minutes';

        return AttendanceStatus::ABSENT;
    }

    private function insufficient(): void
    {
        $this->addException(ExceptionType::INSUFFICIENT_HOURS, 'hours.full_day_minutes', ['net_minutes' => $this->net, 'required_minutes' => $this->required, 'half_day_minutes' => $this->half]);
    }

    private function classifyAnomalies(): string
    {
        $single = count($this->anomalies) === 1 && in_array($this->anomalies[0]['kind'], ['missing_in', 'missing_out'], true);
        if (! $single) {
            return AttendanceStatus::INCOMPLETE;
        }
        $mark = (string) ($this->rules['missing_punch']['mark_status'] ?? 'missing_punch');

        return $mark === 'incomplete' ? AttendanceStatus::INCOMPLETE : AttendanceStatus::MISSING_PUNCH;
    }

    private function dutyStatus(): string
    {
        $types = array_unique($this->onDutyTypes);

        return $types === ['remote_work'] ? AttendanceStatus::REMOTE_WORK : AttendanceStatus::ON_DUTY;
    }

    private function determineWorkMode(): string
    {
        if ($this->status === AttendanceStatus::REMOTE_WORK || ($this->in->remoteApproved && $this->kept !== [])) {
            return 'remote';
        }
        if ($this->status === AttendanceStatus::ON_DUTY) {
            return 'on_duty';
        }

        return 'onsite';
    }

    // ------------------------------------------------------------------ compliance and confidence

    private function compliance(): void
    {
        $c = $this->in->compliance;
        $min = $c['min_rest_minutes'] ?? null;
        if ($min !== null && $this->in->previousDayEndUtc !== null && $this->firstInTs !== null) {
            $prev = CarbonImmutable::parse($this->in->previousDayEndUtc)->getTimestamp();
            $rest = intdiv($this->firstInTs - $prev, 60);
            $violated = $rest < (int) $min;
            $this->step('compliance.rest', 'compliance.min_rest_minutes', ['previous_end' => $this->iso($prev), 'first_in' => $this->iso($this->firstInTs), 'min_rest_minutes' => (int) $min], ['rest_minutes' => $rest, 'violation' => $violated]);
            if ($violated) {
                $this->flags['rest_violation'] = true;
                $this->addException(ExceptionType::REST_VIOLATION, 'compliance.min_rest_minutes', ['rest_minutes' => $rest, 'min_rest_minutes' => (int) $min, 'check' => 'min_rest_minutes'], null, 'Rest period below the configured minimum');
            }
        }
        $max = $c['max_daily_minutes'] ?? null;
        if ($max !== null && $this->gross > (int) $max) {
            $this->flags['max_daily_exceeded'] = true;
            $this->step('compliance.max_daily', 'compliance.max_daily_minutes', ['gross_minutes' => $this->gross, 'max_daily_minutes' => (int) $max], ['violation' => true]);
            $this->addException(ExceptionType::REST_VIOLATION, 'compliance.max_daily_minutes', ['gross_minutes' => $this->gross, 'max_daily_minutes' => (int) $max, 'check' => 'max_daily_minutes'], null, 'Daily working time above the configured maximum');
        }
    }

    private function finalChecks(): void
    {
        if ($this->noPolicy) {
            $this->review = true;
        }
    }

    private function confidence(): string
    {
        if ($this->review) {
            return 'review_required';
        }
        if ($this->status === AttendanceStatus::PENDING || ($this->pendingDay && $this->kept !== [] && $this->status !== AttendanceStatus::LEAVE)) {
            return 'pending';
        }

        return 'confirmed';
    }

    // ------------------------------------------------------------------ output

    private function build(string $confidence, array $explanation): CalculationResult
    {
        $pairs = array_map(fn (Session $s) => $s->toArray(), $this->sessions);
        if ($this->openPunch !== null) {
            $pairs[] = ['in' => $this->openPunch->at->utc()->toIso8601String(), 'out' => null, 'minutes' => 0];
        } else {
            foreach ($this->anomalies as $a) {
                if ($a['kind'] === 'missing_out') {
                    $pairs[] = ['in' => $a['punch']->at->utc()->toIso8601String(), 'out' => null, 'minutes' => 0];
                }
            }
        }
        usort($pairs, fn ($a, $b) => strcmp($a['in'], $b['in']));

        $used = [];
        foreach ($this->sessions as $s) {
            foreach ([$s->in, $s->out] as $p) {
                if ($p->origin !== 'autofill') {
                    $used[$p->id] = true;
                }
            }
        }
        if ($this->openPunch !== null) {
            $used[$this->openPunch->id] = true;
        }
        $ids = array_keys($used);
        sort($ids, SORT_STRING);

        $lastOut = $this->lastOutTs;
        foreach ($this->anomalies as $a) {
            if ($a['kind'] === 'missing_in') {
                $lastOut = max($lastOut ?? 0, $a['punch']->at->getTimestamp());
            }
        }

        return new CalculationResult(
            status: $this->status,
            confidence: $confidence,
            firstIn: $this->firstInTs === null ? null : CarbonImmutable::createFromTimestampUTC($this->firstInTs),
            lastOut: $lastOut === null ? null : CarbonImmutable::createFromTimestampUTC($lastOut),
            requiredMinutes: $this->working && ! $this->leaveFull ? $this->required : 0,
            grossMinutes: $this->gross,
            breakMinutes: $this->break,
            netMinutes: $this->net,
            lateMinutes: $this->late,
            earlyExitMinutes: $this->early,
            overtimeMinutes: $this->ot['total'],
            overtimeBreakdown: $this->ot['breakdown'] ?: array_fill_keys(OvertimeCalculator::TYPES, 0),
            missingPunch: $this->missingPunch,
            workMode: $this->workMode,
            exceptions: $this->sortedExceptions(),
            pairs: $pairs,
            steps: $this->steps,
            explanation: $explanation,
            punchIdsUsed: $ids,
            flags: $this->flags,
        );
    }

    private function sortedExceptions(): array
    {
        $list = $this->exceptions;
        usort($list, fn ($a, $b) => (Severity::ORDER[$b['severity']] ?? 0) <=> (Severity::ORDER[$a['severity']] ?? 0) ?: strcmp($a['type'], $b['type']) ?: strcmp($a['rule'], $b['rule']) ?: $a['_seq'] <=> $b['_seq']);

        return array_map(function ($e) {
            unset($e['_seq']);

            return $e;
        }, $list);
    }

    private function addException(string $type, string $rule, array $details, ?string $severity = null, ?string $title = null): void
    {
        $this->exceptions[] = [
            'type' => $type, 'severity' => $severity ?? ExceptionType::severity($type), 'rule' => $rule, 'details' => $details, 'title' => $title ?? ExceptionType::title($type), '_seq' => $this->seq++,
        ];
    }

    private function step(string $step, string $rule, array $inputs, array $outcome): void
    {
        $this->steps[] = ['step' => $step, 'rule' => $rule, 'inputs' => $inputs, 'outcome' => $outcome];
    }

    private function iso(?int $ts): ?string
    {
        return $ts === null ? null : CarbonImmutable::createFromTimestampUTC($ts)->toIso8601String();
    }
}
