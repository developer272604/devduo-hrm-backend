<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lifecycle_resignations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->date('requested_last_day');
            $t->text('reason');
            $t->string('status', 32)->default('pending');
            $t->foreignId('separation_record_id')->nullable()->constrained('separation_records')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('decision_note')->nullable();
            $t->text('withdrawal_reason')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'employee_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('lifecycle_resignations'); }
};
