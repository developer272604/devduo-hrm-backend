# People insights API

Timeline, Organization Memory, Employee 360, Data Quality, Action Center, completeness, org chart and dashboard.
All paths are under `/api/v1`, require the `people` module, the session + CSRF of the normal API, and are tenant-scoped.
An employee outside the viewer's data scope (or another tenant) is always **404**. Paginated lists use Laravel pagination
(`data`, `current_page`, `last_page`, `total`, `per_page`).

Frontend route contract used inside server-generated links: employee record `/employees/{uuid}` (`?section=...`), list drill-down
`/employees?insight={key}`, quality screen `/people/quality`.

## Endpoints

| Method + path | Permission | Notes |
| --- | --- | --- |
| GET `employees/{uuid}/timeline` | people.view | `group`, `type`, `from`, `to` (Y-m-d), `per_page`. Also returns `groups` |
| GET `people/timeline/types` | people.view | catalogue `{type, category, icon, label}` + group labels |
| GET `employees/{uuid}/memory?date=` | people.view | as-of snapshot, field-filtered |
| GET `people/memory/headcount?date=&by=department\|location\|legal_entity` | people.view | counts only, scope-limited; 403 when directory privacy hides the dimension |
| GET `employees/{uuid}/360` | people.view | `{employee_uuid, sections:[{key,title,order,source,items}]}` |
| GET `employees/{uuid}/completeness` | people.view | `{complete, missing:[{key,label,detail,action}], total_rules, satisfied, percentage}` |
| GET `people/completeness/summary` | people.quality.view | `{employees, incomplete, items:[{key,label,count}]}` |
| GET `people/quality/findings` | people.quality.view | filters `severity`, `category`, `status`, `rule` (comma lists), `employee` (uuid) |
| GET `people/quality/summary` | people.quality.view | `open`, `by_severity`, `by_category`, `by_rule`, `by_status`, `last_scan_at` |
| POST `people/quality/scan` | people.quality.resolve | queues `ScanDataQuality`; 202; throttled 3/min |
| POST `people/quality/findings/{id}/{resolve\|dismiss\|acknowledge\|assign\|reopen}` | people.quality.resolve | body: `resolution` (resolve/dismiss), `assignee_id` (assign) |
| GET `people/action-center?domain=` | people.quality.view | `{data:[insight]}` |
| GET `people/action-center/{key}/employees` | people.quality.view | everyone behind an insight; `?format=csv` needs `people.export` |
| POST `people/action-center/remind` | people.quality.resolve | `{topic, employee_uuids[] \| insight}`; throttled 10/min |
| POST `people/action-center/tasks` | people.quality.resolve | `{insight, assignee_id?}` creates one My Work task |
| GET `people/org-chart` | people.view | `root`, `depth` (1-3), `department_id`, `location_id`, `q`, `page`, `per_page` |
| GET `people/org-chart/{uuid}/children` | people.view | one level, paginated |
| GET `people/org-chart/search?q=` | people.view | min 2 chars; each hit carries `ancestors` |
| GET `people/dashboard` | people.view | see below |

### Timeline entry
`{uuid, type, category, icon, label, summary, occurred_at, effective_date, future_dated, actor_name, details}`.
Entries come only from `domain_events`. Visibility is `FieldAccess::viewClass(viewer, event.classification, employee, relation)`
(relation computed once); hidden classes never appear in pages or totals. `actor_name` only for viewers with `people.personal.view` /
`people.confidential.view`, or when the actor is the viewer. `details` is rebuilt field by field: `reason`, `from`, `to`, `fields`,
`before`/`after` (names, not ids, only for fields the viewer may see), `from_manager`/`to_manager`, and `extra` (see below).

### Memory snapshot
`{employee_uuid, date, employed, status, assignment:{effective_from, effective_to, names:{department, ...}, work_mode?, ...}, managers:{primary:[{employee_uuid,name,effective_from,effective_to}]}, policies:{kind:{reference_id,...}}}`.
Fields the viewer may not see are omitted. Other server modules call the service directly:
`app(OrganizationMemory::class)->snapshot($employee, $date)`, `->employmentOn($personId, $date)`, `->snapshotForPerson(...)`,
`->headcount($tenantId, $date, $dimension, ?callable $constrain)`.

### Quality finding
`{id, rule, rule_label, category, severity (info|warning|action_required|critical), title, detail, status (open|acknowledged|resolved|dismissed), employee:{uuid,name}|null, detected_at, last_seen_at, resolved_at, resolution, assignee:{id,name}|null, details, requires_resolution_text}`.
Visible = data scope (people.quality.view) AND the finding's classification via FieldAccess (managers see findings about their reports
only for manager-sensitive data; HR-confidential findings need `people.confidential.view`). Duplicate findings and findings without an
employee are visible to tenant-wide viewers only. Duplicate flow: `resolve` requires a `resolution` text; `dismiss` ("Not a duplicate")
survives every later scan. Nothing is ever merged or corrected automatically. `assign` needs an active user of the tenant and creates (once)
a My Work task (`source_module=people`, `source_entity=quality:{id}`, `action=/employees/{uuid}`); resolving/dismissing/auto-resolving completes it.

### Insight
`{key, domain, severity, title, count, description, actions:[{label, kind: navigate|api, href | endpoint+method[+body], requires_confirmation}], sample:[{employee_uuid,name}]}`.
People insights: `people.missing_documents`, `people.probation_due`, `people.work_authorization_expiring`, `people.contracts_expiring`,
`people.pending_profile_changes` (needs people.changes.approve), `people.incomplete_profiles`, `people.quality_issues`, `people.without_manager`.
Viewers without `people.quality.resolve` only get "Review employees" (and "Export list" with `people.export`). Reminder topics:
`missing_documents`, `incomplete_profile`, `expiring_documents`, `information_request`. Reminder response:
`{sent, skipped:[{employee_uuid, reason: not_found|recently_reminded|no_linked_user}]}` (de-duplicated per employee and topic for 24 h; max 100 employees).

### Org chart
Node: `{uuid, name, status, designation, department|null, location|null, has_photo, is_self, children_count, has_children, in_scope, ancestors?, children?}`.
`mode` is `tree` (roots = in-scope employees whose manager is missing or outside the viewer's scope), `root`, `children`, `search` or `filtered`.
`ancestors` are `{uuid, name}` only, top first, so the chart stays connected above the viewer's scope. Directory privacy hides
department/location/photo (and the ancestor chain when `manager` is private) from non-HR coworkers. Never loads the full tree: a call reads one level.

### Dashboard
Sections, each `{count, items[]}` (max 10 items): `new_joiners` (30 days), `upcoming_joinings` (30 days), `work_anniversaries` (this month, with `years`),
`birthdays` (`visible` flag; only HR or tenant privacy `date_of_birth`; items carry `day` and `month` only), `probation_due`, `expiring_documents`
(`kind`, `label`, `expiry_date`, `expired`); plus `incomplete_profiles {count}` and `pending_changes {count}`. Empty sections are empty arrays.

### Settings read by these features (`tenants.settings.people`)
`manager_required` (default true), `expiry_window_days` (default 60), `completeness.required` (rule keys; default all of
`emergency_contact, address, bank_account, identity_document, photo, mandatory_documents, manager, work_email`),
`completeness.include_custom_fields` (default true), `quality.required_fields` (default `work_email, department, designation, location`),
`directory_privacy.*`. Country expectations: `config('hrm_countries.<CC>')` keys `required_identifiers`, `bank_required`, or
`identifier_types[].required = true`.

## Extension points (Phase 3 and later)

Register in your own `app/Domain/<Module>/Boot/<Name>Boot.php` (`public static function boot(Application $app)`); class names are
resolved lazily, so booting never touches the database.

```php
$app->make(\App\Domain\Insights\ActionCenter::class)->register(AttendanceInsightProvider::class);
$app->make(\App\Domain\People\Employee360\Employee360::class)->register(AttendanceEmployee360Provider::class);
$app->make(\App\Domain\Quality\DataQualityEngine::class)->register(AttendanceQualityRule::class);
\App\Domain\People\Insights\DomainEventTypes::register('AttendanceFlagged', 'attendance', 'clock', 'Attendance flagged');
```

* **InsightProvider** (`key()`, `insights(User $viewer): array`) returns only insights with `count > 0` that THIS viewer may see; each needs at least
  one valid action or `ActionCenter` drops it. Use `ViewerRelations::for($viewer)` + `DataScope` for scope and class visibility.
* **Employee360Provider** (`key()`, `sections(User $viewer, Employee $employee, array $relation)`) returns sections
  `{key,title,order,items:[{key,label,value,detail?,date?,href?,source}]}`. Facts with a source only, no conclusions; respect permissions
  using the supplied `relation` (computed once per request). A failing provider is logged and skipped.
* **QualityRule** (`key()`, `category()`, `label()`, `scan(int $tenantId): iterable<Finding>`) is read-only and streams `Finding(employeeId, fingerprint, title, detail, severity, subject, details, classification)`.
  The fingerprint must be stable for the same problem (no counts or dates that change). Engine semantics: upsert by `(tenant, rule, fingerprint)`;
  refresh `last_seen_at`; auto-resolve open/acknowledged findings no longer detected ("No longer detected") and complete their task; reopen resolved findings that reappear;
  never touch dismissed ones; if a rule throws nothing is auto-resolved for it. Set `classification` to the class of the data described (defaults to hr_confidential).
* **Timeline**: record events with `DomainEvents::record($type, [... 'classification' => ...])`. Payload keys shown to viewers are `reason`, `from`, `to`, `fields`,
  `before`, `after`; put anything else a viewer who can see the entry may know under `payload.details`. Tasks about an employee should carry `metadata.employee_uuid`
  so Employee 360 "pending actions" can find them.

## Scheduled commands (artisan, per tenant via TenantRunner; the lead schedules them)
| Command | Frequency |
| --- | --- |
| `people:probation-reminders` | daily, early morning (idempotent per 14/3-day window) |
| `people:advance-lifecycle` | hourly (idempotent; tenants span timezones) |
| `people:scan-quality` | daily, night (`--tenant=ID` for one tenant) |
