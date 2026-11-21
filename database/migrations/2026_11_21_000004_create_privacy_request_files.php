<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_request_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('privacy_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('filename', 80);
            $table->longText('encrypted_contents');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_request_files');
    }
};
