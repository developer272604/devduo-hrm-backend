<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 15 controls: MFA, sessions, password policy, tamper-evident audit chain, legal hold and retention registry. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('password_changed_at')->nullable();
            $t->unsignedSmallInteger('failed_logins')->default(0);
            $t->timestamp('locked_until')->nullable();
        });
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->unsignedBigInteger('sequence')->nullable();
            $t->char('prev_hash', 64)->nullable();
            $t->char('row_hash', 64)->nullable();
            $t->unique(['tenant_id', 'sequence']);
        });
        Schema::create('audit_chain_heads', function (Blueprint $t) {
            $t->unsignedBigInteger('tenant_id')->primary();
            $t->unsignedBigInteger('last_sequence')->default(0);
            $t->char('last_hash', 64);
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('security_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->unique()->constrained()->restrictOnDelete();
            $t->json('settings');
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
        Schema::create('user_mfa_factors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->text('secret'); // encrypted at rest
            $t->timestamp('confirmed_at')->nullable();
            $t->unsignedBigInteger('last_used_step')->nullable();
            $t->timestamps();
            $t->index('tenant_id');
        });
        Schema::create('user_mfa_recovery_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->char('code_hash', 64);
            $t->timestamp('used_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['user_id', 'code_hash']);
        });
        Schema::create('user_sessions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->char('session_hash', 64)->index(); // sha256 of the session id; the id itself is never stored
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->string('device', 120)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoke_reason', 60)->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'user_id', 'revoked_at']);
        });
        Schema::create('password_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('password_hash');
            $t->timestamp('created_at')->nullable();
            $t->index(['user_id', 'id']);
        });
        Schema::create('legal_holds', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name', 150);
            $t->text('reason');
            $t->string('module', 40)->nullable(); // null = whole workspace
            $t->string('subject_ref', 100)->nullable(); // e.g. candidate:<uuid>; null = module-wide
            $t->unsignedBigInteger('placed_by');
            $t->timestamp('placed_at');
            $t->unsignedBigInteger('released_by')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->text('release_reason')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'released_at', 'module']);
        });
        Schema::create('retention_policies', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('data_category', 100);
            $t->string('module', 40);
            $t->unsignedInteger('retention_days');
            $t->string('action', 20)->default('review'); // review | purge | anonymize: documents intent; enforced by module jobs
            $t->string('enforced_by', 120)->nullable();
            $t->boolean('enabled')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'data_category']);
        });
    }

    public function down(): void
    {
        foreach (['retention_policies', 'legal_holds', 'password_histories', 'user_sessions', 'user_mfa_recovery_codes', 'user_mfa_factors', 'security_policies', 'audit_chain_heads'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'sequence']);
            $t->dropColumn(['sequence', 'prev_hash', 'row_hash']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['password_changed_at', 'failed_logins', 'locked_until']);
        });
    }
};
