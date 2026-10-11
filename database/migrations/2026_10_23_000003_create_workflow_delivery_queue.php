<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_trigger_cursors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('version_id')->unique()->constrained('workflow_versions');
            $t->unsignedBigInteger('event_id')->default(0);
            $t->timestamp('next_at')->nullable();
            $t->timestamps();
        });
        Schema::create('workflow_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('version_id')->constrained('workflow_versions');
            $t->foreignId('employee_id')->constrained('employees');
            $t->string('source_key', 100);
            $t->string('status')->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_at');
            $t->string('failure_code')->nullable();
            $t->uuid('instance_uuid')->nullable();
            $t->timestamps();
            $t->unique(['version_id', 'source_key']);
            $t->index(['tenant_id', 'status', 'next_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_deliveries');
        Schema::dropIfExists('workflow_trigger_cursors');
    }
};
