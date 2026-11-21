<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_issued_letters', function (Blueprint $table) {
            $table->boolean('signature_requested')->default(false);
            $table->timestamp('signed_at')->nullable();
            $table->unsignedBigInteger('signed_by')->nullable();
            $table->text('encrypted_signer_name')->nullable();
            $table->char('signed_body_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hr_issued_letters', function (Blueprint $table) {
            $table->dropColumn(['signature_requested', 'signed_at', 'signed_by', 'encrypted_signer_name', 'signed_body_sha256']);
        });
    }
};
