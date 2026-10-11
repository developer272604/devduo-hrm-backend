<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Workstream D: append-only sensitive-access log, export purpose/masking columns, simulation title. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_sensitive_access_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('data_type', 32);          // compensation payroll_result payslip bank export simulation
            $t->string('action', 16);             // view reveal export download
            $t->string('purpose_code', 32)->nullable();
            $t->string('purpose_note', 300)->nullable();
            $t->boolean('revealed')->default(false);
            $t->string('surface', 40)->nullable();
            $t->string('record_ref', 80)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'created_at'], 'psal_tenant_time');
            $t->index(['tenant_id', 'employee_id'], 'psal_employee');
        });
        Schema::table('payroll_exports', function (Blueprint $t) {
            $t->string('purpose_code', 32)->nullable();
            $t->string('purpose_note', 300)->nullable();
            $t->boolean('masked')->default(true);
        });
        Schema::table('payroll_simulation_runs', function (Blueprint $t) {
            $t->string('title', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_simulation_runs', fn (Blueprint $t) => $t->dropColumn('title'));
        Schema::table('payroll_exports', fn (Blueprint $t) => $t->dropColumn(['purpose_code', 'purpose_note', 'masked']));
        Schema::dropIfExists('payroll_sensitive_access_logs');
    }
};
