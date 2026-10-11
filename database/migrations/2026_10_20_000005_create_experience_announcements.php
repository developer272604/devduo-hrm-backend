<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experience_announcements', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('title', 200); $t->longText('body'); $t->boolean('acknowledgment_required')->default(false);
            $t->string('status', 16)->default('draft'); $t->date('publish_at')->nullable();
            $t->unsignedBigInteger('created_by'); $t->unsignedBigInteger('published_by')->nullable(); $t->timestamps();
            $t->index(['tenant_id', 'status', 'publish_at']);
        });
        Schema::create('experience_announcement_reads', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('announcement_id'); $t->unsignedBigInteger('user_id');
            $t->dateTime('read_at')->nullable(); $t->dateTime('acknowledged_at')->nullable(); $t->timestamps();
            $t->unique(['tenant_id', 'announcement_id', 'user_id'], 'experience_announcement_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_announcement_reads');
        Schema::dropIfExists('experience_announcements');
    }
};
