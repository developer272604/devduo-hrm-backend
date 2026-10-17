<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Value claims always carry a written basis. Only recovered leakage cases are "verified"; everything else is labelled estimated or claimed.
        Schema::create('value_ledger_entries', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->date('entry_month'); $t->string('category', 16); // recovered_cost | time_saved | avoided_cost | other
            $t->string('title', 200); $t->text('basis');
            $t->decimal('amount', 18, 4)->nullable(); $t->char('currency', 3)->nullable(); $t->decimal('hours', 10, 2)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->index(['tenant_id', 'entry_month']);
        });
        Schema::create('value_workflow_assumptions', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('definition_id')->constrained('workflow_definitions')->restrictOnDelete();
            $t->unsignedSmallInteger('minutes_per_run'); $t->decimal('hourly_cost', 12, 2)->nullable(); $t->char('currency', 3)->nullable(); $t->text('basis');
            $t->foreignId('set_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->unique(['tenant_id', 'definition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('value_workflow_assumptions');
        Schema::dropIfExists('value_ledger_entries');
    }
};
