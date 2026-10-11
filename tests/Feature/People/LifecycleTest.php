<?php

namespace Tests\Feature\People;

use App\Domain\Events\DomainEvent;
use App\Domain\People\Lifecycle\InvitationService;
use App\Domain\People\Models\Assignment;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\Person;
use App\Domain\People\Operations\BulkActions;
use App\Domain\Work\TaskService;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

/** Change requests, invitations, probation actions, separation, rehire and retention. */
class LifecycleTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private Employee $mgr;

    private Employee $emp;

    private int $empUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->mgr = $this->hire('Maya Chen', ['role' => 'Manager']);
        $this->emp = $this->hire('Asha Rao', ['role' => 'Team member', 'manager' => $this->mgr]);
        $this->empUserId = $this->emp->person->user_id;
    }

    /** The tenant middleware clears the context after every HTTP request; model assertions need it back. */
    private function ctx(): static
    {
        $this->context($this->admin->tenant_id);

        return $this;
    }

    private function as(Employee $e): static
    {
        return $this->signIn($e->person->user);
    }

    private function cr(string $suffix = '', ?Employee $e = null): string
    {
        return '/api/v1/employees/'.($e ?? $this->emp)->uuid.$suffix;
    }

    private function bank(): array
    {
        return ['account_holder' => 'Asha Rao', 'bank_name' => 'HDFC Bank', 'country' => 'IN', 'details' => ['ifsc' => 'HDFC0001234', 'account_number' => '1234 5678 9012']];
    }

    private function dump(string $table): string
    {
        return json_encode(DB::table($table)->get());
    }

    // ---- change requests -------------------------------------------------------------------------

    public function test_bank_change_request_is_encrypted_masked_and_applied_atomically_by_authorized_approver(): void
    {
        $this->as($this->emp);
        $res = $this->postJson($this->cr('/change-requests'), ['section' => 'bank', 'operation' => 'create', 'requested_value' => $this->bank(), 'comments' => 'New salary account'])->assertCreated()
            ->assertJsonPath('status', 'pending')->assertJsonPath('masked', true);
        $id = $res->json('uuid');
        $this->assertStringNotContainsString('123456789012', json_encode($res->json()));
        $this->assertStringNotContainsString('1234 5678 9012', $this->dump('profile_change_requests').$this->dump('audit_logs').$this->dump('domain_events').$this->dump('work_items'));
        $this->assertStringNotContainsString('HDFC0001234', $this->dump('profile_change_requests'));
        $this->assertSame(0, DB::table('employee_bank_accounts')->count());
        $this->ctx();
        $this->assertTrue(DomainEvent::where('type', 'ProfileChangeRequested')->exists());

        // the manager has people.changes.approve but not people.bank.manage: not an approver for bank data
        $this->as($this->mgr)->postJson("/api/v1/people/change-requests/$id/approve")->assertNotFound();
        $this->ctx();
        $this->assertSame('pending', DB::table('profile_change_requests')->value('status'));

        $this->signIn($this->admin);
        $this->ctx();
        $this->assertSame(1, WorkItem::where('source_entity', 'change_request:'.$id)->where('status', 'open')->count());
        $list = $this->getJson('/api/v1/people/change-requests?status=pending')->assertOk();
        $this->assertStringNotContainsString('123456789012', json_encode($list->json()));
        $this->postJson("/api/v1/people/change-requests/$id/approve", ['comment' => 'ok'])->assertOk()->assertJsonPath('status', 'approved');
        // idempotent
        $this->postJson("/api/v1/people/change-requests/$id/approve")->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson("/api/v1/people/change-requests/$id/reject", ['comment' => 'late'])->assertStatus(409);

        $account = DB::table('employee_bank_accounts')->first();
        $this->assertNotNull($account);
        $this->assertEquals(['ifsc' => 'HDFC0001234', 'account_number' => '123456789012'], json_decode(Crypt::decryptString($account->details_encrypted), true));
        $this->assertSame('9012', $account->account_last4);
        $this->assertSame(1, (int) $account->is_primary);
        $this->assertSame(1, DB::table('employee_bank_accounts')->count());
        $this->ctx();
        $this->assertSame(0, WorkItem::where('source_entity', 'change_request:'.$id)->where('status', 'open')->count());
        $this->ctx();
        $this->assertTrue(DomainEvent::where('type', 'ProfileChangeApproved')->exists());
        $this->assertTrue(AuditLog::where('action', 'change_approved')->exists());
        $this->assertStringNotContainsString('123456789012', $this->dump('audit_logs').$this->dump('domain_events'));
        $this->assertTrue(DB::table('in_app_notifications')->where('user_id', $this->empUserId)->where('title', 'Profile change approved')->exists());
    }

    public function test_personal_change_applies_on_approval_and_direct_endpoint_still_requires_request(): void
    {
        $this->as($this->emp);
        $this->patchJson($this->cr('/personal'), ['date_of_birth' => '1990-04-01'])->assertStatus(409)->assertJsonPath('code', 'change_request_required');
        $id = $this->postJson($this->cr('/change-requests'), ['section' => 'personal', 'operation' => 'update', 'requested_value' => ['date_of_birth' => '1990-04-01'], 'effective_date' => '2026-10-07'])
            ->assertCreated()->assertJsonPath('old_value.date_of_birth', null)->json('uuid');
        // duplicates are refused while pending
        $this->postJson($this->cr('/change-requests'), ['section' => 'personal', 'operation' => 'update', 'requested_value' => ['date_of_birth' => '1991-04-01']])->assertStatus(422);
        // employees cannot decide; the requester cannot approve his own request either
        $this->postJson("/api/v1/people/change-requests/$id/approve")->assertForbidden();

        $this->as($this->mgr)->postJson("/api/v1/people/change-requests/$id/reject")->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->postJson("/api/v1/people/change-requests/$id/approve", ['comment' => 'Verified'])->assertOk();
        $this->ctx();
        $this->assertSame('1990-04-01', $this->emp->person->refresh()->date_of_birth->toDateString());
        $this->as($this->emp)->getJson($this->cr('/change-requests'))->assertOk()->assertJsonPath('data.0.status', 'approved')->assertJsonPath('data.0.decision_comment', 'Verified');
    }

    public function test_reject_cancel_validation_and_visibility(): void
    {
        $this->as($this->emp);
        $this->postJson($this->cr('/change-requests'), ['section' => 'contact', 'operation' => 'create', 'requested_value' => ['kind' => 'email', 'label' => 'personal', 'value' => 'not-an-email']])
            ->assertStatus(422)->assertJsonValidationErrors('requested_value.value');
        $this->postJson($this->cr('/change-requests'), ['section' => 'contact', 'operation' => 'update', 'target_id' => '6f0d1b0e-0000-4000-8000-000000000000', 'requested_value' => ['value' => 'a@b.test']])->assertStatus(422);
        $id = $this->postJson($this->cr('/change-requests'), ['section' => 'dependent', 'operation' => 'create', 'requested_value' => ['name' => 'Kid Rao', 'relationship' => 'child', 'date_of_birth' => '2018-01-01']])
            ->assertCreated()->json('uuid');
        $this->ctx();
        $other = $this->hire('Olive Other', ['role' => 'Team member', 'department' => 1]);
        $this->as($other)->getJson("/api/v1/people/change-requests/$id")->assertNotFound();
        $this->as($this->emp)->postJson("/api/v1/people/change-requests/$id/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson("/api/v1/people/change-requests/$id/cancel")->assertOk();
        $this->signIn($this->admin)->postJson("/api/v1/people/change-requests/$id/approve")->assertStatus(409);
        $this->assertSame(0, DB::table('employee_dependents')->count());

        $id2 = $this->as($this->emp)->postJson($this->cr('/change-requests'), ['section' => 'dependent', 'operation' => 'create', 'requested_value' => ['name' => 'Kid Rao', 'relationship' => 'child', 'date_of_birth' => '2018-01-01']])->json('uuid');
        $this->as($this->mgr)->postJson("/api/v1/people/change-requests/$id2/reject", ['comment' => 'Need proof'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertSame(0, DB::table('employee_dependents')->count());
        $this->ctx();
        $this->assertTrue(DomainEvent::where('type', 'ProfileChangeRejected')->exists());
    }

    public function test_identity_request_stores_hash_last4_and_encrypted_number_through_the_service(): void
    {
        $this->as($this->emp);
        $id = $this->postJson($this->cr('/change-requests'), ['section' => 'identity', 'operation' => 'create', 'requested_value' => ['type' => 'pan', 'country' => 'IN', 'number' => 'ABCDE1234F']])
            ->assertCreated()->json('uuid');
        $this->postJson($this->cr('/change-requests'), ['section' => 'identity', 'operation' => 'create', 'requested_value' => ['type' => 'pan', 'country' => 'IN', 'number' => 'bad']])->assertStatus(422);
        $this->assertStringNotContainsString('ABCDE1234F', $this->dump('profile_change_requests'));
        $this->signIn($this->admin)->postJson("/api/v1/people/change-requests/$id/approve")->assertOk();
        $row = DB::table('identity_documents')->first();
        $this->assertSame('ABCDE1234F', Crypt::decryptString($row->number_encrypted));
        $this->assertSame('234F', $row->number_last4);
        $this->assertNotEmpty($row->number_hash);
    }

    // ---- invitations ------------------------------------------------------------------------------

    public function test_invitation_lifecycle_and_public_acceptance(): void
    {
        $e = $this->hire('Nina Park');
        $this->signIn($this->admin);
        $res = $this->postJson($this->cr('/invitations', $e), [])->assertCreated()->assertJsonPath('status', 'pending');
        $this->assertArrayNotHasKey('token', $res->json());
        $this->postJson($this->cr('/invitations', $e), [])->assertStatus(422);
        $inv = $res->json('uuid');
        $this->postJson("/api/v1/people/invitations/$inv/resend")->assertOk()->assertJsonPath('resend_count', 1);
        $this->postJson("/api/v1/people/invitations/$inv/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson("/api/v1/people/invitations/$inv/resend")->assertStatus(422);

        $this->ctx();
        [$row, $old] = app(InvitationService::class)->invite($this->admin, $e);
        [, $token] = app(InvitationService::class)->resend($this->admin, $row);
        $this->assertNotSame($old, $token);
        $this->assertNotSame($token, DB::table('employee_invitations')->where('uuid', $row->uuid)->value('token_hash'));

        auth()->logout();
        $users = User::count();
        $people = Person::count();
        $employees = Employee::count();
        $this->postJson('/api/v1/public/invitations/accept', ['token' => $old, 'password' => 'GoodPassword123', 'password_confirmation' => 'GoodPassword123'])->assertStatus(422);
        $this->postJson('/api/v1/public/invitations/accept', ['token' => $token, 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);
        $this->postJson('/api/v1/public/invitations/accept', ['token' => $token, 'password' => 'GoodPassword123', 'password_confirmation' => 'GoodPassword123'])->assertCreated();
        $this->postJson('/api/v1/public/invitations/accept', ['token' => $token, 'password' => 'GoodPassword123', 'password_confirmation' => 'GoodPassword123'])->assertStatus(422);

        $this->ctx();
        $this->assertSame($users + 1, User::count());
        $this->assertSame($people, Person::count());
        $this->assertSame($employees, Employee::count());
        $this->ctx();
        $person = $e->person->refresh();
        $this->assertNotNull($person->user_id);
        $this->assertSame('accepted', DB::table('employee_invitations')->where('uuid', $row->uuid)->value('status'));
        $this->assertTrue(User::find($person->user_id)->allows('people.view'));
        $this->context($this->admin->tenant_id);
        $this->signIn($this->admin)->postJson($this->cr('/invitations', $e), [])->assertStatus(422); // already has an account
    }

    public function test_expired_invitations_are_refused_and_bulk_action_is_registered(): void
    {
        $e = $this->hire('Nina Park');
        [$row, $token] = app(InvitationService::class)->invite($this->admin, $e);
        DB::table('employee_invitations')->where('id', $row->id)->update(['expires_at' => now()->subMinute()]);
        auth()->logout();
        $this->postJson('/api/v1/public/invitations/accept', ['token' => $token, 'password' => 'GoodPassword123', 'password_confirmation' => 'GoodPassword123'])->assertStatus(422);
        $this->ctx();
        $this->assertNull($e->person->refresh()->user_id);

        $this->context($this->admin->tenant_id);
        $actions = app(BulkActions::class);
        $this->assertTrue($actions->has('send_invitation'));
        $this->assertArrayHasKey('send_invitation', $actions->forUser($this->admin));
        $action = $actions->get('send_invitation');
        $other = $this->hire('Raj Mehta');
        $preview = $action->preview($this->admin, collect([$other, $this->emp]), []);
        $this->assertSame('ok', $preview[$other->id]['status']);
        $this->assertSame('skipped', $preview[$this->emp->id]['status']);
        $action->apply($this->admin, $other, []);
        $this->ctx();
        $this->assertSame(1, DB::table('employee_invitations')->where('employee_id', $other->id)->where('status', 'pending')->count());
    }

    // ---- probation ---------------------------------------------------------------------------------

    private function onProbation(string $name, string $end = '2026-10-15'): Employee
    {
        $e = $this->hire($name, ['role' => 'Team member', 'manager' => $this->mgr, 'status' => 'probation']);
        DB::table('employees')->where('id', $e->id)->update(['probation_applicable' => true, 'probation_start' => '2026-07-15', 'probation_end' => $end]);

        return $e->refresh();
    }

    public function test_probation_confirm_and_extend_by_manager_complete_my_work_tasks(): void
    {
        $e = $this->onProbation('Pia Nair');
        $entity = 'probation:'.$e->uuid.':2026-10-15';
        app(TaskService::class)->createOnce(['assignee_id' => $this->mgr->person->user_id, 'title' => 'Confirm or extend probation', 'source_module' => 'people', 'source_entity' => $entity]);

        $this->as($e)->postJson($this->cr('/probation/confirm', $e), [])->assertForbidden();
        $this->as($this->mgr)->postJson($this->cr('/probation/extend', $e), ['new_end_date' => '2026-10-10', 'reason' => 'x'])->assertStatus(422);
        $this->postJson($this->cr('/probation/extend', $e), ['new_end_date' => '2026-11-15'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson($this->cr('/probation/extend', $e), ['new_end_date' => '2026-11-15', 'reason' => 'Needs more time on the platform'])->assertOk()
            ->assertJsonPath('probation_end', '2026-11-15')->assertJsonPath('probation_extensions', 1)->assertJsonPath('status', 'probation');
        $this->ctx();
        $this->assertSame('completed', WorkItem::where('source_entity', $entity)->value('status'));
        $this->ctx();
        $this->assertTrue(DomainEvent::where('type', 'ProbationExtended')->where('employee_id', $e->id)->exists());

        $this->postJson($this->cr('/probation/confirm', $e), ['comments' => 'Great work'])->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('confirmation_date', '2026-10-07');
        $this->ctx();
        $this->assertTrue(DomainEvent::where('type', 'ProbationConfirmed')->where('employee_id', $e->id)->exists());
        $this->postJson($this->cr('/probation/confirm', $e), [])->assertStatus(422);
    }

    // ---- separation, rehire, retention -------------------------------------------------------------

    public function test_separation_closes_open_records_deactivates_login_and_rehire_reuses_the_person(): void
    {
        $this->signIn($this->admin);
        $this->postJson($this->cr('/separation'), ['type' => 'resignation', 'last_working_date' => '2026-10-07', 'reason' => 'Relocating', 'rehire_eligible' => false])
            ->assertCreated()->assertJsonPath('status', 'initiated');
        $this->ctx();
        $this->assertSame('notice_period', $this->emp->refresh()->status);
        $this->assertSame('2026-10-07', $this->emp->separated_on->toDateString());
        $this->postJson($this->cr('/separation'), ['type' => 'resignation', 'last_working_date' => '2026-10-07'])->assertStatus(422);

        $this->postJson($this->cr('/separation/complete'), [])->assertOk()->assertJsonPath('status', 'completed');
        $this->ctx();
        $this->assertSame('separated', $this->emp->refresh()->status);
        $this->ctx();
        $this->assertSame(0, Assignment::where('employee_id', $this->emp->id)->whereNull('effective_to')->count());
        $this->assertSame(0, DB::table('employee_reporting_relationships')->where('employee_id', $this->emp->id)->whereNull('effective_to')->count());
        $this->assertFalse((bool) User::find($this->empUserId)->active);
        $this->ctx();
        $this->assertTrue(DomainEvent::where('type', 'EmployeeSeparated')->where('employee_id', $this->emp->id)->exists());
        $this->postJson($this->cr('/separation/withdraw'), [])->assertStatus(422);

        $body = fn (array $extra = []) => $extra + ['employment' => ['worker_type' => 'employee', 'joined_on' => '2026-10-20'],
            'assignment' => ['legal_entity_id' => $this->org['legal_entity'][0], 'location_id' => $this->org['location'][0], 'department_id' => $this->org['department'][1],
                'designation_id' => $this->org['designation'][0], 'employee_type_id' => $this->org['employee_type'][0]], 'manager_id' => $this->mgr->id];
        $this->postJson($this->cr('/rehire'), $body())->assertStatus(422)->assertJsonValidationErrors('override_reason');
        $res = $this->postJson($this->cr('/rehire'), $body(['override_reason' => 'Returning on a new contract']))->assertCreated()->assertJsonPath('employment_sequence', 2);
        $this->ctx();
        $new = Employee::where('uuid', $res->json('uuid'))->first();
        $this->ctx();
        $this->assertSame($this->emp->person_id, $new->person_id);
        $this->assertSame($this->emp->id, $new->rehire_of_id);
        $this->ctx();
        $this->assertSame(1, Person::where('id', $this->emp->person_id)->count());
        $this->assertSame($this->emp->original_joining_date->toDateString(), $new->original_joining_date->toDateString());
        $this->assertTrue((bool) User::find($this->empUserId)->active);
        $this->ctx();
        $this->assertTrue(DomainEvent::where('type', 'EmployeeRehired')->where('employee_id', $new->id)->exists());
        $this->postJson($this->cr('/rehire'), $body(['override_reason' => 'again']))->assertStatus(422);
    }

    public function test_rehire_eligible_needs_no_override_and_withdraw_restores_status(): void
    {
        $this->signIn($this->admin);
        $this->postJson($this->cr('/separation'), ['type' => 'resignation', 'last_working_date' => '2026-10-31'])->assertCreated();
        $this->postJson($this->cr('/separation/complete'), [])->assertStatus(422); // before the last working date
        $this->postJson($this->cr('/separation/withdraw'), ['comments' => 'Stayed'])->assertOk()->assertJsonPath('status', 'withdrawn');
        $this->ctx();
        $this->assertSame('active', $this->emp->refresh()->status);
        $this->assertNull($this->emp->separated_on);

        $this->postJson($this->cr('/separation'), ['type' => 'contract_end', 'last_working_date' => '2026-10-07', 'rehire_eligible' => true])->assertCreated();
        $this->postJson($this->cr('/separation/complete'), [])->assertOk();
        $this->postJson($this->cr('/rehire'), ['employment' => ['worker_type' => 'employee', 'joined_on' => '2026-11-02'],
            'assignment' => ['legal_entity_id' => $this->org['legal_entity'][0], 'location_id' => $this->org['location'][0], 'department_id' => $this->org['department'][0],
                'designation_id' => $this->org['designation'][0], 'employee_type_id' => $this->org['employee_type'][0]]])->assertCreated()->assertJsonPath('status', 'preboarding');
        $this->ctx();
        $this->assertSame(2, Employee::where('person_id', $this->emp->person_id)->count());
    }

    public function test_legal_hold_blocks_anonymization_and_nothing_is_deleted(): void
    {
        $this->signIn($this->admin);
        $this->postJson($this->cr('/separation'), ['type' => 'termination', 'last_working_date' => '2026-10-07'])->assertCreated();
        $this->postJson($this->cr('/separation/complete'), [])->assertOk();
        $this->postJson($this->cr('/anonymize'), ['confirm' => true, 'reason' => 'Request'])->assertOk(); // no hold: allowed
        $this->ctx();
        $this->assertNotNull($this->emp->person->refresh()->anonymized_at);
        $this->assertSame('Anonymized person', $this->emp->person->display_name);
        $this->assertSame(1, Employee::where('id', $this->emp->id)->count());
        $this->assertSame('archived', $this->emp->refresh()->status);

        $this->ctx();
        $second = $this->hire('Tara Singh', ['role' => 'Team member']);
        $this->postJson($this->cr('/anonymize', $second), ['confirm' => true, 'reason' => 'x'])->assertStatus(422); // still working
        $this->putJson($this->cr('/retention', $second), ['legal_hold' => true, 'retention_until' => '2030-01-01', 'reason' => 'Dispute'])->assertOk()->assertJsonPath('legal_hold', true);
        $this->postJson($this->cr('/separation', $second), ['type' => 'other', 'last_working_date' => '2026-10-07'])->assertCreated();
        $this->postJson($this->cr('/separation/complete', $second), [])->assertOk();
        $this->postJson($this->cr('/anonymize', $second), ['confirm' => true, 'reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'legal_hold');
        $this->putJson($this->cr('/retention', $second), ['legal_hold' => false])->assertOk();
        $this->postJson($this->cr('/anonymize', $second), ['confirm' => true, 'reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'retention_active');
        $this->ctx();
        $this->assertNull($second->person->refresh()->anonymized_at);
        $this->getJson($this->cr('/retention', $second))->assertOk()->assertJsonPath('retention_until', '2030-01-01');

        $this->as($this->mgr)->postJson($this->cr("/separation", $second), ['type' => 'other', 'last_working_date' => '2026-10-07'])->assertForbidden();
    }
}
