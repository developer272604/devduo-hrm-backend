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
        Schema::create('experience_recognitions', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('sender_id'); $t->unsignedBigInteger('recipient_employee_id')->index();
            $t->text('message'); $t->json('value_tags'); $t->string('reward_reference', 100)->nullable();
            $t->string('status', 16)->default('pending'); $t->unsignedBigInteger('moderated_by')->nullable(); $t->dateTime('moderated_at')->nullable();
            $t->timestamps(); $t->index(['tenant_id', 'status', 'created_at']);
        });
        Schema::create('experience_recognition_preferences', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_id')->constrained()->restrictOnDelete(); $t->unsignedBigInteger('employee_id')->unique();
            $t->boolean('opted_out')->default(false); $t->timestamps();
        });

        $permissionId = WorkspaceProvisioner::syncCatalog()['experience.recognition.manage'];
        foreach (DB::table('roles')->get() as $role) {
            if (($role->system && $role->data_scope === 'tenant') || $role->name === 'HR administrator') {
                DB::table('permission_role')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_recognition_preferences');
        Schema::dropIfExists('experience_recognitions');
    }
};
