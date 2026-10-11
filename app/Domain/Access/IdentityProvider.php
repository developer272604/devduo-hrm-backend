<?php

namespace App\Domain\Access;

/** Extension boundary for future OIDC/SAML adapters. Not enabled in Phase 1. */
interface IdentityProvider
{
    public function authorizationUrl(string $state, string $redirectUri): string;

    /** Implementations must validate signature, issuer, audience, state, nonce and tenant membership. */
    public function resolveVerifiedIdentity(array $callback): VerifiedIdentity;
}
