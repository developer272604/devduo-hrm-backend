# Phase 2 progress

Status: core employee/person/employment foundation is implemented; remaining work is tracked against the combined Phase 2–3 report.

| Work item | Status |
|---|---|
| Person/Employment/User model, directory, Employee 360, org chart, effective-dated assignment, lifecycle APIs | Core implemented; full gate and deployment review remain |
| Custom-field definitions, sections and values | Backend and profile support implemented |
| Choice options for single/multi-select custom fields | Admin UI implemented in this continuation; production frontend build passed |
| Employee group member management UI | Implemented; scoped employee search, effective dates, add/update and remove actions; frontend production build passed |
| Import problem-row correction and revalidation | Implemented; inline row editor calls the existing row patch endpoint and revalidates before commit; focused UI suite passed |
| Sensitive identity/bank edit flow | Implemented country-schema forms, direct/approval edits, identity secret blanking, audited bank detail reveal before edits, identity removal and bank retirement; profile suite 11 tests passed and production build passed |
| Custom-field defaults/completeness | Defaults now apply during manual hire/rehire after the initial assignment exists; People config suite passed (24 tests, 329 assertions). Completeness reads those stored values. |
| Dotted-key settings behavior | Existing People config regression test verifies dotted field-policy keys round-trip through nested tenant settings. |
| Security/rollout | Generic local disk serving disabled; sensitive storage, private-note audit/event payloads, and public invitation token controls reviewed and covered by focused tests. Production storage-provider and broader threat review remain. |
| Regression/browser/MariaDB rollout review | Pending where not explicitly verified in phase reports |

This is not a claim that Phase 2 is complete. See `../../docs/PHASE-2-3-REPORT.md` and `../../docs/HRMS-CAPABILITY-AUDIT.md`.
