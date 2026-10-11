<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experience_service_categories', function (Blueprint $t) {
            $t->unsignedBigInteger('default_assignee_id')->nullable()->index();
            $t->foreign('default_assignee_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('experience_service_categories', function (Blueprint $t) {
            $t->dropForeign(['default_assignee_id']);
            $t->dropColumn('default_assignee_id');
        });
    }
};
