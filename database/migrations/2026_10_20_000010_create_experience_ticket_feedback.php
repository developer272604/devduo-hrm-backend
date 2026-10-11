<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experience_ticket_feedback', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('ticket_id')->unique(); $t->unsignedBigInteger('employee_id'); $t->unsignedTinyInteger('rating');
            $t->string('comment', 1000)->nullable(); $t->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('experience_ticket_feedback'); }
};
