# Security controls API (Phase 15)

Base path `/api/v1/security` (module `security`, session + CSRF). All data is tenant scoped; other tenants' users, sessions and holds return 404. No compliance certification is claimed.

## Permissions (`WorkspaceProvisioner::PERMISSIONS`, grant migration `2026_10_30_000002`)

`security.settings.manage`, `security.mfa.manage`, `security.sessions.manage`, `security.audit.view`, `security.audit.verify`, `security.audit.export`, `security.legal_hold.manage`, `security.retention.manage` (plus the existing `security.access_reviews.*`). Granted to the Workspace administrator role; the default HR administrator role is excluded. Own-account MFA and session endpoints need no extra permission.

## Multi-factor authentication (TOTP, RFC 6238, implemented with `hash_hmac`)

| Method | Path | Notes |
|---|---|---|
| GET | `/mfa` | `{enabled, pending_enrollment, required, recovery_codes_remaining}` |
| POST | `/mfa/enroll` | 201 `{secret, otpauth_uri, issuer, account, algorithm, digits, period}`; secret stored encrypted, not yet active |
| POST | `/mfa/confirm` `{code}` | activates, returns 10 single-use `recovery_codes` once (stored as keyed HMAC hashes) |
| POST | `/mfa/recovery-codes` `{code}` | regenerates codes (old ones stop working) |
| POST | `/mfa/disable` `{password, code}` | 403 when the tenant policy requires MFA for the user |
| DELETE | `/users/{id}/mfa` | `security.mfa.manage`: reset a lost device; also signs that user out |

Limits: 5 wrong codes per 5 minutes per user, then 429. A TOTP time step can be used once (replay rejected, compare-and-set).

### Login challenge

`POST /api/login` returns `{mfa_required: true, expires_in: 300}` and signs nobody in when the user has MFA. Then `POST /api/login/mfa {code}` (TOTP or recovery code) returns the normal login payload. Pending state lives in the session (5 minutes, 5 attempts). `/api/me` now carries `security: {mfa_enabled, mfa_enrollment_required, password_expired}`.

### Enforcement

Policy `mfa.enforcement` = `off | all | roles` (+ `role_ids`). A required user who has not enrolled can log in but every API except `/me`, `/logout`, `/change-password` and `/v1/security/mfa*` returns 403 `{code: "mfa_enrollment_required"}`.

## Sessions and devices

The server keeps a register of sessions (`user_sessions`): only a SHA-256 of the session id is stored, plus IP, device label parsed from the user agent, signed-in and last-seen times.

| Method | Path | Notes |
|---|---|---|
| GET | `/sessions` | own active sessions, `current` flag |
| DELETE | `/sessions/{uuid}` | revoke one of your other sessions (422 for the current one) |
| POST | `/sessions/revoke-others` | `{revoked: n}` |
| POST | `/users/{id}/sessions/revoke` | `security.sessions.manage`: forced logout of a user |

Enforced by the `tenant` middleware on the next request (401 "session has ended"): revocation, idle timeout, absolute timeout, concurrent-session limit (oldest sessions are revoked at login). Password change/reset revokes all other sessions (also via `session_version`). Limitation: the session payload in the session store is not deleted; the request is simply refused. Remember-me re-logins create a new register row.

## Password policy

`password.min_length` (8-64, default 12; letters + numbers always required), `history` (reuse of the last N passwords, including the current one, is rejected), `expiry_days` (expired passwords restrict the API to change-password: 403 `password_expired`), `lockout_attempts` / `lockout_minutes` (account locks after N wrong passwords; locked logins get 429 even with the right password). Enforced in `POST /api/change-password` and `POST /api/reset-password` (a rejected reset keeps the token valid). Users created by an administrator are not checked against history.

## Settings

`GET/PUT /settings` (`security.settings.manage`). PUT accepts any subset of `mfa`, `session` (`idle_minutes`, `absolute_minutes`, `max_concurrent`; 0 disables), `password`, `audit.retention_days` (>= 365, a recorded target only) and `sso`. The response is `{settings, sso_adapter}`. Changes are audited with before/after values.

## Tamper-evident audit

Every audit row written through `Audit::record` gets a per-tenant gapless `sequence`, `prev_hash` and `row_hash` = HMAC-SHA256(key derived from `APP_KEY`, previous hash + canonical row). Rows from before this feature are unsealed (`chained: false`). Rotating `APP_KEY` invalidates verification of existing rows; keep the key stable or export evidence first.

| Method | Path | Notes |
|---|---|---|
| GET | `/audit` | `security.audit.view`; filters `action, module, actor, resource, from, to, sensitive, per_page` |
| POST | `/audit/verify` | `security.audit.verify`; `{ok, checked, failures:[{sequence, reason}], unchained_rows_after_start}`; reasons `row_modified`, `missing_rows`, `chain_break`, `tail_truncated_or_altered` |
| GET | `/audit/export` | `security.audit.export`; **`purpose` (10-500 chars) required**; streams CSV (formula-injection neutralised, max 50 000 rows); the export itself is audited with the purpose and filters |

CLI: `php artisan security:audit-verify [--tenant=ID]` (exit code 1 if any chain is broken). Detection only: someone who holds both database and `APP_KEY` can rewrite history. For stronger evidence ship periodic head hashes to external write-once storage (not implemented).

## Legal hold and retention registry

| Method | Path | Notes |
|---|---|---|
| GET/POST | `/legal-holds` | `security.legal_hold.manage`; body `{name, reason, module?, subject_ref?}` (`subject_ref` like `candidate:<uuid>` needs a module) |
| POST | `/legal-holds/{uuid}/release` | `{reason}` |
| GET/POST/PUT | `/retention-policies[/{uuid}]` | `security.retention.manage`; `{data_category, module, retention_days, action: review|purge|anonymize, enforced_by?, enabled, notes?}` |

`LegalHoldService::held($module, $subjectRef = null)` (interface `Contracts\LegalHoldChecker`) is honoured by: lifecycle exit-interview purge (`lifecycle`), recruitment candidate file purge (`recruitment`, per candidate), experience survey purge (`experience`, per survey), attendance location purge (`attendance`). A hold with `module = null` covers all of these. The check fails closed. Other purge jobs (exports housekeeping, etc.) are not wired. The retention registry documents intent only; it does not itself delete anything and contains no legal advice.

## SSO (interface only, NOT LIVE)

`Contracts\SsoAdapter` (OIDC/SAML) and `UnconfiguredSsoAdapter` (throws) exist; `settings.sso` stores protocol, issuer, client id, metadata URL and domain hints. `sso.enabled = true` is rejected with 422. There is no federated login route and no IdP has been tested.
