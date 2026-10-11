<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experience_survey_question_bank', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('prompt', 300); $t->string('category', 50)->nullable(); $t->boolean('active')->default(true); $t->unsignedBigInteger('created_by'); $t->timestamps();
            $t->index(['tenant_id', 'active', 'category']);
        });
        Schema::create('experience_survey_scales', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 30); $t->string('name', 100); $t->unsignedTinyInteger('minimum_value'); $t->unsignedTinyInteger('maximum_value');
            $t->json('labels')->nullable(); $t->boolean('active')->default(true); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
            $t->unique(['tenant_id', 'code'], 'experience_survey_scale_code_unique'); $t->index(['tenant_id', 'active']);
        });
        Schema::table('experience_survey_questions', function (Blueprint $t) {
            $t->unsignedBigInteger('bank_question_id')->nullable()->index();
            $t->unsignedTinyInteger('scale_minimum')->default(1);
            $t->unsignedTinyInteger('scale_maximum')->default(5);
            $t->json('scale_labels')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('experience_survey_questions', fn (Blueprint $t) => $t->dropColumn(['bank_question_id', 'scale_minimum', 'scale_maximum', 'scale_labels']));
        Schema::dropIfExists('experience_survey_scales');
        Schema::dropIfExists('experience_survey_question_bank');
    }
};
