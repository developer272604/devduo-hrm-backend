# Architecture — People (Phase 2) and Time & Attendance (Phase 3)

A modular monolith on Laravel 12 (REST, session + CSRF) and React 19 (Vite, Tailwind 4). No microservices, no extra
infrastructure beyond the database queue already configured.

## 1. Tenancy

* Every tenant-owned table has `tenant_id` (restrictOnDelete). Child tables repeat it on purpose: each query is independently
  tenant-scoped, background jobs and exports filter without a join, and a missed scope is a one-column bug rather than a
  join bug. Query access goes through `App\Domain\Tenancy\TenantRecord` (global scope + `tenant_id` stamped on create).
* `TenantContext` is set by the `tenant` middleware from the authenticated user; jobs use `TenantRunner`. After an HTTP
  request in tests the context is cleared: call `$this->context($tenantId)` before querying models directly.
* Raw `DB::table(...)` queries must filter `tenant_id` explicitly.
* Cross-tenant access is reported as **404**, never 403 or 422 (no existence leaks).

## 2. Identity model

| Concept | Table | Notes |
| --- | --- | --- |
| Person | `people` | who they are; survives rehire; `user_id` links the login |
| Employment | `employees` | one row per employment period (`employment_sequence`, `rehire_of_id`) |
| Job info | `employee_assignments` | effective-dated SCD2 rows; never overlap |
| Reporting | `employee_reporting_relationships` | effective-dated; primary / functional / dotted_line / hr_partner / custom |
| Personal sections | `employee_*` keyed by `person_id` | contacts, addresses, emergency, dependents, education, experience, skills, certs, identity, work auth, bank |
| Employment sections | keyed by `employee_id` | notes, tags, status history, separation, policy references |

`User` is authentication only. Public identifiers are UUIDs (`HasPublicId`); API paths use the employee UUID.

## 3. Effective dating (Organization Memory)

Historical truth is never overwritten. `AssignmentService::change` and `ReportingService::assign` *close* the covering
row (`effective_to = from-1`) and insert a new one that ends before the next future row. Overlap and gap checks are
available (`integrityProblems`). Reads are as-of a date. Domain events add the human-readable "why" and feed the
timeline. Attendance, leave and payroll must resolve organization data **as of the date being calculated**, never "now".

## 4. Authorization (three independent layers, all server-side)

1. **RBAC** — permission keys in `WorkspaceProvisioner::PERMISSIONS`, checked by `permission:` middleware.
2. **Data scope** — `App\Domain\Access\DataScope`: union of role scopes (self, direct_reports, all_subordinates, department,
   business_unit, location, legal_entity, custom_group, explicit, tenant) and `user_data_scopes` grants, evaluated per
   permission against current assignments/relationships. Use `constrain($query, $user, $permission, $employeeColumn)` for lists
   and `canAccess()` for single subjects.
3. **Field-level** — `App\Domain\Access\FieldAccess` + `FieldRegistry` + `Classification`: public_internal, employee,
   manager_sensitive, hr_confidential, highly_sensitive (masked unless `people.sensitive.reveal`), system_restricted. Tenants
   can override classification, self-service edit rules (`direct|request|none`) and directory privacy in `settings.people`.

List endpoints never return a field the viewer cannot see; the UI renders what the API returns and the `viewer.edit` map.

## 5. Cross-module integration (no tight coupling)

* `DomainEvents::record($type, [...])` writes an append-only `domain_events` row (timeline + Organization Memory) and fires
  `DomainEventRecorded`. Listeners live in `app/Listeners/**` (auto-discovered). Modules never call each other for side effects.
* My Work: `TaskService::createOnce()` / `completeBySource()`. Notifications: `NotificationService::send()`.
  Audit: `Audit::record(action, module, resource, context)` using `employee:{uuid}` style resources.
* Insights: modules register an `InsightProvider` (Action Centers) and an `Employee360Provider` (Employee 360 sections).
* Data quality: modules register `QualityRule`s with `DataQualityEngine`.
* Leave (Phase 4) is consumed through the `LeaveDataProvider` contract (null implementation in Phase 3).

## 6. Conventions

* Controllers are thin; business rules live in `app/Domain/**` services. Controllers extend `People\PeopleController`.
* Validation messages are user-facing sentences. Never expose SQL or stack traces.
* Lists use Laravel pagination (`data`, `current_page`, `last_page`, `total`, ...), whitelisted sort keys, `per_page` ≤ 100.
* Mutations that touch more than one row use `DB::transaction`; hierarchy edits lock the tenant row; employee edits lock the employee row.
* Idempotency: client-visible creates accept an `Idempotency-Key` header or `idempotency_key` field where retries are likely.
* Dates: date-only business values are `Y-m-d`; instants are stored in UTC.
* Heavy work (imports, exports, recalculation) runs on the queue (`ShouldQueue` jobs carrying `tenant_id`, executed inside `TenantRunner`).

## 7. Schema

See `database/migrations/2026_10_07_000001_create_people_domain.php` (Phase 2) and the Phase 3 attendance migrations.
Indexes follow the dominant queries (tenant + employee + date, tenant + status, tenant + effective period).
