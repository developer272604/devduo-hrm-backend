# Payroll configuration and compensation API (Phase 5, workstream A)

Code: `backend/app/Domain/Payroll/{Groups,Calendars,Periods,Components,Structures,Compensation,CountryPacks,Calculation}`; controllers `App\Http\Controllers\Payroll\{Config,Compensation}`; routes `routes/payroll/config.php` (prefix `/api/v1/payroll`) and `routes/payroll/compensation.php` (prefix `/api/v1/compensation`). Migration `2026_10_15_A00001_payroll_configuration_support.php` (period type / cycle / parent). All endpoints are module-gated (`payroll`), tenant-scoped (cross-tenant = 404), authorised per route, and employee-bound ones also apply the data scope of the permission. Money is always a decimal **string**. Validation errors are Laravel 422 `{message, errors:{field:[...]}}`.

Permission shorthand: **CFG** = `payroll.configure`; **READ** = `payroll.configure|payroll.compensation.manage|payroll.view`; **PACK** = `payroll.country_pack.manage|payroll.configure`; **PUB** = `payroll.country_pack.publish`; **CV/CM/CA** = `payroll.compensation.view/manage/approve`.

## 1. Groups and employee resolution (`/api/v1/payroll`)
| Method path | Perm | Notes |
| --- | --- | --- |
| `GET groups` | READ | paginated; `{uuid,name,code,legal_entity_id,country,currency,timezone,frequency,active,cutoff_day,employee_category,worker_type,settings,description,country_pack_uuid}` |
| `POST groups` | CFG | body as before (`name,code,legal_entity_id,country,currency,timezone,frequency`) plus optional `employee_category,worker_type,cutoff_day,settings,description`; 201 `{uuid}` |
| `GET groups/{uuid}` / `PATCH groups/{uuid}` | READ / CFG | patch: `name,active,cutoff_day,settings,description,employee_category,worker_type,country_pack_uuid` (pack country must equal group country) |
| `GET groups/{uuid}/employees?from&to` | CFG | employees in the group at any time in the range (data scoped) |
| `POST groups/{uuid}/employees` | CFG | `{employee_uuid, effective_from, effective_to?}`; ends the employee's previous group the day before; refuses a start on/before an existing later assignment and entity mismatch (422). Source of truth is `employee_policy_references(kind=payroll_group)` shared with Attendance |
| `GET employees/{uuid}/group?date=` | CFG | `{employee_uuid,date,group|null}` |
| `GET group-conflicts` | CFG | employees with overlapping group assignments (should be empty) |

## 2. Calendars and periods
| Method path | Perm | Notes |
| --- | --- | --- |
| `GET groups/{uuid}/calendars` | READ | |
| `POST groups/{uuid}/calendars` | CFG | `{name,frequency (must equal group),anchor_date? (weekly/biweekly/four_weekly required),effective_from,effective_to?,rules?}`. rules: `split_day` (semi_monthly), `ranges[{start,end,label}]` (custom), `variable_pay_cutoff_days_before_end, attendance_cutoff_days_before_end, leave_cutoff_days_before_end, input_cutoff_days_before_end, approval_days_after_end, payment_days_after_end` |
| `POST calendars/{uuid}/generate` | CFG | `{from,to}` periods whose start lies in range; idempotent; 201 `{created,skipped}` |
| `GET periods?group_uuid&state&period_type&from&to&per_page` | READ | `{uuid,label,period_type,cycle_no,period_start,period_end,state,tax_year,currency,attendance_cutoff,leave_cutoff,variable_pay_cutoff,input_cutoff,approval_deadline,payment_date,allowed_next[]}` |
| `POST groups/{uuid}/periods` | CFG | off-cycle/bonus/correction: `{period_type: off_cycle|bonus|correction, label, parent_period_uuid? | period_start+period_end, payment_date?, notes?}`; may share dates of a regular period; `cycle_no` increments |
| `GET periods/{uuid}` | READ | period + `transitions[]` |
| `POST periods/{uuid}/transition` | CFG | `{to, reason?}`; legal moves per `PayrollStates::PERIOD_TRANSITIONS`, 422 otherwise; `reopened` needs a reason; audited and stored in `payroll_period_transitions`. (Workstream B drives run-linked transitions) |

Frequencies: `weekly, biweekly, semi_monthly, monthly, four_weekly, custom`; leap years follow calendar months.

## 3. Components, formulas
`GET components` (READ, `?kind=`), `POST components` (CFG), `GET components/{uuid}`, `PATCH components/{uuid}` (only while unpublished and unused, else 422), `POST components/{uuid}/versions` (`effective_from` required, any other field to change; closes the previous version the day before), `POST components/{uuid}/publish` (makes the version immutable).
Fields: `code,name,kind,currency,effective_from,effective_to,formula,payslip_visible,gl_code,nature(fixed|variable),country,calc_method(fixed|percentage|formula|rate|input),tax_classification,proration(none|calendar_days|working_days|payable_days),attendance_dependent,lop_dependent,overtime_dependent,rounding_mode,rounding_scale,rounding_increment,min_amount,max_amount,statutory_mapping,active,sequence,config`. Response adds `version,published,in_use`. `percentage` needs `config:{base_component,percent}`.
`POST formulas/check` (CFG): `{formula, variables?, component_code?}` -> `{valid,problems[],references[],allowed_variables[],result?{expression,variables,scale,value}}`. Never persists.

Formula grammar (no eval, exact decimals): `+ - * /`, parentheses, `n%`, comparisons `> < >= <= == !=` (1/0), `AND OR NOT`, functions `MIN MAX` (2+ args), `ROUND ROUNDUP ROUNDDOWN (x[,places 0-6])`, `FLOOR CEIL ABS`, `IF(cond,a,b)`, `CLAMP(x,lo,hi)`. Variables: `BASE GROSS ANNUAL HOURLY_RATE DAILY_RATE` (compensation time), `WORKING_DAYS CALENDAR_DAYS PAYABLE_DAYS PRESENT_DAYS LOP_DAYS OVERTIME_UNITS PERIODS_PER_YEAR` (run time; formulas using them are *deferred*), other component codes, `RULE_*` (country rule outputs). Self reference and cycles are rejected.

## 4. Salary structures (`/api/v1/compensation`)
`GET structures` (READ), `GET structures/{uuid}`, `POST structures` (CFG) `{code,name,effective_from,effective_to?,currency?,country?,rules?{base_component},description?,components:[{component_code,sequence?,required?,overrides?}]}`, `POST structures/{uuid}/versions` (same body minus code/name), `POST structures/{uuid}/activate`. Draft -> active; activating closes the previous version. Structure + `version` are pinned on the compensation record.

## 5. Employee compensation (`/api/v1/compensation`)
Amounts are returned only to holders of `payroll.compensation.reveal`; others get `masked:true` and `null` amounts. Every read is audited (`compensation.viewed`).
* `GET employees/{uuid}/compensation?as_of=` (CV) -> `{employee_uuid,as_of,compensation|null}`; `GET employees/{uuid}/compensation/history` (CV) newest first, all statuses.
* `POST employees/{uuid}/compensation` (CM): `{group_uuid?,structure_uuid?,effective_from,effective_to?,reason,idempotency_key(uuid),lines:[{component_uuid,amount?}]}` -> 201 `{uuid,status:"pending"}`. `amount` is omitted for percentage/formula/`input` components (computed, with trace and component version in `basis`). Group defaults to the employee's resolved group. Currency must equal group currency; structure must cover the date; negative or missing amounts are rejected. Creates My Work tasks for independent approvers.
* `POST compensations/{uuid}/decision` (CA) `{decision: approved|rejected}`; maker != checker (403), only pending (409). Approving a plain record that overlaps approved history is refused (422).
* Record shape: `{uuid,employee_uuid,employee_name,group_uuid,group_name,currency,effective_from,effective_to,status,reason,can_review,structure,source,pay_frequency,masked,summary{base_pay,gross_reference,annual_compensation,daily_rate,hourly_rate},lines[{component_uuid,code,version,kind,amount,deferred,formula}]}`. `gross_reference` = sum of resolved earning lines per pay period; annual = x periods per year; daily = annual / `calculation.days_per_year`; hourly = daily / `calculation.hours_per_day`.
* The legacy `/api/v1/payroll/{employees,compensations,compensations/{uuid}/decision}` paths still work with the same shapes (additive fields only).

## 6. Revisions (`/api/v1/compensation`)
Body for preview/propose: `{employee_uuid,type(increment|promotion|market_correction|adjustment|transfer|demotion|other),effective_date,structure_uuid?,reason,comments?,letter_reference?,lines[...],idempotency_key (propose only)}`; future-dated and backdated allowed.
* `POST revisions/preview` (CM) -> `{current,proposed,change{gross_reference,percent,annual},backdated,retro_review_required,persisted:false}`; nothing is written. Amount blocks are `null` + `masked:true` without reveal.
* `POST revisions` (CM) -> 201 `{uuid,status:"pending"}`; one pending revision per employee; idempotent on `idempotency_key`.
* `GET revisions?status&employee_uuid` / `GET revisions/{uuid}` (CV); `POST revisions/{uuid}/decision` (CA, maker != checker); `POST revisions/{uuid}/cancel` (initiator); `POST revisions/{uuid}/letter` `{letter_reference}`.
* Approval status becomes `applied`: the previous segment ends the day before, a segment starting the same day is marked `superseded` (never deleted), the new one ends before the next approved segment. Event `payroll.compensation.revised` (no amounts) carries `backdated`, for the retro engine.
* `POST revision-batches/preview` (CM) accepts common revision metadata and up to 100 employee-specific `{employee_uuid,structure_uuid?,lines[]}` rows. It is read-only and returns per-employee validation and impact; salary amounts are masked without reveal.
* `POST revision-batches` (CM) accepts the same payload plus an idempotency key. Valid rows become independent pending revisions that require the existing maker-checker approval; invalid rows remain in the batch error list. `GET revision-batches` (CV) lists at most 50 batches only if the viewer can access every included employee; `GET revision-batches/{uuid}` enforces the same scope. Batch state settles to `applied`, `partially_applied`, or `cancelled` after all included revisions are decided.
* `POST imports/compensation/preview` (CM, multipart form: `file` `.csv`/`.xlsx` up to 10 MB, `type`, `effective_date`, `reason`, optional `mapping[field]`) maps `employee_number`, `component_code`, and `amount` to normalized file headers; header matching ignores case and normalizes spaces. Defaults are auto-detected. It validates rows, encrypts both source rows and canonical values, and returns per-row errors without creating revisions. Files are limited to 10,000 data rows and 100 unique employees. `POST imports/compensation/{uuid}/commit` explicitly submits valid employee groups to maker-checker review; repeating commit returns the same batch. Duplicate employee/component rows are rejected. The Compensation page supports mapping, preview/commit and scoped batch history/details.

## 7. Country packs (`/api/v1/payroll`)
Framework only; no country law is built in. Rules are versioned DATA interpreted by the `declarative` handler (registered in `StatutoryPackRegistry`).
| Method path | Perm |
| --- | --- |
| `GET country-packs`, `GET country-packs/{uuid}` | READ |
| `POST country-packs` `{code,country,name,handler?,description?}` | PACK |
| `POST country-packs/samples/install` (illustrative pack `SAMPLE-ZZ`, flagged non-authoritative) | PACK |
| `POST country-packs/{uuid}/versions` `{effective_from,effective_to?,tax_year_start(MM-DD),metadata,calculation_rules,validation_rules,identifiers,declaration_schema}` | PACK |
| `GET country-pack-versions/{uuid}` (adds `problems[]`), `PATCH` (draft only), `POST .../check`, `POST .../simulate {currency,periods_in_year,amounts{}}` (dry run, never persisted) | READ / PACK |
| `POST country-pack-versions/{uuid}/publish`, `.../verify {note>=10 chars}`, `.../retire {effective_to}`, `POST country-packs/{uuid}/enable|disable` | PUB |
Lifecycle: draft -> published (immutable, checksum) -> retired. Publishing requires valid rules and `metadata.source` + explicit `metadata.requires_expert_validation`; versions must follow each other in time (previous open version is closed the day before). Verification must be by someone other than the publisher. Enabling needs a verified published version unless `calculation.require_pack_verification=false`. `PackResolver::forGroup(groupId,date)` returns the version in force only for an enabled pack (and verified when required); `version(packId,date)` returns the historical version for corrections.
Declarative rules: `calculation_rules.concepts[{id,kind,type: rate|slab|flat|formula,component_code?,base,when[],rate|slabs[{up_to|null,rate}]|amount|formula,wage_cap,wage_floor,min,max,rounding{mode,scale}}]`, `overtime_multipliers`. Numbers are decimal strings (floats refused). The sample pack uses invented numbers and a fictional country `ZZ`: it is not real law.

## 8. Settings and exchange rates
`GET settings` (READ) / `PUT settings` (CFG): sections `calculation` (`rounding_mode,negative_net,zero_pay,days_per_year,hours_per_day,require_pack_verification`) and `currency` (`allow_cross_currency,reporting_currency`). `GET|POST exchange-rates` (READ / CFG) `{base_currency,quote_currency,rate(decimal string),as_of,source?}`; a recorded rate is never overwritten.

## 9. Resolver contracts (for workstreams B, C, D)
`Contracts\CompensationResolver::current(employeeId,date)` / `between(employeeId,from,to)` return approved, non-superseded rows (shape in the interface docblock; each line adds `deferred` and `formula` for run-time evaluation). `Contracts\PackResolver` as above. Bound in `Boot/ConfigBoot.php`. `Groups\GroupResolver` (`groupIdFor`, `segments`, `employeeIds`) is the employee -> group source for the run roster.
