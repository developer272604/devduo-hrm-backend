<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_resumes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->foreignId('candidate_id')->constrained('recruitment_candidates')->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->string('disk', 40);
            $t->string('path', 255)->nullable();
            $t->string('original_name', 200);
            $t->string('mime', 120);
            $t->unsignedBigInteger('size');
            $t->string('sha256', 64);
            $t->foreignId('uploaded_by')->constrained('users');
            $t->dateTime('purged_at')->nullable()->index();
            $t->timestamps();
            $t->unique(['candidate_id', 'version']);
        });
        Schema::create('recruitment_offer_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->string('code', 60);
            $t->unsignedInteger('version');
            $t->string('name', 160);
            $t->longText('body');
            $t->string('status', 20)->default('active');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['tenant_id', 'code', 'version']);
        });
        Schema::create('recruitment_offer_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->foreignId('offer_id')->constrained('recruitment_offers')->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->foreignId('template_id')->constrained('recruitment_offer_templates');
            $t->unsignedInteger('template_version');
            $t->string('trigger', 20);
            $t->longText('content')->nullable();
            $t->text('compensation_snapshot')->nullable();
            $t->string('sha256', 64);
            $t->foreignId('generated_by')->constrained('users');
            $t->dateTime('purged_at')->nullable()->index();
            $t->timestamps();
            $t->unique(['offer_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_offer_documents');
        Schema::dropIfExists('recruitment_offer_templates');
        Schema::dropIfExists('recruitment_resumes');
    }
};
