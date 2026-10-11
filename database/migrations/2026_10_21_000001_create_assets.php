<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->uuid('uuid')->unique();
            $t->string('identifier', 80); $t->string('name', 150); $t->string('category', 80);
            $t->string('serial_number', 150)->nullable(); $t->string('model', 150)->nullable();
            $t->string('purchase_reference', 150)->nullable(); $t->date('warranty_until')->nullable(); $t->string('location', 150)->nullable();
            $t->string('status', 25)->default('available'); $t->string('condition', 25)->default('good');
            $t->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $t->timestamp('acknowledged_at')->nullable(); $t->unsignedInteger('version')->default(0); $t->timestamps();
            $t->unique(['tenant_id', 'identifier']); $t->unique(['tenant_id', 'serial_number']); $t->index(['tenant_id', 'status']);
        });
        Schema::create('asset_movements', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->uuid('uuid')->unique();
            $t->foreignId('asset_id')->constrained('assets')->restrictOnDelete(); $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('from_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $t->foreignId('to_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $t->string('action', 25); $t->string('from_status', 25); $t->string('to_status', 25); $t->text('reason'); $t->timestamps();
            $t->index(['tenant_id', 'asset_id', 'id']);
        });
    }
    public function down(): void { Schema::dropIfExists('asset_movements'); Schema::dropIfExists('assets'); }
};
