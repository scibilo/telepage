<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use UrlValidator;

/**
 * Tests for UrlValidator (SSRF protection).
 *
 * Migrated from tests/UrlValidatorTest.php (kept historically until C2
 * — this is C2). Methods grouped by validation category so a regression
 * in, say, IPv6 handling doesn't drown a regression in scheme handling.
 */
final class UrlValidatorTest extends TestCase
{
    // -----------------------------------------------------------------
    // Scheme validation
    // -----------------------------------------------------------------

    public function testRejectedSchemes(): void
    {
        $this->assertFalse(UrlValidator::isSafeToFetch('file:///etc/passwd'));
        $this->assertFalse(UrlValidator::isSafeToFetch('gopher://example.com/'));
        $this->assertFalse(UrlValidator::isSafeToFetch('dict://example.com/'));
        $this->assertFalse(UrlValidator::isSafeToFetch('ftp://example.com/'));
        $this->assertFalse(UrlValidator::isSafeToFetch('javascript:alert(1)'));
        $this->assertFalse(UrlValidator::isSafeToFetch(''),         'empty string rejected');
        $this->assertFalse(UrlValidator::isSafeToFetch('not a url'),'malformed rejected');
    }

    // -----------------------------------------------------------------
    // IPv4 — private / loopback / link-local / metadata
    // -----------------------------------------------------------------

    public function testIPv4Loopback(): void
    {
        $this->assertFalse(UrlValidator::isSafeToFetch('http://127.0.0.1/'));
        $this->assertFalse(UrlValidator::isSafeToFetch('http://127.1.2.3/'));
    }

    public function testIPv4PrivateRanges(): void
    {
        $this->assertFalse(UrlValidator::isSafeToFetch('http://10.0.0.1/'),       'RFC1918 10.x');
        $this->assertFalse(UrlValidator::isSafeToFetch('http://172.16.0.1/'),     'RFC1918 172.16');
        $this->assertFalse(UrlValidator::isSafeToFetch('http://172.31.255.254/'), 'RFC1918 172.31');
        $this->assertTrue(UrlValidator::isSafeToFetch('http://172.32.0.1/'),      '172.32 is public');
        $this->assertFalse(UrlValidator::isSafeToFetch('http://192.168.1.1/'),    'RFC1918 192.168');
    }

    public function testIPv4SpecialRanges(): void
    {
        $this->assertFalse(UrlValidator::isSafeToFetch('http://169.254.169.254/'), 'AWS metadata');
        $this->assertFalse(UrlValidator::isSafeToFetch('http://169.254.1.1/'),     'link-local');
        $this->assertFalse(UrlValidator::isSafeToFetch('http://0.0.0.0/'));
        $this->assertFalse(UrlValidator::isSafeToFetch('http://255.255.255.255/'), 'broadcast');
        $this->assertFalse(UrlValidator::isSafeToFetch('http://224.0.0.1/'),       'multicast');
        $this->assertFalse(UrlValidator::isSafeToFetch('http://100.64.0.1/'),      'CGN 100.64');
    }

    public function testIPv4PublicAllowed(): void
    {
        // Google DNS and Cloudflare DNS — stable public IPs.
        $this->assertTrue(UrlValidator::isSafeToFetch('http://8.8.8.8/'));
        $this->assertTrue(UrlValidator::isSafeToFetch('http://1.1.1.1/'));
    }

    // -----------------------------------------------------------------
    // IPv6 literals
    // -----------------------------------------------------------------

    public function testIPv6Reserved(): void
    {
        $this->assertFalse(UrlValidator::isSafeToFetch('http://[::1]/'),                'loopback ::1');
        $this->assertFalse(UrlValidator::isSafeToFetch('http://[fc00::1]/'),            'ULA fc00::/7');
        $this->assertFalse(UrlValidator::isSafeToFetch('http://[fe80::1]/'),            'link-local fe80::/10');
        $this->assertFalse(UrlValidator::isSafeToFetch('http://[::ffff:127.0.0.1]/'),   'IPv4-mapped loopback');
        $this->assertFalse(UrlValidator::isSafeToFetch('http://[::ffff:192.168.1.1]/'), 'IPv4-mapped private');
        $this->assertFalse(UrlValidator::isSafeToFetch('http://[ff00::1]/'),            'multicast');
        $this->assertFalse(UrlValidator::isSafeToFetch('http://[::]/'),                 'unspecified ::');
    }

    public function testIPv6PublicAllowed(): void
    {
        $this->assertTrue(UrlValidator::isSafeToFetch('http://[2001:4860:4860::8888]/'));
    }

    // -----------------------------------------------------------------
    // URL structure
    // -----------------------------------------------------------------

    public function testUrlStructure(): void
    {
        $this->assertFalse(UrlValidator::isSafeToFetch('http://user:pass@8.8.8.8/'), 'credentials in URL');
        $this->assertFalse(UrlValidator::isSafeToFetch('//example.com/path'),        'schemeless URL');
    }

    // -----------------------------------------------------------------
    // validate() returns structured reasons
    // -----------------------------------------------------------------

    public function testValidateReasonForBadScheme(): void
    {
        $r = UrlValidator::validate('file:///etc/passwd');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('scheme', $r['reason']);
    }

    public function testValidateReasonForLoopback(): void
    {
        $r = UrlValidator::validate('http://127.0.0.1/');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('127.0.0.1', $r['reason']);
    }

    public function testValidateOkIncludesIps(): void
    {
        $r = UrlValidator::validate('http://8.8.8.8/');
        $this->assertTrue($r['ok']);
        $this->assertContains('8.8.8.8', $r['ips'] ?? []);
    }

    // -----------------------------------------------------------------
    // isBlockedIp direct calls
    // -----------------------------------------------------------------

    public function testIsBlockedIp(): void
    {
        $this->assertTrue(UrlValidator::isBlockedIp('127.0.0.1'));
        $this->assertTrue(UrlValidator::isBlockedIp('192.168.0.1'));
        $this->assertTrue(UrlValidator::isBlockedIp('169.254.169.254'),                'AWS metadata');
        $this->assertFalse(UrlValidator::isBlockedIp('8.8.8.8'));
        $this->assertFalse(UrlValidator::isBlockedIp('1.2.3.4'));
        $this->assertTrue(UrlValidator::isBlockedIp('::1'));
        $this->assertFalse(UrlValidator::isBlockedIp('2001:4860:4860::8888'),          'public IPv6');
        $this->assertTrue(UrlValidator::isBlockedIp('not-an-ip'),                      'fail-safe on garbage');
    }
}
