<?php

namespace Tests\Feature\Security;

use App\Domain\Security\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    public function test_rfc6238_sha1_vectors(): void
    {
        $key = '12345678901234567890';
        foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $time => $expected) {
            $this->assertSame($expected, Totp::code($key, $time, 8, 'sha1'), "SHA1 at $time");
        }
    }

    public function test_rfc6238_sha256_and_sha512_vectors(): void
    {
        $this->assertSame('46119246', Totp::code('12345678901234567890123456789012', 59, 8, 'sha256'));
        $this->assertSame('90693936', Totp::code(str_repeat('1234567890', 6).'1234', 59, 8, 'sha512'));
    }

    public function test_rfc4226_hotp_vectors(): void
    {
        foreach (['755224', '287082', '359152', '969429', '338314'] as $counter => $expected) {
            $this->assertSame($expected, Totp::hotp('12345678901234567890', $counter));
        }
    }

    public function test_base32_round_trip_and_verify_window_and_replay(): void
    {
        $secret = Totp::generateSecret();
        $this->assertSame(32, strlen($secret));
        $this->assertSame(20, strlen(Totp::base32Decode($secret)));
        $this->assertSame('foobar', Totp::base32Decode(Totp::base32Encode('foobar')));
        $now = 1700000000;
        $code = Totp::code(Totp::base32Decode($secret), $now);
        $step = Totp::verify($secret, $code, $now);
        $this->assertSame(Totp::step($now), $step);
        $this->assertNotNull(Totp::verify($secret, $code, $now + 30), 'one step of drift is tolerated');
        $this->assertNull(Totp::verify($secret, $code, $now + 120), 'outside the window');
        $this->assertNull(Totp::verify($secret, $code, $now, 1, $step), 'a used step cannot be replayed');
        $this->assertNull(Totp::verify($secret, 'abcdef', $now));
        $this->assertNull(Totp::verify($secret, '12345', $now));
    }

    public function test_otpauth_uri(): void
    {
        $uri = Totp::uri('Acme Co', 'a@b.test', 'JBSWY3DPEHPK3PXP');
        $this->assertStringStartsWith('otpauth://totp/Acme%20Co%3Aa%40b.test?secret=JBSWY3DPEHPK3PXP&issuer=Acme%20Co', $uri);
    }
}
