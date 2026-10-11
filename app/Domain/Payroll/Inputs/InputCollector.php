<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Audit\Audit;
use App\Domain\Payroll\Contracts\Dto\PayrollInputQuery;
use App\Domain\Payroll\Integration\PayrollInputRegistry;
use App\Domain\Payroll\Models\PayrollInput;
use App\Domain\Payroll\Models\PayrollRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pulls attendance and leave inputs for a run through the PayrollInputRegistry adapters (never recomputing attendance or leave) and stores them
 * as payroll_inputs with provenance. Idempotent: the dedupe key is stable per source fact, so re-collecting refreshes quantities instead of duplicating.
 * Consumed (locked) inputs are never touched.
 */
final class InputCollector
{
    public const SOURCES = ['attendance', 'leave'];

    public function __construct(private PayrollInputRegistry $registry) {}

    /** @return array{collected:int,created:int,updated:int,skipped:int,complete:bool,warnings:array<int,array<string,mixed>>} */
    public function collect(PayrollRun $run, User $actor): array
    {
        $period = $run->period;
        $group = $run->group;
        $rows = DB::table('payroll_run_employees')->where('tenant_id', $run->tenant_id)->where('run_id', $run->id)->whereNotIn('status', ['excluded'])->pluck('employee_id')->all();
        $uuids = DB::table('employees')->whereIn('id', $rows ?: [0])->pluck('id', 'uuid')->all();
        $periodKey = substr((string) $period->period_start, 0, 10).':'.substr((string) $period->period_end, 0, 10);
        $summary = ['collected' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'complete' => true, 'warnings' => []];

        foreach (self::SOURCES as $source) {
            try {
                $batch = $this->registry->provider($source)->collect(new PayrollInputQuery(substr((string) $period->period_start, 0, 10), substr((string) $period->period_end, 0, 10), $actor, $group->code, array_values($uuids), false));
            } catch (Throwable $e) {
                $summary['complete'] = false;
                $summary['warnings'][] = ['key' => $source.'_unavailable', 'message' => ucfirst($source).' inputs could not be collected: '.$e->getMessage()];

                continue;
            }
            $summary['complete'] = $summary['complete'] && $batch->complete;
            array_push($summary['warnings'], ...array_map(fn ($w) => $w + ['source' => $source], $batch->warnings));
            foreach ($batch->lines as $line) {
                $employeeId = $uuids[$line->employeeUuid] ?? null;
                if (! $employeeId) {
                    $summary['skipped']++;

                    continue;
                }
                $summary['collected']++;
                $key = $line->dedupeKey($periodKey);
                $values = ['quantity' => $line->quantity, 'is_final' => $line->isFinal, 'meta' => $line->meta + ['unit' => $line->unit, 'late_adjustment' => $line->lateAdjustment]];
                $existing = PayrollInput::where('dedupe_key', $key)->first();
                if ($existing) {
                    if ($existing->status === 'consumed') {
                        $summary['skipped']++;

                        continue;
                    }
                    $existing->update($values + ['status' => 'approved']);
                    $summary['updated']++;

                    continue;
                }
                PayrollInput::create($values + ['employee_id' => $employeeId, 'payroll_group_id' => $group->id, 'period_id' => $period->id, 'source' => $line->source, 'kind' => $line->kind,
                    'status' => 'approved', 'source_ref' => $line->reference, 'dedupe_key' => $key, 'created_by' => $actor->id]);
                $summary['created']++;
            }
        }
        Audit::record('payroll.inputs.collected', 'payroll', $run->uuid, ['created' => $summary['created'], 'updated' => $summary['updated'], 'complete' => $summary['complete']]);

        return $summary;
    }
}
