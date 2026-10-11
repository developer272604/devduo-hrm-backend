<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_api_clients', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name', 150); $t->string('key_prefix', 16)->unique(); $t->string('key_hash', 64);
            $t->string('previous_key_hash', 64)->nullable(); $t->timestamp('previous_valid_until')->nullable();
            $t->json('scopes'); $t->unsignedInteger('rate_limit_per_minute')->default(60); $t->json('ip_allowlist')->nullable();
            $t->string('status', 16)->default('active'); $t->timestamp('expires_at')->nullable();
            $t->timestamp('last_used_at')->nullable(); $t->timestamp('rotated_at')->nullable(); $t->timestamp('revoked_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->index(['tenant_id', 'status']);
        });
        Schema::create('integration_api_requests', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('client_id')->constrained('integration_api_clients')->restrictOnDelete();
            $t->string('method', 8); $t->string('path', 200); $t->unsignedSmallInteger('status'); $t->string('ip', 45)->nullable();
            $t->timestamp('created_at'); $t->index(['tenant_id', 'client_id', 'created_at']);
        });
        Schema::create('integration_webhook_endpoints', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name', 150); $t->string('url', 500); $t->text('secret'); $t->json('events');
            $t->string('status', 16)->default('active'); $t->boolean('allow_insecure')->default(false);
            $t->unsignedBigInteger('last_event_id')->default(0); $t->unsignedInteger('consecutive_failures')->default(0);
            $t->timestamp('last_success_at')->nullable(); $t->timestamp('last_failure_at')->nullable(); $t->timestamp('secret_rotated_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->index(['tenant_id', 'status']);
        });
        Schema::create('integration_webhook_deliveries', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('endpoint_id')->constrained('integration_webhook_endpoints')->restrictOnDelete();
            $t->string('event_type', 80); $t->string('idempotency_key', 120); $t->json('payload');
            $t->string('status', 16)->default('pending'); $t->unsignedInteger('attempts')->default(0); $t->unsignedInteger('replays')->default(0);
            $t->timestamp('next_at')->nullable(); $t->unsignedSmallInteger('last_status_code')->nullable(); $t->string('last_error', 300)->nullable();
            $t->timestamp('delivered_at')->nullable(); $t->timestamps();
            $t->unique(['endpoint_id', 'idempotency_key']); $t->index(['tenant_id', 'status', 'next_at']);
        });
        Schema::create('integration_import_profiles', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name', 150); $t->string('kind', 32); $t->string('format', 8)->default('csv'); $t->json('mapping')->nullable(); $t->json('options')->nullable();
            $t->string('source', 16)->default('upload'); $t->string('status', 16)->default('active'); $t->unsignedInteger('interval_minutes')->nullable();
            $t->timestamp('next_run_at')->nullable(); $t->timestamp('last_run_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->index(['tenant_id', 'status', 'next_run_at']);
        });
        Schema::create('integration_job_runs', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('profile_id')->constrained('integration_import_profiles')->restrictOnDelete();
            $t->string('idempotency_key', 120); $t->string('trigger', 16)->default('manual'); $t->boolean('dry_run')->default(false);
            $t->string('status', 24)->default('pending'); $t->string('file_name', 250); $t->string('file_path', 300); $t->string('file_hash', 64);
            $t->unsignedInteger('attempts')->default(0); $t->timestamp('next_retry_at')->nullable();
            $t->unsignedInteger('total_rows')->default(0); $t->unsignedInteger('valid_rows')->default(0); $t->unsignedInteger('error_rows')->default(0); $t->unsignedInteger('imported_rows')->default(0);
            $t->string('target_import_uuid', 40)->nullable(); $t->string('error_code', 60)->nullable(); $t->string('error_message', 400)->nullable();
            $t->foreignId('started_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('started_at')->nullable(); $t->timestamp('finished_at')->nullable(); $t->timestamps();
            $t->unique(['tenant_id', 'idempotency_key']); $t->index(['tenant_id', 'profile_id', 'status']);
        });
        Schema::create('integration_job_items', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('run_id')->constrained('integration_job_runs')->cascadeOnDelete();
            $t->unsignedInteger('row_number'); $t->string('status', 16); $t->string('message', 500)->nullable(); $t->timestamps();
            $t->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['integration_job_items', 'integration_job_runs', 'integration_import_profiles', 'integration_webhook_deliveries', 'integration_webhook_endpoints', 'integration_api_requests', 'integration_api_clients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
