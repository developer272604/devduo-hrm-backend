<?php

namespace App\Domain\People\Employee360;

use App\Domain\Access\FieldAccess;
use App\Domain\Access\FieldRegistry;
use App\Domain\Access\PeopleSettings;
use App\Domain\People\Insights\PeopleInsightProvider;
use App\Domain\People\Insights\TimelineService;
use App\Domain\People\Insights\ViewerRelations;
use App\Domain\People\Models\Assignment;
use App\Domain\People\Models\Employee;
use App\Domain\People\Services\AssignmentService;
use App\Domain\People\Services\ReportingService;
use App\Domain\Quality\DataQualityFinding;
use App\Domain\Quality\FindingService;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 Employee 360 sections from People data: identity & position, recent changes, needs attention, expiring documents,
 * pending actions, employment journey. Only facts with their source; every item is filtered through FieldAccess or the same
 * record-level rules as the owning screens.
 */
final class PeopleEmployee360Provider implements Employee360Provider
{
    /** Pending change request section => registry field that governs who may know about it. */
    private const CHANGE_FIELDS = ['personal' => 'person.legal_name', 'contact' => 'contact.records', 'address' => 'address.records', 'emergency' => 'emergency.records', 'dependent' => 'dependent.records',
        'education' => 'education.records', 'experience' => 'experience.records', 'skill' => 'skill.records', 'certification' => 'certification.records', 'document' => 'document.records',
        'identity' => 'identity.records', 'bank' => 'bank.records'];

    public function __construct(private FieldAccess $access, private ReportingService $reporting, private AssignmentService $assignments, private TimelineService $timeline, private FindingService $findings) {}

    public function key(): string
    {
        return 'people';
    }

    public function sections(User $viewer, Employee $employee, array $relation): array
    {
        $employee->loadMissing('person');
        $out = [];
        foreach ([$this->identity($viewer, $employee, $relation), $this->recent($viewer, $employee, $relation), $this->attention($viewer, $employee, $relation),
            $this->expiring($viewer, $employee, $relation), $this->pending($viewer, $employee), $this->journey($viewer, $employee, $relation)] as $section) {
            if ($section) {
                $out[] = $section;
            }
        }

        return $out;
    }

    private function can(User $viewer, Employee $employee, array $relation, string $field): bool
    {
        return $this->access->view($viewer, $field, $employee, $relation) !== FieldAccess::HIDDEN;
    }

    private function item(string $key, string $label, mixed $value, string $source, array $extra = []): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'source' => $source] + $extra;
    }

    private function identity(User $viewer, Employee $employee, array $relation): array
    {
        $items = [$this->item('name', 'Name', $employee->person->display_name, 'people.employees')];
        if ($this->can($viewer, $employee, $relation, 'employee.employee_number')) {
            $items[] = $this->item('employee_number', 'Employee number', $employee->employee_number, 'people.employees');
        }
        $items[] = $this->item('status', 'Employment status', $employee->status, 'people.employees');
        if ($this->can($viewer, $employee, $relation, 'employee.joined_on')) {
            $items[] = $this->item('joined_on', 'Joined', $employee->joined_on->toDateString(), 'people.employees');
        }
        $row = $employee->assignmentOn();
        if ($row) {
            foreach (['designation' => ['designations', 'designation_id', 'Designation'], 'department' => ['departments', 'department_id', 'Department'], 'location' => ['locations', 'location_id', 'Location']] as $key => [$table, $column, $label]) {
                if ($row->{$column} && $this->can($viewer, $employee, $relation, 'job.'.$key)) {
                    $items[] = $this->item($key, $label, DB::table($table)->where('id', $row->{$column})->value('name'), 'people.assignments', ['date' => $row->effective_from->toDateString()]);
                }
            }
        }
        if ($this->can($viewer, $employee, $relation, 'job.manager')) {
            $chain = $this->reporting->getReportingChain($employee, 'primary', null, 6);
            if ($chain->isNotEmpty()) {
                $names = DB::table('people')->whereIn('id', $chain->pluck('person_id'))->pluck('display_name', 'id');
                $items[] = $this->item('manager', 'Reports to', $names[$chain->first()->person_id] ?? null, 'people.reporting', ['href' => '/employees/'.$chain->first()->uuid]);
                $items[] = $this->item('manager_chain', 'Reporting chain', $chain->map(fn ($m) => $names[$m->person_id] ?? null)->filter()->values()->all(), 'people.reporting');
            }
        }

        return ['key' => 'identity', 'title' => 'Identity & position', 'order' => 10, 'items' => $items];
    }

    private function recent(User $viewer, Employee $employee, array $relation): ?array
    {
        $entries = $this->timeline->recent($viewer, $employee, 10, $relation);
        if (! $entries) {
            return null;
        }

        return ['key' => 'recent_changes', 'title' => 'What changed recently', 'order' => 20, 'items' => array_map(fn ($e) => $this->item($e['uuid'], $e['label'], $e['summary'], 'people.timeline',
            ['date' => $e['effective_date'] ?? substr((string) $e['occurred_at'], 0, 10), 'future_dated' => $e['future_dated']]), $entries)];
    }

    private function attention(User $viewer, Employee $employee, array $relation): ?array
    {
        $items = [];
        if ($viewer->allows('people.quality.view')) {
            foreach ($this->findings->visible($viewer)->where('employee_id', $employee->id)->whereIn('status', DataQualityFinding::ACTIVE)->orderBy('id')->limit(20)->get() as $f) {
                $items[] = $this->item('finding:'.$f->id, $f->title, $f->severity, 'people.data_quality', ['detail' => $f->detail, 'date' => $f->detected_at?->toDateString()]);
            }
        }
        if ($relation['self'] || $viewer->allows('people.changes.approve')) {
            foreach (DB::table('profile_change_requests')->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->id)->where('status', 'pending')->orderBy('id')->get(['id', 'section', 'created_at']) as $c) {
                $field = self::CHANGE_FIELDS[$c->section] ?? null;
                if ($field === null || $this->can($viewer, $employee, $relation, $field)) {
                    $items[] = $this->item('change:'.$c->id, 'Pending '.str_replace('_', ' ', $c->section).' change', 'pending', 'people.profile_changes', ['date' => substr((string) $c->created_at, 0, 10)]);
                }
            }
        }

        return $items ? ['key' => 'attention', 'title' => 'What needs attention', 'order' => 30, 'items' => $items] : null;
    }

    private function expiring(User $viewer, Employee $employee, array $relation): ?array
    {
        $today = TenantClock::today();
        $limit = CarbonImmutable::parse($today)->addDays(PeopleInsightProvider::window())->toDateString();
        $items = [];
        $add = function (string $key, string $label, string $kind, ?string $date, string $source) use (&$items, $today) {
            $items[] = $this->item($key, $label, $date, $source, ['date' => $date, 'detail' => $date < $today ? 'Expired' : 'Expires in '.CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($date)).' days', 'kind' => $kind]);
        };
        $base = fn (string $table) => DB::table($table)->where('tenant_id', $employee->tenant_id)->where('person_id', $employee->person_id)->whereNotNull('expiry_date')->where('expiry_date', '<=', $limit);
        if ($this->can($viewer, $employee, $relation, 'document.records') || $relation['self']) {
            foreach ($base('employee_documents')->whereNull('deleted_at')->when(! $this->can($viewer, $employee, $relation, 'document.records'), fn ($q) => $q->where('employee_visible', true))->get(['id', 'title', 'expiry_date']) as $d) {
                $add('document:'.$d->id, $d->title, 'document', substr($d->expiry_date, 0, 10), 'people.documents');
            }
        }
        if ($this->can($viewer, $employee, $relation, 'identity.records')) {
            foreach ($base('identity_documents')->get(['id', 'type', 'expiry_date']) as $d) {
                $add('identity:'.$d->id, ucfirst(str_replace('_', ' ', $d->type)), 'identity', substr($d->expiry_date, 0, 10), 'people.identity');
            }
        }
        if ($this->can($viewer, $employee, $relation, 'work_authorization.records')) {
            foreach ($base('work_authorizations')->where('status', 'active')->get(['id', 'type', 'expiry_date']) as $d) {
                $add('work_authorization:'.$d->id, ucfirst(str_replace('_', ' ', $d->type)), 'work_authorization', substr($d->expiry_date, 0, 10), 'people.work_authorizations');
            }
        }
        if ($this->can($viewer, $employee, $relation, 'certification.records')) {
            foreach ($base('employee_certifications')->get(['id', 'name', 'expiry_date']) as $d) {
                $add('certification:'.$d->id, $d->name, 'certification', substr($d->expiry_date, 0, 10), 'people.certifications');
            }
        }
        usort($items, fn ($a, $b) => $a['date'] <=> $b['date']);

        return $items ? ['key' => 'documents_expiring', 'title' => 'Documents expiring', 'order' => 40, 'items' => $items] : null;
    }

    private function pending(User $viewer, Employee $employee): ?array
    {
        $q = WorkItem::query()->where('status', 'open')->where(fn ($w) => $w->where('metadata->employee_uuid', $employee->uuid)->orWhere('source_entity', 'like', '%'.$employee->uuid.'%'));
        if (! $viewer->hasTenantScope()) {
            $q->where('assignee_id', $viewer->id); // same rule as My Work: you see your own tasks
        }
        $items = $q->orderBy('due_date')->limit(20)->get()->map(fn ($t) => $this->item('task:'.$t->id, $t->title, $t->priority, 'work.tasks', ['date' => $t->due_date?->toDateString(), 'href' => $t->action]))->all();

        return $items ? ['key' => 'pending_actions', 'title' => 'Pending actions', 'order' => 50, 'items' => $items] : null;
    }

    private function journey(User $viewer, Employee $employee, array $relation): ?array
    {
        $events = [];
        $dims = ['designation' => 'Designation', 'department' => 'Department', 'location' => 'Location', 'business_unit' => 'Business unit', 'legal_entity' => 'Legal entity', 'team' => 'Team', 'employee_type' => 'Employee type',
            'position' => 'Position', 'grade' => 'Grade', 'level' => 'Level', 'cost_center' => 'Cost center'];
        $previous = null;
        foreach ($this->assignments->history($employee) as $row) {
            $changes = [];
            foreach ($dims as $key => $label) {
                if (! $this->can($viewer, $employee, $relation, 'job.'.$key)) {
                    continue;
                }
                $new = $row[$key] ?? null;
                $old = $previous[$key] ?? null;
                if ($previous === null && $new) {
                    $changes[] = $label.': '.$new;
                } elseif ($previous !== null && $new !== $old) {
                    $changes[] = $label.': '.($old ?? 'none').' to '.($new ?? 'none');
                }
            }
            if ($changes) {
                $date = $row['effective_from'] instanceof \DateTimeInterface ? $row['effective_from']->format('Y-m-d') : (string) $row['effective_from'];
                $events[] = $this->item('assignment:'.$date, $previous === null ? 'Joined with job record' : ucfirst(str_replace('_', ' ', (string) $row['change_type'])), implode('; ', $changes), 'people.assignments', ['date' => $date]);
            }
            $previous = $row;
        }
        if ($this->can($viewer, $employee, $relation, 'employee.status')) {
            foreach (DB::table('employee_status_history')->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->id)->orderBy('effective_date')->orderBy('id')->get() as $s) {
                $events[] = $this->item('status:'.$s->id, 'Status', ($s->from_status ? $s->from_status.' to ' : '').$s->to_status, 'people.status_history', ['date' => substr($s->effective_date, 0, 10)]);
            }
        }
        if ($this->can($viewer, $employee, $relation, 'job.manager')) {
            foreach ($this->reporting->history($employee) as $m) {
                if ($m['type'] === 'primary') {
                    $events[] = $this->item('manager:'.$m['effective_from'], 'Reporting manager', $m['manager_name'], 'people.reporting', ['date' => $m['effective_from']]);
                }
            }
        }
        usort($events, fn ($a, $b) => $a['date'] <=> $b['date']);

        return $events ? ['key' => 'journey', 'title' => 'Employment journey', 'order' => 60, 'items' => $events] : null;
    }
}
