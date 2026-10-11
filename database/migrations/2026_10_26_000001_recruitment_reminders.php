<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_applications', fn (Blueprint $table) => $table->dateTime('stage_reminded_at')->nullable());
        Schema::table('recruitment_interview_panelists', fn (Blueprint $table) => $table->dateTime('feedback_reminded_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('recruitment_interview_panelists', fn (Blueprint $table) => $table->dropColumn('feedback_reminded_at'));
        Schema::table('recruitment_applications', fn (Blueprint $table) => $table->dropColumn('stage_reminded_at'));
    }
};
