<?php

namespace App\Domain\People\Services;

use App\Domain\Access\DataScope;
use App\Domain\Access\PeopleSettings;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * People Directory query. Lists never hydrate full models: one joined, indexed, paginated query selects
 * only list columns. Two modes:
 *   manage    — rows limited by the viewer's data scope (people.view); HR sees everything in scope
 *   directory — company-wide coworker lookup (people.directory); working employees, basic fields only
 * Directory-privacy settings decide which public fields coworkers (not self/HR) can see.
 */
final class EmployeeDirectory
{
    public const SORTS = ['name' => 'p.display_name', 'employee_number' => 'e.employee_number', 'joined_on' => 'e.joined_on', 'status' => 'e.status',
        'department' => 'dep.name', 'designation' => 'des.name', 'location' => 'loc.name'];

    public function __construct(private DataScope $scope) {}

    /** Base query shared by the directory, exports, bulk operations and saved views. Selects nothing yet. */
    public function query(User $viewer, array $filters = [], string $mode = 'manage')
    {
        $today = TenantClock::today();
        $q = DB::table('employees as e')->join('people as p', 'p.id', '=', 'e.person_id')
            ->leftJoin('employee_assignments as a', function ($j) use ($today) {
                $j->on('a.employee_id', '=', 'e.id')->where('a.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('a.effective_to')->orWhere('a.effective_to', '>=', $today));
            })
            ->leftJoin('departments as dep', 'dep.id', '=', 'a.department_id')->leftJoin('designations as des', 'des.id', '=', 'a.designation_id')
            ->leftJoin('locations as loc', 'loc.id', '=', 'a.location_id')->leftJoin('employee_types as et', 'et.id', '=', 'a.employee_type_id')
            ->leftJoin('employee_reporting_relationships as r', function ($j) use ($today) {
                $j->on('r.employee_id', '=', 'e.id')->where('r.type', '=', 'primary')->where('r.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', $today));
            })
            ->leftJoin('employees as m', 'm.id', '=', 'r.manager_id')->leftJoin('people as mp', 'mp.id', '=', 'm.person_id')
            ->where('e.tenant_id', $viewer->tenant_id)->whereNull('e.deleted_at')->whereNull('p.deleted_at');

        if ($mode === 'directory') {
            $q->whereIn('e.status', ['active', 'probation', 'notice_period', 'suspended', 'long_leave']);
        } else {
            $this->scope->constrain($q, $viewer, 'people.view', 'e.id');
            if (empty($filters['status'])) {
                $q->where('e.status', '!=', 'archived');
            }
        }
        $this->applyFilters($q, $viewer, $filters);

        return $q;
    }

    public function search(User $viewer, array $filters = [], string $mode = 'manage'): LengthAwarePaginator
    {
        $q = $this->query($viewer, $filters, $mode)->select([
            'e.uuid', 'e.id as _id', 'e.employee_number', 'e.status', 'e.worker_type', 'e.joined_on', 'e.work_email', 'e.work_phone', 'p.display_name', 'p.preferred_name', 'p.photo_path', 'p.pronouns',
            'des.name as designation', 'dep.name as department', 'loc.name as location', 'et.name as employee_type', 'm.uuid as manager_uuid', 'mp.display_name as manager_name',
        ]);
        $sort = self::SORTS[$filters['sort'] ?? 'name'] ?? self::SORTS['name'];
        $q->orderBy($sort, ($filters['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc')->orderBy('e.id');
        $page = $q->paginate(min(100, max(5, (int) ($filters['per_page'] ?? 25))));
        $own = $this->scope->ownEmployeeIds($viewer);
        $hr = $viewer->allows('people.personal.view');
        $page->setCollection($page->getCollection()->map(fn ($row) => $this->row($row, $hr || in_array($row->_id, $own, true))));

        return $page;
    }

    /** Applies directory privacy to one list row; self and HR see everything the list carries. */
    private function row(object $row, bool $privileged): array
    {
        $visible = fn (string $key) => $privileged || PeopleSettings::directoryVisible($key);

        return [
            'uuid' => $row->uuid, 'display_name' => $row->display_name, 'preferred_name' => $row->preferred_name, 'status' => $row->status, 'worker_type' => $row->worker_type,
            'joined_on' => $row->joined_on, 'designation' => $row->designation, 'employee_type' => $row->employee_type,
            'employee_number' => $visible('employee_number') ? $row->employee_number : null,
            'work_email' => $visible('work_email') ? $row->work_email : null, 'work_phone' => $visible('work_phone') ? $row->work_phone : null,
            'department' => $visible('department') ? $row->department : null, 'location' => $visible('location') ? $row->location : null,
            'manager' => $visible('manager') && $row->manager_uuid ? ['uuid' => $row->manager_uuid, 'name' => $row->manager_name] : null,
            'has_photo' => $visible('photo') && (bool) $row->photo_path, 'pronouns' => $visible('pronouns') ? $row->pronouns : null,
        ];
    }

    private function applyFilters($q, User $viewer, array $f): void
    {
        $privileged = $viewer->allows('people.personal.view');
        if (! empty($f['q'])) {
            $term = '%'.addcslashes(mb_substr(trim($f['q']), 0, 100), '%_\\').'%';
            $q->where(function ($w) use ($term, $privileged) {
                $w->where('p.display_name', 'like', $term)->orWhere('p.legal_name', 'like', $term)->orWhere('p.preferred_name', 'like', $term)->orWhere('e.employee_number', 'like', $term)->orWhere('e.work_email', 'like', $term);
                if ($privileged) {
                    $w->orWhere('p.personal_email', 'like', $term)->orWhere('p.personal_phone', 'like', $term)->orWhere('e.work_phone', 'like', $term);
                }
            });
        }
        foreach (['status' => 'e.status', 'worker_type' => 'e.worker_type'] as $key => $column) {
            if (! empty($f[$key])) {
                $q->whereIn($column, (array) $f[$key]);
            }
        }
        foreach (['department_id' => 'a.department_id', 'designation_id' => 'a.designation_id', 'location_id' => 'a.location_id', 'business_unit_id' => 'a.business_unit_id', 'team_id' => 'a.team_id',
            'legal_entity_id' => 'a.legal_entity_id', 'employee_type_id' => 'a.employee_type_id'] as $key => $column) {
            if (! empty($f[$key])) {
                $q->whereIn($column, (array) $f[$key]);
            }
        }
        if (! empty($f['manager'])) {
            $q->where('m.uuid', $f['manager']);
        }
        if (! empty($f['skill_id'])) {
            $q->whereExists(fn ($s) => $s->from('employee_skills as es')->whereColumn('es.person_id', 'p.id')->whereIn('es.skill_id', (array) $f['skill_id']));
        }
        if (! empty($f['tag_id'])) {
            $q->whereExists(fn ($s) => $s->from('employee_tags as et2')->whereColumn('et2.employee_id', 'e.id')->whereIn('et2.tag_id', (array) $f['tag_id']));
        }
        if (! empty($f['joined_from'])) {
            $q->where('e.joined_on', '>=', $f['joined_from']);
        }
        if (! empty($f['joined_to'])) {
            $q->where('e.joined_on', '<=', $f['joined_to']);
        }
    }
}
