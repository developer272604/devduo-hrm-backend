<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 workstream B: small additive support columns for the run engine (never edits applied migrations).
 *  - payroll_runs.progress        queued calculation progress {pass, total, done, failed, started_at, finished_at}
 *  - payroll_runs.notes           free-text run note (visible to payroll team)
 *  - payroll_run_employees.calc_pass   the calculation pass that last processed the employee: makes chunked jobs idempotent and retry-safe
 *  - payroll_inputs.rejection_reason   why an input was rejected
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $t) {
            $t->json('progress')->nullable();
            $t->text('notes')->nullable();
        });
        Schema::table('payroll_run_employees', function (Blueprint $t) {
            $t->unsignedInteger('calc_pass')->default(0);
        });
        Schema::table('payroll_inputs', function (Blueprint $t) {
            $t->string('rejection_reason', 400)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_inputs', fn (Blueprint $t) => $t->dropColumn('rejection_reason'));
        Schema::table('payroll_run_employees', fn (Blueprint $t) => $t->dropColumn('calc_pass'));
        Schema::table('payroll_runs', fn (Blueprint $t) => $t->dropColumn(['progress', 'notes']));
    }
};
