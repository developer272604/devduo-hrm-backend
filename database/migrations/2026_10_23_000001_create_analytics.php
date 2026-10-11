<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('uuid')->unique();
            $t->foreignId('owner_id')->constrained('users');
            $t->string('name', 150);
            $t->json('spec');
            $t->timestamps();
        });
        Schema::create('analytics_refresh_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('uuid')->unique();
            $t->foreignId('actor_id')->constrained('users');
            $t->json('spec');
            $t->json('result');
            $t->string('status');
            $t->timestamp('refreshed_at');
            $t->timestamps();
        });
        Schema::create('analytics_access_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action');
            $t->json('spec');
            $t->timestamps();
        });
        Schema::create('analytics_scenarios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('uuid')->unique();
            $t->foreignId('actor_id')->constrained('users');
            $t->json('assumptions');
            $t->json('result');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['analytics_scenarios', 'analytics_access_logs', 'analytics_refresh_runs', 'analytics_reports'] as $n) {
            Schema::dropIfExists($n);
        }
    }
};
