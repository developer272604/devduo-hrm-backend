<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->table('recruitment_jobs', function (Blueprint $t) {
            $t->string('code', 40); $t->string('title', 160); $t->text('description');
            $t->unsignedInteger('version'); $t->unique(['tenant_id', 'code', 'version']);
        });
        $this->table('recruitment_requisitions', function (Blueprint $t) {
            $t->foreignId('job_id')->constrained('recruitment_jobs')->restrictOnDelete();
            $t->foreignId('legal_entity_id')->constrained('legal_entities')->restrictOnDelete();
            $t->foreignId('position_id')->nullable()->constrained('positions')->restrictOnDelete();
            $t->foreignId('recruiter_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('hiring_manager_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('openings'); $t->date('target_date'); $t->string('kind', 24);
            $t->boolean('confidential')->default(false); $t->string('status', 24)->default('pending');
            $t->text('reason'); $t->uuid('idempotency_key');
            $t->char('request_hash', 64);
            $t->unique(['tenant_id', 'idempotency_key'], 'requisition_request_key');
        });
        $this->table('recruitment_candidates', function (Blueprint $t) {
            $t->string('name', 160); $t->text('email'); $t->char('email_hash', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('source', 80); $t->string('privacy_notice_version', 80);
            $t->text('consent_reference'); $t->dateTime('consented_at'); $t->date('retention_until');
            $t->unique(['tenant_id', 'email_hash']);
        });
        $this->table('recruitment_applications', function (Blueprint $t) {
            $t->foreignId('candidate_id')->constrained('recruitment_candidates')->restrictOnDelete();
            $t->foreignId('requisition_id')->constrained('recruitment_requisitions')->restrictOnDelete();
            $t->string('stage', 24)->default('applied'); $t->string('status', 24)->default('active');
            $t->string('source', 80); $t->dateTime('stage_entered_at');
            $t->unique(['candidate_id', 'requisition_id']);
        });
        $this->table('recruitment_application_actions', function (Blueprint $t) {
            $t->foreignId('application_id')->constrained('recruitment_applications')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('from_stage', 24)->nullable(); $t->string('to_stage', 24);
            $t->string('status', 24); $t->text('reason'); $t->uuid('idempotency_key');
            $t->unique(['tenant_id', 'idempotency_key'], 'application_action_request_key');
        });
    }
    private function table(string $name, Closure $columns): void
    {
        Schema::create($name, function (Blueprint $t) use ($columns) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $columns($t); $t->timestamps();
        });
    }
    public function down(): void
    {
        foreach (['recruitment_application_actions', 'recruitment_applications', 'recruitment_candidates', 'recruitment_requisitions', 'recruitment_jobs'] as $table) Schema::dropIfExists($table);
    }
};
