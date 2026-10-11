<?php

namespace App\Domain\Integrations\Webhooks;

/**
 * Signature scheme (documented for receivers): header "X-Devduo-Signature: t=<unix seconds>,v1=<hex>" where
 * v1 = HMAC-SHA256(secret, "<t>.<raw body>"). Receivers must reject signatures older than the replay window (default 300 s).
 */
final class WebhookSigner
{
    public const TOLERANCE = 300;

    public static function sign(string $secret, string $body, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();

        return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret);
    }

    public static function verify(string $secret, string $body, ?string $header, int $tolerance = self::TOLERANCE, ?int $now = null): bool
    {
        if (! $header || ! preg_match('/^t=(\d{9,12}),v1=([a-f0-9]{64})$/', $header, $m)) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $m[1]) > $tolerance) {
            return false; // outside the replay window (too old or too far in the future)
        }

        return hash_equals(hash_hmac('sha256', $m[1].'.'.$body, $secret), $m[2]);
    }
}
