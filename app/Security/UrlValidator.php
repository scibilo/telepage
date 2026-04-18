<?php

/**
 * TELEPAGE — UrlValidator.php
 * SSRF protection — decides whether a URL is safe to fetch from
 * the server side.
 *
 * Rules (strict, no configurable whitelist):
 *  - Scheme MUST be http or https.
 *  - URL MUST parse cleanly.
 *  - Host resolves to one or more IPs; ALL of them must be public.
 *  - Blocked ranges include loopback, RFC 1918 private networks,
 *    link-local (incl. AWS/GCP metadata at 169.254.169.254),
 *    multicast, reserved, ULA/link-local IPv6.
 *
 * This class is pure: no network I/O except gethostbynamel() for
 * DNS. No dependency on Config or DB. Safe to unit-test.
 *
 * Usage:
 *    if (!UrlValidator::isSafeToFetch($url)) {
 *        // refuse to curl this URL
 *    }
 *
 * Integrators MUST also verify redirects. Since cURL's FOLLOWLOCATION
 * bypasses this check, callers should disable FOLLOWLOCATION and
 * re-validate every redirect target manually. See docs in Scraper.
 */

declare(strict_types=1);

class UrlValidator
{
    /**
     * Allowed URL schemes. Anything outside this list (file://,
     * gopher://, dict://, ftp://, etc.) is rejected.
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * IPv4 CIDR blocks that must never be targeted.
     * Sourced from RFC 1918, RFC 3927, RFC 5735, RFC 6598, AWS/GCP metadata docs.
     *
     * Format: [ip_string, prefix_bits]
     */
    private const BLOCKED_IPV4_RANGES = [
        ['0.0.0.0',        8],   // "this network"
        ['10.0.0.0',       8],   // RFC 1918 private
        ['100.64.0.0',    10],   // RFC 6598 CGN
        ['127.0.0.0',      8],   // loopback
        ['169.254.0.0',   16],   // link-local (includes cloud metadata)
        ['172.16.0.0',    12],   // RFC 1918 private
        ['192.0.0.0',     24],   // IETF protocol assignments
        ['192.0.2.0',     24],   // TEST-NET-1 (docs)
        ['192.168.0.0',   16],   // RFC 1918 private
        ['198.18.0.0',    15],   // benchmarking
        ['198.51.100.0',  24],   // TEST-NET-2 (docs)
        ['203.0.113.0',   24],   // TEST-NET-3 (docs)
        ['224.0.0.0',      4],   // multicast
        ['240.0.0.0',      4],   // reserved
        ['255.255.255.255', 32], // broadcast
    ];

    /**
     * IPv6 prefixes that must never be targeted.
     * Stored as a textual prefix of the fully-expanded address so we
     * can match with a simple string comparison after expansion.
     *
     * Format: [ipv6_string, prefix_bits]
     */
    private const BLOCKED_IPV6_RANGES = [
        ['::',      128], // unspecified
        ['::1',     128], // loopback
        ['::ffff:0:0',   96],  // IPv4-mapped (covers all IPv4)
        ['64:ff9b::',    96],  // IPv4-IPv6 translation
        ['100::',   64],  // discard
        ['fc00::',  7],   // unique local (ULA)
        ['fe80::',  10],  // link-local
        ['ff00::',  8],   // multicast
    ];

    /**
     * Main entry point: true if the URL can safely be fetched from
     * the server. Does DNS resolution.
     */
    public static function isSafeToFetch(string $url): bool
    {
        return self::validate($url)['ok'];
    }

    /**
     * Same as isSafeToFetch but returns a structured result with
     * the reason the URL was rejected. Useful for logging.
     *
     * @return array{ok:bool, reason?:string, host?:string, ips?:array}
     */
    public static function validate(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'reason' => 'empty URL'];
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme'])) {
            return ['ok' => false, 'reason' => 'malformed URL'];
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return ['ok' => false, 'reason' => "scheme not allowed: {$scheme}"];
        }

        if (empty($parts['host'])) {
            return ['ok' => false, 'reason' => 'malformed URL: missing host'];
        }

        $host = strtolower($parts['host']);

        // Reject userinfo (user:pass@host) — not useful for scraping
        // and can confuse URL parsers downstream.
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return ['ok' => false, 'reason' => 'URL contains credentials'];
        }

        // If the host is already a literal IP, validate it directly.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (self::isBlockedIp($host)) {
                return ['ok' => false, 'reason' => "blocked IP literal: {$host}"];
            }
            return ['ok' => true, 'host' => $host, 'ips' => [$host]];
        }

        // IPv6 literals in URLs are bracketed: [::1]
        $hostTrimmed = trim($host, '[]');
        if ($hostTrimmed !== $host && filter_var($hostTrimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if (self::isBlockedIp($hostTrimmed)) {
                return ['ok' => false, 'reason' => "blocked IPv6 literal: {$hostTrimmed}"];
            }
            return ['ok' => true, 'host' => $hostTrimmed, 'ips' => [$hostTrimmed]];
        }

        // DNS hostname — resolve and check every returned IP.
        // If ANY resolved IP is blocked, reject the whole URL.
        // This defends against DNS rebinding hacks where a host
        // resolves to a public IP on first lookup and a private one
        // on the second.
        $ips = @gethostbynamel($host);
        if ($ips === false || count($ips) === 0) {
            return ['ok' => false, 'reason' => "DNS resolution failed for {$host}"];
        }

        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                return [
                    'ok'     => false,
                    'reason' => "host {$host} resolves to blocked IP {$ip}",
                    'host'   => $host,
                    'ips'    => $ips,
                ];
            }
        }

        return ['ok' => true, 'host' => $host, 'ips' => $ips];
    }

    /**
     * Returns true if the given IP (v4 or v6) falls inside any
     * blocked range.
     */
    public static function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::BLOCKED_IPV4_RANGES as [$net, $bits]) {
                if (self::ipv4InRange($ip, $net, $bits)) {
                    return true;
                }
            }
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            foreach (self::BLOCKED_IPV6_RANGES as [$net, $bits]) {
                if (self::ipv6InRange($ip, $net, $bits)) {
                    return true;
                }
            }
            return false;
        }

        // Not a recognised IP string: treat as blocked to be safe.
        return true;
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    /**
     * Checks whether an IPv4 address falls inside network/prefix.
     */
    private static function ipv4InRange(string $ip, string $network, int $bits): bool
    {
        $ipLong  = ip2long($ip);
        $netLong = ip2long($network);
        if ($ipLong === false || $netLong === false) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }
        // Build an int32 mask with the top $bits bits set.
        $mask = ($bits === 32) ? 0xFFFFFFFF : (~((1 << (32 - $bits)) - 1)) & 0xFFFFFFFF;
        return (($ipLong & $mask) === ($netLong & $mask));
    }

    /**
     * Checks whether an IPv6 address falls inside network/prefix.
     * Works in binary on inet_pton packed representation.
     */
    private static function ipv6InRange(string $ip, string $network, int $bits): bool
    {
        $ipBin  = @inet_pton($ip);
        $netBin = @inet_pton($network);
        if ($ipBin === false || $netBin === false) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }
        if ($bits > 128) {
            $bits = 128;
        }

        $fullBytes   = intdiv($bits, 8);
        $remainBits  = $bits % 8;

        // Compare the leading full bytes verbatim.
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }
        // If bits isn't a byte multiple, check the partial byte under mask.
        if ($remainBits > 0) {
            $mask = chr((0xFF << (8 - $remainBits)) & 0xFF);
            $ipByte  = $ipBin[$fullBytes]  ?? "\0";
            $netByte = $netBin[$fullBytes] ?? "\0";
            if (($ipByte & $mask) !== ($netByte & $mask)) {
                return false;
            }
        }
        return true;
    }
}
