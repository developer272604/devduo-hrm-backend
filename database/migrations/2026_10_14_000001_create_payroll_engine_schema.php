<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 5 - Global Payroll, Compensation & Benefits: engine schema (P05-T01). Extends 2026_10_11_000001_create_payroll_foundation
 * (payroll_groups, salary_components, employee_compensations, compensation_lines) WITHOUT rewriting it, and adds the remaining tables.
 *
 * Shape rules:
 *   - Every table is tenant-owned. Aggregate roots expose a uuid. Employees are People employees (never users).
 *   - Money: result/instruction/journal amounts are decimal(18,4) (reconcilable with SQL SUM; always handled with App\Domain\Payroll\Support\Money).
 *     Compensation figures stay ENCRYPTED text (see employee_compensations / compensation_lines). Never a float.
 *   - payroll_calculation_results / payroll_result_components are append-only (model-enforced): corrections are new versions.
 *   - Country pack versions and component versions are effective-dated and immutable once published; results store the exact version ids.
 *   - No country law lives in the schema: statutory behaviour is pack-version DATA (rules JSON) or a registered StatutoryPack handler.
 *   - Mapping to the spec table list: salary_component_versions == salary_components (one row per code+version);
 *     employee_compensation_components == compensation_lines; payroll_ledger == payroll_calculation_results + payroll_result_components.
 */
return new class extends Migration
{
    private function table(string $name, callable $columns, bool $uuid = true, bool $timestamps = true): void
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

    private function money(Blueprint $t, string $column, bool $nullable = false): void
    {
        $c = $t->decimal($column, 18, 4);
        $nullable ? $c->nullable() : $c->default(0);
    }

    private function employee(Blueprint $t, bool $nullable = false): void
    {
        $c = $t->foreignId('employee_id');
        if ($nullable) {
            $c->nullable();
        }
        $c->constrained()->restrictOnDelete();
    }

    private function user(Blueprint $t, string $column, bool $nullable = true): void
    {
        $c = $t->foreignId($column);
        if ($nullable) {
            $c->nullable();
        }
        $c->constrained('users')->restrictOnDelete();
    }

    public function up(): void
    {
        // ---- Country packs (global engine vs country packs vs tenant configuration) --------------------------------
        $this->table('payroll_country_packs', function (Blueprint $t) {
            $t->string('code', 40);                         // tenant-chosen pack code, e.g. "IN-PAYROLL"
            $t->char('country', 2);
            $t->string('name', 160);
            $t->string('handler', 80)->default('declarative'); // key in StatutoryPackRegistry; declarative = rules JSON interpreter
            $t->string('status', 20)->default('draft');     // draft | enabled | disabled
            $t->text('description')->nullable();
            $t->foreignId('enabled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('enabled_at')->nullable();
            $t->unique(['tenant_id', 'code']);
            $t->index(['tenant_id', 'country', 'status']);
        });
        $this->table('payroll_country_pack_versions', function (Blueprint $t) {
            $t->foreignId('pack_id')->constrained('payroll_country_packs')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('status', 20)->default('draft');     // draft | published | retired  (published rows are immutable)
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('tax_year_start', 5)->default('01-01'); // MM-DD
            $t->json('metadata')->nullable();               // rule metadata: source, authority, citations, legal review notes
            $t->json('calculation_rules')->nullable();      // tax / contributions / limits / rounding / overtime multipliers
            $t->json('validation_rules')->nullable();      // identifiers, limits, mandatory data
            $t->json('identifiers')->nullable();            // statutory identifier definitions (key,label,required,pattern)
            $t->json('declaration_schema')->nullable();     // tax declaration / proof schema when the country needs one
            $t->char('checksum', 64)->nullable();
            $t->timestamp('published_at')->nullable();
            $this->user($t, 'published_by');
            $t->timestamp('verified_at')->nullable();       // legal/expert validation marker (enablement gate)
            $this->user($t, 'verified_by');
            $t->text('verification_note')->nullable();
            $t->unique(['pack_id', 'version']);
            $t->index(['tenant_id', 'pack_id', 'effective_from'], 'pack_version_resolution');
        });

        // ---- Payroll groups (extend the Phase 3 reference table), calendars, periods ----------------------------------
        Schema::table('payroll_groups', function (Blueprint $t) {
            $t->string('employee_category', 60)->nullable();
            $t->string('worker_type', 40)->nullable();
            $t->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $t->foreignId('country_pack_id')->nullable()->constrained('payroll_country_packs')->restrictOnDelete();
            $t->json('settings')->nullable();               // rounding, payable-day rule, proration rule, payment day rule
            $t->text('description')->nullable();
            $t->index(['tenant_id', 'country', 'currency'], 'payroll_group_country');
        });
        $this->table('payroll_calendars', function (Blueprint $t) {
            $t->foreignId('payroll_group_id')->constrained('payroll_groups')->restrictOnDelete();
            $t->string('name', 120);
            $t->string('frequency', 24);                    // weekly | biweekly | semi_monthly | monthly | four_weekly | custom
            $t->date('anchor_date')->nullable();            // first period start for weekly/biweekly/four_weekly
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->json('rules')->nullable();                  // semi-monthly split days, custom ranges, cutoff / approval / payment offsets
            $t->string('status', 20)->default('active');
            $t->index(['tenant_id', 'payroll_group_id', 'effective_from'], 'payroll_calendar_resolution');
        });
        $this->table('payroll_periods', function (Blueprint $t) {
            $t->foreignId('payroll_group_id')->constrained('payroll_groups')->restrictOnDelete();
            $t->foreignId('calendar_id')->nullable()->constrained('payroll_calendars')->restrictOnDelete();
            $t->string('label', 80);
            $t->date('period_start');
            $t->date('period_end');
            $t->string('tax_year', 9)->nullable();
            $t->string('state', 24)->default('upcoming');   // upcoming input_open processing validation approval approved locked payment_processing paid closed reopened
            $t->date('attendance_cutoff')->nullable();
            $t->date('leave_cutoff')->nullable();
            $t->date('variable_pay_cutoff')->nullable();
            $t->date('input_cutoff')->nullable();
            $t->date('approval_deadline')->nullable();
            $t->date('payment_date')->nullable();
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unique(['tenant_id', 'payroll_group_id', 'period_start', 'period_end'], 'payroll_period_unique');
            $t->index(['tenant_id', 'state', 'period_end'], 'payroll_period_state');
        });
        $this->table('payroll_period_transitions', function (Blueprint $t) { // append-only audit of the state machine
            $t->foreignId('period_id')->constrained('payroll_periods')->restrictOnDelete();
            $t->string('from_state', 24);
            $t->string('to_state', 24);
            $this->user($t, 'actor_id');
            $t->text('reason')->nullable();
            $t->json('meta')->nullable();
        }, false);

        // ---- Components (extend), structures, compensation ------------------------------------------------------------
        Schema::table('salary_components', function (Blueprint $t) {
            $t->string('nature', 12)->default('fixed');     // fixed | variable
            $t->char('country', 2)->nullable();
            $t->string('calc_method', 16)->default('fixed'); // fixed | percentage | formula | rate | input
            $t->string('tax_classification', 40)->nullable(); // taxable | exempt | partially_exempt | pre_tax | post_tax | tenant/pack code
            $t->string('proration', 20)->default('none');   // none | calendar_days | working_days | payable_days
            $t->boolean('attendance_dependent')->default(false);
            $t->boolean('lop_dependent')->default(false);
            $t->boolean('overtime_dependent')->default(false);
            $t->string('rounding_mode', 16)->nullable();     // null = group/pack default
            $t->unsignedTinyInteger('rounding_scale')->nullable();
            $t->string('rounding_increment', 20)->nullable();
            $t->string('min_amount', 40)->nullable();       // decimal strings, never floats
            $t->string('max_amount', 40)->nullable();
            $t->string('statutory_mapping', 80)->nullable(); // pack-defined concept key, e.g. income_tax, pension
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('sequence')->default(100); // evaluation order hint (dependencies still win)
            $t->json('config')->nullable();                 // percentage base, rate source, overtime multiplier ref, ...
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('published_at')->nullable();      // component version is immutable once referenced/published
            $t->index(['tenant_id', 'kind', 'active'], 'component_kind');
        });
        $this->table('salary_structures', function (Blueprint $t) {
            $t->string('code', 40);
            $t->string('name', 120);
            $t->unsignedInteger('version')->default(1);
            $t->char('currency', 3)->nullable();
            $t->char('country', 2)->nullable();
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('status', 20)->default('draft');     // draft | active | retired
            $t->json('rules')->nullable();                  // eligibility (worker type, grade, category), defaults
            $t->text('description')->nullable();
            $this->user($t, 'created_by');
            $t->unique(['tenant_id', 'code', 'version']);
        });
        $this->table('salary_structure_components', function (Blueprint $t) {
            $t->foreignId('structure_id')->constrained('salary_structures')->restrictOnDelete();
            $t->string('component_code', 40);
            $t->foreignId('component_id')->nullable()->constrained('salary_components')->restrictOnDelete(); // optional pin to one version
            $t->unsignedSmallInteger('sequence')->default(100);
            $t->boolean('required')->default(true);
            $t->json('overrides')->nullable();              // per-structure formula/min/max overrides
            $t->unique(['structure_id', 'component_code']);
        });
        Schema::table('employee_compensations', function (Blueprint $t) {
            $t->foreignId('structure_id')->nullable()->constrained('salary_structures')->restrictOnDelete();
            $t->foreignId('supersedes_id')->nullable()->constrained('employee_compensations')->restrictOnDelete();
            $t->char('currency', 3)->nullable();
            $t->string('pay_frequency', 24)->nullable();
            $t->text('base_pay')->nullable();               // ENCRYPTED decimal strings (cast on the model)
            $t->text('gross_reference')->nullable();
            $t->text('annual_compensation')->nullable();
            $t->text('hourly_rate')->nullable();
            $t->text('daily_rate')->nullable();
            $t->string('source', 20)->default('manual');    // manual | import | revision | mass_revision | offer
            $t->json('meta')->nullable();
        });
        Schema::table('compensation_lines', function (Blueprint $t) {
            $t->unsignedSmallInteger('sequence')->default(100);
            $t->json('basis')->nullable();                  // how the amount was derived (fixed, % of base, formula input)
        });
        $this->table('compensation_revisions', function (Blueprint $t) {
            $this->employee($t);
            $t->string('type', 24);                         // increment promotion market_correction adjustment transfer demotion other
            $t->string('status', 20)->default('draft');     // draft pending approved rejected applied cancelled
            $t->date('effective_date');
            $t->foreignId('old_compensation_id')->nullable()->constrained('employee_compensations')->restrictOnDelete();
            $t->foreignId('new_compensation_id')->nullable()->constrained('employee_compensations')->restrictOnDelete();
            $t->text('reason');
            $t->text('comments')->nullable();
            $this->user($t, 'initiator_id', false);
            $this->user($t, 'approver_id');
            $t->timestamp('decided_at')->nullable();
            $t->foreignId('batch_id')->nullable();           // compensation_revision_batches.id (mass revision), FK added below
            $t->text('preview')->nullable();                 // ENCRYPTED json: impact preview at proposal time (current/new/monthly/annual/retro)
            $t->string('letter_reference', 120)->nullable(); // salary-letter template integration foundation
            $t->uuid('idempotency_key')->nullable();
            $t->char('request_hash', 64)->nullable();
            $t->unique(['tenant_id', 'idempotency_key'], 'revision_request_key');
            $t->index(['tenant_id', 'employee_id', 'effective_date'], 'revision_employee_date');
        });
        $this->table('compensation_revision_batches', function (Blueprint $t) {
            $t->string('name', 160);
            $t->string('mode', 24);                         // percentage | fixed | new_structure | import
            $t->string('status', 20)->default('previewed'); // previewed submitted approved partially_applied applied cancelled
            $t->date('effective_date');
            $t->json('params');
            $t->text('preview')->nullable();                // ENCRYPTED json
            $t->unsignedInteger('employees_total')->default(0);
            $t->unsignedInteger('employees_ok')->default(0);
            $t->unsignedInteger('employees_failed')->default(0);
            $t->json('errors')->nullable();
            $this->user($t, 'created_by', false);
            $this->user($t, 'approved_by');
            $t->timestamp('approved_at')->nullable();
            $t->uuid('idempotency_key')->nullable();
            $t->unique(['tenant_id', 'idempotency_key'], 'revision_batch_request_key');
        });
        Schema::table('compensation_revisions', fn (Blueprint $t) => $t->foreign('batch_id')->references('id')->on('compensation_revision_batches')->restrictOnDelete());

        // ---- Runs ------------------------------------------------------------------------------------------------------
        $this->table('payroll_runs', function (Blueprint $t) {
            $t->foreignId('payroll_group_id')->constrained('payroll_groups')->restrictOnDelete();
            $t->foreignId('period_id')->constrained('payroll_periods')->restrictOnDelete();
            $t->unsignedInteger('run_number')->default(1);
            $t->string('run_type', 20)->default('regular'); // regular | off_cycle | settlement | correction
            $t->string('off_cycle_reason', 40)->nullable(); // correction | bonus | settlement | emergency | special
            $t->string('status', 24)->default('draft');     // draft readiness inputs validating calculating review approval approved locked outputs payment closed reopened cancelled
            $t->unsignedTinyInteger('stage')->default(1);   // 1..11 guided stages
            $t->unsignedInteger('calculation_version')->default(0); // +1 for every (re)calculation pass
            $t->string('engine_version', 20)->nullable();
            $t->char('currency', 3);
            $t->foreignId('country_pack_version_id')->nullable()->constrained('payroll_country_pack_versions')->restrictOnDelete();
            $t->foreignId('parent_run_id')->nullable()->constrained('payroll_runs')->restrictOnDelete(); // corrections / off-cycle reference
            $t->json('settings_snapshot')->nullable();     // PayrollSettings + group settings at run creation (reproducibility)
            $t->json('control_totals')->nullable();        // employees, gross, deductions, employer cost, net, payment total
            $t->unsignedInteger('employees_total')->default(0);
            $t->unsignedInteger('employees_failed')->default(0);
            $t->uuid('idempotency_key')->nullable();
            $t->char('request_hash', 64)->nullable();
            $this->user($t, 'created_by', false);
            $this->user($t, 'approved_by');
            $this->user($t, 'locked_by');
            $t->timestamp('calculated_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unique(['tenant_id', 'idempotency_key'], 'payroll_run_request_key');
            $t->unique(['tenant_id', 'period_id', 'run_number'], 'payroll_run_number');
            $t->index(['tenant_id', 'status'], 'payroll_run_status');
        });
        $this->table('payroll_run_employees', function (Blueprint $t) {
            $t->foreignId('run_id')->constrained('payroll_runs')->restrictOnDelete();
            $this->employee($t);
            $t->foreignId('payroll_group_id')->constrained('payroll_groups')->restrictOnDelete();
            $t->string('status', 20)->default('pending');   // pending calculated warning failed excluded held
            $t->unsignedInteger('attempts')->default(0);
            $t->unsignedBigInteger('current_result_id')->nullable(); // payroll_calculation_results.id (FK added below)
            $t->string('exclusion_reason', 160)->nullable();
            $t->text('last_error')->nullable();
            $t->json('employment_snapshot')->nullable();    // legal entity, dept, location, cost centre, joiner/leaver flags as of the period
            $t->unique(['run_id', 'employee_id']);
            $t->index(['tenant_id', 'employee_id'], 'run_employee_lookup');
        });

        // ---- Inputs, imports, adjustments ---------------------------------------------------------------------------
        $this->table('payroll_imports', function (Blueprint $t) {
            $t->string('kind', 24);                         // payroll_input | compensation
            $t->string('file_name', 200);
            $t->string('status', 20)->default('uploaded');  // uploaded previewed committed failed
            $t->json('mapping')->nullable();
            $t->unsignedInteger('rows_total')->default(0);
            $t->unsignedInteger('rows_ok')->default(0);
            $t->unsignedInteger('rows_error')->default(0);
            $t->unsignedInteger('rows_duplicate')->default(0);
            $t->string('storage_path', 300)->nullable();
            $this->user($t, 'created_by', false);
        });
        $this->table('payroll_import_rows', function (Blueprint $t) {
            $t->foreignId('import_id')->constrained('payroll_imports')->restrictOnDelete();
            $t->unsignedInteger('row_number');
            $t->string('status', 16)->default('pending');   // pending valid error duplicate committed
            $t->text('raw')->nullable();                    // ENCRYPTED json of the source row
            $t->json('errors')->nullable();
            $t->string('dedupe_key', 120)->nullable();
            $t->unique(['import_id', 'row_number']);
        }, false);
        $this->table('payroll_inputs', function (Blueprint $t) {
            $this->employee($t);
            $t->foreignId('payroll_group_id')->nullable()->constrained('payroll_groups')->restrictOnDelete();
            $t->foreignId('period_id')->nullable()->constrained('payroll_periods')->restrictOnDelete();
            $t->string('source', 24);                       // attendance leave overtime variable_pay bonus commission adjustment loan benefit reimbursement import api manual
            $t->string('kind', 32);                         // payable_days overtime_units lop_days bonus commission loan_recovery ... (tenant/provider vocabulary)
            $t->string('component_code', 40)->nullable();
            $t->decimal('quantity', 18, 6)->nullable();     // days / hours / units
            $t->decimal('amount', 18, 4)->nullable();       // explicit money (variable pay, reimbursement)
            $t->char('currency', 3)->nullable();
            $t->string('status', 16)->default('pending');   // draft pending approved rejected consumed void
            $t->boolean('is_final')->default(true);         // false = built on open (non-final) source data
            $t->string('source_ref', 200)->nullable();      // provenance: attendance batch, leave batch_key/request uuid, import row, API client
            $t->foreignId('import_id')->nullable()->constrained('payroll_imports')->restrictOnDelete();
            $t->string('dedupe_key', 160)->nullable();
            $t->json('meta')->nullable();
            $this->user($t, 'created_by');
            $this->user($t, 'approved_by');
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('consumed_run_id')->nullable()->constrained('payroll_runs')->restrictOnDelete();
            $t->unique(['tenant_id', 'dedupe_key'], 'payroll_input_dedupe');
            $t->index(['tenant_id', 'employee_id', 'period_id'], 'payroll_input_employee_period');
            $t->index(['tenant_id', 'source', 'status'], 'payroll_input_source');
        });
        $this->table('payroll_adjustments', function (Blueprint $t) {
            $this->employee($t);
            $t->foreignId('run_id')->nullable()->constrained('payroll_runs')->restrictOnDelete(); // run that applies it
            $t->string('kind', 20);                         // arrear retro correction manual_net recovery reversal
            $t->string('status', 20)->default('pending');   // pending approved applied rejected reversed
            $t->foreignId('origin_period_id')->nullable()->constrained('payroll_periods')->restrictOnDelete();
            $t->foreignId('settlement_period_id')->nullable()->constrained('payroll_periods')->restrictOnDelete();
            $t->string('component_code', 40)->nullable();
            $this->money($t, 'amount');
            $t->char('currency', 3);
            $t->text('reason');
            $t->json('trace')->nullable();                  // period-by-period retro / arrear calculation (explainable)
            $t->foreignId('reverses_id')->nullable()->constrained('payroll_adjustments')->restrictOnDelete(); // reversals keep history
            $this->user($t, 'created_by', false);
            $this->user($t, 'approved_by');
            $t->timestamp('approved_at')->nullable();
            $t->uuid('idempotency_key')->nullable();
            $t->unique(['tenant_id', 'idempotency_key'], 'payroll_adjustment_key');
            $t->index(['tenant_id', 'employee_id', 'status'], 'payroll_adjustment_employee');
        });

        // ---- Results (immutable) ------------------------------------------------------------------------------------
        $this->table('payroll_calculation_results', function (Blueprint $t) {
            $t->foreignId('run_id')->constrained('payroll_runs')->restrictOnDelete();
            $t->foreignId('run_employee_id')->constrained('payroll_run_employees')->restrictOnDelete();
            $this->employee($t);
            $t->unsignedInteger('version');                  // calculation pass number inside the run employee
            $t->boolean('is_current')->default(true);
            $t->unsignedBigInteger('superseded_by_id')->nullable();
            $t->unsignedBigInteger('reverses_id')->nullable(); // reversal / correction results point at what they correct
            $t->string('status', 16)->default('ok');         // ok warning failed
            $t->char('currency', 3);
            $this->money($t, 'gross');
            $this->money($t, 'total_deductions');
            $this->money($t, 'total_taxes');
            $this->money($t, 'employer_contributions');
            $this->money($t, 'benefits');
            $this->money($t, 'reimbursements');
            $this->money($t, 'net');
            $t->decimal('payable_days', 10, 4)->nullable();
            $t->decimal('working_days', 10, 4)->nullable();
            $t->string('engine_version', 20);
            $t->foreignId('country_pack_version_id')->nullable()->constrained('payroll_country_pack_versions')->restrictOnDelete();
            $t->foreignId('compensation_id')->nullable()->constrained('employee_compensations')->restrictOnDelete();
            $t->foreignId('structure_id')->nullable()->constrained('salary_structures')->restrictOnDelete();
            $t->text('input_snapshot')->nullable();         // ENCRYPTED json: every input (with provenance) used
            $t->text('trace')->nullable();                  // ENCRYPTED json: payable-day, proration, formula and rule trace
            $t->json('warnings')->nullable();
            $t->json('errors')->nullable();
            $t->char('checksum', 64);                       // sha256 of canonical inputs+outputs+versions (reproducibility proof)
            $t->unique(['run_employee_id', 'version'], 'payroll_result_version');
            $t->index(['tenant_id', 'employee_id', 'is_current'], 'payroll_result_employee');
            $t->index(['tenant_id', 'run_id', 'is_current'], 'payroll_result_run');
        });
        Schema::table('payroll_run_employees', fn (Blueprint $t) => $t->foreign('current_result_id')->references('id')->on('payroll_calculation_results')->nullOnDelete());
        $this->table('payroll_result_components', function (Blueprint $t) {
            $t->foreignId('result_id')->constrained('payroll_calculation_results')->restrictOnDelete();
            $t->foreignId('component_id')->nullable()->constrained('salary_components')->restrictOnDelete(); // exact component VERSION row
            $t->string('component_code', 40);
            $t->unsignedInteger('component_version')->nullable();
            $t->string('name', 120);
            $t->string('kind', 32);                          // earning deduction tax employee_contribution employer_contribution benefit reimbursement informational
            $t->unsignedSmallInteger('sequence')->default(0);
            $this->money($t, 'amount');                      // always positive magnitude; kind gives the sign
            $this->money($t, 'taxable_amount', true);
            $t->decimal('quantity', 18, 6)->nullable();
            $t->string('formula', 1000)->nullable();         // formula text AS APPLIED (historical trace)
            $t->unsignedInteger('formula_version')->nullable();
            $t->string('origin', 24)->default('compensation'); // compensation input adjustment statutory loan benefit arrear retro
            $t->unsignedBigInteger('source_input_id')->nullable();
            $t->string('source_ref', 200)->nullable();
            $t->boolean('payslip_visible')->default(true);
            $t->string('gl_code', 80)->nullable();
            $t->json('trace')->nullable();                   // operands, rounding steps, rule ids
            $t->index(['tenant_id', 'result_id', 'sequence'], 'payroll_result_component_order');
        }, true);

        // ---- Validation, locks, approvals -------------------------------------------------------------------------
        $this->table('payroll_validation_issues', function (Blueprint $t) {
            $t->foreignId('run_id')->nullable()->constrained('payroll_runs')->restrictOnDelete();
            $t->foreignId('payroll_group_id')->nullable()->constrained('payroll_groups')->restrictOnDelete();
            $t->foreignId('period_id')->nullable()->constrained('payroll_periods')->restrictOnDelete();
            $this->employee($t, true);
            $t->string('code', 60);                          // negative_net, missing_bank, attendance_not_final, ...
            $t->string('severity', 20);                      // information | warning | blocking | critical
            $t->string('message', 400);
            $t->json('details')->nullable();
            $t->json('action')->nullable();                  // {label, href, method, endpoint}
            $t->string('status', 16)->default('open');       // open acknowledged resolved waived
            $this->user($t, 'resolved_by');
            $t->timestamp('resolved_at')->nullable();
            $t->string('resolution_note', 400)->nullable();
            $t->char('fingerprint', 40)->nullable();         // code+subject hash: keeps findings idempotent across re-validation
            $t->index(['tenant_id', 'run_id', 'severity', 'status'], 'payroll_issue_run');
            $t->unique(['tenant_id', 'run_id', 'fingerprint'], 'payroll_issue_fingerprint');
        });
        $this->table('payroll_locks', function (Blueprint $t) {
            $t->foreignId('period_id')->constrained('payroll_periods')->restrictOnDelete();
            $t->foreignId('run_id')->nullable()->constrained('payroll_runs')->restrictOnDelete();
            $t->string('status', 20)->default('locked');    // locked | reopen_requested | released
            $this->user($t, 'locked_by', false);
            $t->timestamp('locked_at');
            $t->text('lock_note')->nullable();
            $this->user($t, 'reopen_requested_by');
            $t->text('reopen_reason')->nullable();
            $this->user($t, 'released_by');
            $t->timestamp('released_at')->nullable();
            $t->json('post_lock_changes')->nullable();      // every change made after reopen is recorded here
            $t->index(['tenant_id', 'period_id', 'status'], 'payroll_lock_period');
        });
        $this->table('payroll_approvals', function (Blueprint $t) {  // maker-checker records (tasks live in My Work)
            $t->string('subject_type', 32);                  // run | unlock | bank_file | mass_revision | manual_net | settlement | loan | revision
            $t->unsignedBigInteger('subject_id');
            $t->unsignedTinyInteger('step')->default(1);
            $t->string('role', 20)->default('approver');     // maker | reviewer | approver
            $t->string('status', 16)->default('pending');    // pending approved rejected cancelled
            $this->user($t, 'requested_by', false);
            $this->user($t, 'decided_by');
            $t->timestamp('decided_at')->nullable();
            $t->text('comment')->nullable();
            $t->index(['tenant_id', 'subject_type', 'subject_id'], 'payroll_approval_subject');
        });

        // ---- Payslips, payments, accounting -----------------------------------------------------------------------
        $this->table('payslips', function (Blueprint $t) {
            $this->employee($t);
            $t->foreignId('run_id')->constrained('payroll_runs')->restrictOnDelete();
            $t->foreignId('result_id')->constrained('payroll_calculation_results')->restrictOnDelete();
            $t->foreignId('period_id')->constrained('payroll_periods')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 16)->default('generated'); // generated published superseded withdrawn
            $t->foreignId('supersedes_id')->nullable()->constrained('payslips')->restrictOnDelete();
            $t->text('snapshot');                            // ENCRYPTED json: employee details, earnings, deductions, YTD, masked bank, branding
            $t->string('file_path', 300)->nullable();        // private disk path of the rendered file
            $t->char('checksum', 64);
            $t->string('layout', 40)->nullable();            // country/tenant layout key
            $this->user($t, 'generated_by');
            $t->timestamp('generated_at');
            $t->timestamp('published_at')->nullable();
            $t->timestamp('first_viewed_at')->nullable();
            $t->unique(['employee_id', 'run_id', 'version'], 'payslip_version');
            $t->index(['tenant_id', 'employee_id', 'status'], 'payslip_employee');
        });
        $this->table('payment_batches', function (Blueprint $t) {
            $t->foreignId('run_id')->constrained('payroll_runs')->restrictOnDelete();
            $t->string('format', 40)->nullable();            // PaymentFormat code
            $t->char('currency', 3);
            $t->string('status', 20)->default('not_prepared'); // not_prepared prepared submitted processing paid failed partially_paid
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('employee_count')->default(0);
            $this->money($t, 'total_amount');
            $t->json('source_account')->nullable();         // company debit account reference (masked in responses)
            $this->user($t, 'prepared_by');
            $this->user($t, 'approved_by');
            $t->timestamp('prepared_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $this->user($t, 'confirmed_by');
            $t->string('confirmation_reference', 120)->nullable(); // bank/manual confirmation; required before "paid"
            $t->index(['tenant_id', 'run_id', 'status'], 'payment_batch_run');
        });
        $this->table('payment_instructions', function (Blueprint $t) {
            $t->foreignId('batch_id')->constrained('payment_batches')->restrictOnDelete();
            $this->employee($t);
            $t->foreignId('result_id')->constrained('payroll_calculation_results')->restrictOnDelete();
            $this->money($t, 'amount');
            $t->char('currency', 3);
            $t->string('status', 20)->default('prepared');   // prepared submitted processing paid failed
            $t->text('beneficiary');                         // ENCRYPTED json snapshot of bank details at preparation time
            $t->string('reference', 120)->nullable();
            $t->string('failure_reason', 300)->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->unique(['batch_id', 'employee_id'], 'payment_instruction_unique');
        });
        $this->table('bank_file_exports', function (Blueprint $t) {
            $t->foreignId('batch_id')->constrained('payment_batches')->restrictOnDelete();
            $t->string('format', 40);
            $t->unsignedInteger('file_version')->default(1);
            $t->string('status', 16)->default('generated'); // generated downloaded expired withdrawn
            $t->unsignedInteger('employee_count');
            $this->money($t, 'total_amount');
            $t->string('file_path', 300)->nullable();       // private disk; short-lived signed download only
            $t->char('checksum', 64)->nullable();
            $this->user($t, 'generated_by', false);
            $t->timestamp('generated_at');
            $t->timestamp('expires_at')->nullable();
            $t->unsignedInteger('download_count')->default(0);
            $t->unique(['batch_id', 'file_version'], 'bank_file_version');
        });
        $this->table('accounting_mappings', function (Blueprint $t) {
            $t->string('component_code', 40)->nullable();    // null + kind = default for the kind
            $t->string('kind', 32)->nullable();
            $t->foreignId('payroll_group_id')->nullable()->constrained('payroll_groups')->restrictOnDelete();
            $t->string('debit_account', 80)->nullable();
            $t->string('credit_account', 80)->nullable();
            $t->json('dimensions')->nullable();             // cost_center, department, legal_entity, location, project (future) source rules
            $t->json('allocation')->nullable();             // allocation across cost centres/departments (percent rows)
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->boolean('active')->default(true);
            $t->index(['tenant_id', 'component_code', 'effective_from'], 'accounting_mapping_resolution');
        });
        $this->table('accounting_exports', function (Blueprint $t) {
            $t->foreignId('run_id')->constrained('payroll_runs')->restrictOnDelete();
            $t->string('format', 40);
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 16)->default('generated');  // generated exported failed reconciled mismatch
            $this->money($t, 'total_debit');
            $this->money($t, 'total_credit');
            $t->json('reconciliation')->nullable();         // net vs payments vs journal checks
            $t->string('file_path', 300)->nullable();
            $this->user($t, 'generated_by', false);
            $t->unique(['run_id', 'format', 'version'], 'accounting_export_version');
        });
        $this->table('accounting_export_lines', function (Blueprint $t) {
            $t->foreignId('export_id')->constrained('accounting_exports')->restrictOnDelete();
            $t->string('account', 80);
            $this->money($t, 'debit');
            $this->money($t, 'credit');
            $t->char('currency', 3);
            $t->json('dimensions')->nullable();
            $t->string('component_code', 40)->nullable();
            $t->string('memo', 200)->nullable();
        }, false);

        // ---- Loans, advances, benefits, tax declarations -------------------------------------------------------------
        $this->table('employee_loans', function (Blueprint $t) {
            $this->employee($t);
            $t->string('status', 20)->default('pending');    // pending approved active closed cancelled written_off
            $t->decimal('principal', 18, 4);
            $t->char('currency', 3);
            $t->date('issue_date');
            $t->date('recovery_start');
            $t->unsignedSmallInteger('installments');
            $t->json('interest')->nullable();               // {type: none|flat|reducing, rate: "decimal string"}
            $t->string('recovery_method', 20)->default('equal'); // equal | custom
            $t->string('purpose', 200)->nullable();
            $this->user($t, 'created_by', false);
            $this->user($t, 'approved_by');
            $t->timestamp('approved_at')->nullable();
            $t->index(['tenant_id', 'employee_id', 'status'], 'loan_employee');
        });
        $this->table('loan_installments', function (Blueprint $t) {
            $t->foreignId('loan_id')->constrained('employee_loans')->restrictOnDelete();
            $t->unsignedSmallInteger('sequence');
            $t->date('due_date');
            $t->decimal('principal', 18, 4);
            $t->decimal('interest', 18, 4)->default(0);
            $t->decimal('amount', 18, 4);
            $t->string('status', 16)->default('scheduled');  // scheduled recovered skipped waived
            $t->foreignId('recovered_run_id')->nullable()->constrained('payroll_runs')->restrictOnDelete();
            $t->unique(['loan_id', 'sequence']);
        });
        $this->table('employee_advances', function (Blueprint $t) {
            $this->employee($t);
            $t->string('status', 20)->default('pending');    // pending approved paid recovering closed cancelled
            $t->decimal('amount', 18, 4);
            $t->decimal('recovered', 18, 4)->default(0);
            $t->char('currency', 3);
            $t->date('issue_date');
            $t->date('recovery_start');
            $t->unsignedSmallInteger('recovery_installments')->default(1);
            $t->string('reason', 300)->nullable();
            $this->user($t, 'created_by', false);
            $this->user($t, 'approved_by');
            $t->index(['tenant_id', 'employee_id', 'status'], 'advance_employee');
        });
        $this->table('benefit_plans', function (Blueprint $t) {
            $t->string('code', 40);
            $t->string('name', 160);
            $t->string('category', 24);                      // insurance meal transport housing wellness retirement flexible custom
            $t->json('provider')->nullable();
            $t->char('country', 2)->nullable();
            $t->char('currency', 3)->nullable();
            $t->json('eligibility')->nullable();            // data-driven rules (worker type, grade, tenure, country, entity)
            $t->json('contributions')->nullable();         // employee / employer rules (fixed, percentage, formula)
            $t->string('tax_treatment', 40)->nullable();
            $t->string('component_code', 40)->nullable();   // payroll component the plan feeds
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('status', 16)->default('draft');     // draft active retired
            $t->unique(['tenant_id', 'code']);
        });
        $this->table('benefit_enrollment_windows', function (Blueprint $t) {
            $t->foreignId('plan_id')->constrained('benefit_plans')->restrictOnDelete();
            $t->string('name', 120);
            $t->date('opens_on');
            $t->date('closes_on');
            $t->date('effective_on');
        });
        $this->table('employee_benefit_enrollments', function (Blueprint $t) {
            $this->employee($t);
            $t->foreignId('plan_id')->constrained('benefit_plans')->restrictOnDelete();
            $t->foreignId('window_id')->nullable()->constrained('benefit_enrollment_windows')->restrictOnDelete();
            $t->string('status', 16)->default('pending');   // pending active declined ended
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->text('employee_contribution')->nullable();   // ENCRYPTED decimal strings
            $t->text('employer_contribution')->nullable();
            $t->json('dependents')->nullable();             // employee_dependents uuids
            $t->index(['tenant_id', 'employee_id', 'status'], 'benefit_enrollment_employee');
        });
        $this->table('payroll_tax_declarations', function (Blueprint $t) {
            $this->employee($t);
            $t->foreignId('pack_id')->constrained('payroll_country_packs')->restrictOnDelete();
            $t->string('tax_year', 9);
            $t->string('regime', 40)->nullable();           // regime / category as defined by the pack
            $t->string('status', 20)->default('draft');     // draft submitted under_review approved rejected
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->text('data');                                // ENCRYPTED json validated against the pack declaration schema
            $this->user($t, 'reviewed_by');
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->index(['tenant_id', 'employee_id', 'tax_year'], 'tax_declaration_employee');
        });
        $this->table('payroll_tax_declaration_proofs', function (Blueprint $t) {
            $t->foreignId('declaration_id')->constrained('payroll_tax_declarations')->restrictOnDelete();
            $t->string('category', 60);
            $t->text('declared_amount')->nullable();         // ENCRYPTED
            $t->text('accepted_amount')->nullable();        // ENCRYPTED
            $t->string('file_path', 300)->nullable();
            $t->string('status', 16)->default('pending');    // pending accepted rejected
            $t->string('note', 300)->nullable();
        });

        // ---- Settlement, simulation, exports, rates, statutory filings ------------------------------------------------
        $this->table('settlement_runs', function (Blueprint $t) {
            $this->employee($t);
            $t->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->restrictOnDelete();
            $t->foreignId('result_id')->nullable()->constrained('payroll_calculation_results')->restrictOnDelete();
            $t->date('last_working_date');
            $t->string('status', 20)->default('draft');     // draft calculated review approved paid closed cancelled
            $t->char('currency', 3);
            $this->money($t, 'net_payable');
            $t->json('items_summary')->nullable();
            $t->foreignId('country_pack_version_id')->nullable()->constrained('payroll_country_pack_versions')->restrictOnDelete();
            $this->user($t, 'created_by', false);
            $this->user($t, 'approved_by');
            $t->uuid('idempotency_key')->nullable();
            $t->unique(['tenant_id', 'idempotency_key'], 'settlement_request_key');
            $t->index(['tenant_id', 'employee_id'], 'settlement_employee');
        });
        $this->table('settlement_items', function (Blueprint $t) {
            $t->foreignId('settlement_id')->constrained('settlement_runs')->restrictOnDelete();
            $t->string('kind', 32);                          // salary leave_encashment notice_pay bonus recovery loan advance benefit_recovery other
            $t->string('direction', 8);                      // pay | recover
            $this->money($t, 'amount');
            $t->string('explanation', 400)->nullable();
            $t->string('source_ref', 200)->nullable();
            $t->json('trace')->nullable();
        }, false);
        $this->table('payroll_simulation_runs', function (Blueprint $t) {
            $t->string('kind', 32);                          // salary_revision policy_change formula_change statutory_rule bonus lop structure_change
            $t->json('params');
            $t->text('result')->nullable();                  // ENCRYPTED json. NEVER feeds a live run.
            $t->string('status', 16)->default('completed');
            $this->user($t, 'created_by', false);
        });
        $this->table('payroll_exports', function (Blueprint $t) {
            $t->string('report', 40);
            $t->string('format', 8);
            $t->string('status', 16)->default('queued');     // queued running ready failed expired
            $t->json('filters')->nullable();
            $t->string('file_path', 300)->nullable();
            $t->unsignedInteger('row_count')->nullable();
            $t->string('error', 300)->nullable();
            $t->timestamp('expires_at')->nullable();
            $this->user($t, 'created_by', false);
        });
        $this->table('payroll_statutory_filings', function (Blueprint $t) {
            $t->foreignId('pack_version_id')->constrained('payroll_country_pack_versions')->restrictOnDelete();
            $t->foreignId('payroll_group_id')->nullable()->constrained('payroll_groups')->restrictOnDelete();
            $t->string('kind', 60);                          // pack-defined report / return key
            $t->date('period_start');
            $t->date('period_end');
            $t->string('status', 16)->default('draft');      // draft generated filed
            $t->string('file_path', 300)->nullable();
            $t->json('totals')->nullable();
            $this->user($t, 'generated_by');
        });
        $this->table('currency_exchange_rates', function (Blueprint $t) {
            $t->char('base_currency', 3);
            $t->char('quote_currency', 3);
            $t->decimal('rate', 20, 10);
            $t->date('as_of');
            $t->string('source', 60)->nullable();
            $t->unique(['tenant_id', 'base_currency', 'quote_currency', 'as_of'], 'exchange_rate_unique');
        }, false);
    }

    public function down(): void
    {
        foreach (['currency_exchange_rates', 'payroll_statutory_filings', 'payroll_exports', 'payroll_simulation_runs', 'settlement_items', 'settlement_runs',
            'payroll_tax_declaration_proofs', 'payroll_tax_declarations', 'employee_benefit_enrollments', 'benefit_enrollment_windows', 'benefit_plans',
            'employee_advances', 'loan_installments', 'employee_loans', 'accounting_export_lines', 'accounting_exports', 'accounting_mappings', 'bank_file_exports',
            'payment_instructions', 'payment_batches', 'payslips', 'payroll_approvals', 'payroll_locks', 'payroll_validation_issues', 'payroll_result_components'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('payroll_run_employees', function (Blueprint $t) {
            $t->dropForeign(['current_result_id']);
        });
        foreach (['payroll_calculation_results', 'payroll_adjustments', 'payroll_inputs', 'payroll_import_rows', 'payroll_imports', 'payroll_run_employees', 'payroll_runs'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('compensation_revisions', function (Blueprint $t) {
            $t->dropForeign(['batch_id']);
        });
        foreach (['compensation_revision_batches', 'compensation_revisions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('compensation_lines', function (Blueprint $t) {
            $t->dropColumn(['sequence', 'basis']);
        });
        Schema::table('employee_compensations', function (Blueprint $t) {
            $t->dropForeign(['structure_id']);
            $t->dropForeign(['supersedes_id']);
            $t->dropColumn(['structure_id', 'supersedes_id', 'currency', 'pay_frequency', 'base_pay', 'gross_reference', 'annual_compensation', 'hourly_rate', 'daily_rate', 'source', 'meta']);
        });
        foreach (['salary_structure_components', 'salary_structures'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('salary_components', function (Blueprint $t) {
            $t->dropIndex('component_kind');
            $t->dropForeign(['created_by']);
            $t->dropColumn(['nature', 'country', 'calc_method', 'tax_classification', 'proration', 'attendance_dependent', 'lop_dependent', 'overtime_dependent', 'rounding_mode', 'rounding_scale',
                'rounding_increment', 'min_amount', 'max_amount', 'statutory_mapping', 'active', 'sequence', 'config', 'created_by', 'published_at']);
        });
        foreach (['payroll_period_transitions', 'payroll_periods', 'payroll_calendars'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('payroll_groups', function (Blueprint $t) {
            $t->dropIndex('payroll_group_country');
            $t->dropForeign(['location_id']);
            $t->dropForeign(['country_pack_id']);
            $t->dropColumn(['employee_category', 'worker_type', 'location_id', 'country_pack_id', 'settings', 'description']);
        });
        foreach (['payroll_country_pack_versions', 'payroll_country_packs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
