<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 11 depth: asset imports/history, expense rule engine, FX, travel desk. Money is stored as integer minor units; rates as fixed-scale decimals. */
return new class extends Migration {
    private function base(Blueprint $t, bool $uuid = true): void {
        $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); if ($uuid) $t->uuid('uuid')->unique();
    }
    public function up(): void {
        Schema::create('operations_previews', function (Blueprint $t) {
            $this->base($t); $t->string('kind', 30); $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('filename')->nullable(); $t->string('action', 25)->nullable(); $t->longText('payload'); $t->json('summary');
            $t->string('status', 20)->default('preview'); $t->timestamp('expires_at'); $t->timestamp('committed_at')->nullable(); $t->timestamps();
            $t->index(['tenant_id', 'kind', 'status']);
        });
        Schema::create('asset_maintenance', function (Blueprint $t) {
            $this->base($t); $t->foreignId('asset_id')->constrained('assets')->restrictOnDelete(); $t->string('kind', 25); $t->string('vendor', 150)->nullable();
            $t->string('currency', 3)->nullable(); $t->unsignedTinyInteger('minor_units')->default(2); $t->unsignedBigInteger('cost_minor')->default(0);
            $t->date('started_on'); $t->date('completed_on')->nullable(); $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps(); $t->index(['tenant_id', 'asset_id']);
        });
        Schema::create('asset_procurement', function (Blueprint $t) {
            $this->base($t); $t->foreignId('asset_id')->constrained('assets')->restrictOnDelete(); $t->string('kind', 25); $t->string('vendor', 150)->nullable(); $t->string('reference', 150)->nullable();
            $t->date('purchased_on')->nullable(); $t->string('currency', 3)->nullable(); $t->unsignedTinyInteger('minor_units')->default(2);
            $t->unsignedBigInteger('cost_minor')->nullable(); $t->unsignedBigInteger('salvage_minor')->default(0); $t->unsignedSmallInteger('useful_life_months')->nullable();
            $t->date('warranty_until')->nullable(); $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps(); $t->index(['tenant_id', 'asset_id']);
        });

        Schema::create('expense_limit_rules', function (Blueprint $t) {
            $this->base($t); $t->uuid('rule_key'); $t->unsignedInteger('version'); $t->string('name', 150); $t->string('category', 80);
            $t->string('country', 2)->nullable(); $t->unsignedBigInteger('legal_entity_id')->nullable(); $t->unsignedBigInteger('grade_id')->nullable();
            $t->string('currency', 3); $t->unsignedTinyInteger('minor_units');
            $t->unsignedBigInteger('per_line_limit_minor')->nullable(); $t->unsignedBigInteger('category_total_limit_minor')->nullable();
            $t->unsignedBigInteger('receipt_over_minor')->nullable(); $t->boolean('receipt_always')->default(false);
            $t->string('action', 10)->default('warn'); $t->date('effective_from'); $t->date('effective_to')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->unique(['tenant_id', 'rule_key', 'version']); $t->index(['tenant_id', 'category']);
        });
        Schema::create('expense_rate_tables', function (Blueprint $t) {
            $this->base($t); $t->uuid('rate_key'); $t->unsignedInteger('version'); $t->string('kind', 12); $t->string('name', 150);
            $t->string('country', 2)->nullable(); $t->unsignedBigInteger('grade_id')->nullable(); $t->string('currency', 3); $t->unsignedTinyInteger('minor_units');
            $t->string('unit', 10); $t->decimal('rate', 18, 6); $t->date('effective_from'); $t->date('effective_to')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->unique(['tenant_id', 'rate_key', 'version']); $t->index(['tenant_id', 'kind']);
        });
        Schema::create('expense_fx_rates', function (Blueprint $t) {
            $this->base($t); $t->string('base_currency', 3); $t->string('quote_currency', 3); $t->decimal('rate', 18, 8); $t->date('effective_on');
            $t->string('source', 40); $t->string('source_reference', 150)->nullable(); $t->boolean('is_override')->default(false);
            $t->unsignedBigInteger('claim_id')->nullable(); $t->text('reason')->nullable(); $t->string('status', 15)->default('active');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete(); $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable(); $t->text('decision_reason')->nullable(); $t->timestamps();
            $t->index(['tenant_id', 'base_currency', 'quote_currency', 'effective_on']);
        });

        Schema::table('expense_claims', function (Blueprint $t) {
            $t->unsignedBigInteger('travel_request_id')->nullable()->index(); $t->boolean('escalated')->default(false);
            $t->unsignedBigInteger('department_id')->nullable(); $t->unsignedBigInteger('legal_entity_id')->nullable(); $t->unsignedBigInteger('grade_id')->nullable(); $t->string('country', 2)->nullable();
            $t->timestamp('withdrawn_at')->nullable();
        });
        Schema::table('expense_lines', function (Blueprint $t) {
            $t->string('category', 80)->nullable(); $t->string('kind', 12)->default('amount'); $t->string('quantity', 20)->nullable(); $t->string('rate_applied', 30)->nullable();
            $t->unsignedBigInteger('receipt_id')->nullable(); $t->string('original_currency', 3)->nullable(); $t->unsignedBigInteger('original_amount_minor')->nullable();
            $t->unsignedBigInteger('fx_rate_id')->nullable(); $t->string('fx_rate_applied', 30)->nullable();
        });
        Schema::create('expense_line_allocations', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->foreignId('line_id')->constrained('expense_lines')->restrictOnDelete();
            $t->unsignedBigInteger('cost_center_id'); $t->unsignedBigInteger('amount_minor'); $t->timestamps();
        });
        Schema::create('expense_violations', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->uuid('uuid')->unique();
            $t->foreignId('claim_id')->constrained('expense_claims')->restrictOnDelete(); $t->unsignedBigInteger('line_id')->nullable(); $t->unsignedBigInteger('rule_id')->nullable();
            $t->string('code', 40); $t->string('severity', 10); $t->string('message', 500); $t->json('details')->nullable(); $t->timestamps();
            $t->index(['tenant_id', 'claim_id']); $t->index(['tenant_id', 'code']);
        });

        Schema::create('travel_advances', function (Blueprint $t) {
            $this->base($t); $t->foreignId('travel_request_id')->constrained('travel_requests')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete(); $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('currency', 3); $t->unsignedTinyInteger('minor_units'); $t->unsignedBigInteger('amount_minor'); $t->string('purpose', 300)->nullable();
            $t->string('status', 15)->default('requested'); $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('reviewed_at')->nullable(); $t->text('decision_reason')->nullable();
            $t->string('reference_type', 10)->nullable(); $t->string('finance_reference', 150)->nullable(); $t->foreignId('referenced_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('referenced_at')->nullable();
            $t->timestamps(); $t->unique(['tenant_id', 'finance_reference']); $t->index(['tenant_id', 'travel_request_id']);
        });
        Schema::create('travel_bookings', function (Blueprint $t) {
            $this->base($t); $t->foreignId('travel_request_id')->constrained('travel_requests')->restrictOnDelete(); $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('kind', 15); $t->string('vendor', 150); $t->string('confirmation_reference', 100)->nullable(); $t->date('starts_on'); $t->date('ends_on')->nullable();
            $t->string('currency', 3); $t->unsignedTinyInteger('minor_units'); $t->unsignedBigInteger('cost_minor')->default(0); $t->string('status', 12)->default('booked'); $t->timestamp('cancelled_at')->nullable();
            $t->timestamps(); $t->index(['tenant_id', 'travel_request_id']);
        });
        Schema::create('travel_reconciliations', function (Blueprint $t) {
            $this->base($t); $t->foreignId('travel_request_id')->constrained('travel_requests')->restrictOnDelete(); $t->foreignId('closed_by')->constrained('users')->restrictOnDelete();
            $t->string('currency', 3); $t->unsignedTinyInteger('minor_units'); $t->unsignedBigInteger('budget_minor'); $t->unsignedBigInteger('actual_minor'); $t->unsignedBigInteger('advance_minor');
            $t->bigInteger('variance_minor'); $t->bigInteger('settlement_minor'); $t->json('claims'); $t->text('note')->nullable(); $t->timestamp('closed_at'); $t->timestamps();
            $t->unique(['tenant_id', 'travel_request_id']);
        });
    }
    public function down(): void {
        foreach (['travel_reconciliations', 'travel_bookings', 'travel_advances', 'expense_violations', 'expense_line_allocations'] as $table) Schema::dropIfExists($table);
        Schema::table('expense_lines', fn (Blueprint $t) => $t->dropColumn(['category', 'kind', 'quantity', 'rate_applied', 'receipt_id', 'original_currency', 'original_amount_minor', 'fx_rate_id', 'fx_rate_applied']));
        Schema::table('expense_claims', fn (Blueprint $t) => $t->dropColumn(['travel_request_id', 'escalated', 'department_id', 'legal_entity_id', 'grade_id', 'country', 'withdrawn_at']));
        foreach (['expense_fx_rates', 'expense_rate_tables', 'expense_limit_rules', 'asset_procurement', 'asset_maintenance', 'operations_previews'] as $table) Schema::dropIfExists($table);
    }
};
