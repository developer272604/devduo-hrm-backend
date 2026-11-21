<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_projects', function (Blueprint $table) {
            $table->string('client_name', 150)->nullable();
        });
        Schema::create('work_project_weekly_capacity', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained('work_projects')->cascadeOnDelete();
            $table->date('week_start');
            $table->unsignedInteger('planned_minutes');
            $table->primary(['project_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_project_weekly_capacity');
        Schema::table('work_projects', fn (Blueprint $table) => $table->dropColumn('client_name'));
    }
};
