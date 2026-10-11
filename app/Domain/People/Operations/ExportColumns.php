<?php

namespace App\Domain\People\Operations;

use App\Domain\Access\Classification;
use App\Domain\Access\FieldAccess;
use App\Domain\Access\PeopleSettings;
use App\Models\User;

/**
 * Export column catalogue. Every column maps to a FieldRegistry key; what the viewer may export is decided by
 * classification + permission (people.export_sensitive for HR-confidential data) through FieldAccess::exportable().
 * Highly sensitive data (identity numbers, bank, salary) is never exportable in Phase 2 and is not in the catalogue.
 */
final class ExportColumns
{
    /** key => [label, FieldRegistry key, SQL expression, optional join alias] */
    public const COLUMNS = [
        'employee_number' => ['Employee number', 'employee.employee_number', 'e.employee_number'],
        'display_name' => ['Name', 'person.display_name', 'p.display_name'],
        'preferred_name' => ['Preferred name', 'person.preferred_name', 'p.preferred_name'],
        'legal_name' => ['Legal name', 'person.legal_name', 'p.legal_name'],
        'work_email' => ['Work email', 'employee.work_email', 'e.work_email'],
        'work_phone' => ['Work phone', 'employee.work_phone', 'e.work_phone'],
        'status' => ['Status', 'employee.status', 'e.status'],
        'worker_type' => ['Worker type', 'employee.worker_type', 'e.worker_type'],
        'joined_on' => ['Joining date', 'employee.joined_on', 'e.joined_on'],
        'original_joining_date' => ['Original joining date', 'employee.original_joining_date', 'e.original_joining_date'],
        'department' => ['Department', 'job.department', 'dep.name'],
        'designation' => ['Designation', 'job.designation', 'des.name'],
        'location' => ['Location', 'job.location', 'loc.name'],
        'country' => ['Country', 'job.location', 'e.country'],
        'timezone' => ['Timezone', 'job.location', 'e.timezone'],
        'employee_type' => ['Employee type', 'job.employee_type', 'et.name'],
        'manager' => ['Manager', 'job.manager', 'mp.display_name'],
        'manager_employee_number' => ['Manager employee number', 'job.manager', 'm.employee_number'],
        'team' => ['Team', 'job.team', 'tm.name', 'tm'],
        'business_unit' => ['Business unit', 'job.business_unit', 'bu.name', 'bu'],
        'legal_entity' => ['Legal entity', 'job.legal_entity', 'le.name', 'le'],
        'work_mode' => ['Work mode', 'job.work_mode', 'a.work_mode'],
        'position' => ['Position', 'job.position', 'pos.name', 'pos'],
        'grade' => ['Grade', 'job.grade', 'gr.name', 'gr'],
        'level' => ['Level', 'job.level', 'lv.name', 'lv'],
        'cost_center' => ['Cost center', 'job.cost_center', 'cc.name', 'cc'],
        'employment_category' => ['Employment category', 'job.employment_category', 'a.employment_category'],
        'gender' => ['Gender', 'person.gender', 'p.gender'],
        'date_of_birth' => ['Date of birth', 'person.date_of_birth', 'p.date_of_birth'],
        'personal_email' => ['Personal email', 'person.personal_email', 'p.personal_email'],
        'personal_phone' => ['Personal phone', 'person.personal_phone', 'p.personal_phone'],
        'nationality' => ['Nationality', 'person.nationality', 'p.nationality'],
        'marital_status' => ['Marital status', 'person.marital_status', 'p.marital_status'],
        'blood_group' => ['Blood group', 'person.blood_group', 'p.blood_group'],
        'probation_applicable' => ['Probation applicable', 'employee.probation', 'e.probation_applicable'],
        'probation_end' => ['Probation end', 'employee.probation', 'e.probation_end'],
        'confirmation_date' => ['Confirmation date', 'employee.probation', 'e.confirmation_date'],
        'contract_start' => ['Contract start', 'employee.contract', 'e.contract_start'],
        'contract_end' => ['Contract end', 'employee.contract', 'e.contract_end'],
        'notice_period_days' => ['Notice period (days)', 'employee.notice_period_days', 'e.notice_period_days'],
        'retirement_date' => ['Retirement date', 'employee.retirement_date', 'e.retirement_date'],
        'separated_on' => ['Last working date', 'employee.separated_on', 'e.separated_on'],
    ];

    public const DEFAULTS = ['employee_number', 'display_name', 'work_email', 'status', 'department', 'designation', 'location', 'manager', 'employee_type', 'joined_on'];

    /** Never exportable in Phase 2 (named so a request for them gets a precise message). */
    public const BLOCKED = ['identity' => 'Identity numbers', 'identity_number' => 'Identity numbers', 'bank' => 'Bank details', 'bank_account' => 'Bank details', 'salary' => 'Salary', 'last_salary' => 'Salary', 'work_authorization' => 'Work authorization numbers'];

    public const JOINS = ['tm' => ['teams', 'team_id'], 'bu' => ['business_units', 'business_unit_id'], 'le' => ['legal_entities', 'legal_entity_id'], 'pos' => ['positions', 'position_id'],
        'gr' => ['grades', 'grade_id'], 'lv' => ['levels', 'level_id'], 'cc' => ['cost_centers', 'cost_center_id']];

    /** @return string|null why the viewer cannot export the column, or null when allowed */
    public static function denial(User $user, string $key): ?string
    {
        if (isset(self::BLOCKED[$key])) {
            return self::BLOCKED[$key].' can never be exported.';
        }
        if (! isset(self::COLUMNS[$key])) {
            return 'Unknown column "'.$key.'".';
        }
        [$label, $field] = self::COLUMNS[$key];
        $class = PeopleSettings::classification($field);
        if ($class === Classification::HIGHLY_SENSITIVE || $class === Classification::SYSTEM_RESTRICTED) {
            return $label.' is highly sensitive and can never be exported.';
        }
        if (in_array($class, Classification::EXPORT_SENSITIVE, true) && ! $user->allows('people.export_sensitive')) {
            return $label.' is confidential and needs the export-sensitive permission.';
        }
        if (! app(FieldAccess::class)->exportable($user, $field, null)) {
            return 'You do not have access to export '.strtolower($label).'.';
        }

        return null;
    }

    public static function isSensitive(string $key): bool
    {
        return in_array(PeopleSettings::classification(self::COLUMNS[$key][1] ?? ''), Classification::EXPORT_SENSITIVE, true);
    }

    /** Columns offered to the viewer. */
    public static function available(User $user): array
    {
        $out = [];
        foreach (self::COLUMNS as $key => [$label, $field]) {
            if (self::denial($user, $key) === null) {
                $out[] = ['key' => $key, 'label' => $label, 'field' => $field, 'classification' => PeopleSettings::classification($field), 'sensitive' => self::isSensitive($key), 'default' => in_array($key, self::DEFAULTS, true)];
            }
        }

        return $out;
    }
}
