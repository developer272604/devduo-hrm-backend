# Phase 14 progress

| Work item | Status |
|---|---|
| Tenant-scoped provider catalog and connection metadata | Implemented; all provider adapters explicitly not live |
| Secret reference handling, redaction, diagnostic history and connection revocation | Implemented; no network calls or external token revocation |
| Public API platform: hashed API keys (shown once), scopes, rate limit, rotation (+grace), revocation, IP allow-list, request audit | Implemented and tested |
| Public read-only API v1 (`/api/public/v1/employees`, `/departments`) with scope, tenant and field-privacy enforcement | Implemented and tested |
| Outbound webhooks: endpoint registry, encrypted secret, HMAC-SHA256 signature + timestamp, replay window verifier, event subscriptions, durable queue, retry/backoff/dead-letter, manual replay, delivery log, SSRF guard, payload minimization, idempotency keys | Implemented and tested against a faked HTTP client only (no real receiver) |
| Local inbound adapters: CSV/JSON import profiles for attendance punches and employee master, mapping, dry-run, drop folder | Implemented and tested (reuses Attendance and People import services) |
| Sync run model (runs, items, errors, retry) and `integrations:run-due` scheduled command | Implemented and tested; idempotent by content hash and unique keys |
| Integration Health Center and diagnostics | Implemented and tested; provider adapters reported `not_live` |
| UI: API clients, webhooks + delivery log + replay, import profiles/runs, health | Implemented; vitest for key flows; not browser-tested |
| Google/Microsoft SSO, SAML/OIDC, SCIM | Pending (provider-gated) |
| SAP, Workday, biometric vendor, accounting/GL, bank/payment, ATS job board adapters | Not live (need vendor credentials and sandboxes) |
| Mapping transformations, conflict resolution, cursors for outbound field sync, data lineage, change impact preview | Deferred |

Verified (actually run): `phpunit tests/Feature/Integrations` 21 tests / 295 assertions OK; `phpunit tests/Feature/FoundationTest.php` 18 tests OK; frontend `npm test` 42 files / 255 tests OK (5 new in `PlatformPages.test.jsx`); `npm run build` OK. Full backend suite NOT run in this pass. New migrations `2026_10_31_000001`/`000002` ran only in the in-memory test DB, not the shared dev DB.

Overall: partial. Local platform runtime is done; provider adapters and identity (SSO/SCIM) remain. See ../../docs/PHASE-14-REPORT.md and ../../docs/api/integrations.md.
