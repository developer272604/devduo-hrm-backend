<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experience_announcements', function (Blueprint $t) {
            $t->string('communication_type', 20)->default('announcement')->index();
            $t->dateTime('event_starts_at')->nullable();
            $t->dateTime('event_ends_at')->nullable();
            $t->string('event_location', 200)->nullable();
        });

        Schema::create('experience_event_rsvps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('announcement_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('response', 16);
            $t->timestamps();
            $t->unique(['tenant_id', 'announcement_id', 'user_id'], 'experience_event_rsvp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_event_rsvps');
        Schema::table('experience_announcements', function (Blueprint $t) {
            $t->dropColumn(['communication_type', 'event_starts_at', 'event_ends_at', 'event_location']);
        });
    }
};
