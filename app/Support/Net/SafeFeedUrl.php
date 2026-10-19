<?php

declare(strict_types=1);

namespace App\Support\Net;

use Closure;

/**
 * Decides whether an owner-entered web address is safe for the SERVER to fetch (SSRF guard).
 *
 * One helper, used twice: when a feed source is saved (so the owner gets a plain-words message
 * straight away) and again at fetch time on every hop of every redirect (so a feed that later
 * starts pointing somewhere private is still refused). Only ordinary public web addresses pass:
 *
 *   - http / https only, standard ports only (80 / 443), no user:password in the link;
 *   - the host must resolve, and EVERY address it resolves to must be a public one — loopback,
 *     private (RFC 1918), link-local (incl. the 169.254.169.254 cloud metadata address), CGNAT,
 *     documentation/benchmark, multicast and other reserved ranges are refused, IPv4 and IPv6
 *     (IPv4-mapped / NAT64 / 6to4 IPv6 forms are unwrapped and judged as the IPv4 they carry);
 *   - odd numeric hosts that a browser or curl would read as an IP (2130706433, 0x7f.1) are refused.
 *
 * DNS goes through an injectable resolver so tests can fake it. The resolver takes a host name
 * and returns a list of IP strings; an empty list means "does not exist".
 */
class SafeFeedUrl
{
    public const ALLOWED_PORTS = [80, 443];

    /** @var Closure(string): array<int, string> */
    private Closure $resolver;

    /** @param (callable(string): array<int, string>)|null $resolver */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver !== null ? Closure::fromCallable($resolver) : Closure::fromCallable([self::class, 'resolveViaDns']);
    }

    /**
     * Inspect a URL.
     *
     * @return array{ok: bool, error: ?string, host: ?string, port: int, ips: array<int, string>, literal: bool}
     */
    public function inspect(string $url): array
    {
        $fail = static fn (string $why): array => ['ok' => false, 'error' => $why, 'host' => null, 'port' => 0, 'ips' => [], 'literal' => false];

        $url = trim($url);
        $p   = parse_url($url);
        if ($p === false || ! isset($p['scheme'], $p['host'])) {
            return $fail('That does not look like a web link (it must start with http:// or https://).');
        }
        if (! in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
            return $fail('That does not look like a web link (it must start with http:// or https://).');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return $fail('Remove the user name and password from the link — feeds that need a login are not supported.');
        }

        $port = isset($p['port']) ? (int) $p['port'] : (strtolower($p['scheme']) === 'https' ? 443 : 80);
        if (! in_array($port, self::ALLOWED_PORTS, true)) {
            return $fail('Only normal web addresses are allowed (no custom port numbers).');
        }

        $host = strtolower(trim($p['host'], '[]'));
        if ($host === '' || preg_match('/[\s\x00-\x1f]/', $host)) {
            return $fail('That does not look like a web link (it must start with http:// or https://).');
        }

        $notAllowed = 'That address points to a private or internal network, so it cannot be used as a feed.';

        // Literal IP in the link.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! self::isPublicIp($host)) {
                return $fail($notAllowed);
            }

            return ['ok' => true, 'error' => null, 'host' => $host, 'port' => $port, 'ips' => [$host], 'literal' => true];
        }

        // Numeric / hex / octal shorthand that curl would read as an IP (2130706433, 0x7f.1, 017700000001).
        if (preg_match('/^[0-9a-fx.]+$/i', $host) === 1 && preg_match('/[0-9]/', $host) === 1) {
            return $fail($notAllowed);
        }
        if (! preg_match('/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/i', $host) || ! str_contains($host, '.')) {
            return $fail('That does not look like a public web address.');
        }

        $ips = array_values(array_filter(
            ($this->resolver)($host),
            static fn ($ip) => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false
        ));
        if ($ips === []) {
            return $fail('We could not find that website. Check the link and try again.');
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return $fail($notAllowed);
            }
        }

        return ['ok' => true, 'error' => null, 'host' => $host, 'port' => $port, 'ips' => $ips, 'literal' => false];
    }

    /** A plain-words problem with the URL, or null when it is fine to fetch. */
    public function error(string $url): ?string
    {
        return $this->inspect($url)['error'];
    }

    /** Whether an IP (v4 or v6) is a normal public internet address. */
    public static function isPublicIp(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }

        if (strlen($bin) === 16) {
            // Unwrap IPv4 carried inside an IPv6 address and judge it as that IPv4.
            $embedded = self::embeddedV4($bin);
            if ($embedded !== null) {
                return self::isPublicIp($embedded);
            }
        }

        $ranges = strlen($bin) === 4 ? self::V4_BLOCKED : self::V6_BLOCKED;
        foreach ($ranges as $cidr) {
            if (self::inCidr($bin, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private const V4_BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const V6_BLOCKED = [
        '::/128', '::1/128', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32',
        'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** The IPv4 inside ::ffff:a.b.c.d, 64:ff9b::/96 (NAT64), ::a.b.c.d (compat) or 2002::/16 (6to4), else null. */
    private static function embeddedV4(string $bin): ?string
    {
        $isZeros = static fn (string $s): bool => $s === str_repeat("\0", strlen($s));

        if ($isZeros(substr($bin, 0, 10)) && substr($bin, 10, 2) === "\xff\xff") {
            return inet_ntop(substr($bin, 12, 4)) ?: null;
        }
        if (substr($bin, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
            return inet_ntop(substr($bin, 12, 4)) ?: null;
        }
        // IPv4-compatible (::a.b.c.d), excluding :: and ::1 which the plain ranges handle.
        if ($isZeros(substr($bin, 0, 12)) && ! $isZeros(substr($bin, 12, 4)) && substr($bin, 12, 4) !== "\0\0\0\1") {
            return inet_ntop(substr($bin, 12, 4)) ?: null;
        }
        if (substr($bin, 0, 2) === "\x20\x02") {
            return inet_ntop(substr($bin, 2, 4)) ?: null;
        }

        return null;
    }

    private static function inCidr(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netBin = inet_pton($net);
        if ($netBin === false || strlen($netBin) !== strlen($bin)) {
            return false;
        }

        $bits  = (int) $bits;
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return (ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /** @return array<int, string> */
    private static function resolveViaDns(string $host): array
    {
        $ips = [];
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $key) {
            $records = @dns_get_record($host, $type);
            foreach (is_array($records) ? $records : [] as $r) {
                if (isset($r[$key])) {
                    $ips[] = (string) $r[$key];
                }
            }
        }
        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            $ips = is_array($v4) ? $v4 : [];
        }

        return array_values(array_unique($ips));
    }
}
