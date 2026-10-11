<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_portal_challenges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('candidate_id')->constrained('recruitment_candidates')->cascadeOnDelete();
            $t->string('email_hash', 64);
            $t->string('token_hash', 64)->unique();
            $t->string('browser_hash', 64);
            $t->dateTime('expires_at')->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_portal_challenges');
    }
};
