<?php
namespace Tests\Feature\Operations;

use App\Domain\Organization\OrganizationRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Tests\Concerns\OperationsFixtures;
use Tests\Feature\Performance\PerformanceTestCase;

/** Phase 11 depth: asset import/bulk/history/depreciation, expense rule engine, FX maker-checker, travel desk and reports. */
class OperationsDepthTest extends PerformanceTestCase {
    use OperationsFixtures;

    private const SPENT = '2026-10-06';

    private function policy(array $extra = []): array {
        $this->signIn($this->admin);
        return $this->postJson('/api/v1/expenses/policies', array_replace(['name' => 'Meals', 'category' => 'Meals', 'currency' => 'USD', 'max_amount' => '5000.00', 'receipt_threshold' => '4000.00', 'effective_from' => '2026-01-01'], $extra))->assertCreated()->json();
    }
    private function rule(array $extra = []): array {
        $this->signIn($this->admin);
        return $this->postJson('/api/v1/expenses/rules', array_replace(['name' => 'Meal cap', 'category' => 'Meals', 'currency' => 'USD', 'per_line_limit' => '50.00', 'action' => 'block', 'effective_from' => '2026-01-01'], $extra))->assertCreated()->json();
    }
    private function claim(array $policy, ?array $lines = null, array $extra = []): array {
        $this->actAs($this->emp1);
        return $this->postJson('/api/v1/expenses/claims', $extra + ['title' => 'Client meal', 'policy_uuid' => $policy['uuid'],
            'lines' => $lines ?? [['spent_on' => self::SPENT, 'merchant' => 'Cafe', 'description' => 'Customer meeting', 'amount' => '40.00']]])->assertCreated()->json();
    }
    private function line(string $amount, array $extra = []): array { return ['spent_on' => self::SPENT, 'merchant' => 'Cafe', 'description' => 'Customer meeting', 'amount' => $amount] + $extra; }
    protected function submit(array|string $claim, int|float $goals = 0, int|float $comp = 0, ?string $comment = null) {
        $uuid = is_array($claim) ? $claim['uuid'] : $claim;
        return $this->postJson('/api/v1/expenses/claims/'.$uuid.'/actions', ['action' => 'submit']);
    }
    private function asset(string $id, array $extra = []): array {
        $this->signIn($this->admin);
        return $this->postJson('/api/v1/assets', ['identifier' => $id, 'name' => 'Laptop '.$id, 'category' => 'Computer'] + $extra)->assertCreated()->json();
    }

    // ---- expense policy engine ---------------------------------------------------------------------------------------------
    public function test_block_rule_stops_submission_and_warn_rule_flags_without_blocking(): void {
        $p = $this->policy(); $this->rule();
        $over = $this->claim($p, [$this->line('60.00')]);
        $this->submit($over)->assertUnprocessable()->assertJsonPath('errors.claim.0', fn ($m) => str_contains($m, 'exceeds the limit'));
        $this->getJson('/api/v1/expenses/claims/'.$over['uuid'])->assertOk()->assertJsonPath('status', 'draft')->assertJsonPath('violations.0.code', 'line_limit_exceeded')->assertJsonPath('violations.0.severity', 'block');
        $ok = $this->claim($p, [$this->line('40.00')]); $this->submit($ok)->assertOk()->assertJsonPath('status', 'submitted');

        $this->rule(['category' => 'Lodging', 'name' => 'Lodging cap', 'per_line_limit' => '10.00', 'action' => 'warn']);
        $warn = $this->claim($p, [$this->line('30.00', ['category' => 'Lodging'])]);
        $this->submit($warn)->assertOk()->assertJsonPath('violations.0.severity', 'warn');
        $this->assertSame(1, DB::table('expense_violations')->where('code', 'line_limit_exceeded')->where('severity', 'warn')->count());
    }
    public function test_rules_are_effective_dated_versioned_and_scoped_by_country_and_grade(): void {
        $p = $this->policy(); $v1 = $this->rule();
        $this->signIn($this->admin);
        $v2 = $this->postJson('/api/v1/expenses/rules', ['rule_key' => $v1['rule_key'], 'per_line_limit' => '500.00', 'action' => 'block', 'effective_from' => '2026-10-10'])->assertCreated()->json();
        $this->assertSame(2, $v2['version']); $this->assertSame('50.00', DB::table('expense_limit_rules')->where('version', 1)->value('per_line_limit_minor') ? '50.00' : 'x');
        $this->submit($this->claim($p, [$this->line('60.00')]))->assertUnprocessable();            // v1 still governs 6 Oct
        $this->actAs($this->emp1); $this->assertSame(1, DB::table('expense_limit_rules')->where('rule_key', $v1['rule_key'])->where('version', 1)->count());
        $this->rule(['name' => 'UK only', 'category' => 'Taxi', 'country' => 'GB', 'per_line_limit' => '1.00']);
        $this->submit($this->claim($p, [$this->line('30.00', ['category' => 'Taxi'])]))->assertOk();   // GB rule does not apply to an IN employee
        $this->context($this->admin->tenant_id);
        $g1 = OrganizationRecord::forType('grades')->newQuery()->create(['name' => 'G1', 'code' => 'G1', 'active' => true, 'metadata' => []])->id;
        $g2 = OrganizationRecord::forType('grades')->newQuery()->create(['name' => 'G2', 'code' => 'G2', 'active' => true, 'metadata' => []])->id;
        DB::table('employee_assignments')->where('employee_id', $this->emp1->id)->update(['grade_id' => $g1]);
        $this->rule(['name' => 'G2 books', 'category' => 'Books', 'grade_id' => $g2, 'per_line_limit' => '1.00']);
        $this->rule(['name' => 'G1 books', 'category' => 'Books', 'grade_id' => $g1, 'per_line_limit' => '100.00']);
        $this->submit($this->claim($p, [$this->line('30.00', ['category' => 'Books'])]))->assertOk();
        $this->signIn($this->admin);
        $this->postJson('/api/v1/expenses/rules', ['name' => 'Bad', 'category' => 'X', 'currency' => 'USD', 'action' => 'block', 'effective_from' => '2026-01-01'])->assertUnprocessable();
        $this->postJson('/api/v1/expenses/rules', ['name' => 'Foreign grade', 'category' => 'X', 'currency' => 'USD', 'per_line_limit' => '5', 'grade_id' => 999999, 'action' => 'warn', 'effective_from' => '2026-01-01'])->assertUnprocessable();
    }
    public function test_escalated_claims_need_a_tenant_wide_finance_approver(): void {
        $p = $this->policy(); $this->rule(['action' => 'escalate']);
        $c = $this->claim($p, [$this->line('60.00')]); $this->submit($c)->assertOk()->assertJsonPath('escalated', true);
        $path = '/api/v1/expenses/claims/'.$c['uuid'].'/actions';
        $this->actAs($this->mgr); $this->postJson($path, ['action' => 'approve', 'reason' => 'Looks fine'])->assertForbidden();
        $this->getJson('/api/v1/expenses/claims/'.$c['uuid'])->assertOk()->assertJsonPath('can_review', false);
        $this->signIn($this->admin); $this->postJson($path, ['action' => 'approve', 'reason' => 'Finance approved'])->assertOk()->assertJsonPath('status', 'approved');
        $this->signIn($this->admin);
        $kinds = collect(app(\App\Domain\Operations\OperationsInsightProvider::class)->insights($this->admin))->pluck('key')->all();
        $this->assertNotContains('operations.escalated_claims', $kinds);
    }
    public function test_receipt_rules_apply_per_line_and_lines_can_link_receipts(): void {
        Storage::fake('operations_private');
        $p = $this->policy(); $this->rule(['receipt_over' => '20.00', 'per_line_limit' => null, 'action' => 'warn']);
        $c = $this->claim($p, [$this->line('25.00')]); $path = '/api/v1/expenses/claims/'.$c['uuid'];
        $this->getJson($path)->assertJsonPath('violations.0.code', 'receipt_missing');
        $receipt = $this->post($path.'/receipts', ['file' => UploadedFile::fake()->createWithContent('r.pdf', "%PDF-1.4\nline receipt\n%%EOF")])->assertCreated()->json('uuid');
        $this->putJson($path, ['lines' => [$this->line('25.00', ['receipt_uuid' => $receipt])]])->assertOk()->assertJsonPath('lines.0.receipt_uuid', $receipt)->assertJsonCount(0, 'violations');
        $this->putJson($path, ['lines' => [$this->line('25.00', ['receipt_uuid' => '00000000-0000-4000-8000-000000000000'])]])->assertUnprocessable();
    }
    public function test_mileage_and_per_diem_use_effective_rate_tables_with_decimal_rounding(): void {
        $p = $this->policy();
        $this->signIn($this->admin);
        $rate = $this->postJson('/api/v1/expenses/rates', ['kind' => 'mileage', 'name' => 'Car', 'currency' => 'USD', 'unit' => 'km', 'rate' => '0.655000', 'effective_from' => '2026-01-01'])->assertCreated()->json();
        $this->postJson('/api/v1/expenses/rates', ['rate_key' => $rate['rate_key'], 'rate' => '0.700000', 'effective_from' => '2026-10-10'])->assertCreated()->assertJsonPath('version', 2);
        $this->postJson('/api/v1/expenses/rates', ['kind' => 'per_diem', 'name' => 'Daily', 'currency' => 'USD', 'unit' => 'day', 'rate' => '45.50', 'effective_from' => '2026-01-01'])->assertCreated();
        $this->postJson('/api/v1/expenses/rates', ['kind' => 'mileage', 'name' => 'Bad', 'currency' => 'USD', 'unit' => 'day', 'rate' => '1', 'effective_from' => '2026-01-01'])->assertUnprocessable();
        $c = $this->claim($p, [['spent_on' => self::SPENT, 'merchant' => 'Own car', 'description' => 'Client visit', 'kind' => 'mileage', 'quantity' => '100.50'],
            ['spent_on' => self::SPENT, 'merchant' => 'Per diem', 'description' => 'Two days', 'kind' => 'per_diem', 'quantity' => '2']]);
        $this->assertSame('65.83', $c['lines'][0]['amount']); $this->assertSame('91.00', $c['lines'][1]['amount']); $this->assertSame('156.83', $c['total']);
        $this->postJson('/api/v1/expenses/claims', ['title' => 'No rate', 'policy_uuid' => $p['uuid'], 'lines' => [['spent_on' => self::SPENT, 'merchant' => 'x', 'description' => 'y', 'kind' => 'mileage', 'quantity' => '1e3']]])->assertUnprocessable();
    }
    public function test_cost_center_allocations_must_add_up_and_edit_withdraw_work_before_approval(): void {
        Storage::fake('operations_private');
        $this->signIn($this->admin); $cc1 = OrganizationRecord::forType('cost-centers')->newQuery()->create(['name' => 'CC1', 'code' => 'CC1', 'active' => true, 'metadata' => []])->id;
        $cc2 = OrganizationRecord::forType('cost-centers')->newQuery()->create(['name' => 'CC2', 'code' => 'CC2', 'active' => true, 'metadata' => []])->id;
        $p = $this->policy(); $this->actAs($this->emp1);
        $bad = $this->line('10.00', ['allocations' => [['cost_center_id' => $cc1, 'amount' => '4.00'], ['cost_center_id' => $cc2, 'amount' => '5.00']]]);
        $this->postJson('/api/v1/expenses/claims', ['title' => 'Split', 'policy_uuid' => $p['uuid'], 'lines' => [$bad]])->assertUnprocessable();
        $good = $this->line('10.00', ['allocations' => [['cost_center_id' => $cc1, 'amount' => '4.00'], ['cost_center_id' => $cc2, 'amount' => '6.00']]]);
        $c = $this->postJson('/api/v1/expenses/claims', ['title' => 'Split', 'policy_uuid' => $p['uuid'], 'lines' => [$good]])->assertCreated()->assertJsonCount(2, 'lines.0.allocations')->json();
        $this->postJson('/api/v1/expenses/claims', ['title' => 'Other tenant cc', 'policy_uuid' => $p['uuid'], 'lines' => [$this->line('10.00', ['allocations' => [['cost_center_id' => 999999, 'amount' => '10.00']]])]])->assertUnprocessable();
        $path = '/api/v1/expenses/claims/'.$c['uuid'];
        $this->putJson($path, ['title' => 'Split v2', 'lines' => [$this->line('12.00')]])->assertOk()->assertJsonPath('total', '12.00')->assertJsonPath('title', 'Split v2');
        $this->actAs($this->emp2); $this->putJson($path, ['lines' => [$this->line('1.00')]])->assertNotFound();
        $this->actAs($this->emp1); $this->postJson($path.'/actions', ['action' => 'withdraw'])->assertUnprocessable();   // draft cannot be withdrawn
        $this->submit($c)->assertOk(); $this->putJson($path, ['lines' => [$this->line('1.00')]])->assertUnprocessable();
        $this->postJson($path.'/actions', ['action' => 'withdraw'])->assertOk()->assertJsonPath('status', 'draft');
        $this->assertSame(0, DB::table('work_items')->where('source_module', 'expenses')->where('status', 'open')->count());
        $this->putJson($path, ['lines' => [$this->line('13.00')]])->assertOk();
        $this->submit($c)->assertOk(); $this->actAs($this->mgr);
        $this->postJson($path.'/actions', ['action' => 'approve', 'reason' => 'ok'])->assertOk();
        $this->actAs($this->emp1); $this->postJson($path.'/actions', ['action' => 'withdraw'])->assertUnprocessable();   // no withdrawal after approval
    }

    // ---- FX ----------------------------------------------------------------------------------------------------------------
    public function test_fx_rates_record_source_and_overrides_need_independent_approval(): void {
        $p = $this->policy(); $this->signIn($this->admin);
        $this->postJson('/api/v1/expenses/fx-rates', ['base_currency' => 'EUR', 'quote_currency' => 'USD', 'rate' => '1.08', 'effective_on' => '2026-10-01', 'source' => 'invented'])->assertUnprocessable();
        $this->postJson('/api/v1/expenses/fx-rates', ['base_currency' => 'EUR', 'quote_currency' => 'USD', 'rate' => '1.08', 'effective_on' => '2026-10-01', 'source' => 'central_bank', 'source_reference' => 'ECB 2026-10-01'])->assertCreated()->assertJsonPath('source', 'central_bank');
        $this->postJson('/api/v1/expenses/fx-rates', ['base_currency' => 'EUR', 'quote_currency' => 'EUR', 'rate' => '1', 'effective_on' => '2026-10-01', 'source' => 'manual'])->assertUnprocessable();
        $c = $this->claim($p, [$this->line('100.00', ['currency' => 'EUR'])]);
        $this->assertSame('108.00', $c['total']); $this->assertSame('100.00', $c['lines'][0]['original_amount']);
        $this->postJson('/api/v1/expenses/claims', ['title' => 'No rate', 'policy_uuid' => $p['uuid'], 'lines' => [$this->line('10', ['currency' => 'GBP'])]])->assertUnprocessable();

        $maker = $this->roleUser(['expenses.fx.manage', 'expenses.manage']); $this->signIn($maker);
        $ov = $this->postJson('/api/v1/expenses/fx-rates/overrides', ['claim_uuid' => $c['uuid'], 'base_currency' => 'EUR', 'quote_currency' => 'USD', 'rate' => '1.10', 'effective_on' => '2026-10-06', 'reason' => 'Actual card statement rate'])->assertCreated()->assertJsonPath('status', 'pending')->json();
        $decision = '/api/v1/expenses/fx-rates/'.$ov['uuid'].'/decision';
        $this->postJson($decision, ['decision' => 'approve', 'reason' => 'self'])->assertForbidden();                    // maker lacks the approval permission
        $both = $this->roleUser(['expenses.fx.manage', 'expenses.fx.approve']); $this->signIn($both);
        $own = $this->postJson('/api/v1/expenses/fx-rates/overrides', ['claim_uuid' => $c['uuid'], 'base_currency' => 'EUR', 'quote_currency' => 'USD', 'rate' => '1.12', 'effective_on' => '2026-10-06', 'reason' => 'Second proposal'])->assertCreated()->json();
        $this->postJson('/api/v1/expenses/fx-rates/'.$own['uuid'].'/decision', ['decision' => 'approve', 'reason' => 'my own'])->assertForbidden();   // requester can never approve their own override
        $this->assertSame(1, DB::table('work_items')->where('source_module', 'expenses')->where('source_entity', 'fx:'.$ov['uuid'])->where('status', 'open')->count() > 0 ? 1 : 0);
        $this->actAs($this->emp1); $this->submit($c)->assertOk()->assertJsonPath('total', '108.00');   // pending overrides are never used
        $this->actAs($this->emp1); $this->postJson('/api/v1/expenses/fx-rates/'.$ov['uuid'].'/decision', ['decision' => 'approve', 'reason' => 'x'])->assertForbidden();
        $this->signIn($both); $this->postJson($decision, ['decision' => 'approve', 'reason' => 'Matches statement'])->assertOk()->assertJsonPath('status', 'active');
        $this->postJson($decision, ['decision' => 'approve', 'reason' => 'again'])->assertUnprocessable();
        $this->signIn($this->checker); $this->postJson('/api/v1/expenses/fx-rates/'.$own['uuid'].'/decision', ['decision' => 'reject', 'reason' => 'Not supported'])->assertOk()->assertJsonPath('status', 'rejected');
    }
    public function test_approved_override_prices_the_claim_at_submission(): void {
        $p = $this->policy(); $this->signIn($this->admin);
        $this->postJson('/api/v1/expenses/fx-rates', ['base_currency' => 'EUR', 'quote_currency' => 'USD', 'rate' => '1.08', 'effective_on' => '2026-10-01', 'source' => 'provider'])->assertCreated();
        $c = $this->claim($p, [$this->line('100.00', ['currency' => 'EUR'])]);
        $this->signIn($this->admin);
        $ov = $this->postJson('/api/v1/expenses/fx-rates/overrides', ['claim_uuid' => $c['uuid'], 'base_currency' => 'EUR', 'quote_currency' => 'USD', 'rate' => '1.10', 'effective_on' => '2026-10-06', 'reason' => 'Bank statement rate'])->assertCreated()->json();
        $this->signIn($this->checker); $this->postJson('/api/v1/expenses/fx-rates/'.$ov['uuid'].'/decision', ['decision' => 'approve', 'reason' => 'Verified'])->assertOk();
        $this->actAs($this->emp1); $this->submit($c)->assertOk()->assertJsonPath('total', '110.00')->assertJsonPath('lines.0.fx_rate', '1.10000000');
    }

    // ---- assets ------------------------------------------------------------------------------------------------------------
    public function test_asset_import_previews_then_commits_only_valid_rows_once(): void {
        $this->opsSetUp(); $this->asset('EXIST-1');
        $rows = [['identifier', 'name', 'category', 'serial_number', 'purchase_cost', 'currency', 'purchased_on', 'useful_life_months', 'warranty_until'],
            ['NEW-1', 'Laptop A', 'Computer', 'SN-1', '1200.50', 'USD', '2026-01-15', '36', '2029-01-15'], ['EXIST-1', 'Dup', 'Computer', '', '', '', '', '', ''],
            ['NEW-2', 'Phone', 'Phone', 'SN-2', '', '', '', '', 'not-a-date'], ['NEW-3', 'Monitor', 'Display', 'SN-1', '', '', '', '', ''], ['NEW-4', 'Tablet', 'Tablet', '', '', '', '', '', '']];
        $this->signIn($this->admin);
        $res = $this->post('/api/v1/assets/imports', ['file' => $this->csvFile($rows, 'assets.csv')], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('summary.total', 5)->assertJsonPath('summary.valid', 2)->assertJsonPath('summary.invalid', 3)->json();
        $this->assertSame(1, DB::table('assets')->count());                                                       // preview changes nothing
        $this->postJson('/api/v1/assets/imports/'.$res['uuid'].'/commit')->assertUnprocessable();
        $this->postJson('/api/v1/assets/imports/'.$res['uuid'].'/commit', ['skip_invalid' => true])->assertOk()->assertJsonPath('summary.created', 2)->assertJsonPath('summary.skipped', 3);
        $this->assertSame(3, DB::table('assets')->count()); $this->assertSame(1, DB::table('asset_procurement')->count());
        $this->postJson('/api/v1/assets/imports/'.$res['uuid'].'/commit', ['skip_invalid' => true])->assertUnprocessable();   // replay is refused
        $this->actAs($this->emp1); $this->post('/api/v1/assets/imports', ['file' => $this->csvFile($rows)], ['Accept' => 'application/json'])->assertForbidden();
        $this->getJson('/api/v1/assets/imports/'.$res['uuid'])->assertForbidden();
        $this->bootTenant('beta'); $this->signIn($this->admin); $this->getJson('/api/v1/assets/imports/'.$res['uuid'])->assertNotFound();
        $this->postJson('/api/v1/assets/imports/'.$res['uuid'].'/commit')->assertNotFound();
        $this->post('/api/v1/assets/imports', ['file' => $this->csvFile([['name'], ['x']])], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->opsTearDown();
    }
    public function test_bulk_assign_previews_rows_and_is_all_or_nothing_on_commit(): void {
        $a = $this->asset('B-1'); $b = $this->asset('B-2'); $this->asset('B-3');
        $this->postJson('/api/v1/assets/'.$b['uuid'].'/actions', ['action' => 'assign', 'version' => 0, 'employee_uuid' => $this->emp2->uuid, 'reason' => 'Initial'])->assertOk();
        $bad = $this->postJson('/api/v1/assets/bulk/preview', ['action' => 'assign', 'employee_uuid' => $this->emp1->uuid, 'reason' => 'Onboarding', 'items' => [['identifier' => 'B-1'], ['identifier' => 'B-2'], ['identifier' => 'NOPE']]])->assertCreated()->assertJsonPath('summary.invalid', 2)->json();
        $this->postJson('/api/v1/assets/bulk/'.$bad['uuid'].'/commit')->assertUnprocessable();
        $ok = $this->postJson('/api/v1/assets/bulk/preview', ['action' => 'assign', 'employee_uuid' => $this->emp1->uuid, 'reason' => 'Onboarding', 'items' => [['identifier' => 'B-1'], ['identifier' => 'B-3']]])->assertCreated()->assertJsonPath('summary.invalid', 0)->json();
        $this->assertSame(0, DB::table('assets')->where('status', 'assigned')->where('employee_id', $this->emp1->id)->count());
        $this->postJson('/api/v1/assets/'.$a['uuid'].'/actions', ['action' => 'lost', 'version' => 0, 'reason' => 'Drifted after preview'])->assertOk();
        $this->postJson('/api/v1/assets/bulk/'.$ok['uuid'].'/commit')->assertConflict();   // B-1 is no longer available: nothing applied
        $this->assertSame(0, DB::table('assets')->where('employee_id', $this->emp1->id)->count());
        $fresh = $this->postJson('/api/v1/assets/bulk/preview', ['action' => 'transfer', 'employee_uuid' => $this->emp1->uuid, 'reason' => 'Handover', 'items' => [['identifier' => 'B-2']]])->assertCreated()->json();
        $this->postJson('/api/v1/assets/'.$b['uuid'].'/actions', ['action' => 'acknowledge', 'version' => 1])->assertStatus(403);
        $this->postJson('/api/v1/assets/bulk/'.$fresh['uuid'].'/commit')->assertOk()->assertJsonPath('summary.applied', 1);
        $this->assertSame($this->emp1->id, (int) DB::table('assets')->where('identifier', 'B-2')->value('employee_id'));
        $this->postJson('/api/v1/assets/bulk/'.$fresh['uuid'].'/commit')->assertUnprocessable();
        $stale = $this->postJson('/api/v1/assets/bulk/preview', ['action' => 'return', 'condition' => 'good', 'reason' => 'Return', 'items' => [['identifier' => 'B-2']]])->assertCreated()->json();
        $this->postJson('/api/v1/assets/'.$b['uuid'].'/actions', ['action' => 'transfer', 'version' => 2, 'employee_uuid' => $this->emp2->uuid, 'reason' => 'Moved'])->assertOk();
        $this->postJson('/api/v1/assets/bulk/'.$stale['uuid'].'/commit')->assertConflict();
        $this->actAs($this->emp1); $this->postJson('/api/v1/assets/bulk/preview', ['action' => 'return', 'condition' => 'good', 'reason' => 'x', 'items' => [['identifier' => 'B-2']]])->assertForbidden();
    }
    public function test_maintenance_procurement_history_and_straight_line_depreciation(): void {
        $a = $this->asset('D-1'); $path = '/api/v1/assets/'.$a['uuid'];
        $this->postJson($path.'/maintenance', ['kind' => 'repair', 'vendor' => 'FixIt', 'cost' => '80.25', 'currency' => 'USD', 'started_on' => '2026-05-01', 'completed_on' => '2026-05-03', 'notes' => 'Screen'])->assertCreated()->assertJsonPath('maintenance.0.cost', '80.25');
        $this->postJson($path.'/maintenance', ['kind' => 'repair', 'started_on' => '2026-05-05', 'completed_on' => '2026-05-01'])->assertUnprocessable();
        $this->postJson($path.'/procurement', ['kind' => 'purchase', 'vendor' => 'Dell', 'reference' => 'PO-9', 'purchased_on' => '2026-01-15', 'cost' => '1000.00', 'currency' => 'USD', 'salvage' => '100.00', 'useful_life_months' => 36, 'warranty_until' => '2027-01-15'])->assertCreated();
        $this->postJson($path.'/procurement', ['kind' => 'warranty_extension', 'warranty_until' => '2028-01-15'])->assertCreated()->assertJsonCount(2, 'procurement');
        $this->assertSame('2028-01-15', DB::table('assets')->where('identifier', 'D-1')->value('warranty_until'));
        $this->postJson($path.'/procurement', ['kind' => 'purchase', 'vendor' => 'No cost'])->assertUnprocessable();
        $d = $this->getJson('/api/v1/assets/reports/depreciation?as_of=2026-07-14')->assertOk()->json();
        $this->assertSame('5', (string) $d['rows'][0]['months_elapsed']); $this->assertSame('125.00', $d['rows'][0]['accumulated']); $this->assertSame('875.00', $d['rows'][0]['book_value']);
        $d = $this->getJson('/api/v1/assets/reports/depreciation?as_of=2040-01-01')->assertOk()->json();
        $this->assertSame('900.00', $d['rows'][0]['accumulated']); $this->assertSame('100.00', $d['rows'][0]['book_value']); $this->assertTrue($d['rows'][0]['fully_depreciated']);
        $this->assertSame(0, \App\Domain\Assets\AssetHistoryService::wholeMonths('2026-03-31', '2026-03-30'));
        $this->assertSame(['accumulated' => 14286, 'book_value' => 85714, 'months' => 1], \App\Domain\Assets\AssetHistoryService::straightLine(100000, 0, 7, 1));   // 142.857 rounds half-up to 142.86
        $this->actAs($this->emp1); $this->getJson($path.'/history')->assertForbidden(); $this->getJson('/api/v1/assets/reports/depreciation')->assertForbidden();
        $this->bootTenant('beta'); $this->signIn($this->admin); $this->getJson($path.'/history')->assertNotFound();
    }

    // ---- travel ------------------------------------------------------------------------------------------------------------
    private function approvedTrip(array $extra = []): array {
        $this->actAs($this->emp1);
        $trip = $this->postJson('/api/v1/travel/requests', $extra + ['purpose' => 'Customer workshop', 'destination' => 'London', 'starts_on' => '2026-08-03', 'ends_on' => '2026-08-05', 'timezone' => 'Europe/London', 'currency' => 'USD', 'budget' => '1000.00'])->assertCreated()->json();
        $this->postJson('/api/v1/travel/requests/'.$trip['uuid'].'/actions', ['action' => 'submit'])->assertOk();
        $this->actAs($this->mgr); $this->postJson('/api/v1/travel/requests/'.$trip['uuid'].'/actions', ['action' => 'approve', 'reason' => 'Approved'])->assertOk();
        return $trip;
    }
    public function test_travel_advances_are_independent_references_only_and_bookings_are_recorded(): void {
        $trip = $this->approvedTrip(); $base = '/api/v1/travel/requests/'.$trip['uuid'];
        $this->actAs($this->emp1);
        $this->postJson($base.'/advances', ['amount' => '1500.00'])->assertUnprocessable();                       // exceeds budget
        $adv = $this->postJson($base.'/advances', ['amount' => '200.00', 'purpose' => 'Per diems'])->assertCreated()->assertJsonPath('status', 'requested')->json();
        $this->postJson('/api/v1/travel/advances/'.$adv['uuid'].'/decision', ['decision' => 'approve', 'reason' => 'Self'])->assertForbidden();
        $this->actAs($this->emp2); $this->postJson('/api/v1/travel/advances/'.$adv['uuid'].'/decision', ['decision' => 'approve', 'reason' => 'Peer'])->assertForbidden();
        $this->actAs($this->outsider); $this->getJson($base.'/desk')->assertNotFound();
        $this->actAs($this->mgr); $this->postJson('/api/v1/travel/advances/'.$adv['uuid'].'/decision', ['decision' => 'approve', 'reason' => 'Within budget'])->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson('/api/v1/travel/advances/'.$adv['uuid'].'/reference', ['reference_type' => 'finance', 'reference' => 'FIN-1'])->assertForbidden();
        $this->actAs($this->emp1); $this->postJson('/api/v1/travel/advances/'.$adv['uuid'].'/reference', ['reference_type' => 'finance', 'reference' => 'FIN-1'])->assertForbidden();
        $this->signIn($this->admin);
        $this->postJson('/api/v1/travel/advances/'.$adv['uuid'].'/reference', ['reference_type' => 'payroll', 'reference' => 'PAY-7'])->assertOk()->assertJsonPath('finance_reference', 'PAY-7')->assertJsonPath('note', fn ($n) => str_contains($n, 'Payment happens'));
        $this->postJson('/api/v1/travel/advances/'.$adv['uuid'].'/reference', ['reference_type' => 'payroll', 'reference' => 'PAY-8'])->assertUnprocessable();
        $this->actAs($this->emp1); $second = $this->postJson($base.'/advances', ['amount' => '100.00'])->assertCreated()->json();
        $this->signIn($this->admin); $this->postJson('/api/v1/travel/advances/'.$second['uuid'].'/reference', ['reference_type' => 'payroll', 'reference' => 'PAY-7'])->assertUnprocessable();   // not approved yet
        $this->actAs($this->emp1); $this->postJson('/api/v1/travel/advances/'.$second['uuid'].'/cancel')->assertOk()->assertJsonPath('status', 'cancelled');
        $b = $this->postJson($base.'/bookings', ['kind' => 'flight', 'vendor' => 'AirX', 'confirmation_reference' => 'ABC123', 'starts_on' => '2026-08-03', 'ends_on' => '2026-08-05', 'currency' => 'USD', 'cost' => '450.00'])->assertCreated()->json();
        $this->postJson('/api/v1/travel/bookings/'.$b['uuid'].'/cancel')->assertOk()->assertJsonPath('status', 'cancelled');
        $this->getJson($base.'/desk')->assertOk()->assertJsonCount(2, 'advances')->assertJsonCount(1, 'bookings');
        $this->actAs($this->emp2); $this->postJson($base.'/bookings', ['kind' => 'hotel', 'vendor' => 'H', 'starts_on' => '2026-08-03', 'currency' => 'USD'])->assertNotFound();
        $this->bootTenant('beta'); $this->signIn($this->admin); $this->getJson($base.'/desk')->assertNotFound();
    }
    public function test_trip_expenses_link_to_approved_trips_and_reconciliation_settles_exactly(): void {
        $draft = $this->postJson('/api/v1/travel/requests', ['purpose' => 'x', 'destination' => 'Paris', 'starts_on' => '2026-08-03', 'ends_on' => '2026-08-05', 'timezone' => 'Europe/Paris', 'currency' => 'USD', 'budget' => '10'] )->status();
        $trip = $this->approvedTrip(); $base = '/api/v1/travel/requests/'.$trip['uuid']; $p = $this->policy();
        $this->actAs($this->emp2); $this->postJson('/api/v1/expenses/claims', ['title' => 'Not mine', 'policy_uuid' => $p['uuid'], 'travel_request_uuid' => $trip['uuid'], 'lines' => [$this->line('5.00', ['spent_on' => '2026-08-04'])]])->assertNotFound();
        $claim = $this->claim($p, [$this->line('300.00', ['spent_on' => '2026-08-04'])], ['title' => 'Trip costs', 'travel_request_uuid' => $trip['uuid']]);
        $outside = $this->claim($p, [$this->line('10.00')], ['title' => 'Late receipt', 'travel_request_uuid' => $trip['uuid']]);
        $this->getJson('/api/v1/expenses/claims/'.$outside['uuid'])->assertJsonPath('violations.0.code', 'outside_trip_dates');
        $this->postJson('/api/v1/expenses/claims/'.$outside['uuid'].'/actions', ['action' => 'submit'])->assertOk();
        $adv = $this->postJson($base.'/advances', ['amount' => '200.00'])->assertCreated()->json();
        $this->actAs($this->mgr); $this->postJson('/api/v1/travel/advances/'.$adv['uuid'].'/decision', ['decision' => 'approve', 'reason' => 'okay'])->assertOk();
        $this->signIn($this->admin);
        $this->getJson($base.'/reconciliation')->assertOk()->assertJsonPath('can_close', false)->assertJsonCount(3, 'blockers');   // 2 claim blockers + advance reference
        $this->postJson($base.'/reconciliation')->assertUnprocessable();
        $this->postJson('/api/v1/travel/advances/'.$adv['uuid'].'/reference', ['reference_type' => 'finance', 'reference' => 'FIN-9'])->assertOk();
        $this->actAs($this->emp1); $this->postJson('/api/v1/expenses/claims/'.$claim['uuid'].'/actions', ['action' => 'submit'])->assertOk();
        $this->postJson('/api/v1/expenses/claims/'.$outside['uuid'].'/actions', ['action' => 'withdraw'])->assertOk();
        $this->actAs($this->mgr); $this->postJson('/api/v1/expenses/claims/'.$claim['uuid'].'/actions', ['action' => 'approve', 'reason' => 'ok'])->assertOk();
        $this->actAs($this->emp1); $this->postJson($base.'/reconciliation')->assertForbidden();
        $this->actAs($this->mgr); $this->postJson($base.'/reconciliation')->assertForbidden();
        $this->signIn($this->admin);
        $this->getJson($base.'/reconciliation')->assertJsonPath('blockers.0', fn ($m) => str_contains($m, 'Late receipt'));
        $this->actAs($this->emp1); $this->postJson('/api/v1/expenses/claims/'.$outside['uuid'].'/actions', ['action' => 'submit'])->assertOk();
        $this->actAs($this->mgr); $this->postJson('/api/v1/expenses/claims/'.$outside['uuid'].'/actions', ['action' => 'reject', 'reason' => 'Outside trip'])->assertOk();
        $this->signIn($this->admin);
        $this->postJson($base.'/reconciliation', ['note' => 'Closed'])->assertCreated()->assertJsonPath('status', 'closed')->assertJsonPath('actual', '300.00')->assertJsonPath('advance', '200.00')->assertJsonPath('variance', '700.00')
            ->assertJsonPath('settlement', '100.00')->assertJsonPath('settlement_direction', 'company_owes_employee');
        $this->postJson($base.'/reconciliation')->assertUnprocessable();
        $this->assertSame(1, DB::table('travel_reconciliations')->count());
        $this->actAs($this->emp1); $this->postJson('/api/v1/expenses/claims', ['title' => 'Linked later', 'policy_uuid' => $p['uuid'], 'travel_request_uuid' => $draft ? $trip['uuid'] : '', 'lines' => [$this->line('1.00')]])->assertUnprocessable();
        $future = $this->postJson('/api/v1/travel/requests', ['purpose' => 'Later', 'destination' => 'Oslo', 'starts_on' => '2026-08-03', 'ends_on' => '2026-08-04', 'timezone' => 'Europe/Oslo', 'currency' => 'USD', 'budget' => '10'])->json();
        $this->postJson('/api/v1/expenses/claims', ['title' => 'Unapproved trip', 'policy_uuid' => $p['uuid'], 'travel_request_uuid' => $future['uuid'], 'lines' => [$this->line('1.00')]])->assertUnprocessable();
    }

    // ---- reports, insights, tenant isolation ------------------------------------------------------------------------------
    public function test_reports_are_permission_gated_scoped_masked_and_purpose_bound(): void {
        $p = $this->policy(); $this->rule(['action' => 'warn', 'per_line_limit' => '10.00']);
        $c = $this->claim($p, [$this->line('40.00')]); $this->submit($c)->assertOk();
        $this->actAs($this->mgr); $this->postJson('/api/v1/expenses/claims/'.$c['uuid'].'/actions', ['action' => 'approve', 'reason' => 'ok'])->assertOk();
        $this->asset('R-1'); $this->signIn($this->admin);
        $this->getJson('/api/v1/operations/reports/spend?from=2026-10-01&to=2026-10-31&group=category')->assertOk()->assertJsonPath('rows.0.category', 'Meals')->assertJsonPath('rows.0.total', '40.00')->assertJsonPath('rows.0.currency', 'USD');
        $this->getJson('/api/v1/operations/reports/spend?from=2026-10-01&to=2026-10-31&group=department')->assertOk()->assertJsonPath('rows.0.department', 'Engineering');
        $this->getJson('/api/v1/operations/reports/violations?from=2026-10-01&to=2099-12-31')->assertOk()->assertJsonPath('rows.0.employee', 'Arun Kumar');
        $this->getJson('/api/v1/operations/reports/asset-utilization')->assertOk()->assertJsonPath('rows.0.category', 'Computer');
        $this->getJson('/api/v1/operations/reports/nonsense')->assertNotFound();
        $this->getJson('/api/v1/operations/reports/spend?from=2026-11-01&to=2026-10-01')->assertUnprocessable();
        $this->get('/api/v1/operations/reports/violations/export?from=2026-10-01&to=2099-12-31')->assertStatus(422);
        $res = $this->get('/api/v1/operations/reports/violations/export?from=2026-10-01&to=2099-12-31&purpose=Quarterly+policy+review', ['Accept' => 'text/csv'])->assertOk();
        $csv = file_get_contents($res->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('A. K.', $csv); $this->assertStringNotContainsString('Arun', $csv); $this->assertStringNotContainsString('Customer', $csv);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'operations.report_exported')->count());
        $viewOnly = $this->roleUser(['operations.reports.view']); $this->signIn($viewOnly);
        $this->getJson('/api/v1/operations/reports/asset-utilization')->assertForbidden();
        $this->get('/api/v1/operations/reports/spend/export?purpose=Quarterly+review+now')->assertForbidden();
        $this->getJson('/api/v1/operations/reports/spend?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonCount(1, 'rows');
        $this->actAs($this->emp1); $this->getJson('/api/v1/operations/reports/spend')->assertForbidden();
        $this->bootTenant('beta'); $this->signIn($this->admin);
        $this->getJson('/api/v1/operations/reports/spend?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonCount(0, 'rows');
    }
    public function test_rules_fx_and_rates_are_tenant_isolated_and_permission_checked(): void {
        $p = $this->policy(); $r = $this->rule();
        $this->signIn($this->admin); $fx = $this->postJson('/api/v1/expenses/fx-rates', ['base_currency' => 'EUR', 'quote_currency' => 'USD', 'rate' => '1.08', 'effective_on' => '2026-10-01', 'source' => 'manual'])->assertCreated()->json();
        $this->actAs($this->emp1);
        $this->postJson('/api/v1/expenses/rules', ['name' => 'x', 'category' => 'Meals', 'currency' => 'USD', 'per_line_limit' => '1', 'action' => 'warn', 'effective_from' => '2026-01-01'])->assertForbidden();
        $this->postJson('/api/v1/expenses/rates', ['kind' => 'mileage', 'name' => 'x', 'currency' => 'USD', 'unit' => 'km', 'rate' => '1', 'effective_from' => '2026-01-01'])->assertForbidden();
        $this->postJson('/api/v1/expenses/fx-rates', ['base_currency' => 'EUR', 'quote_currency' => 'USD', 'rate' => '1', 'effective_on' => '2026-10-01', 'source' => 'manual'])->assertForbidden();
        $this->getJson('/api/v1/expenses/fx-rates')->assertForbidden();
        $this->getJson('/api/v1/expenses/rules')->assertOk()->assertJsonCount(1);
        $this->bootTenant('beta'); $this->signIn($this->admin);
        $this->getJson('/api/v1/expenses/rules')->assertOk()->assertJsonCount(0); $this->getJson('/api/v1/expenses/fx-rates')->assertOk()->assertJsonCount(0);
        $this->postJson('/api/v1/expenses/rules', ['rule_key' => $r['rule_key'], 'per_line_limit' => '1', 'action' => 'warn', 'effective_from' => '2026-12-01'])->assertNotFound();
        $this->postJson('/api/v1/expenses/fx-rates/'.$fx['uuid'].'/decision', ['decision' => 'approve', 'reason' => 'nope'])->assertNotFound();
    }
    public function test_insights_and_quality_rules_report_open_operations_work(): void {
        $this->signIn($this->admin); $this->asset('Q-1');
        $a = $this->asset('Q-2'); $this->postJson('/api/v1/assets/'.$a['uuid'].'/actions', ['action' => 'assign', 'version' => 0, 'employee_uuid' => $this->emp1->uuid, 'reason' => 'x'])->assertOk();
        DB::table('employees')->where('id', $this->emp1->id)->update(['status' => 'separated']);
        $findings = [];
        foreach (\App\Domain\Operations\OperationsQualityRules::all() as $rule) foreach ($rule->scan($this->admin->tenant_id) as $f) $findings[$rule->key()][] = $f->fingerprint;
        $this->assertCount(2, $findings['asset_missing_serial_number']); $this->assertCount(1, $findings['asset_held_by_departed_employee']);
        $this->signIn($this->admin);
        $this->postJson('/api/v1/expenses/fx-rates', ['base_currency' => 'EUR', 'quote_currency' => 'USD', 'rate' => '1.08', 'effective_on' => '2026-10-01', 'source' => 'manual'])->assertCreated();
        $this->assertSame([], collect(app(\App\Domain\Operations\OperationsInsightProvider::class)->insights($this->admin))->where('key', 'operations.fx_overrides_pending')->all());
    }
}
