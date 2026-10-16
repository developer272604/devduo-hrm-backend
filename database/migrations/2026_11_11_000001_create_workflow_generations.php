<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every generated draft is recorded so valid-generation and edit rates can be measured honestly before anything is advertised.
        Schema::create('workflow_generations', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->text('instruction'); $t->string('interpreter', 40);
            $t->json('result'); $t->unsignedSmallInteger('steps')->default(0); $t->unsignedSmallInteger('unresolved')->default(0);
            $t->string('outcome', 20)->default('generated'); // generated | drafted
            $t->string('definition_uuid', 36)->nullable(); $t->boolean('edited_before_save')->default(false);
            $t->timestamps();
            $t->index(['tenant_id', 'created_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_generations');
    }
};
