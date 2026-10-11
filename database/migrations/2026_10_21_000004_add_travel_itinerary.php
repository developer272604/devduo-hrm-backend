<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('travel_requests', function (Blueprint $t) { $t->json('itinerary')->nullable(); $t->unsignedInteger('itinerary_version')->default(0); }); }
    public function down(): void { Schema::table('travel_requests', fn (Blueprint $t) => $t->dropColumn(['itinerary', 'itinerary_version'])); }
};
