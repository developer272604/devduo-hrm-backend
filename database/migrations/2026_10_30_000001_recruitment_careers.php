<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_postings', function (Blueprint $t) {
            $t->boolean('public_enabled')->default(false);
            $t->text('privacy_notice')->nullable();
            $t->string('notice_version', 80)->nullable();
            $t->unsignedSmallInteger('retention_days')->nullable();
        });
        Schema::create('recruitment_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->foreignId('posting_id')->constrained('recruitment_postings');
            $t->longText('payload')->nullable();
            $t->string('status')->default('pending');
            $t->dateTime('expires_at')->index();
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_submissions');
        Schema::table('recruitment_postings', fn (Blueprint $t) => $t->dropColumn(['public_enabled', 'privacy_notice', 'notice_version', 'retention_days']));
    }
};
