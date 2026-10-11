<?php

namespace App\Domain\Leave\Payroll;

use App\Domain\Attendance\Operations\PayrollCutoff;
use App\Domain\Audit\Audit;
use App\Domain\Leave\Contracts\LeaveLockGuard;
use App\Domain\Leave\Operations\LeaveOps;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Leave\Support\Units;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Leave -> Payroll contract (consumed by Phase 5). REFERENCES AND METADATA ONLY: unit totals per pay class, request/segment
 * references, encashment/settlement references and cutoff metadata. No rate, no amount, no salary maths, no currency.
 *
 * Pay classes: paid | unpaid | statutory | lop | comp_off | encashment. A line is flagged `late_adjustment` when the leave was
 * decided after the cutoff date of the period, so payroll can pick it up as a retro item rather than silently changing a closed period.
 * Phase 5 reads either preview() (live) or a published batch (leave_payroll_lines rows under a batch_key, an immutable snapshot).
 */
final class PayrollContract
{
    public const PAY_CLASSES = ['paid', 'unpaid', 'statutory', 'lop', 'comp_off', 'encashment'];

    public function __construct(private LeaveLockGuard $locks) {}

    /**
     * Cutoff metadata for a period. cutoff_day comes from leave settings integration.cutoff_day, else the attendance payroll cutoff day.
     * Convention (same as attendance): the cutoff day of the month FOLLOWING the period end is when payroll for the period is cut.
     *
     * @return array{configured:bool,cutoff_day:?int,cutoff_date:?string,period_start:string,period_end:string,status:string,source:?string}
     */
    public function cutoff(string $from, string $to, ?string $today = null): array
    {
        $today ??= LeaveOps::today();
        $day = LeaveSettings::get('integration.cutoff_day');
        $source = $day ? 'leave_settings' : null;
        if (! $day) {
            $day = PayrollCutoff::day();
            $source = $day ? 'attendance_settings' : null;
        }
        if (! $day) {
            return ['configured' => false, 'cutoff_day' => null, 'cutoff_date' => null, 'period_start' => $from, 'period_end' => $to, 'status' => 'not_configured', 'source' => null];
        }
        $next = CarbonImmutable::parse($to)->startOfMonth()->addMonthNoOverflow();
        $cutoff = $next->addDays(min((int) $day, $next->daysInMonth) - 1)->toDateString();

        return ['configured' => true, 'cutoff_day' => (int) $day, 'cutoff_date' => $cutoff, 'period_start' => $from, 'period_end' => $to, 'status' => $today > $cutoff ? 'passed' : ($today === $cutoff ? 'today' : 'upcoming'), 'source' => $source];
    }

    /**
     * Live contract lines for the period. $employeeIds null = whole tenant; otherwise only those employees (data scope applied by the caller).
     *
     * @param  array<int,int>|null  $employeeIds
     * @return array{period:array<string,string>,cutoff:array<string,mixed>,lines:array<int,array<string,mixed>>,totals:array<string,string>,employees:int,warnings:array<int,array<string,mixed>>,money:null}
     */
    public function preview(string $from, string $to, ?array $employeeIds = null): array
    {
        $this->assertPeriod($from, $to);
        $tenant = LeaveOps::tenantId();
        $cutoff = $this->cutoff($from, $to);
        $lines = [];

        $segments = DB::table('leave_request_segments as s')->join('leave_requests as r', 'r.id', '=', 's.request_id')->join('leave_types as t', 't.id', '=', 's.leave_type_id')->join('employees as e', 'e.id', '=', 's.employee_id')
            ->where('s.tenant_id', $tenant)->where('r.status', 'approved')->where('s.status', 'active')->where('s.charged', true)->whereBetween('s.leave_date', [$from, $to])
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('s.employee_id', $employeeIds ?: [0]))
            ->orderBy('e.id')->orderBy('r.id')->orderBy('s.leave_date')
            ->get(['s.id as segment_id', 's.request_id', 's.employee_id', 'e.uuid as employee_uuid', 'e.employee_number', 's.leave_type_id', 't.code as leave_type_code', 't.category', 't.is_statutory', 's.pay_class', 's.units', 's.leave_date', 'r.uuid as request_uuid', 'r.decided_at']);
        $grouped = [];
        foreach ($segments as $s) {
            $class = $this->payClass($s->pay_class, $s->category, (bool) $s->is_statutory);
            $key = $s->request_id.'|'.$class;
            $grouped[$key] ??= ['employee_id' => (int) $s->employee_id, 'employee_uuid' => $s->employee_uuid, 'employee_number' => $s->employee_number, 'pay_class' => $class, 'leave_type_id' => (int) $s->leave_type_id,
                'leave_type_code' => $s->leave_type_code, 'units' => '0.0000', 'request_id' => (int) $s->request_id, 'request_uuid' => $s->request_uuid, 'segment_id' => null,
                'decided_at' => $s->decided_at, 'dates' => []];
            $grouped[$key]['units'] = Units::add($grouped[$key]['units'], $s->units);
            $grouped[$key]['dates'][] = substr((string) $s->leave_date, 0, 10);
        }
        foreach ($grouped as $g) {
            $decided = $g['decided_at'] ? substr((string) $g['decided_at'], 0, 10) : null;
            $late = $cutoff['configured'] && $decided !== null && $decided > $cutoff['cutoff_date'];
            $lines[] = ['employee_id' => $g['employee_id'], 'employee_uuid' => $g['employee_uuid'], 'employee_number' => $g['employee_number'], 'period_start' => $from, 'period_end' => $to, 'pay_class' => $g['pay_class'], 'leave_type_id' => $g['leave_type_id'],
                'leave_type_code' => $g['leave_type_code'], 'units' => $g['units'], 'request_id' => $g['request_id'], 'segment_id' => null, 'late_adjustment' => $late, 'cutoff_date' => $cutoff['cutoff_date'],
                'meta' => ['kind' => 'leave', 'request_uuid' => $g['request_uuid'], 'dates' => $g['dates'], 'decided_on' => $decided]];
        }

        $refs = DB::table('leave_settlement_references as x')->join('employees as e', 'e.id', '=', 'x.employee_id')->join('leave_types as t', 't.id', '=', 'x.leave_type_id')
            ->where('x.tenant_id', $tenant)->where('x.kind', 'encashment')->whereIn('x.status', ['approved', 'posted'])->whereBetween('x.as_of', [$from, $to])
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('x.employee_id', $employeeIds ?: [0]))
            ->get(['x.uuid', 'x.employee_id', 'e.uuid as employee_uuid', 'e.employee_number', 'x.leave_type_id', 't.code as leave_type_code', 'x.units', 'x.as_of', 'x.payroll_reference', 'x.status']);
        foreach ($refs as $x) {
            $lines[] = ['employee_id' => (int) $x->employee_id, 'employee_uuid' => $x->employee_uuid, 'employee_number' => $x->employee_number, 'period_start' => $from, 'period_end' => $to, 'pay_class' => 'encashment', 'leave_type_id' => (int) $x->leave_type_id,
                'leave_type_code' => $x->leave_type_code, 'units' => Units::normalize($x->units), 'request_id' => null, 'segment_id' => null, 'late_adjustment' => false, 'cutoff_date' => $cutoff['cutoff_date'],
                'meta' => ['kind' => 'encashment_reference', 'settlement_reference' => $x->uuid, 'as_of' => substr((string) $x->as_of, 0, 10), 'payroll_reference' => $x->payroll_reference, 'status' => $x->status]];
        }

        $totals = array_fill_keys(self::PAY_CLASSES, '0.0000');
        foreach ($lines as $l) {
            $totals[$l['pay_class']] = Units::add($totals[$l['pay_class']], $l['units']);
        }

        return ['period' => ['from' => $from, 'to' => $to], 'cutoff' => $cutoff, 'lines' => $lines, 'totals' => $totals, 'employees' => count(array_unique(array_column($lines, 'employee_id'))),
            'warnings' => $this->warnings($from, $to, $employeeIds, $lines, $cutoff), 'money' => null];
    }

    /**
     * Publishes the contract as an immutable batch snapshot Phase 5 can read by batch_key. Re-publishing the same key replaces the
     * snapshot of that key (idempotent); other keys are untouched.
     */
    public function publish(string $from, string $to, ?array $employeeIds = null, ?string $batchKey = null): array
    {
        $result = $this->preview($from, $to, $employeeIds);
        $batchKey = $batchKey ?: 'leave:'.$from.':'.$to.':'.Str::lower(Str::random(6));
        if (! preg_match('/^[A-Za-z0-9:_\-.]{3,120}$/', $batchKey)) {
            throw ValidationException::withMessages(['batch_key' => 'Use 3-120 letters, numbers, colon, dash, dot or underscore.']);
        }
        $tenant = LeaveOps::tenantId();
        DB::transaction(function () use ($result, $batchKey, $tenant) {
            DB::table('leave_payroll_lines')->where('tenant_id', $tenant)->where('batch_key', $batchKey)->delete();
            foreach (array_chunk($result['lines'], 200) as $chunk) {
                DB::table('leave_payroll_lines')->insert(array_map(fn ($l) => ['tenant_id' => $tenant, 'batch_key' => $batchKey, 'employee_id' => $l['employee_id'], 'period_start' => $l['period_start'], 'period_end' => $l['period_end'],
                    'pay_class' => $l['pay_class'], 'leave_type_id' => $l['leave_type_id'], 'units' => $l['units'], 'request_id' => $l['request_id'], 'segment_id' => $l['segment_id'], 'late_adjustment' => $l['late_adjustment'],
                    'cutoff_date' => $l['cutoff_date'], 'meta' => json_encode($l['meta'] + ['employee_uuid' => $l['employee_uuid']]), 'created_at' => now(), 'updated_at' => now()], $chunk));
            }
        });
        Audit::record('payroll_batch_published', 'leave', 'payroll_batch:'.$batchKey, ['period' => [$from, $to], 'lines' => count($result['lines']), 'totals' => $result['totals']]);

        return ['batch_key' => $batchKey] + $result;
    }

    /** Lines of a published batch (read-only), limited to the given employees. @param array<int,int>|null $employeeIds */
    public function batch(string $batchKey, ?array $employeeIds = null): ?array
    {
        $rows = DB::table('leave_payroll_lines as l')->join('employees as e', 'e.id', '=', 'l.employee_id')->join('leave_types as t', 't.id', '=', 'l.leave_type_id')->where('l.tenant_id', LeaveOps::tenantId())->where('l.batch_key', $batchKey)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('l.employee_id', $employeeIds ?: [0]))->orderBy('l.id')
            ->get(['l.*', 'e.uuid as employee_uuid', 'e.employee_number', 't.code as leave_type_code']);
        if ($rows->isEmpty()) {
            return null;
        }
        $totals = array_fill_keys(self::PAY_CLASSES, '0.0000');
        $lines = $rows->map(function ($l) use (&$totals) {
            $totals[$l->pay_class] = Units::add($totals[$l->pay_class], $l->units);

            return ['employee_uuid' => $l->employee_uuid, 'employee_number' => $l->employee_number, 'period_start' => substr((string) $l->period_start, 0, 10), 'period_end' => substr((string) $l->period_end, 0, 10), 'pay_class' => $l->pay_class,
                'leave_type_code' => $l->leave_type_code, 'units' => Units::normalize($l->units), 'late_adjustment' => (bool) $l->late_adjustment, 'cutoff_date' => $l->cutoff_date ? substr((string) $l->cutoff_date, 0, 10) : null, 'meta' => json_decode($l->meta ?? 'null', true)];
        })->all();

        return ['batch_key' => $batchKey, 'lines' => $lines, 'totals' => $totals, 'money' => null];
    }

    /** @return array<int,array<string,mixed>> */
    private function warnings(string $from, string $to, ?array $employeeIds, array $lines, array $cutoff): array
    {
        $tenant = LeaveOps::tenantId();
        $out = [];
        $pending = DB::table('leave_requests')->where('tenant_id', $tenant)->where('status', 'pending')->where('start_date', '<=', $to)->where('end_date', '>=', $from)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds ?: [0]))->count();
        if ($pending > 0) {
            $out[] = ['key' => 'pending_requests', 'count' => $pending, 'message' => $pending.' leave request(s) in this period are still pending and are not included.'];
        }
        $late = count(array_filter($lines, fn ($l) => $l['late_adjustment']));
        if ($late > 0) {
            $out[] = ['key' => 'late_adjustments', 'count' => $late, 'message' => $late.' line(s) were approved after the cutoff date and are flagged as late adjustments.'];
        }
        $unlocked = DB::table('leave_locks')->where('tenant_id', $tenant)->where('status', 'locked')->where('start_date', '<=', $from)->where('end_date', '>=', $to)->doesntExist();
        if ($unlocked) {
            $out[] = ['key' => 'period_not_locked', 'count' => 1, 'message' => 'The leave period is not locked yet, so these lines can still change.'];
        }
        if (! $cutoff['configured']) {
            $out[] = ['key' => 'cutoff_not_configured', 'count' => 1, 'message' => 'No payroll cutoff day is configured, so late adjustments cannot be detected.'];
        }

        return $out;
    }

    private function payClass(?string $segmentClass, ?string $category, bool $statutory): string
    {
        $class = $segmentClass ?: 'paid';
        if (! in_array($class, self::PAY_CLASSES, true)) {
            $class = 'paid';
        }
        if ($class === 'paid' && $statutory) {
            return 'statutory';
        }
        if ($class === 'paid' && $category === 'unpaid') {
            return 'unpaid';
        }

        return $class;
    }

    private function assertPeriod(string $from, string $to): void
    {
        foreach (['from' => $from, 'to' => $to] as $k => $v) {
            if (LeaveOps::date($v) === null) {
                throw ValidationException::withMessages([$k => 'Use the format YYYY-MM-DD.']);
            }
        }
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'The end date cannot be before the start date.']);
        }
    }
}
