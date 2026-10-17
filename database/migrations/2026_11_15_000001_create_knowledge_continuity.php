<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What each person is the only one who really knows, and who could cover it. Reads against upcoming exits.
        Schema::create('continuity_responsibilities', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('owner_employee_id')->constrained('employees')->restrictOnDelete();
            $t->string('title', 200); $t->text('description')->nullable();
            $t->string('criticality', 10)->default('medium'); // low | medium | high | critical
            $t->boolean('documented')->default(false); $t->string('documentation_ref', 500)->nullable();
            $t->foreignId('backup_employee_id')->nullable()->constrained('employees')->restrictOnDelete(); $t->boolean('backup_trained')->default(false);
            $t->date('last_reviewed_on')->nullable(); $t->string('status', 10)->default('active'); // active | retired
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->index(['tenant_id', 'owner_employee_id', 'status'], 'continuity_owner_status_idx'); // MySQL identifiers are limited to 64 characters
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('continuity_responsibilities');
    }
};
