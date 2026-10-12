<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 8 closeout: employee appeal queue for finalized reviews, kept separate from immutable review snapshots. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_review_appeals', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('review_id')->constrained('performance_reviews')->restrictOnDelete();
            $t->string('status', 16)->default('pending'); // pending|approved|rejected
            $t->text('reason');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->dateTime('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'status']);
            $t->index(['review_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_review_appeals');
    }
};
