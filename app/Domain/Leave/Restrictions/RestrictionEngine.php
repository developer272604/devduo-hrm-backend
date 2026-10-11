<?php

namespace App\Domain\Leave\Restrictions;

use App\Domain\Attendance\Scheduling\AssignmentResolver;
use App\Domain\Attendance\Scheduling\OrgContext;
use App\Domain\Leave\Contracts\Dto\DurationResult;
use App\Domain\Leave\Duration\RuleSet;
use App\Domain\Leave\Models\LeaveDocumentRule;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveRestriction;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Leave\Support\Units;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Finds everything that conflicts with a leave request or merely deserves a heads-up, and applies the policy's chosen
 * response: warn / block / escalate. Pure read: it never writes (the request service persists the result in leave_conflicts).
 *
 * Statutory entitlements are never blindly denied: a "block" coming from a staffing-type restriction or an eligibility rule is
 * downgraded to "escalate" (HR review) when the leave type is statutory and the restriction exempts it (or the tenant setting
 * restrictions.never_block_statutory is on). Factual double-booking (overlap) is always a block.
 *
 * Conflict shape: {kind, severity: info|warning|escalate|block, message, detail, restriction_id?}
 */
final class RestrictionEngine
{
    private const SEVERITY = ['warn' => 'warning', 'block' => 'block', 'escalate' => 'escalate'];

    private const SOFT_KINDS = ['blackout', 'max_concurrent', 'min_staffing', 'notice', 'consecutive', 'eligibility', 'backdate', 'future_limit'];

    public function __construct(private TeamScope $teams) {}

    /**
     * @param  int  $documents  number of documents already attached (satisfies document rules)
     * @return array<int,array{kind:string,severity:string,message:string,detail:array<string,mixed>,restriction_id?:int}>
     */
    public function evaluate(Employee $employee, LeaveType $type, DurationResult $duration, ?RuleSet $rules = null, ?int $ignoreRequestId = null, ?int $delegateEmployeeId = null, int $documents = 0): array
    {
        $rules ??= RuleSet::load($employee->id, $type->id, $duration->segments[0]->date ?? TenantClock::today());
        $segments = array_values(array_filter($duration->segments, fn ($s) => $s->charged));
        $dates = array_values(array_unique(array_map(fn ($s) => $s->date, $segments)));
        sort($dates);
        $out = [];
        if (! $dates) {
            return $out;
        }
        $statutory = $this->isStatutory($type);

        // A policy's max_consecutive limit applies to the contiguous chain of this employee's pending/approved requests for the same type.
        // DurationEngine already handles a single request that exceeds the limit; this adds adjacent requests to that span.
        $maxConsecutive = $rules->get('max_consecutive');
        $requestSpan = (int) CarbonImmutable::parse($dates[0])->diffInDays(CarbonImmutable::parse(end($dates))) + 1;
        if ($maxConsecutive !== null && $requestSpan <= (int) $maxConsecutive) {
            [$chainStart, $chainEnd, $adjacentCount] = $this->consecutiveChain($employee->id, $type->id, $dates[0], end($dates), $ignoreRequestId);
            $chainSpan = (int) CarbonImmutable::parse($chainStart)->diffInDays(CarbonImmutable::parse($chainEnd)) + 1;
            if ($chainSpan > (int) $maxConsecutive) {
                $conflict = ['kind' => 'max_consecutive', 'severity' => 'block',
                    'message' => "{$type->name} cannot run for more than $maxConsecutive consecutive days (this request would extend the existing leave chain to $chainSpan).",
                    'detail' => ['max' => (int) $maxConsecutive, 'span' => $chainSpan, 'request_span' => $requestSpan, 'chain_start' => $chainStart, 'chain_end' => $chainEnd, 'adjacent_requests' => $adjacentCount]];
                $out[] = $this->soften($conflict, $statutory, true);
            }
        }

        // ---- overlap with the employee's own pending / approved leave (a fact, never downgraded) -----
        foreach ($this->overlaps($employee->id, $segments, $ignoreRequestId) as $o) {
            $out[] = ['kind' => 'overlap', 'severity' => 'block', 'message' => "You already have leave on {$o['date']} ({$o['status']}).", 'detail' => $o];
        }
        // ---- attendance already recorded / approved on-duty / roster ---------------------------------
        $today = TenantClock::today($employee->timezone ?: null);
        $punched = DB::table('attendance_days')->where('tenant_id', app(TenantContext::class)->id)->where('employee_id', $employee->id)->whereIn('attendance_date', $dates)->where('attendance_date', '<=', $today)
            ->whereNotNull('first_in')->pluck('attendance_date')->all();
        foreach ($punched as $date) {
            $out[] = ['kind' => 'attendance', 'severity' => 'warning', 'message' => "Attendance (a punch) is already recorded on $date; approval will reconcile that day with the leave.", 'detail' => ['date' => $date]];
        }
        foreach (DB::table('on_duty_requests')->where('tenant_id', app(TenantContext::class)->id)->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
            ->where('start_date', '<=', end($dates))->where('end_date', '>=', $dates[0])->get(['start_date', 'end_date', 'status', 'type']) as $od) {
            $out[] = ['kind' => 'on_duty', 'severity' => $od->status === 'approved' ? 'warning' : 'info', 'message' => "An {$od->status} on-duty request ({$od->type}) overlaps these dates.", 'detail' => ['start' => $od->start_date, 'end' => $od->end_date, 'status' => $od->status]];
        }
        foreach ($segments as $s) {
            if (($s->detail['source'] ?? null) === 'roster') {
                $out[] = ['kind' => 'roster', 'severity' => 'info', 'message' => "You are on the published roster on {$s->date}; your manager may need to arrange cover.", 'detail' => ['date' => $s->date, 'shift' => $s->detail['shift'] ?? null]];
            }
        }
        // ---- delegate availability ---------------------------------------------------------------------
        if ($delegateEmployeeId) {
            foreach ($dates as $date) {
                if (($this->teams->away([$delegateEmployeeId], $date)[$delegateEmployeeId] ?? 0) >= 1.0) {
                    $out[] = ['kind' => 'availability', 'severity' => 'warning', 'message' => "Your chosen delegate is also away on $date.", 'detail' => ['date' => $date]];
                    break;
                }
            }
        }
        // ---- eligibility (policy data) ------------------------------------------------------------------
        foreach ($this->eligibility($employee, $type, $rules, $dates[0]) as $reason) {
            $out[] = $this->soften(['kind' => 'eligibility', 'severity' => 'block', 'message' => $reason, 'detail' => []], $statutory, true);
        }
        // ---- configured restrictions (blackout, staffing, concurrency, ...) --------------------------------
        foreach ($this->restrictions($employee, $type, $dates, $ignoreRequestId) as $hit) {
            $out[] = $hit['conflict'] + ['restriction_id' => $hit['id']];
        }
        // ---- required documents ---------------------------------------------------------------------------
        foreach ($this->documentRules($type, $rules, $duration->chargedUnits) as $rule) {
            if ($documents > 0) {
                continue;
            }
            $later = $rule->submit_within_days !== null;
            $out[] = $this->soften(['kind' => 'document', 'severity' => $later ? 'warning' : 'block', 'detail' => ['document_kind' => $rule->document_kind, 'submit_within_days' => $rule->submit_within_days, 'sensitive' => (bool) $rule->is_sensitive],
                'message' => ucfirst(str_replace('_', ' ', $rule->document_kind)).' is required for '.rtrim(rtrim(Units::normalize($duration->chargedUnits), '0'), '.').' or more'.($later ? " (to be submitted within {$rule->submit_within_days} day(s))." : ' before you can submit.')], $statutory, true);
        }

        return $out;
    }

    /** @return array{blocked:bool,escalate:bool,warnings:int} */
    public static function summary(array $conflicts): array
    {
        $severities = array_column($conflicts, 'severity');

        return ['blocked' => in_array('block', $severities, true), 'escalate' => in_array('escalate', $severities, true), 'warnings' => count(array_filter($severities, fn ($s) => $s === 'warning'))];
    }

    private function isStatutory(LeaveType $type): bool
    {
        return $type->is_statutory || $type->category === 'statutory';
    }

    /** Statutory leave is escalated, never blindly denied. */
    private function soften(array $conflict, bool $statutory, bool $exempt): array
    {
        if ($conflict['severity'] === 'block' && $statutory && $exempt && LeaveSettings::get('restrictions.never_block_statutory', true)) {
            $conflict['severity'] = 'escalate';
            $conflict['message'] .= ' This is statutory leave, so it is sent for HR review instead of being refused.';
        }

        return $conflict;
    }

    /** @return array<int,array<string,mixed>> */
    private function overlaps(int $employeeId, array $segments, ?int $ignoreRequestId): array
    {
        $hits = [];
        $existing = DB::table('leave_request_segments as s')->join('leave_requests as r', 'r.id', '=', 's.request_id')->where('s.tenant_id', app(TenantContext::class)->id)
            ->where('s.employee_id', $employeeId)->whereIn('s.leave_date', array_map(fn ($s) => $s->date, $segments))->where('s.status', 'active')->where('s.charged', true)
            ->whereIn('r.status', ['pending', 'approved', 'partially_cancelled'])->when($ignoreRequestId, fn ($q) => $q->where('r.id', '!=', $ignoreRequestId))
            ->get(['s.leave_date', 's.portion', 's.starts_at', 's.ends_at', 'r.status', 'r.uuid']);
        foreach ($segments as $new) {
            foreach ($existing->where('leave_date', $new->date) as $old) {
                $halves = ['first_half', 'second_half'];
                if (in_array($new->portion, $halves, true) && in_array($old->portion, $halves, true) && $new->portion !== $old->portion) {
                    continue;
                }
                if ($new->portion === 'hours' && $old->portion === 'hours' && $new->startsAt && $old->starts_at && ($new->endsAt <= $old->starts_at || $new->startsAt >= $old->ends_at)) {
                    continue;
                }
                $hits[] = ['date' => $new->date, 'status' => $old->status, 'request' => $old->uuid, 'portion' => $old->portion];
            }
        }

        return $hits;
    }

    /** @return array{0:string,1:string,2:int} contiguous date range and number of related existing requests */
    private function consecutiveChain(int $employeeId, int $leaveTypeId, string $from, string $to, ?int $ignoreRequestId): array
    {
        $start = $from;
        $end = $to;
        $known = [];
        $connected = [];
        $changed = true;

        while ($changed) {
            $changed = false;
            $rows = LeaveRequest::query()->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)
                ->whereIn('status', ['pending', 'approved', 'partially_cancelled'])
                ->when($ignoreRequestId, fn ($q) => $q->whereKeyNot($ignoreRequestId))
                ->where('start_date', '<=', CarbonImmutable::parse($end)->addDay()->toDateString())
                ->where('end_date', '>=', CarbonImmutable::parse($start)->subDay()->toDateString())
                ->with(['segments' => fn ($q) => $q->where('status', 'active')])
                ->get();

            foreach ($rows as $request) {
                if (isset($known[$request->id])) {
                    continue;
                }
                $known[$request->id] = $request->status === 'partially_cancelled'
                    ? $this->activeDateRuns($request->segments->pluck('leave_date')->map(fn ($date) => substr((string) $date, 0, 10))->all())
                    : [[substr((string) $request->start_date, 0, 10), substr((string) $request->end_date, 0, 10)]];
            }

            foreach ($known as $requestId => $ranges) {
                foreach ($ranges as [$requestStart, $requestEnd]) {
                    if ($requestStart > CarbonImmutable::parse($end)->addDay()->toDateString() || $requestEnd < CarbonImmutable::parse($start)->subDay()->toDateString()) {
                        continue;
                    }
                    $connected[$requestId] = true;
                    $nextStart = min($start, $requestStart);
                    $nextEnd = max($end, $requestEnd);
                    if ($nextStart !== $start || $nextEnd !== $end) {
                        $start = $nextStart;
                        $end = $nextEnd;
                        $changed = true;
                    }
                }
            }
        }

        return [$start, $end, count($connected)];
    }

    /** @param array<int,string> $dates @return array<int,array{0:string,1:string}> */
    private function activeDateRuns(array $dates): array
    {
        $dates = array_values(array_unique($dates));
        sort($dates);
        $ranges = [];
        foreach ($dates as $date) {
            $last = array_key_last($ranges);
            if ($last !== null && CarbonImmutable::parse($ranges[$last][1])->addDay()->toDateString() === $date) {
                $ranges[$last][1] = $date;
            } else {
                $ranges[] = [$date, $date];
            }
        }

        return $ranges;
    }

    /** @return array<int,string> reasons the employee is not eligible (empty = eligible) */
    private function eligibility(Employee $employee, LeaveType $type, RuleSet $rules, string $date): array
    {
        $e = $rules->rules['eligibility'] ?? [];
        $reasons = [];
        $joined = (string) $employee->getRawOriginal('joined_on');
        $tenure = $joined ? (int) CarbonImmutable::parse(substr($joined, 0, 10))->diffInDays(CarbonImmutable::parse($date), false) : null;
        foreach (['probation_days' => 'is not available until %d days after joining (probation)', 'min_tenure_days' => 'needs at least %d days of service'] as $key => $text) {
            if (! empty($e[$key]) && $tenure !== null && $tenure < (int) $e[$key]) {
                $reasons[] = $type->name.' '.sprintf($text, (int) $e[$key]).' (you have '.max(0, $tenure).').';
            }
        }
        $assignment = $employee->assignmentOn($date);
        if (! empty($e['employee_types']) && $assignment && ! in_array((int) $assignment->employee_type_id, array_map('intval', (array) $e['employee_types']), true)) {
            $reasons[] = $type->name.' is not available for your employee type.';
        }
        if (! empty($e['legal_entities']) && $assignment && ! in_array((int) $assignment->legal_entity_id, array_map('intval', (array) $e['legal_entities']), true)) {
            $reasons[] = $type->name.' is not available for your legal entity.';
        }
        if (! empty($e['countries'])) {
            $ctx = OrgContext::load([$employee->id], $date, $date)->at($employee->id, $date);
            if (! in_array(strtoupper((string) $ctx['country']), array_map('strtoupper', (array) $e['countries']), true)) {
                $reasons[] = $type->name.' is not available in your country.';
            }
        }

        return $reasons;
    }

    /** @return iterable<int,LeaveDocumentRule> */
    private function documentRules(LeaveType $type, RuleSet $rules, string $charged): iterable
    {
        return LeaveDocumentRule::query()->where('leave_type_id', $type->id)->where('active', true)
            ->where(fn ($q) => $q->whereNull('policy_version_id')->when($rules->versionId, fn ($qq) => $qq->orWhere('policy_version_id', $rules->versionId)))
            ->where('mandatory', true)->get()->filter(fn ($r) => Units::cmp($charged, $r->getRawOriginal('required_after_units')) >= 0 && Units::cmp($r->getRawOriginal('required_after_units'), '0') >= 0);
    }

    /** @return array<int,array{id:int,conflict:array<string,mixed>}> */
    private function restrictions(Employee $employee, LeaveType $type, array $dates, ?int $ignoreRequestId = null): array
    {
        $from = $dates[0];
        $to = end($dates);
        $rows = LeaveRestriction::query()->where('active', true)->where(fn ($q) => $q->whereNull('leave_type_id')->orWhere('leave_type_id', $type->id))
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $to))->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $from))->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $org = OrgContext::load([$employee->id], $from, $to);
        $statutory = $this->isStatutory($type);
        $hits = [];
        foreach ($rows as $r) {
            $applies = AssignmentResolver::matches(['target_type' => $r->target_type, 'target_id' => $r->target_id, 'effective_from' => '1900-01-01', 'effective_to' => null], $org->at($employee->id, $from), $from);
            if (! $applies) {
                continue;
            }
            $params = $r->params ?? [];
            $action = self::SEVERITY[$r->action] ?? 'warning';
            $base = ['kind' => $r->kind === 'blackout' ? 'blackout' : (in_array($r->kind, ['max_concurrent', 'min_staffing'], true) ? 'staffing' : $r->kind), 'severity' => $action, 'detail' => ['restriction' => $r->uuid, 'name' => $r->name]];
            $inRange = array_values(array_filter($dates, fn ($d) => (! $r->start_date || $d >= $r->start_date) && (! $r->end_date || $d <= $r->end_date)));
            $messages = [];
            switch ($r->kind) {
                case 'blackout':
                    if ($inRange) {
                        $messages[] = "{$r->name}: leave is restricted on {$inRange[0]}".(count($inRange) > 1 ? ' and '.(count($inRange) - 1).' more day(s)' : '').($params['reason'] ?? null ? ' ('.$params['reason'].')' : '').'.';
                    }
                    break;
                case 'max_concurrent':
                    $dimension = $params['group_by'] ?? ($r->target_type === 'tenant' ? 'department' : $r->target_type);
                    foreach ($inRange as $date) {
                        $peers = array_values(array_diff($this->teams->peers($employee->id, $dimension, $date), [$employee->id]));
                        $away = array_sum($this->teams->away($peers, $date));
                        if ($away + 1 > (float) ($params['max'] ?? 1)) {
                            $messages[] = "{$r->name}: at most ".($params['max'] ?? 1)." people in the {$dimension} may be away at once; on $date ".rtrim(rtrim(number_format($away, 2), '0'), '.').' already are.';
                            break;
                        }
                    }
                    break;
                case 'min_staffing':
                    $dimension = $params['group_by'] ?? ($r->target_type === 'tenant' ? 'department' : $r->target_type);
                    foreach ($inRange as $date) {
                        $peers = $this->teams->peers($employee->id, $dimension, $date);
                        $away = array_sum($this->teams->away(array_values(array_diff($peers, [$employee->id])), $date)) + 1;
                        $available = max(0, count($peers) - $away);
                        $need = isset($params['min_percent']) ? (float) $params['min_percent'] * count($peers) / 100 : (float) ($params['min_available'] ?? 1);
                        if ($peers && $available < $need) {
                            $messages[] = "{$r->name}: only ".rtrim(rtrim(number_format($available, 2), '0'), '.').' of '.count($peers)." people would be available on $date (minimum ".rtrim(rtrim(number_format($need, 2), '0'), '.').').';
                            break;
                        }
                    }
                    break;
                case 'notice':
                    $ahead = (int) CarbonImmutable::parse(TenantClock::today($employee->timezone ?: null))->diffInDays(CarbonImmutable::parse($from), false);
                    if ($ahead >= 0 && $ahead < (int) ($params['days'] ?? 0)) {
                        $messages[] = "{$r->name}: {$params['days']} day(s) notice is expected (this starts in $ahead).";
                    }
                    break;
                case 'consecutive':
                    [$chainStart, $chainEnd, $related] = $this->consecutiveChain($employee->id, $type->id, $from, $to, $ignoreRequestId);
                    $span = (int) CarbonImmutable::parse($chainStart)->diffInDays(CarbonImmutable::parse($chainEnd)) + 1;
                    if ($span > (int) ($params['max_days'] ?? PHP_INT_MAX)) {
                        $messages[] = "{$r->name}: more than {$params['max_days']} consecutive days needs a review (this request would extend the existing leave chain to $span).";
                        $base['detail'] += ['span' => $span, 'chain_start' => $chainStart, 'chain_end' => $chainEnd, 'adjacent_requests' => $related];
                    }
                    break;
                case 'eligibility':
                    $tenure = (int) CarbonImmutable::parse(substr((string) $employee->getRawOriginal('joined_on'), 0, 10))->diffInDays(CarbonImmutable::parse($from), false);
                    if ($tenure < (int) ($params['min_tenure_days'] ?? 0)) {
                        $messages[] = "{$r->name}: needs at least {$params['min_tenure_days']} days of service.";
                    }
                    break;
            }
            foreach ($messages as $message) {
                $conflict = $base + ['message' => $message];
                if (in_array($r->kind, self::SOFT_KINDS, true)) {
                    $conflict = $this->soften($conflict, $statutory, (bool) $r->exempt_statutory);
                }
                $hits[] = ['id' => $r->id, 'conflict' => $conflict];
            }
        }

        return $hits;
    }
}
