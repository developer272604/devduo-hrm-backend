<?php

namespace App\Domain\People\Insights;

use App\Domain\Access\DataScope;
use App\Domain\Access\PeopleSettings;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * People dashboard: only real, permission-aware items inside the viewer's data scope; every section is {count, items[]} and is
 * empty when there is nothing. Birthdays appear only when the viewer is HR (people.personal.view) or the tenant's directory privacy
 * makes date_of_birth visible, and only as day + month (never the year).
 */
final class PeopleDashboard
{
    private const ITEMS = 10;

    public function __construct(private DataScope $scope, private ProfileCompleteness $completeness, private PeopleInsightProvider $insights) {}

    public function build(User $viewer): array
    {
        $rel = ViewerRelations::for($viewer);
        $today = TenantClock::today();
        $date = CarbonImmutable::parse($today);

        $joiners = fn ($from, $to) => $this->base($viewer, $rel)->whereBetween('e.joined_on', [$from, $to])->orderBy('e.joined_on')->orderBy('e.id')
            ->get(['e.uuid', 'p.display_name as name', 'e.joined_on']);
        $new = $joiners($date->subDays(30)->toDateString(), $today);
        $upcoming = $joiners($date->addDay()->toDateString(), $date->addDays(30)->toDateString());
        $joinerItem = fn ($r) => ['employee_uuid' => $r->uuid, 'name' => $r->name, 'joined_on' => substr($r->joined_on, 0, 10)];

        $anniversaries = $this->base($viewer, $rel, Employee::WORKING)->whereMonth('e.original_joining_date', $date->month)->whereYear('e.original_joining_date', '<', $date->year)
            ->get(['e.uuid', 'p.display_name as name', 'e.original_joining_date as d'])->map(fn ($r) => ['employee_uuid' => $r->uuid, 'name' => $r->name, 'day' => (int) substr($r->d, 8, 2), 'month' => $date->month,
                'years' => $date->year - (int) substr($r->d, 0, 4)])->sortBy('day')->values();

        $birthdaysVisible = $rel->isHr() || PeopleSettings::directoryVisible('date_of_birth');
        $birthdays = $birthdaysVisible ? $this->base($viewer, $rel, Employee::WORKING)->whereNotNull('p.date_of_birth')->whereMonth('p.date_of_birth', $date->month)
            ->get(['e.uuid', 'p.display_name as name', 'p.date_of_birth as d'])->map(fn ($r) => ['employee_uuid' => $r->uuid, 'name' => $r->name, 'day' => (int) substr($r->d, 8, 2), 'month' => $date->month])->sortBy('day')->values() : collect();

        $probation = $this->insightRows($viewer, 'people.probation_due', $rel);
        $incomplete = $this->incomplete($viewer, $rel);

        return [
            'as_of' => $today,
            'new_joiners' => $this->section($new->map($joinerItem)),
            'upcoming_joinings' => $this->section($upcoming->map($joinerItem)),
            'work_anniversaries' => $this->section($anniversaries),
            'birthdays' => ['visible' => $birthdaysVisible] + $this->section($birthdays),
            'probation_due' => $this->section(collect($probation)->map(fn ($r) => ['employee_uuid' => $r->uuid, 'name' => $r->name, 'detail' => $r->detail])),
            'expiring_documents' => $this->section($this->expiring($viewer, $rel, $today, $date->addDays(PeopleInsightProvider::window())->toDateString())),
            'incomplete_profiles' => ['count' => $incomplete],
            'pending_changes' => ['count' => $this->pendingChanges($viewer, $rel)],
        ];
    }

    private function section($items): array
    {
        $items = collect($items);

        return ['count' => $items->count(), 'items' => $items->take(self::ITEMS)->values()->all()];
    }

    private function base(User $viewer, ViewerRelations $rel, ?array $statuses = null)
    {
        $q = DB::table('employees as e')->join('people as p', fn ($j) => $j->on('p.id', '=', 'e.person_id')->whereNull('p.deleted_at'))->where('e.tenant_id', $viewer->tenant_id)->whereNull('e.deleted_at');
        $statuses ? $q->whereIn('e.status', $statuses) : $q->where('e.status', '!=', 'archived');
        $this->scope->constrain($q, $viewer, 'people.view', 'e.id');

        return $q;
    }

    private function insightRows(User $viewer, string $key, ViewerRelations $rel): array
    {
        return $viewer->allows('people.quality.view') ? $this->insights->affected($viewer, $key, $rel) : [];
    }

    private function incomplete(User $viewer, ViewerRelations $rel): int
    {
        $n = 0;
        foreach ($this->completeness->scan($viewer, 'people.view', $rel) as [, $result]) {
            $n += $result['complete'] ? 0 : 1;
        }

        return $n;
    }

    private function pendingChanges(User $viewer, ViewerRelations $rel): int
    {
        $q = DB::table('profile_change_requests as c')->join('employees as e', 'e.id', '=', 'c.employee_id')->where('c.tenant_id', $viewer->tenant_id)->where('c.status', 'pending');
        if ($viewer->allows('people.changes.approve')) {
            $this->scope->constrain($q, $viewer, 'people.changes.approve', 'e.id');
        } else {
            $q->whereIn('e.id', $rel->ownIds() ?: [0]);
        }

        return $q->count();
    }

    /** Expired or expiring documents, identity documents, work authorizations and certifications the viewer may see. */
    private function expiring(User $viewer, ViewerRelations $rel, string $today, string $limit)
    {
        $sources = [
            ['employee_documents', 'document.records', 'title', 'document', true], ['identity_documents', 'identity.records', 'type', 'identity', false],
            ['work_authorizations', 'work_authorization.records', 'type', 'work_authorization', false], ['employee_certifications', 'certification.records', 'name', 'certification', false],
        ];
        $items = collect();
        foreach ($sources as [$table, $field, $labelColumn, $kind, $softDeletes]) {
            $q = $this->base($viewer, $rel, Employee::WORKING)->join($table.' as d', 'd.person_id', '=', 'e.person_id')->where('d.tenant_id', $viewer->tenant_id)->whereNotNull('d.expiry_date')->where('d.expiry_date', '<=', $limit);
            if ($softDeletes) {
                $q->whereNull('d.deleted_at');
            }
            if ($table === 'work_authorizations') {
                $q->where('d.status', 'active');
            }
            $rel->constrainField($q, $field, 'e.id');
            foreach ($q->orderBy('d.expiry_date')->limit(200)->get(['e.uuid', 'p.display_name as name', 'd.'.$labelColumn.' as label', 'd.expiry_date']) as $r) {
                $items->push(['employee_uuid' => $r->uuid, 'name' => $r->name, 'kind' => $kind, 'label' => $kind === 'identity' || $kind === 'work_authorization' ? str_replace('_', ' ', $r->label) : $r->label,
                    'expiry_date' => substr($r->expiry_date, 0, 10), 'expired' => substr($r->expiry_date, 0, 10) < $today]);
            }
        }

        return $items->sortBy('expiry_date')->values();
    }
}
