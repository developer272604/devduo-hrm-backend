# Phase 15 — Advanced security, compliance and enterprise controls

Status: **partial** against the master specification. Access reviews (earlier increment) plus the controls below are implemented and tested locally. No compliance certification (SOC 2, ISO 27001, GDPR, ...) is claimed; the gate is not met.

## Delivered

### Access reviews (earlier increment)
Tenant-scoped campaigns snapshot role names and data scopes; reviewers confirm, request revocation or record an exception (reason required, self-review blocked, audited). Revocation is a recorded finding only.

### Multi-factor authentication (TOTP)
- RFC 4226/6238 implemented with `hash_hmac` (`Domain/Security/Totp.php`); unit tests use the RFC vectors (SHA-1, SHA-256, SHA-512, HOTP).
- Enrolment (`secret` + `otpauth://` URI text, no QR image library), confirmation, 10 single-use recovery codes stored as keyed HMAC hashes, regeneration, self-disable (blocked when required), admin reset.
- Login challenge: `POST /api/login` returns `mfa_required`, `POST /api/login/mfa` completes sign-in; TOTP step replay protection (compare-and-set), 5-attempt pending window, per-user rate limit (429), all audited.
- Enforcement policy per tenant: off / all / selected roles; unenrolled users are confined to enrolment routes (403 `mfa_enrollment_required`).

### Sessions and devices
- Server-side session register (`user_sessions`, SHA-256 of the session id only): device, IP, signed-in/last-seen; list, revoke one, revoke all others, admin forced logout.
- Tenant-configurable idle timeout, absolute timeout and concurrent-session limit, enforced in the `tenant` middleware; password change/reset and MFA reset sign out the other sessions.

### Password policy
Min length, history, expiry (API restricted to change-password once expired), lockout threshold/duration; enforced in change and reset flows; lockout and policy changes audited.

### Tamper-evident audit
Per-tenant gapless sequence + `prev_hash` + keyed `row_hash` on every row written via `Audit::record` (`Domain/Audit/AuditChain.php`); verify endpoint and `php artisan security:audit-verify`; detects modified, deleted, inserted-gap and truncated-tail rows; search with filters; CSV export requires `security.audit.export` and a stated purpose, is audited, and neutralises spreadsheet formulas. Audit retention is a stored target (>= 365 days); no automatic audit purge exists.

### Legal hold and retention registry
Holds (workspace, module or record scoped) with audited place/release; `LegalHoldService::held()` is honoured by lifecycle exit-interview purge, recruitment candidate file purge, experience survey purge and attendance location purge. Retention policy registry (category, module, days, action, enforcing job) documents intent only.

### SSO
`Contracts\SsoAdapter` (OIDC/SAML) interface, a fail-closed `UnconfiguredSsoAdapter`, and settings storage. **NOT LIVE**: no IdP integration, no federated login route, `enabled` cannot be set.

### UI
- Password and security page: two-factor panel (enrol, recovery codes, disable) and active-sessions panel.
- Login: second-step code form.
- Admin pages: Security settings (MFA, sessions, password, retention, SSO placeholder), Audit review (filters, sensitive-only, verify, export with purpose), Legal holds and retention registry.

## Permissions and migrations
New keys: `security.settings.manage`, `security.mfa.manage`, `security.sessions.manage`, `security.audit.view`, `security.audit.verify`, `security.audit.export`, `security.legal_hold.manage`, `security.retention.manage` (default HR administrator role excluded). Migrations: `2026_10_30_000001_create_security_controls` (tables, user/audit columns), `2026_10_30_000002_grant_security_controls_permissions`. API reference: `docs/api/security-controls.md`. **Not applied to the shared/dev database** (run `php artisan migrate`).

## Verification (actually run)
- `php vendor/phpunit/phpunit/phpunit tests/Feature/Security tests/Feature/FoundationTest.php`: OK, 57 tests, 439 assertions (39 Security tests + 18 Foundation).
- Full backend suite: FULL_SUITE_RESULT
- Frontend: `npx vitest run src/pages/security`: 7 tests passed. FRONTEND_RESULT
- `npm run build`: BUILD_RESULT

## Deferred / gated (not implemented)
Break-glass and JIT privileged access, automated access revocation, field-level key management and at-rest encryption assessment (only MFA secrets use `Crypt`), SCIM, live SSO/IdP, external anchoring of audit head hashes (write-once storage), automated audit purging by retention, privacy request (DSAR) workflows, residency architecture, incident-response tooling, security CI controls/pen-test evidence, per-device trust/remember-this-device, WebAuthn, and legal-hold wiring for purge jobs other than the four listed. The UI does not auto-redirect users with `mfa_enrollment_required` to the setup page; they see 403 messages until they open Password and security. Admin-created users bypass password history. In-browser behaviour was not tested manually; MariaDB-specific behaviour (JSON key ordering is handled by canonical hashing) was not verified outside SQLite tests.
