<?php

namespace App\Domain\Payroll\Engine;

use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Payroll\Contracts\EmployeeCalculator;
use App\Domain\Payroll\Contracts\ResultStore;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Results\ResultDraft;
use App\Domain\Payroll\Results\ResultRejected;
use App\Domain\Payroll\Runs\ControlTotals;
use App\Domain\Payroll\Runs\PeriodSync;
use App\Domain\Payroll\Support\PayrollEvents;
use App\Domain\Payroll\Support\PayrollSettings;
use App\Domain\Payroll\Support\PayrollStates;
use App\Domain\Payroll\Validation\ValidationEngine;
use App\Domain\Payroll\Runs\CalculateRunChunk;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Run-level calculation orchestration. start() opens a calculation pass and queues chunked jobs; processChunk() is idempotent and retry-safe
 * (run_employees.calc_pass marks finished employees); finalize runs once, when the last chunk of the CURRENT pass completes. Results are written
 * only through ResultStore; one employee's failure never fails the run.
 */
final class RunCalculator
{
    public const CHUNK = 50;

    public function __construct(private RequestBuilder $requests, private EmployeeCalculator $calculator, private ResultStore $store, private ValidationEngine $validation, private ControlTotals $totals) {}

    public function start(PayrollRun $run, int $actorId): PayrollRun
    {
        $run = DB::transaction(function () use ($run, $actorId) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! in_array($run->status, PayrollStates::CALCULABLE_RUN, true)) {
                throw ResultRejected::locked($run->status);
            }
            foreach (['inputs' => ['validating', 'calculating'], 'validating' => ['calculating'], 'review' => ['calculating'], 'reopened' => ['inputs', 'validating', 'calculating']][$run->status] ?? [] as $next) {
                PayrollStates::assertTransition(PayrollStates::RUN_TRANSITIONS, $run->status, $next, 'status');
                $run->status = $next;
            }
            $pass = $run->calculation_version + 1;
            $total = PayrollRunEmployee::where('run_id', $run->id)->whereNotIn('status', ['excluded', 'held'])->count();
            $run->fill(['calculation_version' => $pass, 'stage' => 4, 'employees_total' => $total, 'progress' => ['pass' => $pass, 'total' => $total, 'done' => 0, 'failed' => 0, 'started_at' => now()->toIso8601String(), 'finished_at' => null]])->save();
            PeriodSync::follow($run->period, 'calculating', $actorId, 'Calculation started');
            Audit::record('payroll.run.calculation_started', 'payroll', $run->uuid, ['pass' => $pass]);
            \App\Domain\Payroll\Locks\PayrollLockService::logChange($run, 'recalculation', ['pass' => $pass]);

            return $run;
        });
        $ids = PayrollRunEmployee::where('run_id', $run->id)->whereNotIn('status', ['excluded', 'held'])->orderBy('id')->pluck('id')->all();
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            CalculateRunChunk::dispatch($run->tenant_id, $run->uuid, $run->calculation_version, $chunk);
        }
        if (! $ids) {
            $this->finalize($run->fresh());
        }

        return $run->fresh();
    }

    /** @param array<int,int> $runEmployeeIds */
    public function processChunk(PayrollRun $run, int $pass, array $runEmployeeIds): void
    {
        foreach (PayrollRunEmployee::whereIn('id', $runEmployeeIds)->where('run_id', $run->id)->where('calc_pass', '<', $pass)->whereNotIn('status', ['excluded', 'held'])->orderBy('id')->get() as $re) {
            $fresh = PayrollRun::find($run->id);
            if (! $fresh || $fresh->calculation_version !== $pass || $fresh->status !== 'calculating') {
                return; // superseded pass or run moved on: stop quietly
            }
            $this->calculateOne($fresh, $re, $pass);
        }
        $this->finalize(PayrollRun::find($run->id), $pass);
    }

    public function calculateOne(PayrollRun $run, PayrollRunEmployee $re, ?int $pass = null): void
    {
        try {
            $request = $this->requests->build($run, $re);
            $draft = $this->calculator->calculate($request);
            if ($run->run_type === 'correction') {
                $draft = $this->asCorrection($run, $re, $draft);
            }
        } catch (ResultRejected $e) {
            throw $e;
        } catch (Throwable $e) {
            $draft = new ResultDraft($re->id, $run->currency, [], PayrollSettings::ENGINE_VERSION, errors: [['code' => 'calculation_error', 'message' => 'The calculation failed: '.$e->getMessage()]]);
        }
        try {
            $this->store->record($draft);
        } catch (ResultRejected $e) {
            if ($e->reason === 'run_locked') {
                throw $e;
            }
            $re->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
        }
        if ($pass !== null) {
            $re->update(['calc_pass' => $pass]);
        }
    }

    public function finalize(?PayrollRun $run, ?int $pass = null): void
    {
        if (! $run) {
            return;
        }
        DB::transaction(function () use ($run, $pass) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'calculating' || ($pass !== null && $run->calculation_version !== $pass)) {
                return;
            }
            if (PayrollRunEmployee::where('run_id', $run->id)->whereNotIn('status', ['excluded', 'held'])->where('calc_pass', '<', $run->calculation_version)->exists()) {
                return;
            }
            PayrollStates::assertTransition(PayrollStates::RUN_TRANSITIONS, 'calculating', 'review', 'status');
            $failed = PayrollRunEmployee::where('run_id', $run->id)->where('status', 'failed')->count();
            $run->status = 'review';
            $run->fill(['stage' => 5, 'engine_version' => PayrollSettings::ENGINE_VERSION, 'calculated_at' => now(), 'employees_failed' => $failed,
                'progress' => array_merge($run->progress ?? [], ['done' => (int) ($run->progress['total'] ?? 0), 'failed' => $failed, 'finished_at' => now()->toIso8601String()])])->save();
            $run->control_totals = $this->totals->compute($run);
            $run->save();
            $this->validation->validate($run);
            PeriodSync::follow($run->period, 'review', null, 'Calculation finished');
            Audit::record('payroll.run.calculated', 'payroll', $run->uuid, ['pass' => $run->calculation_version, 'failed' => $failed]);
            app(DomainEvents::class)->record(PayrollEvents::PAYROLL_CALCULATED, ['summary' => 'Payroll run calculated', 'payload' => ['run_uuid' => $run->uuid, 'pass' => $run->calculation_version, 'failed' => $failed], 'actor_id' => null]);
        });
    }

    /**
     * Re-calculation proof: re-runs the PURE calculator on the request stored inside the result's input snapshot and compares the checksum
     * of the produced components with the stored result, then verifies the stored rows are untampered.
     *
     * @return array{reproducible:bool,intact:bool,checksum:string,recomputed:?string}
     */
    public function proof(int $runEmployeeId): array
    {
        $result = $this->store->current($runEmployeeId) ?? throw ResultRejected::invalid('There is no result for this employee yet.');
        $request = ($result->input_snapshot ?? [])['request'] ?? null;
        $recomputed = null;
        if ($request) {
            $draft = $this->calculator->calculate($request);
            $recomputed = \App\Domain\Payroll\Support\Checksum::of($this->componentFingerprint($draft->components));
        }
        $stored = \App\Domain\Payroll\Support\Checksum::of($this->componentFingerprint($result->components()->orderBy('sequence')->get()->map(fn ($c) => ['component_code' => $c->component_code, 'kind' => $c->kind, 'amount' => \App\Domain\Payroll\Support\Decimal::str($c->amount, 4)])->all()));

        return ['reproducible' => $recomputed !== null && hash_equals($stored, $recomputed), 'intact' => $this->store->verify($result), 'checksum' => $result->checksum, 'recomputed' => $recomputed];
    }

    /** @param array<int,array<string,mixed>> $components */
    private function componentFingerprint(array $components): array
    {
        return array_map(fn ($c) => [$c['component_code'], $c['kind'], \App\Domain\Payroll\Support\Decimal::str($c['amount'], 4)], array_values($components));
    }

    /** Correction run: the draft holds only the DIFFERENCE to the parent run's current result (the parent stays untouched). */
    private function asCorrection(PayrollRun $run, PayrollRunEmployee $re, ResultDraft $draft): ResultDraft
    {
        if ($draft->errors || ! $run->parent_run_id) {
            return $draft;
        }
        $parentResult = DB::table('payroll_calculation_results')->where('tenant_id', $run->tenant_id)->where('run_id', $run->parent_run_id)->where('employee_id', $re->employee_id)->where('is_current', true)->first();
        $old = [];
        if ($parentResult) {
            foreach (DB::table('payroll_result_components')->where('result_id', $parentResult->id)->get() as $c) {
                $old[$c->component_code.'|'.$c->kind] = \App\Domain\Payroll\Support\Decimal::fromDb($c->amount, 4);
            }
        }
        $new = [];
        foreach ($draft->components as $c) {
            $k = $c['component_code'].'|'.$c['kind'];
            $new[$k] = \App\Domain\Payroll\Support\Decimal::str(\App\Domain\Payroll\Support\Decimal::add($new[$k] ?? 0, $c['amount']), 4);
        }
        $mirror = ['earning' => 'deduction', 'deduction' => 'earning', 'tax' => 'earning', 'employee_contribution' => 'earning', 'reimbursement' => 'deduction'];
        $components = [];
        $keys = array_unique([...array_keys($old), ...array_keys($new)]);
        sort($keys);
        foreach ($keys as $k) {
            [$code, $kind] = explode('|', $k);
            $delta = \App\Domain\Payroll\Support\Decimal::sub($new[$k] ?? 0, $old[$k] ?? 0);
            if (\App\Domain\Payroll\Support\Decimal::isZero($delta)) {
                continue;
            }
            $kindOut = $kind;
            if ($delta->isNegative()) {
                $kindOut = $mirror[$kind] ?? 'informational';
            }
            $components[] = ['component_code' => $code, 'name' => 'Correction '.$code, 'kind' => $kindOut, 'amount' => \App\Domain\Payroll\Support\Decimal::str($delta->abs(), 4), 'origin' => 'retro',
                'source_ref' => 'correction_of:run:'.$run->parent_run_id, 'sequence' => count($components), 'trace' => ['old' => $old[$k] ?? '0.0000', 'new' => $new[$k] ?? '0.0000', 'original_kind' => $kind]];
        }

        return new ResultDraft($draft->runEmployeeId, $draft->currency, $components, $draft->engineVersion, $draft->payableDays, $draft->workingDays, $draft->countryPackVersionId, $draft->compensationId, $draft->structureId,
            $draft->inputSnapshot, $draft->trace + ['correction_of_result_id' => $parentResult->id ?? null], $draft->warnings, [], $parentResult->id ?? null);
    }
}
