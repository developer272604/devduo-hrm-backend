<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 workstream A: configuration and compensation support.
 *   - payroll_periods: off-cycle / bonus / correction periods may share the dates of a regular period, so the uniqueness moves to
 *     (group, dates, period_type, cycle_no). Regular periods keep cycle_no = 1 (one regular period per date range, as before).
 *   - payroll_periods.parent_period_id links a correction / off-cycle period to the regular period it relates to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $t) {
            $t->string('period_type', 16)->default('regular');   // regular | off_cycle | bonus | correction
            $t->unsignedSmallInteger('cycle_no')->default(1);
            $t->foreignId('parent_period_id')->nullable()->constrained('payroll_periods')->restrictOnDelete();
            $t->string('currency', 3)->nullable();               // group currency at generation time (multi-currency reporting)
            $t->text('notes')->nullable();
            $t->unique(['tenant_id', 'payroll_group_id', 'period_start', 'period_end', 'period_type', 'cycle_no'], 'payroll_period_unique_v2');
        });
        Schema::table('payroll_periods', function (Blueprint $t) {
            $t->dropUnique('payroll_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $t) {
            $t->unique(['tenant_id', 'payroll_group_id', 'period_start', 'period_end'], 'payroll_period_unique');
        });
        Schema::table('payroll_periods', function (Blueprint $t) {
            $t->dropUnique('payroll_period_unique_v2');
            $t->dropForeign(['parent_period_id']);
            $t->dropColumn(['period_type', 'cycle_no', 'parent_period_id', 'currency', 'notes']);
        });
    }
};
