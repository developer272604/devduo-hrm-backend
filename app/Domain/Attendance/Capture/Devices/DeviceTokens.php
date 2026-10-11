<?php

namespace App\Domain\Attendance\Capture\Devices;

use App\Domain\Attendance\Models\AttendanceDevice;
use Illuminate\Support\Str;

/**
 * Per-device API tokens: "atd_<prefix>.<secret>". The plaintext is returned ONCE (issue/rotate); only the 8-character
 * prefix (for lookup) and the SHA-256 hash of the whole token are stored. Comparison is constant-time.
 */
final class DeviceTokens
{
    public const PREFIX = 'atd_';

    /** Issues (or rotates) the token; the previous token stops working immediately. @return string the plaintext token */
    public static function issue(AttendanceDevice $device): string
    {
        $prefix = Str::lower(Str::random(8));
        $token = self::PREFIX.$prefix.'.'.Str::random(40);
        $device->forceFill(['auth_token_prefix' => $prefix, 'auth_token_hash' => hash('sha256', $token), 'token_issued_at' => now()])->save();

        return $token;
    }

    public static function revoke(AttendanceDevice $device): void
    {
        $device->forceFill(['auth_token_prefix' => null, 'auth_token_hash' => null, 'token_issued_at' => null])->save();
    }

    /** Resolves the device (in ANY tenant) for a presented token, or null. Does not check device status. */
    public static function resolve(?string $token): ?AttendanceDevice
    {
        if (! is_string($token) || ! preg_match('/^atd_([a-z0-9]{8})\.[A-Za-z0-9]{40}$/', $token, $m)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $found = null;
        foreach (AttendanceDevice::withoutGlobalScopes()->where('auth_token_prefix', $m[1])->whereNotNull('auth_token_hash')->get() as $device) {
            if (hash_equals($device->auth_token_hash, $hash)) {
                $found = $device;
            }
        }

        return $found;
    }
}
