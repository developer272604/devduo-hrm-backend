# Public recruitment surface — implementation review plan

Status: listing/intake/recruiter-review plus verified candidate login/status controls are implemented and locally tested. Public uploads, candidate self-service decisions and live provider delivery remain pending. Portal login is disabled by default pending mail/queue configuration. This document is not a completed penetration test or provider/legal approval.

## Findings

- Existing recruitment routes require employee session authentication, tenant middleware and recruitment permissions. Candidate portal access must not grant or reuse employee roles.
- TenantRecord queries require an explicit TenantContext; public requests must resolve a workspace from a public identifier, verify recruitment/careers enablement, and clear context in a finally block. Never trust a submitted tenant_id.
- Current posting publication can mean local/internal publication through a neutral adapter. A separate explicit public visibility setting is needed; existing published records must not become public automatically.
- Candidate email hashes support deduplication, but they do not establish email ownership. Anonymous applicants must not receive candidate IDs, duplicate-match details, interview notes, or existing applications based on email alone.
- Resume routes are recruiter-scoped private storage. A public upload requires a separate authorization boundary, quarantine/scan policy, type/size limits and retention cleanup; do not expose the existing private file path.

## Implementation order

1. Add default-off public careers settings and explicit public posting visibility. List/detail allowlists contain job-facing fields only. Exclude confidential requisitions, internal postings, closed/expired postings and disabled tenants.
2. Add bounded, CSRF-protected, rate-limited application intake with explicit notice version/purpose/consent and validated retention. Preserve source attribution. Return a generic receipt and keep unverified submissions separate from existing candidate identities until review or email verification.
3. Add candidate identity verification through an independently scoped, expiring, single-use challenge. Store hashes, invalidate retries/replays, and use generic responses to avoid account enumeration. Live email delivery requires an adapter and provider credentials; do not return a login secret in a public API response.
4. Add candidate-only sessions and status/document views after verification. Exclude recruiter notes, independent feedback, compensation benchmarks, other candidates and other tenants. Offer decisions need state validation and audit provenance.
5. Add safe resume upload and scanning before documents become available. External antivirus verification remains a release gate.

## Required acceptance tests

Cross-tenant and confidential-posting exclusion; explicit publication/expiry; disabled modules; input and file size bounds; duplicate/email enumeration resistance; consent provenance; context cleanup; CSRF/rate limits; challenge expiry/replay; identity separation from employee sessions; ownership checks on every candidate document and offer action; retained/erased data behavior; accessible loading/error/confirmation flows.

External gates: mail/provider sandbox evidence, antivirus behavior, production database concurrency, and country-specific privacy/offer terms. Local implementation and tests can proceed without these credentials, but must not claim live delivery or legal certification.
