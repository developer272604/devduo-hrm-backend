<?php

namespace App\Domain\Integrations\Webhooks;

/**
 * SSRF protection for outbound webhooks. A target must be https (http only when explicitly allowed AND the app runs in local/testing),
 * carry no credentials, use a standard port, and resolve ONLY to public IP addresses. It is checked when the endpoint is saved and
 * again before every delivery (DNS can change); the validated address is pinned for the request.
 */
final class UrlGuard
{
    /** @var (callable(string):array<int,string>)|null */
    private static $resolver = null;

    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function devAllowed(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    /** @return array{host:string,port:int,ip:string,scheme:string} @throws \InvalidArgumentException */
    public static function check(string $url, bool $allowInsecure = false): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            throw new \InvalidArgumentException('Enter a valid webhook URL.');
        }
        $scheme = strtolower($parts['scheme']);
        $devHttp = $allowInsecure && self::devAllowed();
        if ($scheme !== 'https' && ! ($scheme === 'http' && $devHttp)) {
            throw new \InvalidArgumentException('Webhook URLs must use https.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Webhook URLs must not contain credentials.');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (! ($devHttp && $scheme === 'http') && ! in_array($port, [443, 8443], true)) {
            throw new \InvalidArgumentException('Webhook URLs must use port 443 or 8443.');
        }
        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if (! $ips) {
            throw new \InvalidArgumentException('The webhook host could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (! ($devHttp && $scheme === 'http') && self::isBlocked($ip)) {
                throw new \InvalidArgumentException('The webhook host resolves to a private, loopback or reserved address, which is not allowed.');
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0], 'scheme' => $scheme];
    }

    public static function isBlocked(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return true;
        }
        if (strlen($bin) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible forms inherit the IPv4 verdict.
            if (substr($bin, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
                return self::isBlocked(inet_ntop(substr($bin, 12)));
            }
            $first = ord($bin[0]);
            $second = ord($bin[1]);
            if ($bin === str_repeat("\0", 16) || $bin === str_repeat("\0", 15)."\x01" // :: and ::1
                || ($first & 0xFE) === 0xFC                                          // fc00::/7 unique local
                || ($first === 0xFE && ($second & 0xC0) === 0x80)                    // fe80::/10 link-local
                || $first === 0xFF                                                   // multicast
                || ($first === 0x20 && $second === 0x01 && ord($bin[2]) === 0x0D && ord($bin[3]) === 0xB8) // documentation
                || ($first === 0x01 && $second === 0x00 && substr($bin, 2, 6) === str_repeat("\0", 6))) { // discard
                return true;
            }

            return false;
        }
        [$a, $b] = [ord($bin[0]), ord($bin[1])];

        return $a === 0 || $a === 10 || $a === 127 || $a >= 224
            || ($a === 100 && $b >= 64 && $b <= 127)        // CGNAT
            || ($a === 169 && $b === 254)                   // link-local / cloud metadata
            || ($a === 172 && $b >= 16 && $b <= 31)
            || ($a === 192 && $b === 168)
            || ($a === 192 && $b === 0 && ord($bin[2]) <= 2) // 192.0.0.0/24, 192.0.2.0/24
            || ($a === 198 && ($b === 18 || $b === 19))     // benchmarking
            || ($a === 198 && $b === 51 && ord($bin[2]) === 100)
            || ($a === 203 && $b === 0 && ord($bin[2]) === 113);
    }

    /** @return array<int,string> */
    private static function resolve(string $host): array
    {
        if (self::$resolver) {
            return (self::$resolver)($host);
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        return array_values(array_filter($ips));
    }
}
