<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experience_announcements', fn (Blueprint $t) => $t->dateTime('notifications_sent_at')->nullable());
        Schema::create('experience_notification_preferences', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->unsignedBigInteger('user_id')->index();
            foreach (['announcements', 'surveys', 'recognition', 'events'] as $category) {
                $t->boolean($category.'_in_app')->default(true); $t->boolean($category.'_email')->default(false);
            }
            $t->timestamps(); $t->unique(['tenant_id', 'user_id'], 'experience_notification_preferences_user_unique');
        });
        Schema::create('experience_announcement_deliveries', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->unsignedBigInteger('announcement_id')->index(); $t->unsignedBigInteger('user_id')->index();
            $t->dateTime('sent_at')->nullable(); $t->dateTime('suppressed_at')->nullable(); $t->timestamps();
            $t->foreign('announcement_id')->references('id')->on('experience_announcements')->cascadeOnDelete();
            $t->unique(['tenant_id', 'announcement_id', 'user_id'], 'experience_announcement_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_announcement_deliveries');
        Schema::dropIfExists('experience_notification_preferences');
        Schema::table('experience_announcements', fn (Blueprint $t) => $t->dropColumn('notifications_sent_at'));
    }
};
