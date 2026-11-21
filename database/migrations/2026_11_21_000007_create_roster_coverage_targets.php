<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_coverage_targets', function (Blueprint $table) {
            $table->foreignId('roster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('shift_key', 36);
            $table->date('work_date');
            $table->unsignedInteger('minimum');
            $table->primary(['roster_id', 'user_id', 'shift_key', 'work_date'], 'roster_coverage_target_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_coverage_targets');
    }
};
