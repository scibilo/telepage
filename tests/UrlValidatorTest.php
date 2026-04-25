<?php
/**
 * TELEPAGE — tests/UrlValidatorTest.php
 *
 * Dependency-free smoke tests. Run with:
 *     php tests/UrlValidatorTest.php
 *
 * Exits 0 on success, non-zero on any failure.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$failures = 0;
function assertTrue(bool $cond, string $label): void {
    global $failures;
    if ($cond) { echo "  ✓ {$label}\n"; }
    else       { echo "  ✗ {$label}\n"; $failures++; }
}

echo "UrlValidator tests\n";

// ──────────────────────────────────────────────────────────────────
// Scheme validation
// ──────────────────────────────────────────────────────────────────
echo "\n[scheme]\n";
assertTrue(!UrlValidator::isSafeToFetch('file:///etc/passwd'),     'file:// rejected');
assertTrue(!UrlValidator::isSafeToFetch('gopher://example.com/'),  'gopher:// rejected');
assertTrue(!UrlValidator::isSafeToFetch('dict://example.com/'),    'dict:// rejected');
assertTrue(!UrlValidator::isSafeToFetch('ftp://example.com/'),     'ftp:// rejected');
assertTrue(!UrlValidator::isSafeToFetch('javascript:alert(1)'),    'javascript: rejected');
assertTrue(!UrlValidator::isSafeToFetch(''),                        'empty string rejected');
assertTrue(!UrlValidator::isSafeToFetch('not a url'),              'malformed rejected');

// ──────────────────────────────────────────────────────────────────
// IPv4 literals — private / loopback / link-local / metadata
// ──────────────────────────────────────────────────────────────────
echo "\n[ipv4 literals]\n";
assertTrue(!UrlValidator::isSafeToFetch('http://127.0.0.1/'),              'loopback 127.0.0.1 rejected');
assertTrue(!UrlValidator::isSafeToFetch('http://127.1.2.3/'),              'loopback 127.x rejected');
assertTrue(!UrlValidator::isSafeToFetch('http://10.0.0.1/'),               'RFC1918 10.x rejected');
assertTrue(!UrlValidator::isSafeToFetch('http://172.16.0.1/'),             'RFC1918 172.16 rejected');
assertTrue(!UrlValidator::isSafeToFetch('http://172.31.255.254/'),         'RFC1918 172.31 rejected');
assertTrue( UrlValidator::isSafeToFetch('http://172.32.0.1/'),             '172.32 is public (outside range)');
assertTrue(!UrlValidator::isSafeToFetch('http://192.168.1.1/'),            'RFC1918 192.168 rejected');
assertTrue(!UrlValidator::isSafeToFetch('http://169.254.169.254/'),        'AWS metadata rejected');
assertTrue(!UrlValidator::isSafeToFetch('http://169.254.1.1/'),            'link-local rejected');
assertTrue(!UrlValidator::isSafeToFetch('http://0.0.0.0/'),                '0.0.0.0 rejected');
assertTrue(!UrlValidator::isSafeToFetch('http://255.255.255.255/'),        'broadcast rejected');
assertTrue(!UrlValidator::isSafeToFetch('http://224.0.0.1/'),              'multicast rejected');
assertTrue(!UrlValidator::isSafeToFetch('http://100.64.0.1/'),             'CGN 100.64 rejected');

// Public IPs should pass — using Google DNS and Cloudflare DNS as
// stable public IPs.
assertTrue( UrlValidator::isSafeToFetch('http://8.8.8.8/'),                'public 8.8.8.8 allowed');
assertTrue( UrlValidator::isSafeToFetch('http://1.1.1.1/'),                'public 1.1.1.1 allowed');

// ──────────────────────────────────────────────────────────────────
// IPv6 literals
// ──────────────────────────────────────────────────────────────────
echo "\n[ipv6 literals]\n";
assertTrue(!UrlValidator::isSafeToFetch('http://[::1]/'),                  'IPv6 loopback ::1 rejected');
assertTrue(!UrlValidator::isSafeToFetch('http://[fc00::1]/'),              'IPv6 ULA fc00::/7 rejected');
assertTrue(!UrlValidator::isSafeToFetch('http://[fe80::1]/'),              'IPv6 link-local fe80::/10 rejected');
assertTrue(!UrlValidator::isSafeToFetch('http://[::ffff:127.0.0.1]/'),     'IPv4-mapped IPv6 loopback rejected');
assertTrue(!UrlValidator::isSafeToFetch('http://[::ffff:192.168.1.1]/'),   'IPv4-mapped IPv6 private rejected');
assertTrue(!UrlValidator::isSafeToFetch('http://[ff00::1]/'),              'IPv6 multicast rejected');
assertTrue(!UrlValidator::isSafeToFetch('http://[::]/'),                   'IPv6 unspecified :: rejected');
// Public IPv6 (Google DNS)
assertTrue( UrlValidator::isSafeToFetch('http://[2001:4860:4860::8888]/'), 'public IPv6 allowed');

// ──────────────────────────────────────────────────────────────────
// URL structure
// ──────────────────────────────────────────────────────────────────
echo "\n[structure]\n";
assertTrue(!UrlValidator::isSafeToFetch('http://user:pass@8.8.8.8/'),      'credentials in URL rejected');
assertTrue(!UrlValidator::isSafeToFetch('//example.com/path'),             'schemeless rejected');

// ──────────────────────────────────────────────────────────────────
// Reasons surfaced by validate()
// ──────────────────────────────────────────────────────────────────
echo "\n[validate() reasons]\n";
$r = UrlValidator::validate('file:///etc/passwd');
assertTrue($r['ok'] === false && str_contains($r['reason'], 'scheme'), 'file:// reason mentions scheme');

$r = UrlValidator::validate('http://127.0.0.1/');
assertTrue($r['ok'] === false && str_contains($r['reason'], '127.0.0.1'), '127.0.0.1 reason mentions the IP');

$r = UrlValidator::validate('http://8.8.8.8/');
assertTrue($r['ok'] === true && in_array('8.8.8.8', $r['ips'] ?? [], true), 'valid result includes ips[]');

// ──────────────────────────────────────────────────────────────────
// isBlockedIp direct calls
// ──────────────────────────────────────────────────────────────────
echo "\n[isBlockedIp]\n";
assertTrue( UrlValidator::isBlockedIp('127.0.0.1'),           'isBlockedIp: 127.0.0.1 true');
assertTrue( UrlValidator::isBlockedIp('192.168.0.1'),         'isBlockedIp: 192.168.0.1 true');
assertTrue( UrlValidator::isBlockedIp('169.254.169.254'),     'isBlockedIp: metadata true');
assertTrue(!UrlValidator::isBlockedIp('8.8.8.8'),             'isBlockedIp: 8.8.8.8 false');
assertTrue(!UrlValidator::isBlockedIp('1.2.3.4'),             'isBlockedIp: 1.2.3.4 false');
assertTrue( UrlValidator::isBlockedIp('::1'),                 'isBlockedIp: ::1 true');
assertTrue(!UrlValidator::isBlockedIp('2001:4860:4860::8888'), 'isBlockedIp: public IPv6 false');
assertTrue( UrlValidator::isBlockedIp('not-an-ip'),           'isBlockedIp: garbage true (fail-safe)');

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} test(s)\n";
    exit(1);
}
echo "All tests passed\n";
exit(0);
