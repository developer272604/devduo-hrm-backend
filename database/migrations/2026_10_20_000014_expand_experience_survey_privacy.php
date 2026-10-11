<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experience_surveys', function (Blueprint $t) {
            $t->string('campaign_type', 16)->default('custom');
            $t->boolean('requires_consent')->default(false);
            $t->json('eligibility_filters')->nullable();
            $t->date('retention_until')->nullable();
            $t->dateTime('data_purged_at')->nullable();
        });
        Schema::table('experience_survey_participants', function (Blueprint $t) {
            $t->boolean('consented')->default(false);
        });
        DB::table('experience_surveys')->whereNull('retention_until')->orderBy('id')->get(['id', 'closes_at', 'created_at'])->each(function ($survey) {
            $base = $survey->closes_at ?: $survey->created_at;
            DB::table('experience_surveys')->where('id', $survey->id)->update(['retention_until' => Carbon::parse($base)->addMonths(6)->toDateString()]);
        });
    }

    public function down(): void
    {
        Schema::table('experience_survey_participants', fn (Blueprint $t) => $t->dropColumn('consented'));
        Schema::table('experience_surveys', fn (Blueprint $t) => $t->dropColumn(['campaign_type', 'requires_consent', 'eligibility_filters', 'retention_until', 'data_purged_at']));
    }
};
