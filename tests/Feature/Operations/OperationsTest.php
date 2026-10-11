<?php
namespace Tests\Feature\Operations;
use Tests\Feature\Performance\PerformanceTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};

class OperationsTest extends PerformanceTestCase {
    public function test_drafts_and_employee360_summaries_respect_employee_scope(): void {
        $p = $this->policy(['receipt_threshold' => '500.00']); $c = $this->claim($p);
        $path = '/api/v1/expenses/claims/'.$c['uuid'];
        $this->actAs($this->mgr); $this->getJson($path)->assertNotFound();
        $this->actAs($this->emp1); $this->postJson($path.'/actions', ['action' => 'submit'])->assertOk();
        $this->actAs($this->mgr);
        $provider = app(\App\Domain\Operations\OperationsEmployee360Provider::class);
        $items = $provider->sections($this->userOf($this->mgr), $this->emp1, []);
        $this->assertSame('submitted', $items[0]['items'][0]['value']);
        $this->assertStringNotContainsString('15.10', json_encode($items));
        $this->assertSame([], $provider->sections($this->userOf($this->outsider), $this->emp1, []));
        $this->assertArrayHasKey('operations', app(\App\Domain\People\Employee360\Employee360::class)->providers());
    }
    public function test_module_settings_accept_registered_modules_and_reject_unknown_modules(): void {
        $settings = ['timezone' => 'Asia/Kolkata', 'work_week' => [1, 2, 3, 4, 5], 'hr_email' => 'hr@alpha.test', 'notifications' => true,
            'modules' => \App\Domain\Access\WorkspaceProvisioner::MODULES, 'brand_name' => 'Alpha'];
        $this->putJson('/api/settings', ['name' => 'Alpha', 'settings' => $settings])->assertOk()->assertJsonPath('settings.modules', $settings['modules']);
        $settings['modules'][] = 'unsupported';
        $this->putJson('/api/settings', ['name' => 'Alpha', 'settings' => $settings])->assertUnprocessable();
    }
    public function test_exit_clearance_reads_live_asset_and_expense_obligations(): void {
        $a = $this->postJson('/api/v1/assets', ['identifier' => 'EXIT-1', 'name' => 'Laptop', 'category' => 'Computer'])->assertCreated()->json();
        $this->postJson('/api/v1/assets/'.$a['uuid'].'/actions', ['action' => 'assign', 'version' => 0, 'employee_uuid' => $this->emp1->uuid, 'reason' => 'Custody'])->assertOk();
        $p = $this->policy(['receipt_threshold' => '500.00']); $c = $this->claim($p);
        $this->postJson('/api/v1/expenses/claims/'.$c['uuid'].'/actions', ['action' => 'submit'])->assertOk();
        $this->signIn($this->admin);
        $registry = app(\App\Domain\Lifecycle\Contracts\ClearanceRegistry::class);
        $this->assertSame(['assets', 'expenses'], array_column($registry->blockers($this->emp1->id), 'provider'));
        $this->assertSame([], $registry->blockers($this->emp2->id));
        $this->postJson('/api/v1/assets/'.$a['uuid'].'/actions', ['action' => 'return', 'version' => 1, 'condition' => 'good', 'reason' => 'Returned on exit'])->assertOk();
        $this->postJson('/api/v1/expenses/claims/'.$c['uuid'].'/actions', ['action' => 'reject', 'reason' => 'Not reimbursable'])->assertOk();
        $this->signIn($this->admin);
        $this->assertSame([], $registry->blockers($this->emp1->id));
    }
    public function test_expense_approvers_cannot_review_their_own_claim_or_an_unrelated_employee(): void {
        $p = $this->policy(['receipt_threshold' => '500.00']);
        $this->actAs($this->outsider);
        $c = $this->postJson('/api/v1/expenses/claims', ['title' => 'Outside team', 'policy_uuid' => $p['uuid'],
            'lines' => [['spent_on' => '2026-10-06', 'merchant' => 'Cafe', 'description' => 'Meeting', 'amount' => '2.00']]])->assertCreated()->json();
        $this->postJson('/api/v1/expenses/claims/'.$c['uuid'].'/actions', ['action' => 'submit'])->assertOk();
        $this->actAs($this->mgr);
        $this->postJson('/api/v1/expenses/claims/'.$c['uuid'].'/actions', ['action' => 'approve', 'reason' => 'Out of scope'])->assertNotFound();
        $own = $this->postJson('/api/v1/expenses/claims', ['title' => 'Own meal', 'policy_uuid' => $p['uuid'],
            'lines' => [['spent_on' => '2026-10-06', 'merchant' => 'Cafe', 'description' => 'Meeting', 'amount' => '2.00']]])->assertCreated()->json();
        $this->postJson('/api/v1/expenses/claims/'.$own['uuid'].'/actions', ['action' => 'submit'])->assertOk();
        $this->postJson('/api/v1/expenses/claims/'.$own['uuid'].'/actions', ['action' => 'approve', 'reason' => 'Own claim'])->assertForbidden();
    }
    private function policy(array $extra = []): array {
        $this->signIn($this->admin);
        return $this->postJson('/api/v1/expenses/policies', array_replace(['name' => 'Travel meals', 'category' => 'Meals', 'currency' => 'USD', 'max_amount' => '500.00', 'receipt_threshold' => '10.00', 'effective_from' => '2026-01-01'], $extra))->assertCreated()->json();
    }
    private function claim(array $policy, string $amount = '15.10'): array {
        $this->actAs($this->emp1);
        return $this->postJson('/api/v1/expenses/claims', ['title' => 'Business meal', 'policy_uuid' => $policy['uuid'],
            'lines' => [['spent_on' => '2026-10-06', 'merchant' => 'Cafe', 'description' => 'Customer meeting', 'amount' => $amount]]])->assertCreated()->json();
    }
    private function receipt(string $uuid) {
        return $this->post('/api/v1/expenses/claims/'.$uuid.'/receipts', ['file' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\nSame receipt content\n%%EOF")])->assertCreated();
    }
    public function test_asset_custody_requires_fresh_version_and_owner_acknowledgment(): void {
        $a = $this->postJson('/api/v1/assets', ['identifier' => 'LAP-1', 'name' => 'Laptop', 'category' => 'Computer'])->assertCreated()->json();
        $path = '/api/v1/assets/'.$a['uuid'];
        $this->postJson($path.'/actions', ['action' => 'assign', 'version' => 0, 'employee_uuid' => $this->emp1->uuid, 'reason' => 'Joining kit'])->assertOk()->assertJsonPath('version', 1);
        $this->postJson($path.'/actions', ['action' => 'assign', 'version' => 0, 'employee_uuid' => $this->emp2->uuid, 'reason' => 'Stale request'])->assertConflict();
        $this->actAs($this->emp2); $this->getJson($path)->assertNotFound();
        $this->actAs($this->emp1);
        $this->postJson($path.'/actions', ['action' => 'return', 'version' => 1, 'condition' => 'good', 'reason' => 'Unauthorized inventory action'])->assertForbidden();
        $this->postJson($path.'/actions', ['action' => 'acknowledge', 'version' => 1])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('can_acknowledge', false);
        $this->assertSame(1, DB::table('work_items')->where('source_module', 'assets')->where('status', 'completed')->count());
        $this->signIn($this->admin);
        $this->postJson($path.'/actions', ['action' => 'return', 'version' => 2, 'condition' => 'damaged', 'reason' => 'Screen damaged'])->assertOk()->assertJsonPath('status', 'repair');
        $this->postJson($path.'/actions', ['action' => 'restore', 'version' => 3, 'reason' => 'Repair completed'])->assertOk()->assertJsonPath('status', 'available');
        $this->postJson($path.'/actions', ['action' => 'dispose', 'version' => 4, 'reason' => 'End of useful life'])->assertOk()->assertJsonPath('status', 'disposed');
        $this->postJson($path.'/actions', ['action' => 'assign', 'version' => 5, 'employee_uuid' => $this->emp1->uuid, 'reason' => 'Invalid reuse'])->assertUnprocessable();
        $this->getJson($path)->assertOk()->assertJsonCount(6, 'movements');
        $this->bootTenant('beta'); $this->signIn($this->admin); $this->getJson($path)->assertNotFound();
    }
    public function test_assets_transfer_preserves_history_and_limits_inventory_management(): void {
        $a = $this->postJson('/api/v1/assets', ['identifier' => 'PHONE-1', 'name' => 'Phone', 'category' => 'Phone'])->assertCreated()->json();
        $path = '/api/v1/assets/'.$a['uuid'];
        $this->postJson($path.'/actions', ['action' => 'assign', 'version' => 0, 'employee_uuid' => $this->emp1->uuid, 'reason' => 'Initial custody'])->assertOk();
        $this->postJson($path.'/actions', ['action' => 'transfer', 'version' => 1, 'employee_uuid' => $this->emp2->uuid, 'reason' => 'Transfer accepted by inventory'])->assertOk()->assertJsonPath('employee_uuid', $this->emp2->uuid);
        $this->actAs($this->emp1); $this->getJson($path)->assertNotFound();
        $this->actAs($this->emp2); $this->getJson($path)->assertOk()->assertJsonCount(1, 'movements')->assertJsonPath('movements.0.reason', null);
        $this->postJson('/api/v1/assets', ['identifier' => 'OTHER', 'name' => 'Unauthorized', 'category' => 'Phone'])->assertForbidden();
    }
    public function test_claim_requires_receipt_independent_approval_and_confirmed_payment(): void {
        Storage::fake('operations_private');
        $p = $this->policy(); $c = $this->claim($p); $path = '/api/v1/expenses/claims/'.$c['uuid'];
        $this->assertSame('15.10', $c['total']);
        $this->postJson($path.'/actions', ['action' => 'submit'])->assertUnprocessable();
        $file = $this->receipt($c['uuid'])->json('uuid');
        $this->postJson($path.'/actions', ['action' => 'submit'])->assertOk()->assertJsonPath('status', 'submitted');
        $this->postJson($path.'/actions', ['action' => 'approve', 'reason' => 'Self review'])->assertForbidden();
        $this->actAs($this->outsider); $this->getJson($path)->assertNotFound(); $this->get($path.'/receipts/'.$file)->assertNotFound();
        $this->actAs($this->mgr);
        $this->get($path.'/receipts/'.$file)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($path.'/actions', ['action' => 'approve', 'reason' => 'Business purpose verified'])->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson($path.'/actions', ['action' => 'confirm_payment', 'payment_reference' => 'AP-123'])->assertForbidden();
        $this->signIn($this->admin);
        $this->postJson($path.'/actions', ['action' => 'confirm_payment', 'payment_reference' => 'AP-123'])->assertOk()->assertJsonPath('status', 'paid');
        $this->postJson($path.'/actions', ['action' => 'confirm_payment', 'payment_reference' => 'AP-124'])->assertUnprocessable();
        $this->assertSame(0, DB::table('work_items')->where('source_module', 'expenses')->where('status', 'open')->count());
    }
    public function test_duplicate_receipts_cannot_enter_two_submitted_claims(): void {
        Storage::fake('operations_private'); $p = $this->policy();
        $first = $this->claim($p); $this->receipt($first['uuid']);
        $this->postJson('/api/v1/expenses/claims/'.$first['uuid'].'/actions', ['action' => 'submit'])->assertOk();
        $second = $this->claim($p); $this->receipt($second['uuid']);
        $this->postJson('/api/v1/expenses/claims/'.$second['uuid'].'/actions', ['action' => 'submit'])->assertUnprocessable();
        $this->getJson('/api/v1/expenses/claims/'.$second['uuid'])->assertOk()->assertJsonPath('status', 'draft');
        $this->assertSame(1, DB::table('expense_receipt_uses')->count());
    }
    public function test_money_precision_and_policy_limits_are_enforced(): void {
        $p = $this->policy(['currency' => 'JPY', 'max_amount' => '500', 'receipt_threshold' => '100']);
        $this->actAs($this->emp1);
        foreach (['0', '1.50', '501', '1e2', '-5'] as $amount) $this->postJson('/api/v1/expenses/claims', ['title' => 'Invalid claim', 'policy_uuid' => $p['uuid'],
            'lines' => [['spent_on' => '2026-10-06', 'merchant' => 'Cafe', 'description' => 'Business', 'amount' => $amount]]])->assertUnprocessable();
        $p = $this->policy(['currency' => 'KWD', 'max_amount' => '10.001', 'receipt_threshold' => '10.001']);
        $c = $this->claim($p, '0.001'); $this->assertSame('0.001', $c['total']);
        $this->bootTenant('beta'); $this->signIn($this->admin); $this->getJson('/api/v1/expenses/claims/'.$c['uuid'])->assertNotFound();
    }
    public function test_travel_approval_is_scoped_and_cannot_be_self_approved(): void {
        $this->actAs($this->mgr);
        $trip = $this->postJson('/api/v1/travel/requests', ['purpose' => 'Customer workshop', 'destination' => 'London', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-03', 'timezone' => 'Europe/London', 'currency' => 'GBP', 'budget' => '1200.50'])->assertCreated()->json();
        $path = '/api/v1/travel/requests/'.$trip['uuid'];
        $this->postJson($path.'/actions', ['action' => 'submit'])->assertOk()->assertJsonPath('status', 'submitted');
        $this->postJson($path.'/actions', ['action' => 'approve', 'reason' => 'Self approval'])->assertForbidden();
        $this->actAs($this->outsider); $this->getJson($path)->assertNotFound();
        $this->signIn($this->admin);
        $this->postJson($path.'/actions', ['action' => 'approve', 'reason' => 'Budget approved'])->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('budget', '1200.50');
        $this->postJson($path.'/actions', ['action' => 'reject', 'reason' => 'Replay'])->assertUnprocessable();
        $this->actAs($this->mgr); $this->postJson($path.'/actions', ['action' => 'cancel', 'reason' => 'Trip postponed'])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->bootTenant('beta'); $this->signIn($this->admin); $this->getJson($path)->assertNotFound();
    }
    public function test_itinerary_validates_timezone_order_versions_and_freezes_on_submission(): void {
        $this->actAs($this->emp1);
        $trip = $this->postJson('/api/v1/travel/requests', ['purpose' => 'Workshop', 'destination' => 'London', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-03', 'timezone' => 'Europe/London', 'currency' => 'GBP', 'budget' => '100'])->assertCreated()->json();
        $path = '/api/v1/travel/requests/'.$trip['uuid'];
        $leg = ['origin' => 'Chennai', 'destination' => 'London', 'departure' => '2026-11-01T09:00:00+05:30', 'arrival' => '2026-11-01T15:00:00+00:00', 'departure_timezone' => 'Asia/Kolkata', 'arrival_timezone' => 'Europe/London'];
        $this->putJson($path.'/itinerary', ['version' => 0, 'legs' => [$leg]])->assertOk()->assertJsonPath('itinerary_version', 1)->assertJsonPath('itinerary.0.origin', 'Chennai');
        $this->putJson($path.'/itinerary', ['version' => 0, 'legs' => []])->assertConflict();
        $this->putJson($path.'/itinerary', ['version' => 1, 'legs' => [array_replace($leg, ['departure' => '2026-11-01T09:00:00+04:30'])]])->assertUnprocessable();
        $this->putJson($path.'/itinerary', ['version' => 1, 'legs' => [$leg, $leg]])->assertUnprocessable();
        $this->putJson($path.'/itinerary', ['version' => 1, 'legs' => [array_replace($leg, ['arrival' => '2026-11-04T15:00:00+00:00'])]])->assertUnprocessable();
        $this->actAs($this->emp2); $this->putJson($path.'/itinerary', ['version' => 1, 'legs' => []])->assertNotFound();
        $this->actAs($this->emp1);
        $this->postJson($path.'/actions', ['action' => 'submit'])->assertOk();
        $this->putJson($path.'/itinerary', ['version' => 1, 'legs' => []])->assertUnprocessable();
        $this->actAs($this->mgr); $this->getJson($path)->assertOk()->assertJsonCount(1, 'itinerary');
        $this->bootTenant('beta'); $this->signIn($this->admin); $this->getJson($path)->assertNotFound();
    }
}
