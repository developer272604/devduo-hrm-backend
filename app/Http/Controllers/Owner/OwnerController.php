<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Audit\Audit;
use App\Domain\Tenancy\AccountLifecycle;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** HRMS owner panel: every company on the platform, its trial/approval state, subscription and access. */
class OwnerController extends Controller
{
    private function companies()
    {
        return Tenant::where('is_platform', false);
    }

    private function company(int $id): Tenant
    {
        return $this->companies()->findOrFail($id);
    }

    public function overview(AccountLifecycle $lifecycle)
    {
        $lifecycle->expireDue();
        $by = $this->companies()->select('status', 'status_reason', DB::raw('count(*) as n'))->groupBy('status', 'status_reason')->get();
        $count = fn (callable $f) => (int) $by->filter($f)->sum('n');

        return response()->json([
            'counts' => [
                'total' => $count(fn () => true),
                'trial' => $count(fn ($r) => $r->status === 'trial'),
                'awaiting_approval' => $count(fn ($r) => $r->status === 'suspended' && $r->status_reason === 'trial_expired'),
                'active' => $count(fn ($r) => $r->status === 'active'),
                'suspended' => $count(fn ($r) => $r->status === 'suspended' && $r->status_reason !== 'trial_expired'),
                'rejected' => $count(fn ($r) => $r->status === 'rejected'),
                'deactivated' => $count(fn ($r) => $r->status === 'deactivated'),
            ],
            'users' => User::where('is_owner', false)->count(),
            'new_last_7_days' => $this->companies()->where('created_at', '>=', now()->subDays(7))->count(),
            'system' => ['laravel' => app()->version(), 'php' => PHP_VERSION, 'environment' => app()->environment(), 'database' => DB::connection()->getDriverName(), 'time' => now()->toIso8601String(), 'trial_hours' => AccountLifecycle::TRIAL_HOURS],
            'activity' => DB::table('audit_logs')->join('tenants', 'tenants.id', '=', 'audit_logs.tenant_id')
                ->orderByDesc('audit_logs.id')->limit(30)->get(['audit_logs.id', 'audit_logs.actor', 'audit_logs.action', 'audit_logs.module', 'audit_logs.resource', 'audit_logs.created_at', 'tenants.name as company']),
        ]);
    }

    public function index(Request $r, AccountLifecycle $lifecycle)
    {
        $data = $r->validate(['status' => 'nullable|in:trial,awaiting_approval,active,suspended,rejected,deactivated', 'search' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|min:1|max:100']);
        $lifecycle->expireDue();
        $q = $this->companies()->withCount('users');
        match ($data['status'] ?? null) {
            'awaiting_approval' => $q->where('status', 'suspended')->where('status_reason', 'trial_expired'),
            'suspended' => $q->where('status', 'suspended')->where(fn ($w) => $w->whereNull('status_reason')->orWhere('status_reason', '!=', 'trial_expired')),
            null => null,
            default => $q->where('status', $data['status']),
        };
        if (! empty($data['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $data['search']).'%';
            $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('slug', 'like', $term));
        }

        return response()->json($q->latest('id')->paginate($data['per_page'] ?? 25)->through(fn ($t) => $this->present($t)));
    }

    public function show(int $id, AccountLifecycle $lifecycle)
    {
        $t = $this->company($id);
        $lifecycle->refresh($t);
        $owner = app(TenantContext::class)->id;
        app(TenantContext::class)->id = $t->id;
        try {
            $users = User::where('tenant_id', $t->id)->get(['id', 'name', 'email', 'active', 'created_at'])->map(fn ($u) => $u->toArray() + ['roles' => $u->roles()->get(['roles.id', 'name'])]);
            $roles = Role::get(['id', 'name']);
        } finally {
            app(TenantContext::class)->id = $owner;
        }

        return response()->json($this->present($t) + ['users' => $users, 'roles' => $roles, 'available_modules' => WorkspaceProvisioner::MODULES, 'modules' => $t->settings['modules'] ?? []]);
    }

    public function transition(Request $r, int $id, string $action, AccountLifecycle $lifecycle)
    {
        abort_unless(isset(AccountLifecycle::ACTIONS[$action]), 404);
        $data = $r->validate(['note' => 'nullable|string|max:500', 'plan' => 'nullable|string|max:40', 'subscription_ends_at' => 'nullable|date|after:now']);
        $t = $lifecycle->transition($this->company($id), $action, $r->user()->id, $data['note'] ?? null, array_intersect_key($data, array_flip(['plan', 'subscription_ends_at'])));
        Audit::record('company_'.$action, 'owner', 'tenant:'.$t->id, ['company' => $t->name, 'note' => $data['note'] ?? null]);

        return response()->json($this->present($t));
    }

    public function extendTrial(Request $r, int $id)
    {
        $data = $r->validate(['hours' => 'required|integer|min:1|max:720']);
        $t = $this->company($id);
        $base = $t->status === 'trial' && $t->trial_ends_at?->isFuture() ? $t->trial_ends_at : now();
        $t->forceFill(['status' => 'trial', 'status_reason' => null, 'status_note' => null, 'plan' => 'trial', 'trial_started_at' => $t->trial_started_at ?? now(), 'trial_ends_at' => $base->copy()->addHours($data['hours']), 'status_changed_at' => now()])->save();
        Audit::record('trial_extended', 'owner', 'tenant:'.$t->id, ['company' => $t->name, 'hours' => $data['hours']]);

        return response()->json($this->present($t));
    }

    /** Subscription plan, end date and the modules (feature access) the company may use. */
    public function subscription(Request $r, int $id)
    {
        $data = $r->validate(['plan' => 'nullable|string|max:40', 'subscription_ends_at' => 'nullable|date', 'modules' => 'nullable|array', 'modules.*' => ['string', Rule::in(WorkspaceProvisioner::MODULES)]]);
        $t = $this->company($id);
        $attrs = array_intersect_key($data, array_flip(['plan', 'subscription_ends_at']));
        if (isset($data['modules'])) {
            $attrs['settings'] = array_merge($t->settings ?? [], ['modules' => array_values(array_unique(array_merge(['work', 'notifications'], $data['modules'])))]);
        }
        $t->forceFill($attrs)->save();
        Audit::record('subscription_updated', 'owner', 'tenant:'.$t->id, ['company' => $t->name] + array_intersect_key($data, array_flip(['plan', 'subscription_ends_at', 'modules'])));

        return response()->json($this->present($t));
    }

    public function setUserActive(Request $r, int $id)
    {
        $data = $r->validate(['active' => 'required|boolean']);
        $u = User::where('is_owner', false)->findOrFail($id);
        $u->forceFill(['active' => $data['active']] + ($data['active'] ? [] : ['session_version' => $u->session_version + 1]))->save();
        Audit::record($data['active'] ? 'user_activated' : 'user_deactivated', 'owner', 'user:'.$u->id, ['email' => $u->email]);

        return response()->json(['id' => $u->id, 'active' => $u->active]);
    }

    /** Replaces a company user's roles with a single role of that company (role/permission management across tenants). */
    public function setUserRole(Request $r, int $id)
    {
        $data = $r->validate(['role_id' => 'required|integer']);
        $u = User::where('is_owner', false)->findOrFail($id);
        $own = app(TenantContext::class)->id;
        app(TenantContext::class)->id = $u->tenant_id;
        try {
            $role = Role::findOrFail($data['role_id']);
            $u->roles()->sync([$role->id]);
        } finally {
            app(TenantContext::class)->id = $own;
        }
        Audit::record('user_role_changed', 'owner', 'user:'.$u->id, ['email' => $u->email, 'role' => $role->name]);

        return response()->json(['id' => $u->id, 'role' => $role->name]);
    }

    private function present(Tenant $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'status' => $t->status, 'status_reason' => $t->status_reason, 'status_note' => $t->status_note,
            'awaiting_approval' => $t->status === 'suspended' && $t->status_reason === 'trial_expired', 'plan' => $t->plan, 'trial_started_at' => $t->trial_started_at, 'trial_ends_at' => $t->trial_ends_at,
            'approved_at' => $t->approved_at, 'subscription_ends_at' => $t->subscription_ends_at, 'created_at' => $t->created_at, 'status_changed_at' => $t->status_changed_at,
            'admin_email' => $t->settings['hr_email'] ?? null, 'users_count' => $t->users_count ?? User::where('tenant_id', $t->id)->count()];
    }
}
