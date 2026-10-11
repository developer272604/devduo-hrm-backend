<?php

namespace App\Domain\People\Config;

use App\Domain\Access\Classification;
use App\Domain\Access\DataScope;
use App\Domain\Access\FieldAccess;
use App\Domain\Access\PeopleSettings;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\CustomFieldDefinition;
use App\Domain\People\Models\CustomFieldValue;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee-facing custom field engine (public API for other modules):
 *
 *   definitionsFor($employee)            active definitions that apply to the employee (country, employee type, section)
 *   valuesFor($viewer, $employee)        flat map key => value of the fields the viewer may see (masked where required)
 *   sectionsFor($viewer, $employee)      the same grouped by section with per-field metadata and an editable flag
 *   setValues($actor, $employee, [...])  permission-aware, validated, audited write (effective-dated where configured)
 *   historyFor($viewer, $employee, $key) value history of one field
 *   missingRequired($employee)           required fields that have no value (for completeness / data quality)
 *   applyDefaults($employee)             stores configured defaults for fields that have no value yet
 *
 * Values live only in custom_field_values as JSON text. Entity "employee" values are keyed by the employment,
 * entity "person" values by the person (shared across rehires).
 */
final class CustomFieldService
{
    private const RANK = [Classification::PUBLIC_INTERNAL => 0, Classification::EMPLOYEE => 1, Classification::MANAGER_SENSITIVE => 2, Classification::HR_CONFIDENTIAL => 3, Classification::HIGHLY_SENSITIVE => 4];

    public function __construct(private CustomFieldValidator $validator, private DataScope $scope, private FieldAccess $access, private DomainEvents $events) {}

    // ------------------------------------------------------------------ applicability

    /** @return Collection<int,CustomFieldDefinition> ordered by section then field order; section and options are loaded. */
    public function definitionsFor(Employee $employee, ?string $asOf = null): Collection
    {
        $asOf ??= TenantClock::today($employee->timezone);
        $typeId = $employee->assignmentOn($asOf)?->employee_type_id;
        $applies = fn (?array $countries, ?array $types) => (! $countries || in_array(strtoupper((string) $employee->country), array_map('strtoupper', $countries), true))
            && (! $types || ($typeId !== null && in_array($typeId, array_map('intval', $types), true)));

        return CustomFieldDefinition::query()->where('active', true)->with(['section', 'options'])->get()
            ->filter(fn (CustomFieldDefinition $d) => $applies($d->countries, $d->employee_types) && (! $d->section || ($d->section->active && $applies($d->section->countries, $d->section->employee_types))))
            ->sortBy([fn ($a, $b) => ($a->section?->sort ?? PHP_INT_MAX) <=> ($b->section?->sort ?? PHP_INT_MAX), fn ($a, $b) => ($a->section_id ?? PHP_INT_MAX) <=> ($b->section_id ?? PHP_INT_MAX),
                fn ($a, $b) => $a->sort <=> $b->sort, fn ($a, $b) => $a->id <=> $b->id])->values();
    }

    private function entityId(CustomFieldDefinition $def, Employee $employee): int
    {
        return $def->entity === 'person' ? $employee->person_id : $employee->id;
    }

    /**
     * Current value rows keyed by definition id (the row effective on $asOf; for effective-dated fields the latest start wins).
     *
     * @return array<int,CustomFieldValue>
     */
    private function currentRows(Employee $employee, Collection $defs, string $asOf): array
    {
        if ($defs->isEmpty()) {
            return [];
        }
        $rows = CustomFieldValue::query()->whereIn('definition_id', $defs->pluck('id'))
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('entity_type', 'employee')->where('entity_id', $employee->id))->orWhere(fn ($q) => $q->where('entity_type', 'person')->where('entity_id', $employee->person_id)))
            ->where('effective_from', '<=', $asOf)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $asOf))
            ->orderBy('effective_from')->get();
        $current = [];
        foreach ($rows as $row) {
            $current[$row->definition_id] = $row; // later start overrides earlier
        }

        return $current;
    }

    // ------------------------------------------------------------------ visibility and editability

    /** @return array{self:bool,manager:bool} */
    private function relation(User $viewer, Employee $employee): array
    {
        return $this->access->relation($viewer, $employee);
    }

    private function viewMode(User $viewer, Employee $employee, CustomFieldDefinition $def, array $relation): string
    {
        $section = $def->section;
        if ($section?->view_permission && ! $relation['self'] && ! $viewer->allows($section->view_permission)) {
            return FieldAccess::HIDDEN;
        }
        $modes = [$this->access->viewClass($viewer, $def->classification, $employee, $relation)];
        if ($section) {
            $modes[] = $this->access->viewClass($viewer, $section->classification, $employee, $relation);
        }

        return in_array(FieldAccess::HIDDEN, $modes, true) ? FieldAccess::HIDDEN : (in_array(FieldAccess::MASKED, $modes, true) ? FieldAccess::MASKED : FieldAccess::FULL);
    }

    /** direct | request | none: what the actor may do to the field right now. */
    private function editMode(User $actor, Employee $employee, CustomFieldDefinition $def, array $relation, string $viewMode, bool $hrCan): string
    {
        if ($viewMode !== FieldAccess::FULL) {
            return 'none';
        }
        if ($def->hr_editable && $hrCan) {
            return 'direct';
        }
        if ($def->manager_editable && $relation['manager']) {
            return 'direct';
        }
        if ($def->employee_editable && $relation['self']) {
            $rule = PeopleSettings::get('edit_rules.'.PeopleSettingsService::CUSTOM_GROUP) ?? 'direct';

            return in_array($rule, ['direct', 'request'], true) ? $rule : 'none';
        }

        return 'none';
    }

    private function hrCan(User $actor, Employee $employee): bool
    {
        return $actor->allows('people.manage') && $this->scope->canAccess($actor, $employee, 'people.manage');
    }

    // ------------------------------------------------------------------ reading

    /**
     * Fields the viewer may see, with their current value.
     *
     * @return array<int,array{def:CustomFieldDefinition,mode:string,row:?CustomFieldValue,edit:string}>
     */
    private function visible(User $viewer, Employee $employee): array
    {
        $asOf = TenantClock::today($employee->timezone);
        $defs = $this->definitionsFor($employee, $asOf);
        $relation = $this->relation($viewer, $employee);
        $hrCan = $this->hrCan($viewer, $employee);
        $rows = $this->currentRows($employee, $defs, $asOf);
        $out = [];
        foreach ($defs as $def) {
            $mode = $this->viewMode($viewer, $employee, $def, $relation);
            if ($mode === FieldAccess::HIDDEN) {
                continue;
            }
            $row = $rows[$def->id] ?? null;
            if ($row && $row->entity_type !== $def->entity) {
                $row = null;
            }
            $out[] = ['def' => $def, 'mode' => $mode, 'row' => $row, 'edit' => $this->editMode($viewer, $employee, $def, $relation, $mode, $hrCan)];
        }

        return $out;
    }

    private function shown(array $item): mixed
    {
        $value = $item['row']?->decoded();
        if ($item['mode'] === FieldAccess::MASKED) {
            return is_scalar($value) ? FieldAccess::mask((string) $value) : null;
        }

        return $value;
    }

    /** Flat map key => value for the fields the viewer may see (masked values are masked, hidden ones are absent). */
    public function valuesFor(User $viewer, Employee $employee): array
    {
        $out = [];
        foreach ($this->visible($viewer, $employee) as $item) {
            $out[$item['def']->key] = $this->shown($item);
        }

        return $out;
    }

    /** Sections with their visible fields, values and per-field editable flags (empty sections are omitted). */
    public function sectionsFor(User $viewer, Employee $employee): array
    {
        $sections = [];
        foreach ($this->visible($viewer, $employee) as $item) {
            /** @var CustomFieldDefinition $def */
            $def = $item['def'];
            $bucket = $def->section_id ?? 0;
            $sections[$bucket] ??= $def->section
                ? ['id' => $def->section->id, 'key' => $def->section->key, 'label' => $def->section->label, 'description' => $def->section->description, 'fields' => []]
                : ['id' => null, 'key' => null, 'label' => 'Additional information', 'description' => null, 'fields' => []];
            $value = $this->shown($item);
            $sections[$bucket]['fields'][] = [
                'id' => $def->id, 'key' => $def->key, 'label' => $def->label, 'description' => $def->description, 'type' => $def->type, 'entity' => $def->entity,
                'required' => $def->required, 'effective_dated' => $def->effective_dated, 'validation' => $def->validation ?: (object) [], 'default' => $def->defaultValue(),
                'options' => in_array($def->type, ['single_select', 'multi_select'], true) ? $def->options->where('active', true)->map(fn ($o) => ['value' => $o->value, 'label' => $o->label])->values()->all() : [],
                'value' => $value, 'display' => $item['mode'] === FieldAccess::FULL ? $this->validator->display($def, $value) : null, 'masked' => $item['mode'] === FieldAccess::MASKED,
                'effective_from' => $def->effective_dated ? $item['row']?->effective_from?->toDateString() : null,
                'editable' => $item['edit'] === 'direct', 'edit_mode' => $item['edit'],
            ];
        }

        return array_values($sections);
    }

    /** Value history of one field (any applicable or retired definition) as far as the viewer may see it. */
    public function historyFor(User $viewer, Employee $employee, string $key): array
    {
        $def = CustomFieldDefinition::query()->with(['section', 'options'])->where('key', $key)->first();
        abort_unless($def, 404);
        $mode = $this->viewMode($viewer, $employee, $def, $this->relation($viewer, $employee));
        abort_if($mode === FieldAccess::HIDDEN, 404);
        $rows = CustomFieldValue::query()->where('definition_id', $def->id)->where('entity_type', $def->entity)->where('entity_id', $this->entityId($def, $employee))
            ->orderByDesc('effective_from')->get();

        return $rows->map(function (CustomFieldValue $row) use ($mode, $def) {
            $value = $row->decoded();
            $shown = $mode === FieldAccess::MASKED ? (is_scalar($value) ? FieldAccess::mask((string) $value) : null) : $value;

            return ['effective_from' => $def->effective_dated ? $row->effective_from->toDateString() : null, 'effective_to' => $row->effective_to?->toDateString(), 'value' => $shown,
                'display' => $mode === FieldAccess::FULL ? $this->validator->display($def, $value) : null, 'masked' => $mode === FieldAccess::MASKED, 'updated_at' => $row->updated_at?->toIso8601String()];
        })->values()->all();
    }

    /** Required fields that apply to the employee but have no current value. Independent of any viewer (rule for completeness/data quality). */
    public function missingRequired(Employee $employee): array
    {
        $asOf = TenantClock::today($employee->timezone);
        $defs = $this->definitionsFor($employee, $asOf)->where('required', true);
        $rows = $this->currentRows($employee, $defs, $asOf);

        return $defs->filter(fn ($d) => ! isset($rows[$d->id]) || $rows[$d->id]->entity_type !== $d->entity || $rows[$d->id]->value === null)
            ->map(fn ($d) => ['id' => $d->id, 'key' => $d->key, 'label' => $d->label, 'section' => $d->section?->label, 'entity' => $d->entity])->values()->all();
    }

    // ------------------------------------------------------------------ writing

    /**
     * Sets values by field key. A value may be given directly or as {value, effective_from}; effective_from only matters
     * for effective-dated fields (default: $effectiveFrom, then today). A null/empty value clears the field.
     * Throws ValidationException (422) for invalid input, a 403 response listing the fields that are not editable by the actor
     * and a 409 (change_request_required) when tenant policy requires approval for the actor's own edits.
     *
     * @param  array<string,mixed>  $values
     */
    public function setValues(User $actor, Employee $employee, array $values, ?string $effectiveFrom = null): void
    {
        abort_if($values === [], 422, 'No changes were submitted.');
        if ($effectiveFrom !== null) {
            $this->assertDate($effectiveFrom, 'effective_from');
        }
        $defs = $this->definitionsFor($employee)->keyBy('key');
        $errors = [];
        foreach (array_keys($values) as $key) {
            if (! $defs->has((string) $key)) {
                $errors['values.'.$key] = ['This field does not exist or does not apply to this employee.'];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $relation = $this->relation($actor, $employee);
        $hrCan = $this->hrCan($actor, $employee);
        $blocked = [];
        $needsRequest = [];
        foreach ($values as $key => $_) {
            $def = $defs[$key];
            $edit = $this->editMode($actor, $employee, $def, $relation, $this->viewMode($actor, $employee, $def, $relation), $hrCan);
            if ($edit === 'none') {
                $blocked[] = ['key' => $def->key, 'label' => $def->label];
            } elseif ($edit === 'request') {
                $needsRequest[] = ['key' => $def->key, 'label' => $def->label];
            }
        }
        if ($blocked) {
            throw new HttpResponseException(response()->json(['message' => 'You cannot edit: '.implode(', ', array_column($blocked, 'label')).'.', 'code' => 'fields_not_editable', 'fields' => $blocked], 403));
        }
        if ($needsRequest) {
            throw new HttpResponseException(response()->json(['message' => 'These changes need approval before they apply: '.implode(', ', array_column($needsRequest, 'label')).'.', 'code' => 'change_request_required', 'fields' => $needsRequest], 409));
        }

        $plan = [];
        foreach ($values as $key => $raw) {
            $def = $defs[$key];
            $from = $effectiveFrom;
            if (is_array($raw) && ! array_is_list($raw) && array_key_exists('value', $raw)) {
                $from = $raw['effective_from'] ?? $from;
                $raw = $raw['value'];
                if ($from !== null) {
                    $this->assertDate($from, 'values.'.$key.'.effective_from');
                }
            }
            [$value, $error] = $this->validator->normalize($def, $raw, $employee);
            if ($error) {
                $errors['values.'.$key] = [$def->label.': '.$error];
            } elseif ($value === null && $def->required) {
                $errors['values.'.$key] = [$def->label.' is required.'];
            }
            $plan[] = [$def, $value, $def->effective_dated ? ($from ?? TenantClock::today($employee->timezone)) : CustomFieldValue::ORIGIN];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($actor, $employee, $plan) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $changed = [];
            $audit = [];
            foreach ($plan as [$def, $value, $from]) {
                $before = $this->write($def, $employee, $value, $from);
                if ($before !== false) {
                    $changed[] = $def;
                    if ($def->classification === Classification::PUBLIC_INTERNAL) {
                        $audit[$def->key] = ['before' => $before, 'after' => $value];
                    }
                }
            }
            if (! $changed) {
                return;
            }
            $labels = array_map(fn ($d) => $d->label, $changed);
            $class = collect($changed)->map(fn ($d) => $d->classification)->push(...collect($changed)->map(fn ($d) => $d->section?->classification)->filter())
                ->sortByDesc(fn ($c) => self::RANK[$c] ?? 0)->first();
            $personOnly = collect($changed)->every(fn ($d) => $d->entity === 'person');
            $this->events->record($personOnly ? 'PersonalInformationUpdated' : 'EmployeeUpdated', [
                'employee' => $employee, 'subject' => $personOnly ? ['person', $employee->person_id] : ['employee', $employee->id], 'actor_id' => $actor->id, 'classification' => $class,
                'summary' => 'Custom fields updated: '.implode(', ', $labels).'.', 'payload' => ['custom_fields' => array_map(fn ($d) => $d->key, $changed)],
            ]);
            Audit::record('updated', 'people', 'employee:'.$employee->uuid, ['custom_fields' => array_map(fn ($d) => $d->key, $changed), 'values' => $audit]);
        });
    }

    /**
     * Stores configured default values for applicable fields that have no stored value yet (e.g. after employee creation).
     * No permission check: this is a system action. Returns the number of fields initialised.
     */
    public function applyDefaults(Employee $employee): int
    {
        $asOf = TenantClock::today($employee->timezone);
        $defs = $this->definitionsFor($employee, $asOf)->filter(fn ($d) => $d->default_value !== null);
        $rows = $this->currentRows($employee, $defs, $asOf);
        $count = 0;
        DB::transaction(function () use ($employee, $defs, $rows, $asOf, &$count) {
            foreach ($defs as $def) {
                if (isset($rows[$def->id]) && $rows[$def->id]->entity_type === $def->entity) {
                    continue;
                }
                [$value, $error] = $this->validator->normalize($def, $def->defaultValue(), $employee);
                if ($error === null && $value !== null) {
                    $this->write($def, $employee, $value, $def->effective_dated ? $asOf : CustomFieldValue::ORIGIN);
                    $count++;
                }
            }
        });

        return $count;
    }

    /**
     * Writes one value. Returns the previous value when something changed, or false when nothing changed.
     * Non-dated fields keep a single row; effective-dated fields close the covering row and insert a new one
     * (history is never overwritten), ending before any future-dated row.
     */
    private function write(CustomFieldDefinition $def, Employee $employee, mixed $value, string $from): mixed
    {
        $entityId = $this->entityId($def, $employee);
        $json = $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $base = CustomFieldValue::query()->where('definition_id', $def->id)->where('entity_type', $def->entity)->where('entity_id', $entityId);

        if (! $def->effective_dated) {
            $row = (clone $base)->where('effective_from', CustomFieldValue::ORIGIN)->first();
            if (($row?->value) === $json) {
                return false;
            }
            $before = $row?->decoded();
            if ($json === null) {
                $row?->delete();
            } elseif ($row) {
                $row->update(['value' => $json]);
            } else {
                CustomFieldValue::create(['definition_id' => $def->id, 'entity_type' => $def->entity, 'entity_id' => $entityId, 'value' => $json, 'effective_from' => CustomFieldValue::ORIGIN]);
            }

            return $before;
        }

        $rows = (clone $base)->orderBy('effective_from')->get();
        $covering = $rows->filter(fn ($r) => $r->effective_from->toDateString() <= $from)->last();
        if ($covering && $covering->effective_from->toDateString() === $from) {
            if ($covering->value === $json) {
                return false;
            }
            $before = $covering->decoded();
            $covering->update(['value' => $json]);

            return $before;
        }
        if ($covering && $covering->value === $json && ($covering->effective_to === null || $covering->effective_to->toDateString() >= $from)) {
            return false; // unchanged since the covering row
        }
        $next = $rows->first(fn ($r) => $r->effective_from->toDateString() > $from);
        if ($covering && ($covering->effective_to === null || $covering->effective_to->toDateString() >= $from)) {
            $covering->update(['effective_to' => CarbonImmutable::parse($from)->subDay()->toDateString()]);
        }
        CustomFieldValue::create(['definition_id' => $def->id, 'entity_type' => $def->entity, 'entity_id' => $entityId, 'value' => $json, 'effective_from' => $from,
            'effective_to' => $next ? CarbonImmutable::parse($next->effective_from->toDateString())->subDay()->toDateString() : null]);

        return $covering?->decoded();
    }

    private function assertDate(string $value, string $field): void
    {
        $date = \DateTime::createFromFormat('!Y-m-d', $value);
        if (! $date || $date->format('Y-m-d') !== $value || $date->format('Y') < 1900) {
            throw ValidationException::withMessages([$field => ['Use a date in YYYY-MM-DD format.']]);
        }
    }
}
