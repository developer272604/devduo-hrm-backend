<?php

namespace Tests\Feature\People;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

final class LettersTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    public function test_letters_are_versioned_private_and_bound_to_the_employee(): void
    {
        $this->bootTenant();
        $admin = $this->admin;
        $employee = $this->hire('Asha Rao', ['role' => 'Team member']);
        $outside = $this->hire('Other Member', ['role' => 'Team member']);
        $templates = '/api/v1/people/letter-templates';
        $letters = '/api/v1/employees/'.$employee->uuid.'/letters';
        $payload = ['kind' => 'appointment', 'title' => 'Appointment', 'body' => 'Employee: {{employee_name}} <script>alert(1)</script>'];
        $this->signIn($admin);
        $this->postJson($templates, array_replace($payload, ['body' => '{{password}}']))->assertUnprocessable();
        $template = $this->postJson($templates, $payload)->assertCreated()->assertJsonPath('version', 1)->json('uuid');
        $letter = $this->postJson($letters, ['template' => $template])->assertCreated()->json('uuid');
        $encrypted = DB::table('hr_issued_letters')->where('uuid', $letter)->value('encrypted_body');
        $this->assertStringNotContainsString('Asha Rao', $encrypted);
        $this->postJson($templates, array_replace($payload, ['body' => 'Updated {{employee_name}}']))->assertCreated()->assertJsonPath('version', 2);
        $this->getJson($letters)->assertJsonPath('templates.0.version', 2)->assertJsonPath('issued.data.0.version', 1);
        $relieving = $this->postJson($templates, ['kind' => 'relieving', 'title' => 'Relieving', 'body' => '{{employee_name}}'])->assertCreated()->json('uuid');
        $this->postJson($letters, ['template' => $relieving])->assertUnprocessable();

        $ack = $letters.'/'.$letter.'/acknowledge';
        $this->postJson($ack, ['acknowledged' => true])->assertForbidden();
        $this->signIn($employee->person->user);
        $this->postJson($ack, ['acknowledged' => false])->assertUnprocessable();
        $at = $this->postJson($ack, ['acknowledged' => true])->assertOk()->json('acknowledged_at');
        $this->travel(1)->minutes();
        $this->postJson($ack, ['acknowledged' => true])->assertOk()->assertJsonPath('acknowledged_at', $at);
        $this->getJson($letters)->assertJsonPath('issued.data.0.acknowledged_at', $at);
        $this->assertDatabaseHas('hr_issued_letters', ['uuid' => $letter, 'acknowledged_by' => $employee->person->user->id,
            'acknowledged_body_sha256' => hash('sha256', 'Employee: Asha Rao <script>alert(1)</script>')]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'hr_letter.acknowledged')->count());
        $this->postJson($letters, ['template' => $template])->assertForbidden();
        $download = $this->get($letters.'/'.$letter.'/download')->assertOk();
        $this->assertSame('Employee: Asha Rao <script>alert(1)</script>', $download->streamedContent());
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $print = $this->get($letters.'/'.$letter.'/download?format=print')->assertOk()
            ->assertSee('Employee: Asha Rao <script>alert(1)</script>')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('Template version 1');
        $this->assertStringContainsString("default-src 'none'", $print->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $print->headers->get('Cache-Control'));
        $pdf = $this->get($letters.'/'.$letter.'/download?format=pdf')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="appointment-'.$letter.'.pdf"');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('no-store', $pdf->headers->get('Cache-Control'));
        $this->signIn($outside->person->user);
        $this->postJson($ack, ['acknowledged' => true])->assertNotFound();
        $this->getJson($letters.'/'.$letter.'/download?format=print')->assertNotFound();
        $this->getJson($letters.'/'.$letter.'/download?format=pdf')->assertNotFound();
        $this->getJson($letters.'/'.$letter.'/download')->assertNotFound();
        $this->bootTenant('letters-outside');
        $this->signIn($this->admin);
        $this->getJson($letters)->assertNotFound();
        $this->getJson($letters.'/'.$letter.'/download?format=pdf')->assertNotFound();
        $this->getJson($templates)->assertJsonCount(0, 'data');
    }

    public function test_requested_signature_is_employee_only_and_bound_to_the_issued_body(): void
    {
        $this->bootTenant();
        $employee = $this->hire('Asha Rao', ['role' => 'Team member']);
        $other = $this->hire('Other Member', ['role' => 'Team member']);
        $base = "/api/v1/employees/{$employee->uuid}/letters";
        $this->signIn($this->admin);
        $template = $this->postJson('/api/v1/people/letter-templates', [
            'kind' => 'appointment', 'title' => 'Appointment', 'body' => 'Appoint {{employee_name}}',
        ])->assertCreated()->json('uuid');
        $letter = $this->postJson($base, ['template' => $template, 'signature_requested' => true])->assertCreated()->json('uuid');
        $ordinary = $this->postJson($base, ['template' => $template])->assertCreated()->json('uuid');
        $notice = DB::table('in_app_notifications')->where('category', 'people.letter_signature')->sole();
        $this->assertSame($employee->person->user->id, (int) $notice->user_id);
        $this->assertSame($employee->tenant_id, (int) $notice->tenant_id);
        $this->assertSame("/people/{$employee->uuid}?section=documents&letters=1", $notice->action);
        $path = "$base/$letter";
        $signature = ['signer_name' => 'Asha Rao', 'consent' => true];
        $this->postJson("$path/sign", $signature)->assertForbidden();
        $this->signIn($other->person->user);
        $this->postJson("$path/sign", $signature)->assertNotFound();
        $this->signIn($employee->person->user);
        $this->postJson("$base/$ordinary/sign", $signature)->assertConflict();
        $this->postJson("$path/sign", array_replace($signature, ['consent' => false]))->assertUnprocessable();
        $this->postJson("$path/sign", array_replace($signature, ['signer_name' => 'Someone else']))->assertUnprocessable();
        $signedAt = $this->postJson("$path/sign", $signature)->assertOk()->json('signed_at');
        $this->travel(1)->minutes();
        $this->postJson("$path/sign", $signature)->assertOk()->assertJsonPath('signed_at', $signedAt);
        $this->assertDatabaseHas('hr_issued_letters', ['uuid' => $letter, 'signed_by' => $employee->person->user->id,
            'signed_body_sha256' => hash('sha256', 'Appoint Asha Rao'), 'acknowledged_at' => null]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'hr_letter.signed')->count());
        $this->getJson($base)->assertJsonPath('issued.data.1.signed_at', $signedAt);
        $this->get("$path/download?format=print")->assertOk()->assertSee('Electronically signed in DevDuo HRM by Asha Rao');
        $this->get("$path/download?format=pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_salary_certificates_require_payroll_scope_and_are_visible_to_the_employee(): void
    {
        $this->bootTenant();
        $admin = $this->admin;
        $employee = $this->hire('Asha Rao', ['role' => 'Team member']);
        $hr = $this->hire('HR Officer', ['role' => 'HR administrator']);
        $letters = '/api/v1/employees/'.$employee->uuid.'/letters';
        $this->signIn($admin);
        $template = $this->postJson('/api/v1/people/letter-templates', [
            'kind' => 'salary_certificate', 'title' => 'Salary certificate',
            'body' => "சம்பளச் சான்றிதழ்\n{{employee_name}}: {{salary_currency}} {{salary_amount}} {{salary_period}}",
        ])->assertCreated()->json('uuid');
        $request = ['template' => $template, 'salary_amount' => '50000', 'salary_currency' => 'INR', 'salary_period' => 'monthly'];
        $this->postJson($letters, array_replace($request, ['salary_amount' => '50000.001']))->assertUnprocessable();
        $letter = $this->postJson($letters, $request)->assertCreated()->json('uuid');

        $this->signIn($hr->person->user);
        $this->postJson($letters, $request)->assertForbidden();
        $this->getJson($letters)->assertJsonCount(0, 'issued.data')->assertJsonCount(0, 'templates');
        $this->getJson($letters.'/'.$letter.'/download')->assertNotFound();
        $this->getJson($letters.'/'.$letter.'/download?format=pdf')->assertNotFound();

        $this->signIn($employee->person->user);
        $download = $this->get($letters.'/'.$letter.'/download')->assertOk();
        $this->assertSame("சம்பளச் சான்றிதழ்\nAsha Rao: INR 50000.00 monthly", $download->streamedContent());
        $pdf = $this->get($letters.'/'.$letter.'/download?format=pdf')->assertOk();
        $this->assertStringContainsString('FreeSerif', $pdf->getContent());
    }

}
