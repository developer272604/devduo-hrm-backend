<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-defined countries (no shipped country pack) need a display name. The base country_configurations
 * table only stored overrides of known packs, so a brand-new country could not be described by data alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('country_configurations', function (Blueprint $t) {
            $t->string('name', 120)->nullable()->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('country_configurations', function (Blueprint $t) {
            $t->dropColumn('name');
        });
    }
};
