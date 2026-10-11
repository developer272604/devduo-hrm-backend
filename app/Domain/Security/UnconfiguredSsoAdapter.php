<?php

namespace App\Domain\Security;

use App\Domain\Access\VerifiedIdentity;
use App\Domain\Security\Contracts\SsoAdapter;

/** Placeholder adapter: fails closed. Replace with a reviewed OIDC/SAML implementation to go live. */
final class UnconfiguredSsoAdapter implements SsoAdapter
{
    public function protocol(): string
    {
        return 'none';
    }

    public function authorizationRequest(string $tenantSlug, string $state): array
    {
        throw new \RuntimeException('SSO is not live: no identity provider adapter is installed.');
    }

    public function verifyResponse(array $payload): VerifiedIdentity
    {
        throw new \RuntimeException('SSO is not live: no identity provider adapter is installed.');
    }

    public function isLive(): bool
    {
        return false;
    }
}
