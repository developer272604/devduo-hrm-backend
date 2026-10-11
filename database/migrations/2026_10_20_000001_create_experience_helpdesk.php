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
        Schema::create('experience_service_categories', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 40); $t->string('name', 120); $t->text('description')->nullable(); $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('target_hours')->nullable(); $t->timestamps(); $t->unique(['tenant_id', 'code']);
        });
        Schema::create('experience_tickets', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('employee_id')->index(); $t->unsignedBigInteger('category_id')->index(); $t->unsignedBigInteger('requester_id');
            $t->unsignedBigInteger('assignee_id')->nullable()->index(); $t->string('subject', 200); $t->text('description');
            $t->string('priority', 16)->default('normal'); $t->string('status', 24)->default('open'); $t->string('resolution', 1000)->nullable();
            $t->dateTime('sla_due_at')->nullable(); $t->dateTime('first_response_at')->nullable(); $t->dateTime('resolved_at')->nullable(); $t->dateTime('closed_at')->nullable(); $t->timestamps();
            $t->index(['tenant_id', 'status', 'created_at']);
        });
        Schema::create('experience_ticket_comments', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('ticket_id')->index(); $t->unsignedBigInteger('author_id'); $t->string('visibility', 12)->default('public'); $t->text('body'); $t->timestamps();
        });

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            foreach ([['PAYROLL', 'Payroll'], ['BENEFITS', 'Benefits'], ['LEAVE', 'Leave'], ['WORKPLACE', 'Workplace'], ['OTHER', 'Other']] as [$code, $name]) {
                DB::table('experience_service_categories')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_ticket_comments');
        Schema::dropIfExists('experience_tickets');
        Schema::dropIfExists('experience_service_categories');
    }
};
