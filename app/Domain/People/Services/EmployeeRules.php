<?php

namespace App\Domain\People\Services;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

/** Reusable validation rules shared by the wizard, quick add, import and bulk operations. */
final class EmployeeRules
{
    public const WORKER_TYPES = ['employee', 'contractor', 'intern', 'consultant', 'temporary'];

    public const GENDERS = ['female', 'male', 'non_binary', 'other', 'undisclosed'];

    public static function person(bool $required = true): array
    {
        $r = $required ? 'required' : 'sometimes';

        return [
            'legal_first_name' => [$r, 'string', 'max:120'], 'legal_middle_name' => ['nullable', 'string', 'max:120'], 'legal_last_name' => ['nullable', 'string', 'max:120'],
            'preferred_name' => ['nullable', 'string', 'max:120'], 'display_name' => ['nullable', 'string', 'max:240'],
            'gender' => ['nullable', Rule::in(self::GENDERS)], 'pronouns' => ['nullable', 'string', 'max:40'], 'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'marital_status' => ['nullable', 'string', 'max:30'], 'nationality' => ['nullable', 'string', 'size:2'], 'citizenship' => ['nullable', 'string', 'size:2'],
            'blood_group' => ['nullable', 'string', 'max:6'], 'personal_email' => ['nullable', 'email:rfc', 'max:255'], 'personal_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-.\s]{5,40}$/'],
            'country' => ['nullable', 'string', 'size:2'], 'timezone' => ['nullable', 'timezone'], 'locale' => ['nullable', 'string', 'max:20'],
            'working_language' => ['nullable', 'string', 'max:10'], 'preferred_language' => ['nullable', 'string', 'max:10'],
        ];
    }

    public static function employment(bool $required = true): array
    {
        $r = $required ? 'required' : 'sometimes';

        return [
            'employee_number' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._\/\-]+$/'],
            'worker_type' => [$r, Rule::in(self::WORKER_TYPES)], 'joined_on' => [$r, 'date_format:Y-m-d', 'after:1950-01-01'],
            'country' => ['nullable', 'string', 'size:2'], 'timezone' => ['nullable', 'timezone'],
            'work_email' => ['nullable', 'email:rfc', 'max:255'], 'work_phone' => ['nullable', 'string', 'max:40'],
            'probation_applicable' => ['sometimes', 'boolean'], 'probation_start' => ['nullable', 'date_format:Y-m-d'], 'probation_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:probation_start'],
            'contract_start' => ['nullable', 'date_format:Y-m-d'], 'contract_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:contract_start'],
            'notice_period_days' => ['nullable', 'integer', 'between:0,365'], 'retirement_date' => ['nullable', 'date_format:Y-m-d'], 'status' => ['nullable', Rule::in(['preboarding', 'active', 'probation'])],
        ];
    }

    /** Organization references must exist (and be active) in the current tenant. */
    public static function assignment(): array
    {
        $tenant = app(TenantContext::class)->id;
        $rules = [];
        foreach (['legal_entity_id' => 'legal_entities', 'business_unit_id' => 'business_units', 'department_id' => 'departments', 'team_id' => 'teams', 'cost_center_id' => 'cost_centers',
            'designation_id' => 'designations', 'position_id' => 'positions', 'grade_id' => 'grades', 'level_id' => 'levels', 'employee_type_id' => 'employee_types', 'location_id' => 'locations'] as $column => $table) {
            $rules[$column] = ['nullable', 'integer', Rule::exists($table, 'id')->where('tenant_id', $tenant)->where('active', true)];
        }
        $rules['employment_category'] = ['nullable', 'string', 'max:60'];
        $rules['work_mode'] = ['nullable', Rule::in(['onsite', 'remote', 'hybrid'])];

        return $rules;
    }

    /** Prefix every rule key (for nested payloads: person.*, employment.*, assignment.*). */
    public static function prefixed(string $prefix, array $rules): array
    {
        $out = [];
        foreach ($rules as $key => $rule) {
            $out[$prefix.'.'.$key] = $rule;
        }

        return $out;
    }
}
