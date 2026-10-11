<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_benefit_enrollments', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('status')->constrained('users')->restrictOnDelete();
            $table->index(['tenant_id', 'created_by'], 'benefit_enrollment_maker');
        });
    }

    public function down(): void
    {
        Schema::table('employee_benefit_enrollments', function (Blueprint $table) {
            $table->dropIndex('benefit_enrollment_maker');
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
