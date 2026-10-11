<?php

namespace App\Domain\Leave\Policies;

use App\Domain\Attendance\Scheduling\Specificity;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Validates and labels polymorphic targets (employee, department, country ...) with the attendance vocabulary and specificity. */
final class Targets
{
    private const ORG_TABLES = ['team' => 'teams', 'department' => 'departments', 'business_unit' => 'business_units', 'location' => 'locations',
        'employee_type' => 'employee_types', 'legal_entity' => 'legal_entities', 'employee_group' => 'employee_groups'];

    /** @return array{0:string,1:string} [target_type, target_id] */
    public static function normalize(?string $type, mixed $rawId, string $field = 'target_id'): array
    {
        if (! in_array($type, Specificity::types(), true)) {
            throw ValidationException::withMessages(['target_type' => 'Choose who this applies to: '.implode(', ', Specificity::types()).'.']);
        }
        $tenant = app(TenantContext::class)->id;
        $id = (string) ($rawId ?? '*');
        if ($type === 'tenant') {
            return [$type, '*'];
        }
        if ($type === 'country') {
            $id = strtoupper($id);
            if (! preg_match('/^[A-Z]{2}$/', $id)) {
                throw ValidationException::withMessages([$field => 'Use a two-letter country code.']);
            }

            return [$type, $id];
        }
        if ($type === 'employee') {
            if (! ctype_digit($id)) {
                $id = (string) (DB::table('employees')->where('tenant_id', $tenant)->where('uuid', $id)->value('id') ?? throw ValidationException::withMessages([$field => 'We could not find that employee.']));
            }
            if (! DB::table('employees')->where('tenant_id', $tenant)->where('id', $id)->exists()) {
                throw ValidationException::withMessages([$field => 'We could not find that employee.']);
            }

            return [$type, $id];
        }
        if (! DB::table(self::ORG_TABLES[$type])->where('tenant_id', $tenant)->where('id', $id)->exists()) {
            throw ValidationException::withMessages([$field => 'We could not find that '.Specificity::label($type).'.']);
        }

        return [$type, $id];
    }

    public static function label(string $type, string $id): string
    {
        $tenant = app(TenantContext::class)->id;
        if ($type === 'tenant') {
            return 'the whole company';
        }
        if ($type === 'country') {
            return 'country '.$id;
        }
        if ($type === 'employee') {
            $name = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.tenant_id', $tenant)->where('employees.id', $id)->value('people.display_name');

            return $name ?? 'employee '.$id;
        }
        $table = self::ORG_TABLES[$type] ?? null;
        $name = $table ? DB::table($table)->where('tenant_id', $tenant)->where('id', $id)->value('name') : null;

        return ($name ?? '#'.$id).' ('.Specificity::label($type).')';
    }
}
