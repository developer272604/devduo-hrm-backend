<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Workstream D: queued, expiring, per-user leave report exports (same shape as attendance_exports). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_exports', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('report', 40);
            $t->string('format', 10);
            $t->json('filters')->nullable();
            $t->string('status', 20)->default('queued'); // queued|processing|completed|failed
            $t->string('path')->nullable();
            $t->unsignedInteger('row_count')->default(0);
            $t->timestamp('expires_at')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_exports');
    }
};
