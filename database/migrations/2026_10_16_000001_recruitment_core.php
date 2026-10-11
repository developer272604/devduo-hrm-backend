<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 6 core: requisition budget/headcount, postings, interviews + scorecards, versioned offers, pre-joiner handover, candidate privacy. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_requisitions', function (Blueprint $t) {
            $t->unsignedBigInteger('department_id')->nullable()->index();
            $t->unsignedBigInteger('location_id')->nullable();
            $t->decimal('budget_amount', 14, 2)->nullable();
            $t->string('budget_currency', 3)->nullable();
            $t->unsignedInteger('filled_count')->default(0);
            $t->dateTime('opened_at')->nullable();
            $t->dateTime('filled_at')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->index(['tenant_id', 'status']);
        });
        Schema::table('recruitment_candidates', function (Blueprint $t) {
            $t->text('phone')->nullable();
            $t->dateTime('anonymized_at')->nullable();
        });
        Schema::table('recruitment_applications', function (Blueprint $t) {
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->dateTime('closed_at')->nullable();
            $t->index(['tenant_id', 'stage', 'status']);
        });

        $this->table('recruitment_postings', function (Blueprint $t) {
            $t->foreignId('requisition_id')->constrained('recruitment_requisitions')->restrictOnDelete();
            $t->string('title', 160); $t->text('description'); $t->string('channel', 24)->default('internal');
            $t->string('location_text', 160)->nullable();
            $t->string('status', 24)->default('draft'); $t->dateTime('published_at')->nullable(); $t->date('closes_on')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->index(['tenant_id', 'status']);
        });
        $this->table('recruitment_interviews', function (Blueprint $t) {
            $t->foreignId('application_id')->constrained('recruitment_applications')->restrictOnDelete();
            $t->string('round', 80); $t->dateTime('starts_at'); $t->dateTime('ends_at'); $t->string('timezone', 64);
            $t->string('mode', 24)->default('video'); $t->string('location_text', 255)->nullable();
            $t->string('status', 24)->default('scheduled');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->index(['tenant_id', 'starts_at']);
        });
        $this->table('recruitment_interview_panelists', function (Blueprint $t) {
            $t->foreignId('interview_id')->constrained('recruitment_interviews')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->unique(['interview_id', 'user_id']); $t->index(['tenant_id', 'user_id']);
        });
        $this->table('recruitment_scorecards', function (Blueprint $t) {
            $t->foreignId('interview_id')->constrained('recruitment_interviews')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->unsignedTinyInteger('rating'); $t->string('recommendation', 24);
            $t->text('strengths')->nullable(); $t->text('concerns')->nullable();
            $t->dateTime('submitted_at');
            $t->unique(['interview_id', 'user_id']);
        });
        $this->table('recruitment_offers', function (Blueprint $t) {
            $t->foreignId('application_id')->constrained('recruitment_applications')->restrictOnDelete();
            $t->unsignedInteger('version'); $t->string('status', 24)->default('draft');
            $t->string('job_title', 160); $t->date('start_date'); $t->date('expires_on')->nullable();
            $t->text('compensation_reference')->nullable(); $t->string('currency', 3)->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('decided_at')->nullable(); $t->dateTime('sent_at')->nullable(); $t->dateTime('responded_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->unique(['application_id', 'version']);
        });
        $this->table('recruitment_handovers', function (Blueprint $t) {
            $t->foreignId('offer_id')->constrained('recruitment_offers')->restrictOnDelete();
            $t->foreignId('application_id')->constrained('recruitment_applications')->restrictOnDelete();
            $t->unsignedBigInteger('employee_id')->index(); $t->unsignedBigInteger('person_id');
            $t->string('source', 80)->nullable();
            $t->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $t->unique('offer_id'); $t->unique('application_id');
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
        foreach (['recruitment_handovers', 'recruitment_offers', 'recruitment_scorecards', 'recruitment_interview_panelists', 'recruitment_interviews', 'recruitment_postings'] as $t) Schema::dropIfExists($t);
    }
};
