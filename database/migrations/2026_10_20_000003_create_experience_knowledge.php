<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experience_knowledge_articles', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('article_key', 180); $t->unsignedInteger('version'); $t->string('locale', 12)->default('en');
            $t->string('title', 200); $t->string('summary', 500)->nullable(); $t->longText('body');
            $t->string('visibility', 12)->default('employee'); $t->string('status', 16)->default('draft');
            $t->date('effective_from')->nullable(); $t->date('effective_until')->nullable();
            $t->unsignedBigInteger('created_by'); $t->unsignedBigInteger('published_by')->nullable(); $t->timestamps();
            $t->unique(['tenant_id', 'article_key', 'version', 'locale'], 'experience_article_version_unique');
            $t->index(['tenant_id', 'status', 'visibility', 'locale'], 'experience_article_search_idx');
        });
    }

    public function down(): void { Schema::dropIfExists('experience_knowledge_articles'); }
};
