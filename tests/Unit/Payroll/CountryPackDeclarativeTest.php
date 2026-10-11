<?php

namespace Tests\Unit\Payroll;

use App\Domain\Payroll\Calculation\FormulaEngine;
use App\Domain\Payroll\Contracts\Dto\StatutoryContext;
use App\Domain\Payroll\CountryPacks\{DeclarativePack, RuleValidator, SamplePacks};
use PHPUnit\Framework\TestCase;

class CountryPackDeclarativeTest extends TestCase
{
    private function ctx(array $amounts, ?array $rules = null, int $ppy = 12): StatutoryContext
    {
        $rules ??= SamplePacks::definition()['version']['calculation_rules'];

        return new StatutoryContext('ZZ', 'USD', '2026-01-01', '2026-01-31', '2026', $ppy, 1, 1, 1, $rules, [], $amounts);
    }

    public function test_sample_pack_rules_are_valid_but_flagged_non_authoritative(): void
    {
        $def = SamplePacks::definition();
        $this->assertSame([], (new RuleValidator(new FormulaEngine))->problems($def['version']['calculation_rules']));
        $this->assertTrue($def['version']['metadata']['non_authoritative']);
        $this->assertTrue($def['version']['metadata']['requires_expert_validation']);
    }

    public function test_slab_rate_cap_and_threshold(): void
    {
        $pack = new DeclarativePack(new FormulaEngine);
        $r = $pack->calculate($this->ctx(['gross' => '5000.00', 'basic' => '4000.00', 'taxable_gross' => '5000.00']));
        $by = collect($r->lines)->keyBy('concept');
        // annual 60000: 0 on first 10000, 10% on next 40000 = 4000, 20% on 10000 = 2000 -> 6000 / 12
        $this->assertSame('500.00', $by['sample_income_tax']['amount']);
        $this->assertSame('100.00', $by['sample_pension_employee']['amount']); // 5% of capped 2000
        $this->assertSame('140.00', $by['sample_pension_employer']['amount']);
        $this->assertSame('10.00', $by['sample_flat_levy']['amount']);
        $this->assertSame([], $r->errors);
        $this->assertSame('2000', $by['sample_pension_employee']['trace']['capped_base']);
        $low = collect($pack->calculate($this->ctx(['gross' => '800', 'basic' => '800', 'taxable_gross' => '800']))->lines)->keyBy('concept');
        $this->assertSame('0.00', $low['sample_income_tax']['amount']);
        $this->assertFalse($low->has('sample_flat_levy'));
    }

    public function test_formula_rule_and_validation(): void
    {
        $rules = ['concepts' => [['id' => 'f', 'kind' => 'deduction', 'type' => 'formula', 'formula' => 'MIN(GROSS * 2%, 50)']]];
        $v = new RuleValidator(new FormulaEngine);
        $this->assertSame([], $v->problems($rules));
        $line = (new DeclarativePack(new FormulaEngine))->calculate($this->ctx(['gross' => '1000'], $rules))->lines[0];
        $this->assertSame('20.00', $line['amount']);
        $bad = ['concepts' => [['id' => 'x', 'kind' => 'tax', 'type' => 'slab', 'slabs' => [['up_to' => '100', 'rate' => '5'], ['up_to' => '50', 'rate' => '5']]]]];
        $this->assertNotEmpty($v->problems($bad));
        $this->assertNotEmpty($v->problems(['concepts' => [['id' => 'x', 'kind' => 'tax', 'type' => 'rate', 'rate' => '150']]]));
        $this->expectException(\InvalidArgumentException::class);
        $v->normalize(['rate' => 0.5]);
    }
}
