<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experience_surveys', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('title', 200); $t->text('description')->nullable(); $t->string('status', 16)->default('draft');
            $t->date('opens_at')->nullable(); $t->date('closes_at')->nullable(); $t->unsignedSmallInteger('minimum_responses')->default(5);
            $t->unsignedBigInteger('created_by'); $t->unsignedBigInteger('published_by')->nullable(); $t->timestamps();
            $t->index(['tenant_id', 'status', 'opens_at', 'closes_at']);
        });
        Schema::create('experience_survey_questions', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('survey_id'); $t->unsignedTinyInteger('position'); $t->string('prompt', 300);
            $t->string('kind', 16)->default('rating'); $t->timestamps(); $t->unique(['tenant_id', 'survey_id', 'position']);
        });
        // Only aggregate bucket counts are stored; individual ratings and respondent bundles never persist.
        Schema::create('experience_survey_answer_counts', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('survey_id'); $t->unsignedBigInteger('question_id'); $t->unsignedTinyInteger('answer'); $t->unsignedInteger('response_count')->default(0);
            $t->unique(['tenant_id', 'survey_id', 'question_id', 'answer'], 'experience_survey_bucket_unique');
        });
        // This table tracks duplicate submissions only and is never joined to answer rows.
        Schema::create('experience_survey_participants', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('survey_id'); $t->unsignedBigInteger('user_id'); $t->boolean('submitted')->default(false);
            $t->unique(['tenant_id', 'survey_id', 'user_id'], 'experience_survey_participant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_survey_participants');
        Schema::dropIfExists('experience_survey_answer_counts');
        Schema::dropIfExists('experience_survey_questions');
        Schema::dropIfExists('experience_surveys');
    }
};
