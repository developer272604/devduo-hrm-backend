<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experience_ticket_escalations', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tenant_id')->index(); $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('ticket_id')->index(); $t->string('escalation_type', 24);
            $t->unsignedSmallInteger('level')->default(1); $t->string('dedupe_key', 150);
            $t->unsignedBigInteger('created_by')->nullable(); $t->text('reason'); $t->dateTime('notified_at')->nullable(); $t->timestamps();
            $t->foreign('ticket_id')->references('id')->on('experience_tickets')->cascadeOnDelete();
            $t->unique(['tenant_id', 'dedupe_key']);
            $t->index(['tenant_id', 'ticket_id', 'created_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('experience_ticket_escalations'); }
};
