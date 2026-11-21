<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_shift_openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('roster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('awarded_employee_id')->nullable()->constrained('employees');
            $table->uuid('uuid')->unique();
            $table->date('work_date');
            $table->string('status', 16)->default('open');
            $table->timestamps();
            $table->index(['tenant_id', 'created_by', 'work_date']);
        });
        Schema::create('roster_shift_bids', function (Blueprint $table) {
            $table->foreignId('opening_id')->constrained('roster_shift_openings')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained();
            $table->timestamp('bid_at')->nullable();
            $table->primary(['opening_id', 'employee_id']);
            $table->index(['employee_id', 'opening_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_shift_bids');
        Schema::dropIfExists('roster_shift_openings');
    }
};
