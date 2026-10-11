<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 workstream C (loans, benefits, declarations, payslips, payments, accounting, statutory filings, settlement): support schema.
 * Additive only - 2026_10_14_000001 is never edited. New tables: loan/advance ledger (append-only), payslip access log, bank detail
 * verification, payment status events (append-only), tax declaration windows. New columns on the foundation tables are nullable/defaulted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_loan_ledger', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('subject_type', 10);                   // loan | advance
            $t->unsignedBigInteger('subject_id');
            $t->string('entry_type', 16);                     // recovery reversal waiver write_off
            $t->string('effect', 10);                         // reduce | increase (outstanding)
            $t->decimal('amount', 18, 4);                     // positive magnitude
            $t->unsignedBigInteger('installment_id')->nullable();
            $t->foreignId('run_id')->nullable()->constrained('payroll_runs')->restrictOnDelete();
            $t->foreignId('reverses_id')->nullable()->constrained('payroll_loan_ledger')->restrictOnDelete();
            $t->string('dedupe_key', 120)->nullable();
            $t->string('note', 300)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'dedupe_key'], 'loan_ledger_dedupe');
            $t->index(['tenant_id', 'subject_type', 'subject_id'], 'loan_ledger_subject');
        });

        Schema::create('payslip_access_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('payslip_id')->constrained('payslips')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action', 24);                         // viewed downloaded link_issued published withdrawn generated
            $t->string('ip', 64)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'payslip_id'], 'payslip_access_payslip');
        });

        Schema::create('payroll_bank_verifications', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('bank_account_id');
            $t->char('fingerprint', 64);                      // hmac of the account details: a changed account is NOT verified
            $t->string('status', 12)->default('verified');    // verified rejected
            $t->string('method', 24)->default('manual');      // manual document penny_drop provider
            $t->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at');
            $t->string('note', 300)->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'employee_id'], 'bank_verification_employee');
            $t->index(['tenant_id', 'bank_account_id', 'fingerprint'], 'bank_verification_account');
        });

        Schema::create('payment_status_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('batch_id')->constrained('payment_batches')->restrictOnDelete();
            $t->foreignId('instruction_id')->nullable()->constrained('payment_instructions')->restrictOnDelete();
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20);
            $t->string('source', 16);                         // manual import system
            $t->string('reference', 120)->nullable();
            $t->string('reason', 300)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'batch_id'], 'payment_event_batch');
        });

        Schema::create('payroll_tax_declaration_windows', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('pack_id')->constrained('payroll_country_packs')->restrictOnDelete();
            $t->string('tax_year', 9);
            $t->date('opens_on');
            $t->date('closes_on');
            $t->date('proof_closes_on')->nullable();
            $t->boolean('locked')->default(false);            // HR can lock early; employees cannot change anything after lock
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'pack_id', 'tax_year'], 'declaration_window_year');
        });

        Schema::table('employee_loans', function (Blueprint $t) {
            $t->uuid('idempotency_key')->nullable();
            $t->char('request_hash', 64)->nullable();
            $t->string('decision_note', 300)->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unique(['tenant_id', 'idempotency_key'], 'loan_request_key');
        });
        Schema::table('employee_advances', function (Blueprint $t) {
            $t->uuid('idempotency_key')->nullable();
            $t->char('request_hash', 64)->nullable();
            $t->string('decision_note', 300)->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unique(['tenant_id', 'idempotency_key'], 'advance_request_key');
        });
        Schema::table('payslips', function (Blueprint $t) {
            $t->timestamp('withdrawn_at')->nullable();
            $t->string('withdraw_reason', 300)->nullable();
            $t->unsignedBigInteger('withdrawn_by')->nullable();
            $t->unsignedInteger('download_count')->default(0);
        });
        Schema::table('payment_batches', function (Blueprint $t) {
            $t->date('payment_date')->nullable();
            $t->json('exceptions')->nullable();
            $t->json('reconciliation')->nullable();
            $t->unsignedBigInteger('retry_of_id')->nullable();
            $t->uuid('idempotency_key')->nullable();
            $t->char('request_hash', 64)->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->string('failure_note', 300)->nullable();
            $t->unique(['tenant_id', 'idempotency_key'], 'payment_batch_request_key');
        });
        Schema::table('payment_instructions', function (Blueprint $t) {
            $t->string('bank_reference', 120)->nullable();
            $t->unsignedTinyInteger('attempt')->default(1);
            $t->timestamp('status_at')->nullable();
        });
        Schema::table('accounting_exports', function (Blueprint $t) {
            $t->date('posting_date')->nullable();
            $t->unsignedInteger('line_count')->default(0);
            $t->char('checksum', 64)->nullable();
        });
        Schema::table('payroll_statutory_filings', function (Blueprint $t) {
            $t->string('reference', 120)->nullable();
            $t->timestamp('filed_at')->nullable();
            $t->unsignedBigInteger('filed_by')->nullable();
            $t->string('note', 400)->nullable();
            $t->char('checksum', 64)->nullable();
            $t->unsignedInteger('row_count')->default(0);
        });
        Schema::table('settlement_runs', function (Blueprint $t) {
            $t->json('inputs')->nullable();
            $t->unsignedInteger('calculation_version')->default(0);
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->char('request_hash', 64)->nullable();
            $t->string('decision_note', 300)->nullable();
        });
        Schema::table('employee_benefit_enrollments', function (Blueprint $t) {
            $t->string('source', 10)->default('admin');       // self | admin
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 300)->nullable();
        });
        Schema::table('payroll_tax_declarations', function (Blueprint $t) {
            $t->unsignedBigInteger('window_id')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('submitted_at')->nullable();
            $t->boolean('locked')->default(false);
        });
        Schema::table('payroll_tax_declaration_proofs', function (Blueprint $t) {
            $t->string('original_name', 200)->nullable();
            $t->string('mime', 80)->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_tax_declaration_proofs', fn (Blueprint $t) => $t->dropColumn(['original_name', 'mime', 'size', 'reviewed_by']));
        Schema::table('payroll_tax_declarations', fn (Blueprint $t) => $t->dropColumn(['window_id', 'version', 'submitted_at', 'locked']));
        Schema::table('employee_benefit_enrollments', fn (Blueprint $t) => $t->dropColumn(['source', 'decided_by', 'decided_at', 'decision_note']));
        Schema::table('settlement_runs', fn (Blueprint $t) => $t->dropColumn(['inputs', 'calculation_version', 'approved_at', 'paid_at', 'request_hash', 'decision_note']));
        Schema::table('payroll_statutory_filings', fn (Blueprint $t) => $t->dropColumn(['reference', 'filed_at', 'filed_by', 'note', 'checksum', 'row_count']));
        Schema::table('accounting_exports', fn (Blueprint $t) => $t->dropColumn(['posting_date', 'line_count', 'checksum']));
        Schema::table('payment_instructions', fn (Blueprint $t) => $t->dropColumn(['bank_reference', 'attempt', 'status_at']));
        Schema::table('payment_batches', function (Blueprint $t) {
            $t->dropUnique('payment_batch_request_key');
            $t->dropColumn(['payment_date', 'exceptions', 'reconciliation', 'retry_of_id', 'idempotency_key', 'request_hash', 'approved_at', 'failure_note']);
        });
        Schema::table('payslips', fn (Blueprint $t) => $t->dropColumn(['withdrawn_at', 'withdraw_reason', 'withdrawn_by', 'download_count']));
        Schema::table('employee_advances', function (Blueprint $t) {
            $t->dropUnique('advance_request_key');
            $t->dropColumn(['idempotency_key', 'request_hash', 'decision_note', 'approved_at']);
        });
        Schema::table('employee_loans', function (Blueprint $t) {
            $t->dropUnique('loan_request_key');
            $t->dropColumn(['idempotency_key', 'request_hash', 'decision_note', 'closed_at']);
        });
        foreach (['payroll_tax_declaration_windows', 'payment_status_events', 'payroll_bank_verifications', 'payslip_access_logs', 'payroll_loan_ledger'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
