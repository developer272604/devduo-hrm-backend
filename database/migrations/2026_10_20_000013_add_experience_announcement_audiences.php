<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experience_announcements', function (Blueprint $t) {
            $t->json('audience_filters')->nullable(); $t->boolean('audience_all')->default(true); $t->boolean('pinned')->default(false);
            $t->date('expires_at')->nullable();
        });
        Schema::create('experience_announcement_recipients', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->unsignedBigInteger('announcement_id'); $t->unsignedBigInteger('employee_id');
            $t->unique(['tenant_id', 'announcement_id', 'employee_id'], 'experience_announcement_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_announcement_recipients');
        Schema::table('experience_announcements', function (Blueprint $t) {
            $t->dropColumn(['audience_filters', 'audience_all', 'pinned', 'expires_at']);
        });
    }
};
