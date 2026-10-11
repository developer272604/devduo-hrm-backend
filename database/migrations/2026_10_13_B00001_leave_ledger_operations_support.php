<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4 workstream B: columns the adjustment (maker-checker) and expiry-alert flows need on top of the foundation schema. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_adjustments', function (Blueprint $t) {
            $t->foreignId('leave_type_id')->nullable()->after('employee_id')->constrained('leave_types')->restrictOnDelete();
            $t->date('effective_date')->nullable()->after('units');
            $t->date('expires_on')->nullable()->after('effective_date');
            $t->text('decision_note')->nullable()->after('decided_at');
        });
        Schema::table('leave_expiries', function (Blueprint $t) {
            $t->unsignedInteger('notified_days')->nullable()->after('notified_at');   // smallest "days before" threshold already announced
        });
    }

    public function down(): void
    {
        Schema::table('leave_expiries', fn (Blueprint $t) => $t->dropColumn('notified_days'));
        Schema::table('leave_adjustments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('leave_type_id');
            $t->dropColumn(['effective_date', 'expires_on', 'decision_note']);
        });
    }
};
