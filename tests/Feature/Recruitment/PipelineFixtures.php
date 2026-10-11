<?php

namespace Tests\Feature\Recruitment;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concerns\PeopleFixtures;

/** Shared helpers for the pipeline/SLA and requisition-version tests. */
trait PipelineFixtures
{
    use PeopleFixtures;

    private User $checker;

    private function setUpRecruitment(): void
    {
        $this->bootTenant();
        $this->checker = $this->userWithRole('Workspace administrator', 'checker@alpha.test');
        $this->signIn($this->admin);
    }

    private function stages(array $overrides = []): array
    {
        return $overrides + [
            ['code' => 'new', 'label' => 'New', 'kind' => 'active'],
            ['code' => 'review', 'label' => 'Review', 'kind' => 'active', 'sla_hours' => 48, 'reminder_recipients' => ['recruiter', 'hiring_manager']],
            ['code' => 'final', 'label' => 'Final', 'kind' => 'active', 'sla_hours' => 24],
            ['code' => 'offer', 'label' => 'Offer', 'kind' => 'offer'],
            ['code' => 'hired', 'label' => 'Hired', 'kind' => 'hired'],
        ];
    }

    private function pipeline(string $code = 'FAST', array $extra = [], ?array $stages = null): string
    {
        return $this->postJson('/api/v1/recruitment/pipelines', $extra + ['code' => $code, 'name' => 'Fast track', 'stages' => $stages ?? $this->stages()])->assertCreated()->json('uuid');
    }

    private function requisition(int $openings = 1, array $extra = [], bool $open = true): string
    {
        $job = $this->postJson('/api/v1/recruitment/jobs', ['code' => 'ENG'.Str::upper(Str::random(4)), 'title' => 'Software engineer', 'description' => 'Build tested applications.'])->assertCreated()->json('uuid');
        $uuid = $this->postJson('/api/v1/recruitment/requisitions', $extra + ['job_uuid' => $job, 'legal_entity_id' => $this->org['legal_entity'][0], 'department_id' => $this->org['department'][0], 'recruiter_id' => $this->admin->id,
            'hiring_manager_id' => $this->checker->id, 'openings' => $openings, 'target_date' => '2026-12-01', 'kind' => 'new', 'reason' => 'Approved staffing plan', 'budget_amount' => '1000.00', 'budget_currency' => 'inr',
            'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('uuid');
        if ($open) {
            $this->signIn($this->checker)->postJson('/api/v1/recruitment/requisitions/'.$uuid.'/decision', ['decision' => 'open'])->assertOk();
            $this->signIn($this->admin);
        }

        return $uuid;
    }

    private function candidate(string $email = 'asha@example.test'): string
    {
        return $this->postJson('/api/v1/recruitment/candidates', ['name' => 'Asha Rao', 'email' => $email, 'source' => 'Referral', 'privacy_notice_version' => '2026.1', 'consent_reference' => 'Consent ref 001',
            'consented_at' => '2026-10-01T10:00:00+05:30', 'retention_until' => '2027-12-01'])->assertCreated()->json('uuid');
    }

    private function apply(string $req, ?string $candidate = null): string
    {
        return $this->postJson('/api/v1/recruitment/applications', ['candidate_uuid' => $candidate ?? $this->candidate(), 'requisition_uuid' => $req, 'source' => 'Referral'])->assertCreated()->json('uuid');
    }

    private function move(string $app, string $from, string $to, string $status = 'active')
    {
        return $this->postJson('/api/v1/recruitment/applications/'.$app.'/transition', ['stage' => $to, 'expected_stage' => $from, 'status' => $status, 'reason' => 'Progressing on job-related criteria', 'idempotency_key' => (string) Str::uuid()]);
    }
}
