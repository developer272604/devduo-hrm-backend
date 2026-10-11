<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_application_actions', function (Blueprint $t) {
            $t->unsignedBigInteger('actor_id')->nullable()->change();
            $t->foreignId('candidate_actor_id')->nullable()->constrained('recruitment_candidates')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('recruitment_application_actions')->whereNull('actor_id')->exists()) {
            throw new RuntimeException('Cannot roll back candidate action history without losing its provenance.');
        }
        Schema::table('recruitment_application_actions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('candidate_actor_id');
            $t->unsignedBigInteger('actor_id')->nullable(false)->change();
        });
    }
};
