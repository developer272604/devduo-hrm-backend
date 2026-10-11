<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experience_ticket_attachments', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tenant_id')->index(); $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('ticket_id')->index(); $t->unsignedBigInteger('uploaded_by')->index();
            $t->string('filename', 255); $t->string('mime_type', 120); $t->unsignedInteger('size_bytes'); $t->string('path', 500);
            $t->timestamps();
            $t->foreign('ticket_id')->references('id')->on('experience_tickets')->cascadeOnDelete();
        });
    }

    public function down(): void { Schema::dropIfExists('experience_ticket_attachments'); }
};
