<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('provider_key', 80); $t->string('name', 150); $t->string('status', 24)->default('disabled');
            $t->string('credential_ref', 220)->nullable(); $t->json('scopes')->nullable(); $t->json('settings')->nullable();
            $t->string('health_status', 32)->default('not_configured'); $t->timestamp('last_checked_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->index(['tenant_id', 'provider_key', 'status']);
        });
        Schema::create('integration_sync_runs', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('connection_id')->constrained('integration_connections')->restrictOnDelete();
            $t->string('status', 24); $t->string('direction', 16)->default('diagnostic'); $t->unsignedInteger('attempts')->default(0);
            $t->json('summary')->nullable(); $t->string('error_code', 80)->nullable(); $t->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('started_at'); $t->timestamp('finished_at')->nullable(); $t->timestamps();
            $t->index(['tenant_id', 'connection_id', 'created_at']);
        });
        Schema::create('access_review_campaigns', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name', 150); $t->string('status', 24)->default('open'); $t->date('due_on');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamp('completed_at')->nullable(); $t->timestamps();
            $t->index(['tenant_id', 'status', 'due_on']);
        });
        Schema::create('access_review_items', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('campaign_id')->constrained('access_review_campaigns')->restrictOnDelete(); $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->json('role_snapshot'); $t->string('status', 24)->default('pending'); $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('note')->nullable(); $t->timestamp('reviewed_at')->nullable(); $t->timestamps();
            $t->unique(['campaign_id', 'user_id']); $t->index(['tenant_id', 'campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_review_items'); Schema::dropIfExists('access_review_campaigns');
        Schema::dropIfExists('integration_sync_runs'); Schema::dropIfExists('integration_connections');
    }
};
