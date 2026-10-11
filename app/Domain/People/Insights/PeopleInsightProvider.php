<?php

namespace App\Domain\People\Insights;

use App\Domain\Access\DataScope;
use App\Domain\Access\PeopleSettings;
use App\Domain\Insights\InsightProvider;
use App\Domain\People\Models\Employee;
use App\Domain\Quality\DataQualityFinding;
use App\Domain\Quality\FindingService;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * People insights for the Action Center. Only viewers with people.quality.view get any, limited to their data scope and to the
 * classification of the underlying data (a manager never learns about documents or contracts they could not open).
 * affected() is also the drill-down used by the list / export / reminder / task endpoints, so counts and lists always agree.
 */
final class PeopleInsightProvider implements InsightProvider
{
    public const LIMIT = 2000;

    /** key => [severity, title (":n" count), description, reminder topic or null, reminder action label] */
    public const CATALOGUE = [
        'people.missing_documents' => ['warning', ':n employees have missing mandatory documents', 'Mandatory document types have no uploaded document.', 'missing_documents', 'Request information'],
        'people.probation_due' => ['warning', ':n probation confirmations are due within 14 days', 'Probation ends within 14 days (or has passed) and the employee is still on probation.', null, null],
        'people.work_authorization_expiring' => ['action_required', ':n work permits or visas expire soon', 'Work authorizations expire within the expiry window.', 'expiring_documents', 'Send reminder'],
        'people.contracts_expiring' => ['warning', ':n contracts expire soon', 'Contract end dates fall within the expiry window.', null, null],
        'people.pending_profile_changes' => ['info', ':n employee changes await approval', 'Profile change requests are pending a decision.', null, null],
        'people.incomplete_profiles' => ['info', ':n profiles are missing required information', 'Profiles that miss enabled completeness items.', 'incomplete_profile', 'Request information'],
        'people.quality_issues' => ['action_required', ':n open data quality issues need attention', 'Critical or action-required data quality findings are open.', null, null],
        'people.without_manager' => ['warning', ':n employees have no reporting manager', 'Current employees without a primary manager.', null, null],
    ];

    public function __construct(private DataScope $scope, private ProfileCompleteness $completeness, private MandatoryDocuments $documents, private FindingService $findings) {}

    public function key(): string
    {
        return 'people';
    }

    public static function window(): int
    {
        return max(1, (int) PeopleSettings::get('expiry_window_days', 60));
    }

    public function insights(User $viewer): array
    {
        if (! $viewer->allows('people.quality.view')) {
            return [];
        }
        $out = [];
        $relations = ViewerRelations::for($viewer);
        foreach (self::CATALOGUE as $key => [$severity, $title, $description, $topic, $topicLabel]) {
            $affected = $this->affected($viewer, $key, $relations);
            $count = $key === 'people.quality_issues' ? $this->qualityCount($viewer, $relations) : count($affected);
            if ($count < 1) {
                continue;
            }
            $out[] = ['key' => $key, 'domain' => 'people', 'severity' => $key === 'people.quality_issues' && $this->hasCritical($viewer, $relations) ? 'critical' : $severity,
                'title' => str_replace(':n', (string) $count, $count === 1 ? str_replace(['employees have', 'employees', 'profiles are', 'contracts expire', 'work permits or visas expire', 'probation confirmations are', 'employee changes await', 'issues need', 'issues'], ['employee has', 'employee', 'profile is', 'contract expires', 'work permit or visa expires', 'probation confirmation is', 'employee change awaits', 'issue needs', 'issue'], $title) : $title),
                'count' => $count, 'description' => $description, 'actions' => $this->actions($viewer, $key, $topic, $topicLabel),
                'sample' => array_map(fn ($r) => ['employee_uuid' => $r->uuid, 'name' => $r->name], array_slice($affected, 0, 5))];
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function actions(User $viewer, string $key, ?string $topic, ?string $topicLabel): array
    {
        $actions = [['label' => 'Review employees', 'kind' => 'navigate', 'href' => '/employees?insight='.$key, 'requires_confirmation' => false]];
        if ($key === 'people.quality_issues') {
            $actions[] = ['label' => 'Resolve issue', 'kind' => 'navigate', 'href' => '/people/quality?status=open&severity=critical,action_required', 'requires_confirmation' => false];
        }
        if ($viewer->allows('people.quality.resolve')) {
            if ($topic) {
                $actions[] = ['label' => $topicLabel, 'kind' => 'api', 'endpoint' => '/api/v1/people/action-center/remind', 'method' => 'POST', 'body' => ['insight' => $key, 'topic' => $topic], 'requires_confirmation' => true];
            }
            $actions[] = ['label' => 'Assign task', 'kind' => 'api', 'endpoint' => '/api/v1/people/action-center/tasks', 'method' => 'POST', 'body' => ['insight' => $key], 'requires_confirmation' => false];
        }
        if ($viewer->allows('people.export')) {
            $actions[] = ['label' => 'Export list', 'kind' => 'api', 'endpoint' => '/api/v1/people/action-center/'.$key.'/employees?format=csv', 'method' => 'GET', 'requires_confirmation' => false];
        }

        return $actions;
    }

    /** @return array<int,object{id:int,uuid:string,name:string,detail:?string}> everyone behind an insight for this viewer (capped). */
    public function affected(User $viewer, string $key, ?ViewerRelations $rel = null): array
    {
        abort_unless(isset(self::CATALOGUE[$key]), 404);
        if (! $viewer->allows('people.quality.view')) {
            return [];
        }
        $rel ??= ViewerRelations::for($viewer);
        $today = TenantClock::today();
        $rows = match ($key) {
            'people.missing_documents' => $this->missingDocuments($viewer, $rel),
            'people.probation_due' => $this->base($viewer, $rel, 'employee.probation')->where('e.status', 'probation')->whereNull('e.confirmation_date')->whereNotNull('e.probation_end')
                ->where('e.probation_end', '<=', CarbonImmutable::parse($today)->addDays(14)->toDateString())->orderBy('e.probation_end')->get(['e.id', 'e.uuid', 'p.display_name as name', 'e.probation_end as d'])
                ->map(fn ($r) => (object) ['id' => $r->id, 'uuid' => $r->uuid, 'name' => $r->name, 'detail' => 'Probation ends '.substr($r->d, 0, 10)])->all(),
            'people.work_authorization_expiring' => $this->base($viewer, $rel, 'work_authorization.records')->join('work_authorizations as w', 'w.person_id', '=', 'e.person_id')->where('w.tenant_id', $viewer->tenant_id)
                ->where('w.status', 'active')->whereBetween('w.expiry_date', [$today, CarbonImmutable::parse($today)->addDays(self::window())->toDateString()])->orderBy('w.expiry_date')
                ->get(['e.id', 'e.uuid', 'p.display_name as name', 'w.type', 'w.expiry_date'])->unique('id')
                ->map(fn ($r) => (object) ['id' => $r->id, 'uuid' => $r->uuid, 'name' => $r->name, 'detail' => str_replace('_', ' ', $r->type).' expires '.substr($r->expiry_date, 0, 10)])->values()->all(),
            'people.contracts_expiring' => $this->base($viewer, $rel, 'employee.contract')->whereBetween('e.contract_end', [$today, CarbonImmutable::parse($today)->addDays(self::window())->toDateString()])->orderBy('e.contract_end')
                ->get(['e.id', 'e.uuid', 'p.display_name as name', 'e.contract_end as d'])->map(fn ($r) => (object) ['id' => $r->id, 'uuid' => $r->uuid, 'name' => $r->name, 'detail' => 'Contract ends '.substr($r->d, 0, 10)])->all(),
            'people.pending_profile_changes' => $viewer->allows('people.changes.approve') ? $this->base($viewer, $rel, null)->join('profile_change_requests as c', 'c.employee_id', '=', 'e.id')
                ->where('c.tenant_id', $viewer->tenant_id)->where('c.status', 'pending')->get(['e.id', 'e.uuid', 'p.display_name as name', 'c.section'])->groupBy('id')
                ->map(fn ($g) => (object) ['id' => $g[0]->id, 'uuid' => $g[0]->uuid, 'name' => $g[0]->name, 'detail' => $g->count().' pending change'.($g->count() === 1 ? '' : 's')])->values()->all() : [],
            'people.incomplete_profiles' => $this->incomplete($viewer, $rel),
            'people.quality_issues' => $this->qualityEmployees($viewer, $rel),
            'people.without_manager' => PeopleSettings::get('manager_required', true) ? $this->base($viewer, $rel, null)->whereNotExists(function ($s) use ($today) {
                $s->from('employee_reporting_relationships as r')->whereColumn('r.employee_id', 'e.id')->where('r.type', 'primary')->where('r.effective_from', '<=', $today)
                    ->where(fn ($w) => $w->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', $today));
            })->orderBy('p.display_name')->get(['e.id', 'e.uuid', 'p.display_name as name'])->map(fn ($r) => (object) ['id' => $r->id, 'uuid' => $r->uuid, 'name' => $r->name, 'detail' => null])->all() : [],
        };

        return array_slice($rows, 0, self::LIMIT);
    }

    /** Working employees in the viewer's scope whose data of the given registry field the viewer may see. */
    private function base(User $viewer, ViewerRelations $rel, ?string $field)
    {
        $q = DB::table('employees as e')->join('people as p', fn ($j) => $j->on('p.id', '=', 'e.person_id')->whereNull('p.deleted_at'))
            ->where('e.tenant_id', $viewer->tenant_id)->whereNull('e.deleted_at')->whereIn('e.status', Employee::WORKING);
        $this->scope->constrain($q, $viewer, 'people.quality.view', 'e.id');

        return $field ? $rel->constrainField($q, $field, 'e.id') : $q;
    }

    private function missingDocuments(User $viewer, ViewerRelations $rel): array
    {
        $today = TenantClock::today();
        $rows = $this->base($viewer, $rel, 'document.records')->leftJoin('employee_assignments as a', function ($j) use ($today) {
            $j->on('a.employee_id', '=', 'e.id')->where('a.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('a.effective_to')->orWhere('a.effective_to', '>=', $today));
        })->get(['e.id', 'e.uuid', 'e.person_id', 'e.country', 'a.employee_type_id', 'p.display_name as name'])->all();
        $missing = $this->documents->missing($viewer->tenant_id, $rows);
        $out = [];
        foreach ($rows as $r) {
            if (isset($missing[$r->id])) {
                $out[] = (object) ['id' => $r->id, 'uuid' => $r->uuid, 'name' => $r->name, 'detail' => 'Missing: '.implode(', ', $missing[$r->id])];
            }
        }

        return $out;
    }

    private function incomplete(User $viewer, ViewerRelations $rel): array
    {
        $out = [];
        foreach ($this->completeness->scan($viewer, 'people.quality.view', $rel) as [$row, $result]) {
            if (! $result['complete']) {
                $name = DB::table('people')->where('id', $row->person_id)->value('display_name');
                $out[] = (object) ['id' => $row->id, 'uuid' => $row->uuid, 'name' => $name, 'detail' => 'Missing: '.implode(', ', array_column($result['missing'], 'label'))];
                if (count($out) >= self::LIMIT) {
                    break;
                }
            }
        }

        return $out;
    }

    private function openFindings(User $viewer, ViewerRelations $rel)
    {
        return $this->findings->visible($viewer, $rel)->whereIn('status', DataQualityFinding::ACTIVE)->whereIn('severity', ['critical', 'action_required']);
    }

    private function qualityCount(User $viewer, ViewerRelations $rel): int
    {
        return $this->openFindings($viewer, $rel)->count();
    }

    private function hasCritical(User $viewer, ViewerRelations $rel): bool
    {
        return $this->openFindings($viewer, $rel)->where('severity', 'critical')->exists();
    }

    private function qualityEmployees(User $viewer, ViewerRelations $rel): array
    {
        $rows = $this->openFindings($viewer, $rel)->whereNotNull('employee_id')->orderBy('id')->limit(self::LIMIT)->get(['employee_id', 'title']);
        if ($rows->isEmpty()) {
            return [];
        }
        $people = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->whereIn('employees.id', $rows->pluck('employee_id')->unique())->get(['employees.id', 'employees.uuid', 'people.display_name'])->keyBy('id');

        return $rows->groupBy('employee_id')->map(fn ($g, $id) => (object) ['id' => (int) $id, 'uuid' => $people[$id]->uuid, 'name' => $people[$id]->display_name, 'detail' => $g->pluck('title')->implode('; ')])->values()->all();
    }
}
