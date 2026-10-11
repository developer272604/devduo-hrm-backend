<?php

namespace App\Domain\People\Config;

use App\Domain\Access\Classification;
use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Audit\Audit;
use App\Domain\People\Models\CustomFieldDefinition;
use App\Domain\People\Models\CustomFieldOption;
use App\Domain\People\Models\CustomFieldValue;
use App\Domain\People\Models\CustomSection;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administration of custom sections, custom field definitions and select options. Everything is validated against a
 * whitelist (no mass assignment), keys/entity/type are immutable once created, and nothing here ever alters the schema.
 */
final class CustomFieldAdmin
{
    public const MAX_DEFINITIONS = 300;

    public const MAX_SECTIONS = 50;

    public const MAX_OPTIONS = 200;

    public const SELECT_TYPES = ['single_select', 'multi_select'];

    /** Classes assignable to custom data (system_restricted would hide the data from everybody). */
    private const CLASSES = [Classification::PUBLIC_INTERNAL, Classification::EMPLOYEE, Classification::MANAGER_SENSITIVE, Classification::HR_CONFIDENTIAL, Classification::HIGHLY_SENSITIVE];

    private const KEY = '/^[a-z][a-z0-9_]{1,59}$/';

    public function __construct(private CustomFieldValidator $validator) {}

    private function tenant(): int
    {
        return app(TenantContext::class)->id;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    // ------------------------------------------------------------------ presentation

    public function presentSection(CustomSection $section): array
    {
        return ['id' => $section->id, 'key' => $section->key, 'label' => $section->label, 'description' => $section->description, 'countries' => $section->countries, 'employee_types' => $section->employee_types,
            'view_permission' => $section->view_permission, 'classification' => $section->classification, 'sort' => $section->sort, 'active' => $section->active,
            'field_count' => CustomFieldDefinition::query()->where('section_id', $section->id)->count(), 'created_at' => $section->created_at?->toIso8601String(), 'updated_at' => $section->updated_at?->toIso8601String()];
    }

    public function present(CustomFieldDefinition $def, ?bool $inUse = null): array
    {
        $def->loadMissing(['section', 'options']);

        return ['id' => $def->id, 'entity' => $def->entity, 'key' => $def->key, 'label' => $def->label, 'description' => $def->description, 'type' => $def->type,
            'section' => $def->section ? ['id' => $def->section->id, 'key' => $def->section->key, 'label' => $def->section->label] : null,
            'required' => $def->required, 'default' => $def->defaultValue(), 'validation' => $def->validation ?: (object) [], 'classification' => $def->classification,
            'employee_editable' => $def->employee_editable, 'manager_editable' => $def->manager_editable, 'hr_editable' => $def->hr_editable,
            'countries' => $def->countries, 'employee_types' => $def->employee_types, 'effective_dated' => $def->effective_dated, 'active' => $def->active, 'sort' => $def->sort,
            'options' => $def->options->map(fn ($o) => $this->presentOption($o))->values()->all(),
            'has_values' => $inUse ?? CustomFieldValue::query()->where('definition_id', $def->id)->exists(),
            'created_at' => $def->created_at?->toIso8601String(), 'updated_at' => $def->updated_at?->toIso8601String()];
    }

    public function presentOption(CustomFieldOption $option): array
    {
        return ['id' => $option->id, 'value' => $option->value, 'label' => $option->label, 'sort' => $option->sort, 'active' => $option->active];
    }

    public function catalogue(): array
    {
        return [
            'types' => CustomFieldValidator::TYPES, 'entities' => ['employee', 'person'], 'classifications' => array_map(fn ($c) => ['value' => $c, 'label' => Classification::LABELS[$c]], self::CLASSES),
            'validation_rules' => ['text' => ['min_length', 'max_length', 'pattern'], 'long_text' => ['min_length', 'max_length', 'pattern'], 'email' => ['max_length'], 'phone' => ['max_length'], 'url' => ['max_length'],
                'number' => ['min', 'max', 'decimals'], 'currency' => ['min', 'max', 'decimals', 'currency'], 'date' => ['min', 'max'], 'datetime' => ['min', 'max'], 'multi_select' => ['min', 'max']],
            'permissions' => array_keys(WorkspaceProvisioner::PERMISSIONS), 'limits' => ['definitions' => self::MAX_DEFINITIONS, 'sections' => self::MAX_SECTIONS, 'options' => self::MAX_OPTIONS, 'pattern_length' => CustomFieldValidator::MAX_PATTERN_LENGTH],
        ];
    }

    // ------------------------------------------------------------------ shared rules

    private function applicability(array $data): array
    {
        $out = [];
        if (array_key_exists('countries', $data)) {
            $out['countries'] = $data['countries'] ? array_values(array_unique(array_map('strtoupper', $data['countries']))) : null;
            foreach ($out['countries'] ?? [] as $country) {
                Countries::valid($country) || $this->fail('countries', "\"$country\" is not a valid two-letter country code.");
            }
        }
        if (array_key_exists('employee_types', $data)) {
            $ids = $data['employee_types'] ? array_values(array_unique(array_map('intval', $data['employee_types']))) : null;
            if ($ids) {
                $found = DB::table('employee_types')->where('tenant_id', $this->tenant())->whereIn('id', $ids)->count();
                $found === count($ids) || $this->fail('employee_types', 'One or more employee types do not exist in this company.');
            }
            $out['employee_types'] = $ids;
        }

        return $out;
    }

    private function applicabilityRules(): array
    {
        return ['countries' => ['sometimes', 'nullable', 'array', 'max:300'], 'countries.*' => ['string', 'size:2'], 'employee_types' => ['sometimes', 'nullable', 'array', 'max:100'], 'employee_types.*' => ['integer']];
    }

    // ------------------------------------------------------------------ sections

    public function createSection(array $input): CustomSection
    {
        $data = Validator::make($input, [
            'key' => ['required', 'regex:'.self::KEY, Rule::unique('custom_sections', 'key')->where('tenant_id', $this->tenant())], 'label' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:1000'],
            'view_permission' => ['nullable', Rule::in(array_keys(WorkspaceProvisioner::PERMISSIONS))], 'classification' => ['sometimes', Rule::in(self::CLASSES)],
            'sort' => ['sometimes', 'integer', 'between:0,65535'], 'active' => ['sometimes', 'boolean'],
        ] + $this->applicabilityRules(), ['key.regex' => 'Use a key of 2 to 60 lowercase letters, digits or underscores, starting with a letter.', 'key.unique' => 'A section with this key already exists.',
            'view_permission.in' => 'Unknown permission key.', 'classification.in' => 'Choose a valid data classification.'])->validate();
        abort_if(CustomSection::query()->count() >= self::MAX_SECTIONS, 422, 'You have reached the limit of '.self::MAX_SECTIONS.' custom sections.');

        return DB::transaction(function () use ($data, $input) {
            $section = CustomSection::create(array_merge(['classification' => Classification::EMPLOYEE, 'sort' => 0, 'active' => true], collect($data)->only(['key', 'label', 'description', 'view_permission', 'classification', 'sort', 'active'])->all(), $this->applicability($input)));
            Audit::record('created', 'people', 'custom-section:'.$section->id, ['key' => $section->key, 'label' => $section->label]);

            return $section;
        });
    }

    public function updateSection(CustomSection $section, array $input): CustomSection
    {
        if (array_key_exists('key', $input) && $input['key'] !== $section->key) {
            $this->fail('key', 'A section key cannot be changed once created.');
        }
        $data = Validator::make($input, [
            'label' => ['sometimes', 'required', 'string', 'max:150'], 'description' => ['sometimes', 'nullable', 'string', 'max:1000'], 'view_permission' => ['sometimes', 'nullable', Rule::in(array_keys(WorkspaceProvisioner::PERMISSIONS))],
            'classification' => ['sometimes', Rule::in(self::CLASSES)], 'sort' => ['sometimes', 'integer', 'between:0,65535'], 'active' => ['sometimes', 'boolean'],
        ] + $this->applicabilityRules(), ['view_permission.in' => 'Unknown permission key.', 'classification.in' => 'Choose a valid data classification.'])->validate();

        return DB::transaction(function () use ($section, $data, $input) {
            $before = $section->only(['label', 'description', 'view_permission', 'classification', 'sort', 'active', 'countries', 'employee_types']);
            $section->fill(array_merge(collect($data)->only(['label', 'description', 'view_permission', 'classification', 'sort', 'active'])->all(), $this->applicability($input)))->save();
            Audit::record('updated', 'people', 'custom-section:'.$section->id, ['key' => $section->key, 'before' => $before, 'after' => $section->only(array_keys($before))]);

            return $section;
        });
    }

    public function deleteSection(CustomSection $section): void
    {
        abort_if(CustomFieldDefinition::query()->where('section_id', $section->id)->exists(), 422, 'This section still contains fields. Move or delete them first, or deactivate the section instead.');
        DB::transaction(function () use ($section) {
            $section->delete();
            Audit::record('deleted', 'people', 'custom-section:'.$section->id, ['key' => $section->key]);
        });
    }

    // ------------------------------------------------------------------ definitions

    public function createField(array $input): CustomFieldDefinition
    {
        $data = Validator::make($input, [
            'entity' => ['required', Rule::in(['employee', 'person'])], 'key' => ['required', 'regex:'.self::KEY], 'label' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', Rule::in(CustomFieldValidator::TYPES)], 'section_id' => ['nullable', 'integer', Rule::exists('custom_sections', 'id')->where('tenant_id', $this->tenant())],
            'required' => ['sometimes', 'boolean'], 'default' => ['sometimes', 'nullable'], 'validation' => ['sometimes', 'nullable', 'array'], 'classification' => ['sometimes', Rule::in(self::CLASSES)],
            'employee_editable' => ['sometimes', 'boolean'], 'manager_editable' => ['sometimes', 'boolean'], 'hr_editable' => ['sometimes', 'boolean'], 'effective_dated' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'], 'sort' => ['sometimes', 'integer', 'between:0,65535'], 'options' => ['sometimes', 'array', 'max:'.self::MAX_OPTIONS],
        ] + $this->applicabilityRules(), ['key.regex' => 'Use a key of 2 to 60 lowercase letters, digits or underscores, starting with a letter.', 'type.in' => 'Choose a supported field type.', 'entity.in' => 'Entity must be employee or person.',
            'section_id.exists' => 'Choose a section from this company.', 'classification.in' => 'Choose a valid data classification.'])->validate();

        // Keys are unique per tenant across both entities so API payloads ({key: value}) are never ambiguous.
        if (CustomFieldDefinition::query()->where('key', $data['key'])->exists()) {
            $this->fail('key', 'A custom field with this key already exists.');
        }
        abort_if(CustomFieldDefinition::query()->count() >= self::MAX_DEFINITIONS, 422, 'You have reached the limit of '.self::MAX_DEFINITIONS.' custom fields.');
        $this->checkRules($data['type'], $data['validation'] ?? null);
        $options = $data['options'] ?? [];
        if ($options && ! in_array($data['type'], self::SELECT_TYPES, true)) {
            $this->fail('options', 'Only single select and multi select fields have options.');
        }

        return DB::transaction(function () use ($data, $input, $options) {
            $def = CustomFieldDefinition::create(array_merge(['classification' => Classification::EMPLOYEE, 'hr_editable' => true, 'sort' => 0, 'active' => true],
                collect($data)->only(['entity', 'key', 'label', 'description', 'type', 'section_id', 'required', 'classification', 'employee_editable', 'manager_editable', 'hr_editable', 'effective_dated', 'active', 'sort'])->all(),
                $this->applicability($input), ['validation' => $data['validation'] ?? null]));
            $seen = [];
            foreach ($options as $index => $option) {
                $this->createOption($def, $option, $seen, "options.$index");
            }
            $def->load('options');
            $this->storeDefault($def, $data, array_key_exists('default', $data));
            Audit::record('created', 'people', 'custom-field:'.$def->id, ['key' => $def->key, 'type' => $def->type, 'entity' => $def->entity, 'classification' => $def->classification]);

            return $def->refresh();
        });
    }

    public function updateField(CustomFieldDefinition $def, array $input): CustomFieldDefinition
    {
        foreach (['key', 'entity', 'type'] as $immutable) {
            if (array_key_exists($immutable, $input) && $input[$immutable] !== $def->{$immutable}) {
                $this->fail($immutable, 'A custom field '.$immutable.' cannot be changed once created. Create a new field instead.');
            }
        }
        $data = Validator::make($input, [
            'label' => ['sometimes', 'required', 'string', 'max:150'], 'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'section_id' => ['sometimes', 'nullable', 'integer', Rule::exists('custom_sections', 'id')->where('tenant_id', $this->tenant())], 'required' => ['sometimes', 'boolean'], 'default' => ['sometimes', 'nullable'],
            'validation' => ['sometimes', 'nullable', 'array'], 'classification' => ['sometimes', Rule::in(self::CLASSES)], 'employee_editable' => ['sometimes', 'boolean'], 'manager_editable' => ['sometimes', 'boolean'],
            'hr_editable' => ['sometimes', 'boolean'], 'effective_dated' => ['sometimes', 'boolean'], 'active' => ['sometimes', 'boolean'], 'sort' => ['sometimes', 'integer', 'between:0,65535'],
        ] + $this->applicabilityRules(), ['section_id.exists' => 'Choose a section from this company.', 'classification.in' => 'Choose a valid data classification.'])->validate();
        if (array_key_exists('validation', $data)) {
            $this->checkRules($def->type, $data['validation']);
        }
        if (array_key_exists('effective_dated', $data) && (bool) $data['effective_dated'] !== $def->effective_dated && CustomFieldValue::query()->where('definition_id', $def->id)->exists()) {
            $this->fail('effective_dated', 'Effective dating cannot be switched once values exist.');
        }

        return DB::transaction(function () use ($def, $data, $input) {
            $before = $def->only(['label', 'section_id', 'required', 'classification', 'employee_editable', 'manager_editable', 'hr_editable', 'effective_dated', 'active', 'sort', 'countries', 'employee_types', 'validation']);
            $def->fill(array_merge(collect($data)->only(['label', 'description', 'section_id', 'required', 'classification', 'employee_editable', 'manager_editable', 'hr_editable', 'effective_dated', 'active', 'sort', 'validation'])->all(), $this->applicability($input)))->save();
            $def->load('options');
            if (array_key_exists('default', $data)) {
                $this->storeDefault($def, $data, true);
            } elseif ($def->default_value !== null && array_key_exists('validation', $data)) {
                $this->storeDefault($def, ['default' => $def->defaultValue()], true);
            }
            Audit::record('updated', 'people', 'custom-field:'.$def->id, ['key' => $def->key, 'before' => $before, 'after' => $def->only(array_keys($before))]);

            return $def->refresh();
        });
    }

    public function deleteField(CustomFieldDefinition $def): void
    {
        abort_if(CustomFieldValue::query()->where('definition_id', $def->id)->exists(), 422, 'This field has stored values and cannot be deleted. Deactivate it to hide it while keeping the data.');
        DB::transaction(function () use ($def) {
            CustomFieldOption::query()->where('definition_id', $def->id)->delete();
            $def->delete();
            Audit::record('deleted', 'people', 'custom-field:'.$def->id, ['key' => $def->key]);
        });
    }

    private function checkRules(string $type, mixed $validation): void
    {
        if ($error = CustomFieldValidator::rulesError($type, $validation)) {
            $this->fail('validation', $error);
        }
    }

    /** Validates the default through the same engine as real values, then stores it as JSON text. */
    private function storeDefault(CustomFieldDefinition $def, array $data, bool $given): void
    {
        if (! $given) {
            return;
        }
        $raw = $data['default'] ?? null;
        if ($raw === null || $raw === '') {
            $def->default_value = null;
        } else {
            [$value, $error] = $this->validator->normalize($def, $raw);
            $error && $this->fail('default', 'The default value is invalid: '.$error);
            $def->default_value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $def->save();
    }

    // ------------------------------------------------------------------ options

    /** @param array<string,bool> $seen values already used in this request (for duplicate detection) */
    private function createOption(CustomFieldDefinition $def, array $input, array &$seen, string $prefix = ''): CustomFieldOption
    {
        $data = Validator::make($input, ['value' => ['sometimes', 'nullable', 'regex:/^[a-z0-9][a-z0-9_\-]{0,119}$/'], 'label' => ['required', 'string', 'max:150'], 'sort' => ['sometimes', 'integer', 'between:0,65535'], 'active' => ['sometimes', 'boolean']],
            ['value.regex' => 'An option value may use lowercase letters, digits, - and _ (up to 120 characters).', 'label.required' => 'Each option needs a label.'])->validate();
        $value = $data['value'] ?? null ?: (Str::slug($data['label'], '_') ?: 'option');
        $value = substr($value, 0, 120);
        $key = ($prefix ?: 'value');
        if (isset($seen[$value]) || CustomFieldOption::query()->where('definition_id', $def->id)->where('value', $value)->exists()) {
            $this->fail($key, "An option with the value \"$value\" already exists.");
        }
        abort_if(CustomFieldOption::query()->where('definition_id', $def->id)->count() >= self::MAX_OPTIONS, 422, 'A field can have at most '.self::MAX_OPTIONS.' options.');
        $seen[$value] = true;

        return CustomFieldOption::create(['definition_id' => $def->id, 'value' => $value, 'label' => $data['label'], 'sort' => $data['sort'] ?? ((int) CustomFieldOption::query()->where('definition_id', $def->id)->max('sort') + 10), 'active' => $data['active'] ?? true]);
    }

    public function addOption(CustomFieldDefinition $def, array $input): CustomFieldOption
    {
        abort_unless(in_array($def->type, self::SELECT_TYPES, true), 422, 'Only single select and multi select fields have options.');

        return DB::transaction(function () use ($def, $input) {
            $seen = [];
            $option = $this->createOption($def, $input, $seen);
            Audit::record('option_added', 'people', 'custom-field:'.$def->id, ['key' => $def->key, 'option' => $option->value]);

            return $option;
        });
    }

    public function updateOption(CustomFieldOption $option, array $input): CustomFieldOption
    {
        if (array_key_exists('value', $input) && $input['value'] !== $option->value) {
            $this->fail('value', 'An option value cannot be changed once created. Add a new option and deactivate this one.');
        }
        $data = Validator::make($input, ['label' => ['sometimes', 'required', 'string', 'max:150'], 'sort' => ['sometimes', 'integer', 'between:0,65535'], 'active' => ['sometimes', 'boolean']])->validate();

        return DB::transaction(function () use ($option, $data) {
            $option->fill($data)->save();
            Audit::record('option_updated', 'people', 'custom-field:'.$option->definition_id, ['option' => $option->value, 'active' => $option->active]);

            return $option;
        });
    }

    public function deleteOption(CustomFieldOption $option): void
    {
        abort_if($this->optionInUse($option), 422, 'This option is used by saved values and cannot be deleted. Deactivate it instead.');
        DB::transaction(function () use ($option) {
            $option->delete();
            Audit::record('option_deleted', 'people', 'custom-field:'.$option->definition_id, ['option' => $option->value]);
        });
    }

    private function optionInUse(CustomFieldOption $option): bool
    {
        $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], '"'.$option->value.'"');

        return CustomFieldValue::query()->where('definition_id', $option->definition_id)->whereRaw("value LIKE ? ESCAPE '!'", ['%'.$needle.'%'])->exists();
    }
}
