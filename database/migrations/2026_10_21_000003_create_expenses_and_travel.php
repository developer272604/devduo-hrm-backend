<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('expense_policies', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->uuid('uuid')->unique();
            $t->string('name', 150); $t->string('category', 80); $t->string('currency', 3); $t->unsignedTinyInteger('minor_units');
            $t->unsignedBigInteger('max_amount_minor'); $t->unsignedBigInteger('receipt_threshold_minor');
            $t->date('effective_from'); $t->date('effective_to')->nullable(); $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
        Schema::create('expense_claims', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->uuid('uuid')->unique();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete(); $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('policy_id')->constrained('expense_policies')->restrictOnDelete(); $t->json('policy_snapshot');
            $t->string('title', 200); $t->string('currency', 3); $t->unsignedTinyInteger('minor_units'); $t->unsignedBigInteger('total_minor');
            $t->string('status', 25)->default('draft'); $t->timestamp('submitted_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('reviewed_at')->nullable(); $t->text('decision_reason')->nullable();
            $t->string('payment_reference', 150)->nullable(); $t->foreignId('payment_confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('paid_at')->nullable(); $t->timestamps(); $t->unique(['tenant_id', 'payment_reference']); $t->index(['tenant_id', 'employee_id', 'status']);
        });
        Schema::create('expense_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->foreignId('claim_id')->constrained('expense_claims')->restrictOnDelete();
            $t->date('spent_on'); $t->string('merchant', 150); $t->string('description', 500); $t->unsignedBigInteger('amount_minor'); $t->timestamps();
        });
        Schema::create('expense_receipts', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->uuid('uuid')->unique();
            $t->foreignId('claim_id')->constrained('expense_claims')->restrictOnDelete(); $t->string('filename'); $t->string('path', 500);
            $t->string('mime_type', 100); $t->unsignedInteger('size_bytes'); $t->string('content_hash', 64); $t->timestamps();
            $t->unique(['claim_id', 'content_hash']);
        });
        Schema::create('expense_receipt_uses', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->foreignId('claim_id')->constrained('expense_claims')->restrictOnDelete();
            $t->string('content_hash', 64); $t->unique(['tenant_id', 'content_hash']);
        });
        Schema::create('travel_requests', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->uuid('uuid')->unique();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete(); $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('purpose', 500); $t->string('destination', 200); $t->date('starts_on'); $t->date('ends_on'); $t->string('timezone', 80);
            $t->string('currency', 3); $t->unsignedTinyInteger('minor_units'); $t->unsignedBigInteger('budget_minor');
            $t->string('status', 25)->default('draft'); $t->timestamp('submitted_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('reviewed_at')->nullable();
            $t->text('decision_reason')->nullable(); $t->timestamps(); $t->index(['tenant_id', 'employee_id', 'status']);
        });
    }
    public function down(): void {
        foreach (['travel_requests', 'expense_receipt_uses', 'expense_receipts', 'expense_lines', 'expense_claims', 'expense_policies'] as $table) Schema::dropIfExists($table);
    }
};
