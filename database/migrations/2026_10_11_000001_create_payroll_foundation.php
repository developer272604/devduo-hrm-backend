<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend the Phase 3 reference group; existing attendance references remain valid.
        Schema::table('payroll_groups', function (Blueprint $t) {
            $t->uuid('uuid')->nullable()->unique();
            $t->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->restrictOnDelete();
            $t->char('country', 2)->nullable(); $t->char('currency', 3)->nullable(); $t->string('timezone', 64)->nullable();
            $t->string('frequency', 24)->nullable();
        });
        \Illuminate\Support\Facades\DB::table('payroll_groups')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) \Illuminate\Support\Facades\DB::table('payroll_groups')->where('id', $row->id)->update(['uuid' => (string) \Illuminate\Support\Str::uuid()]);
        });
        $this->table('salary_components', function (Blueprint $t) {
            $t->string('code', 40); $t->string('name', 120); $t->string('kind', 32);
            $t->char('currency', 3); $t->unsignedInteger('version');
            $t->date('effective_from'); $t->date('effective_to')->nullable();
            $t->string('formula', 1000)->nullable(); $t->boolean('payslip_visible')->default(true);
            $t->string('gl_code', 80)->nullable();
            $t->unique(['tenant_id', 'code', 'version']);
        });
        $this->table('employee_compensations', function (Blueprint $t) {
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('payroll_group_id')->constrained()->restrictOnDelete();
            $t->date('effective_from'); $t->date('effective_to')->nullable();
            $t->string('status', 24)->default('pending'); $t->text('reason');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->uuid('idempotency_key');
            $t->char('request_hash', 64);
            $t->unique(['tenant_id', 'idempotency_key'], 'compensation_request_key');
            $t->index(['tenant_id', 'employee_id', 'effective_from'], 'compensation_resolution');
        });
        $this->table('compensation_lines', function (Blueprint $t) {
            $t->foreignId('compensation_id')->constrained('employee_compensations')->restrictOnDelete();
            $t->foreignId('component_id')->constrained('salary_components')->restrictOnDelete();
            $t->text('amount'); // Encrypted decimal string; never binary floating point.
            $t->unique(['compensation_id', 'component_id']);
        });
    }

    private function table(string $name, Closure $columns): void
    {
        Schema::create($name, function (Blueprint $t) use ($columns) {
            $t->id(); $t->uuid('uuid')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $columns($t); $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['compensation_lines', 'employee_compensations', 'salary_components'] as $table) Schema::dropIfExists($table);
        Schema::table('payroll_groups', function (Blueprint $t) {
            $t->dropForeign(['legal_entity_id']);
            $t->dropUnique(['uuid']);
            $t->dropColumn(['uuid', 'legal_entity_id', 'country', 'currency', 'timezone', 'frequency']);
        });
    }
};
