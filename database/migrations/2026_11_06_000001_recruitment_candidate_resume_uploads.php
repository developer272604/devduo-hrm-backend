<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_resumes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('uploaded_by');
        });
        Schema::table('recruitment_resumes', function (Blueprint $t) {
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('uploaded_source', 24)->default('recruiter')->index();
            $t->string('review_status', 32)->default('available')->index();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_resumes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('uploaded_by');
            $t->dropConstrainedForeignId('reviewed_by');
            $t->dropColumn(['uploaded_source', 'review_status', 'reviewed_at']);
        });
        Schema::table('recruitment_resumes', function (Blueprint $t) {
            $t->foreignId('uploaded_by')->constrained('users');
        });
    }
};
