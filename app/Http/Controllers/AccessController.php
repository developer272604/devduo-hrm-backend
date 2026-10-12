<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Audit;
use App\Domain\Security\PasswordPolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccessController extends Controller
{
    public function users(Request $r)
    {
        $q = User::where('tenant_id', $r->user()->tenant_id)->with('roles');
        if ($r->filled('search')) {
            $q->where(fn ($q) => $q->where('name', 'like', '%'.substr($r->search, 0, 100).'%')->orWhere('email', 'like', '%'.substr($r->search, 0, 100).'%'));
        }

        return $q->orderBy('name')->paginate(20);
    }

    public function saveUser(Request $r, ?int $id = null)
    {
        $u = $id ? User::where('tenant_id', $r->user()->tenant_id)->findOrFail($id) : new User;
        $data = $r->validate(['name' => 'required|string|max:150', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($id)], 'active' => 'required|boolean', 'password' => $id ? ['prohibited'] : ['required', app(PasswordPolicy::class)->rule($r->user()->tenant_id)],
            'role_ids' => 'required|array|min:1', 'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where('tenant_id', $r->user()->tenant_id)]]);

        return DB::transaction(function () use ($r, $u, $data, $id) {
            Tenant::whereKey($r->user()->tenant_id)->lockForUpdate()->first();
            $admin = Role::where('system', true)->firstOrFail();
            $losesAdmin = $id && $u->roles()->whereKey($admin->id)->exists() && (! $data['active'] || ! in_array($admin->id, $data['role_ids']));
            if ($losesAdmin) {
                abort_unless(User::where('tenant_id', $r->user()->tenant_id)->where('active', true)->where('id', '!=', $id)->whereHas('roles', fn ($q) => $q->where('roles.id', $admin->id))->exists(), 422, 'Keep at least one active workspace administrator.');
            }
            abort_if($id === $r->user()->id && ! $data['active'], 422, 'You cannot deactivate your own account.');
            $u->fill(collect($data)->except('role_ids')->all());
            $u->email = strtolower($u->email);
            $u->tenant_id = $r->user()->tenant_id;
            if (! $id) {
                $u->password_changed_at = now();
            }
            $u->save();
            $u->roles()->sync($data['role_ids']);
            Audit::record($id ? 'updated' : 'created', 'access', 'user:'.$u->id);

            return response()->json($u->load('roles'), $id ? 200 : 201);
        });
    }

    public function roles()
    {
        return Role::with('permissions')->orderBy('name')->get();
    }

    public function permissions()
    {
        return Permission::orderBy('group')->get();
    }

    public function saveRole(Request $r, ?int $id = null)
    {
        $role = $id ? Role::findOrFail($id) : new Role;
        abort_if($role->system, 422, 'The workspace administrator role is protected.');
        $data = $r->validate(['name' => ['required', 'string', 'max:100', Rule::unique('roles')->where('tenant_id', $r->user()->tenant_id)->ignore($id)], 'description' => 'nullable|string|max:255', 'data_scope' => 'required|in:self,team,direct_reports,all_subordinates,department,business_unit,location,legal_entity,custom_group,explicit,tenant', 'permission_ids' => 'present|array', 'permission_ids.*' => 'integer|exists:permissions,id']);

        return DB::transaction(function () use ($role, $data, $id) {
            $role->fill(collect($data)->except('permission_ids')->all())->save();
            $role->permissions()->sync($data['permission_ids']);
            Audit::record($id ? 'updated' : 'created', 'access', 'role:'.$role->id);

            return response()->json($role->load('permissions'), $id ? 200 : 201);
        });
    }

    public function deleteRole(int $id)
    {
        $role = Role::findOrFail($id);
        abort_if($role->system, 422, 'The workspace administrator role is protected.');
        abort_if(DB::table('role_user')->where('role_id', $id)->exists(), 422, 'Reassign users before deleting this role.');
        DB::transaction(function () use ($role, $id) {
            $role->delete();
            Audit::record('deleted','access','role:'.$id);
        });

        return response()->noContent();
    }
}
