<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_issued_letters', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users');
            $table->char('acknowledged_body_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hr_issued_letters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn(['acknowledged_at', 'acknowledged_body_sha256']);
        });
    }
};
