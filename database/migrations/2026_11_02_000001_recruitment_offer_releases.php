<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('recruitment_offer_releases', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $t->uuid('uuid')->unique();
            $t->foreignId('offer_id')->constrained('recruitment_offers'); $t->unsignedInteger('version');
            $t->longText('content')->nullable(); $t->foreignId('released_by')->constrained('users'); $t->timestamps();
            $t->unique(['offer_id', 'version']);
        });
        Schema::table('recruitment_offers', fn (Blueprint $t) => $t->uuid('candidate_response_release')->nullable());
    }
    public function down(): void {
        Schema::table('recruitment_offers', fn (Blueprint $t) => $t->dropColumn('candidate_response_release'));
        Schema::dropIfExists('recruitment_offer_releases');
    }
};
