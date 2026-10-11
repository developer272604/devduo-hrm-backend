<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Device events whose device employee id has no mapping yet: kept so they can be replayed once the mapping exists. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_devices', function (Blueprint $t) {
            $t->timestamp('last_seen_at')->nullable();   // last authenticated call from the device (heartbeat/events)
            $t->string('last_seen_ip', 45)->nullable();
            $t->json('last_heartbeat')->nullable();      // firmware, queue depth, battery... as reported
            $t->timestamp('token_issued_at')->nullable();
        });
        Schema::create('device_unmapped_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('device_id')->constrained('attendance_devices')->restrictOnDelete();
            $t->foreignId('sync_run_id')->nullable()->constrained('device_sync_runs')->restrictOnDelete();
            $t->string('device_employee_id', 80);
            $t->dateTime('occurred_at');
            $t->string('direction', 8)->default('UNKNOWN');
            $t->string('external_ref', 120)->nullable();
            $t->json('raw')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'device_id', 'device_employee_id'], 'unmapped_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_unmapped_events');
        Schema::table('attendance_devices', function (Blueprint $t) {
            $t->dropColumn(['last_seen_at', 'last_seen_ip', 'last_heartbeat', 'token_issued_at']);
        });
    }
};
