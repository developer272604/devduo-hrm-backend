<?php

namespace App\Domain\Quality\Rules;

use App\Domain\Access\Classification;
use App\Domain\Access\PeopleSettings;
use App\Domain\Quality\Finding;

/** Required employee fields that are empty. The required set is configurable: settings.people.quality.required_fields. */
final class MissingRequiredFieldsRule extends PeopleRule
{
    /** key => [label, column] */
    public const FIELDS = [
        'work_email' => ['Work email', 'e.work_email'], 'date_of_birth' => ['Date of birth', 'p.date_of_birth'], 'personal_email' => ['Personal email', 'p.personal_email'],
        'department' => ['Department', 'a.department_id'], 'designation' => ['Designation', 'a.designation_id'], 'location' => ['Location', 'a.location_id'],
        'legal_entity' => ['Legal entity', 'a.legal_entity_id'], 'employee_type' => ['Employee type', 'a.employee_type_id'],
    ];

    public const DEFAULTS = ['work_email', 'department', 'designation', 'location'];

    public function key(): string
    {
        return 'missing_required_fields';
    }

    public function category(): string
    {
        return 'completeness';
    }

    public function label(): string
    {
        return 'Missing required employee fields';
    }

    public function scan(int $tenantId): iterable
    {
        $required = array_values(array_intersect((array) PeopleSettings::get('quality.required_fields', self::DEFAULTS), array_keys(self::FIELDS)));
        if (! $required) {
            return;
        }
        $query = $this->employees($tenantId, self::CURRENT, true)->select(['e.id as id', ...array_map(fn ($k) => self::FIELDS[$k][1].' as f_'.$k, $required)]);
        foreach ($this->stream($query) as $row) {
            $missing = [];
            foreach ($required as $key) {
                $value = $row->{'f_'.$key};
                if ($value === null || trim((string) $value) === '') {
                    $missing[$key] = self::FIELDS[$key][0];
                }
            }
            if ($missing) {
                yield new Finding((int) $row->id, 'emp:'.$row->id, 'Missing required information: '.$this->list(array_values($missing)), 'Required employee fields are empty. Open the employee record and complete them.',
                    'warning', ['employee', (int) $row->id], ['missing' => array_keys($missing), 'labels' => array_values($missing)], Classification::MANAGER_SENSITIVE);
            }
        }
    }
}
