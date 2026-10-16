<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A case is a lead for human review: evidence + estimated exposure, never a verdict.
        Schema::create('intelligence_leakage_cases', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('fingerprint', 64);
            $t->string('rule_code', 40); $t->string('severity', 20);
            $t->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $t->date('window_from'); $t->date('window_to');
            $t->string('title', 300); $t->text('summary')->nullable();
            $t->decimal('exposure_amount', 18, 4)->nullable(); $t->char('currency', 3)->nullable(); $t->unsignedInteger('exposure_minutes')->nullable();
            $t->json('evidence'); $t->json('explain');
            $t->string('status', 20)->default('open'); $t->text('status_reason')->nullable();
            $t->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('work_item_id')->nullable();
            $t->decimal('recovered_amount', 18, 4)->nullable();
            $t->unsignedInteger('detections')->default(1);
            $t->timestamp('first_seen_at'); $t->timestamp('last_seen_at'); $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'fingerprint']);
            $t->index(['tenant_id', 'status', 'rule_code']);
            $t->index(['tenant_id', 'employee_id']);
        });
        Schema::create('intelligence_leakage_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('case_id')->constrained('intelligence_leakage_cases')->restrictOnDelete();
            $t->string('type', 30); $t->string('from_status', 20)->nullable(); $t->string('to_status', 20)->nullable();
            $t->text('reason')->nullable(); $t->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->json('meta')->nullable(); $t->timestamp('created_at');
            $t->index(['tenant_id', 'case_id']);
        });
        Schema::create('intelligence_leakage_settings', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete()->unique();
            $t->json('thresholds'); $t->json('disabled_rules')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['intelligence_leakage_settings', 'intelligence_leakage_events', 'intelligence_leakage_cases'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
