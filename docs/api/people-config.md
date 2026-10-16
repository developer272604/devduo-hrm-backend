# People admin configuration API

All paths are under `/api/v1` (authenticated session, tenant-scoped, `module:people`). Errors: `422` validation
(`{message, errors:{field:[...]}}`), `403` missing permission, `404` unknown or cross-tenant id (never reveals existence).
Config objects (lookups, custom fields, groups, grants) use numeric `id`; employees are always addressed by `uuid`.

## 1. People settings (`people.settings`)

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/people/settings` | Effective config + `catalogue` |
| PUT | `/people/settings/{section}` | Sections below; returns the same shape as GET |
| GET | `/people/settings/preview-numbering` | Query: `pattern?`, `start?`, `count?` (1-5), `employee_type_id?`, `legal_entity_id?`, `location_id?`, `country?`. Returns `{pattern, mode, samples[], current_next}`. Never consumes a sequence. |

GET response keys: `numbering {mode, pattern, scope, start, rules[]}`, `edit_rules {group: direct|request|none}`,
`directory_privacy {key: bool}`, `field_policies {"entity.field": classification}` (overrides only),
`completeness {required[]}`, `probation_days`, `document_expiry_days`, `default_country`,
`statuses {transitions {status: [allowed targets]}, labels {status: label}}`, and `catalogue`:
`field_groups[{key,default,sensitive,never_direct}]`, `fields[{key,group,privacy_key,default_classification,classification,allowed_classifications[]}]`,
`classifications[{value,label}]`, `numbering_tokens`, `numbering_scopes`, `numbering_match_keys`, `edit_rule_values`, `privacy_keys`,
`completeness_rules[{key,label,description}]`, `completeness_patterns`, `statuses[{code,label,default_transitions}]`, `worker_types`.

PUT bodies (dictionary sections are patches: a key set to `null` resets to default; every change is audited with before/after):

| Section | Body | Rules |
| --- | --- | --- |
| `numbering` | `{mode: auto\|manual, pattern, scope: tenant\|legal_entity\|location\|employee_type, start, rules: [{match:{employee_type_id\|legal_entity_id\|location_id\|worker_type\|country}, pattern}]}` | Pattern needs exactly one `{SEQ:n}`/`{SEQ}`; other tokens `{LE} {LOC} {TYPE} {COUNTRY} {YYYY} {YY}`; literals `A-Z a-z 0-9 - _ / . space`; max 60 chars; `match` non-empty, ids must exist in tenant. `rules` replaces the list. |
| `edit_rules` | `{rules: {group: direct\|request\|none\|null}, confirm_sensitive?: bool}` | Groups are the catalogue `field_groups` (includes `custom` for custom fields). `bank`/`identity` -> `direct` needs `confirm_sensitive=true`; `employment`/`job`/`note` can never be `direct`. |
| `directory_privacy` | `{privacy: {work_email, work_phone, personal_phone, date_of_birth, manager, department, location, photo, employee_number, pronouns: bool\|null}, confirm_sensitive?}` | Turning on `personal_phone`/`date_of_birth` needs `confirm_sensitive=true`. |
| `field_policies` | `{policies: {"person.gender": classification\|null}}` | Keys must be in the catalogue; `highly_sensitive` fields cannot go below `hr_confidential`. |
| `completeness` | `{required: [rule keys]}` (replaces) | Keys from `completeness_rules`, `field:<registry key>` or `custom:<custom field key>`. |
| `general` | `{probation_days 0-730, document_expiry_days 1-365, default_country ISO-2}` | Patch. |
| `statuses` | `{transitions: {status: [targets]\|null}}` | Known statuses only; `archived` stays final; `separated` may only go to `archived`; non-terminal statuses need an exit; no transition back to `preboarding`. |

## 2. Lookups

`kind` in `employment_status, separation_reason, relationship_type, document_category, skill_category, termination_reason`.

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/people/lookups/{kind}` (`?active=`) | `people.view` (seeds system defaults on first read) |
| POST | `/people/lookups/{kind}` `{label, code?, active?, sort?, metadata?}` | `people.settings` |
| PUT | `/people/lookups/{kind}/{id}` `{label?, active?, sort?, metadata?}` | `people.settings` |
| DELETE | `/people/lookups/{kind}/{id}` | `people.settings` |

Row: `{id, kind, code, label, active, system, sort, metadata, in_use, can_delete}`. Code is a slug, immutable. System rows and in-use rows
cannot be deleted (deactivate). `employment_status`: labels only (no new codes, no deactivation).

## 3. Custom sections and fields

Admin (`people.settings` to write; `people.settings` or `people.manage` to read):

| Method | Path |
| --- | --- |
| GET | `/people/custom-fields/catalogue` (types, classifications, validation rules per type, permissions, limits) |
| GET/POST | `/people/custom-sections`; PUT/DELETE `/people/custom-sections/{id}` |
| GET/POST | `/people/custom-fields` (`?entity=&section_id=&type=&active=`); GET/PUT/DELETE `/people/custom-fields/{id}` |
| POST | `/people/custom-fields/{id}/options`; PUT/DELETE `/people/custom-fields/{id}/options/{optionId}` |

Section: `{key, label, description, countries[]|null, employee_types[]|null (ids), view_permission|null, classification, sort, active}`.
Field: `{entity: employee|person, key, label, description, type, section_id, required, default, validation, classification, employee_editable,
manager_editable, hr_editable, countries, employee_types, effective_dated, active, sort, options?[{value?,label,sort?,active?}]}`.
Types: `text long_text number currency date datetime boolean single_select multi_select email phone url employee_ref department_ref location_ref country attachment_ref`.
Validation keys per type: text/long_text `min_length,max_length,pattern`; email/phone/url `max_length`; number `min,max,decimals`; currency `+currency`;
date/datetime `min,max`; multi_select `min,max` (counts). Patterns are length-capped and vetted (no nested quantifiers, backreferences, lookbehind, recursion).
`key`, `entity`, `type` and option `value` are immutable; keys are unique per tenant across entities. Class `system_restricted` is not assignable.
Fields/options with stored values cannot be deleted: set `active:false` (values are kept, field hidden). Classification of the section is a floor combined with the field's.

Values format: text/number as JSON scalars; `date` `Y-m-d`; `datetime` ISO-8601 (stored UTC); `boolean`; select -> option `value`; multi -> array of values;
`employee_ref` -> employee uuid; `department_ref`/`location_ref` -> id; `country` ISO-2; `attachment_ref` -> the employee's document uuid.

Employee values (`people.view`; authorization per field inside):

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/employees/{uuid}/custom-fields` | `{sections:[{id,key,label,description,fields:[{id,key,label,type,entity,required,effective_dated,validation,default,options[],value,display,masked,effective_from,editable,edit_mode}]}]}`; hidden fields are omitted |
| PUT | `/employees/{uuid}/custom-fields` | `{values:{key: value \| {value, effective_from}}, effective_from?}`; returns the same as GET |
| GET | `/employees/{uuid}/custom-fields/{key}/history` | `{data:[{effective_from,effective_to,value,display,masked}]}` |

PUT errors: `422` invalid values (`errors["values.<key>"]`), `403` `{code:"fields_not_editable", fields:[{key,label}]}`, `409` `{code:"change_request_required", fields}` when tenant `edit_rules.custom=request`.
Effective-dated fields insert a new row (closing the previous one) instead of overwriting.

## 4. Employee groups

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/people/groups` (`?q=&active=&per_page=`), `/people/groups/{id}`, `/people/groups/{id}/members` | `people.view` (members limited to the viewer's data scope) |
| POST/PUT/DELETE | `/people/groups`, `/people/groups/{id}` `{name, code?, description?, active?}` | `people.settings` |
| POST | `/people/groups/{id}/members` `{employees:[uuid], effective_from?, effective_to?}` -> `{added, updated}` | `people.settings` |
| DELETE | `/people/groups/{id}/members/{employeeUuid}` | `people.settings` |

A group cannot be deleted while it has members or grants; deactivate it.

## 5. Data-scope grants (`users.manage`)

| Method | Path |
| --- | --- |
| GET | `/access/data-scopes` (`?user_id=&kind=`) paginated `{id, user{id,name,email}, kind, reference_id, reference_label}` |
| POST | `/access/data-scopes` `{user_id, kind: department\|business_unit\|location\|legal_entity\|employee_group\|employee, reference_id \| reference_ids[]}` -> `{grants[], created}` (idempotent; 201 if new, else 200) |
| DELETE | `/access/data-scopes/{id}` |
| GET | `/access/data-scopes/catalogue` (`users.view\|users.manage\|roles.manage`) -> `{scopes:[{scope,label,description,uses_grants}], grant_kinds}` |

`reference_id` is the numeric id, except for `kind=employee` where it is the employee **uuid**. References must belong to the tenant (422 otherwise).

## PHP services for other modules

`CustomFieldService` (`definitionsFor`, `valuesFor`, `sectionsFor`, `setValues`, `historyFor`, `missingRequired`, `applyDefaults`), `Lookups` (`ensureDefaults`, `active`, `labels`, `label`, `isActive`),
`NumberingPattern` (`error`, `render`), `PeopleSettingsService`.
