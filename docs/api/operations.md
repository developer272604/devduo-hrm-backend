# Assets, expenses and travel API

All endpoints use authenticated tenant sessions, CSRF, module switches and permissions. UUIDs from other tenants resolve to 404. Asset inventory management requires tenant scope specifically on `assets.manage`; claim/travel reviewers require their respective approval permission and current employee scope. Own requests cannot be independently approved by their requester.

## Assets — `/api/v1/assets`

- `GET /?page=&status=&search=`: paginated inventory for tenant managers or own currently assigned assets for employees.
- `POST /`: `{identifier,name,category,serial_number?,model?,location?,purchase_reference?,warranty_until?}` registers available inventory.
- `GET /employees?search=`: manager-only active/preboarding custodian options, at most 100 matches.
- `GET /{uuid}`: asset details and up to 100 recent custody events; employees receive only their own events without manager notes.
- `POST /{uuid}/actions`: `{action,version,reason?,employee_uuid?,condition?}`. Actions: assign, transfer, acknowledge, return, repair, restore, lost, dispose. Assignment/transfer requires a working or preboarding employee. Return requires good/worn/damaged condition. Every manager action requires a reason. Acknowledge is available only to the current employee; stale versions return 409.

## Expenses — `/api/v1/expenses`

- `GET /policies`: up to 100 immutable dated policies.
- `POST /policies`: finance manager with tenant scope supplies `{name,category,currency,max_amount,receipt_threshold,effective_from,effective_to?}`. Amounts are decimal strings. Supported currencies: USD/EUR/GBP/INR (2 decimals), JPY (0), KWD (3).
- `GET /claims?page=` and `GET /claims/{uuid}`: own claims or claims in the caller's review/finance scope. Detail includes policy snapshot, lines, receipt metadata and readiness.
- `POST /claims`: `{title,policy_uuid,lines:[{spent_on,merchant,description,amount}]}`. Up to 20 positive lines; spend dates must fit the policy dates and not be in the future. The total may not exceed the policy limit.
- `POST /claims/{uuid}/receipts`: multipart `file`, PDF/PNG/JPG, 5 MB, five per draft; requester only.
- `GET /claims/{uuid}/receipts/{receipt}`: scoped audited private download, no-store.
- `POST /claims/{uuid}/actions`: `{action,reason?,payment_reference?}`. Submit validates the receipt threshold and reserves receipt hashes. Approve/reject requires an independent reviewer and reason. Confirm_payment requires tenant-wide `expenses.payments.confirm`, approved/unpaid state and a unique confirmed external reference; it does not initiate a transfer.

## Travel — `/api/v1/travel`

- `GET /requests?page=` and `GET /requests/{uuid}`: own requests and permitted reviewer scope.
- `POST /requests`: `{purpose,destination,starts_on,ends_on,timezone,currency,budget}`; dates are local to the provided IANA timezone and budget is a decimal string.
- `POST /requests/{uuid}/actions`: `{action,reason?}`. Submit creates shared My Work review tasks. Approve/reject requires independent employee scope; cancel is requester-only. Decisions and cancellation require reasons. Approval authorizes a budget request only, not bookings, advances or payment.

## Shared behavior

`PUT /api/v1/travel/requests/{uuid}/itinerary` replaces a requester's draft itinerary with `{version,legs:[]}` (maximum 20). Each leg requires `origin`, `destination`, `departure`, `arrival`, `departure_timezone`, `arrival_timezone`. Timestamps use `2026-11-01T09:00:00+05:30` with a matching IANA timezone. Legs must be chronological, non-overlapping, and within the trip dates interpreted in the trip timezone. A stale version returns 409. Submission freezes itinerary editing. Responses include `itinerary` and `itinerary_version`.

Expense and travel drafts are private to their owners. Employee 360 summaries respect the same permissions and employee scope, omitting financial amounts, receipt metadata and private travel details.

Review status transitions and custody changes lock the source row. Completed reviews close related My Work tasks. Receipt fingerprints are reserved within the tenant on submission and remain reserved after rejection pending a future finance exception workflow. Unsupported currencies and excess decimal precision are rejected rather than silently rounded. No receipt content, transaction amount or private storage path is put in audit context.
