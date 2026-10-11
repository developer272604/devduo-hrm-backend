<?php

namespace App\Domain\Payroll\Integration;

use App\Domain\Leave\Payroll\PayrollContract;
use App\Domain\Payroll\Contracts\Dto\PayrollInputBatch;
use App\Domain\Payroll\Contracts\Dto\PayrollInputLine;
use App\Domain\Payroll\Contracts\Dto\PayrollInputQuery;
use App\Domain\Payroll\Contracts\PayrollInputProvider;

/**
 * Phase 4 -> Phase 5 adapter over App\Domain\Leave\Payroll\PayrollContract (references and unit totals per pay class; no money).
 * Pay classes become input kinds: leave_paid, leave_unpaid, leave_statutory, leave_lop, leave_comp_off, leave_encashment. Late adjustments
 * (leave decided after the cutoff) are flagged so the arrear engine can pick them up instead of silently changing a closed period.
 * When a published batch key is supplied (query meta via $batchKey) the immutable snapshot is read instead of the live preview.
 */
final class LeavePayrollInputProvider implements PayrollInputProvider
{
    /** Leave warnings that mean the leave data is not final for payroll. */
    private const NOT_FINAL = ['pending_requests', 'period_not_locked'];

    public function __construct(private PayrollContract $contract) {}

    public function source(): string
    {
        return 'leave';
    }

    public function collect(PayrollInputQuery $query, ?string $batchKey = null): PayrollInputBatch
    {
        if ($batchKey !== null) {
            $batch = $this->contract->batch($batchKey, $query->employeeIds);
            $contract = ['lines' => [], 'warnings' => []];
            foreach (($batch['lines'] ?? []) as $l) {
                $contract['lines'][] = $l + ['request_uuid' => $l['meta']['request_uuid'] ?? null];
            }
            $reference = 'leave:batch:'.$batchKey;
        } else {
            $contract = $this->contract->preview($query->from, $query->to, $query->employeeIds);
            $reference = 'leave:'.$query->from.':'.$query->to;
        }
        $lines = array_map(fn (array $l) => new PayrollInputLine(
            'leave', 'leave_'.$l['pay_class'], (string) $l['employee_uuid'], $l['employee_number'] ?? null, (string) $l['units'], 'leave_units',
            $l['meta']['request_uuid'] ?? $l['meta']['settlement_reference'] ?? $reference, true, (bool) $l['late_adjustment'],
            ['leave_type' => $l['leave_type_code'] ?? null, 'dates' => $l['meta']['dates'] ?? null, 'kind' => $l['meta']['kind'] ?? 'leave', 'payroll_reference' => $l['meta']['payroll_reference'] ?? null, 'batch' => $reference],
        ), $contract['lines']);
        $warnings = $contract['warnings'] ?? [];
        $complete = $batchKey !== null || array_filter($warnings, fn ($w) => in_array($w['key'], self::NOT_FINAL, true)) === [];

        return new PayrollInputBatch('leave', $query->from, $query->to, $lines, $warnings, $complete, $reference);
    }
}
