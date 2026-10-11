<?php

namespace App\Domain\Quality\Rules;

use App\Domain\Access\Classification;
use App\Domain\People\Models\Assignment;
use App\Domain\Quality\Finding;
use Illuminate\Support\Facades\DB;

/**
 * The current assignment points at organization records that are inactive or missing, or the primary manager has left.
 * Reads only; fixing the reference (transfer, reassign) is a human decision.
 */
final class InvalidOrgReferenceRule extends PeopleRule
{
    private const LABELS = ['legal_entity_id' => 'Legal entity', 'business_unit_id' => 'Business unit', 'department_id' => 'Department', 'team_id' => 'Team', 'cost_center_id' => 'Cost center',
        'designation_id' => 'Designation', 'position_id' => 'Position', 'grade_id' => 'Grade', 'level_id' => 'Level', 'employee_type_id' => 'Employee type', 'location_id' => 'Location'];

    public function key(): string
    {
        return 'invalid_org_reference';
    }

    public function category(): string
    {
        return 'organization';
    }

    public function label(): string
    {
        return 'Invalid organization references';
    }

    public function scan(int $tenantId): iterable
    {
        $problems = [];
        foreach (Assignment::DIMENSIONS as $column => $table) {
            $rows = $this->employees($tenantId, self::CURRENT, true)->leftJoin("$table as o", 'o.id', '=', "a.$column")
                ->whereNotNull("a.$column")->where(fn ($w) => $w->whereNull('o.id')->orWhere('o.active', false))->select(['e.id as id', 'o.id as org_id'])->get();
            foreach ($rows as $row) {
                $problems[$row->id][] = ['kind' => $row->org_id ? 'inactive' : 'missing', 'label' => self::LABELS[$column]];
            }
        }
        $today = $this->today();
        $leftManagers = $this->employees($tenantId, self::CURRENT)->join('employee_reporting_relationships as r', function ($j) use ($today) {
            $j->on('r.employee_id', '=', 'e.id')->where('r.type', '=', 'primary')->where('r.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', $today));
        })->join('employees as m', 'm.id', '=', 'r.manager_id')->where(fn ($w) => $w->whereIn('m.status', ['separated', 'archived'])->orWhereNotNull('m.deleted_at'))->select(['e.id as id'])->get();
        foreach ($leftManagers as $row) {
            $problems[$row->id][] = ['kind' => 'manager_left', 'label' => 'Reporting manager'];
        }
        ksort($problems);
        foreach ($problems as $employeeId => $list) {
            $labels = array_map(fn ($p) => $p['kind'] === 'manager_left' ? 'Reporting manager has left the company' : $p['label'].($p['kind'] === 'missing' ? ' (missing)' : ' (inactive)'), $list);
            yield new Finding((int) $employeeId, 'emp:'.$employeeId, 'Invalid organization reference: '.$this->list($labels),
                'The current job record refers to organization data that is inactive or no longer valid. Review the employee\'s job information.', 'action_required',
                ['employee', (int) $employeeId], ['problems' => $list], Classification::MANAGER_SENSITIVE);
        }
    }
}
