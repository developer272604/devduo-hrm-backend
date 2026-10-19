<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            // Existing workspaces keep working: only self-registered tenants start as 'trial'.
            $t->string('status', 20)->default('active')->index('tenants_status_idx');
            $t->string('status_reason', 40)->nullable();
            $t->text('status_note')->nullable();
            $t->timestamp('status_changed_at')->nullable();
            $t->timestamp('trial_started_at')->nullable();
            $t->timestamp('trial_ends_at')->nullable()->index('tenants_trial_ends_idx');
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->string('plan', 40)->default('standard');
            $t->timestamp('subscription_ends_at')->nullable();
            $t->boolean('is_platform')->default(false);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_owner')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_owner'));
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropIndex('tenants_status_idx');
            $t->dropIndex('tenants_trial_ends_idx');
            $t->dropColumn(['status', 'status_reason', 'status_note', 'status_changed_at', 'trial_started_at', 'trial_ends_at', 'approved_at', 'approved_by', 'plan', 'subscription_ends_at', 'is_platform']);
        });
    }
};
