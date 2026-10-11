<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Change requests reference the target record by its public uuid (target_id keeps the internal id when known). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_change_requests', function (Blueprint $t) {
            $t->string('target_uuid', 36)->nullable()->after('target_id');
        });
    }

    public function down(): void
    {
        Schema::table('profile_change_requests', function (Blueprint $t) {
            $t->dropColumn('target_uuid');
        });
    }
};
