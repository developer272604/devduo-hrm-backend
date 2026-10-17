<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A control is something the company must do on a schedule; evidence is proof it was done, accepted by someone other than the submitter.
        Schema::create('compliance_controls', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 40); $t->string('name', 200); $t->text('description')->nullable();
            $t->string('category', 12)->default('policy'); // statutory | policy | security | other
            $t->string('frequency', 12); // monthly | quarterly | half_yearly | annual | one_time
            $t->string('regulation_ref', 300)->nullable();
            $t->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $t->date('next_due_on')->nullable(); $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });
        Schema::create('compliance_evidence', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('control_id')->constrained('compliance_controls')->restrictOnDelete();
            $t->date('covers_due_on')->nullable(); $t->date('collected_on'); $t->string('title', 200); $t->string('reference', 500); $t->text('note')->nullable();
            $t->string('status', 10)->default('submitted'); // submitted | accepted | rejected
            $t->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('reviewed_at')->nullable(); $t->text('review_note')->nullable();
            $t->timestamps(); $t->index(['tenant_id', 'control_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_evidence');
        Schema::dropIfExists('compliance_controls');
    }
};
