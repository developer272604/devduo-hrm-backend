<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Contracts\PackResolver;
use App\Domain\Payroll\CountryPacks\SamplePacks;
use App\Domain\Payroll\Models\CountryPackVersion;
use App\Domain\Payroll\Support\StatutoryPackRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PayrollFixtures;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class CountryPackLifecycleTest extends TestCase
{
    use RefreshDatabase, PeopleFixtures, PayrollFixtures;

    protected function setUp(): void { parent::setUp(); $this->bootTenant(); $this->signIn($this->admin); }

    private function ctx(): void { $this->context($this->admin->tenant_id); }

    public function test_declarative_handler_is_registered_and_sample_is_flagged(): void
    {
        $this->assertTrue(app(StatutoryPackRegistry::class)->has('declarative'));
        $pack = $this->postJson('/api/v1/payroll/country-packs/samples/install')->assertCreated()->json();
        $this->assertSame(SamplePacks::CODE, $pack['code']);
        $this->assertTrue($pack['versions'][0]['non_authoritative']);
        $this->assertTrue($pack['versions'][0]['requires_expert_validation']);
    }

    public function test_publish_verify_enable_gates_immutability_and_date_resolution(): void
    {
        $pack = $this->postJson('/api/v1/payroll/country-packs/samples/install')->json(); $this->ctx();
        $v1 = $pack['versions'][0]['uuid'];
        $group = $this->payrollGroup(['country' => 'ZZ', 'code' => 'ZZ_M']);
        $this->patchJson('/api/v1/payroll/groups/'.$group->uuid, ['country_pack_uuid' => $pack['uuid']])->assertOk(); $this->ctx();
        $this->postJson('/api/v1/payroll/country-packs/'.$pack['uuid'].'/enable')->assertUnprocessable(); $this->ctx();   // nothing published
        $this->postJson('/api/v1/payroll/country-pack-versions/'.$v1.'/check')->assertOk()->assertJsonPath('valid', true); $this->ctx();
        $this->postJson('/api/v1/payroll/country-pack-versions/'.$v1.'/publish')->assertOk()->assertJsonPath('status', 'published'); $this->ctx();
        $this->patchJson('/api/v1/payroll/country-pack-versions/'.$v1, ['tax_year_start' => '04-01'])->assertUnprocessable(); $this->ctx();
        $this->postJson('/api/v1/payroll/country-packs/'.$pack['uuid'].'/enable')->assertUnprocessable(); $this->ctx();   // unverified
        $this->postJson('/api/v1/payroll/country-pack-versions/'.$v1.'/verify', ['note' => 'Reviewed by test'])->assertUnprocessable(); $this->ctx(); // publisher cannot verify
        $checker = $this->userWithRole('Workspace administrator', 'checker@alpha.test');
        $this->signIn($checker)->postJson('/api/v1/payroll/country-pack-versions/'.$v1.'/verify', ['note' => 'Reviewed by independent expert'])->assertOk()->assertJsonPath('verified', true); $this->context($checker->tenant_id);
        $this->postJson('/api/v1/payroll/country-packs/'.$pack['uuid'].'/enable')->assertOk()->assertJsonPath('status', 'enabled'); $this->context($checker->tenant_id);
        $resolver = app(PackResolver::class);
        $this->assertSame(1, $resolver->forGroup($group->id, '2026-06-01')->version);
        $this->assertNull($resolver->forGroup($group->id, '2025-12-31'));
        // second version from 2027, published after the first: first is closed the day before
        $v2 = $this->postJson('/api/v1/payroll/country-packs/'.$pack['uuid'].'/versions', ['effective_from' => '2027-01-01', 'metadata' => ['source' => 'Fictional', 'requires_expert_validation' => true, 'non_authoritative' => true],
            'calculation_rules' => ['concepts' => [['id' => 'levy', 'kind' => 'deduction', 'type' => 'flat', 'amount' => '12']]]])->assertCreated()->json('uuid'); $this->context($checker->tenant_id);
        $this->postJson('/api/v1/payroll/country-pack-versions/'.$v2.'/publish')->assertOk(); $this->context($checker->tenant_id);
        $this->assertSame('2026-12-31', substr((string) CountryPackVersion::byUuid($v1)->effective_to, 0, 10));
        $this->assertSame(1, $resolver->forGroup($group->id, '2026-12-31')->version);
        $this->assertNull($resolver->forGroup($group->id, '2027-03-01')); // v2 not verified yet
        $this->assertSame(2, $resolver->version($pack['id'] ?? CountryPackVersion::byUuid($v2)->pack_id, '2027-03-01')->version);
        // published rules cannot change even through the model
        $this->expectException(\LogicException::class);
        CountryPackVersion::byUuid($v1)->update(['calculation_rules' => ['concepts' => []]]);
    }

    public function test_invalid_rules_cannot_be_published_and_floats_are_refused(): void
    {
        $p = $this->postJson('/api/v1/payroll/country-packs', ['code' => 'TEST-ZZ', 'country' => 'ZZ', 'name' => 'Test'])->assertCreated()->json(); $this->ctx();
        $bad = $this->postJson('/api/v1/payroll/country-packs/'.$p['uuid'].'/versions', ['effective_from' => '2026-01-01', 'metadata' => ['source' => 's', 'requires_expert_validation' => true],
            'calculation_rules' => ['concepts' => [['id' => 'x', 'kind' => 'tax', 'type' => 'rate', 'rate' => '120']]]])->assertCreated()->json('uuid'); $this->ctx();
        $this->postJson('/api/v1/payroll/country-pack-versions/'.$bad.'/publish')->assertUnprocessable(); $this->ctx();
        $this->postJson('/api/v1/payroll/country-packs/'.$p['uuid'].'/versions', ['effective_from' => '2026-01-01', 'calculation_rules' => ['concepts' => [['id' => 'x', 'kind' => 'tax', 'type' => 'rate', 'rate' => 0.5]]]])->assertUnprocessable(); $this->ctx();
        $this->postJson('/api/v1/payroll/country-pack-versions/'.$bad.'/simulate', ['currency' => 'USD', 'periods_in_year' => 12, 'amounts' => ['gross' => '1000']])->assertOk()->assertJsonPath('persisted', false);
    }
}
