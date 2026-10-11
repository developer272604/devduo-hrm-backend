<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('action', 60);
            $t->json('params')->nullable();
            $t->json('employee_ids');
            $t->string('status', 20)->default('previewed'); // previewed|queued|processing|completed|partial|failed
            $t->timestamp('token_expires_at')->nullable();
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('applied')->default(0);
            $t->unsignedInteger('skipped')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->json('results')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_runs');
    }
};
