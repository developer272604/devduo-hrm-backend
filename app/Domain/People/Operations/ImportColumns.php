<?php

namespace App\Domain\People\Operations;

use App\Domain\People\Services\NumberingService;
use App\Domain\Tenancy\TenantContext;
use App\Support\Spreadsheet\TabularReader;
use Illuminate\Support\Facades\DB;

/** Supported import columns, their aliases for auto-mapping, and active custom-field columns (cf:<key>). */
final class ImportColumns
{
    /** key => [label, group, example, aliases] */
    public const COLUMNS = [
        'employee_number' => ['Employee number', 'Employment', 'EMP-1001', ['employee_no', 'emp_no', 'emp_id', 'employee_id', 'empid', 'staff_id', 'staff_number', 'staff_no', 'employee_code', 'emp_code']],
        'first_name' => ['First name', 'Personal', 'Asha', ['firstname', 'first', 'given_name', 'forename', 'legal_first_name', 'fname']],
        'middle_name' => ['Middle name', 'Personal', '', ['middlename', 'legal_middle_name', 'middle']],
        'last_name' => ['Last name', 'Personal', 'Rao', ['lastname', 'surname', 'family_name', 'legal_last_name', 'lname', 'last']],
        'preferred_name' => ['Preferred name', 'Personal', '', ['nickname', 'known_as', 'preferred']],
        'gender' => ['Gender', 'Personal', 'female', ['sex']],
        'date_of_birth' => ['Date of birth', 'Personal', '1992-04-01', ['dob', 'birth_date', 'birthdate', 'born_on']],
        'personal_email' => ['Personal email', 'Contact', 'asha@example.com', ['personal_mail', 'private_email', 'email_personal', 'home_email']],
        'personal_phone' => ['Personal phone', 'Contact', '+91 98765 43210', ['mobile', 'mobile_number', 'personal_mobile', 'cell', 'personal_phone_number', 'phone_personal']],
        'work_email' => ['Work email', 'Contact', 'asha.rao@company.com', ['email', 'official_email', 'office_email', 'company_email', 'work_mail', 'email_address']],
        'work_phone' => ['Work phone', 'Contact', '', ['office_phone', 'work_phone_number']],
        'worker_type' => ['Worker type', 'Employment', 'employee', ['worker_kind']],
        'joined_on' => ['Joining date', 'Employment', '2026-01-05', ['date_of_joining', 'doj', 'joining_date', 'join_date', 'date_joined', 'start_date', 'hire_date', 'joined', 'date_of_hire']],
        'country' => ['Country (ISO 2)', 'Employment', 'IN', ['country_code']],
        'timezone' => ['Timezone', 'Employment', 'Asia/Kolkata', ['time_zone', 'tz']],
        'legal_entity' => ['Legal entity', 'Organization', '', ['company', 'entity', 'legal_entity_code', 'legal_entity_name']],
        'location' => ['Location', 'Organization', '', ['office', 'work_location', 'branch', 'site']],
        'department' => ['Department', 'Organization', '', ['dept', 'department_name', 'department_code']],
        'designation' => ['Designation', 'Organization', '', ['job_title', 'title', 'position', 'role']],
        'employee_type' => ['Employee type', 'Organization', '', ['employment_type', 'emp_type', 'staff_type', 'type_of_employment']],
        'business_unit' => ['Business unit', 'Organization', '', ['bu', 'division', 'business_unit_name']],
        'team' => ['Team', 'Organization', '', ['team_name', 'squad']],
        'cost_center' => ['Cost center', 'Organization', '', ['cost_centre', 'costcenter', 'cc']],
        'grade' => ['Grade', 'Organization', '', ['pay_grade']],
        'level' => ['Level', 'Organization', '', ['job_level']],
        'manager_employee_number' => ['Manager employee number', 'Organization', '', ['manager_id', 'manager_emp_no', 'manager_employee_id', 'reporting_manager_id', 'manager_code', 'reports_to', 'reporting_to', 'manager_number', 'manager_employee_no', 'supervisor_id', 'manager']],
        'probation_applicable' => ['Probation applicable', 'Employment', 'yes', ['on_probation', 'probation', 'has_probation']],
        'probation_end' => ['Probation end', 'Employment', '', ['probation_end_date', 'probation_ends_on', 'confirmation_due']],
        'contract_end' => ['Contract end', 'Employment', '', ['contract_end_date', 'contract_expiry', 'contract_ends_on']],
        'notice_period_days' => ['Notice period (days)', 'Employment', '', ['notice_period', 'notice_days', 'notice']],
    ];

    /** Organization columns → [table, assignment column, label]. */
    public const ORGANIZATION = ['legal_entity' => ['legal_entities', 'legal_entity_id', 'Legal entity'], 'location' => ['locations', 'location_id', 'Location'], 'department' => ['departments', 'department_id', 'Department'],
        'designation' => ['designations', 'designation_id', 'Designation'], 'employee_type' => ['employee_types', 'employee_type_id', 'Employee type'], 'business_unit' => ['business_units', 'business_unit_id', 'Business unit'],
        'team' => ['teams', 'team_id', 'Team'], 'cost_center' => ['cost_centers', 'cost_center_id', 'Cost center'], 'grade' => ['grades', 'grade_id', 'Grade'], 'level' => ['levels', 'level_id', 'Level']];

    /** @return array<int,object> active employee custom field definitions (key, label, type, required, default_value, validation, id) */
    public static function customFields(): array
    {
        return DB::table('custom_field_definitions')->where('tenant_id', app(TenantContext::class)->id)->where('entity', 'employee')->where('active', true)->orderBy('sort')->orderBy('id')
            ->get(['id', 'key', 'label', 'type', 'required', 'default_value', 'validation'])->all();
    }

    public static function required(): array
    {
        return array_values(array_filter(['first_name', 'joined_on', app(NumberingService::class)->isManual() ? 'employee_number' : null]));
    }

    /** @return array<int,array{key:string,label:string,group:string,required:bool,example:string,custom:bool}> */
    public static function catalogue(): array
    {
        $required = self::required();
        $out = [];
        foreach (self::COLUMNS as $key => [$label, $group, $example]) {
            $out[] = ['key' => $key, 'label' => $label, 'group' => $group, 'required' => in_array($key, $required, true), 'example' => $example, 'custom' => false];
        }
        foreach (self::customFields() as $field) {
            $out[] = ['key' => 'cf:'.$field->key, 'label' => $field->label, 'group' => 'Custom fields', 'required' => (bool) $field->required, 'example' => '', 'custom' => true];
        }

        return $out;
    }

    /**
     * Suggests target => source header from the file's headers.
     *
     * @param  array<int,string>  $headers
     * @return array<string,string>
     */
    public static function suggest(array $headers): array
    {
        $lookup = [];
        foreach (self::COLUMNS as $key => [$label, , , $aliases]) {
            foreach ([$key, TabularReader::normalizeHeader($label), ...$aliases] as $alias) {
                $lookup[$alias] ??= $key;
            }
        }
        foreach (self::customFields() as $field) {
            foreach (['cf:'.$field->key, TabularReader::normalizeHeader($field->label), TabularReader::normalizeHeader($field->key)] as $alias) {
                $lookup[$alias] ??= 'cf:'.$field->key;
            }
        }
        $mapping = [];
        foreach ($headers as $header) {
            $target = $lookup[TabularReader::normalizeHeader($header)] ?? null;
            if ($target && ! isset($mapping[$target])) {
                $mapping[$target] = $header;
            }
        }

        return $mapping;
    }

    public static function isKnown(string $target): bool
    {
        if (isset(self::COLUMNS[$target])) {
            return true;
        }

        return str_starts_with($target, 'cf:') && collect(self::customFields())->contains(fn ($f) => 'cf:'.$f->key === $target);
    }
}
