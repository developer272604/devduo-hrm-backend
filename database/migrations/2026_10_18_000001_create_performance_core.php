<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 8 core: goals/OKRs/KPIs, review cycles + templates + scales, reviews/responses/items, calibration, feedback, 1:1s, development plans, PIPs. */
return new class extends Migration
{
    public function up(): void
    {
        $this->table('performance_rating_scales', function (Blueprint $t) {
            $t->string('code', 40); $t->string('name', 120); $t->decimal('min_value', 5, 2); $t->decimal('max_value', 5, 2);
            $t->json('labels'); $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'code']);
        });
        $this->table('performance_competencies', function (Blueprint $t) {
            $t->string('code', 40); $t->string('name', 120); $t->text('description')->nullable(); $t->string('category', 40)->default('competency');
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'code']);
        });
        $this->table('performance_templates', function (Blueprint $t) {
            $t->string('name', 160); $t->foreignId('scale_id')->constrained('performance_rating_scales')->restrictOnDelete();
            $t->json('sections');          // [{key,title,type:goals|competencies|values,weight,competency_codes?}]
            $t->json('reviewer_weights');  // {manager,self,peer,skip} percentages summing to 100
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
        });
        $this->table('performance_cycles', function (Blueprint $t) {
            $t->string('name', 160); $t->string('kind', 24)->default('annual');
            $t->date('start_date'); $t->date('end_date');
            $t->foreignId('template_id')->constrained('performance_templates')->restrictOnDelete();
            $t->string('status', 24)->default('draft'); // draft|published|open|calibration|closed
            $t->date('self_due')->nullable(); $t->date('manager_due')->nullable(); $t->date('peer_due')->nullable(); $t->date('calibration_due')->nullable();
            $t->json('eligibility')->nullable(); $t->json('settings')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->dateTime('opened_at')->nullable(); $t->dateTime('closed_at')->nullable();
            $t->index(['tenant_id', 'status']);
        });
        $this->table('performance_goals', function (Blueprint $t) {
            $t->string('level', 16);            // company|department|team|individual
            $t->string('kind', 16)->default('goal'); // goal|objective|kpi
            $t->unsignedBigInteger('parent_id')->nullable()->index();
            $t->unsignedBigInteger('owner_employee_id')->nullable()->index();
            $t->unsignedBigInteger('department_id')->nullable()->index();
            $t->unsignedBigInteger('cycle_id')->nullable()->index();
            $t->string('title', 200); $t->text('description')->nullable();
            $t->decimal('weight', 6, 2)->default(100);
            $t->date('start_date'); $t->date('end_date');
            $t->string('status', 24)->default('draft'); // draft|pending_approval|active|completed|cancelled|rejected
            $t->string('visibility', 16)->default('team'); // owner|team|company
            $t->string('progress_mode', 12)->default('rollup'); // rollup (key results/children) | manual | metric
            $t->decimal('progress', 6, 2)->default(0);
            $t->string('unit', 24)->nullable(); $t->string('direction', 12)->default('increase');
            $t->decimal('start_value', 18, 4)->nullable(); $t->decimal('target_value', 18, 4)->nullable(); $t->decimal('current_value', 18, 4)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('approved_by')->nullable(); $t->dateTime('approved_at')->nullable(); $t->string('decision_note', 500)->nullable();
            $t->index(['tenant_id', 'status']); $t->index(['tenant_id', 'level']);
        });
        $this->table('performance_key_results', function (Blueprint $t) {
            $t->foreignId('goal_id')->constrained('performance_goals')->restrictOnDelete();
            $t->string('title', 200); $t->decimal('weight', 6, 2)->default(1);
            $t->string('unit', 24)->nullable(); $t->string('direction', 12)->default('increase');
            $t->decimal('start_value', 18, 4)->default(0); $t->decimal('target_value', 18, 4); $t->decimal('current_value', 18, 4)->nullable();
            $t->decimal('progress', 6, 2)->default(0);
        });
        $this->table('performance_checkins', function (Blueprint $t) { // append-only
            $t->foreignId('goal_id')->constrained('performance_goals')->restrictOnDelete();
            $t->unsignedBigInteger('key_result_id')->nullable();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->decimal('value', 18, 4)->nullable(); $t->decimal('progress_before', 6, 2); $t->decimal('progress_after', 6, 2);
            $t->string('confidence', 12)->default('on_track'); $t->text('note')->nullable();
        });
        $this->table('performance_reviews', function (Blueprint $t) {
            $t->foreignId('cycle_id')->constrained('performance_cycles')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->unsignedBigInteger('manager_employee_id')->nullable(); $t->unsignedBigInteger('department_id')->nullable(); $t->unsignedBigInteger('legal_entity_id')->nullable();
            $t->string('status', 24)->default('in_progress'); // in_progress|calibration|finalized|acknowledged
            $t->decimal('computed_score', 7, 3)->nullable(); $t->decimal('final_score', 7, 3)->nullable(); $t->string('final_rating', 80)->nullable();
            $t->json('trace')->nullable(); $t->json('snapshot')->nullable();
            $t->dateTime('finalized_at')->nullable(); $t->unsignedBigInteger('finalized_by')->nullable(); $t->dateTime('acknowledged_at')->nullable(); $t->text('employee_comment')->nullable();
            $t->unique(['cycle_id', 'employee_id']); $t->index(['tenant_id', 'status']);
        });
        $this->table('performance_review_responses', function (Blueprint $t) {
            $t->foreignId('review_id')->constrained('performance_reviews')->restrictOnDelete();
            $t->string('reviewer_type', 12); // self|manager|peer|skip
            $t->unsignedBigInteger('reviewer_employee_id')->nullable(); $t->unsignedBigInteger('reviewer_user_id')->nullable()->index();
            $t->string('status', 12)->default('pending'); $t->date('due_on')->nullable();
            $t->decimal('score', 7, 3)->nullable(); $t->text('comments')->nullable(); $t->dateTime('submitted_at')->nullable(); $t->dateTime('reminded_at')->nullable();
            $t->index(['review_id', 'reviewer_type']);
        });
        $this->table('performance_review_items', function (Blueprint $t) {
            $t->foreignId('response_id')->constrained('performance_review_responses')->restrictOnDelete();
            $t->string('section', 40); $t->string('ref', 80); $t->string('label', 200);
            $t->decimal('weight', 6, 2)->default(1); $t->decimal('rating', 5, 2); $t->text('comment')->nullable();
        });
        $this->table('performance_calibration_changes', function (Blueprint $t) {
            $t->foreignId('review_id')->constrained('performance_reviews')->restrictOnDelete();
            $t->decimal('from_score', 7, 3)->nullable(); $t->decimal('to_score', 7, 3);
            $t->text('justification');
            $t->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 12)->default('pending'); // pending|approved|rejected
            $t->unsignedBigInteger('decided_by')->nullable(); $t->dateTime('decided_at')->nullable(); $t->string('decision_note', 500)->nullable();
        });
        $this->table('performance_feedback', function (Blueprint $t) {
            $t->foreignId('to_employee_id')->constrained('employees')->restrictOnDelete();
            $t->foreignId('from_user_id')->constrained('users')->restrictOnDelete();
            $t->string('kind', 16)->default('feedback'); // feedback|recognition|development
            $t->string('visibility', 20)->default('recipient'); // recipient|recipient_manager
            $t->boolean('anonymous')->default(false); $t->text('message');
        });
        $this->table('performance_one_on_ones', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->date('held_on'); $t->text('shared_notes')->nullable(); $t->text('private_notes')->nullable(); $t->json('action_items')->nullable();
            $t->index(['tenant_id', 'employee_id', 'held_on']);
        });
        $this->table('performance_development_plans', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->string('title', 200); $t->text('description')->nullable(); $t->string('status', 16)->default('draft'); $t->date('target_date')->nullable();
            $t->unsignedBigInteger('goal_id')->nullable(); $t->unsignedBigInteger('review_id')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
        });
        $this->table('performance_development_actions', function (Blueprint $t) {
            $t->foreignId('plan_id')->constrained('performance_development_plans')->restrictOnDelete();
            $t->string('title', 200); $t->unsignedBigInteger('skill_id')->nullable(); $t->string('learning_ref', 120)->nullable();
            $t->date('due_on')->nullable(); $t->string('status', 16)->default('open'); $t->dateTime('completed_at')->nullable();
        });
        $this->table('performance_pips', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->unsignedBigInteger('manager_employee_id')->nullable();
            $t->string('status', 24)->default('draft'); // draft|active|completed_success|completed_unsuccessful|cancelled
            $t->text('reason'); $t->text('support_plan')->nullable(); $t->date('start_date'); $t->date('end_date'); $t->text('outcome_note')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->index(['tenant_id', 'employee_id']);
        });
        $this->table('performance_pip_checkpoints', function (Blueprint $t) {
            $t->foreignId('pip_id')->constrained('performance_pips')->restrictOnDelete();
            $t->date('due_on'); $t->text('expectation'); $t->string('status', 16)->default('pending'); // pending|met|partially_met|not_met
            $t->text('note')->nullable(); $t->unsignedBigInteger('recorded_by')->nullable(); $t->dateTime('recorded_at')->nullable(); $t->dateTime('reminded_at')->nullable();
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
        foreach (['performance_pip_checkpoints', 'performance_pips', 'performance_development_actions', 'performance_development_plans', 'performance_one_on_ones', 'performance_feedback', 'performance_calibration_changes',
            'performance_review_items', 'performance_review_responses', 'performance_reviews', 'performance_checkins', 'performance_key_results', 'performance_goals', 'performance_cycles', 'performance_templates', 'performance_competencies', 'performance_rating_scales'] as $t) Schema::dropIfExists($t);
    }
};
