<?php

namespace App\Domain\Payroll\Explainer;

use App\Domain\Payroll\Contracts\ResultStore;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Operations\PayrollOps;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Why is this net / each component?" Built ONLY from stored, immutable results (components, formula text as applied, input snapshot, trace,
 * rule versions). Nothing is recalculated; `reproducible` is the ResultStore checksum verification and `reconciles` proves the components add up to the stored net.
 */
final class PayrollExplainer
{
    public const SIGN = ['earning' => 1, 'reimbursement' => 1, 'deduction' => -1, 'employee_contribution' => -1, 'tax' => -1];

    public function __construct(private ResultStore $results) {}

    public static function signed(string $kind, string $amount): string
    {
        $s = self::SIGN[$kind] ?? 0;

        return $s === 0 ? '0' : ($s > 0 ? $amount : Decimal::sub('0', $amount)->__toString());
    }

    /** @return array{0:object,1:PayrollRunEmployee,2:\App\Domain\Payroll\Models\PayrollCalculationResult} */
    public function locate(User $viewer, string $employeeUuid, ?string $runUuid, string $permission = 'payroll.explain'): array
    {
        $employee = PayrollOps::employees($viewer, [$permission])->where('e.uuid', $employeeUuid)->first(['e.id', 'e.uuid', 'e.employee_number', 'p.display_name']);
        abort_unless($employee, 404, 'Employee not found.');
        $q = PayrollRunEmployee::where('employee_id', $employee->id)->whereNotNull('current_result_id');
        if ($runUuid) {
            $run = PayrollRun::where('uuid', $runUuid)->first();
            abort_unless($run, 404, 'Run not found.');
            $q->where('run_id', $run->id);
        } else {
            $q->whereHas('run', fn ($r) => $r->where('status', '!=', 'cancelled'));
        }
        $re = $q->orderByDesc('id')->first();
        abort_unless($re, 404, 'No calculated payroll result exists for this employee.');
        $result = $this->results->current($re->id);
        abort_unless($result, 404, 'No calculated payroll result exists for this employee.');

        return [$employee, $re, $result];
    }

    /** @return array<string,mixed> */
    public function explain(User $viewer, string $employeeUuid, ?string $runUuid): array
    {
        [$employee, , $result] = $this->locate($viewer, $employeeUuid, $runUuid);
        $run = PayrollRun::with('period')->find($result->run_id);
        $scale = Money::exponent($result->currency);
        $fmt = fn ($v) => Decimal::str($v, $scale);
        $components = $result->components()->orderBy('sequence')->get();
        $sum = ['earning' => '0', 'reimbursement' => '0', 'deduction' => '0', 'employee_contribution' => '0', 'tax' => '0'];
        $rows = [];
        $inputIds = $components->pluck('source_input_id')->filter()->all();
        $inputs = $inputIds ? DB::table('payroll_inputs')->whereIn('id', $inputIds)->get(['id', 'uuid', 'source', 'kind', 'source_ref', 'is_final'])->keyBy('id') : collect();
        foreach ($components as $c) {
            if (isset($sum[$c->kind])) {
                $sum[$c->kind] = Decimal::add($sum[$c->kind], $c->amount)->__toString();
            }
            $input = $c->source_input_id ? ($inputs[$c->source_input_id] ?? null) : null;
            $dir = self::SIGN[$c->kind] ?? 0;
            $rows[] = ['code' => $c->component_code, 'name' => $c->name, 'kind' => $c->kind, 'direction' => $dir > 0 ? 'adds' : ($dir < 0 ? 'subtracts' : 'informational'),
                'amount' => $fmt($c->amount), 'quantity' => $c->quantity, 'formula' => $c->formula, 'formula_version' => $c->formula_version, 'component_version' => $c->component_version, 'origin' => $c->origin,
                'source' => ['ref' => $c->source_ref, 'input' => $input ? ['uuid' => $input->uuid, 'source' => $input->source, 'kind' => $input->kind, 'ref' => $input->source_ref, 'final' => (bool) $input->is_final] : null],
                'trace' => $c->trace];
        }
        $derivedNet = Decimal::sub(Decimal::sub(Decimal::add($sum['earning'], $sum['reimbursement']), Decimal::add($sum['deduction'], $sum['employee_contribution'])), $sum['tax']);
        $snapshot = $result->input_snapshot ?? [];
        $col = collect($rows);

        return [
            'employee' => ['employee_uuid' => $employee->uuid, 'employee_number' => $employee->employee_number, 'name' => $employee->display_name],
            'run' => ['uuid' => $run?->uuid, 'status' => $run?->status, 'period' => $run?->period?->label],
            'result' => ['version' => (int) $result->version, 'status' => $result->status, 'currency' => $result->currency, 'checksum' => $result->checksum, 'engine_version' => $result->engine_version],
            'totals' => ['gross' => $fmt($result->gross), 'reimbursements' => $fmt($result->reimbursements), 'deductions' => $fmt($result->total_deductions), 'taxes' => $fmt($result->total_taxes),
                'employer_contributions' => $fmt($result->employer_contributions), 'benefits' => $fmt($result->benefits), 'net' => $fmt($result->net)],
            'equation' => 'net = gross + reimbursements - deductions (incl. employee contributions) - taxes',
            'reconciles' => Decimal::eq($derivedNet, $result->net), 'reproducible' => $this->results->verify($result),
            'answers' => [
                'why_gross' => $col->where('kind', 'earning')->map(fn ($r) => $r['name'].' '.$r['amount'].($r['formula'] ? ' ('.$r['formula'].')' : ''))->values()->all(),
                'why_net' => 'Gross '.$fmt($result->gross).' + reimbursements '.$fmt($result->reimbursements).' - deductions '.$fmt($result->total_deductions).' - taxes '.$fmt($result->total_taxes).' = '.$fmt($result->net),
                'deductions' => $col->whereIn('kind', ['deduction', 'employee_contribution', 'tax'])->map(fn ($r) => ['name' => $r['name'], 'kind' => $r['kind'], 'amount' => $r['amount'], 'origin' => $r['origin'], 'formula' => $r['formula'], 'source' => $r['source']])->values()->all(),
                'attendance_leave_inputs' => collect($snapshot)->only(['attendance', 'leave', 'overtime'])->all(),
            ],
            'payable_days' => $result->payable_days, 'working_days' => $result->working_days,
            'components' => $rows, 'inputs' => $snapshot, 'trace' => $result->trace ?? [],
            'rules' => ['country_pack_version_id' => $result->country_pack_version_id, 'compensation_id' => $result->compensation_id, 'structure_id' => $result->structure_id,
                'formulas' => $col->filter(fn ($r) => $r['formula'])->map(fn ($r) => ['code' => $r['code'], 'formula' => $r['formula'], 'formula_version' => $r['formula_version'], 'component_version' => $r['component_version']])->values()->all()],
            'warnings' => $result->warnings ?? [], 'errors' => $result->errors ?? [],
        ];
    }
}
