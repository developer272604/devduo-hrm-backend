<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Observed organization names so historical reports show the name that applied at the as-of date.
        Schema::create('analytics_org_name_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('org_type', 24);
            $t->unsignedBigInteger('org_id');
            $t->string('name');
            $t->date('effective_from');
            $t->timestamps();
            $t->index(['tenant_id', 'org_type', 'org_id', 'effective_from'], 'analytics_org_name_idx');
        });
        Schema::create('analytics_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('uuid')->unique();
            $t->foreignId('report_id')->constrained('analytics_reports')->cascadeOnDelete();
            $t->foreignId('owner_id')->constrained('users');
            $t->string('frequency', 10); // daily|weekly|monthly
            $t->unsignedTinyInteger('day_of_week')->nullable(); // 0=Sunday
            $t->unsignedTinyInteger('day_of_month')->nullable();
            $t->string('time_of_day', 5)->default('08:00'); // tenant timezone
            $t->json('recipient_ids');
            $t->json('channels');
            $t->boolean('active')->default(true);
            $t->timestamp('next_run_at')->nullable();
            $t->timestamp('last_run_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'active', 'next_run_at'], 'analytics_sched_due');
        });
        Schema::create('analytics_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('uuid')->unique();
            $t->foreignId('schedule_id')->constrained('analytics_schedules')->cascadeOnDelete();
            $t->foreignId('recipient_id')->constrained('users');
            $t->string('slot_key', 40); // the due slot (UTC ISO) this delivery belongs to
            $t->string('channel', 10)->default('in_app');
            $t->string('status', 24); // sent|skipped_permission|skipped_blocked|failed|unavailable
            $t->string('reason', 255)->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->string('path')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('last_attempt_at')->nullable();
            $t->timestamps();
            $t->unique(['schedule_id', 'recipient_id', 'slot_key', 'channel'], 'analytics_delivery_slot');
        });
        Schema::create('analytics_dashboards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('uuid')->unique();
            $t->foreignId('owner_id')->constrained('users');
            $t->string('name', 150);
            $t->string('audience', 12); // employee|manager|hr|executive
            $t->json('widgets');
            $t->timestamps();
        });
        Schema::create('analytics_export_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('uuid')->unique();
            $t->foreignId('requested_by')->constrained('users');
            $t->json('spec');
            $t->string('status', 16)->default('queued'); // queued|running|completed|failed|expired
            $t->unsignedInteger('row_limit');
            $t->unsignedInteger('row_count')->nullable();
            $t->json('masked_columns')->nullable();
            $t->string('error', 255)->nullable();
            $t->string('path')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['analytics_export_jobs', 'analytics_dashboards', 'analytics_deliveries', 'analytics_schedules', 'analytics_org_name_history'] as $n) {
            Schema::dropIfExists($n);
        }
    }
};
