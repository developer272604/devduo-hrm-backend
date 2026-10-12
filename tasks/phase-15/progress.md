# Phase 15 progress

| Work item | Status |
|---|---|
| Tenant-scoped access-review campaigns and role/scope snapshots | Implemented |
| Audited confirmation, exception and revocation-request outcomes | Implemented; revocation is not automatic |
| Access-review campaign and reviewer decision UI | Implemented |
| TOTP MFA: enrol, confirm, recovery codes (hashed, single use), login challenge, enforcement policy, admin reset, rate limits, audit | Implemented + tested |
| Sessions/devices: list, revoke one/others, forced logout, idle/absolute timeout, concurrent limit | Implemented + tested |
| Password policy: length, history, expiry, lockout in change/reset | Implemented + tested |
| Tamper-evident audit hash chain, verify endpoint/command, search, purpose-gated export, retention target | Implemented + tested (no automatic purge) |
| Legal hold + retention registry; hold honoured by 4 purge jobs | Implemented + tested (other jobs not wired) |
| Security settings, audit review, legal hold UI; MFA/sessions panels; login challenge | Implemented + vitest |
| SSO | Interface + settings only, NOT LIVE |
| JIT/break-glass, automated revocation, key management, DSAR, residency, incident ops, external audit anchoring, WebAuthn | Deferred |

Overall: partial; no compliance certification is claimed.

Tests actually run: Security + Foundation backend `OK (57 tests, 439 assertions)`; full backend suite: FULL_SUITE_RESULT; vitest security 7 passed; FRONTEND_RESULT; BUILD_RESULT. Migrations `2026_10_30_000001/2` are NOT applied to the shared/dev DB. See ../../docs/PHASE-15-REPORT.md and ../../docs/api/security-controls.md.
