<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Insights workstream additions to the fixed Phase 2 schema:
 *  - data_quality_findings.classification  who may see a finding (Classification class of the data it describes)
 *  - data_quality_findings.handled_by / acknowledged_at   who last resolved/dismissed/acknowledged it
 *  - domain_events (tenant, person, occurred_at) index  person-level timeline entries without an employee
 *  - people_reminders   de-duplication + audit trail of HR "send reminder" notifications (24h window)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_quality_findings', function (Blueprint $t) {
            $t->string('classification', 30)->default('hr_confidential');
            $t->unsignedBigInteger('handled_by')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
        });
        Schema::table('domain_events', function (Blueprint $t) {
            $t->index(['tenant_id', 'person_id', 'occurred_at'], 'event_person_timeline');
        });
        Schema::create('people_reminders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->string('topic', 40);
            $t->foreignId('sent_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('notified_user_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'employee_id', 'topic', 'created_at'], 'people_reminder_recent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people_reminders');
        Schema::table('domain_events', function (Blueprint $t) {
            $t->dropIndex('event_person_timeline');
        });
        Schema::table('data_quality_findings', function (Blueprint $t) {
            $t->dropColumn(['classification', 'handled_by', 'acknowledged_at']);
        });
    }
};
