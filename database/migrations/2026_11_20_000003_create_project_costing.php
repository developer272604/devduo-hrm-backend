<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_project_weekly_rates', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained('work_projects')->cascadeOnDelete();
            $table->date('week_start');
            $table->string('currency', 3);
            $table->unsignedBigInteger('billing_minor');
            $table->unsignedBigInteger('labor_minor');
            $table->unsignedBigInteger('expenses_minor');
            $table->primary(['project_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_project_weekly_rates');
    }
};
