<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

final class PrivacyRequestsTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    public function test_privacy_request_is_encrypted_scoped_reviewed_and_idempotent(): void
    {
        $this->bootTenant();
        $admin = $this->admin;
        $employee = $this->hire('Asha Rao', ['role' => 'Team member'])->person->user;
        $other = $this->hire('Other Member', ['role' => 'Team member'])->person->user;
        $path = '/api/v1/security/privacy-requests';
        $review = '/api/v1/security/privacy-review';
        $payload = ['request_key' => (string) Str::uuid(), 'kind' => 'correction', 'details' => 'Correct the address on my record.'];

        $this->signIn($employee);
        $this->getJson('/api/me')->assertJsonPath('privacy_management', false);
        $uuid = $this->postJson($path, $payload)->assertCreated()->assertJsonPath('status', 'submitted')->json('uuid');
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('uuid', $uuid);
        $this->postJson($path, array_replace($payload, ['details' => 'Different request.']))->assertConflict();
        $this->assertStringNotContainsString($payload['details'], DB::table('privacy_requests')->where('uuid', $uuid)->value('encrypted_details'));
        $this->getJson($review)->assertForbidden();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'privacy.request_submitted')->count());

        $this->signIn($other);
        $this->getJson($path)->assertJsonCount(0, 'data');
        $this->postJson($path.'/'.$uuid.'/withdraw')->assertNotFound();
        $this->signIn($admin);
        $this->getJson('/api/me')->assertJsonPath('privacy_management', true);
        $this->getJson($review.'?status=submitted')->assertJsonPath('data.0.requester', 'Asha Rao');
        $this->postJson($review.'/'.$uuid, ['status' => 'in_review', 'version' => 1])->assertOk()->assertJsonPath('version', 2);
        $this->postJson($review.'/'.$uuid, ['status' => 'completed', 'version' => 1, 'outcome' => 'Updated.'])->assertConflict();
        $this->postJson($review.'/'.$uuid, ['status' => 'completed', 'version' => 2])->assertUnprocessable();
        $this->postJson($review.'/'.$uuid, ['status' => 'completed', 'version' => 2, 'outcome' => 'Updated.'])->assertOk();
        $this->assertStringNotContainsString('Updated.', DB::table('privacy_requests')->where('uuid', $uuid)->value('encrypted_outcome'));

        $self = $this->postJson($path, array_replace($payload, ['request_key' => (string) Str::uuid()]))->assertCreated()->json('uuid');
        $this->postJson($review.'/'.$self, ['status' => 'in_review', 'version' => 1])->assertForbidden();
        $this->postJson($path.'/'.$self.'/withdraw')->assertOk()->assertJsonPath('status', 'withdrawn');
        $this->postJson($path.'/'.$self.'/withdraw')->assertOk()->assertJsonPath('version', 2);

        $this->signIn($employee);
        $this->getJson($path)->assertJsonPath('data.0.outcome', 'Updated.');
        $this->postJson($path.'/'.$uuid.'/withdraw')->assertConflict();

        $this->bootTenant('outside');
        $this->signIn($this->admin);
        $this->getJson($review)->assertJsonCount(0, 'data');
        $this->postJson($review.'/'.$uuid, ['status' => 'in_review', 'version' => 1])->assertNotFound();
    }
}
