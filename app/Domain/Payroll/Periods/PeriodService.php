<?php

namespace App\Domain\Payroll\Periods;

use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Payroll\Calendars\PeriodGenerator;
use App\Domain\Payroll\Models\{PayrollCalendar, PayrollGroup, PayrollPeriod, PayrollPeriodTransition};
use App\Domain\Payroll\Support\{PayrollEvents, PayrollStates};
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PeriodService
{
    public function __construct(private PeriodGenerator $gen) {}

    public function createCalendar(PayrollGroup $group, array $d): PayrollCalendar
    {
        if ($d['frequency'] !== 'custom') {
            try { $this->gen->generate($d['frequency'], $d['effective_from'], $d['effective_from'], $d['anchor_date'] ?? null, $d['rules'] ?? []); }
            catch (\InvalidArgumentException $e) { throw ValidationException::withMessages(['frequency' => $e->getMessage()]); }
        }

        return DB::transaction(function () use ($group, $d) {
            DB::table('tenants')->where('id', auth()->user()->tenant_id)->lockForUpdate()->first();
            if (PayrollCalendar::where('payroll_group_id', $group->id)->where('status', 'active')->where('effective_from', '<=', $d['effective_to'] ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $d['effective_from']))->exists()) throw ValidationException::withMessages(['effective_from' => 'This group already has an active calendar for those dates.']);
            $c = PayrollCalendar::create(['payroll_group_id' => $group->id, 'name' => $d['name'], 'frequency' => $d['frequency'], 'anchor_date' => $d['anchor_date'] ?? null,
                'effective_from' => $d['effective_from'], 'effective_to' => $d['effective_to'] ?? null, 'rules' => $d['rules'] ?? null, 'status' => 'active']);
            Audit::record('calendar.created', 'payroll', $c->uuid);

            return $c;
        });
    }

    /** Creates missing regular periods (idempotent). @return array{created:int,skipped:int} */
    public function generate(PayrollCalendar $cal, string $from, string $to): array
    {
        $group = $cal->group;
        try { $list = $this->gen->generate($cal->frequency, $from, $to, $cal->anchor_date ? substr((string) $cal->anchor_date, 0, 10) : null, $cal->rules ?? []); }
        catch (\InvalidArgumentException $e) { throw ValidationException::withMessages(['from' => $e->getMessage()]); }
        $created = 0; $skipped = 0;
        DB::transaction(function () use ($list, $cal, $group, &$created, &$skipped) {
            foreach ($list as $p) {
                if ($p['start'] < substr((string) $cal->effective_from, 0, 10) || ($cal->effective_to && $p['end'] > substr((string) $cal->effective_to, 0, 10))) { $skipped++; continue; }
                if (PayrollPeriod::where('payroll_group_id', $group->id)->where('period_type', 'regular')->where('period_start', $p['start'])->where('period_end', $p['end'])->exists()) { $skipped++; continue; }
                PayrollPeriod::create(['payroll_group_id' => $group->id, 'calendar_id' => $cal->id, 'label' => $p['label'], 'period_start' => $p['start'], 'period_end' => $p['end'], 'state' => 'upcoming',
                    'tax_year' => substr($p['start'], 0, 4), 'currency' => $group->currency, 'period_type' => 'regular', 'cycle_no' => 1] + $this->gen->dates($p['end'], $cal->rules ?? []));
                $created++;
            }
            Audit::record('periods.generated', 'payroll', $cal->uuid, ['created' => $created, 'skipped' => $skipped]);
        });

        return compact('created', 'skipped');
    }

    /** Off-cycle / bonus / correction period (may reuse the dates of a regular period). */
    public function createSpecial(PayrollGroup $group, array $d): PayrollPeriod
    {
        return DB::transaction(function () use ($group, $d) {
            $parent = null;
            if (! empty($d['parent_period_uuid'])) {
                $parent = PayrollPeriod::where('uuid', $d['parent_period_uuid'])->where('payroll_group_id', $group->id)->first() ?? throw ValidationException::withMessages(['parent_period_uuid' => 'Parent period not found for this group.']);
            }
            $start = $d['period_start'] ?? ($parent ? substr((string) $parent->period_start, 0, 10) : throw ValidationException::withMessages(['period_start' => 'Provide dates or a parent period.']));
            $end = $d['period_end'] ?? ($parent ? substr((string) $parent->period_end, 0, 10) : $start);
            if ($end < $start) throw ValidationException::withMessages(['period_end' => 'End date cannot be before start date.']);
            $cycle = (PayrollPeriod::where('payroll_group_id', $group->id)->where('period_type', $d['period_type'])->where('period_start', $start)->where('period_end', $end)->max('cycle_no') ?? 0) + 1;
            $p = PayrollPeriod::create(['payroll_group_id' => $group->id, 'parent_period_id' => $parent?->id, 'label' => $d['label'], 'period_start' => $start, 'period_end' => $end, 'state' => 'upcoming',
                'tax_year' => substr($start, 0, 4), 'currency' => $group->currency, 'period_type' => $d['period_type'], 'cycle_no' => $cycle, 'payment_date' => $d['payment_date'] ?? null, 'notes' => $d['notes'] ?? null]);
            Audit::record('period.created', 'payroll', $p->uuid, ['type' => $d['period_type']]);

            return $p;
        });
    }

    public function transition(User $actor, PayrollPeriod $period, string $to, ?string $reason = null): PayrollPeriod
    {
        return DB::transaction(function () use ($actor, $period, $to, $reason) {
            $p = PayrollPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            PayrollStates::assertTransition(PayrollStates::PERIOD_TRANSITIONS, $p->state, $to);
            if ($to === 'reopened' && ! $reason) throw ValidationException::withMessages(['reason' => 'A reason is required to reopen a period.']);
            $from = $p->state;
            $p->update(['state' => $to] + ($to === 'input_open' && ! $p->opened_at ? ['opened_at' => now()] : []) + ($to === 'closed' ? ['closed_at' => now()] : []));
            PayrollPeriodTransition::create(['period_id' => $p->id, 'from_state' => $from, 'to_state' => $to, 'actor_id' => $actor->id, 'reason' => $reason]);
            Audit::record('period.'.$to, 'payroll', $p->uuid, ['from' => $from]);
            if ($to === 'input_open') app(DomainEvents::class)->record(PayrollEvents::PAYROLL_OPENED, ['summary' => 'Payroll period opened', 'payload' => ['period_uuid' => $p->uuid, 'group_id' => $p->payroll_group_id], 'actor_id' => $actor->id, 'effective_date' => substr((string) $p->period_start, 0, 10)]);

            return $p;
        });
    }

    public function present(PayrollPeriod $p): array
    {
        $d = fn ($v) => $v ? substr((string) $v, 0, 10) : null;

        return ['uuid' => $p->uuid, 'label' => $p->label, 'period_type' => $p->period_type, 'cycle_no' => $p->cycle_no, 'period_start' => $d($p->period_start), 'period_end' => $d($p->period_end), 'state' => $p->state,
            'tax_year' => $p->tax_year, 'currency' => $p->currency, 'attendance_cutoff' => $d($p->attendance_cutoff), 'leave_cutoff' => $d($p->leave_cutoff), 'variable_pay_cutoff' => $d($p->variable_pay_cutoff),
            'input_cutoff' => $d($p->input_cutoff), 'approval_deadline' => $d($p->approval_deadline), 'payment_date' => $d($p->payment_date), 'allowed_next' => PayrollStates::PERIOD_TRANSITIONS[$p->state] ?? []];
    }
}
