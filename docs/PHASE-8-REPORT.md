# Phase 8 — Performance Management

**Status: partial; goals, reviews, configuration, calibration, feedback, 1:1, development plans and PIP browser workflows are implemented (see continuation section).** The backend domain and API already covered broader performance workflows. This continuation added the main browser flows and reviewer response submission; administration and adjacent workflows remain incomplete in the UI.

## Implemented and verified

- Goals: browse visible goals, create a personal goal and submit progress check-ins.
- Cycles: list, create, publish and launch a review cycle for users with cycle-management permission.
- Reviews: browse assigned/scoped reviews, inspect privacy-filtered response data, submit an assigned self/manager/peer response using the cycle template, and acknowledge a finalized result.
- Dashboard: show scoped review completion, late response, calibration and goal approval counts.
- Review detail now returns visible template sections and active competency choices needed for response submission; existing response privacy filtering remains in force.
- Frontend route and navigation are registered under Performance.

## Verification

- `cd backend && php vendor/phpunit/phpunit/phpunit --colors=never tests/Feature/Performance` — **18 tests, 332 assertions passed**.
- `cd frontend && npm.cmd test -- --run src/pages/performance/__tests__/performance.test.jsx` — **5 tests passed**.
- Frontend production build succeeded after the final response-form changes; Vite reported a JavaScript chunk above the 500 kB advisory threshold.

## Deferred-UI continuation (browser workflows added)

New screens under `frontend/src/pages/performance` (`ConfigPages`, `CalibrationPage`, `CollabPages`, `PipPages`, `PeerPanel`, `parts`), routes in `src/routes/performanceRoutes.jsx`, API helpers and client validators in `src/lib/performanceApi.js`. Every screen has skeleton/empty/error (403) states, toasts, `performance.*` permission gating and card layouts on mobile.

- Admin config (`performance.cycles.manage`): rating scales, competencies, review templates (section builder, weights, live reviewer-weight total, client validation mirroring the server), cycles (create with eligibility and peer settings, publish confirmation, eligibility preview, launch as preview-then-confirm with acknowledgement and an "nobody eligible" block).
- Manager/HR: peer nomination (max 8) and manager reassignment from the review page (anonymity note); calibration workspace (distribution, fairness prompts, propose change with reason, independent approve/reject, "cannot approve your own change", finalize preview with pending-change block and not-ready list, start calibration); cycle dashboard with cycle selector and late-review list.
- Employee: feedback give/received/given (privacy and anonymity notes), 1:1 notes (private by default, shared notes and action items), development plans with actions and status updates, own improvement plan (read-only, confidentiality banner, only when active), goal "Submit for approval" next to existing check-ins, acknowledge finalized review (already present).
- PIPs (`performance.pip.manage`): list, create (draft), activate (confirmation that the employee is notified), checkpoint results, complete with outcome note and human-decision confirmation; actions gated by status; confidentiality banners.

Backend gaps filled (small, tested): `GET performance/one-on-ones` (own journal, employee name included; private notes only for the author) and `late_list` in `GET performance/dashboard` (employee, reviewer type, due date, days late; reviewer identity never listed). Documented in `docs/api/performance.md`.

## Verification (this continuation)

- `cd backend && php vendor/phpunit/phpunit/phpunit tests/Feature/Performance --colors=never` - **22 tests, 373 assertions passed** (2 new tests).
- `cd frontend && npm test -- --run src/pages/performance` - **18 tests passed** (13 new in `workflows.test.jsx`: calibration maker-checker, config validation, feedback anonymity messaging, PIP gating, 403 states, late-review dashboard).
- `cd frontend && npm run build` - succeeded (existing >500 kB chunk advisory).
- Full backend suite and full frontend suite were NOT re-run in this continuation. No browser (manual) testing was performed; response shapes were coded from controller source and covered by mocked-API tests only.

## Deferred

Appeals and cycle reopen (need new states and audit rules, not trivial), reminders UI, goal evidence/dependencies, recognition feed, mentoring, team trend analytics, bulk nomination, listing the already-nominated peers on the review page (the API does not return them), a dedicated self-service endpoint for the viewer's own employee id (1:1 and plan pickers use the coworker directory), editing/retiring scales, competencies and templates (API is create-only), PIP list pagination filters, per-field `FieldAccess` entries for review text.
