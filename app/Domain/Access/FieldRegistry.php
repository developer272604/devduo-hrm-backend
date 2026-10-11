<?php

namespace App\Domain\Access;

/**
 * Catalogue of People fields: default classification, edit group and directory-privacy key.
 * Tenants may override classification and edit rules in settings.people.* (see PeopleSettings).
 * Key format: "<entity>.<field>".
 */
final class FieldRegistry
{
    /** field => [classification, group, privacy key (directory privacy toggle) or null] */
    public const FIELDS = [
        'person.display_name' => [Classification::PUBLIC_INTERNAL, 'personal', null],
        'person.preferred_name' => [Classification::PUBLIC_INTERNAL, 'personal', null],
        'person.legal_name' => [Classification::EMPLOYEE, 'personal', null],
        'person.legal_first_name' => [Classification::EMPLOYEE, 'personal', null],
        'person.legal_middle_name' => [Classification::EMPLOYEE, 'personal', null],
        'person.legal_last_name' => [Classification::EMPLOYEE, 'personal', null],
        'person.photo' => [Classification::PUBLIC_INTERNAL, 'personal', 'photo'],
        'person.pronouns' => [Classification::PUBLIC_INTERNAL, 'personal', 'pronouns'],
        'person.gender' => [Classification::EMPLOYEE, 'personal', null],
        'person.date_of_birth' => [Classification::EMPLOYEE, 'personal', 'date_of_birth'],
        'person.marital_status' => [Classification::EMPLOYEE, 'personal', null],
        'person.nationality' => [Classification::EMPLOYEE, 'personal', null],
        'person.citizenship' => [Classification::EMPLOYEE, 'personal', null],
        'person.blood_group' => [Classification::HR_CONFIDENTIAL, 'personal', null],
        'person.personal_email' => [Classification::EMPLOYEE, 'contact', null],
        'person.personal_phone' => [Classification::EMPLOYEE, 'contact', 'personal_phone'],
        'person.locale' => [Classification::EMPLOYEE, 'personal', null],
        'person.preferred_language' => [Classification::EMPLOYEE, 'personal', null],
        'employee.employee_number' => [Classification::PUBLIC_INTERNAL, 'employment', 'employee_number'],
        'employee.work_email' => [Classification::PUBLIC_INTERNAL, 'contact', 'work_email'],
        'employee.work_phone' => [Classification::PUBLIC_INTERNAL, 'contact', 'work_phone'],
        'employee.status' => [Classification::PUBLIC_INTERNAL, 'employment', null],
        'employee.worker_type' => [Classification::PUBLIC_INTERNAL, 'employment', null],
        'employee.joined_on' => [Classification::PUBLIC_INTERNAL, 'employment', null],
        'employee.original_joining_date' => [Classification::PUBLIC_INTERNAL, 'employment', null],
        'employee.probation' => [Classification::MANAGER_SENSITIVE, 'employment', null],
        'employee.contract' => [Classification::HR_CONFIDENTIAL, 'employment', null],
        'employee.notice_period_days' => [Classification::HR_CONFIDENTIAL, 'employment', null],
        'employee.retirement_date' => [Classification::HR_CONFIDENTIAL, 'employment', null],
        'employee.separated_on' => [Classification::HR_CONFIDENTIAL, 'employment', null],
        'employee.policy_references' => [Classification::HR_CONFIDENTIAL, 'employment', null],
        'job.department' => [Classification::PUBLIC_INTERNAL, 'job', 'department'],
        'job.designation' => [Classification::PUBLIC_INTERNAL, 'job', null],
        'job.location' => [Classification::PUBLIC_INTERNAL, 'job', 'location'],
        'job.manager' => [Classification::PUBLIC_INTERNAL, 'job', 'manager'],
        'job.team' => [Classification::PUBLIC_INTERNAL, 'job', null],
        'job.business_unit' => [Classification::PUBLIC_INTERNAL, 'job', null],
        'job.legal_entity' => [Classification::PUBLIC_INTERNAL, 'job', null],
        'job.work_mode' => [Classification::PUBLIC_INTERNAL, 'job', null],
        'job.employee_type' => [Classification::PUBLIC_INTERNAL, 'job', null],
        'job.position' => [Classification::HR_CONFIDENTIAL, 'job', null],
        'job.grade' => [Classification::HR_CONFIDENTIAL, 'job', null],
        'job.level' => [Classification::HR_CONFIDENTIAL, 'job', null],
        'job.cost_center' => [Classification::HR_CONFIDENTIAL, 'job', null],
        'job.employment_category' => [Classification::HR_CONFIDENTIAL, 'job', null],
        'contact.records' => [Classification::EMPLOYEE, 'contact', null],
        'address.records' => [Classification::EMPLOYEE, 'address', null],
        'emergency.records' => [Classification::EMPLOYEE, 'emergency', null],
        'dependent.records' => [Classification::EMPLOYEE, 'dependent', null],
        'education.records' => [Classification::PUBLIC_INTERNAL, 'education', null],
        'experience.records' => [Classification::PUBLIC_INTERNAL, 'experience', null],
        'experience.last_salary' => [Classification::HIGHLY_SENSITIVE, 'experience', null],
        'skill.records' => [Classification::PUBLIC_INTERNAL, 'skill', null],
        'certification.records' => [Classification::EMPLOYEE, 'certification', null],
        'document.records' => [Classification::HR_CONFIDENTIAL, 'document', null],
        'identity.records' => [Classification::HIGHLY_SENSITIVE, 'identity', null],
        'work_authorization.records' => [Classification::HIGHLY_SENSITIVE, 'identity', null],
        'bank.records' => [Classification::HIGHLY_SENSITIVE, 'bank', null],
        // Payroll / compensation (Phase 5): always highly sensitive, never editable through self-service, never in the directory.
        'compensation.base_pay' => [Classification::HIGHLY_SENSITIVE, 'compensation', null],
        'compensation.gross_reference' => [Classification::HIGHLY_SENSITIVE, 'compensation', null],
        'compensation.annual_compensation' => [Classification::HIGHLY_SENSITIVE, 'compensation', null],
        'compensation.hourly_rate' => [Classification::HIGHLY_SENSITIVE, 'compensation', null],
        'compensation.daily_rate' => [Classification::HIGHLY_SENSITIVE, 'compensation', null],
        'compensation.components' => [Classification::HIGHLY_SENSITIVE, 'compensation', null],
        'compensation.history' => [Classification::HIGHLY_SENSITIVE, 'compensation', null],
        'payroll.net_pay' => [Classification::HIGHLY_SENSITIVE, 'compensation', null],
        'payroll.result' => [Classification::HIGHLY_SENSITIVE, 'compensation', null],
        'payslip.snapshot' => [Classification::HIGHLY_SENSITIVE, 'compensation', null],
        'loan.records' => [Classification::HIGHLY_SENSITIVE, 'compensation', null],
        'advance.records' => [Classification::HIGHLY_SENSITIVE, 'compensation', null],
        'benefit.enrollment' => [Classification::HIGHLY_SENSITIVE, 'compensation', null],
        'tax_declaration.records' => [Classification::HIGHLY_SENSITIVE, 'compensation', null],
        'note.records' => [Classification::HR_CONFIDENTIAL, 'note', null],
    ];

    /** Default self-service edit rule per group: direct | request | none. Tenants override via settings. */
    public const SELF_EDIT_DEFAULTS = [
        'personal' => 'request', 'contact' => 'direct', 'address' => 'direct', 'emergency' => 'direct', 'dependent' => 'request',
        'education' => 'direct', 'experience' => 'direct', 'skill' => 'direct', 'certification' => 'direct', 'document' => 'request',
        'identity' => 'request', 'bank' => 'request', 'compensation' => 'none', 'employment' => 'none', 'job' => 'none', 'note' => 'none',
    ];

    /** Directory-privacy defaults (true = visible to coworkers who can see the employee). */
    public const PRIVACY_DEFAULTS = [
        'work_email' => true, 'work_phone' => true, 'personal_phone' => false, 'date_of_birth' => false, 'manager' => true, 'department' => true,
        'location' => true, 'photo' => true, 'employee_number' => true, 'pronouns' => true,
    ];

    public static function known(string $field): bool
    {
        return isset(self::FIELDS[$field]);
    }

    public static function group(string $field): string
    {
        return self::FIELDS[$field][1] ?? 'personal';
    }

    public static function privacyKey(string $field): ?string
    {
        return self::FIELDS[$field][2] ?? null;
    }
}
