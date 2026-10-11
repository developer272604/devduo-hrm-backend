<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_pipelines', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 40); $t->unsignedInteger('version'); $t->string('name', 120);
            $t->string('status', 16)->default('active'); // active|retired
            $t->boolean('is_default')->default(false);
            $t->boolean('allow_skip')->default(false);
            $t->unsignedInteger('feedback_reminder_hours')->default(24);
            $t->dateTime('used_at')->nullable(); // set when a requisition snapshots this version: immutable afterwards
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unique(['tenant_id', 'code', 'version']);
            $t->index(['tenant_id', 'is_default', 'status']);
            $t->timestamps();
        });
        Schema::create('recruitment_pipeline_stages', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('pipeline_id')->constrained('recruitment_pipelines')->cascadeOnDelete();
            $t->unsignedSmallInteger('position'); $t->string('code', 24); $t->string('label', 80);
            $t->string('kind', 12)->default('active'); // active|offer|hired|rejected
            $t->unsignedInteger('sla_hours')->nullable();
            $t->json('reminder_recipients')->nullable();
            $t->unique(['pipeline_id', 'code']); $t->unique(['pipeline_id', 'position']);
            $t->timestamps();
        });
        Schema::table('recruitment_requisitions', function (Blueprint $t) {
            $t->string('pipeline_code', 40)->nullable();
            $t->unsignedBigInteger('pipeline_id')->nullable()->index();
            $t->unsignedInteger('version')->default(1);
        });
        Schema::create('recruitment_requisition_versions', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('requisition_id')->constrained('recruitment_requisitions')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('status', 16); // applied|pending|approved|rejected
            $t->boolean('requires_approval')->default(false);
            $t->json('before'); $t->json('after'); $t->json('changed_fields');
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('decided_at')->nullable();
            $t->unique(['requisition_id', 'version']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_requisition_versions');
        Schema::table('recruitment_requisitions', fn (Blueprint $t) => $t->dropColumn(['pipeline_code', 'pipeline_id', 'version']));
        Schema::dropIfExists('recruitment_pipeline_stages');
        Schema::dropIfExists('recruitment_pipelines');
    }
};
