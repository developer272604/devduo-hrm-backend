# People operations API

Import, export, bulk actions and saved views. All routes live under `/api/v1/people/...`, require the `people` module and a signed-in session (cookie + CSRF like the rest of the API). Cross-tenant ids return **404**. Validation errors are `422 {message, errors}`; plain business refusals are `422 {message}` / `409 {message}`.

Heavy work (import commit, export, large bulk runs) runs on the queue; poll the resource until `status` is final.

## Imports (`people.import`)

Statuses: `uploaded -> validated -> processing -> completed | partial | failed`, and `rolled_back`.
Row statuses: `pending | valid | warning | error | imported | skipped`.

| Method + path | Purpose |
| --- | --- |
| `GET /people/imports/template?format=csv\|xlsx` | Download the template (all supported columns plus `cf:<key>` for active employee custom fields). |
| `POST /people/imports` | Multipart: `file` (csv/xlsx, <= 10 MB, <= 20,000 rows) and optional `options[...]`. Returns `201` with `uuid`, `headers`, `columns[{header,suggested,samples}]`, `mapping` (suggested), `targets[{key,label,group,required}]`, `missing_required`. |
| `PUT /people/imports/{uuid}/mapping` | Body `{mapping:{target: "File header"}, options?}`. Resets validation. |
| `POST /people/imports/{uuid}/validate` | Dry run (nothing is written to employee tables). Returns counts, up to 50 `problem_rows`, `requires_duplicate_decision`. |
| `POST /people/imports/{uuid}/commit` | Body `{accept_errors?: bool}`. `202`; queued job. `accept_errors` is required when rows have errors (only valid rows are imported). 422 if duplicate warnings exist and neither `skip_duplicates` nor `allow_duplicates` is set. 409 if already running/done. |
| `GET /people/imports` | History (paginated). |
| `GET /people/imports/{uuid}` | Status, `counts{valid,warning,error,imported,skipped}`, `processed_rows`, `mapping`, `options`, `error`. |
| `GET /people/imports/{uuid}/rows?status=error,warning&page=&per_page=` | Paginated rows: `row_number, status, raw, summary, errors[{field,message}], warnings[{field,code,message,matches?}], employee_uuid`. |
| `PATCH /people/imports/{uuid}/rows/{n}` | `{values:{"File header": "new value"}}`; row becomes `pending` (re-validate or retry). |
| `GET /people/imports/{uuid}/errors.csv` | Error rows with original values, a `row_number` column and an `error_reason` column. |
| `POST /people/imports/{uuid}/retry` | Optional multipart `file` = corrected errors.csv (matched by `row_number`). Re-validates unresolved rows and imports the ones that are now valid. Only for `partial|failed|completed`. |
| `POST /people/imports/{uuid}/rollback` | Soft-deletes employees created by the import, only if none has had later activity. Otherwise `409 {code:"rollback_blocked", blocked:[{employee_uuid,employee_number,name,reasons[]}]}` and nothing changes. |
| `DELETE /people/imports/{uuid}` | Discard an import that created nothing. |

Options: `date_format` (`Y-m-d` default, `d/m/Y`, `m/d/Y`; ISO dates and Excel date cells are always accepted), `skip_duplicates`, `allow_duplicates` (mutually exclusive), `default_country`, `default_timezone`, `default_worker_type`.

Supported columns: `employee_number, first_name, middle_name, last_name, preferred_name, gender, date_of_birth, personal_email, personal_phone, work_email, work_phone, worker_type, joined_on, country, timezone, legal_entity, location, department, designation, employee_type, business_unit, team, cost_center, grade, level, manager_employee_number, probation_applicable, probation_end, contract_end, notice_period_days` and `cf:<key>`. Organization columns accept the record code or name; unknown values give "Department 'Salse' was not found. Did you mean 'Sales'?". Required: `first_name`, `joined_on` (and `employee_number` when numbering is manual). A manager may be in the same file; rows are imported in dependency order.

Duplicates: same employee number / work email twice in the file, or a number already in use, are **errors**. Probable duplicates (existing people or other rows: personal email, phone, name + date of birth, work email of an existing person) are **warnings** with `code: "possible_duplicate"`.

## Exports (`people.export`)

| Method + path | Purpose |
| --- | --- |
| `GET /people/exports/columns` | Columns the viewer may export: `{key,label,field,classification,sensitive,default}` plus `defaults`. HR-confidential columns need `people.export_sensitive`; highly sensitive data (identity, bank, salary) is never exportable. |
| `POST /people/exports` | `{format:"csv"\|"xlsx", filters?, selected_uuids?[], columns?[]}`. `filters` use the directory keys (`q,status,worker_type,department_id,designation_id,location_id,business_unit_id,team_id,legal_entity_id,employee_type_id,manager,skill_id,tag_id,joined_from,joined_to,sort,dir`). Requesting a column the user may not export is a `422` naming the reason. `202` with the export. |
| `GET /people/exports` / `GET /people/exports/{uuid}` | Own exports. `status: queued\|processing\|completed\|failed\|expired`, `row_count`, `expires_at`, `download_url`. |
| `GET /people/exports/{uuid}/download` | Owner only. `410` after expiry (24 h by default; files are purged by `people:purge-exports`, scheduled hourly). |

Rows honor the viewer's data scope. Text cells starting with `= + - @` are prefixed with `'` (formula-injection safe).

## Bulk actions (`people.bulk` + the action's permission)

| Method + path | Purpose |
| --- | --- |
| `GET /people/bulk/actions` | `[{key,label,description,permission}]` the viewer may run. Built-in: `assign_department {department_id}`, `assign_location {location_id}`, `assign_employee_type {employee_type_id}` (all with `effective_from`, `reason`), `assign_manager {manager_uuid}`, `add_tag` / `remove_tag {tag_id}`, `activate`, `deactivate` (`reason`, `effective_date`), `export {format, columns?}`. |
| `POST /people/bulk/preview` | `{action, params, employee_uuids[] \| filters}`. Returns `affected_count`, `counts{ok,skipped,error}`, `rows[{uuid,employee_number,name,status,reason}]` (errors first, max 500, `rows_truncated`), `will_queue` (> 100), `confirmation_token`, `expires_at` (15 min). Employees outside the viewer's `people.manage` scope appear as "Employee not found.". |
| `POST /people/bulk/apply` | `{confirmation_token}`. Token is bound to user, tenant, action, exact employee set and params, and is single use. `200` (done) or `202` (queued) with `{uuid,status,total,applied,skipped,failed_count,failed[{uuid,employee_number,name,reason}],result}`. |
| `GET /people/bulk/runs`, `GET /people/bulk/runs/{uuid}` | Run history / polling. |

Other modules add actions with `app(BulkActions::class)->register(new MyAction)` (interface `App\Domain\People\Operations\BulkAction`).

## Directory ergonomics and saved views (`people.view`)

| Method + path | Purpose |
| --- | --- |
| `GET /people/directory/columns` | `[{key,label,default_visible,sortable,sort_key}]` for column customization. |
| `GET /people/views` | Own views plus views shared by others (`owned`, `read_only`, `owner`). |
| `POST /people/views` / `GET\|PUT\|DELETE /people/views/{uuid}` | `{name, filters?, columns?, sort?:{key,dir}, shared?}`. Filter keys and columns are validated; names are unique per user. Only `people.settings` users may set `shared:true`; shared views are read-only for everyone but the owner (403). A view stores filters/columns/sort, never data. |

## Spreadsheet utility (backend, reusable)

`App\Support\Spreadsheet\TabularReader::open($path, $originalName, $options)` (`rows()`, `chunks()`, `headers()`, `rawHeaders()`, `sample()`), `TabularWriter::open($format, $path, $headers)` (`write()`, `close()`), `Template::download($columns, $examples, $format, $basename)`, `SpreadsheetException` (renders as 422).
