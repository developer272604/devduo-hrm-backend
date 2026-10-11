<?php

namespace App\Domain\People\Config;

use App\Domain\Access\Classification;
use App\Domain\Access\DataScope;
use App\Domain\Access\FieldRegistry;
use App\Domain\Access\PeopleSettings;
use App\Domain\Audit\Audit;
use App\Domain\People\Models\CustomFieldDefinition;
use App\Domain\People\Services\EmployeeRules;
use App\Domain\People\Services\NumberingService;
use App\Domain\People\Services\StatusService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administration of the tenant "settings.people" block: effective configuration for the admin UI and strictly
 * validated, audited section updates. Every update reads, modifies and writes the whole block inside one
 * transaction (under the tenant row lock) so concurrent edits of different sections cannot clobber each other,
 * and the other sections (and tenant settings owned by SettingsController) are preserved.
 */
final class PeopleSettingsService
{
    public const SECTIONS = ['numbering', 'edit_rules', 'directory_privacy', 'field_policies', 'completeness', 'general', 'statuses'];

    /** Edit group used for custom fields: the field flag employee_editable is the explicit opt-in, this is the tenant policy. */
    public const CUSTOM_GROUP = 'custom';

    /** Self-service edit groups that must never silently skip approval. */
    public const SENSITIVE_EDIT_GROUPS = ['bank', 'identity'];

    /** Groups an employee may never edit directly about themselves. */
    public const NEVER_DIRECT = ['employment', 'job', 'note'];

    /** Directory-privacy keys that expose personal data company-wide when enabled. */
    public const SENSITIVE_PRIVACY = ['personal_phone', 'date_of_birth'];

    /** Classes a highly sensitive field may be configured to (never below HR confidential). */
    public const HIGHLY_SENSITIVE_FLOOR = [Classification::HR_CONFIDENTIAL, Classification::HIGHLY_SENSITIVE, Classification::SYSTEM_RESTRICTED];

    public const EDIT_VALUES = ['direct', 'request', 'none'];

    public const SCOPES = ['tenant', 'legal_entity', 'location', 'employee_type'];

    public const COMPLETENESS_RULES = [
        'emergency_contact' => ['Emergency contact', 'At least one emergency contact is recorded.'],
        'address' => ['Address', 'At least one address is recorded.'],
        'bank_account' => ['Bank account', 'A bank account is on file.'],
        'identity_document' => ['Identity document', 'At least one identity record is on file.'],
        'mandatory_documents' => ['Mandatory documents', 'All mandatory document types for the employee are uploaded.'],
        'profile_photo' => ['Profile photo', 'A profile photo is set.'],
        'personal_email' => ['Personal email', 'A personal email is recorded.'],
        'personal_phone' => ['Personal phone', 'A personal phone is recorded.'],
        'date_of_birth' => ['Date of birth', 'Date of birth is recorded.'],
        'work_email' => ['Work email', 'A work email is recorded.'],
        'manager' => ['Reporting manager', 'A primary reporting manager is assigned.'],
        'required_custom_fields' => ['Required custom fields', 'All required custom fields that apply to the employee are filled.'],
    ];

    /** @return array<int,string> real edit groups (FieldRegistry groups plus the custom-field group). */
    public static function editGroups(): array
    {
        return array_values(array_unique([...array_column(FieldRegistry::FIELDS, 1), self::CUSTOM_GROUP]));
    }

    public static function editDefaults(): array
    {
        return FieldRegistry::SELF_EDIT_DEFAULTS + [self::CUSTOM_GROUP => 'direct'];
    }

    // ------------------------------------------------------------------ reading

    /** The effective (defaults merged with tenant overrides) configuration plus UI catalogues. */
    public function show(): array
    {
        return $this->effective(PeopleSettings::all()) + ['catalogue' => $this->catalogue()];
    }

    public function effective(?array $people = null): array
    {
        $people ??= PeopleSettings::all();
        $stored = fn (string $key) => is_array($people[$key] ?? null) ? $people[$key] : [];
        $transitions = [];
        foreach (StatusService::TRANSITIONS as $from => $default) {
            $override = $stored('statuses')['transitions'][$from] ?? null;
            $transitions[$from] = is_array($override) ? array_values($override) : $default;
        }
        $fieldPolicies = [];
        // Stored nested ({"bank": {"records": "..."}}) because PeopleSettings::get() resolves dotted paths.
        foreach (\Illuminate\Support\Arr::dot($stored('field_policies')) as $field => $class) {
            if (FieldRegistry::known($field) && is_string($class) && Classification::valid($class)) {
                $fieldPolicies[$field] = $class;
            }
        }

        return [
            'numbering' => array_replace(NumberingService::DEFAULT, $stored('numbering')),
            'edit_rules' => array_replace(self::editDefaults(), array_intersect_key($stored('edit_rules'), array_flip(self::editGroups()))),
            'directory_privacy' => array_replace(FieldRegistry::PRIVACY_DEFAULTS, array_map('boolval', array_intersect_key($stored('directory_privacy'), FieldRegistry::PRIVACY_DEFAULTS))),
            'field_policies' => $fieldPolicies,
            'completeness' => ['required' => array_values($stored('completeness')['required'] ?? [])],
            'probation_days' => $people['probation_days'] ?? null,
            'document_expiry_days' => $people['document_expiry_days'] ?? null,
            'default_country' => $people['default_country'] ?? null,
            'statuses' => ['transitions' => $transitions, 'labels' => array_replace(StatusService::LABELS, Lookups::labels('employment_status'))],
        ];
    }

    public function catalogue(): array
    {
        $fields = [];
        foreach (FieldRegistry::FIELDS as $key => [$default, $group, $privacy]) {
            $fields[] = ['key' => $key, 'group' => $group, 'privacy_key' => $privacy, 'default_classification' => $default, 'classification' => PeopleSettings::classification($key),
                'allowed_classifications' => $default === Classification::HIGHLY_SENSITIVE ? self::HIGHLY_SENSITIVE_FLOOR : Classification::ALL];
        }
        $groups = [];
        foreach (self::editGroups() as $group) {
            $groups[] = ['key' => $group, 'default' => self::editDefaults()[$group] ?? 'none', 'sensitive' => in_array($group, self::SENSITIVE_EDIT_GROUPS, true), 'never_direct' => in_array($group, self::NEVER_DIRECT, true)];
        }
        $completeness = [];
        foreach (self::COMPLETENESS_RULES as $key => [$label, $description]) {
            $completeness[] = ['key' => $key, 'label' => $label, 'description' => $description];
        }

        return [
            'field_groups' => $groups,
            'fields' => $fields,
            'classifications' => array_map(fn ($c) => ['value' => $c, 'label' => Classification::LABELS[$c]], Classification::ALL),
            'numbering_tokens' => array_map(fn ($t, $d) => ['token' => $t, 'description' => $d], array_keys(NumberingPattern::TOKENS), NumberingPattern::TOKENS),
            'numbering_scopes' => self::SCOPES,
            'numbering_match_keys' => ['employee_type_id', 'legal_entity_id', 'location_id', 'worker_type', 'country'],
            'edit_rule_values' => self::EDIT_VALUES,
            'privacy_keys' => array_map(fn ($k, $v) => ['key' => $k, 'default' => $v, 'sensitive' => in_array($k, self::SENSITIVE_PRIVACY, true)], array_keys(FieldRegistry::PRIVACY_DEFAULTS), FieldRegistry::PRIVACY_DEFAULTS),
            'completeness_rules' => $completeness,
            'completeness_patterns' => ['field:<registry field key>', 'custom:<custom field key>'],
            'statuses' => array_map(fn ($code, $label) => ['code' => $code, 'label' => $label, 'terminal' => in_array($code, ['archived'], true), 'default_transitions' => StatusService::TRANSITIONS[$code]], array_keys(StatusService::LABELS), StatusService::LABELS),
            'worker_types' => EmployeeRules::WORKER_TYPES,
        ];
    }

    // ------------------------------------------------------------------ updating

    /**
     * Validates and saves one section, returning the effective configuration.
     * Dictionary-style sections (edit_rules, directory_privacy, field_policies, general, statuses) are patches: provided
     * keys change, null resets a key to its default. numbering and completeness replace the keys they carry.
     */
    public function update(string $section, array $input): array
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);

        return DB::transaction(function () use ($section, $input) {
            Tenant::query()->whereKey(app(TenantContext::class)->id)->lockForUpdate()->firstOrFail();
            $people = PeopleSettings::all();
            $before = $this->sectionView($section, $this->effective($people));
            $people = match ($section) {
                'numbering' => $this->numbering($people, $input),
                'edit_rules' => $this->editRules($people, $input),
                'directory_privacy' => $this->directoryPrivacy($people, $input),
                'field_policies' => $this->fieldPolicies($people, $input),
                'completeness' => $this->completeness($people, $input),
                'general' => $this->general($people, $input),
                'statuses' => $this->statuses($people, $input),
            };
            PeopleSettings::save($people);
            $effective = $this->effective($people);
            Audit::record('updated', 'people', 'people-settings:'.$section, ['section' => $section, 'before' => $before, 'after' => $this->sectionView($section, $effective)]);

            return $effective + ['catalogue' => $this->catalogue()];
        });
    }

    private function sectionView(string $section, array $effective): array
    {
        return match ($section) {
            'general' => array_intersect_key($effective, array_flip(['probation_days', 'document_expiry_days', 'default_country'])),
            'statuses' => ['transitions' => $effective['statuses']['transitions']],
            default => $effective[$section],
        };
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private function validate(array $input, array $rules, array $messages = []): array
    {
        return Validator::make($input, $rules, $messages)->validate();
    }

    private function numbering(array $people, array $input): array
    {
        $data = $this->validate($input, [
            'mode' => ['sometimes', Rule::in(['auto', 'manual'])], 'pattern' => ['sometimes', 'nullable', 'string', 'max:'.NumberingPattern::MAX_LENGTH],
            'scope' => ['sometimes', Rule::in(self::SCOPES)], 'start' => ['sometimes', 'integer', 'between:1,999999999'],
            'rules' => ['sometimes', 'array', 'max:30'], 'rules.*' => ['array'], 'rules.*.match' => ['required', 'array', 'min:1'], 'rules.*.pattern' => ['required', 'string'],
        ], [
            'mode.in' => 'Numbering mode must be auto or manual.', 'scope.in' => 'Numbering scope must be one of: '.implode(', ', self::SCOPES).'.',
            'start.between' => 'The starting number must be between 1 and 999999999.', 'rules.*.match.required' => 'Each numbering rule needs a non-empty match.',
            'rules.*.match.min' => 'Each numbering rule needs a non-empty match.', 'rules.*.pattern.required' => 'Each numbering rule needs a pattern.', 'rules.max' => 'Use at most 30 numbering rules.',
        ]);
        if (array_key_exists('pattern', $data) && $data['pattern'] === null) {
            unset($data['pattern']);
        }
        $config = array_replace(NumberingService::DEFAULT, is_array($people['numbering'] ?? null) ? $people['numbering'] : []);
        $config = array_replace($config, array_intersect_key($data, array_flip(['mode', 'pattern', 'scope', 'start'])));
        $config['start'] = (int) $config['start'];

        if (($data['pattern'] ?? null) !== null || ($config['mode'] === 'auto')) {
            if ($error = NumberingPattern::error($config['pattern'])) {
                $this->fail('pattern', $error);
            }
        }
        if (array_key_exists('rules', $data)) {
            $rules = [];
            foreach ($data['rules'] as $index => $rule) {
                if ($error = NumberingPattern::error($rule['pattern'])) {
                    $this->fail("rules.$index.pattern", $error);
                }
                $rules[] = ['match' => $this->normalizeMatch($rule['match'], $index), 'pattern' => $rule['pattern']];
            }
            $config['rules'] = $rules;
        }
        $people['numbering'] = $config;

        return $people;
    }

    private function normalizeMatch(array $match, int $index): array
    {
        $tables = ['employee_type_id' => 'employee_types', 'legal_entity_id' => 'legal_entities', 'location_id' => 'locations'];
        $out = [];
        foreach ($match as $key => $value) {
            $field = "rules.$index.match.$key";
            if (isset($tables[$key])) {
                if (! is_numeric($value) || (int) $value != $value || ! DB::table($tables[$key])->where('tenant_id', app(TenantContext::class)->id)->where('id', (int) $value)->exists()) {
                    $this->fail($field, 'Choose an existing '.str_replace('_', ' ', substr($key, 0, -3)).' for this numbering rule.');
                }
                $out[$key] = (int) $value;
            } elseif ($key === 'worker_type') {
                in_array($value, EmployeeRules::WORKER_TYPES, true) || $this->fail($field, 'Worker type must be one of: '.implode(', ', EmployeeRules::WORKER_TYPES).'.');
                $out[$key] = $value;
            } elseif ($key === 'country') {
                Countries::valid(is_string($value) ? $value : null) || $this->fail($field, 'Use a two-letter country code.');
                $out[$key] = strtoupper($value);
            } else {
                $this->fail($field, "A numbering rule cannot match on \"$key\". Use employee_type_id, legal_entity_id, location_id, worker_type or country.");
            }
        }

        return $out;
    }

    private function editRules(array $people, array $input): array
    {
        $data = $this->validate($input, ['rules' => ['required', 'array', 'min:1'], 'confirm_sensitive' => ['sometimes', 'boolean']], ['rules.required' => 'Provide the edit rules to change.']);
        $confirmed = (bool) ($data['confirm_sensitive'] ?? false);
        $current = $this->effective($people)['edit_rules'];
        $defaults = self::editDefaults();
        $stored = is_array($people['edit_rules'] ?? null) ? $people['edit_rules'] : [];
        foreach ($data['rules'] as $group => $value) {
            if (! in_array($group, self::editGroups(), true)) {
                $this->fail("rules.$group", "\"$group\" is not a profile section. Valid sections: ".implode(', ', self::editGroups()).'.');
            }
            if ($value === null) {
                unset($stored[$group]);

                continue;
            }
            if (! in_array($value, self::EDIT_VALUES, true)) {
                $this->fail("rules.$group", 'Edit rule must be direct, request or none.');
            }
            if ($value === 'direct' && $current[$group] !== 'direct') {
                if (in_array($group, self::NEVER_DIRECT, true)) {
                    $this->fail("rules.$group", 'Employees cannot edit their own '.$group.' data directly. Use "request" or "none".');
                }
                if (in_array($group, self::SENSITIVE_EDIT_GROUPS, true) && ! $confirmed) {
                    $this->fail('confirm_sensitive', 'Letting employees change '.$group.' details without approval is risky. Send confirm_sensitive=true to confirm.');
                }
            }
            $stored[$group] = $value;
        }
        $people['edit_rules'] = array_filter($stored, fn ($value, $group) => ($defaults[$group] ?? 'none') !== $value, ARRAY_FILTER_USE_BOTH);

        return $people;
    }

    private function directoryPrivacy(array $people, array $input): array
    {
        $data = $this->validate($input, ['privacy' => ['required', 'array', 'min:1'], 'confirm_sensitive' => ['sometimes', 'boolean']], ['privacy.required' => 'Provide the directory privacy settings to change.']);
        $confirmed = (bool) ($data['confirm_sensitive'] ?? false);
        $current = $this->effective($people)['directory_privacy'];
        $stored = is_array($people['directory_privacy'] ?? null) ? $people['directory_privacy'] : [];
        foreach ($data['privacy'] as $key => $value) {
            if (! array_key_exists($key, FieldRegistry::PRIVACY_DEFAULTS)) {
                $this->fail("privacy.$key", "\"$key\" is not a directory privacy setting. Valid settings: ".implode(', ', array_keys(FieldRegistry::PRIVACY_DEFAULTS)).'.');
            }
            if ($value === null) {
                unset($stored[$key]);

                continue;
            }
            if (! is_bool($value) && ! in_array($value, [0, 1, '0', '1'], true)) {
                $this->fail("privacy.$key", 'Each privacy setting must be true or false.');
            }
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            if ($value && ! $current[$key] && in_array($key, self::SENSITIVE_PRIVACY, true) && ! $confirmed) {
                $this->fail('confirm_sensitive', 'Showing '.str_replace('_', ' ', $key).' to every coworker exposes personal data. Send confirm_sensitive=true to confirm.');
            }
            $stored[$key] = $value;
        }
        $people['directory_privacy'] = array_filter($stored, fn ($value, $key) => (bool) (FieldRegistry::PRIVACY_DEFAULTS[$key] ?? false) !== (bool) $value, ARRAY_FILTER_USE_BOTH);

        return $people;
    }

    private function fieldPolicies(array $people, array $input): array
    {
        $data = $this->validate($input, ['policies' => ['required', 'array', 'min:1']], ['policies.required' => 'Provide the field classifications to change.']);
        $stored = \Illuminate\Support\Arr::dot(is_array($people['field_policies'] ?? null) ? $people['field_policies'] : []);
        foreach ($data['policies'] as $field => $class) {
            if (! FieldRegistry::known($field)) {
                $this->fail("policies.$field", "\"$field\" is not a known employee field.");
            }
            if ($class === null) {
                unset($stored[$field]);

                continue;
            }
            if (! is_string($class) || ! Classification::valid($class)) {
                $this->fail("policies.$field", 'Unknown classification. Use one of: '.implode(', ', Classification::ALL).'.');
            }
            $default = FieldRegistry::FIELDS[$field][0];
            if ($default === Classification::HIGHLY_SENSITIVE && ! in_array($class, self::HIGHLY_SENSITIVE_FLOOR, true)) {
                $this->fail("policies.$field", Classification::LABELS[Classification::HIGHLY_SENSITIVE].' fields cannot be classified below '.Classification::LABELS[Classification::HR_CONFIDENTIAL].'.');
            }
            $stored[$field] = $class;
        }
        // Stored overrides that equal the built-in classification are noise; drop them. Stale/invalid keys are dropped too.
        $flat = array_filter($stored, fn ($class, $field) => FieldRegistry::known($field) && Classification::valid((string) $class) && FieldRegistry::FIELDS[$field][0] !== $class, ARRAY_FILTER_USE_BOTH);
        $people['field_policies'] = \Illuminate\Support\Arr::undot($flat);

        return $people;
    }

    private function completeness(array $people, array $input): array
    {
        $data = $this->validate($input, ['required' => ['present', 'array', 'max:60'], 'required.*' => ['required', 'string', 'max:80', 'distinct']],
            ['required.present' => 'Provide the list of required profile items (it may be empty).', 'required.*.distinct' => 'Each completeness rule can only be listed once.']);
        foreach ($data['required'] as $index => $rule) {
            $known = isset(self::COMPLETENESS_RULES[$rule])
                || (str_starts_with($rule, 'field:') && FieldRegistry::known(substr($rule, 6)))
                || (str_starts_with($rule, 'custom:') && CustomFieldDefinition::query()->where('key', substr($rule, 7))->exists());
            $known || $this->fail("required.$index", "\"$rule\" is not a known completeness rule.");
        }
        $block = is_array($people['completeness'] ?? null) ? $people['completeness'] : [];
        $block['required'] = array_values($data['required']);
        $people['completeness'] = $block;

        return $people;
    }

    private function general(array $people, array $input): array
    {
        $data = $this->validate(array_intersect_key($input, array_flip(['probation_days', 'document_expiry_days', 'default_country'])), [
            'probation_days' => ['sometimes', 'nullable', 'integer', 'between:0,730'], 'document_expiry_days' => ['sometimes', 'nullable', 'integer', 'between:1,365'], 'default_country' => ['sometimes', 'nullable', 'string', 'size:2'],
        ], ['probation_days.between' => 'Probation can be 0 to 730 days.', 'document_expiry_days.between' => 'The document expiry window can be 1 to 365 days.', 'default_country.size' => 'Use a two-letter country code.']);
        abort_if($data === [], 422, 'Provide at least one general setting to change.');
        foreach ($data as $key => $value) {
            if ($key === 'default_country' && $value !== null) {
                Countries::valid($value) || $this->fail('default_country', 'Use a valid two-letter country code.');
                $value = strtoupper($value);
            }
            if ($value === null) {
                unset($people[$key]);
            } else {
                $people[$key] = $key === 'default_country' ? $value : (int) $value;
            }
        }

        return $people;
    }

    private function statuses(array $people, array $input): array
    {
        $data = $this->validate($input, ['transitions' => ['required', 'array', 'min:1']], ['transitions.required' => 'Provide the status transitions to change.']);
        $codes = array_keys(StatusService::LABELS);
        $stored = is_array($people['statuses']['transitions'] ?? null) ? $people['statuses']['transitions'] : [];
        foreach ($data['transitions'] as $from => $targets) {
            if (! in_array($from, $codes, true)) {
                $this->fail("transitions.$from", "\"$from\" is not an employment status.");
            }
            if ($targets === null) {
                unset($stored[$from]);

                continue;
            }
            if (! is_array($targets) || ! array_is_list($targets)) {
                $this->fail("transitions.$from", 'Transitions must be a list of status codes.');
            }
            $targets = array_values(array_unique($targets));
            foreach ($targets as $to) {
                if (! is_string($to) || ! in_array($to, $codes, true)) {
                    $this->fail("transitions.$from", 'Unknown status "'.(is_scalar($to) ? $to : 'value').'". Valid statuses: '.implode(', ', $codes).'.');
                }
                if ($to === $from) {
                    $this->fail("transitions.$from", 'A status cannot transition to itself.');
                }
                if ($to === 'preboarding') {
                    $this->fail("transitions.$from", 'Preboarding is a starting state; employees cannot move back into it.');
                }
            }
            if ($from === 'archived' && $targets !== []) {
                $this->fail("transitions.$from", 'Archived is final: no further transitions are allowed.');
            }
            if ($from === 'separated' && array_diff($targets, ['archived']) !== []) {
                $this->fail("transitions.$from", 'A separated employee can only be archived. Rehiring creates a new employment instead.');
            }
            if (! in_array($from, ['archived', 'separated'], true) && $targets === []) {
                $this->fail("transitions.$from", 'Every active status needs at least one way out.');
            }
            $stored[$from] = $targets;
        }
        foreach ($stored as $from => $targets) {
            $default = StatusService::TRANSITIONS[$from] ?? null;
            if ($default === null || (count($default) === count($targets) && ! array_diff($default, $targets))) {
                unset($stored[$from]);
            }
        }
        $block = is_array($people['statuses'] ?? null) ? $people['statuses'] : [];
        $block['transitions'] = $stored;
        $people['statuses'] = $block;

        return $people;
    }

    // ------------------------------------------------------------------ numbering preview

    /**
     * Sample numbers for a draft (or the current) pattern without touching employee_number_sequences.
     *
     * @return array{pattern:string,samples:array<int,string>,current_next:?string,mode:string}
     */
    public function previewNumbering(array $input): array
    {
        $data = $this->validate($input, [
            'pattern' => ['nullable', 'string', 'max:200'], 'start' => ['nullable', 'integer', 'between:1,999999999'], 'count' => ['nullable', 'integer', 'between:1,5'],
            'employee_type_id' => ['nullable', 'integer'], 'legal_entity_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'],
            'country' => ['nullable', 'string', 'size:2'], 'worker_type' => ['nullable', 'string', 'max:30'],
        ]);
        $numbering = app(NumberingService::class);
        $config = $numbering->config();
        $pattern = $data['pattern'] ?? $config['pattern'];
        if ($error = NumberingPattern::error($pattern)) {
            $this->fail('pattern', $error);
        }
        $context = array_filter(array_intersect_key($data, array_flip(['employee_type_id', 'legal_entity_id', 'location_id', 'country', 'worker_type'])), fn ($v) => $v !== null);
        $start = (int) ($data['start'] ?? $config['start']);
        $codes = NumberingPattern::codesFor($context);
        $samples = [];
        for ($i = 0; $i < (int) ($data['count'] ?? 3); $i++) {
            $samples[] = NumberingPattern::render($pattern, $start + $i, $codes);
        }

        return ['pattern' => $pattern, 'mode' => $config['mode'], 'samples' => $samples, 'current_next' => $config['mode'] === 'manual' || isset($data['pattern']) ? null : $numbering->preview($context)];
    }

    /** Human labels and descriptions of the role data scopes (for the role editor). */
    public static function scopeCatalogue(): array
    {
        $info = [
            'self' => ['Own record only', 'The user sees only their own employment records.', false],
            'direct_reports' => ['Direct reports', 'Employees who report directly (primary line) to the user.', false],
            'all_subordinates' => ['All subordinates', 'Everyone below the user in the primary reporting tree, at any depth.', false],
            'department' => ['Department', 'Employees in the same department as the user, plus departments granted explicitly.', true],
            'business_unit' => ['Business unit', 'Employees in the same business unit as the user, plus business units granted explicitly.', true],
            'location' => ['Location', 'Employees at the same location as the user, plus locations granted explicitly.', true],
            'legal_entity' => ['Legal entity', 'Employees of the same legal entity as the user, plus legal entities granted explicitly.', true],
            'custom_group' => ['Custom group', 'Employees in the employee groups granted to the user.', true],
            'explicit' => ['Explicit employees', 'Only the individual employees granted to the user.', true],
            'tenant' => ['Entire company', 'Every employee in the company.', false],
        ];

        return array_map(fn ($scope) => ['scope' => $scope, 'label' => $info[$scope][0], 'description' => $info[$scope][1], 'uses_grants' => $info[$scope][2]], DataScope::SCOPES);
    }
}
