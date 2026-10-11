<?php

namespace App\Domain\People\Config;

use App\Domain\Audit\Audit;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\UserDataScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Explicit per-user data-scope grants (user_data_scopes), read by App\Domain\Access\DataScope on top of the role scopes.
 * A grant of kind department|business_unit|location|legal_entity|employee_group widens the user's reach to everyone in
 * that organization unit / group; kind "employee" grants a single employee (given by public uuid in the API).
 */
final class DataScopeGrants
{
    /** kind => [table, human label] */
    public const KINDS = [
        'department' => ['departments', 'Department'], 'business_unit' => ['business_units', 'Business unit'], 'location' => ['locations', 'Location'],
        'legal_entity' => ['legal_entities', 'Legal entity'], 'employee_group' => ['employee_groups', 'Employee group'], 'employee' => ['employees', 'Employee'],
    ];

    private function tenant(): int
    {
        return app(TenantContext::class)->id;
    }

    public function catalogue(): array
    {
        return ['scopes' => PeopleSettingsService::scopeCatalogue(), 'grant_kinds' => array_map(fn ($k, $v) => ['kind' => $k, 'label' => $v[1], 'reference' => $k === 'employee' ? 'employee uuid' : 'id'], array_keys(self::KINDS), self::KINDS)];
    }

    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = UserDataScope::query()->orderByDesc('id');
        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }
        $page = $query->paginate($perPage);
        $labels = $this->labels($page->getCollection());
        $users = User::query()->where('tenant_id', $this->tenant())->whereIn('id', $page->getCollection()->pluck('user_id')->unique())->get(['id', 'name', 'email'])->keyBy('id');

        return $page->through(fn (UserDataScope $grant) => $this->present($grant, $users[$grant->user_id] ?? null, $labels));
    }

    /** @param array<string,array{reference:string,label:?string}> $labels */
    private function present(UserDataScope $grant, ?User $user, array $labels): array
    {
        $info = $labels[$grant->id] ?? ['reference' => (string) $grant->reference_id, 'label' => null];

        return ['id' => $grant->id, 'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : ['id' => $grant->user_id], 'kind' => $grant->kind,
            'reference_id' => $info['reference'], 'reference_label' => $info['label'], 'created_at' => $grant->created_at?->toIso8601String()];
    }

    /** @return array<int,array{reference:string,label:?string}> grant id => public reference and display label */
    private function labels($grants): array
    {
        $out = [];
        foreach ($grants->groupBy('kind') as $kind => $items) {
            $ids = $items->pluck('reference_id')->unique();
            if ($kind === 'employee') {
                $rows = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.tenant_id', $this->tenant())->whereIn('employees.id', $ids)
                    ->get(['employees.id', 'employees.uuid', 'people.display_name'])->keyBy('id');
                foreach ($items as $grant) {
                    $out[$grant->id] = ['reference' => $rows[$grant->reference_id]->uuid ?? (string) $grant->reference_id, 'label' => $rows[$grant->reference_id]->display_name ?? null];
                }
            } elseif (isset(self::KINDS[$kind])) {
                $rows = DB::table(self::KINDS[$kind][0])->where('tenant_id', $this->tenant())->whereIn('id', $ids)->pluck('name', 'id');
                foreach ($items as $grant) {
                    $out[$grant->id] = ['reference' => (string) $grant->reference_id, 'label' => $rows[$grant->reference_id] ?? null];
                }
            }
        }

        return $out;
    }

    /**
     * Grants one or more references to a user. Idempotent: an existing grant is returned, not duplicated.
     *
     * @return array{grants:array<int,array>,created:int}
     */
    public function create(array $input): array
    {
        $data = Validator::make($input, [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('tenant_id', $this->tenant())], 'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'reference_id' => ['required_without:reference_ids', 'nullable'], 'reference_ids' => ['required_without:reference_id', 'array', 'min:1', 'max:100'],
        ], ['user_id.exists' => 'Choose a user from this company.', 'kind.in' => 'Choose a grant type: '.implode(', ', array_keys(self::KINDS)).'.',
            'reference_id.required_without' => 'Choose what to grant access to.', 'reference_ids.required_without' => 'Choose what to grant access to.'])->validate();
        $kind = $data['kind'];
        $references = array_values(array_unique(array_map('strval', $data['reference_ids'] ?? [$data['reference_id']])));
        $resolved = [];
        foreach ($references as $reference) {
            $resolved[] = $this->resolve($kind, $reference);
        }

        return DB::transaction(function () use ($data, $kind, $resolved) {
            $created = 0;
            $grants = [];
            foreach ($resolved as [$id, $label, $public]) {
                $grant = UserDataScope::query()->where('user_id', $data['user_id'])->where('kind', $kind)->where('reference_id', $id)->first();
                if (! $grant) {
                    $grant = UserDataScope::create(['user_id' => $data['user_id'], 'kind' => $kind, 'reference_id' => $id]);
                    $created++;
                    Audit::record('data_scope_granted', 'access', 'user:'.$data['user_id'], ['kind' => $kind, 'reference' => $public, 'label' => $label]);
                }
                $grants[] = $grant;
            }
            $user = User::query()->where('tenant_id', $this->tenant())->find($data['user_id']);
            $labels = $this->labels(collect($grants));

            return ['grants' => array_map(fn ($g) => $this->present($g, $user, $labels), $grants), 'created' => $created];
        });
    }

    /** @return array{0:int,1:?string,2:string} [internal id, label, public reference] */
    private function resolve(string $kind, string $reference): array
    {
        $field = $kind === 'employee' ? 'reference_id' : 'reference_id';
        if ($kind === 'employee') {
            $employee = preg_match('/^[0-9a-fA-F-]{36}$/', $reference) ? Employee::query()->where('uuid', strtolower($reference))->with('person')->first() : null;
            $employee || throw ValidationException::withMessages([$field => ['Choose an employee from this company (use the employee uuid).']]);

            return [$employee->id, $employee->person?->display_name, $employee->uuid];
        }
        $row = ctype_digit($reference) ? DB::table(self::KINDS[$kind][0])->where('tenant_id', $this->tenant())->where('id', (int) $reference)->first() : null;
        $row || throw ValidationException::withMessages([$field => ['Choose a '.strtolower(self::KINDS[$kind][1]).' from this company.']]);

        return [(int) $row->id, $row->name ?? null, (string) $row->id];
    }

    public function delete(UserDataScope $grant): void
    {
        $info = $this->labels(collect([$grant]))[$grant->id] ?? ['reference' => (string) $grant->reference_id, 'label' => null];
        DB::transaction(function () use ($grant, $info) {
            $grant->delete();
            Audit::record('data_scope_revoked', 'access', 'user:'.$grant->user_id, ['kind' => $grant->kind, 'reference' => $info['reference'], 'label' => $info['label']]);
        });
    }
}
