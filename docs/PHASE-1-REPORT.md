# Phase 1 implementation report

## Architecture

`frontend/` is a React 19 / Vite 6 JavaScript application using React Router. `src/lib/api.js` handles JSON requests, credentials, CSRF rotation and session-expiry events. `AuthProvider` resolves the signed-in identity from the API; frontend access guards complement server checks. `components/` contains the shell and reusable design primitives; `pages/` contains the workflows. No business records or session tokens are stored in localStorage.

`backend/` is Laravel 12, selected for the installed PHP 8.2 runtime. REST endpoints live in `routes/web.php` intentionally, so Laravel's standard encrypted cookie, session, and CSRF middleware apply. Domains cover Tenancy, Access, Organization, Work, Notifications and Audit. Controllers validate input, enforce tenant ownership, and transact business writes with audit records. Composer and npm lockfiles are included.

`TenantSession` derives the tenant from the authenticated account, never a client-supplied tenant header. `TenantRecord` globally scopes tenant-owned models and fails closed without context. User authentication is the intentional exception: users are resolved using workspace slug plus email; management queries explicitly constrain their tenant. Cross-tenant role/assignee references are validated. `TenantRunner` establishes and restores trusted context for future background jobs.

Administrators have a protected system role. Removing the final active administrator is blocked under a tenant row lock. Task data scopes implement own-record and whole-tenant visibility. Module flags are checked in navigation and API middleware. Password changes/reset rotate the remember token and increment a session version, invalidating older sessions.

## Database tables

| Area | Tables |
| --- | --- |
| Tenancy | `tenants` (settings, setup draft/progress, completion timestamp) |
| Identity | `users`, `password_reset_tokens`, `sessions` |
| Access | `roles`, `permissions`, `role_user`, `permission_role` |
| Organization | `legal_entities`, `locations`, `business_units`, `departments`, `teams`, `cost_centers`, `designations`, `positions`, `grades`, `levels`, `employee_types` |
| Work and updates | `work_items`, `in_app_notifications`, `announcements` |
| Audit | `audit_logs` |
| Laravel infrastructure | `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations` |

Organization tables have tenant-owned codes, names, descriptions, active state, metadata, and timestamps. Legal registration and location address metadata are persisted by setup. Work items include source module/entity, assignee, priority, due date, status, local action path, metadata and completion time. Audit entries include actor, action, module, resource, timestamp, IP address and allowlisted context. Passwords/tokens are never copied to audit context. The API has no audit update/delete endpoints.

## REST API inventory

All paths below are prefixed `/api`. Collection endpoints are paginated (20 records) except the permission and role catalogs.

| Methods | Paths | Purpose |
| --- | --- | --- |
| GET | `/csrf` | Initialize session and CSRF token |
| POST | `/login`, `/logout` | Session authentication |
| GET | `/me` | Identity, permissions, tenant and module settings |
| POST | `/forgot-password`, `/reset-password`, `/change-password` | Password lifecycle |
| GET | `/dashboard` | Role-aware counts, own tasks, announcements and alerts |
| GET, POST | `/organization/{type}` | Search/filter records; create record |
| PUT, DELETE | `/organization/{type}/{id}` | Update/delete same-tenant record |
| GET, POST | `/users` | Search people; create account with roles |
| PUT | `/users/{id}` | Edit account, active state and role assignment |
| GET, POST | `/roles` | Role catalog; create role |
| PUT, DELETE | `/roles/{id}` | Edit/delete non-system role |
| GET | `/permissions` | Human-readable grouped permissions |
| GET, PUT | `/settings` | Company, work week, HR contact, notification, module and brand preferences |
| PUT | `/setup` | Validate/save step or atomically complete all eight steps |
| GET, POST | `/work` | Filter scoped tasks; create task and notification |
| PATCH | `/work/{id}` | Complete/reopen task |
| GET | `/notifications` | Own updates, read status and category filters |
| POST | `/notifications/read-all`, `/notifications/{id}/read` | Own read-state updates |
| GET | `/audit` | Filter by person, action, module, resource, date range and IP |

`{type}` is an explicit whitelist: `legal-entities`, `locations`, `business-units`, `departments`, `teams`, `cost-centers`, `designations`, `positions`, `grades`, `levels`, `employee-types`.

## Frontend pages

`/login`, `/forgot-password`, `/reset-password`, `/`, `/my-work`, `/organization/:type`, `/users`, `/roles`, `/permissions` (redirect to grouped role management), `/notifications`, `/audit`, `/setup`, `/settings`, `/security`, `/unauthorized`, `/forbidden`, and a not-found screen.

Company setup: Company → Legal entity → Location → Departments → Work week → Employee types → Admin & HR → Review. Each validated step can be saved; completion revalidates every step and creates organization records inside a transaction. Company settings are editable afterward.

The dashboard displays real task/user/notification counts and stored announcements. Attendance, people away, upcoming events and pending approvals have explicit unconnected-module empty states. No invented graphs or fake attendance data are shown.

## Reusable components

Button, Input, Select, MultiSelect, Textarea, Checkbox, Radio, Switch, native DatePicker, DateRange, native TimePicker, Badge, Avatar, Tooltip, Dropdown, Popover, Modal, Drawer, Tabs, Accordion, Breadcrumb, Pagination, Skeleton, EmptyState, ErrorState, ToastProvider, ConfirmationDialog, DataTable, FilterBar, Search, StatCard, ActivityTimeline, PageHeader, Card, Resource and FormError.

Dialogs support Escape, focus trapping, focus restoration and labelled titles. Tabs support arrow keys. Mobile tables become labelled cards, and navigation becomes a drawer. The desktop sidebar supports expansion, collapse and nested organization links. The later styling update replaces custom CSS and Google Fonts with Tailwind utilities and system fonts, adds #7c46f1 branding, shared themed dropdowns, and persistent light/dark mode.

## Verification

- Backend: 20 tests, 167 assertions, passing on in-memory SQLite and isolated MariaDB 10.4 using the MySQL driver.
- Frontend unit/component: 6 tests passing (dialog keyboard behavior, multi-select, tabs, empty states, safe action links).
- Browser: 11 tests passing in Chrome against the real Laravel API and database. Covers required fields, protected routes, sign-in/out, remember-me session restoration, task creation/completion, setup save/resume, organization create/edit/delete, user creation/deactivation, real member permission denial, CSRF enforcement, all main screens, desktop collapse and mobile navigation.
- Viewports verified: 1440, 1024, 768, and 390 pixels. No horizontal page overflow in checked screens. Handover screenshots are preserved in `docs/screenshots`; test traces go to ignored `frontend/test-results`.
- Production frontend bundle builds successfully. No runtime JavaScript errors were observed in the primary-route browser test.

## Known limits and deployment work

1. The Master Specification was not attached. The stated assumptions in README need reconciliation before expanding the domain model.
2. Local database verification used installed MariaDB, not an Oracle MySQL 8.4 server. The MySQL 8.4 Compose definition is supplied but Docker is not installed here, so that specific server/version remains untested.
3. SMTP, HTTPS production hosting, backups, centralized monitoring and production secrets need environment-specific configuration. Password recovery currently writes its email to the local Laravel log. The application has not been publicly deployed or independently security-audited.
4. MFA, Google/Microsoft SSO, SAML/OIDC and department/team data scopes are extension boundaries, not active authentication features. Organizational entities are catalogs; employee membership and richer reporting relationships belong to later domain work.
5. One account currently belongs to one tenant and login emails are globally unique. Multi-workspace membership is not implemented.
6. Global search is explicitly a placeholder. Approval workflows, attendance, leave, events and announcement publishing administration are not Phase 1 modules. Announcements are read from the database; future modules can publish through tenant-aware services.
7. Task due-date filters and the dashboard date use the company timezone. Attendance calendars, holiday rules, and payroll cutoffs remain future domain policies.
8. UI controls use native date/time pickers. Branding supports a text brand name and the shared purple identity, not logo uploads or a theme builder.
9. Automated coverage is substantive but is not a full accessibility audit, load test, disaster-recovery drill or browser-matrix certification.

## Phase 2 readiness

The core extension points are available: trusted tenant contexts, grouped permissions, task publishing, in-app notification publishing, audit events, module flags, organization catalogs and session authentication. Add future module migrations, policies, services, routes and UI against those boundaries with cross-tenant tests. **Phase 2 has not been started.**
