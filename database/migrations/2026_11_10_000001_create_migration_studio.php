<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A project orchestrates the EXISTING per-module imports (people, leave, payroll compensation) in dependency order and proves the result.
        Schema::create('migration_projects', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name', 200); $t->string('source_system', 120)->nullable();
            $t->string('status', 20)->default('planning'); // planning | in_progress | ready_for_cutover | live | cancelled
            $t->date('target_cutover_on')->nullable(); $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('went_live_at')->nullable(); $t->foreignId('went_live_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
        });
        Schema::create('migration_stages', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('project_id')->constrained('migration_projects')->restrictOnDelete();
            $t->string('code', 40); $t->unsignedSmallInteger('position');
            $t->string('import_uuid', 36)->nullable();
            $t->string('status', 20)->default('pending'); // pending | linked | imported | verified | skipped
            $t->text('skip_reason')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('verified_at')->nullable();
            $t->text('override_reason')->nullable();
            $t->json('import_snapshot')->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'code']);
            $t->index(['tenant_id', 'import_uuid']);
        });
        Schema::create('migration_reconciliations', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('stage_id')->constrained('migration_stages')->restrictOnDelete();
            $t->string('metric', 40);
            $t->decimal('source_value', 18, 4); $t->decimal('tolerance', 18, 4)->default(0);
            $t->decimal('imported_value', 18, 4)->nullable(); $t->decimal('variance', 18, 4)->nullable();
            $t->string('status', 12)->default('pending'); // pending | matched | variance
            $t->text('note')->nullable();
            $t->foreignId('entered_by')->constrained('users')->restrictOnDelete(); $t->timestamp('checked_at')->nullable();
            $t->timestamps();
            $t->unique(['stage_id', 'metric']);
        });
        Schema::create('migration_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('project_id')->constrained('migration_projects')->restrictOnDelete();
            $t->string('type', 30); $t->string('stage_code', 40)->nullable(); $t->text('detail')->nullable();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('created_at');
            $t->index(['tenant_id', 'project_id']);
        });
    }

    public function down(): void
    {
        foreach (['migration_events', 'migration_reconciliations', 'migration_stages', 'migration_projects'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
