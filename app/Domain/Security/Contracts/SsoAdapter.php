<?php

namespace App\Domain\Security\Contracts;

use App\Domain\Access\VerifiedIdentity;

/**
 * SSO adapter INTERFACE ONLY (OIDC / SAML). NOT LIVE: no identity provider integration ships with the product, no
 * federated login route exists, and the "enabled" setting cannot be turned on. Implementations must be added and
 * security-reviewed against a real IdP before enabling.
 */
interface SsoAdapter
{
    /** "oidc" or "saml". */
    public function protocol(): string;

    /** Build the redirect/request that starts a login at the identity provider. */
    public function authorizationRequest(string $tenantSlug, string $state): array;

    /** Validate the IdP response (signature, audience, nonce, expiry) and return verified claims. @throws \RuntimeException */
    public function verifyResponse(array $payload): VerifiedIdentity;

    public function isLive(): bool;
}
