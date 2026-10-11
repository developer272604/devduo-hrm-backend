<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experience_milestone_deliveries', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->unsignedBigInteger('employee_id')->index(); $t->unsignedBigInteger('user_id')->index();
            $t->string('milestone_type', 24); $t->date('milestone_date'); $t->dateTime('sent_at')->nullable(); $t->dateTime('suppressed_at')->nullable(); $t->timestamps();
            $t->unique(['tenant_id', 'user_id', 'milestone_type', 'milestone_date'], 'experience_milestone_delivery_unique');
        });
    }

    public function down(): void { Schema::dropIfExists('experience_milestone_deliveries'); }
};
