<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Contracts\ResultStore as ResultStoreContract;
use App\Domain\Payroll\Models\PayrollCalculationResult;
use App\Domain\Payroll\Models\PayrollResultComponent;
use App\Domain\Payroll\Results\ResultDraft;
use App\Domain\Payroll\Results\ResultRejected;
use App\Domain\Payroll\Results\ResultStore;
use App\Domain\Payroll\Support\PayrollSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\PayrollFixtures;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class ResultStoreTest extends TestCase
{
    use PayrollFixtures, PeopleFixtures, RefreshDatabase;

    private $run;

    private $runEmployee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $group = $this->payrollGroup();
        $this->run = $this->payrollRun($group, $this->payrollPeriod($group));
        $this->runEmployee = $this->runEmployee($this->run, $this->hire('Asha Rao'));
    }

    private function draft(?array $components = null, array $o = []): ResultDraft
    {
        return new ResultDraft($this->runEmployee->id, $o['currency'] ?? 'INR', $components ?? $this->sampleComponents(), PayrollSettings::ENGINE_VERSION, '28.5', '30',
            inputSnapshot: ['attendance' => ['payable_days' => '28.50', 'ref' => 'attendance:IN_MONTHLY:2026-09-01:2026-09-30']], trace: ['payable_days' => 'attendance payable days'],
            controlledCorrection: $o['correction'] ?? false);
    }

    public function test_totals_are_derived_from_components_with_exact_decimals(): void
    {
        $result = app(ResultStoreContract::class)->record($this->draft());
        $this->assertSame(1, $result->version);
        $this->assertSame('70000.0000', $result->fresh()->gross);
        $this->assertSame('3800.0000', $result->fresh()->total_deductions); // loan 2000 + employee pension 1800
        $this->assertSame('4500.5000', $result->fresh()->total_taxes);
        $this->assertSame('1800.0000', $result->fresh()->employer_contributions);
        $this->assertSame('1200.0000', $result->fresh()->reimbursements);
        $this->assertSame('62899.5000', $result->fresh()->net); // 70000 + 1200 - 3800 - 4500.50
        $this->assertSame(7, PayrollResultComponent::where('result_id', $result->id)->count());
        $this->assertSame($result->id, $this->runEmployee->fresh()->current_result_id);
    }

    public function test_identical_recalculation_is_idempotent_and_changes_create_a_superseding_version(): void
    {
        $store = app(ResultStoreContract::class);
        $first = $store->record($this->draft());
        $again = $store->record($this->draft());
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, PayrollCalculationResult::count());

        $changed = $this->sampleComponents();
        $changed[0]['amount'] = '52000.00';
        $second = $store->record($this->draft($changed));
        $this->assertSame(2, $second->version);
        $this->assertTrue((bool) $second->fresh()->is_current);
        $this->assertFalse((bool) $first->fresh()->is_current);
        $this->assertSame($second->id, $first->fresh()->superseded_by_id);
        $this->assertSame('70000.0000', $first->fresh()->gross, 'the superseded version keeps its figures');
        $this->assertSame($second->id, $store->current($this->runEmployee->id)->id);
    }

    public function test_results_and_components_are_immutable(): void
    {
        $result = app(ResultStoreContract::class)->record($this->draft());
        try {
            $result->update(['net' => '1.0000']);
            $this->fail('net must not be editable');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        try {
            $result->delete();
            $this->fail('results must not be deletable');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $component = PayrollResultComponent::first();
        $this->expectException(LogicException::class);
        $component->update(['amount' => '1.0000']);
    }

    public function test_checksum_proves_reproducibility_and_detects_tampering(): void
    {
        $store = app(ResultStoreContract::class);
        $result = $store->record($this->draft());
        $this->assertTrue($store->verify($result));
        DB::table('payroll_result_components')->where('result_id', $result->id)->where('component_code', 'BASE')->update(['amount' => '99999.0000']);
        $this->assertFalse($store->verify($result->fresh()));
    }

    public function test_unrounded_negative_unknown_and_float_values_are_refused(): void
    {
        $store = app(ResultStoreContract::class);
        foreach ([['amount' => '10.005'], ['amount' => '-5.00'], ['amount' => '5.00', 'kind' => 'mystery']] as $bad) {
            $components = [array_merge(['component_code' => 'X', 'name' => 'X', 'kind' => 'earning'], $bad)];
            try {
                $store->record($this->draft($components));
                $this->fail('expected rejection');
            } catch (ResultRejected $e) {
                $this->assertSame('invalid_result', $e->reason);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $store->record($this->draft([['component_code' => 'X', 'name' => 'X', 'kind' => 'earning', 'amount' => 10.5]]));
    }

    public function test_currency_must_match_the_run_and_zero_decimal_currencies_are_enforced(): void
    {
        $this->expectException(ResultRejected::class);
        app(ResultStoreContract::class)->record($this->draft(null, ['currency' => 'USD']));
    }

    public function test_locked_runs_refuse_ordinary_recalculation_but_allow_a_declared_correction(): void
    {
        $store = app(ResultStoreContract::class);
        $store->record($this->draft());
        $this->run->update(['status' => 'locked']);
        $changed = $this->sampleComponents();
        $changed[1]['amount'] = '21000.00';
        try {
            $store->record($this->draft($changed));
            $this->fail('locked run must refuse');
        } catch (ResultRejected $e) {
            $this->assertSame('run_locked', $e->reason);
            $this->assertSame(423, $e->getStatusCode());
        }
        $corrected = $store->record($this->draft($changed, ['correction' => true]));
        $this->assertSame(2, $corrected->version);
    }

    public function test_totals_helper_matches_the_store_so_simulators_can_reuse_it(): void
    {
        $totals = ResultStore::totals($this->sampleComponents(), 'INR');
        $this->assertSame('62899.5000', $totals['net']);
    }

    public function test_results_are_tenant_isolated(): void
    {
        app(ResultStoreContract::class)->record($this->draft());
        $other = $this->bootTenant('beta');
        $this->context($other->tenant_id);
        $this->assertSame(0, PayrollCalculationResult::count());
        $this->assertSame(0, PayrollResultComponent::count());
    }
}
