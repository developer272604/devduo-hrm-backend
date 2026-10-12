# Payroll benefits API

All endpoints are under `/api/v1/benefits`, require the Payroll module, and use tenant-scoped records. Contributions and dependents are encrypted or kept out of ordinary employee responses.

## Plans and enrollment windows

- `GET plans` and `POST plans` require `payroll.benefits.manage`. Plans include category, provider metadata, country/currency, effective dates, optional component code, eligibility rules, and fixed employee/employer contributions. `eligibility` supports `worker_types`, `countries`, `legal_entity_ids`, and `min_tenure_days`. `contributions` currently supports `employee_fixed` and `employer_fixed`; these values are copied to encrypted enrollment fields by the server.
- `POST plans/{uuid}/activate` activates a non-expired draft plan.
- `POST plans/{uuid}/windows` requires an active plan and `{name, opens_on, closes_on, effective_on}`. Effective date cannot precede the opening date.

## Employee self-service

- `GET available` requires own benefits view or enroll permission and returns plans eligible for the signed-in employee with windows open today. Employee identity comes from the session.
- `POST plans/{uuid}/enroll` requires `payroll.benefits.enroll_own` and `{window_uuid, dependents?}`. The server verifies plan eligibility, open window, duplicate active/pending enrollment, and that every dependent belongs to the employee. Employee/employer contributions are sourced from the plan rules; clients cannot override them. A new enrollment starts `pending` and creates a task for an independent benefits manager.
- `GET me` requires `payroll.benefits.view_own` and returns enrollment status, plan label/category, and effective dates without contribution amounts or dependent details.
- `POST me/{uuid}/termination` requires `payroll.benefits.view_own` and `{effective_to}`. Only the signed-in employee's active enrollment can request an end date on or after today/start date. Coverage remains active pending independent review; approval ends the enrollment on the requested date, while rejection restores active status and clears the requested date.

## Benefits operations

- `GET enrollments`, `POST enrollments/{uuid}/decision`, and `POST enrollments/{uuid}/termination-decision` require `payroll.benefits.manage`. The list is data-scope constrained; pending enrollments and termination requests can be approved or rejected by an authorized reviewer. Enrollment approval rechecks current plan eligibility and active status. The termination requester cannot decide their own request; decisions complete the matching My Work task and are audited.

This is a core enrollment workflow. The payroll Benefits Administration page creates plans, opens windows, and reviews enrollment and termination requests; employees can request a coverage end date in My Benefits. Provider integrations, richer contribution formulas, and benefit-to-payroll input generation remain open Phase 5 work.
