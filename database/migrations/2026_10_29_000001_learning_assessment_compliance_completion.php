<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 9 completion: assessment attempt limits/numbering, compliance rule legal-entity targeting, certificate-to-course link. Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_assessments', function (Blueprint $t) {
            $t->unsignedSmallInteger('max_attempts')->nullable()->default(3); // null = unlimited
        });
        Schema::table('learning_attempts', function (Blueprint $t) {
            $t->unsignedSmallInteger('attempt_no')->default(1);
            $t->index(['tenant_id', 'assessment_id', 'employee_id'], 'learning_attempt_emp_idx');
        });
        Schema::table('learning_rules', function (Blueprint $t) {
            $t->unsignedBigInteger('legal_entity_id')->nullable();
        });
        Schema::table('learning_employee_certs', function (Blueprint $t) {
            $t->unsignedBigInteger('course_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('learning_employee_certs', fn (Blueprint $t) => $t->dropColumn('course_id'));
        Schema::table('learning_rules', fn (Blueprint $t) => $t->dropColumn('legal_entity_id'));
        Schema::table('learning_attempts', function (Blueprint $t) { $t->dropIndex('learning_attempt_emp_idx'); $t->dropColumn('attempt_no'); });
        Schema::table('learning_assessments', fn (Blueprint $t) => $t->dropColumn('max_attempts'));
    }
};
