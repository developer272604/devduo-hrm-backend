# Payroll outputs and employee self-service API (Phase 5, workstream C - core)

Base: `/api/v1/payroll` (module `payroll`). All routes are tenant scoped (other tenant = 404), permission gated and data-scoped. Money is ALWAYS a decimal string (never a number). Payroll amounts have 4 decimals except where noted. Validation errors are HTTP 422 `{message, errors}`. Create calls that mutate money take an `idempotency_key` (uuid); repeating it with the same body returns the same record, with a different body 409.
Code: `app/Domain/Payroll/{Loans,Payslips,Payments,Accounting,Settlement,TaxDeclarations,SelfService}`; schema: migration `2026_10_15_C00001_payroll_outputs_support`.

## 1. Employee self-service (`/me`, own data only; employee is taken from the signed-in user)

| Method path | Permission | Notes |
| --- | --- | --- |
| GET `me/payslips` | `payroll.payslips.view_own` | paginated, published (and superseded-but-once-published) only. Item: `{uuid, version, status published|superseded, period{label,start,end}, currency, net, published_at, first_viewed_at, checksum}` |
| GET `me/payslips/{uuid}` | same | adds `snapshot` (see 2). Logs a view. Another employee's uuid = 404 |
| GET `me/payslips/{uuid}/print` | same | `text/html` with print stylesheet (browser print = PDF). CSP locked, no-store |
| POST `me/payslips/{uuid}/download-link` | same | `{url (relative, signed), expires_at}` (setting `payslips.download_link_minutes`, default 10) |
| GET `{url}` (`me/payslips/{uuid}/download`) | signed + session + owner | attachment `.html`; unsigned/expired = 403 |
| GET `me/compensation` | `payroll.compensation.view_own` | `{available, compensation{currency, effective_from/to, pay_frequency, base_pay, gross_reference, annual_compensation, components[{code,name,kind,amount}]}}`; `available:false` until workstream A binds `CompensationResolver`. Audited |
| GET `me/loans` | `payroll.loans.view_own` | `{loans[], advances[]}` with `outstanding` and loan `schedule[]` |
| GET `me/tax-declaration-windows` | `payroll.tax_declaration.submit` | `[{pack_uuid,name,country,tax_year,opens_on,closes_on,open}]` |
| GET `me/tax-declaration-schema?pack_uuid=` | same | `{fields[{key,label,type,required,sensitive,options,pattern}], regimes[]}` from the published pack version `declaration_schema` (empty fields = free decimal keys) |
| GET/POST `me/tax-declarations` | same | POST `{pack_uuid, tax_year, regime?, data{key:string}}`; window must be open and declaration not approved/locked; each submit bumps `version`. Sensitive fields are masked in every response (`******234F`) and encrypted at rest |
| POST `me/tax-declarations/{uuid}/proofs` | same | multipart `{category, declared_amount?, file? pdf/jpg/png <=5MB}`; refused after `proof_closes_on` |

Benefits self-service (`/api/v1/benefits/*`) is NOT in this lean delivery.

## 2. Payslips (admin)

Generation requires a run in `locked|outputs|payment|closed`. Snapshot is built from the CURRENT results of the run through `ResultStore`/models, encrypted, checksummed, immutable. A correction (new result version, controlled correction in B) + generate again = payslip `version+1` (`generated`); the old published version stays visible to the employee until the new one is published, then becomes `superseded` (history kept). Release rule `payroll.payslips.publish_on`: `lock` (default, after lock) or `payment` (after a batch is paid/partially paid). Publishing notifies the employee (no amounts).

| Method path | Permission | Notes |
| --- | --- | --- |
| POST `runs/{runUuid}/payslips/generate` | `payroll.payslips.manage` | queued idempotent job (`GeneratePayslipsJob`), 202 `{queued}` |
| POST `runs/{runUuid}/payslips/publish` | same | publishes all `generated` payslips: `{published: n}` |
| GET `runs/{runUuid}/payslips` | same | paginated (data scoped) summaries + `employee_uuid, employee_name` |
| GET `payslips/{uuid}` | same | summary + `snapshot`; audited + access-logged |
| POST `payslips/{uuid}/publish` | same | one payslip |
| POST `payslips/{uuid}/withdraw` | same | `{reason}`; status `withdrawn`, hidden from employee |
| GET `payslips/{uuid}/access-log` | same | `{data:[{action,user_id,ip,created_at}]}` actions: generated viewed link_issued downloaded published withdrawn |

Snapshot shape: `{employee{uuid,number,name,joined_on}, period{label,start,end}, run{uuid,type}, country, currency, payable_days, working_days, lines{earnings,reimbursements,deductions,taxes,employee_contributions,employer_contributions}[{code,name,amount}], totals{gross,deductions,taxes,reimbursements,net}, ytd{gross,deductions,taxes,net,periods}|null, bank{bank_name,account "****1234"}|null, branding{logo_path,footer}, result_version, result_checksum}`. Employer contributions only if `payslips.show_employer_contributions`. YTD = calendar year of the period start, locked runs only.

## 3. Loans and advances

Permissions: request `payroll.loans.manage`, approve/adjust `payroll.loans.approve` (different user than the requester; My Work task created).

| Method path | Notes |
| --- | --- |
| GET/POST `loans` | POST `{employee_uuid, principal, currency, issue_date, recovery_start, installments 1-120, interest?{type none|flat|reducing, rate % p.a.}, recovery_method equal|custom, custom_amounts[]?, purpose?, idempotency_key}` -> 201 with `schedule[{sequence,due_date,principal,interest,amount,status}]`, `outstanding`, `status pending` |
| GET `loans/{uuid}` | |
| POST `loans/{uuid}/decision` | `{decision approved|rejected, note?}` -> `active` / `cancelled` |
| POST `loans/{uuid}/adjust` | `{entry waiver|write_off, amount, note}` (append-only ledger; closes loan at zero) |
| GET/POST `advances`, POST `advances/{uuid}/decision` | POST `{employee_uuid, amount, currency, issue_date, recovery_start, recovery_installments?, reason?, idempotency_key}`; approved = `recovering` |

Schedule: equal split (largest remainder, exact), flat interest (rate x principal x n/12 spread), reducing balance (EMI, last installment absorbs rounding). Schedule rows never change after creation. `outstanding` is derived from the append-only `payroll_loan_ledger` (recovery, waiver, write_off reduce; reversal increases).
Run-engine hooks (PHP, `Loans\LoanService`, for workstream B): `dueFor($employeeId, $periodEnd)` -> `[{type loan|advance, uuid, installment_id, amount, currency, component_code "LOAN_RECOVERY", reference}]` (pure read; includes earlier unrecovered installments); `consume(PayrollRun)` after lock (idempotent per run+installment); `release(PayrollRun)` on reopen/cancel (compensating ledger rows, installments back to `scheduled`).

## 4. Payments and bank files

Permissions: `payroll.payments.prepare` (prepare/verify bank/generate/submit/download/retry), `payroll.payments.approve` (checker), `payroll.payments.confirm` (record outcomes). Status vocabulary batch: `prepared submitted processing paid failed partially_paid`; instruction: `prepared submitted processing paid failed`. "paid" exists ONLY via confirmation with a bank reference (setting `payments.require_confirmation_for_paid`).

| Method path | Notes |
| --- | --- |
| GET `payment-formats` | `[{code,label,countries}]` - registered `PaymentFormat`s (`generic_csv` neutral reference only; no real bank format is claimed) |
| POST `bank-verifications` | `{employee_uuid, note?}` verifies the employee's primary bank account (fingerprint bound; a later change un-verifies). Setting `payments.require_bank_verification` (default true) |
| POST `runs/{runUuid}/payments` | prepare `{format?, payment_date?, idempotency_key}` from a locked run: employees with net <= 0, no/unverified/currency-mismatched bank are listed in `exceptions[]` and excluded; 422 if nobody payable. Response: `{uuid, status prepared, total_amount, employee_count, reconciliation{net_total,instructed,excluded_net,difference}, exceptions[], instructions[{uuid,employee_uuid,employee_name,amount,status,reference,account "********9012",bank_reference,failure_reason}], files[], can_approve}` |
| GET `payments`, GET `payments/{uuid}` | |
| POST `payments/{uuid}/approve` | `{decision?, comment?}` checker must differ from the preparer (setting `approvals.bank_file_checker`) |
| POST `payments/{uuid}/bank-file` | after approval; stores a versioned private file; 201 `{uuid,file_version,employee_count,expires_at,download}` |
| GET `bank-files/{uuid}/download` | permission + audit, expires per `payments.bank_file_retention_days`, 410 afterwards |
| POST `payments/{uuid}/submit` | marks handed to bank (not paid) |
| POST `payments/{uuid}/confirm` | `{preview?, updates[{uuid|reference, status processing|paid|failed, bank_reference?, reason?}]}` -> `{results[{key,result applied|unchanged|rejected|not_found|would_apply,message?}], batch}`; terminal states never change |
| POST `payments/{uuid}/import-response` | multipart CSV `reference,status,bank_reference,reason` (+ `preview`) - basic reconciliation of a bank response |
| POST `payments/{uuid}/retry` | `{idempotency_key}` new batch (version+1, `retry_of_id`) containing only failed instructions; needs approval again |

## 5. Accounting journals

Permissions: `payroll.accounting.manage` (mappings, preview), `payroll.accounting.export` (export/download).
* `GET accounting/formats` (mapper codes; `journal_csv`), `GET|POST accounting/mappings` `{component_code | kind, group_uuid?, debit_account, credit_account, dimensions?, allocation?[{percent,dimensions}] (sum 100), effective_from, effective_to?}`, `POST accounting/mappings/{uuid}/deactivate`. Resolution: component+group > component > kind default, effective-dated.
* `GET runs/{runUuid}/journal/preview?posting_date=&format=` -> `{lines[{account,debit,credit,currency,component_code,dimensions}], total_debit, total_credit, balanced, reconciliation{net_total,payments_total,journal_difference,payments_difference,ok,payments_match}}`. Unmapped components (except informational/benefit) = 422 listing them. Requires a locked run.
* `POST runs/{runUuid}/journal/export {posting_date, format?}` -> 201 versioned export (`version`, `status generated|mismatch`, `download`); `GET runs/{runUuid}/journal/exports`; `GET accounting/exports/{uuid}/download`.
Each mapped component posts debit_account / credit_account for its amount (so journals balance by construction); dimensions legal_entity, department, cost_center, location come from the employee assignment at period end.

## 6. Full and final settlement

Permissions: `payroll.settlement.manage`, `payroll.settlement.approve` (different user).
`POST settlements {employee_uuid, last_working_date, currency, group_uuid?, daily_rate?, salary_days?, notice?{required_days,served_days}, include_leave_encashment?, recover_balances?, leave_window_days?, items?[{kind,direction pay|recover,amount,explanation?}], idempotency_key}` -> itemised result `{status calculated, net_payable, summary{pay,recover,warnings[],negative}, items[{kind,direction,amount,explanation,source_ref}]}`. Items: salary days x daily rate, leave encashment from the Leave payroll contract references (units x daily rate), notice shortfall recovery, full recovery of open loan/advance balances, manual items. Daily rate = request value or `CompensationResolver` (warning + submit blocked if unknown). `POST settlements/{uuid}/calculate` (recalculate with changed inputs), `/submit` -> `review` (My Work task), `/decision {approved|rejected,note?}`. Approval stores the result via `ResultStore` in a `run_type=settlement` run (status set to `locked`, so payslips, payments and journals work on it) and recovers loan balances. Negative net blocks approval.

## 7. Tax declaration review

Permission `payroll.tax_declaration.review` (+ data scope). `POST tax-declaration-windows {pack_uuid, tax_year, opens_on, closes_on, proof_closes_on?, locked?}`; `GET tax-declarations?status=`; `GET tax-declarations/{uuid}` (audited, masked); `POST tax-declarations/{uuid}/decision {decision approved|rejected, note (required when rejected), proofs?[{uuid,status accepted|rejected,accepted_amount?,note?}]}`. Approval locks the declaration; accepted amount cannot exceed declared.

## Settings added (`PayrollSettings` section `payments`): `require_bank_verification` (true).
