<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private function table(string $name, callable $columns): void
    {
        Schema::create($name, function (Blueprint $t) use ($columns) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $columns($t);
            $t->timestamps();
        });
    }

    public function up(): void
    {
        $this->table('employees', function ($t) {
            $t->string('number', 40); $t->string('name');
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->date('joined_on'); $t->date('separated_on')->nullable();
            $t->string('timezone')->default('Asia/Kolkata'); $t->string('country', 2);
            $t->unique(['tenant_id', 'number']); $t->unique(['tenant_id', 'user_id']);
        });
        $this->table('employment_assignments', function ($t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('manager_id')->nullable()->constrained('employees')->restrictOnDelete();
            $t->date('effective_from'); $t->date('effective_to')->nullable();
            $t->json('attributes'); $t->index(['tenant_id','employee_id','effective_from'], 'employment_effective');
        });
        foreach (['work_weeks','holiday_calendars','shifts','attendance_policy_versions','leave_types','leave_policy_versions'] as $name) {
            $this->table($name, function ($t) {
                $t->string('name'); $t->string('code', 40); $t->unsignedInteger('version')->default(1);
                $t->json('rules'); $t->date('effective_from'); $t->date('effective_to')->nullable();
                $t->string('status')->default('published');
                $t->unique(['tenant_id','code','version']);
            });
        }
        $this->table('holidays', function ($t) {
            $t->foreignId('calendar_id')->constrained('holiday_calendars')->restrictOnDelete();
            $t->date('date'); $t->string('name'); $t->string('kind')->default('mandatory');
            $t->unsignedSmallInteger('fraction')->default(10000);
            $t->unique(['tenant_id','calendar_id','date']);
        });
        $this->table('time_assignments', function ($t) {
            $t->string('kind'); $t->unsignedBigInteger('record_id'); $t->string('scope')->default('tenant');
            $t->string('scope_value')->default('*'); $t->date('effective_from'); $t->date('effective_to')->nullable();
            $t->index(['tenant_id','kind','effective_from'], 'time_assignment_resolution');
        });
        $this->table('roster_entries', function ($t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete(); $t->date('date');
            $t->foreignId('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $t->string('status')->default('draft'); $t->boolean('off')->default(false); $t->json('history');
            $t->unique(['tenant_id','employee_id','date']);
        });
        $this->table('attendance_events', function ($t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->dateTime('occurred_at'); $t->string('direction', 10); $t->string('source');
            $t->string('idempotency_key', 100); $t->json('context');
            $t->unique(['tenant_id','idempotency_key']); $t->index(['tenant_id','employee_id','occurred_at'], 'event_employee_time');
        });
        $this->table('attendance_days', function ($t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete(); $t->date('date');
            $t->string('status'); $t->string('confidence'); $t->unsignedInteger('version')->default(1);
            $t->integer('worked_minutes')->default(0); $t->integer('required_minutes')->default(0);
            $t->integer('late_minutes')->default(0); $t->integer('early_minutes')->default(0);
            $t->integer('overtime_minutes')->default(0); $t->integer('approved_overtime_minutes')->default(0);
            $t->integer('leave_minutes')->default(0); $t->json('snapshot');
            $t->unique(['tenant_id','employee_id','date']);
        });
        $this->table('attendance_calculation_traces', function ($t) {
            $t->foreignId('day_id')->constrained('attendance_days')->restrictOnDelete();
            $t->unsignedInteger('version'); $t->json('trace'); $t->unique(['tenant_id','day_id','version']);
        });
        $this->table('time_requests', function ($t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->string('kind'); $t->date('start_date'); $t->date('end_date');
            $t->string('status')->default('pending'); $t->text('reason'); $t->json('payload');
            $t->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->string('idempotency_key',100); $t->unique(['tenant_id','idempotency_key']);
            $t->index(['tenant_id','employee_id','start_date','end_date'], 'request_employee_dates');
        });
        $this->table('time_request_actions', function ($t) {
            $t->foreignId('request_id')->constrained('time_requests')->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete(); $t->string('action'); $t->text('reason');
        });
        $this->table('leave_request_segments', function ($t) {
            $t->foreignId('request_id')->constrained('time_requests')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $t->date('date'); $t->integer('units'); $t->integer('minutes');
            $t->string('session'); $t->json('trace'); $t->unique(['tenant_id','request_id','date']);
            $t->index(['tenant_id','employee_id','date'], 'leave_employee_date');
        });
        $this->table('employee_leave_accounts', function ($t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $t->unique(['tenant_id','employee_id','leave_type_id'], 'leave_account_identity');
        });
        $this->table('leave_ledger_entries', function ($t) {
            $t->foreignId('account_id')->constrained('employee_leave_accounts')->restrictOnDelete();
            $t->integer('units'); $t->string('type'); $t->date('effective_date'); $t->date('expires_on')->nullable();
            $t->string('source'); $t->string('source_id'); $t->string('idempotency_key',100);
            $t->text('reason'); $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->json('trace'); $t->unique(['tenant_id','idempotency_key']);
            $t->index(['tenant_id','account_id','effective_date'], 'ledger_account_date');
        });
        $this->table('time_period_locks', function ($t) {
            $t->string('module'); $t->date('start_date'); $t->date('end_date');
            $t->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('unlocked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('reason'); $t->text('unlock_reason')->nullable(); $t->timestamp('unlocked_at')->nullable();
            $t->json('snapshot');
        });
        $this->table('time_processing_runs', function ($t) {
            $t->string('kind'); $t->string('status')->default('queued'); $t->json('parameters');
            $t->unsignedInteger('processed')->default(0); $t->unsignedInteger('failed')->default(0);
            $t->json('errors'); $t->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['time_processing_runs','time_period_locks','leave_ledger_entries','employee_leave_accounts','leave_request_segments','time_request_actions','time_requests','attendance_calculation_traces','attendance_days','attendance_events','roster_entries','time_assignments','holidays','leave_policy_versions','leave_types','attendance_policy_versions','shifts','holiday_calendars','work_weeks','employment_assignments','employees'] as $table) Schema::dropIfExists($table);
    }
};
