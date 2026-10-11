<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experience_surveys', function (Blueprint $t) {
            $t->string('response_mode', 16)->default('anonymous');
        });

        // Confidential payloads contain no participant foreign key or response timestamp.
        Schema::create('experience_survey_confidential_responses', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('survey_id')->index(); $t->uuid('response_uuid')->unique(); $t->longText('encrypted_answers');
            $t->foreign('survey_id')->references('id')->on('experience_surveys')->cascadeOnDelete();
            $t->unique(['tenant_id', 'survey_id', 'response_uuid'], 'experience_survey_confidential_uuid_unique');
        });

        // Identified payloads deliberately link to the duplicate-submission record and are
        // available only behind the separately granted identifiable-response permission.
        Schema::create('experience_survey_identified_responses', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('survey_id')->index(); $t->unsignedBigInteger('participant_id')->unique(); $t->longText('encrypted_answers');
            $t->foreign('survey_id')->references('id')->on('experience_surveys')->cascadeOnDelete();
            $t->foreign('participant_id')->references('id')->on('experience_survey_participants')->cascadeOnDelete();
            $t->unique(['tenant_id', 'survey_id', 'participant_id'], 'experience_survey_identified_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_survey_identified_responses');
        Schema::dropIfExists('experience_survey_confidential_responses');
        Schema::table('experience_surveys', fn (Blueprint $t) => $t->dropColumn('response_mode'));
    }
};
