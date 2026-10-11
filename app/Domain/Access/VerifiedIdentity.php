<?php

namespace App\Domain\Access;

final readonly class VerifiedIdentity
{
    public function __construct(public string $provider, public string $subject, public string $email, public bool $emailVerified) {}
}
