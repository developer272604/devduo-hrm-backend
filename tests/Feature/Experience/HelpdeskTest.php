<?php

namespace Tests\Feature\Experience;

use Tests\Feature\Performance\PerformanceTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class HelpdeskTest extends PerformanceTestCase
{
    private function openTicket(): string
    {
        $category = collect($this->getJson('/api/v1/experience/service-categories')->assertOk()->json())->first()['uuid'];
        $this->actAs($this->emp1);
        return $this->postJson('/api/v1/experience/tickets', ['category_uuid' => $category, 'subject' => 'Payslip question', 'description' => 'Please help me understand this payslip.'])
            ->assertCreated()->json('uuid');
    }

    public function test_service_category_routes_new_tickets_to_its_default_owner(): void
    {
        $category = $this->postJson('/api/v1/experience/service-categories', [
            'code' => 'HR-OPS', 'name' => 'HR Operations', 'target_hours' => 24, 'default_assignee_id' => $this->admin->id,
        ])->assertCreated()->json();
        $this->actAs($this->emp1);
        $ticket = $this->postJson('/api/v1/experience/tickets', ['category_uuid' => $category['uuid'], 'subject' => 'HR operations question', 'description' => 'Please route this request.'])
            ->assertCreated()->assertJsonPath('assignee_id', $this->admin->id)->json();
        $this->assertSame($this->admin->id, \DB::table('experience_tickets')->where('uuid', $ticket['uuid'])->value('assignee_id'));
    }

    public function test_private_notes_are_hidden_from_employee_and_manager(): void
    {
        $ticket = $this->openTicket();
        $this->postJson("/api/v1/experience/tickets/$ticket/comments", ['body' => 'Additional detail from me.'])->assertOk()->assertJsonPath('first_response_at', null);
        $this->signIn($this->admin);
        $this->postJson("/api/v1/experience/tickets/$ticket/comments", ['body' => 'We are checking this.', 'visibility' => 'public'])->assertOk()->assertJsonPath('first_response_at', fn ($value) => ! empty($value));
        $this->postJson("/api/v1/experience/tickets/$ticket/comments", ['body' => 'Check the payroll export before responding.', 'visibility' => 'private'])->assertOk();

        $this->actAs($this->emp1);
        $response = $this->getJson("/api/v1/experience/tickets/$ticket")->assertOk();
        $response->assertJsonCount(2, 'comments')->assertJsonMissing(['body' => 'Check the payroll export before responding.']);
        $this->postJson("/api/v1/experience/tickets/$ticket/comments", ['body' => 'I would like to add an internal note.', 'visibility' => 'private'])->assertForbidden();

        $this->actAs($this->mgr);
        $this->getJson("/api/v1/experience/tickets/$ticket")->assertOk()->assertJsonCount(2, 'comments');
        $this->signIn($this->admin);
        $this->getJson("/api/v1/experience/tickets/$ticket")->assertOk()->assertJsonCount(3, 'comments');
    }

    public function test_ticket_visibility_is_tenant_scoped(): void
    {
        $ticket = $this->openTicket();
        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->getJson("/api/v1/experience/tickets/$ticket")->assertNotFound();
    }

    public function test_hr_can_resolve_and_close_but_invalid_transition_is_rejected(): void
    {
        $ticket = $this->openTicket();
        $this->signIn($this->admin);
        $this->patchJson("/api/v1/experience/tickets/$ticket", ['status' => 'closed'])->assertUnprocessable();
        $this->patchJson("/api/v1/experience/tickets/$ticket", ['status' => 'resolved', 'resolution' => 'Corrected the payslip'])->assertOk()->assertJsonPath('status', 'resolved');
        $this->patchJson("/api/v1/experience/tickets/$ticket", ['status' => 'closed'])->assertOk()->assertJsonPath('status', 'closed');
    }

    public function test_requester_can_reopen_and_submit_one_satisfaction_rating(): void
    {
        $ticket = $this->openTicket();
        $this->signIn($this->admin);
        $this->patchJson("/api/v1/experience/tickets/$ticket", ['status' => 'resolved'])->assertOk();
        $this->actAs($this->emp1);
        $this->getJson("/api/v1/experience/tickets/$ticket")->assertOk()->assertJsonPath('can_reopen', true)->assertJsonPath('can_feedback', true);
        $this->postJson("/api/v1/experience/tickets/$ticket/reopen")->assertOk()->assertJsonPath('status', 'in_progress');
        $this->signIn($this->admin);
        $this->patchJson("/api/v1/experience/tickets/$ticket", ['status' => 'resolved'])->assertOk();
        $this->actAs($this->emp1);
        $this->postJson("/api/v1/experience/tickets/$ticket/feedback", ['rating' => 5, 'comment' => 'Helpful and clear.'])->assertOk()->assertJsonPath('can_feedback', false);
        $this->postJson("/api/v1/experience/tickets/$ticket/feedback", ['rating' => 4])->assertUnprocessable();
        $this->actAs($this->emp2);
        $this->postJson("/api/v1/experience/tickets/$ticket/feedback", ['rating' => 4])->assertNotFound();
    }

    public function test_service_health_is_tenant_admin_only_and_suppresses_small_feedback_groups(): void
    {
        $this->openTicket();
        $this->signIn($this->admin);
        $this->getJson('/api/v1/experience/service-health')->assertOk()->assertJsonPath('backlog.total', 1)->assertJsonPath('satisfaction.suppressed', true)->assertJsonMissingPath('satisfaction.average_rating');
        $this->actAs($this->emp1);
        $this->getJson('/api/v1/experience/service-health')->assertForbidden();
    }

    public function test_ticket_attachments_are_private_and_scoped_to_visible_ticket(): void
    {
        Storage::fake('experience_private');
        $ticket = $this->openTicket();
        $upload = $this->post("/api/v1/experience/tickets/$ticket/attachments", ['file' => UploadedFile::fake()->image('receipt.png')]);
        $upload->assertCreated()->assertJsonPath('filename', 'receipt.png');
        $attachment = $upload->json('uuid');
        $this->getJson("/api/v1/experience/tickets/$ticket")->assertOk()->assertJsonCount(1, 'attachments');
        $this->get("/api/v1/experience/tickets/$ticket/attachments/$attachment")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actAs($this->emp2);
        $this->get("/api/v1/experience/tickets/$ticket/attachments/$attachment")->assertNotFound();
    }

    public function test_manual_escalation_is_audited_and_reason_is_private(): void
    {
        $ticket = $this->openTicket();
        $this->signIn($this->admin);
        $this->postJson("/api/v1/experience/tickets/$ticket/escalate", ['reason' => 'Payroll deadline is at risk.'])
            ->assertOk()->assertJsonPath('priority', 'high')->assertJsonPath('escalations.0.type', 'manual');
        $this->actAs($this->emp1);
        $this->getJson("/api/v1/experience/tickets/$ticket")->assertOk()->assertJsonPath('escalations', [])->assertJsonMissing(['reason' => 'Payroll deadline is at risk.']);
    }

    public function test_sla_breach_escalation_command_is_tenant_scoped_and_idempotent(): void
    {
        $ticket = $this->openTicket();
        $ticketId = \DB::table('experience_tickets')->where('uuid', $ticket)->value('id');
        \DB::table('experience_tickets')->where('id', $ticketId)->update(['sla_due_at' => now()->subMinute()]);
        $alphaTenantId = $this->admin->tenant_id;
        $alphaAdminId = $this->admin->id;
        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $betaTenant = \App\Models\Tenant::findOrFail($this->admin->tenant_id);
        $settings = $betaTenant->settings ?? []; $settings['people']['default_country'] = 'IN'; $betaTenant->update(['settings' => $settings]);
        $category = collect($this->getJson('/api/v1/experience/service-categories')->assertOk()->json())->first()['uuid'];
        $this->context($this->admin->tenant_id);
        $betaEmployee = $this->hire('Beta Worker', ['work_email' => 'beta.worker@beta.test']);
        $betaTicket = app(\App\Domain\Experience\HelpdeskService::class)->create($this->admin, $betaEmployee,
            ['category_uuid' => $category, 'subject' => 'Beta request', 'description' => 'Check tenant boundary.'])->uuid;
        $betaTicketId = \DB::table('experience_tickets')->where('uuid', $betaTicket)->value('id');
        \DB::table('experience_tickets')->where('id', $betaTicketId)->update(['sla_due_at' => now()->subMinute()]);

        $options = ['--tenant' => (string) $alphaTenantId];
        $this->artisan('experience:escalate-sla-tickets', $options)->assertExitCode(0);
        $this->artisan('experience:escalate-sla-tickets', $options)->assertExitCode(0);
        $this->assertDatabaseHas('experience_tickets', ['id' => $ticketId, 'priority' => 'urgent']);
        $this->assertSame(1, \DB::table('experience_ticket_escalations')->where('ticket_id', $ticketId)->where('escalation_type', 'sla_breach')->count());
        $this->assertSame(1, \DB::table('in_app_notifications')->where('user_id', $alphaAdminId)->where('title', 'HR ticket escalated')->count());
        $this->assertSame(0, \DB::table('experience_ticket_escalations')->where('ticket_id', $betaTicketId)->count());
        $this->artisan('experience:escalate-sla-tickets', ['--tenant' => (string) $this->admin->tenant_id])->assertExitCode(0);
        $this->assertSame(1, \DB::table('experience_ticket_escalations')->where('ticket_id', $betaTicketId)->where('escalation_type', 'sla_breach')->count());
    }

    public function test_assignee_lookup_is_manager_only_and_tenant_scoped(): void
    {
        $alphaAdminId = $this->admin->id;
        $this->getJson('/api/v1/experience/tickets/assignees')->assertOk()->assertJsonFragment(['id' => $alphaAdminId]);
        $this->actAs($this->emp1);
        $this->getJson('/api/v1/experience/tickets/assignees')->assertForbidden();
        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->getJson('/api/v1/experience/tickets/assignees')->assertOk()->assertJsonMissing(['id' => $alphaAdminId]);
    }
}

