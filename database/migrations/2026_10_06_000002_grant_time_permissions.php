<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Domain\Access\WorkspaceProvisioner;

return new class extends Migration {
    public function up(): void {
        foreach(WorkspaceProvisioner::PERMISSIONS as $key=>[$group,$label]) {
            DB::table('permissions')->updateOrInsert(['key'=>$key],compact('group','label'));
        }
        $all=DB::table('permissions')->pluck('id');
        foreach(DB::table('roles')->where('system',true)->where('data_scope','tenant')->get() as $role) foreach($all as $id) DB::table('permission_role')->updateOrInsert(['permission_id'=>$id,'role_id'=>$role->id]);
        $self=DB::table('permissions')->whereIn('key',['people.view','attendance.view','leave.view'])->pluck('id');
        foreach(DB::table('roles')->where('name','Team member')->where('data_scope','self')->get() as $role) foreach($self as $id) DB::table('permission_role')->updateOrInsert(['permission_id'=>$id,'role_id'=>$role->id]);
    }
    public function down(): void { /* Access grants are preserved on rollback. */ }
};
