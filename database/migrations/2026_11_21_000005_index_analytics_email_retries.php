<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_deliveries', function (Blueprint $table) {
            $table->index(['tenant_id', 'channel', 'status', 'last_attempt_at'], 'analytics_delivery_email_retry');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_deliveries', fn (Blueprint $table) => $table->dropIndex('analytics_delivery_email_retry'));
    }
};
