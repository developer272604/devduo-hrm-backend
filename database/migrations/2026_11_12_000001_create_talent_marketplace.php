<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Employees opt in to being found and state the spare hours they can give. No profile row = not discoverable, no capacity.
        Schema::create('talent_profiles', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->boolean('discoverable')->default(false); $t->unsignedSmallInteger('spare_hours_per_week')->default(0); $t->string('interests', 500)->nullable();
            $t->timestamps(); $t->unique(['tenant_id', 'employee_id']);
        });
        Schema::create('talent_opportunities', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $t->string('title', 200); $t->text('description')->nullable();
            $t->string('kind', 16)->default('project'); // project | gig | stretch | role_trial
            $t->unsignedSmallInteger('hours_per_week'); $t->date('starts_on'); $t->date('ends_on')->nullable(); $t->date('closes_on')->nullable();
            $t->unsignedSmallInteger('openings')->default(1);
            $t->string('status', 12)->default('draft'); // draft | open | closed | filled | cancelled
            $t->timestamps(); $t->index(['tenant_id', 'status']);
        });
        Schema::create('talent_opportunity_skills', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('opportunity_id')->constrained('talent_opportunities')->restrictOnDelete();
            $t->foreignId('skill_id')->constrained('skills')->restrictOnDelete();
            $t->unsignedTinyInteger('required_rank'); $t->boolean('mandatory')->default(true);
            $t->unique(['opportunity_id', 'skill_id']);
        });
        Schema::create('talent_applications', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('opportunity_id')->constrained('talent_opportunities')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            // interested | shortlisted | awaiting_manager | placed | completed | declined | withdrawn | release_denied | ended_early
            $t->string('status', 20)->default('interested');
            $t->string('note', 1000)->nullable(); $t->text('decision_reason')->nullable();
            $t->unsignedSmallInteger('hours_committed')->default(0);
            $t->foreignId('release_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('release_decided_at')->nullable();
            $t->timestamp('placed_at')->nullable(); $t->timestamp('closed_at')->nullable(); $t->json('attested_skills')->nullable();
            $t->timestamps(); $t->unique(['opportunity_id', 'employee_id']); $t->index(['tenant_id', 'employee_id', 'status']);
        });
        Schema::create('talent_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('opportunity_id')->constrained('talent_opportunities')->restrictOnDelete();
            $t->foreignId('application_id')->nullable()->constrained('talent_applications')->restrictOnDelete();
            $t->string('type', 30); $t->text('detail')->nullable();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('created_at');
            $t->index(['tenant_id', 'opportunity_id']);
        });
    }

    public function down(): void
    {
        foreach (['talent_events', 'talent_applications', 'talent_opportunity_skills', 'talent_opportunities', 'talent_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
