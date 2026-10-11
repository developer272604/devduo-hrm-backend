<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance requests & approvals support tables:
 *  - attendance_bulk_runs            preview/confirm/apply runs of the attendance bulk runner (targets + results, signed-token bound)
 *  - attendance_request_notices      dedupe ledger so reminders and notifications never spam (unique per user + key)
 *  - approval_delegations.uuid       public id for delegation URLs (the fixed schema has none)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_bulk_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('action', 60);
            $t->json('params')->nullable();
            $t->json('targets');                                 // normalized targets (internal ids)
            $t->string('targets_hash', 64);
            $t->string('params_hash', 64);
            $t->string('status', 14)->default('previewed');      // previewed | queued | processing | completed | partial | failed
            $t->timestamp('token_expires_at')->nullable();
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('applied')->default(0);
            $t->unsignedInteger('skipped')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->json('preview')->nullable();
            $t->json('results')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'user_id', 'created_at']);
        });

        Schema::create('attendance_request_notices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('dedupe_key', 160);
            $t->timestamp('sent_at');
            $t->unique(['tenant_id', 'user_id', 'dedupe_key'], 'attendance_notice_dedupe');
        });

        Schema::table('approval_delegations', function (Blueprint $t) {
            $t->uuid('uuid')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('approval_delegations', function (Blueprint $t) {
            $t->dropUnique(['uuid']);
            $t->dropColumn('uuid');
        });
        Schema::dropIfExists('attendance_request_notices');
        Schema::dropIfExists('attendance_bulk_runs');
    }
};
