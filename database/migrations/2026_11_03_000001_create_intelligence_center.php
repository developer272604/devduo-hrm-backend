<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (tenant, audience user, insight key): findings are generated through the audience's own permissions and data scope.
        Schema::create('intelligence_findings', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('fingerprint', 64);
            $t->foreignId('audience_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('domain', 60); $t->string('insight_key', 120); $t->string('severity', 20);
            $t->string('title', 300); $t->text('description')->nullable(); $t->unsignedInteger('count')->default(1);
            $t->string('status', 20)->default('open'); $t->text('status_reason')->nullable();
            $t->date('due_on')->nullable(); $t->timestamp('snoozed_until')->nullable();
            $t->json('evidence'); $t->json('explain');
            $t->unsignedBigInteger('work_item_id')->nullable();
            $t->unsignedInteger('occurrences')->default(1);
            $t->timestamp('first_seen_at'); $t->timestamp('last_seen_at'); $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'fingerprint']);
            $t->index(['tenant_id', 'audience_user_id', 'status']);
        });
        Schema::create('intelligence_finding_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('finding_id')->constrained('intelligence_findings')->restrictOnDelete();
            $t->string('type', 30); $t->string('from_status', 20)->nullable(); $t->string('to_status', 20)->nullable();
            $t->text('reason')->nullable(); $t->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->json('meta')->nullable(); $t->timestamp('created_at');
            $t->index(['tenant_id', 'finding_id']);
        });
        Schema::create('intelligence_scenario_runs', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('kind', 40); $t->string('title', 200)->nullable(); $t->json('params'); $t->json('result');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->index(['tenant_id', 'created_by']);
        });
        Schema::create('intelligence_settings', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete()->unique();
            $t->boolean('risk_signals_enabled')->default(false); $t->unsignedSmallInteger('min_cohort')->default(5);
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['intelligence_settings', 'intelligence_scenario_runs', 'intelligence_finding_events', 'intelligence_findings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
