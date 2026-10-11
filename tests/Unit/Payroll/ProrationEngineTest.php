<?php

namespace Tests\Unit\Payroll;

use App\Domain\Payroll\Calculation\FormulaEngine;
use App\Domain\Payroll\Contracts\Dto\StatutoryContext;
use App\Domain\Payroll\Contracts\Dto\StatutoryResult;
use App\Domain\Payroll\Contracts\StatutoryPack;
use App\Domain\Payroll\Engine\PayableDays;
use App\Domain\Payroll\Engine\PayrollCalculator;
use App\Domain\Payroll\Support\StatutoryPackRegistry;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class ProrationEngineTest extends TestCase
{
    private function calc(): PayrollCalculator
    {
        $registry = new StatutoryPackRegistry(new Container);
        $registry->register('fake', new class implements StatutoryPack
        {
            public function handler(): string
            {
                return 'fake';
            }

            public function calculate(StatutoryContext $c): StatutoryResult
            {
                // 10% of taxable gross (test fixture, not law)
                $tax = bcmul($c->amounts['taxable_gross'], '0.10', 2);

                return new StatutoryResult([['concept' => 'income_tax', 'kind' => 'tax', 'component_code' => 'TAX', 'amount' => $tax, 'rule_id' => 'fixture-1']]);
            }

            public function validate(StatutoryContext $c): array
            {
                return [];
            }

            public function identifiers(array $rules): array
            {
                return [];
            }

            public function reports(array $rules): array
            {
                return [];
            }
        });

        return new PayrollCalculator(new FormulaEngine, $registry);
    }

    private function compensation(string $base = '30000.00'): array
    {
        $line = fn (string $code, string $kind, string $amount, array $meta = [], ?string $formula = null) => ['component_id' => null, 'component_code' => $code, 'component_version' => 1, 'kind' => $kind, 'name' => $code, 'amount' => $amount, 'formula' => $formula, 'sequence' => 10, 'meta' => $meta];

        return ['compensation_id' => 1, 'structure_id' => null, 'base_pay' => $base, 'hourly_rate' => '100.00', 'effective_from' => '2026-09-01', 'effective_to' => null,
            'lines' => [$line('BASE', 'earning', $base, ['proration' => 'payable_days']), $line('HRA', 'earning', '0', ['proration' => 'payable_days'], 'BASE * 0.4'), $line('MEAL', 'earning', '1000.00', ['proration' => 'none']),
                $line('PF', 'deduction', '1800.00', ['proration' => 'none'])]];
    }

    private function request(array $o = []): array
    {
        $comp = $this->compensation();
        $base = ['run_employee_id' => 0, 'employee_id' => 1, 'group_id' => 1, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency' => 'INR', 'run_type' => 'regular',
            'employment' => ['start' => '2026-01-05', 'end' => null], 'compensation' => $comp, 'segments' => [['from' => '2026-09-01', 'to' => '2026-09-30', 'compensation' => $comp]], 'inputs' => [], 'adjustments' => [],
            'statutory' => ['handler' => 'fake', 'country' => 'XX', 'rules' => [], 'validation_rules' => [], 'pack_version_id' => 7, 'pack_version' => 1, 'tax_year' => '2026', 'periods_in_year' => 12, 'period_index' => 9]];

        return array_merge($base, $o);
    }

    private function byCode($draft): array
    {
        return array_column($draft->components, null, 'component_code');
    }

    public function test_payable_days_golden_cases(): void
    {
        $full = PayableDays::resolve(['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'employment_start' => '2026-01-01']);
        $this->assertSame('30.0000', $full['payable_days']);
        $this->assertSame('15.0000', PayableDays::resolve(['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'employment_start' => '2026-09-16'])['payable_days']);
        $this->assertSame('10.0000', PayableDays::resolve(['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'employment_end' => '2026-09-10'])['payable_days']);
        $this->assertSame('27.5000', PayableDays::resolve(['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'lop_days' => '2.5'])['payable_days']);
        $this->assertSame(29, PayableDays::resolve(['period_start' => '2028-02-01', 'period_end' => '2028-02-29'])['period_days']);
        $att = PayableDays::resolve(['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'attendance_payable' => '26.5', 'lop_days' => '4']);
        $this->assertSame('26.5000', $att['payable_days']); // LOP already reflected in attendance
        $this->assertSame('attendance', $att['source']);
    }

    public function test_full_month_calculation_with_formula_statutory_and_exact_rounding(): void
    {
        $draft = $this->calc()->calculate($this->request());
        $by = $this->byCode($draft);
        $this->assertSame([], $draft->errors);
        $this->assertSame('30000.00', $by['BASE']['amount']);
        $this->assertSame('12000.00', $by['HRA']['amount']);
        $this->assertSame('1000.00', $by['MEAL']['amount']);
        $this->assertSame('4300.00', $by['TAX']['amount']); // 10% of 43000 taxable
        $this->assertSame('statutory', $by['TAX']['origin']);
        $this->assertSame(7, $draft->countryPackVersionId);
    }

    public function test_lop_and_joiner_proration(): void
    {
        $lop = $this->calc()->calculate($this->request(['inputs' => [['id' => 1, 'source' => 'leave', 'kind' => 'leave_lop', 'quantity' => '3.0000', 'amount' => null]]]));
        $by = $this->byCode($lop);
        $this->assertSame('27000.00', $by['BASE']['amount']);
        $this->assertSame('10800.00', $by['HRA']['amount']);
        $this->assertSame('1000.00', $by['MEAL']['amount']); // proration none
        $this->assertSame('27.0000', $lop->payableDays);

        $join = $this->calc()->calculate($this->request(['employment' => ['start' => '2026-09-16', 'end' => null]]));
        $this->assertSame('15000.00', $this->byCode($join)['BASE']['amount']);
        $this->assertSame('15.0000', $join->payableDays);
    }

    public function test_mid_period_revision_prorates_each_segment_with_its_own_rate(): void
    {
        $old = $this->compensation('30000.00');
        $new = $this->compensation('36000.00');
        $draft = $this->calc()->calculate($this->request(['segments' => [['from' => '2026-09-01', 'to' => '2026-09-15', 'compensation' => $old], ['from' => '2026-09-16', 'to' => '2026-09-30', 'compensation' => $new]]]));
        $by = $this->byCode($draft);
        $this->assertSame('33000.00', $by['BASE']['amount']); // 15000 + 18000
        $this->assertSame('1000.00', $by['MEAL']['amount']);
    }

    public function test_overtime_bonus_and_arrear_inputs_become_components_with_provenance(): void
    {
        $req = $this->request(['inputs' => [
            ['id' => 5, 'source' => 'overtime', 'kind' => 'approved_overtime_minutes', 'quantity' => '150', 'amount' => null],
            ['id' => 6, 'source' => 'bonus', 'kind' => 'bonus', 'component_code' => 'BONUS', 'quantity' => null, 'amount' => '2500.00', 'source_ref' => 'manual:1'],
        ], 'adjustments' => [['id' => 9, 'kind' => 'arrear', 'component_code' => null, 'amount' => '1000.00'], ['id' => 10, 'kind' => 'recovery', 'component_code' => 'ADV', 'amount' => '-200.00']],
            'overtime' => ['multiplier' => '1.5']]);
        $by = $this->byCode($this->calc()->calculate($req));
        $this->assertSame('375.00', $by['OVERTIME']['amount']); // 2.5h * 100 * 1.5
        $this->assertSame('2500.00', $by['BONUS']['amount']);
        $this->assertSame(6, $by['BONUS']['source_input_id']);
        $this->assertSame('1000.00', $by['ARREAR']['amount']);
        $this->assertSame('arrear', $by['ARREAR']['origin']);
        $this->assertSame('deduction', $by['ADV']['kind']);
    }

    public function test_adjustment_reversals_retain_original_tax_and_recovery_semantics(): void
    {
        $draft = $this->calc()->calculate($this->request(['adjustments' => [
            ['id' => 1, 'kind' => 'reversal', 'component_code' => 'RECOVERY_REFUND', 'amount' => '100.00', 'trace' => ['reverses_kind' => 'recovery']],
            ['id' => 2, 'kind' => 'reversal', 'component_code' => 'NET_CORRECTION', 'amount' => '-50.00', 'trace' => ['reverses_kind' => 'manual_net']],
        ]]));
        $by = $this->byCode($draft);

        $this->assertSame('earning', $by['RECOVERY_REFUND']['kind']);
        $this->assertNull($by['RECOVERY_REFUND']['taxable_amount']);
        $this->assertSame('deduction', $by['NET_CORRECTION']['kind']);
        $this->assertNull($by['NET_CORRECTION']['taxable_amount']);
    }

    public function test_same_request_gives_identical_draft_and_failures_are_isolated(): void
    {
        $this->assertEquals($this->calc()->calculate($this->request()), $this->calc()->calculate($this->request()));

        $bad = $this->request(['segments' => [], 'compensation' => null]);
        $failed = $this->calc()->calculate($bad);
        $this->assertSame('no_compensation', $failed->errors[0]['code']);
        $this->assertSame([], $failed->components);
    }
}
