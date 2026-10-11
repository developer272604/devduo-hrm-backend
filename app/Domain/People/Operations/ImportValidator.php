<?php

namespace App\Domain\People\Operations;

use App\Domain\Access\PeopleSettings;
use App\Domain\People\Models\EmployeeImport;
use App\Domain\People\Services\EmployeeRules;
use App\Domain\People\Services\NumberingService;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Validates staged import rows without writing anything to employee tables.
 * Row status: valid | warning | error. Errors block a row; warnings (possible duplicates, future joiners) do not.
 */
final class ImportValidator
{
    public const DATE_FORMATS = ['Y-m-d' => 'YYYY-MM-DD', 'd/m/Y' => 'DD/MM/YYYY', 'm/d/Y' => 'MM/DD/YYYY'];

    private const GENDERS = ['m' => 'male', 'male' => 'male', 'man' => 'male', 'f' => 'female', 'female' => 'female', 'woman' => 'female', 'non_binary' => 'non_binary', 'non-binary' => 'non_binary',
        'nonbinary' => 'non_binary', 'other' => 'other', 'undisclosed' => 'undisclosed', 'prefer_not_to_say' => 'undisclosed', 'not_specified' => 'undisclosed'];

    private const FIELD_OF = ['person.legal_first_name' => 'first_name', 'person.legal_middle_name' => 'middle_name', 'person.legal_last_name' => 'last_name', 'person.preferred_name' => 'preferred_name',
        'person.gender' => 'gender', 'person.date_of_birth' => 'date_of_birth', 'person.personal_email' => 'personal_email', 'person.personal_phone' => 'personal_phone', 'person.country' => 'country',
        'employment.employee_number' => 'employee_number', 'employment.worker_type' => 'worker_type', 'employment.joined_on' => 'joined_on', 'employment.country' => 'country', 'employment.timezone' => 'timezone',
        'employment.work_email' => 'work_email', 'employment.work_phone' => 'work_phone', 'employment.probation_end' => 'probation_end', 'employment.contract_end' => 'contract_end', 'employment.notice_period_days' => 'notice_period_days'];

    private OrganizationLookup $org;

    private array $mapping;

    private array $options;

    private array $cf;

    /** @var array<string,array<int,string>> select options per definition id */
    private array $cfOptions = [];

    private bool $manual;

    public function __construct(private EmployeeImport $import)
    {
        $this->org = new OrganizationLookup;
        $this->mapping = $import->mapping ?? [];
        $this->options = $import->options ?? [];
        $this->cf = collect(ImportColumns::customFields())->keyBy(fn ($f) => 'cf:'.$f->key)->all();
        foreach (DB::table('custom_field_options')->where('tenant_id', app(TenantContext::class)->id)->whereIn('definition_id', collect($this->cf)->pluck('id'))->get(['definition_id', 'value', 'label']) as $o) {
            $this->cfOptions[$o->definition_id][mb_strtolower($o->value)] = $o->value;
            $this->cfOptions[$o->definition_id][mb_strtolower($o->label)] = $o->value;
        }
        $this->manual = app(NumberingService::class)->isManual();
    }

    /** Applies the column mapping to a raw row. @return array<string,?string> */
    public function mapped(array $raw): array
    {
        $out = [];
        foreach ($this->mapping as $target => $source) {
            if ($source !== null && $source !== '') {
                $v = $raw[$source] ?? null;
                $out[$target] = is_string($v) ? (trim($v) === '' ? null : trim($v)) : ($v === null ? null : (string) $v);
            }
        }

        return $out;
    }

    /** @return array{0:array,1:array,2:array} [normalized, errors, warnings] — identity/duplicate checks are separate */
    public function evaluate(array $raw): array
    {
        $v = $this->mapped($raw);
        $errors = [];
        $warnings = [];
        $err = function (?string $field, string $message) use (&$errors) {
            $errors[] = ['field' => $field, 'message' => $message];
        };
        $get = fn (string $k) => $v[$k] ?? null;

        $date = fn (string $field) => $this->date($field, $get($field), $err);
        $dob = $date('date_of_birth');
        $joined = $date('joined_on');
        $probationEnd = $date('probation_end');
        $contractEnd = $date('contract_end');

        $gender = null;
        if ($g = $get('gender')) {
            $gender = self::GENDERS[mb_strtolower(trim($g))] ?? null;
            $gender ?: $err('gender', "Gender '".$g."' is not recognised. Use female, male, non_binary, other or undisclosed.");
        }
        $worker = mb_strtolower($get('worker_type') ?? ($this->options['default_worker_type'] ?? 'employee'));
        $probation = null;
        if (($p = $get('probation_applicable')) !== null) {
            $probation = match (mb_strtolower($p)) {
                'yes', 'y', 'true', '1' => true, 'no', 'n', 'false', '0' => false, default => null,
            };
            $probation === null && $err('probation_applicable', "Probation applicable '".$p."' must be yes or no.");
        }
        $notice = $get('notice_period_days');
        if ($notice !== null && ! ctype_digit($notice)) {
            $err('notice_period_days', "Notice period '".$notice."' must be a whole number of days.");
            $notice = null;
        }

        $assignment = [];
        $labels = [];
        $records = [];
        foreach (ImportColumns::ORGANIZATION as $key => [$table, $column, $label]) {
            if (($value = $get($key)) === null) {
                continue;
            }
            [$record, $problem] = $this->org->find($table, $label, $value);
            if ($problem) {
                $err($key, $problem);

                continue;
            }
            $assignment[$column] = $record->id;
            $labels[$key] = $record->name;
            $records[$key] = $record;
        }

        $country = $get('country') ? strtoupper($get('country')) : ($this->options['default_country'] ?? null);
        $country ??= isset($records['location']) ? OrganizationLookup::metadata($records['location'], 'country') : null;
        $country ??= isset($records['legal_entity']) ? OrganizationLookup::metadata($records['legal_entity'], 'country') : null;
        $country ??= PeopleSettings::get('default_country');
        $country || $err('country', 'The country could not be determined. Add a country column, choose a default country, or set a location.');
        $timezone = $get('timezone') ?? ($this->options['default_timezone'] ?? null);
        $timezone ??= isset($records['location']) ? OrganizationLookup::metadata($records['location'], 'timezone') : null;

        $person = array_filter(['legal_first_name' => $get('first_name'), 'legal_middle_name' => $get('middle_name'), 'legal_last_name' => $get('last_name'), 'preferred_name' => $get('preferred_name'),
            'gender' => $gender, 'date_of_birth' => $dob, 'personal_email' => $get('personal_email'), 'personal_phone' => $get('personal_phone'), 'country' => $country], fn ($x) => $x !== null);
        $employment = array_filter(['employee_number' => $get('employee_number'), 'worker_type' => $worker, 'joined_on' => $joined, 'country' => $country, 'timezone' => $timezone,
            'work_email' => $get('work_email'), 'work_phone' => $get('work_phone'), 'probation_end' => $probationEnd, 'contract_end' => $contractEnd, 'notice_period_days' => $notice !== null ? (int) $notice : null], fn ($x) => $x !== null);
        if ($probation !== null) {
            $employment['probation_applicable'] = $probation;
        }
        if ($this->manual && empty($employment['employee_number'])) {
            $err('employee_number', 'Employee number is required because this workspace uses manual numbering.');
        }
        if ($joined && $contractEnd && $contractEnd < $joined) {
            $err('contract_end', 'Contract end cannot be before the joining date.');
        }
        if ($joined && $probationEnd && $probationEnd < $joined) {
            $err('probation_end', 'Probation end cannot be before the joining date.');
        }
        if ($joined && $joined > TenantClock::today()) {
            $warnings[] = ['field' => 'joined_on', 'code' => 'future_joiner', 'message' => 'The joining date is in the future: this employee will start in preboarding.'];
        }

        // Shared wizard rules (dates are already normalized to Y-m-d; cross-field rules are checked above).
        $employmentRules = EmployeeRules::employment();
        $employmentRules['probation_end'] = $employmentRules['contract_end'] = ['nullable', 'date_format:Y-m-d'];
        unset($employmentRules['status']);
        $rules = EmployeeRules::prefixed('person', EmployeeRules::person()) + EmployeeRules::prefixed('employment', $employmentRules);
        $seen = [];
        foreach ($errors as $e) {
            $seen[$e['field']] = true;
        }
        $validator = Validator::make(['person' => $person, 'employment' => $employment], $rules);
        $validator->setAttributeNames(collect(self::FIELD_OF)->mapWithKeys(fn ($f, $k) => [$k => strtolower(ImportColumns::COLUMNS[$f][0] ?? $f)])->all());
        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $key => $messages) {
                $field = self::FIELD_OF[$key] ?? null;
                if ($field && isset($seen[$field])) {
                    continue;
                }
                $err($field, ucfirst($messages[0]));
                $field && $seen[$field] = true;
            }
        }

        $custom = [];
        foreach ($this->cf as $target => $definition) {
            $value = $get($target);
            if ($value === null && $definition->default_value !== null) {
                continue;
            }
            if ($value === null) {
                $definition->required && $err($target, $definition->label.' is required.');

                continue;
            }
            $custom[$definition->key] = $this->customValue($definition, $value, $err, $target);
        }

        $manager = ['number' => $get('manager_employee_number'), 'employee_id' => null];
        $normalized = ['person' => $person, 'employment' => $employment, 'assignment' => $assignment, 'manager' => $manager, 'custom_fields' => $custom,
            'summary' => ['name' => trim(($get('first_name') ?? '').' '.($get('last_name') ?? '')), 'employee_number' => $get('employee_number'), 'work_email' => $get('work_email')] + $labels];

        return [$normalized, $errors, $warnings];
    }

    private function customValue(object $definition, string $value, callable $err, string $target): mixed
    {
        $label = $definition->label;
        switch ($definition->type) {
            case 'number': case 'integer': case 'decimal':
                if (! is_numeric($value)) {
                    $err($target, $label." '".$value."' must be a number.");

                    return null;
                }

                return $definition->type === 'integer' ? (int) $value : $value + 0;
            case 'boolean':
                $b = match (mb_strtolower($value)) {
                    'yes', 'y', 'true', '1' => true, 'no', 'n', 'false', '0' => false, default => null,
                };
                $b === null && $err($target, $label." '".$value."' must be yes or no.");

                return $b;
            case 'date':
                return $this->date($target, $value, $err, $label);
            case 'select': case 'dropdown':
                $canonical = $this->cfOptions[$definition->id][mb_strtolower($value)] ?? null;
                if ($canonical === null && ! empty($this->cfOptions[$definition->id])) {
                    $err($target, $label." '".$value."' is not one of the allowed options.");
                }

                return $canonical ?? $value;
            default:
                return $value;
        }
    }

    private function date(string $field, ?string $value, callable $err, ?string $label = null): ?string
    {
        if ($value === null) {
            return null;
        }
        $format = $this->options['date_format'] ?? 'Y-m-d';
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T]\d/', $value, $m)) {
            $value = $m[1];
        }
        foreach (array_unique([$format, 'Y-m-d']) as $f) {
            $d = \DateTimeImmutable::createFromFormat('!'.$f, $value);
            $problems = \DateTimeImmutable::getLastErrors();
            if ($d && (! $problems || ($problems['warning_count'] === 0 && $problems['error_count'] === 0)) && (int) $d->format('Y') >= 1900 && (int) $d->format('Y') <= 2100) {
                return $d->format('Y-m-d');
            }
        }
        $hint = preg_match('#^\d{1,2}[/.-]\d{1,2}[/.-]\d{2,4}$#', $value) && $format === 'Y-m-d' ? ' If your dates look like this, choose the date format (DD/MM/YYYY or MM/DD/YYYY) in the import options.' : '';
        $err($field, ($label ?? strtolower(ImportColumns::COLUMNS[$field][0] ?? $field))." '".$value."' is not a valid date. Expected ".self::DATE_FORMATS[$format].'.'.$hint);

        return null;
    }
}
