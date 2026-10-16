# Enterprise platform APIs (Phases 14–16 lean foundation)

All routes use authenticated tenant sessions and tenant/module middleware.

## Integrations

- `GET /api/v1/integrations/providers` (`integrations.view|integrations.manage`) returns a provider catalog with `live: false` for unimplemented adapters.
- `GET /api/v1/integrations/connections` returns the tenant's connection metadata; credential references are hidden.
- `POST /api/v1/integrations/connections` (`integrations.manage`) accepts provider, name, optional secret-manager URI reference and scopes. The resulting connection is disabled. Mapping/settings are not exposed in this foundation.
- `POST /api/v1/integrations/connections/{uuid}/test` creates a diagnostic record with `not_configured`. It makes no network request.
- `GET /api/v1/integrations/connections/{uuid}/runs` lists diagnostic history.
- `POST /api/v1/integrations/connections/{uuid}/revoke` retires the connection record; revocation is idempotent and future diagnostics are rejected.

## Access reviews

- `GET /api/v1/security/access-reviews` lists campaigns and counts.
- `POST /api/v1/security/access-reviews` creates a campaign and snapshots active users' roles/data scopes.
- `GET /api/v1/security/access-reviews/{uuid}/items` returns paginated review items.
- `POST /api/v1/security/access-reviews/{campaign}/items/{item}/review` accepts `confirmed`, `revoke_requested`, or `exception_accepted`, plus a required note for the latter two. It never revokes access automatically.

## Intelligence

- `GET /api/v1/intelligence/findings?domain=people` returns current read-only findings from registered, permission-aware Action Center providers.

Provider connectivity, actual account revocation, and persisted cross-module intelligence remain unimplemented.
