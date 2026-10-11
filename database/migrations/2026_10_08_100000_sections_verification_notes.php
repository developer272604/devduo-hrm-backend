<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal profile sections: verification needs a reviewer note (e.g. why a certificate was rejected),
 * and employee skills had a verifier but no verification timestamp. Additive, nullable columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_education', function (Blueprint $t) {
            $t->string('verification_note', 500)->nullable();
        });
        Schema::table('employee_certifications', function (Blueprint $t) {
            $t->string('verification_note', 500)->nullable();
        });
        Schema::table('employee_skills', function (Blueprint $t) {
            $t->timestamp('verified_at')->nullable();
            $t->string('verification_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employee_education', fn (Blueprint $t) => $t->dropColumn('verification_note'));
        Schema::table('employee_certifications', fn (Blueprint $t) => $t->dropColumn('verification_note'));
        Schema::table('employee_skills', fn (Blueprint $t) => $t->dropColumn(['verified_at', 'verification_note']));
    }
};
