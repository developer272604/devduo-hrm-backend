# Phase 11 — Assets, Expenses and Travel

Status: partial against the full master specification. Core API and frontend workflows are implemented and targeted integration checks pass.

## Implemented

- Tenant inventory with unique inventory identifiers and optional serial numbers; model, category, stock location, purchase reference and warranty date.
- Assign, transfer, acknowledge, return, repair, restore, loss and disposal actions. Transactions lock the current asset and reject stale versions. Every successful change appends a custody event and audit entry.
- Employees see only current assignments and their own movement events; only tenant-wide asset managers alter custody. Assignment creates an acknowledgment task in the existing My Work service.
- Expense policies are immutable dated versions with explicit currency precision, claim limits and receipt thresholds. Claims retain the policy snapshot, currency and integer minor-unit totals. USD/EUR/GBP/INR, JPY and KWD have explicit supported precision; no conversion is implied.
- Claims support up to 20 expense lines, five private receipts of 5 MB each, submission, independent scoped approval/rejection, and recording externally confirmed reimbursement references. Uploads stop after submission. File hashes prevent the same receipt entering two submitted claims, including concurrent reservations through a unique tenant/hash constraint.
- Receipt downloads recheck claim scope and are audited. API responses omit internal storage paths and receipt hashes.
- Travel requests include purpose, destination, local dates, destination timezone and estimated budget. Submission creates shared review tasks; scoped independent approval/rejection and requester cancellation complete those tasks.
- Draft travel itineraries support up to 20 chronological legs with explicit timezone offsets and IANA zones. Offset/date/order validation and optimistic versions prevent inconsistent or stale edits; submission freezes the itinerary.
- Employee 360 includes permission-filtered asset, expense and travel summaries without amounts or private trip details. Other employees' drafts are excluded from review lists and summaries.
- Asset and expense clearance providers replace the Phase 7 null providers. Outstanding assigned/lost assets and submitted/approved unpaid claims block exit clearance with generic messages.
- Asset inventory/detail, expense policies/claims/detail, and travel list/detail screens reuse existing forms, navigation, error states and paginated lists. Module settings now accept and show all implemented modules.

## Limitations

Phase 11 is not complete. Remaining: asset imports/bulk operations and fuller maintenance/procurement history; country/entity/grade policy eligibility and exception approvals; mileage/per-diem and tax references; per-line receipt/allocation and edits/withdrawals; FX rate sources and independent override approval; travel bookings/advances, approved-trip expense linkage and post-trip reconciliation; reimbursement batches and actual payroll/AP adapters; Organization Memory and broader reporting integration. Exact receipt hashes identify byte-identical receipts only. Current payment confirmation records evidence from an external payment process and does not execute payments.

## Database and rollout

Migrations `2026_10_21_000001` through `000004` introduce inventory/custody, permissions/modules, expenses/receipts, travel and versioned itineraries. They have not been applied to shared development MariaDB. The isolated SQLite feature suite runs them from scratch. Rollback drops the new tables and removes operations permission grants/module flags; private receipt files require a retention-aware cleanup process and are not deleted by schema rollback.

## Verification

Backend Operations, Lifecycle and the Experience permission migration regression: 28 tests / 346 assertions passed. Frontend production build passed with the existing large-chunk advisory. The permission migration regression verifies that replaying the pending Experience migration chain does not grant HR identified-survey access.

Frontend Operations suite: 5 tests passed, including itinerary editing with the loaded version and explicit timezone offsets.
