<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 - Advanced Leave & Absence Management (schema only; behaviour lives in app/Domain/Leave).
 *
 * Rules enforced by the shape of the schema:
 *   - Balances are NEVER stored. leave_ledger_entries is append-only (signed decimal(14,4) units, unique idempotency key,
 *     a reversal points at the entry it reverses, consumption allocations trace source lots). Balances are derived.
 *   - Every table is tenant-owned (tenant_id). Aggregate roots expose a uuid; employees are People employees (not users).
 *   - Policies are versioned and effective-dated; requests/segments/ledger rows snapshot the policy version used so
 *     history never changes when a policy is later edited.
 *   - No country-specific law lives here: country/entity applicability is data (JSON rules + assignment targets).
 */
return new class extends Migration
{
    private function table(string $name, callable $columns, bool $uuid = false, bool $timestamps = true): void
    {
        Schema::create($name, function (Blueprint $t) use ($columns, $uuid, $timestamps) {
            $t->id();
            if ($uuid) {
                $t->uuid('uuid')->unique();
            }
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $columns($t);
            foreach ($t->getCommands() as $command) {
                if (is_string($command->index) && strlen($command->index) > 64) {
                    $command->index = substr($command->index, 0, 40).'_'.substr(sha1($command->index), 0, 16);
                }
            }
            if ($timestamps) {
                $t->timestamps();
            }
        });
    }

    private function units(Blueprint $t, string $column, bool $nullable = false): void
    {
        $c = $t->decimal($column, 14, 4);
        $nullable ? $c->nullable() : $c->default(0);
    }

    /** Polymorphic assignment target (same vocabulary as attendance assignments). */
    private function target(Blueprint $t): void
    {
        $t->string('target_type', 30);                   // employee | team | department | employee_group | business_unit | location | employee_type | legal_entity | grade | country | tenant
        $t->string('target_id', 40)->default('*');
        $t->date('effective_from');
        $t->date('effective_to')->nullable();
        $t->smallInteger('priority')->default(0);
        $t->boolean('is_override')->default(false);
        $t->text('note')->nullable();
        $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
    }

    public function up(): void
    {
        // ---- Types, policies, versions, assignments ----------------------------------------
        $this->table('leave_types', function (Blueprint $t) {
            $t->string('name');
            $t->string('code', 40);
            $t->string('kind', 30)->default('custom');           // annual|casual|sick|medical|parental|maternity|paternity|bereavement|marriage|study|sabbatical|unpaid|comp_off|optional_holiday|custom
            $t->string('category', 12)->default('paid');         // paid | unpaid | statutory | company
            $t->string('unit', 12)->default('day');              // day | half_day | quarter_day | hour
            $t->boolean('tracks_balance')->default(true);
            $t->boolean('is_statutory')->default(false);         // protected: staffing warnings never auto-deny
            $t->boolean('is_sensitive')->default(false);         // reason/attachments restricted (medical)
            $t->string('color', 9)->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->json('config')->nullable();                      // applicability (countries/entities), evidence, limits
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'code']);
        }, true);
        $this->table('leave_policies', function (Blueprint $t) {
            $t->string('name');
            $t->string('code', 40);
            $t->string('country', 2)->nullable();
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unique(['tenant_id', 'code']);
        }, true);
        $this->table('leave_policy_versions', function (Blueprint $t) {
            $t->foreignId('policy_id')->constrained('leave_policies')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('status', 12)->default('draft');         // draft | published | scheduled | active | retired
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->json('rules')->nullable();                       // policy-wide rules (sandwich, approvals, restriction defaults)
            $t->string('checksum', 64)->nullable();              // hash of rules + type rules at publish: published versions are immutable
            $t->text('change_note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->unique(['policy_id', 'version']);
            $t->index(['tenant_id', 'status', 'effective_from']);
        }, true);
        $this->table('leave_policy_type_rules', function (Blueprint $t) {
            $t->foreignId('policy_version_id')->constrained('leave_policy_versions')->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $t->json('rules');                                   // entitlement, accrual, carry/expiry, limits, eligibility, approvals, negative balance
            $t->unique(['policy_version_id', 'leave_type_id']);
        });
        $this->table('leave_policy_assignments', function (Blueprint $t) {
            $t->foreignId('policy_id')->constrained('leave_policies')->restrictOnDelete();
            $this->target($t);
            $t->index(['tenant_id', 'target_type', 'target_id', 'effective_from']);
        }, true);

        // ---- Cycles and accounts -----------------------------------------------------------
        $this->table('leave_cycles', function (Blueprint $t) {
            $t->string('name');
            $t->string('code', 40);
            $t->string('kind', 12)->default('calendar');         // calendar | fiscal | anniversary | custom
            $t->unsignedTinyInteger('start_month')->default(1);
            $t->unsignedTinyInteger('start_day')->default(1);
            $t->unsignedTinyInteger('length_months')->default(12);
            $t->json('config')->nullable();
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'code']);
        }, true);
        $this->table('leave_cycle_periods', function (Blueprint $t) {
            $t->foreignId('cycle_id')->constrained('leave_cycles')->restrictOnDelete();
            $t->string('label', 60);
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 10)->default('open');           // open | closing | closed
            $t->timestamp('closed_at')->nullable();
            $t->unique(['cycle_id', 'start_date']);
        });
        $this->table('leave_accounts', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $t->foreignId('cycle_period_id')->nullable()->constrained('leave_cycle_periods')->restrictOnDelete();
            $t->foreignId('policy_version_id')->nullable()->constrained('leave_policy_versions')->restrictOnDelete();
            $t->date('period_start');
            $t->date('period_end');
            $t->string('status', 10)->default('open');           // open | closed
            $this->units($t, 'negative_limit');                  // how far below zero available may go (>= 0); 0 = never negative
            $this->units($t, 'max_balance', true);               // accumulation cap (null = none)
            $t->json('meta')->nullable();
            $t->unique(['tenant_id', 'employee_id', 'leave_type_id', 'period_start'], 'leave_accounts_identity');
            $t->index(['tenant_id', 'employee_id', 'period_end']);
        }, true);

        // ---- Requests (created before the ledger so entries can reference them) -----------
        $this->table('leave_requests', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $t->foreignId('policy_version_id')->nullable()->constrained('leave_policy_versions')->restrictOnDelete();
            $t->string('status', 20)->default('draft');          // draft|pending|approved|rejected|withdrawn|cancelled|partially_cancelled|modified|expired
            $t->date('start_date');
            $t->date('end_date');
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->string('timezone', 64)->nullable();
            $t->string('unit', 12)->default('day');
            $t->string('portion', 14)->default('full_day');      // full_day | first_half | second_half | quarter | hours
            $this->units($t, 'requested_units');
            $this->units($t, 'charged_units');
            $this->units($t, 'projected_balance', true);
            $t->text('reason')->nullable();
            $t->boolean('is_sensitive')->default(false);
            $t->foreignId('delegate_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $t->text('handover_notes')->nullable();
            $t->unsignedTinyInteger('current_step')->default(1);
            $t->unsignedTinyInteger('total_steps')->default(1);
            $t->foreignId('approver_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('parent_request_id')->nullable()->constrained('leave_requests')->restrictOnDelete(); // controlled modification chain
            $t->string('idempotency_key', 120)->nullable();
            $t->string('confidence', 20)->default('pending');    // confirmed | pending | review_required
            $t->json('warnings')->nullable();
            $t->json('explanation')->nullable();                 // duration/charge trace at submission
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('attendance_synced_at')->nullable();
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['tenant_id', 'employee_id', 'start_date', 'end_date']);
            $t->index(['tenant_id', 'status', 'approver_user_id']);
        }, true);

        // ---- The ledger (append-only) ------------------------------------------------------
        $this->table('leave_ledger_entries', function (Blueprint $t) {
            $t->foreignId('account_id')->constrained('leave_accounts')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $t->string('entry_type', 30);                        // opening|accrual|reservation|reservation_release|consumption|settlement|reversal|adjustment|carry_forward_out|carry_forward_in|expiry|comp_off_credit|encashment|transfer_out|transfer_in|donation_out|donation_in
            $t->string('bucket', 12);                            // opening|accrued|carried|adjusted|consumed|pending|scheduled|expired|encashed
            $this->units($t, 'units');                           // SIGNED delta to available: credits > 0, debits < 0. Never 0.
            $t->date('effective_date');
            $t->date('expires_on')->nullable();                  // credit lots only (expiry / consumption ordering)
            $t->string('idempotency_key', 120);
            $t->foreignId('reverses_entry_id')->nullable()->constrained('leave_ledger_entries')->restrictOnDelete();
            $t->foreignId('source_entry_id')->nullable()->constrained('leave_ledger_entries')->restrictOnDelete(); // lot this entry draws on / follows from
            $t->foreignId('request_id')->nullable()->constrained('leave_requests')->restrictOnDelete();
            $t->unsignedBigInteger('segment_id')->nullable();
            $t->unsignedBigInteger('accrual_item_id')->nullable();
            $t->unsignedBigInteger('comp_off_credit_id')->nullable();
            $t->unsignedBigInteger('import_row_id')->nullable();
            $t->foreignId('policy_version_id')->nullable()->constrained('leave_policy_versions')->restrictOnDelete();
            $t->string('reference_type', 40)->nullable();
            $t->string('reference_id', 40)->nullable();
            $t->string('reason', 500)->nullable();
            $t->json('meta')->nullable();                        // calculation trace: inputs, rule, formula
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at');
            $t->unique(['tenant_id', 'idempotency_key'], 'leave_ledger_idempotency');
            $t->unique(['tenant_id', 'reverses_entry_id'], 'leave_ledger_one_reversal');
            $t->index(['tenant_id', 'account_id', 'bucket']);
            $t->index(['tenant_id', 'employee_id', 'effective_date']);
            $t->index(['tenant_id', 'request_id']);
            $t->index(['tenant_id', 'expires_on']);
        }, true, false);
        $this->table('leave_ledger_allocations', function (Blueprint $t) {
            $t->foreignId('entry_id')->constrained('leave_ledger_entries')->restrictOnDelete();         // the debit
            $t->foreignId('source_entry_id')->constrained('leave_ledger_entries')->restrictOnDelete();  // the credit lot it consumed
            $this->units($t, 'units');                           // positive magnitude
            $t->unique(['entry_id', 'source_entry_id']);
            $t->index(['tenant_id', 'source_entry_id']);
        }, false, false);

        $this->table('leave_request_segments', function (Blueprint $t) {
            $t->foreignId('request_id')->constrained('leave_requests')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $t->foreignId('account_id')->nullable()->constrained('leave_accounts')->restrictOnDelete();
            $t->date('leave_date');                              // logical date in the shift/employee timezone
            $t->dateTime('starts_at')->nullable();               // UTC instants for partial / cross-midnight segments
            $t->dateTime('ends_at')->nullable();
            $t->string('portion', 14)->default('full_day');      // full_day | first_half | second_half | quarter | hours
            $t->unsignedInteger('minutes')->default(0);
            $this->units($t, 'units');                           // charged units
            $t->string('pay_class', 12)->default('paid');        // paid | unpaid | statutory | lop | comp_off
            $t->string('day_kind', 14)->default('workday');      // workday | holiday | weekly_off | sandwiched | partial
            $t->boolean('charged')->default(true);
            $t->string('status', 12)->default('active');         // active | cancelled
            $t->json('explanation')->nullable();                 // why this day was (not) charged
            $t->foreignId('ledger_entry_id')->nullable()->constrained('leave_ledger_entries')->restrictOnDelete();
            $t->unique(['request_id', 'leave_date', 'portion'], 'leave_segment_identity');
            $t->index(['tenant_id', 'employee_id', 'leave_date'], 'leave_segment_employee_date');
        });
        $this->table('leave_request_actions', function (Blueprint $t) {
            $t->foreignId('request_id')->constrained('leave_requests')->restrictOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action', 24);                            // submit|approve|reject|withdraw|cancel|partial_cancel|modify|escalate|delegate|comment|system
            $t->unsignedTinyInteger('step')->nullable();
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20)->nullable();
            $t->text('comment')->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->index(['tenant_id', 'request_id']);
        });
        $this->table('leave_request_documents', function (Blueprint $t) {
            $t->foreignId('request_id')->constrained('leave_requests')->restrictOnDelete();
            $t->string('kind', 40)->nullable();
            $t->string('filename');
            $t->string('path');                                  // private storage only
            $t->string('mime', 100)->nullable();
            $t->unsignedBigInteger('size_bytes')->default(0);
            $t->boolean('is_sensitive')->default(false);         // medical: needs leave.medical.view
            $t->date('retain_until')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->index(['tenant_id', 'request_id']);
        }, true);
        $this->table('leave_conflicts', function (Blueprint $t) {
            $t->foreignId('request_id')->constrained('leave_requests')->restrictOnDelete();
            $t->unsignedBigInteger('restriction_id')->nullable();
            $t->string('kind', 30);                              // overlap|attendance|on_duty|holiday|weekly_off|employment|roster|availability|blackout|concurrency|staffing|document|eligibility
            $t->string('severity', 10);                          // info | warning | block | escalate
            $t->string('message', 500);
            $t->json('detail')->nullable();
            $t->boolean('acknowledged')->default(false);
            $t->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->index(['tenant_id', 'request_id']);
        });

        // ---- Accrual -----------------------------------------------------------------------
        $this->table('leave_accrual_rules', function (Blueprint $t) {
            $t->foreignId('policy_version_id')->constrained('leave_policy_versions')->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $t->string('frequency', 14);                         // annual|monthly|weekly|daily|pay_period|anniversary|manual
            $this->units($t, 'amount');
            $t->json('tenure_bands')->nullable();                // [{from_months,to_months,amount}]
            $t->json('proration')->nullable();                   // joiner/leaver/transfer/probation behaviour
            $t->unsignedInteger('start_after_days')->default(0);
            $this->units($t, 'max_accumulation', true);
            $t->boolean('active')->default(true);
            $t->index(['tenant_id', 'policy_version_id']);
        }, true);
        $this->table('leave_accrual_runs', function (Blueprint $t) {
            $t->string('run_key', 120);                          // deterministic: same key => same run, no double accrual
            $t->string('mode', 10)->default('scheduled');        // scheduled | manual
            $t->string('status', 12)->default('preview');        // preview|running|completed|partial|failed|cancelled
            $t->date('period_start');
            $t->date('period_end');
            $t->date('as_of')->nullable();
            $t->json('scope')->nullable();
            $t->unsignedInteger('total_items')->default(0);
            $t->unsignedInteger('posted_items')->default(0);
            $t->unsignedInteger('skipped_items')->default(0);
            $t->unsignedInteger('failed_items')->default(0);
            $t->foreignId('retry_of_id')->nullable()->constrained('leave_accrual_runs')->restrictOnDelete();
            $t->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->text('error')->nullable();
            $t->unique(['tenant_id', 'run_key']);
        }, true);
        $this->table('leave_accrual_items', function (Blueprint $t) {
            $t->foreignId('run_id')->constrained('leave_accrual_runs')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $t->foreignId('account_id')->nullable()->constrained('leave_accounts')->restrictOnDelete();
            $t->foreignId('rule_id')->nullable()->constrained('leave_accrual_rules')->restrictOnDelete();
            $t->string('status', 10)->default('preview');        // preview | posted | skipped | failed
            $this->units($t, 'units');
            $t->string('skip_reason', 255)->nullable();
            $t->json('calculation')->nullable();                 // tenure band, proration factor, caps applied
            $t->foreignId('ledger_entry_id')->nullable()->constrained('leave_ledger_entries')->restrictOnDelete();
            $t->unique(['run_id', 'employee_id', 'leave_type_id'], 'leave_accrual_item_identity');
            $t->index(['tenant_id', 'run_id', 'status']);
        });

        // ---- Carry forward and expiry ------------------------------------------------------
        $this->table('leave_carry_forwards', function (Blueprint $t) {
            $t->string('run_key', 120);
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $t->foreignId('from_account_id')->constrained('leave_accounts')->restrictOnDelete();
            $t->foreignId('to_account_id')->nullable()->constrained('leave_accounts')->restrictOnDelete();
            $t->string('mode', 12);                              // none | full | capped | percentage
            $this->units($t, 'eligible_units');
            $this->units($t, 'carried_units');
            $this->units($t, 'forfeited_units');
            $t->date('valid_until')->nullable();
            $t->string('status', 10)->default('preview');        // preview | posted | skipped | failed
            $t->foreignId('out_entry_id')->nullable()->constrained('leave_ledger_entries')->restrictOnDelete();
            $t->foreignId('in_entry_id')->nullable()->constrained('leave_ledger_entries')->restrictOnDelete();
            $t->json('calculation')->nullable();
            $t->unique(['tenant_id', 'run_key', 'employee_id', 'leave_type_id'], 'leave_carry_identity');
        }, true);
        $this->table('leave_expiries', function (Blueprint $t) {
            $t->foreignId('account_id')->constrained('leave_accounts')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $t->foreignId('source_entry_id')->constrained('leave_ledger_entries')->restrictOnDelete();
            $this->units($t, 'units');
            $t->date('expires_on');
            $t->string('status', 10)->default('scheduled');      // scheduled | notified | expired | cancelled
            $t->timestamp('notified_at')->nullable();
            $t->foreignId('expiry_entry_id')->nullable()->constrained('leave_ledger_entries')->restrictOnDelete();
            $t->unique(['tenant_id', 'source_entry_id'], 'leave_expiry_one_per_lot');
            $t->index(['tenant_id', 'status', 'expires_on']);
        });

        // ---- Comp-off, optional holidays ---------------------------------------------------
        $this->table('leave_comp_off_credits', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_type_id')->nullable()->constrained('leave_types')->restrictOnDelete();
            $t->string('source_type', 20);                       // overtime | holiday_work | rest_day_work | authorized
            $t->string('source_id', 40)->nullable();             // overtime_requests.uuid / attendance_days.uuid ...
            $t->date('work_date');
            $this->units($t, 'units');
            $t->string('status', 10)->default('pending');        // pending | approved | rejected | expired | cancelled
            $t->date('valid_from')->nullable();
            $t->date('expires_on')->nullable();
            $t->text('note')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->foreignId('ledger_entry_id')->nullable()->constrained('leave_ledger_entries')->restrictOnDelete();
            $t->unique(['tenant_id', 'employee_id', 'source_type', 'source_id'], 'leave_compoff_source');
            $t->index(['tenant_id', 'employee_id', 'status']);
        }, true);
        $this->table('leave_comp_off_claims', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('credit_id')->constrained('leave_comp_off_credits')->restrictOnDelete();
            $t->foreignId('request_id')->nullable()->constrained('leave_requests')->restrictOnDelete();
            $this->units($t, 'units');
            $t->string('status', 10)->default('pending');        // pending | approved | rejected | cancelled
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->index(['tenant_id', 'employee_id', 'status']);
        }, true);
        $this->table('leave_optional_holiday_pools', function (Blueprint $t) {
            $t->string('name');
            $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('max_selection')->default(1);
            $t->foreignId('leave_type_id')->nullable()->constrained('leave_types')->restrictOnDelete();
            $t->json('holidays');                                // [{date,name,holiday_uuid?}]
            $t->json('applicability')->nullable();
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'name', 'year']);
        }, true);

        // ---- Restrictions and documents ----------------------------------------------------
        $this->table('leave_restrictions', function (Blueprint $t) {
            $t->string('name');
            $t->string('kind', 20);                              // blackout|max_concurrent|min_staffing|notice|consecutive|eligibility|backdate|future_limit
            $t->string('action', 10)->default('warn');           // warn | block | escalate
            $t->boolean('exempt_statutory')->default(true);      // protected leave is never blocked by staffing-type rules
            $t->string('target_type', 30)->default('tenant');
            $t->string('target_id', 40)->default('*');
            $t->foreignId('leave_type_id')->nullable()->constrained('leave_types')->restrictOnDelete();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->json('params')->nullable();
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->index(['tenant_id', 'kind', 'active']);
        }, true);
        $this->table('leave_document_rules', function (Blueprint $t) {
            $t->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $t->foreignId('policy_version_id')->nullable()->constrained('leave_policy_versions')->restrictOnDelete();
            $this->units($t, 'required_after_units');            // request length that triggers the requirement
            $t->string('document_kind', 40)->default('supporting');
            $t->boolean('mandatory')->default(true);
            $t->boolean('is_sensitive')->default(false);
            $t->unsignedInteger('submit_within_days')->nullable();
            $t->unsignedInteger('retention_days')->nullable();
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $t->index(['tenant_id', 'leave_type_id']);
        });

        // ---- Locks, imports, simulations ---------------------------------------------------
        $this->table('leave_locks', function (Blueprint $t) {
            $t->string('scope_type', 20);                        // tenant | legal_entity | payroll_group | location | employee_group | employee
            $t->string('scope_id', 40)->default('*');
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 10)->default('locked');         // locked | unlocked
            $t->text('reason');
            $t->foreignId('attendance_lock_id')->nullable()->constrained('attendance_locks')->nullOnDelete(); // coordinated cutoff
            $t->foreignId('locked_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('locked_at');
            $t->foreignId('unlock_requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('unlocked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('unlocked_at')->nullable();
            $t->text('unlock_reason')->nullable();
            $t->index(['tenant_id', 'status', 'start_date', 'end_date']);
            $t->index(['tenant_id', 'scope_type', 'scope_id']);
        }, true);
        $this->table('leave_imports', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('kind', 20);                              // opening_balance | historical_requests | bulk_adjustment
            $t->string('filename');
            $t->string('path')->nullable();
            $t->string('file_hash', 64)->nullable();
            $t->string('status', 20)->default('uploaded');       // uploaded|validated|approved|importing|completed|failed
            $t->json('mapping')->nullable();
            $t->json('options')->nullable();
            foreach (['total_rows', 'valid_rows', 'warning_rows', 'error_rows', 'imported_rows', 'duplicate_rows'] as $c) {
                $t->unsignedInteger($c)->default(0);
            }
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->index(['tenant_id', 'status']);
        }, true);
        $this->table('leave_import_rows', function (Blueprint $t) {
            $t->foreignId('import_id')->constrained('leave_imports')->restrictOnDelete();
            $t->unsignedInteger('row_number');
            $t->json('raw');
            $t->json('normalized')->nullable();
            $t->string('status', 12)->default('pending');        // pending|valid|warning|error|duplicate|imported|skipped
            $t->json('errors')->nullable();
            $t->json('warnings')->nullable();
            $t->string('idempotency_key', 120)->nullable();
            $t->foreignId('ledger_entry_id')->nullable()->constrained('leave_ledger_entries')->restrictOnDelete();
            $t->foreignId('request_id')->nullable()->constrained('leave_requests')->restrictOnDelete();
            $t->unique(['import_id', 'row_number']);
            $t->index(['tenant_id', 'import_id', 'status']);
        });
        $this->table('leave_simulation_runs', function (Blueprint $t) {
            $t->string('kind', 24);                              // policy_sandbox | entitlement | request_impact | forecast | policy_diff
            $t->json('input');
            $t->json('result')->nullable();
            $t->foreignId('policy_version_id')->nullable()->constrained('leave_policy_versions')->restrictOnDelete();
            $t->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->index(['tenant_id', 'kind', 'created_at']);
        }, true);

        // ---- Adjustments, transfers, settlement, payroll contract, misc ---------------------
        $this->table('leave_adjustments', function (Blueprint $t) {
            $t->foreignId('account_id')->constrained('leave_accounts')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $this->units($t, 'units');                           // signed
            $t->string('kind', 12)->default('adjustment');       // adjustment | grant | debit
            $t->string('status', 10)->default('pending');        // pending | approved | rejected | posted
            $t->string('bulk_key', 120)->nullable();
            $t->text('reason');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete(); // maker-checker: differs from requested_by
            $t->timestamp('decided_at')->nullable();
            $t->foreignId('ledger_entry_id')->nullable()->constrained('leave_ledger_entries')->restrictOnDelete();
            $t->index(['tenant_id', 'status']);
        }, true);
        $this->table('leave_type_mappings', function (Blueprint $t) {
            $t->foreignId('from_leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $t->foreignId('to_leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $t->decimal('ratio', 10, 4)->default(1);
            $t->string('purpose', 20)->default('transfer');      // transfer | import | conversion
            $t->unique(['tenant_id', 'from_leave_type_id', 'to_leave_type_id', 'purpose'], 'leave_type_mapping_identity');
        });
        $this->table('leave_transfers', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->string('trigger', 16);                           // transfer | separation | rehire | policy_change
            $t->date('effective_date');
            $t->foreignId('from_policy_version_id')->nullable()->constrained('leave_policy_versions')->restrictOnDelete();
            $t->foreignId('to_policy_version_id')->nullable()->constrained('leave_policy_versions')->restrictOnDelete();
            $t->string('balance_mode', 10)->default('preserve'); // preserve | transfer | convert | expire
            $t->string('status', 10)->default('preview');        // preview | applied | cancelled
            $t->json('plan')->nullable();
            $t->json('result')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unique(['tenant_id', 'employee_id', 'trigger', 'effective_date'], 'leave_transfer_identity');
        }, true);
        $this->table('leave_settlement_references', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $t->string('kind', 12);                              // encashment | separation
            $this->units($t, 'units');                           // units only: NO money in Phase 4
            $t->string('status', 10)->default('proposed');       // proposed | approved | posted | cancelled
            $t->date('as_of');
            $t->foreignId('ledger_entry_id')->nullable()->constrained('leave_ledger_entries')->restrictOnDelete();
            $t->string('payroll_reference', 80)->nullable();
            $t->json('meta')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->index(['tenant_id', 'employee_id', 'status']);
        }, true);
        $this->table('leave_payroll_lines', function (Blueprint $t) {
            $t->string('batch_key', 120);
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('period_start');
            $t->date('period_end');
            $t->string('pay_class', 12);                         // paid | unpaid | statutory | lop | comp_off | encashment
            $t->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $this->units($t, 'units');
            $t->foreignId('request_id')->nullable()->constrained('leave_requests')->restrictOnDelete();
            $t->foreignId('segment_id')->nullable()->constrained('leave_request_segments')->restrictOnDelete();
            $t->boolean('late_adjustment')->default(false);      // approved after the period's cutoff
            $t->date('cutoff_date')->nullable();
            $t->json('meta')->nullable();
            $t->index(['tenant_id', 'batch_key', 'employee_id']);
            $t->index(['tenant_id', 'employee_id', 'period_start']);
        });
        $this->table('leave_return_to_work', function (Blueprint $t) {
            $t->foreignId('request_id')->constrained('leave_requests')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('expected_return_date');
            $t->date('actual_return_date')->nullable();
            $t->string('status', 12)->default('expected');       // expected | confirmed | extended | cleared
            $t->json('checklist')->nullable();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unique(['tenant_id', 'request_id']);
        });
        $this->table('leave_donations', function (Blueprint $t) {
            $t->foreignId('donor_employee_id')->constrained('employees')->restrictOnDelete();
            $t->foreignId('recipient_employee_id')->constrained('employees')->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $this->units($t, 'units');
            $t->string('status', 10)->default('pending');        // pending | approved | rejected | posted
            $t->foreignId('out_entry_id')->nullable()->constrained('leave_ledger_entries')->restrictOnDelete();
            $t->foreignId('in_entry_id')->nullable()->constrained('leave_ledger_entries')->restrictOnDelete();
            $t->text('note')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
        }, true);
    }

    public function down(): void
    {
        foreach ([
            'leave_donations', 'leave_return_to_work', 'leave_payroll_lines', 'leave_settlement_references', 'leave_transfers', 'leave_type_mappings',
            'leave_adjustments', 'leave_simulation_runs', 'leave_import_rows', 'leave_imports', 'leave_locks', 'leave_document_rules', 'leave_restrictions',
            'leave_optional_holiday_pools', 'leave_comp_off_claims', 'leave_comp_off_credits', 'leave_expiries', 'leave_carry_forwards',
            'leave_accrual_items', 'leave_accrual_runs', 'leave_accrual_rules', 'leave_conflicts', 'leave_request_documents', 'leave_request_actions',
            'leave_request_segments', 'leave_ledger_allocations', 'leave_ledger_entries', 'leave_requests', 'leave_accounts', 'leave_cycle_periods',
            'leave_cycles', 'leave_policy_assignments', 'leave_policy_type_rules', 'leave_policy_versions', 'leave_policies', 'leave_types',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
