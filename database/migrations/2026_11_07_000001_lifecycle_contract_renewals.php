<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_contract_renewals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->date('current_contract_end')->nullable();
            $t->date('new_contract_end');
            $t->string('status', 24)->default('pending');
            $t->text('note')->nullable();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->foreignId('case_id')->nullable()->constrained('lifecycle_cases')->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'employee_id', 'status'], 'life_contract_employee_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_contract_renewals');
    }
};
