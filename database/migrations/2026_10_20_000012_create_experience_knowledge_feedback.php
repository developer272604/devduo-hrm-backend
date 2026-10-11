<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experience_knowledge_feedback', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('article_id'); $t->unsignedBigInteger('user_id'); $t->boolean('helpful'); $t->timestamps();
            $t->unique(['tenant_id', 'article_id', 'user_id'], 'experience_knowledge_feedback_unique');
        });
    }

    public function down(): void { Schema::dropIfExists('experience_knowledge_feedback'); }
};
