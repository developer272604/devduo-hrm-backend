<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Business results the company enters (orders, units, revenue...) so workforce cost can be read against output.
        Schema::create('impact_metrics', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 40); $t->string('name', 120); $t->string('unit', 40);
            $t->string('kind', 10)->default('output'); // output | revenue
            $t->char('currency', 3)->nullable(); $t->string('description', 500)->nullable(); $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });
        Schema::create('impact_values', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('metric_id')->constrained('impact_metrics')->restrictOnDelete();
            $t->date('period_start'); $t->decimal('value', 18, 4);
            $t->string('source', 12)->default('manual'); // manual | import
            $t->string('note', 300)->nullable(); $t->foreignId('entered_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->unique(['metric_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impact_values');
        Schema::dropIfExists('impact_metrics');
    }
};
