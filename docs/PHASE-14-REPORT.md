# Phase 14 — Integrations and enterprise platform

Status: metadata foundation plus a locally verifiable platform runtime (public API, signed webhooks, file import adapters, run-due scheduler, health center); **partial against the master specification** (no live provider adapters, no SSO/SCIM).

## Delivered

- Tenant-scoped provider catalog, connection metadata and diagnostic run records.
- Credential values are not accepted. Credential references must use a secret-manager URI and are hidden from serialized responses.
- Connection settings reject common secret-bearing field names. New connections remain disabled.
- Provider diagnostics make no network calls and explicitly return `not_configured` until a real adapter is installed.
- Integration view/manage permissions, tenant module flags, audit events and list/create/test API routes.
- Diagnostic history and idempotent connection-record revocation; a revoked connection cannot be tested again and its secret reference/scope list is cleared. External token revocation is not implemented.
- Admin UI for connection metadata, diagnostic history, non-live diagnostics, and local connection-record revocation.

## Platform runtime (added)

- **Public API platform**: tenant API clients, `dvk_` keys stored as SHA-256 hash + prefix and shown once, scopes (`employees:read`, `employees:email`, `departments:read`), per-client rate limit (429 + headers), rotation with optional grace window, revocation, optional IP/CIDR allow-list, request audit. Read-only `/api/public/v1/employees` and `/departments` with fixed minimal field set; tenant comes from the key.
- **Outbound webhooks**: endpoint registry, encrypted `whsec_` secret (shown once, rotatable), `X-Devduo-Signature: t=..,v1=HMAC-SHA256(t.body)` with a 300 s replay-window verifier, subscriptions limited to the workflow event catalog, durable delivery table with unique idempotency key, 6 attempts with 1m/5m/30m/2h/6h backoff then dead letter, manual replay, delivery log, SSRF guard (https only, public IPs only, re-checked and IP-pinned at delivery, no redirects), minimized payloads (ids, type, time, status transition).
- **Local inbound adapters**: import profiles for attendance punches (Attendance import service) and employee master (People import service); CSV or JSON; column mapping; dry-run; manual upload or scheduled drop folder; idempotent by file hash.
- **Sync runs**: `integration_job_runs` / `integration_job_items` (counts, row errors, attempts, retry); `integrations:run-due` (scheduled every minute, per-tenant lock) handles webhook enqueue/delivery, drop folders, bounded auto-retry and finalization.
- **Health center + diagnostics**: real counters (24 h), issues, scheduler heartbeat, local self-checks; SAP, Workday, ZKTeco, Suprema, Google Workspace, Microsoft 365, accounting/GL, bank/payment and ATS job boards are reported `not_live`. No fake success anywhere.
- **UI**: `/integrations/health`, `/integrations/api-clients`, `/integrations/webhooks`, `/integrations/imports` (plus the original connections page), in `frontend/src/pages/enterprise/PlatformPages.jsx`.
- **Security**: `integrations.api_clients.manage`, `integrations.webhooks.manage`, `integrations.imports.manage` (catalog in WorkspaceProvisioner, grant migration `2026_10_31_000002`); tenant-scoped records (404 across tenants); secrets/hashes never serialized; all mutations audited.
- **Files**: backend `app/Domain/Integrations/{Api,Webhooks,Imports,Health}`, `RunDue.php`, `Models/*`, `app/Http/Controllers/Integrations/{PlatformController,PublicApiController}.php`, `app/Http/Middleware/ApiClientAuthentication.php`, `app/Console/Commands/IntegrationsRunDue.php`, `routes/integrations.php` (+ `routes/integrations_public.php`), migrations `2026_10_31_000001` (7 tables) and `2026_10_31_000002`; shared one-line edits in `routes/web.php`, `bootstrap/app.php` (middleware alias), `routes/console.php` (schedule), `WorkspaceProvisioner` (3 permissions). API doc: `docs/api/integrations.md`.

### Verification (actually run)

| Command | Result |
|---|---|
| `php vendor/phpunit/phpunit/phpunit tests/Feature/Integrations` | OK, 21 tests, 295 assertions (key hashing/show-once, scopes, rate limit, rotation/grace/revocation, IP allow-list, field privacy, signature + replay window, SSRF blocklist incl. delivery-time recheck, retry/backoff/dead-letter/replay, idempotent enqueue, tenant isolation, permissions, dry-run vs live import, run-due idempotency, health not-live) |
| `phpunit tests/Feature/FoundationTest.php` | OK, 18 tests (permission catalog change did not break it) |
| `npm test` (frontend, whole suite) | 42 files, 255 tests passed (includes 5 new in `PlatformPages.test.jsx`) |
| `npm run build` | Passed |

Not run: full backend suite, browser/manual UI testing, migration on MariaDB/dev DB, real network webhook delivery (HTTP client is faked in tests), real queue worker, load/performance tests.

### Provider-gated (remain NOT LIVE)

SAP, Workday, ZKTeco/Suprema or any biometric vendor adapters, Google/Microsoft SSO and SCIM, SAML/OIDC, accounting/GL, banks/payment providers, ATS job boards, background checks, LMS, calendars, e-sign, messaging, ITSM. These need vendor credentials, sandboxes and contract-specific mapping; the platform marks them `not_live` and they never report success.

## Deferred (still open)

Google/Microsoft SSO, SAML/OIDC and SCIM, provider adapters (see provider-gated list above), OAuth/token lifecycle, outbound field mappings with transformations, conflict resolution and cursors, Sync Conflict Explainer, Data Lineage, Change Impact Preview, write endpoints in the public API, webhook endpoint auto-disable policy, and production provider-outage validation.

The gate is not fully met: connectivity primitives (API, webhooks, file import, health) are secure, observable and resilient and tested locally, and unconnected adapters are clearly marked not live, but identity/provider integrations are not built.

## Rollout notes

Run `php artisan migrate` (migrations `2026_10_24_000001/2`, `2026_10_31_000001/2`). The migrations were exercised only against in-memory SQLite in tests; they have not been applied to the shared dev DB or verified on MariaDB. Production needs a real queue worker (employee import commits) and the Laravel scheduler (`integrations:run-due`).
