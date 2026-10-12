# People records API: addresses, documents, identity, work authorization, bank, photo, countries

Base path `/api/v1` (session + CSRF, tenant-scoped, module `people`). `{uuid}` is the **employee** uuid; `{id}` is the **record** uuid
(document types use their `code`). Records belong to the person, so they survive rehire. Responses never contain sequential ids.

## Conventions

| Status | Meaning |
| --- | --- |
| 403 | Authenticated but not allowed (missing permission, no access to the section, edit rule `none`). |
| 404 | Employee/record does not exist, is outside your data scope, or belongs to another tenant. |
| 409 `{code:'change_request_required', section}` | The tenant edit rule for this section is `request`; submit a change request instead. `section` is `address`, `document`, `identity`, `work_authorization` or `bank`. |
| 409 `{code:'possible_duplicate'}` | HR only: the identifier already exists on another person. Resend with `confirm_duplicate: true`. |
| 422 | Validation (`errors` keyed by field, friendly sentences; values are never echoed). Country rules use the field name, e.g. `postal_code`, `extra.suburb`, `details.ifsc`. |

Every list response has `data` and a `viewer` block (`edit`: `direct|request|none`, `can_reveal`, `mode`) so the UI can offer the right action.
Highly sensitive values (identity numbers, bank details) are **masked in every response** (`••••1234`); only the explicit `reveal` endpoints return
plaintext (`people.sensitive.reveal`, audited, `Cache-Control: no-store`).

## Countries

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| GET | `/people/countries` | `people.view` or `people.directory` | Enabled countries: `code,name,currency,timezone,locale,date_format,number_format,phone_code,name_format,working_language,source`. |
| GET | `/people/countries/{code}` | same | Merged pack (default + tenant override): scalars plus `address_format`, `identifier_types`, `bank_schema`, `employment_fields`, `statutory_fields`, `enabled`, `source` (`default|override|custom|generic`). Unknown country: 404. |
| PUT | `/people/countries/{code}` | `people.settings` | Upsert override. Send only what changes (null clears). A brand-new country needs `name`. Audited. |
| DELETE | `/people/countries/{code}` | `people.settings` | Remove the override (back to the shipped pack). |

Pack entries:
* `address_format[]`: `{key,label,required,pattern?,hint?,options?:[{value,label}]}`; keys `line1,line2,city,region,postal_code` are columns, any other key goes in the address `extra` object.
* `identifier_types[]`: `{key,label,kind:'identity'|'work_authorization',pattern?,classification,mask:'last4'|'last2'|'full',checksum?}`.
* `bank_schema[]`: `{key,label,required,pattern?,normalize?,primary?,checksum?,options?}`.
* Lists merge **by key**; `{key,disabled:true}` removes a default entry. Patterns are regex source without delimiters and are matched against the normalized value (upper case, no spaces . - /).

## Addresses  (`address.records`, employee-class; edit group `address`)

| Method | Path | Permission | Body / notes |
| --- | --- | --- | --- |
| GET | `/employees/{uuid}/addresses` | `people.view` | Self or `people.personal.view`; others 403. |
| POST | `/employees/{uuid}/addresses` | `people.view` + edit rule | `type` (current\|permanent\|mailing\|other), `line1`, `line2?`, `city?`, `region?`, `postal_code?`, `country` (ISO-2), `extra?{}`, `is_primary?`, `effective_from?`, `effective_to?` (Y-m-d). Validated by the country pack. 201. |
| PATCH | `/employees/{uuid}/addresses/{id}` | same | Any subset. |
| DELETE | `/employees/{uuid}/addresses/{id}` | same | 204. |

Response: `{uuid,type,line1,line2,city,region,postal_code,country,extra,is_primary,effective_from,effective_to,formatted,updated_at}`. One primary per type; the first of a type is primary automatically. Option fields (e.g. India state) are stored as the option `value` (`KA`); labels or values are accepted on input.

## Documents  (private storage, versioned)

| Method | Path | Permission | Body / notes |
| --- | --- | --- | --- |
| GET | `/people/document-types` | `people.view` | Seeds defaults lazily. Non-admins get active types only. |
| POST / PATCH / DELETE | `/people/document-types[/{code}]` | `people.settings` | `code,name,category,requires_expiry,mandatory,mandatory_for{countries[],employee_types[]},classification,active`. Built-in or used types cannot be deleted (deactivate). |
| GET | `/employees/{uuid}/documents` | `people.view` | Filters `category,type,verification_status`. HR (`people.documents.manage` or `people.confidential.view`) sees all; the employee sees only `employee_visible`; managers/others 403. |
| GET | `/employees/{uuid}/documents/requirements` | `people.view` | Mandatory types for this employee with `provided`. |
| POST | `/employees/{uuid}/documents` | `people.view` + edit rule `document` | multipart: `file` (<= 10 MB; pdf, jpg, png, webp, doc, docx, xls, xlsx; real content type checked), `document_type` (code), `title?`, `issue_date?`, `expiry_date?` (required when the type `requires_expiry`), `reference_number?`, `notes?`, `tags[]?`, `employee_visible?`. Employee uploads are always visible and `pending`. 201. |
| GET | `/employees/{uuid}/documents/{id}` | `people.view` | Includes `versions[]`. |
| PATCH | `/employees/{uuid}/documents/{id}` | edit rule | Metadata only. Employees cannot change verified documents or `employee_visible`. |
| POST | `/employees/{uuid}/documents/{id}/versions` | edit rule | multipart `file`. Appends a version, resets verification to `pending`. |
| GET | `/employees/{uuid}/documents/{id}/download?version=&inline=1` | visibility | Authorized stream (no URLs exist). `inline=1` only for pdf/images. Confidential downloads are audited (`downloaded`). |
| POST | `/employees/{uuid}/documents/{id}/verify` | `people.documents.verify` | `{status: pending\|verified\|rejected}`; you cannot verify your own documents. |
| DELETE | `/employees/{uuid}/documents/{id}` | `people.documents.manage` | Soft delete (versions retained). 204. |

Document: `{uuid,type{code,name,category,classification},title,issue_date,expiry_date,expiry_status(expired|expiring|valid|null),reference_number,verification_status,verified_by,verified_at,employee_visible,notes,tags,current_version{version,original_name,mime,size,sha256,uploaded_at,uploaded_by},previewable,can{download,edit,verify,delete},versions?}`.
Upload errors (422, key `file`): too large, type not allowed, content does not match extension, executable/double extension.

## Identity  (`identity.records`, highly sensitive)

| Method | Path | Permission | Body / notes |
| --- | --- | --- | --- |
| GET | `/employees/{uuid}/identity` | `people.view`; self or `people.sensitive.view`/`reveal`/`identity.manage` | Masked list; others 403. |
| POST | `/employees/{uuid}/identity` | `people.view` + edit rule `identity` (HR: `people.identity.manage`) | `type` (pack identity type), `country` (issuing, ISO-2), `number`, `issue_date?`, `expiry_date?`, `document_id?` (document uuid), `confirm_duplicate?`. Number validated against the pack pattern/checksum. |
| PATCH | `/employees/{uuid}/identity/{id}` | same | Changing the number resets verification. |
| DELETE | `/employees/{uuid}/identity/{id}` | same | 204. |
| POST | `/employees/{uuid}/identity/{id}/verify` | `people.documents.verify` | `{status: unverified\|verified\|rejected}`; not on your own records. |
| POST | `/employees/{uuid}/identity/{id}/reveal` | `people.sensitive.reveal` | `{uuid,type,number}`; audited (`revealed`). |

Item: `{uuid,type,type_label,country,number_masked,issue_date,expiry_date,expiry_status,verification_status,verified_by,verified_at,document_id,...}`.

## Work authorizations  (`work_authorization.records`, same access rules as identity)

Paths `/employees/{uuid}/work-authorizations[/{id}[/verify|/reveal]]`. Body: `type` (pack type of kind `work_authorization`), `country`, `number?`, `sponsor?`, `issue_date?`, `expiry_date?`, `status?` (active\|pending\|expired\|revoked), `document_id?`. `status` reads `expired` when active but past expiry.

## Bank accounts  (`bank.records`, highly sensitive, edit group `bank`; self-service default is `request`)

| Method | Path | Permission | Body / notes |
| --- | --- | --- | --- |
| GET | `/employees/{uuid}/bank-accounts` | `people.view`; self or `people.sensitive.view`/`reveal`/`bank.manage` | Masked; non-self reads are audited (`bank_viewed`). |
| GET | `/employees/{uuid}/bank-accounts/{id}` | same | |
| POST | `/employees/{uuid}/bank-accounts` | `people.view` + edit rule (HR: `people.bank.manage`) | `account_holder`, `bank_name`, `country`, `currency?` (default from pack), `details{...pack bank_schema fields}`, `is_primary?`, `effective_from?`, `effective_to?`. |
| PATCH | `/employees/{uuid}/bank-accounts/{id}` | same | Active accounts only; `details` replaces all details. |
| POST | `/employees/{uuid}/bank-accounts/{id}/retire` | same | `{effective_to?}`. Accounts are never deleted. |
| POST | `/employees/{uuid}/bank-accounts/{id}/reveal` | `people.sensitive.reveal` | `{uuid,details}`; audited (`revealed`). |

Item: `{uuid,account_holder,bank_name,country,currency,account_last4,account_masked,details{key:masked},is_primary,status(active|retired),effective_from,effective_to}`. One active primary per person.

## Photo

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| GET | `/employees/{uuid}/photo` | `people.view` or `people.directory` | Streams a JPEG. 404 when none or hidden by directory privacy (`person.photo`). Coworkers outside your scope are visible only while working. |
| POST | `/employees/{uuid}/photo` | `people.view` | multipart `file` (jpg/png/webp, <= 5 MB). Re-encoded to JPEG <= 512 px, metadata stripped. Self always allowed; HR needs direct edit rights. 201 `{has_photo:true}`. |
| DELETE | `/employees/{uuid}/photo` | same | 204. |

## Audit and events

Audit entries use resource `employee:{uuid}` with `context.section` and `context.record`; actions: `created, updated, deleted, uploaded, version_added, verified, rejected, downloaded, revealed, bank_viewed, retired, photo_updated`. Domain events: `DocumentUploaded, DocumentUpdated, DocumentVerified, DocumentRejected, DocumentDeleted, DocumentExpiring, AddressChanged, IdentityChanged, IdentityVerified, WorkAuthorizationChanged, WorkAuthorizationVerified, BankAccountChanged, ProfilePhotoChanged`.
`php artisan people:document-expiry [--days=N]` (window default: `people.document_expiry_days` setting, else 30) records one `DocumentExpiring` event per item and window and opens a My Work task for users with `people.documents.verify`.
