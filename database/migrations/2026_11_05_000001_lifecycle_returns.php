<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('lifecycle_returns', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('status_history_id')->nullable();
            $t->string('status', 24)->default('pending');
            $t->text('note'); $t->text('decision_note')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->date('returned_on')->nullable();
            $t->foreignId('case_id')->nullable()->constrained('lifecycle_cases')->restrictOnDelete();
            $t->timestamps(); $t->index(['tenant_id', 'employee_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('lifecycle_returns'); }
};
