<?php

use App\Domain\Access\WorkspaceProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('requester_id')->constrained('users');
            $table->foreignId('reviewer_id')->nullable()->constrained('users');
            $table->uuid('uuid')->unique();
            $table->uuid('request_key');
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->unsignedInteger('version')->default(1);
            $table->longText('encrypted_details');
            $table->longText('encrypted_outcome')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'requester_id', 'request_key']);
            $table->index(['tenant_id', 'status', 'id']);
        });
        $ids = WorkspaceProvisioner::syncCatalog();
        DB::table('roles')->where('name', 'Workspace administrator')->where('system', true)
            ->where('data_scope', 'tenant')->orderBy('id')->chunkById(100, function ($roles) use ($ids) {
                foreach ($roles as $role) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $ids['security.privacy.manage']]);
                }
            });
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('key', 'security.privacy.manage')->value('id');
        DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
        Schema::dropIfExists('privacy_requests');
    }
};
