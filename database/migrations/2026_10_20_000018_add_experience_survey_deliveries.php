<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experience_surveys', function (Blueprint $t) {
            $t->unsignedSmallInteger('reminder_interval_days')->nullable();
            $t->unsignedTinyInteger('max_reminders')->default(0);
        });
        Schema::create('experience_survey_deliveries', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->unsignedBigInteger('survey_id')->index(); $t->unsignedBigInteger('user_id')->index();
            $t->dateTime('invitation_processed_at')->nullable(); $t->dateTime('invited_at')->nullable(); $t->dateTime('invitation_suppressed_at')->nullable();
            $t->dateTime('last_reminded_at')->nullable(); $t->unsignedTinyInteger('reminder_count')->default(0); $t->timestamps();
            $t->foreign('survey_id')->references('id')->on('experience_surveys')->cascadeOnDelete();
            $t->unique(['tenant_id', 'survey_id', 'user_id'], 'experience_survey_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_survey_deliveries');
        Schema::table('experience_surveys', fn (Blueprint $t) => $t->dropColumn(['reminder_interval_days', 'max_reminders']));
    }
};
