<?php

namespace Tests\Feature\People;

use App\Domain\Access\DataScope;
use App\Domain\Access\PeopleSettings;
use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Events\DomainEvent;
use App\Domain\People\Config\CustomFieldAdmin;
use App\Domain\People\Config\CustomFieldService;
use App\Domain\People\Config\CustomFieldValidator;
use App\Domain\People\Config\Lookups;
use App\Domain\People\Config\NumberingPattern;
use App\Domain\People\Models\CustomFieldValue;
use App\Domain\People\Models\EmployeeGroup;
use App\Domain\People\Models\LookupValue;
use App\Domain\People\Services\NumberingService;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class ConfigTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
    }

    private function as(User $user): static
    {
        return $this->signIn($user);
    }

    private function member(string $name, array $o = []): array
    {
        $e = $this->hire($name, $o + ['role' => 'Team member']);

        return [$e, User::find($e->person->user_id)];
    }

    private function field(array $o): \App\Domain\People\Models\CustomFieldDefinition
    {
        return app(CustomFieldAdmin::class)->createField($o + ['entity' => 'employee']);
    }

    // ------------------------------------------------------------------ settings

    public function test_settings_show_effective_defaults_and_catalogues(): void
    {
        $res = $this->as($this->admin)->getJson('/api/v1/people/settings')->assertOk();
        $res->assertJsonPath('numbering.pattern', 'EMP-{SEQ:5}')->assertJsonPath('edit_rules.bank', 'request')->assertJsonPath('edit_rules.contact', 'direct')
            ->assertJsonPath('directory_privacy.personal_phone', false)->assertJsonPath('statuses.labels.active', 'Active');
        $this->assertContains('archived', array_column($res->json('catalogue.statuses'), 'code'));
        $this->assertNotEmpty($res->json('catalogue.fields'));
        $this->assertNotEmpty($res->json('catalogue.numbering_tokens'));
        $this->assertCount(6, $res->json('catalogue.classifications'));
    }

    public function test_numbering_validation_rejects_bad_patterns_scopes_and_rules(): void
    {
        $this->as($this->admin);
        foreach (['EMP-{SEQ:5}-{FOO}', 'EMP-001', 'EMP-{SEQ}-{SEQ}', 'EMP {SEQ:5} !!', '{SEQ:5', 'X{SEQ:99}'] as $bad) {
            $this->putJson('/api/v1/people/settings/numbering', ['mode' => 'auto', 'pattern' => $bad])->assertStatus(422)->assertJsonValidationErrors('pattern');
        }
        $this->putJson('/api/v1/people/settings/numbering', ['pattern' => 'A-{SEQ:4}', 'scope' => 'planet'])->assertStatus(422)->assertJsonValidationErrors('scope');
        $this->putJson('/api/v1/people/settings/numbering', ['pattern' => 'A-{SEQ:4}', 'rules' => [['match' => [], 'pattern' => 'C-{SEQ:4}']]])->assertStatus(422);
        $this->putJson('/api/v1/people/settings/numbering', ['pattern' => 'A-{SEQ:4}', 'rules' => [['match' => ['employee_type_id' => 999999], 'pattern' => 'C-{SEQ:4}']]])->assertStatus(422);
        $this->putJson('/api/v1/people/settings/numbering', ['pattern' => 'A-{SEQ:4}', 'rules' => [['match' => ['salary' => 1], 'pattern' => 'C-{SEQ:4}']]])->assertStatus(422);
        $this->putJson('/api/v1/people/settings/numbering', ['pattern' => 'A-{SEQ:4}', 'rules' => [['match' => ['worker_type' => 'contractor'], 'pattern' => 'C-{BAD}']]])->assertStatus(422);

        $this->putJson('/api/v1/people/settings/numbering', ['mode' => 'auto', 'pattern' => '{COUNTRY}-{SEQ:4}', 'scope' => 'legal_entity', 'start' => 100,
            'rules' => [['match' => ['employee_type_id' => $this->org['employee_type'][1]], 'pattern' => 'CTR-{SEQ:5}']]])->assertOk()->assertJsonPath('numbering.start', 100);
        $this->context($this->admin->tenant_id);
        $this->assertSame('CTR-{SEQ:5}', app(NumberingService::class)->config()['rules'][0]['pattern']);
        $this->assertSame('IN-0100', $this->hire('Zed Zee')->employee_number);
    }

    public function test_numbering_preview_is_pure_and_does_not_consume_sequences(): void
    {
        $this->as($this->admin);
        $res = $this->getJson('/api/v1/people/settings/preview-numbering?pattern='.urlencode('{LOC}-{SEQ:3}').'&start=7&count=3&location_id='.$this->org['location'][0])->assertOk();
        $this->assertSame(['CHN-007', 'CHN-008', 'CHN-009'], $res->json('samples'));
        $this->getJson('/api/v1/people/settings/preview-numbering?pattern=NOPE')->assertStatus(422);
        $this->assertSame(0, DB::table('employee_number_sequences')->count());
        $this->assertNull(NumberingPattern::error('EMP-{SEQ:5}'));
    }

    public function test_sections_preserve_each_other_and_survive_the_company_settings_form(): void
    {
        $this->as($this->admin);
        $this->putJson('/api/v1/people/settings/general', ['probation_days' => 90, 'document_expiry_days' => 30, 'default_country' => 'in'])->assertOk()->assertJsonPath('default_country', 'IN');
        $this->putJson('/api/v1/people/settings/numbering', ['pattern' => 'X-{SEQ:3}'])->assertOk();
        $this->putJson('/api/v1/people/settings/edit_rules', ['rules' => ['contact' => 'request']])->assertOk();
        $this->putJson('/api/v1/people/settings/completeness', ['required' => ['emergency_contact', 'field:person.gender']])->assertOk();
        $this->putJson('/api/v1/people/settings/general', ['probation_days' => 60])->assertOk();

        $this->putJson('/api/settings', ['name' => 'Alpha', 'settings' => ['timezone' => 'Asia/Kolkata', 'work_week' => [1, 2, 3, 4, 5], 'hr_email' => 'hr@alpha.test', 'notifications' => true,
            'modules' => ['work', 'notifications', 'people', 'attendance']]])->assertOk();

        $this->context($this->admin->tenant_id);
        $this->assertSame(60, PeopleSettings::get('probation_days'));
        $this->assertSame(30, PeopleSettings::get('document_expiry_days'));
        $this->assertSame('IN', PeopleSettings::get('default_country'));
        $this->assertSame('X-{SEQ:3}', PeopleSettings::get('numbering.pattern'));
        $this->assertSame('request', PeopleSettings::editRule('contact'));
        $this->assertSame(['emergency_contact', 'field:person.gender'], PeopleSettings::get('completeness.required'));
        $this->putJson('/api/v1/people/settings/completeness', ['required' => ['nonsense']])->assertStatus(422);
        $this->context($this->admin->tenant_id);
        $audit = AuditLog::where('resource', 'people-settings:numbering')->latest('id')->first();
        $this->assertSame('X-{SEQ:3}', $audit->context['after']['pattern']);
        $this->assertSame('EMP-{SEQ:5}', $audit->context['before']['pattern']);
    }

    public function test_field_policies_cannot_downgrade_highly_sensitive_fields(): void
    {
        $this->as($this->admin);
        $this->putJson('/api/v1/people/settings/field_policies', ['policies' => ['bank.records' => 'public_internal']])->assertStatus(422)->assertJsonValidationErrors('policies.bank.records');
        $this->putJson('/api/v1/people/settings/field_policies', ['policies' => ['experience.last_salary' => 'employee']])->assertStatus(422);
        $this->putJson('/api/v1/people/settings/field_policies', ['policies' => ['person.gender' => 'secret']])->assertStatus(422);
        $this->putJson('/api/v1/people/settings/field_policies', ['policies' => ['person.nope' => 'employee']])->assertStatus(422);
        $res = $this->putJson('/api/v1/people/settings/field_policies', ['policies' => ['bank.records' => 'hr_confidential', 'person.blood_group' => 'highly_sensitive']])->assertOk();
        $this->assertSame('hr_confidential', $res->json('field_policies')['bank.records']);
        $this->context($this->admin->tenant_id);
        $this->assertSame('hr_confidential', PeopleSettings::classification('bank.records'));
        $this->as($this->admin)->putJson('/api/v1/people/settings/field_policies', ['policies' => ['bank.records' => null]])->assertOk();
        $this->assertArrayNotHasKey('bank.records', $this->getJson('/api/v1/people/settings')->json('field_policies'));
    }

    public function test_edit_rules_guard_sensitive_groups_and_unknown_keys(): void
    {
        $this->as($this->admin);
        $this->putJson('/api/v1/people/settings/edit_rules', ['rules' => ['bank' => 'direct']])->assertStatus(422)->assertJsonValidationErrors('confirm_sensitive');
        $this->putJson('/api/v1/people/settings/edit_rules', ['rules' => ['identity' => 'direct']])->assertStatus(422);
        $this->putJson('/api/v1/people/settings/edit_rules', ['rules' => ['spaceship' => 'direct']])->assertStatus(422);
        $this->putJson('/api/v1/people/settings/edit_rules', ['rules' => ['contact' => 'maybe']])->assertStatus(422);
        $this->putJson('/api/v1/people/settings/edit_rules', ['rules' => ['job' => 'direct']])->assertStatus(422);
        $this->putJson('/api/v1/people/settings/edit_rules', ['rules' => ['bank' => 'direct'], 'confirm_sensitive' => true])->assertOk()->assertJsonPath('edit_rules.bank', 'direct');
        $this->putJson('/api/v1/people/settings/edit_rules', ['rules' => ['bank' => null]])->assertOk()->assertJsonPath('edit_rules.bank', 'request');
    }

    public function test_directory_privacy_and_status_rules(): void
    {
        $this->as($this->admin);
        $this->putJson('/api/v1/people/settings/directory_privacy', ['privacy' => ['date_of_birth' => true]])->assertStatus(422);
        $this->putJson('/api/v1/people/settings/directory_privacy', ['privacy' => ['dob2' => true]])->assertStatus(422);
        $this->putJson('/api/v1/people/settings/directory_privacy', ['privacy' => ['manager' => false]])->assertOk()->assertJsonPath('directory_privacy.manager', false);

        $this->putJson('/api/v1/people/settings/statuses', ['transitions' => ['archived' => ['active']]])->assertStatus(422);
        $this->putJson('/api/v1/people/settings/statuses', ['transitions' => ['separated' => ['active']]])->assertStatus(422);
        $this->putJson('/api/v1/people/settings/statuses', ['transitions' => ['active' => ['ghost']]])->assertStatus(422);
        $this->putJson('/api/v1/people/settings/statuses', ['transitions' => ['active' => []]])->assertStatus(422);
        $this->putJson('/api/v1/people/settings/statuses', ['transitions' => ['suspended' => ['active', 'separated']]])->assertOk()->assertJsonPath('statuses.transitions.suspended', ['active', 'separated']);
        $this->putJson('/api/v1/people/settings/unknown', [])->assertNotFound();
    }

    public function test_non_admins_get_403(): void
    {
        [, $member] = $this->member('Asha Rao');
        $this->as($member);
        $this->getJson('/api/v1/people/settings')->assertForbidden();
        $this->putJson('/api/v1/people/settings/general', ['probation_days' => 1])->assertForbidden();
        $this->postJson('/api/v1/people/lookups/separation_reason', ['label' => 'X'])->assertForbidden();
        $this->postJson('/api/v1/people/custom-fields', [])->assertForbidden();
        $this->postJson('/api/v1/people/groups', ['name' => 'G'])->assertForbidden();
        $this->getJson('/api/v1/access/data-scopes')->assertForbidden();
        $this->getJson('/api/v1/people/lookups/separation_reason')->assertOk();
        $this->getJson('/api/v1/people/groups')->assertOk();
    }

    // ------------------------------------------------------------------ lookups

    public function test_lookup_defaults_are_idempotent_and_system_rows_are_protected(): void
    {
        Lookups::ensureDefaults('separation_reason');
        Lookups::ensureDefaults('separation_reason');
        $this->assertSame(6, LookupValue::where('kind', 'separation_reason')->count());
        $this->assertSame(['primary', 'functional', 'dotted_line', 'hr_partner'], Lookups::active('relationship_type')->pluck('code')->all());

        $this->as($this->admin);
        $list = $this->getJson('/api/v1/people/lookups/employment_status')->assertOk();
        $this->assertCount(9, $list->json('data'));
        $this->postJson('/api/v1/people/lookups/employment_status', ['label' => 'Sabbatical'])->assertStatus(422);
        $id = collect($list->json('data'))->firstWhere('code', 'active')['id'];
        $this->putJson("/api/v1/people/lookups/employment_status/$id", ['label' => 'Working'])->assertOk()->assertJsonPath('label', 'Working');
        $this->putJson("/api/v1/people/lookups/employment_status/$id", ['active' => false])->assertStatus(422);
        $this->putJson("/api/v1/people/lookups/employment_status/$id", ['code' => 'other'])->assertStatus(422);
        $this->deleteJson("/api/v1/people/lookups/employment_status/$id")->assertStatus(422);
        $this->getJson('/api/v1/people/settings')->assertJsonPath('statuses.labels.active', 'Working');

        $this->context($this->admin->tenant_id);
        $reason = LookupValue::where('kind', 'separation_reason')->where('code', 'resignation')->first();
        $this->deleteJson("/api/v1/people/lookups/separation_reason/{$reason->id}")->assertStatus(422);
        $this->putJson("/api/v1/people/lookups/separation_reason/{$reason->id}", ['active' => false])->assertOk()->assertJsonPath('active', false);
        $this->getJson('/api/v1/people/lookups/nonsense')->assertNotFound();
    }

    public function test_custom_lookup_values_crud_and_in_use_protection(): void
    {
        $this->as($this->admin);
        $created = $this->postJson('/api/v1/people/lookups/skill_category', ['label' => 'Data Science', 'metadata' => ['color' => 'blue']])->assertCreated()->assertJsonPath('code', 'data_science');
        $this->postJson('/api/v1/people/lookups/skill_category', ['label' => 'Data Science'])->assertStatus(422);
        $this->postJson('/api/v1/people/lookups/skill_category', ['label' => 'Bad', 'code' => 'Bad Code!'])->assertStatus(422);
        $id = $created->json('id');
        $this->context($this->admin->tenant_id);
        DB::table('skills')->insert(['tenant_id' => $this->admin->tenant_id, 'name' => 'Pandas', 'category' => 'data_science', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->deleteJson("/api/v1/people/lookups/skill_category/$id")->assertStatus(422);
        DB::table('skills')->where('name', 'Pandas')->delete();
        $this->deleteJson("/api/v1/people/lookups/skill_category/$id")->assertNoContent();
    }

    // ------------------------------------------------------------------ custom fields

    public function test_validator_handles_every_field_type(): void
    {
        $v = app(CustomFieldValidator::class);
        $emp = $this->hire('Target One');
        $dept = $this->org['department'][0];
        $loc = $this->org['location'][0];
        $cases = [
            ['text', ['validation' => ['max_length' => 5, 'pattern' => '^[A-Z]+$']], 'ABC', 'ABC', 'abc'],
            ['long_text', [], "Line one\nLine two", "Line one\nLine two", ['x']],
            ['number', ['validation' => ['min' => 1, 'max' => 10]], '5', 5, 11],
            ['currency', ['validation' => ['currency' => 'USD']], '12.5', 12.5, '1.234'],
            ['date', [], '2026-10-07', '2026-10-07', '2026-02-30'],
            ['datetime', [], '2026-10-07T09:30:00Z', '2026-10-07T09:30:00Z', 'tomorrow'],
            ['boolean', [], 'false', false, 'maybe'],
            ['single_select', ['options' => [['label' => 'Red'], ['label' => 'Blue']]], 'red', 'red', 'green'],
            ['multi_select', ['options' => [['label' => 'Red'], ['label' => 'Blue']], 'validation' => ['max' => 2]], ['red', 'blue', 'red'], ['red', 'blue'], ['red', 'pink']],
            ['email', [], 'A@Example.com', 'a@example.com', 'nope'],
            ['phone', [], '+91 98765 43210', '+91 98765 43210', 'abc'],
            ['url', [], 'https://example.com/x', 'https://example.com/x', 'javascript:alert(1)'],
            ['employee_ref', [], $emp->uuid, $emp->uuid, '00000000-0000-4000-8000-000000000000'],
            ['department_ref', [], $dept, $dept, 999999],
            ['location_ref', [], (string) $loc, $loc, 999999],
            ['country', [], 'in', 'IN', 'ZZ'],
            ['attachment_ref', [], null, null, '00000000-0000-4000-8000-000000000000'],
        ];
        foreach ($cases as $i => [$type, $extra, $good, $expected, $bad]) {
            $def = $this->field(['key' => 'f_'.$type, 'label' => ucfirst($type), 'type' => $type] + $extra);
            $def->load('options');
            [$value, $error] = $v->normalize($def, $good, $emp);
            $this->assertNull($error, "$type should accept the valid value: $error");
            $this->assertSame($expected, $value, "$type normalization");
            [, $error] = $v->normalize($def, $bad, $emp);
            $this->assertNotNull($error, "$type should reject the invalid value");
        }
        $this->assertSame([null, null], $v->normalize($this->field(['key' => 'empty_ok', 'label' => 'E', 'type' => 'text']), '', $emp));
    }

    public function test_definitions_reject_unsafe_keys_regexes_and_schema_tricks(): void
    {
        $this->as($this->admin);
        $base = ['entity' => 'employee', 'label' => 'X', 'type' => 'text'];
        foreach (['Bad Key', 'drop table', 'a', '1abc', 'x;--', str_repeat('a', 61)] as $key) {
            $this->postJson('/api/v1/people/custom-fields', $base + ['key' => $key])->assertStatus(422)->assertJsonValidationErrors('key');
        }
        foreach (['(a+)+$', '(', str_repeat('a', 201), '(a)\1', '(?<=a)b'] as $pattern) {
            $this->postJson('/api/v1/people/custom-fields', $base + ['key' => 'ok_key', 'validation' => ['pattern' => $pattern]])->assertStatus(422)->assertJsonValidationErrors('validation');
        }
        $this->postJson('/api/v1/people/custom-fields', $base + ['key' => 'ok_key', 'validation' => ['max' => 3]])->assertStatus(422);
        $this->postJson('/api/v1/people/custom-fields', array_merge($base, ['key' => 'ok_key', 'type' => 'blob']))->assertStatus(422);
        $this->postJson('/api/v1/people/custom-fields', $base + ['key' => 'ok_key', 'classification' => 'system_restricted'])->assertStatus(422);
        $created = $this->postJson('/api/v1/people/custom-fields', $base + ['key' => 'ok_key', 'validation' => ['pattern' => '^[A-Z]{2}-\d+$']])->assertCreated();
        $this->postJson('/api/v1/people/custom-fields', ['key' => 'ok_key', 'entity' => 'person'] + $base)->assertStatus(422); // key unique per tenant
        $id = $created->json('id');
        $this->putJson("/api/v1/people/custom-fields/$id", ['key' => 'renamed'])->assertStatus(422);
        $this->putJson("/api/v1/people/custom-fields/$id", ['type' => 'number'])->assertStatus(422);
        $this->putJson("/api/v1/people/custom-fields/$id", ['label' => 'Renamed'])->assertOk()->assertJsonPath('label', 'Renamed');
        $this->assertFalse(CustomFieldValidator::matches('^[A-Z]{2}-\d+$', 'xx'));
    }

    public function test_select_options_lifecycle(): void
    {
        $this->as($this->admin);
        $id = $this->postJson('/api/v1/people/custom-fields', ['entity' => 'employee', 'key' => 'shirt', 'label' => 'Shirt', 'type' => 'single_select', 'default' => 'm',
            'options' => [['label' => 'S'], ['label' => 'M'], ['label' => 'L']]])->assertCreated()->assertJsonPath('default', 'm')->json('id');
        $this->postJson('/api/v1/people/custom-fields/'.$id.'/options', ['label' => 'M'])->assertStatus(422);
        $opt = $this->postJson('/api/v1/people/custom-fields/'.$id.'/options', ['label' => 'XL'])->assertCreated()->json('id');
        $text = $this->postJson('/api/v1/people/custom-fields', ['entity' => 'employee', 'key' => 'plain', 'label' => 'Plain', 'type' => 'text'])->json('id');
        $this->postJson('/api/v1/people/custom-fields/'.$text.'/options', ['label' => 'No'])->assertStatus(422);
        $this->putJson('/api/v1/people/custom-fields/'.$id.'/options/'.$opt, ['value' => 'changed'])->assertStatus(422);

        $this->context($this->admin->tenant_id);
        $e = $this->hire('Target One');
        app(CustomFieldService::class)->setValues($this->admin, $e, ['shirt' => 'xl']);
        $this->deleteJson('/api/v1/people/custom-fields/'.$id.'/options/'.$opt)->assertStatus(422);
        $this->deleteJson('/api/v1/people/custom-fields/'.$id)->assertStatus(422);
        $this->putJson('/api/v1/people/custom-fields/'.$id.'/options/'.$opt, ['active' => false])->assertOk();
        $this->putJson('/api/v1/people/custom-fields/'.$id, ['active' => false])->assertOk();
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, CustomFieldValue::count(), 'deactivating keeps values');
        $this->assertArrayNotHasKey('shirt', app(CustomFieldService::class)->valuesFor($this->admin, $e));
    }

    public function test_applicability_by_country_and_employee_type(): void
    {
        $this->field(['key' => 'everyone', 'label' => 'Everyone', 'type' => 'text']);
        $this->field(['key' => 'uk_only', 'label' => 'UK only', 'type' => 'text', 'countries' => ['GB']]);
        $this->field(['key' => 'contractors', 'label' => 'Contractors', 'type' => 'text', 'employee_types' => [$this->org['employee_type'][1]]]);
        $section = app(CustomFieldAdmin::class)->createSection(['key' => 'gb_hr', 'label' => 'GB HR', 'countries' => ['GB']]);
        $this->field(['key' => 'in_gb_section', 'label' => 'In GB section', 'type' => 'text', 'section_id' => $section->id]);
        $service = app(CustomFieldService::class);

        $india = $this->hire('Asha Rao');
        $uk = $this->hire('Ben Ford', ['location' => 1]);
        $ctr = $this->hire('Cy Dee', ['assignment' => ['employee_type_id' => $this->org['employee_type'][1]]]);
        $this->assertSame(['everyone'], $service->definitionsFor($india)->pluck('key')->all());
        $this->assertEqualsCanonicalizing(['everyone', 'uk_only', 'in_gb_section'], $service->definitionsFor($uk)->pluck('key')->all());
        $this->assertEqualsCanonicalizing(['everyone', 'contractors'], $service->definitionsFor($ctr)->pluck('key')->all());
        $this->expectException(ValidationException::class);
        $service->setValues($this->admin, $india, ['uk_only' => 'x']);
    }

    public function test_visibility_depends_on_classification_and_viewer(): void
    {
        $this->field(['key' => 'nick', 'label' => 'Nickname', 'type' => 'text', 'classification' => 'public_internal']);
        $this->field(['key' => 'hobby', 'label' => 'Hobby', 'type' => 'text', 'classification' => 'employee']);
        $this->field(['key' => 'notes_mgr', 'label' => 'Manager note', 'type' => 'text', 'classification' => 'manager_sensitive']);
        $this->field(['key' => 'union_no', 'label' => 'Union number', 'type' => 'text', 'classification' => 'hr_confidential']);
        $this->field(['key' => 'ssn', 'label' => 'Government id', 'type' => 'text', 'classification' => 'highly_sensitive']);
        $boss = $this->hire('Maya Chen', ['role' => 'Manager']);
        [$e, $self] = $this->member('Asha Rao', ['manager' => $boss]);
        [, $peer] = $this->member('Pat Peer');
        $service = app(CustomFieldService::class);
        $service->setValues($this->admin, $e, ['nick' => 'Ash', 'hobby' => 'Chess', 'notes_mgr' => 'Strong', 'union_no' => 'U-1', 'ssn' => '123456789']);
        $boss = User::find($boss->person->user_id);

        $this->assertSame(['nick' => 'Ash', 'hobby' => 'Chess', 'ssn' => '•••••6789'], $service->valuesFor($self, $e), 'self: employee class, masked sensitive');
        $this->assertSame(['nick' => 'Ash', 'notes_mgr' => 'Strong'], $service->valuesFor($boss, $e), 'manager: manager-sensitive only');
        $this->assertSame(['nick' => 'Ash'], $service->valuesFor($peer, $e));
        $this->assertSame(['nick' => 'Ash', 'hobby' => 'Chess', 'notes_mgr' => 'Strong', 'union_no' => 'U-1', 'ssn' => '123456789'], $service->valuesFor($this->admin, $e));
    }

    public function test_section_view_permission_and_classification_apply(): void
    {
        $section = app(CustomFieldAdmin::class)->createSection(['key' => 'medical', 'label' => 'Medical', 'classification' => 'employee', 'view_permission' => 'people.confidential.view']);
        $this->field(['key' => 'allergy', 'label' => 'Allergy', 'type' => 'text', 'section_id' => $section->id]);
        $boss = $this->hire('Maya Chen', ['role' => 'Manager']);
        [$e, $self] = $this->member('Asha Rao', ['manager' => $boss]);
        $service = app(CustomFieldService::class);
        $service->setValues($this->admin, $e, ['allergy' => 'Peanuts']);
        $hr = $this->userWithRole('HR administrator', 'hr@alpha.test');
        $this->assertArrayHasKey('allergy', $service->valuesFor($self, $e), 'the employee always sees own data permitted by classification');
        $this->assertSame([], $service->valuesFor(User::find($boss->person->user_id), $e));
        $this->assertSame(['allergy' => 'Peanuts'], $service->valuesFor($hr, $e));
        $sections = $service->sectionsFor($hr, $e);
        $this->assertSame('Medical', $sections[0]['label']);
        $this->assertSame('Peanuts', $sections[0]['fields'][0]['value']);
        $this->assertTrue($sections[0]['fields'][0]['editable']);
    }

    public function test_editability_for_employee_manager_and_hr_with_friendly_errors(): void
    {
        $this->field(['key' => 'phone_alt', 'label' => 'Alternate phone', 'type' => 'phone', 'employee_editable' => true, 'hr_editable' => true]);
        $this->field(['key' => 'rating', 'label' => 'Rating', 'type' => 'number', 'manager_editable' => true, 'hr_editable' => false, 'classification' => 'manager_sensitive']);
        $this->field(['key' => 'locker', 'label' => 'Locker', 'type' => 'text', 'hr_editable' => true]);
        $boss = $this->hire('Maya Chen', ['role' => 'Manager']);
        [$e, $self] = $this->member('Asha Rao', ['manager' => $boss]);
        $managerUser = User::find($boss->person->user_id);
        $service = app(CustomFieldService::class);

        $service->setValues($self, $e, ['phone_alt' => '+91 98765 43210']);
        $res = $this->as($self)->putJson('/api/v1/employees/'.$e->uuid.'/custom-fields', ['values' => ['phone_alt' => '+91 11111 22222', 'locker' => 'L9']])->assertForbidden();
        $this->assertStringContainsString('Locker', $res->json('message'));
        $this->assertSame('locker', $res->json('fields.0.key'));
        $this->as($self)->putJson('/api/v1/employees/'.$e->uuid.'/custom-fields', ['values' => ['phone_alt' => '+91 11111 22222']])->assertOk()
            ->assertJsonPath('sections.0.fields.0.editable', true);

        $this->context($this->admin->tenant_id);
        $service->setValues($managerUser, $e, ['rating' => 4]);
        $this->assertSame(4, $service->valuesFor($managerUser, $e)['rating']);
        try {
            $service->setValues($managerUser, $e, ['locker' => 'x']);
            $this->fail('manager must not edit hr-only fields');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $ex) {
            $this->assertSame(403, $ex->getResponse()->getStatusCode());
        }
        // HR is not allowed to edit manager-only fields (hr_editable false)
        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);
        $service->setValues($this->admin, $e, ['rating' => 5]);
    }

    public function test_tenant_policy_can_require_approval_or_block_self_edits(): void
    {
        $this->field(['key' => 'hobby', 'label' => 'Hobby', 'type' => 'text', 'employee_editable' => true]);
        [$e, $self] = $this->member('Asha Rao');
        $this->as($this->admin)->putJson('/api/v1/people/settings/edit_rules', ['rules' => ['custom' => 'request']])->assertOk();
        $res = $this->as($self)->putJson('/api/v1/employees/'.$e->uuid.'/custom-fields', ['values' => ['hobby' => 'Chess']])->assertStatus(409);
        $this->assertSame('change_request_required', $res->json('code'));
        $this->as($this->admin)->putJson('/api/v1/people/settings/edit_rules', ['rules' => ['custom' => 'none']])->assertOk();
        $this->as($self)->putJson('/api/v1/employees/'.$e->uuid.'/custom-fields', ['values' => ['hobby' => 'Chess']])->assertForbidden();
    }

    public function test_required_fields_are_enforced_and_reported_missing(): void
    {
        $this->field(['key' => 'badge', 'label' => 'Badge', 'type' => 'text', 'required' => true, 'default' => 'B-0']);
        $this->field(['key' => 'optional', 'label' => 'Optional', 'type' => 'text']);
        $e = $this->hire('Asha Rao');
        $service = app(CustomFieldService::class);
        $this->assertSame([], $service->missingRequired($e), 'a configured default is applied during normal employee creation');
        $this->assertSame('B-0', $service->valuesFor($this->admin, $e)['badge']);
        try {
            $service->setValues($this->admin, $e, ['badge' => '']);
            $this->fail('required field cannot be cleared');
        } catch (ValidationException $ex) {
            $this->assertStringContainsString('required', $ex->errors()['values.badge'][0]);
        }
        $this->assertSame(0, $service->applyDefaults($e), 'applying defaults again is idempotent');
        $this->assertSame([], $service->missingRequired($e));
        $this->assertSame(0, $service->applyDefaults($e));
        $this->assertSame('B-0', $service->valuesFor($this->admin, $e)['badge']);
    }

    public function test_effective_dated_values_keep_history(): void
    {
        $this->field(['key' => 'band', 'label' => 'Band', 'type' => 'text', 'effective_dated' => true]);
        $e = $this->hire('Asha Rao');
        $service = app(CustomFieldService::class);
        $service->setValues($this->admin, $e, ['band' => 'A']);
        $service->setValues($this->admin, $e, ['band' => ['value' => 'B', 'effective_from' => '2026-12-01']]);
        $service->setValues($this->admin, $e, ['band' => 'C'], '2026-11-01');
        $service->setValues($this->admin, $e, ['band' => 'C'], '2026-11-01'); // no-op

        $rows = CustomFieldValue::orderBy('effective_from')->get();
        $this->assertSame(['"A"', '"C"', '"B"'], $rows->pluck('value')->all());
        $this->assertSame(['2026-10-31', '2026-11-30', null], $rows->map(fn ($r) => $r->effective_to?->toDateString())->all());
        $this->assertSame('A', $service->valuesFor($this->admin, $e)['band'], 'future rows are not current yet');
        $history = $service->historyFor($this->admin, $e, 'band');
        $this->assertSame(['B', 'C', 'A'], array_column($history, 'value'));
        $this->travelTo(now()->addDays(40));
        $this->assertSame('C', $service->valuesFor($this->admin, $e)['band']);
        $this->as($this->admin)->getJson('/api/v1/employees/'.$e->uuid.'/custom-fields/band/history')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_person_entity_values_are_shared_across_rehire_and_events_never_leak_values(): void
    {
        $this->field(['key' => 'blood_card', 'label' => 'Donor card', 'type' => 'text', 'entity' => 'person', 'classification' => 'hr_confidential']);
        $e = $this->hire('Asha Rao');
        $service = app(CustomFieldService::class);
        $service->setValues($this->admin, $e, ['blood_card' => 'SECRET-123']);
        $row = CustomFieldValue::first();
        $this->assertSame('person', $row->entity_type);
        $this->assertSame($e->person_id, $row->entity_id);
        $event = DomainEvent::where('type', 'PersonalInformationUpdated')->latest('id')->first();
        $this->assertStringContainsString('Donor card', $event->summary);
        $this->assertStringNotContainsString('SECRET', $event->summary.json_encode($event->payload));
        $this->assertSame('hr_confidential', $event->classification);
        $audit = AuditLog::where('resource', 'employee:'.$e->uuid)->where('action', 'updated')->latest('id')->first();
        $this->assertStringNotContainsString('SECRET', json_encode($audit->context));
        $this->assertSame(['blood_card'], $audit->context['custom_fields']);
    }

    // ------------------------------------------------------------------ groups and data scopes

    public function test_groups_membership_and_data_scope_grants_reach_employees(): void
    {
        [$alice, $aliceUser] = $this->member('Alice One');
        $bob = $this->hire('Bob Two', ['department' => 1]);
        $scope = app(DataScope::class);
        $this->assertFalse($scope->canAccess($aliceUser, $bob));

        $this->as($this->admin);
        $group = $this->postJson('/api/v1/people/groups', ['name' => 'Night Crew'])->assertCreated()->assertJsonPath('code', 'night_crew')->json();
        $this->postJson('/api/v1/people/groups', ['name' => 'Night Crew'])->assertStatus(422);
        $this->postJson('/api/v1/people/groups/'.$group['id'].'/members', ['employees' => [$bob->uuid], 'effective_from' => '2026-01-01'])->assertCreated()->assertJsonPath('added', 1);
        $this->postJson('/api/v1/people/groups/'.$group['id'].'/members', ['employees' => [$bob->uuid]])->assertCreated()->assertJsonPath('updated', 1);
        $this->getJson('/api/v1/people/groups/'.$group['id'].'/members')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.employee_uuid', $bob->uuid);
        $this->getJson('/api/v1/people/groups')->assertJsonPath('data.0.member_count', 1);
        $this->putJson('/api/v1/people/groups/'.$group['id'], ['code' => 'other'])->assertStatus(422);

        $this->context($this->admin->tenant_id);
        $this->assertFalse($scope->canAccess($aliceUser, $bob), 'membership alone grants nothing');
        $grant = $this->postJson('/api/v1/access/data-scopes', ['user_id' => $aliceUser->id, 'kind' => 'employee_group', 'reference_id' => $group['id']])->assertCreated()->json('grants.0');
        $this->postJson('/api/v1/access/data-scopes', ['user_id' => $aliceUser->id, 'kind' => 'employee_group', 'reference_id' => $group['id']])->assertOk()->assertJsonPath('created', 0);
        $this->context($this->admin->tenant_id);
        $this->assertTrue($scope->canAccess($aliceUser, $bob));
        $this->deleteJson('/api/v1/people/groups/'.$group['id'])->assertStatus(422);

        $this->deleteJson('/api/v1/access/data-scopes/'.$grant['id'])->assertNoContent();
        $this->context($this->admin->tenant_id);
        $this->assertFalse($scope->canAccess($aliceUser, $bob));

        $this->postJson('/api/v1/access/data-scopes', ['user_id' => $aliceUser->id, 'kind' => 'department', 'reference_id' => $this->org['department'][1]])->assertCreated();
        $this->postJson('/api/v1/access/data-scopes', ['user_id' => $aliceUser->id, 'kind' => 'employee', 'reference_id' => $bob->uuid])->assertCreated()->assertJsonPath('grants.0.reference_id', $bob->uuid);
        $this->context($this->admin->tenant_id);
        $this->assertTrue($scope->canAccess($aliceUser, $bob));
        $this->assertTrue(AuditLog::where('action', 'data_scope_granted')->exists());
        $this->getJson('/api/v1/access/data-scopes?user_id='.$aliceUser->id)->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/access/data-scopes/catalogue')->assertOk()->assertJsonCount(10, 'scopes');

        $this->deleteJson('/api/v1/people/groups/'.$group['id'].'/members/'.$bob->uuid)->assertNoContent();
        $this->deleteJson('/api/v1/people/groups/'.$group['id'].'/members/'.$bob->uuid)->assertNotFound();
    }

    public function test_group_members_respect_the_viewers_data_scope(): void
    {
        [, $viewer] = $this->member('Alice One');
        $bob = $this->hire('Bob Two');
        $group = EmployeeGroup::create(['name' => 'G', 'code' => 'g', 'active' => true]);
        $this->as($this->admin)->postJson('/api/v1/people/groups/'.$group->id.'/members', ['employees' => [$bob->uuid]])->assertCreated();
        $this->as($viewer)->getJson('/api/v1/people/groups/'.$group->id.'/members')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_tenant_isolation_for_groups_lookups_fields_and_grants(): void
    {
        $beta = app(WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->context($beta->tenant_id);
        $foreignGroup = EmployeeGroup::create(['name' => 'Foreign', 'code' => 'foreign', 'active' => true]);
        $foreignDept = \App\Domain\Organization\OrganizationRecord::forType('departments')->newQuery()->create(['name' => 'BetaDept', 'code' => 'BD', 'active' => true])->id;
        Lookups::ensureDefaults('separation_reason');
        $foreignLookup = LookupValue::first()->id;
        $foreignField = app(CustomFieldAdmin::class)->createField(['entity' => 'employee', 'key' => 'beta_field', 'label' => 'B', 'type' => 'text']);
        $foreignEmployee = $this->hireIn($beta);
        $this->context($this->admin->tenant_id);
        [, $user] = $this->member('Alice One');

        $this->as($this->admin);
        $this->getJson('/api/v1/people/groups/'.$foreignGroup->id)->assertNotFound();
        $this->deleteJson('/api/v1/people/groups/'.$foreignGroup->id)->assertNotFound();
        $this->postJson('/api/v1/people/groups/'.$foreignGroup->id.'/members', ['employees' => []])->assertNotFound();
        $this->putJson("/api/v1/people/lookups/separation_reason/$foreignLookup", ['label' => 'x'])->assertNotFound();
        $this->putJson('/api/v1/people/custom-fields/'.$foreignField->id, ['label' => 'x'])->assertNotFound();
        $this->getJson('/api/v1/employees/'.$foreignEmployee.'/custom-fields')->assertNotFound();
        $own = $this->postJson('/api/v1/people/groups', ['name' => 'Own'])->assertCreated()->json('id');
        $this->postJson("/api/v1/people/groups/$own/members", ['employees' => [$foreignEmployee]])->assertStatus(422);
        $this->postJson('/api/v1/access/data-scopes', ['user_id' => $user->id, 'kind' => 'department', 'reference_id' => $foreignDept])->assertStatus(422);
        $this->postJson('/api/v1/access/data-scopes', ['user_id' => $user->id, 'kind' => 'employee_group', 'reference_id' => $foreignGroup->id])->assertStatus(422);
        $this->postJson('/api/v1/access/data-scopes', ['user_id' => $user->id, 'kind' => 'employee', 'reference_id' => $foreignEmployee])->assertStatus(422);
        $this->postJson('/api/v1/access/data-scopes', ['user_id' => $beta->id, 'kind' => 'department', 'reference_id' => $this->org['department'][0]])->assertStatus(422);
        $this->postJson('/api/v1/people/custom-fields', ['entity' => 'employee', 'key' => 'beta_field', 'label' => 'Same key ok', 'type' => 'text'])->assertCreated();
        $this->putJson('/api/v1/people/settings/numbering', ['rules' => [['match' => ['location_id' => 999999], 'pattern' => 'A-{SEQ}']]])->assertStatus(422);
    }

    /** Creates an employee in another tenant and returns its uuid. */
    private function hireIn(User $tenantAdmin): string
    {
        $this->context($tenantAdmin->tenant_id);
        $make = fn ($t, $n, $c) => \App\Domain\Organization\OrganizationRecord::forType($t)->newQuery()->create(['name' => $n, 'code' => $c, 'active' => true, 'metadata' => ['country' => 'US']])->id;
        $e = app(\App\Domain\People\Services\EmployeeService::class)->create([
            'person' => ['legal_first_name' => 'Far', 'legal_last_name' => 'Away'], 'employment' => ['worker_type' => 'employee', 'joined_on' => '2026-01-05'],
            'assignment' => ['legal_entity_id' => $make('legal-entities', 'BLE', 'BLE'), 'location_id' => $make('locations', 'BLoc', 'BLC'), 'department_id' => $make('departments', 'BDept2', 'BD2'),
                'designation_id' => $make('designations', 'BDes', 'BDS'), 'employee_type_id' => $make('employee-types', 'BT', 'BTT')], 'confirm_duplicates' => true,
        ]);

        return $e->uuid;
    }
}
