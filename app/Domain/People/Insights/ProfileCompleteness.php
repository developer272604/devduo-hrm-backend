<?php

namespace App\Domain\People\Insights;

use App\Domain\Access\DataScope;
use App\Domain\Access\FieldRegistry;
use App\Domain\Access\PeopleSettings;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Configurable profile completeness. No invented score: a profile is complete when every ENABLED rule the viewer is
 * allowed to see is satisfied; the "percentage" is satisfied / total_rules over those rules and is only meaningful next to the list
 * of missing items. Rules the viewer may not see (e.g. bank for a manager) are left out entirely - neither counted nor listed - so
 * nobody learns that sensitive data exists or is missing. Enabled rules: settings.people.completeness.required (default: all);
 * required custom fields are added automatically unless settings.people.completeness.include_custom_fields is false.
 */
final class ProfileCompleteness
{
    /** key => [label, registry field (visibility), profile section, action label] */
    public const RULES = [
        'emergency_contact' => ['Emergency contact', 'emergency.records', 'emergency', 'Add an emergency contact'],
        'address' => ['Address', 'address.records', 'address', 'Add an address'],
        'bank_account' => ['Bank account', 'bank.records', 'bank', 'Add bank details'],
        'identity_document' => ['Identity document', 'identity.records', 'identity', 'Add an identity document'],
        'photo' => ['Profile photo', 'person.photo', 'personal', 'Upload a profile photo'],
        'mandatory_documents' => ['Mandatory documents', 'document.records', 'documents', 'Upload the missing documents'],
        'manager' => ['Reporting manager', 'job.manager', 'job', 'Assign a reporting manager'],
        'work_email' => ['Work email', 'employee.work_email', 'employment', 'Add a work email'],
    ];

    public function __construct(private DataScope $scope, private MandatoryDocuments $documents) {}

    /** @return array<int,string> */
    public function enabledRules(): array
    {
        $configured = (array) PeopleSettings::get('completeness.required', array_keys(self::RULES));

        return array_values(array_filter(array_keys(self::RULES), fn ($k) => in_array($k, $configured, true)));
    }

    /** Completeness of one employee as seen by the viewer. */
    public function for(Employee $employee, User $viewer): array
    {
        $row = $this->rowsQuery($employee->tenant_id)->where('e.id', $employee->id)->first();

        return $row ? $this->evaluate($viewer, ViewerRelations::for($viewer), [$row])[$row->id] : ['complete' => true, 'missing' => [], 'total_rules' => 0, 'satisfied' => 0, 'percentage' => null];
    }

    /**
     * Streams [row, result] for every working employee in the viewer's data scope.
     *
     * @return \Generator<int,array{0:object,1:array}>
     */
    public function scan(User $viewer, string $permission = 'people.quality.view', ?ViewerRelations $relations = null): \Generator
    {
        $relations ??= ViewerRelations::for($viewer);
        $query = $this->rowsQuery($viewer->tenant_id)->whereIn('e.status', Employee::WORKING);
        $this->scope->constrain($query, $viewer, $permission, 'e.id');
        $last = 0;
        while (true) {
            $rows = (clone $query)->where('e.id', '>', $last)->orderBy('e.id')->limit(500)->get()->all();
            if (! $rows) {
                return;
            }
            $results = $this->evaluate($viewer, $relations, $rows);
            foreach ($rows as $row) {
                yield [$row, $results[$row->id]];
            }
            $last = end($rows)->id;
        }
    }

    /** @return array{employees:int,incomplete:int,items:array<int,array{key:string,label:string,count:int}>} */
    public function summary(User $viewer): array
    {
        $counts = [];
        $employees = 0;
        $incomplete = 0;
        foreach ($this->scan($viewer) as [, $result]) {
            $employees++;
            if (! $result['complete']) {
                $incomplete++;
            }
            foreach ($result['missing'] as $m) {
                $counts[$m['key']] = ($counts[$m['key']] ?? 0) + 1;
            }
        }
        $items = [];
        foreach ($counts as $key => $count) {
            $items[] = ['key' => $key, 'label' => $this->label($key), 'count' => $count];
        }
        usort($items, fn ($a, $b) => $b['count'] <=> $a['count']);

        return ['employees' => $employees, 'incomplete' => $incomplete, 'items' => $items];
    }

    private function label(string $key): string
    {
        return self::RULES[$key][0] ?? (DB::table('custom_field_definitions')->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->where('key', substr($key, 7))->value('label') ?? $key);
    }

    private function rowsQuery(int $tenantId)
    {
        $today = TenantClock::today();

        return DB::table('employees as e')->join('people as p', fn ($j) => $j->on('p.id', '=', 'e.person_id')->whereNull('p.deleted_at'))
            ->leftJoin('employee_assignments as a', function ($j) use ($today) {
                $j->on('a.employee_id', '=', 'e.id')->where('a.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('a.effective_to')->orWhere('a.effective_to', '>=', $today));
            })->where('e.tenant_id', $tenantId)->whereNull('e.deleted_at')
            ->select(['e.id as id', 'e.uuid', 'e.person_id', 'e.country', 'e.work_email', 'p.photo_path', 'a.employee_type_id']);
    }

    /**
     * @param  array<int,object>  $rows
     * @return array<int,array{complete:bool,missing:array<int,array<string,mixed>>,total_rules:int,satisfied:int,percentage:?int}>
     */
    private function evaluate(User $viewer, ViewerRelations $rel, array $rows): array
    {
        $tenant = $viewer->tenant_id;
        $enabled = $this->enabledRules();
        $people = array_values(array_unique(array_map(fn ($r) => $r->person_id, $rows)));
        $ids = array_map(fn ($r) => $r->id, $rows);
        $sets = [];
        $personSet = fn (string $table, ?callable $extra = null) => DB::table($table)->where('tenant_id', $tenant)->whereIn('person_id', $people)->when($extra, fn ($q) => $extra($q))->distinct()->pluck('person_id')->flip()->all();
        if (in_array('emergency_contact', $enabled, true)) {
            $sets['emergency_contact'] = $personSet('employee_emergency_contacts');
        }
        if (in_array('address', $enabled, true)) {
            $sets['address'] = $personSet('employee_addresses');
        }
        if (in_array('bank_account', $enabled, true)) {
            $sets['bank_account'] = $personSet('employee_bank_accounts', fn ($q) => $q->where('status', 'active'));
        }
        if (in_array('identity_document', $enabled, true)) {
            $sets['identity_document'] = $personSet('identity_documents');
        }
        if (in_array('manager', $enabled, true)) {
            $today = TenantClock::today();
            $sets['manager'] = DB::table('employee_reporting_relationships')->where('tenant_id', $tenant)->whereIn('employee_id', $ids)->where('type', 'primary')->where('effective_from', '<=', $today)
                ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $today))->pluck('employee_id')->flip()->all();
        }
        $missingDocs = in_array('mandatory_documents', $enabled, true) ? $this->documents->missing($tenant, $rows) : [];
        $custom = $this->customDefinitions($tenant);
        $customValues = $custom ? $this->customValues($tenant, $custom, $rows) : [];

        $out = [];
        foreach ($rows as $row) {
            $relation = $rel->relation($row->id);
            $total = 0;
            $missing = [];
            foreach ($enabled as $key) {
                [$label, $field, $section, $action] = self::RULES[$key];
                if ($rel->visibility(PeopleSettings::classification($field), $relation, FieldRegistry::privacyKey($field)) === 'hidden') {
                    continue;
                }
                $total++;
                $ok = match ($key) {
                    'photo' => (bool) $row->photo_path,
                    'work_email' => trim((string) $row->work_email) !== '',
                    'manager' => isset($sets['manager'][$row->id]),
                    'mandatory_documents' => ! isset($missingDocs[$row->id]),
                    default => isset($sets[$key][$row->person_id]),
                };
                if (! $ok) {
                    $missing[] = ['key' => $key, 'label' => $label, 'detail' => $key === 'mandatory_documents' ? implode(', ', $missingDocs[$row->id]) : null,
                        'action' => ['label' => $action, 'kind' => 'navigate', 'href' => '/employees/'.$row->uuid.'?section='.$section]];
                }
            }
            foreach ($custom as $def) {
                if (! $this->customApplies($def, $row) || $rel->visibility($def->classification, $relation) === 'hidden') {
                    continue;
                }
                $total++;
                $entity = $def->entity === 'person' ? $row->person_id : $row->id;
                if (! isset($customValues[$def->id.':'.$def->entity.':'.$entity])) {
                    $missing[] = ['key' => 'custom:'.$def->key, 'label' => $def->label, 'detail' => null, 'action' => ['label' => 'Complete '.$def->label, 'kind' => 'navigate', 'href' => '/employees/'.$row->uuid.'?section=custom']];
                }
            }
            $out[$row->id] = ['complete' => ! $missing, 'missing' => $missing, 'total_rules' => $total, 'satisfied' => $total - count($missing), 'percentage' => $total ? (int) round(($total - count($missing)) / $total * 100) : null];
        }

        return $out;
    }

    private function customDefinitions(int $tenant): array
    {
        if (! PeopleSettings::get('completeness.include_custom_fields', true)) {
            return [];
        }

        return DB::table('custom_field_definitions')->where('tenant_id', $tenant)->where('required', true)->where('active', true)->get(['id', 'key', 'label', 'entity', 'classification', 'countries', 'employee_types'])->all();
    }

    private function customApplies(object $def, object $row): bool
    {
        $countries = array_map('strtoupper', (array) json_decode((string) $def->countries, true));
        $types = array_map('strval', (array) json_decode((string) $def->employee_types, true));

        return (! $countries || in_array(strtoupper((string) $row->country), $countries, true)) && (! $types || in_array((string) $row->employee_type_id, $types, true));
    }

    /** @return array<string,true> "definition:entity:entityId" for every current, non-empty value. */
    private function customValues(int $tenant, array $defs, array $rows): array
    {
        $today = TenantClock::today();
        $out = [];
        $q = DB::table('custom_field_values')->where('tenant_id', $tenant)->whereIn('definition_id', array_map(fn ($d) => $d->id, $defs))->where('effective_from', '<=', $today)
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->where(fn ($w) => $w->where(fn ($e) => $e->where('entity_type', 'employee')->whereIn('entity_id', array_map(fn ($r) => $r->id, $rows)))
                ->orWhere(fn ($p) => $p->where('entity_type', 'person')->whereIn('entity_id', array_map(fn ($r) => $r->person_id, $rows))));
        foreach ($q->get(['definition_id', 'entity_type', 'entity_id', 'value']) as $v) {
            if ($v->value !== null && ! in_array(trim((string) $v->value), ['', 'null', '""', '[]'], true)) {
                $out[$v->definition_id.':'.$v->entity_type.':'.$v->entity_id] = true;
            }
        }

        return $out;
    }
}
