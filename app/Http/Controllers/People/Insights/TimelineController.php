<?php

namespace App\Http\Controllers\People\Insights;

use App\Domain\Access\DataScope;
use App\Domain\Access\FieldAccess;
use App\Domain\People\Insights\DomainEventTypes;
use App\Domain\People\Insights\TimelineService;
use App\Domain\People\Memory\OrganizationMemory;
use App\Domain\Tenancy\TenantClock;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;

/** Timeline (domain events) and Organization Memory (as-of snapshots, headcount). */
class TimelineController extends PeopleController
{
    public function timeline(Request $r, string $uuid, TimelineService $timeline)
    {
        $employee = $this->employee($r, $uuid);
        $f = $r->validate(['group' => 'nullable|string|max:40', 'type' => 'nullable|string|max:80', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'per_page' => 'nullable|integer']);
        $page = $timeline->page($r->user(), $employee, $f, $this->perPage($r));

        return response()->json(array_merge($page->toArray(), ['groups' => DomainEventTypes::categories()]));
    }

    /** Catalogue of event types for icons, labels and group filters. */
    public function types()
    {
        return ['types' => array_values(DomainEventTypes::all()), 'groups' => DomainEventTypes::categories()];
    }

    public function memory(Request $r, string $uuid, OrganizationMemory $memory)
    {
        $employee = $this->employee($r, $uuid);
        $r->validate(['date' => 'nullable|date_format:Y-m-d']);
        $date = $r->input('date', TenantClock::today());
        $snap = $memory->snapshot($employee, $date);
        $user = $r->user();
        $access = $this->access();
        $relation = $access->relation($user, $employee);
        $can = fn (string $field) => $access->view($user, $field, $employee, $relation) !== FieldAccess::HIDDEN;
        $hr = $user->allows('people.confidential.view');

        $out = ['employee_uuid' => $snap['employee_uuid'], 'date' => $snap['date']];
        if ($can('employee.joined_on')) {
            $out['employed'] = $snap['employed'];
        }
        if ($can('employee.status')) {
            $out['status'] = $snap['status'];
        }
        if ($a = $snap['assignment']) {
            $names = [];
            foreach ($a['names'] as $dimension => $name) {
                if ($can('job.'.$dimension)) {
                    $names[$dimension] = $name;
                }
            }
            $assignment = ['effective_from' => $a['effective_from'], 'effective_to' => $a['effective_to'], 'names' => $names];
            if ($can('job.work_mode')) {
                $assignment['work_mode'] = $a['work_mode'];
            }
            if ($can('job.employment_category')) {
                $assignment['employment_category'] = $a['employment_category'];
            }
            if ($hr) {
                $assignment += ['change_type' => $a['change_type'], 'reason' => $a['reason']];
            }
            $out['assignment'] = $names || count($assignment) > 3 ? $assignment : null;
        } else {
            $out['assignment'] = null;
        }
        $managers = [];
        if ($can('job.manager')) {
            foreach ($snap['managers'] as $type => $list) {
                if ($type === 'primary' || $hr) {
                    $managers[$type] = array_map(fn ($m) => array_diff_key($m, ['employee_id' => 1] + ($hr ? [] : ['reason' => 1])), $list);
                }
            }
        }
        $out['managers'] = (object) $managers;
        $out['policies'] = (object) ($can('employee.policy_references') ? $snap['policies'] : []);

        return $out;
    }

    public function headcount(Request $r, OrganizationMemory $memory, DataScope $scope)
    {
        $f = $r->validate(['date' => 'nullable|date_format:Y-m-d', 'by' => 'nullable|in:department,location,legal_entity']);
        $by = $f['by'] ?? 'department';
        $date = $f['date'] ?? TenantClock::today();
        $user = $r->user();
        abort_unless($this->access()->view($user, 'job.'.$by, null) !== FieldAccess::HIDDEN, 403, 'This breakdown is not available to you.');
        $rows = $memory->headcount($user->tenant_id, $date, $by, fn ($q) => $scope->constrain($q, $user, 'people.view', 'e.id'));

        return ['date' => $date, 'by' => $by, 'total' => array_sum(array_column($rows, 'count')), 'data' => $rows];
    }
}
