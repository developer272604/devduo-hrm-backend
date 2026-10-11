<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Contracts\EntitlementEngine;
use App\Domain\Leave\Accrual\EntitlementCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AccrualEngineTest extends AccrualTestCase
{
    use RefreshDatabase;

    private function engine(): EntitlementEngine
    {
        return app(EntitlementEngine::class);
    }

    public function test_engine_is_bound_and_monthly_entitlement_with_joiner_proration(): void
    {
        $this->setUpLeave(['entitlement' => ['basis' => 'monthly', 'amount' => '2']]);
        $this->assertInstanceOf(EntitlementCalculator::class, $this->engine());
        $full = $this->hire('Full Year', ['joined_on' => '2025-01-01']);
        $mid = $this->hire('Mid Joiner', ['joined_on' => '2026-03-16']);

        $r = $this->engine()->entitlementFor($full->id, $this->al->id, '2026-01-01', '2026-12-31');
        $this->assertSame('24.0000', $r['units']);
        $this->assertCount(12, $r['lines']);
        // March: 16 of 31 days employed => 2 * 16/31 = 1.0323; Apr-Dec = 18
        $r = $this->engine()->entitlementFor($mid->id, $this->al->id, '2026-01-01', '2026-12-31');
        $this->assertSame('19.0323', $r['units']);
        $this->assertSame('19.0323', \App\Domain\Leave\Support\Units::sum(array_column($r['lines'], 'units')));
    }

    public function test_joiner_modes_leaver_and_rounding(): void
    {
        $this->setUpLeave(['entitlement' => ['basis' => 'annual', 'amount' => '18', 'proration' => ['joiner' => 'daily', 'rounding' => 'nearest_half']]]);
        $e = $this->hire('Joiner', ['joined_on' => '2026-07-01']);
        $this->assertSame('9.0000', $this->engine()->entitlementFor($e->id, $this->al->id, '2026-01-01', '2026-12-31')['units']); // 9.0740 -> nearest half
        $this->rules['entitlement']['proration'] = ['joiner' => 'none'];
        $this->assertSame('0.0000', $this->engine()->entitlementFor($e->id, $this->al->id, '2026-01-01', '2026-12-31')['units']);
        $this->rules['entitlement']['proration'] = ['joiner' => 'full'];
        $this->assertSame('18.0000', $this->engine()->entitlementFor($e->id, $this->al->id, '2026-01-01', '2026-12-31')['units']);
        $leaver = $this->hire('Leaver', ['joined_on' => '2020-01-01']);
        $leaver->forceFill(['separated_on' => '2026-06-30'])->save();
        $this->rules['entitlement']['proration'] = ['leaver' => 'daily'];
        $this->assertSame('8.9260', $this->engine()->entitlementFor($leaver->id, $this->al->id, '2026-01-01', '2026-12-31')['units']); // 181/365 * 18
    }

    public function test_tenure_bands_and_waiting_period_deferral(): void
    {
        $this->setUpLeave(['entitlement' => ['basis' => 'anniversary', 'amount' => '1', 'tenure_bands' => [['from_months' => 0, 'to_months' => 36, 'amount' => '1'], ['from_months' => 36, 'amount' => '3']]]]);
        $e = $this->hire('Veteran', ['joined_on' => '2020-03-10']);
        $r = $this->engine()->accrualFor($e->id, $this->al->id, '2026-03-01', '2026-03-31');
        $this->assertSame('3.0000', $r['units']);
        $this->assertSame('2026-03-10', $r['periods'][0]['occurrences'][0]);

        $this->rules = ['entitlement' => ['basis' => 'monthly', 'amount' => '2', 'start_after_days' => 90]];
        $new = $this->hire('New Hire', ['joined_on' => '2026-08-01']);
        $this->assertSame('0.0000', $this->engine()->accrualFor($new->id, $this->al->id, '2026-08-01', '2026-08-31')['units']);
        // waiting ends 2026-10-30: Aug+Sep+Oct accrual is credited together (deferred, not lost)
        $r = $this->engine()->accrualFor($new->id, $this->al->id, '2026-10-30', '2026-10-31');
        $this->assertSame('6.0000', $r['units']); // Aug 2 + Sep 2 + Oct 2 (Oct 31 occurrence full month)... joiner daily on Aug (31/31)
    }

    public function test_caps_negative_limit_and_manual_basis(): void
    {
        $this->setUpLeave(['entitlement' => ['basis' => 'monthly', 'amount' => '2', 'max_per_period' => '5'], 'limits' => ['max_balance' => '3']]);
        $e = $this->hire('Capped', ['joined_on' => '2025-01-01']);
        $r = $this->engine()->accrualFor($e->id, $this->al->id, '2026-01-01', '2026-06-30');
        $this->assertSame('3.0000', $r['units']); // 2 then capped to 1 by the balance cap
        $this->assertNotEmpty(array_filter($r['lines'], fn ($l) => str_contains($l['label'], 'capped')));
        $this->rules = ['entitlement' => ['basis' => 'manual', 'amount' => '5']];
        $this->assertSame('manual_basis', $this->engine()->accrualFor($e->id, $this->al->id, '2026-01-01', '2026-06-30')['skipped']);
        $this->assertSame('5.0000', $this->engine()->entitlementFor($e->id, $this->al->id, '2026-01-01', '2026-12-31')['units']);
        $this->rules = [];
        $this->assertSame('type_not_granted', $this->engine()->accrualFor($e->id, $this->al->id, '2026-01-01', '2026-06-30')['skipped']);
    }
}
