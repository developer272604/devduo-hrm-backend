# Integrations platform API (Phase 14 runtime)

Admin API: `/api/v1/integrations/*` (cookie session + CSRF, module `integrations`). Public API: `/api/public/v1/*` (bearer API key, no session/CSRF).
Permissions: `integrations.view` (read), `integrations.manage` (legacy connection metadata), `integrations.api_clients.manage`, `integrations.webhooks.manage`, `integrations.imports.manage`. Existing tenants get the three new keys through migration `2026_10_31_000002`. Every mutation is audited (`integration.*`). Secrets are never returned except once at creation/rotation.

## Public API v1 (read-only)

Auth: `Authorization: Bearer dvk_<8 chars>.<40 chars>` (or `X-Api-Key`). Unknown, revoked, expired and wrong-tenant keys all return the same `401`. The tenant is taken from the key, never from the request.
Order of checks: key -> tenant module -> IP allow-list (`403`) -> per-client rate limit (`429`, `Retry-After`, `X-RateLimit-*` headers) -> scope (`403 required_scope`). Every authenticated attempt is written to the request audit.

| Method | Path | Scope | Notes |
|---|---|---|---|
| GET | `/api/public/v1/employees` | `employees:read` | `page`, `per_page` (max 100), `status` (default: working statuses), `updated_since`. Fields: `id`, `employee_number`, `display_name`, `status`, `joined_on`, `department_code`; `work_email` only with scope `employees:email`. |
| GET | `/api/public/v1/departments` | `departments:read` | `code`, `name`, `active`. |

Response: `{data:[...], meta:{page,per_page,total,last_page}}`. Field privacy is fixed in code: no date of birth, personal contact, identity, bank, compensation, medical or free text. Deprecation policy: v1 fields are only added, never removed or retyped; breaking changes ship as `/v2` with a sunset notice.

## API clients (`/api/v1/integrations`)

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/api-clients` | view | `data` (no key, no hash) + `scopes` catalog. |
| POST | `/api-clients` | api_clients.manage | `name`, `scopes[]`, `rate_limit_per_minute` (1-6000, default 60), `ip_allowlist[]` (IP or CIDR, optional), `expires_at?`. Returns `{client, key}`; `key` appears only here. Stored: SHA-256 hash + 8-char prefix. |
| PATCH | `/api-clients/{uuid}` | api_clients.manage | name, scopes, rate limit, allow-list, expiry. |
| POST | `/api-clients/{uuid}/rotate` | api_clients.manage | `grace_minutes` (0-1440). Returns the new `key` once; the old key stops immediately or after the grace window. |
| POST | `/api-clients/{uuid}/revoke` | api_clients.manage | Idempotent; revoked clients cannot be rotated (409). |
| GET | `/api-clients/{uuid}/requests` | view | Request audit: method, path, status, ip, time. |

## Outbound webhooks

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/webhooks` | view | Endpoints (never the secret) + `event_types`. |
| POST | `/webhooks` | webhooks.manage | `name`, `url`, `events[]`, `allow_insecure?`. Returns `{endpoint, secret}`; the `whsec_` secret is shown once and stored encrypted. |
| PATCH / DELETE | `/webhooks/{uuid}` | webhooks.manage | DELETE disables the endpoint and cancels queued deliveries. |
| POST | `/webhooks/{uuid}/rotate-secret` | webhooks.manage | New secret returned once. |
| POST | `/webhooks/{uuid}/test` | webhooks.manage | Queues a signed `webhook.test` delivery (202). |
| GET | `/webhooks/{uuid}/deliveries?status=` | view | Delivery log. |
| POST | `/webhook-deliveries/{uuid}/replay` | webhooks.manage | Re-sends the same payload and idempotency key (409 while in flight). |

Events: the employee-linked, non-sensitive DomainEvents types in `WorkflowEventCatalog` (e.g. `EmployeeCreated`, `EmployeeSeparated`, `EmploymentStatusChanged`, `leave.request.submitted`). Highly sensitive/system-restricted events are never delivered. New endpoints start at the current event cursor (no backfill).

Payload (minimized): `{id, type, occurred_at, employee_id, employee_number, data?:{from,to}}`. No names, summaries or free text.

Delivery request headers: `X-Devduo-Signature: t=<unix>,v1=<hex>` where `v1 = HMAC-SHA256(secret, "<t>.<raw body>")`; `X-Devduo-Event`, `X-Devduo-Delivery`, `Idempotency-Key` (`evt:<event uuid>`; receivers should deduplicate on it). Receivers must reject signatures older than 300 seconds (`WebhookSigner::verify`). Redirects are not followed.

Reliability: durable queue table, unique `(endpoint, idempotency_key)`, lease while sending, up to 6 attempts with backoff 1 min, 5 min, 30 min, 2 h, 6 h, then `dead_letter` (manual replay only). Statuses: `pending`, `sending`, `retry`, `delivered`, `dead_letter`, `cancelled`.

SSRF protection (`UrlGuard`, checked on save and again before every delivery, resolved IP pinned): https only, no credentials, port 443/8443, host must resolve only to public addresses (blocks loopback, RFC1918, CGNAT, link-local incl. 169.254.169.254, multicast, documentation/benchmark ranges, IPv6 ::1/fc00::/7/fe80::/10 and IPv4-mapped forms). `allow_insecure` permits plain http to any host only when `APP_ENV` is `local`/`testing`.

## Import profiles and runs (local file adapters)

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/import-profiles` | view | Profiles + `kinds`. |
| POST / PATCH | `/import-profiles[/{uuid}]` | imports.manage | `name`, `kind` (`attendance_punches` -> Attendance import service, `employee_master` -> People import service), `format` (`csv`/`json`), `mapping` (`field` -> file column), `options` (`accept_errors`, `timezone`, `date_format`, ...), `source` (`upload`/`drop`), `interval_minutes` (drop only, 5-10080). |
| POST | `/import-profiles/{uuid}/runs` | imports.manage | Multipart `file`, `dry_run` (default true). Dry run validates only and writes no punches/employees. |
| GET | `/import-runs?profile=` | view | Runs with counts. |
| GET | `/import-runs/{uuid}/items` | view | Row errors (row number + message; raw file values are not stored on items). |
| POST | `/import-runs/{uuid}/retry` | imports.manage | Retries a failed run from its stored file. |

JSON input: an array of flat objects or `{"data":[...]}`. Idempotency: a run is keyed by `(profile, SHA-256 of file bytes, dry-run flag)`; re-sending the same bytes returns the earlier run. Run statuses: `dry_run_ok`, `dry_run_errors`, `completed`, `completed_with_errors`, `processing`, `failed`.
Drop folder: `storage/app/private/integrations/<tenant id>/drop/<profile uuid>/` (private disk `local`); processed files move to `processed/`.

## Scheduler

`php artisan integrations:run-due` (every minute, `withoutOverlapping`, per-tenant cache lock): enqueues new webhook events, delivers due deliveries, scans due drop folders, auto-retries failed runs with `next_retry_at` (max 3 attempts, only for unexpected errors; validation failures are not retried), finalizes asynchronous employee imports. Safe to run repeatedly.

## Health

`GET /health`: `status` (`ok`/`degraded`/`attention`), `issues[]`, API/webhook/import counters (24 h), scheduler heartbeat, and `adapters[]`. Local adapters report `live`. Provider adapters without code (SAP, Workday, ZKTeco, Suprema, Google Workspace, Microsoft 365, accounting/GL, bank/payment, ATS job boards) report `state: not_live` and are never reported healthy. `GET /diagnostics`: local self-checks (encryption round-trip, private storage, queue driver, scheduler, provider gating). Neither makes outbound network calls. The earlier `/connections` endpoints are unchanged and still return `not_configured`.
