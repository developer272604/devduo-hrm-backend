<?php

namespace App\Domain\Security;

use App\Domain\Audit\Audit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\Rule;

final class SecuritySettingsService
{
    public function show(User $actor): array
    {
        return ['settings' => SecurityPolicy::for($actor->tenant_id), 'sso_adapter' => $this->ssoStatus()];
    }

    public function ssoStatus(): array
    {
        return ['live' => (new UnconfiguredSsoAdapter)->isLive(), 'supported_protocols' => ['oidc', 'saml'],
            'note' => 'SSO is NOT LIVE. Only the adapter interface and settings storage exist; there is no federated login route and no IdP was tested.'];
    }

    public function update(User $actor, array $input): array
    {
        $roleIds = Role::pluck('id')->all();
        $data = validator($input, [
            'mfa.enforcement' => 'sometimes|in:off,all,roles', 'mfa.role_ids' => 'sometimes|array|max:50', 'mfa.role_ids.*' => ['integer', Rule::in($roleIds)],
            'session.idle_minutes' => 'sometimes|integer|min:0|max:525600', 'session.absolute_minutes' => 'sometimes|integer|min:0|max:525600', 'session.max_concurrent' => 'sometimes|integer|min:0|max:20',
            'password.min_length' => 'sometimes|integer|min:8|max:64', 'password.history' => 'sometimes|integer|min:0|max:24', 'password.expiry_days' => 'sometimes|integer|min:0|max:730',
            'password.lockout_attempts' => 'sometimes|integer|min:0|max:50', 'password.lockout_minutes' => 'sometimes|integer|min:1|max:1440',
            'audit.retention_days' => 'sometimes|integer|min:365|max:36500',
            'sso.protocol' => 'sometimes|in:none,oidc,saml', 'sso.issuer' => 'nullable|url|max:255', 'sso.client_id' => 'nullable|string|max:255',
            'sso.metadata_url' => 'nullable|url|max:255', 'sso.domain_hints' => 'sometimes|array|max:20', 'sso.domain_hints.*' => 'string|max:120',
        ])->validate();
        if (($input['sso']['enabled'] ?? false) === true) {
            abort(422, 'SSO cannot be enabled: no identity provider adapter is live in this release.');
        }
        $before = SecurityPolicy::for($actor->tenant_id);
        $after = $before;
        foreach ($data as $section => $values) {
            foreach ($values as $key => $value) {
                $after[$section][$key] = $value;
            }
        }
        $after['sso']['enabled'] = false;
        if ($after['mfa']['enforcement'] === 'roles' && ! $after['mfa']['role_ids']) {
            abort(422, 'Choose at least one role for role-based MFA enforcement.');
        }
        SecurityPolicy::save($actor->tenant_id, $after, $actor->id);
        $changed = [];
        foreach ($after as $section => $values) {
            foreach ($values as $key => $value) {
                if (($before[$section][$key] ?? null) !== $value) {
                    $changed[$section.'.'.$key] = ['from' => $before[$section][$key] ?? null, 'to' => $value];
                }
            }
        }
        Audit::record('security.settings.updated', 'security', 'security_policy:'.$actor->tenant_id, ['changes' => $changed]);

        return $this->show($actor);
    }
}
