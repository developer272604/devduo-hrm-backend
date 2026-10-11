<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_views', function (Blueprint $t) {
            $t->uuid('uuid')->nullable();
        });
        foreach (DB::table('saved_views')->pluck('id') as $id) {
            DB::table('saved_views')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        }
        Schema::table('saved_views', function (Blueprint $t) {
            $t->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('saved_views', function (Blueprint $t) {
            $t->dropUnique(['uuid']);
            $t->dropColumn('uuid');
        });
    }
};
