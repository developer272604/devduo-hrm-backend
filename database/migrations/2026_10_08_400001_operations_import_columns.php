<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_imports', function (Blueprint $t) {
            $t->json('headers')->nullable();
            $t->unsignedInteger('skipped_rows')->default(0);
            $t->unsignedInteger('processed_rows')->default(0);
            $t->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employee_imports', function (Blueprint $t) {
            $t->dropColumn(['headers', 'skipped_rows', 'processed_rows', 'error']);
        });
    }
};
