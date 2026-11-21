<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_letter_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->uuid('uuid')->unique();
            $table->string('kind', 24);
            $table->unsignedInteger('version');
            $table->string('title', 150);
            $table->text('body');
            $table->timestamps();
            $table->unique(['tenant_id', 'kind', 'version']);
        });
        Schema::create('hr_issued_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('employee_id')->constrained();
            $table->foreignId('template_id')->constrained('hr_letter_templates');
            $table->foreignId('issued_by')->constrained('users');
            $table->uuid('uuid')->unique();
            $table->longText('encrypted_body');
            $table->timestamps();
            $table->index(['tenant_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_issued_letters');
        Schema::dropIfExists('hr_letter_templates');
    }
};
