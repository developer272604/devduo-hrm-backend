<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_benefit_enrollments', function (Blueprint $table) {
            $table->foreignId('termination_requested_by')->nullable()->after('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('termination_decided_by')->nullable()->after('termination_requested_by')->constrained('users')->restrictOnDelete();
            $table->index(['tenant_id', 'termination_requested_by'], 'benefit_termination_maker');
        });
    }

    public function down(): void
    {
        Schema::table('employee_benefit_enrollments', function (Blueprint $table) {
            $table->dropIndex('benefit_termination_maker');
            $table->dropConstrainedForeignId('termination_decided_by');
            $table->dropConstrainedForeignId('termination_requested_by');
        });
    }
};
