<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Str;

/**
 * Tests for the Str helper class.
 *
 * Migrated from the dependency-free script at tests/StrTest.php (kept
 * historically until C2 — this is C2). Method names group assertions
 * by feature so a failure points straight at the affected behaviour
 * area instead of giving you 76 line numbers and a cliff to climb.
 */
final class StrTest extends TestCase
{
    // -----------------------------------------------------------------
    // Str::slugify
    // -----------------------------------------------------------------

    public function testSlugifyBasic(): void
    {
        $this->assertSame('hello-world',    Str::slugify('Hello World'));
        $this->assertSame('deep-learning',  Str::slugify('Deep Learning'));
        $this->assertSame('already-a-slug', Str::slugify('already-a-slug'));
        $this->assertSame('all-caps',       Str::slugify('ALL CAPS'));
    }

    public function testSlugifyUnderscorePreserved(): void
    {
        $this->assertSame('deep_learning',         Str::slugify('deep_learning'));
        $this->assertSame('machine_learning_101', Str::slugify('machine_learning_101'));
    }

    public function testSlugifyCollapse(): void
    {
        $this->assertSame('hello-world',         Str::slugify('hello  world'));
        $this->assertSame('hello-world',         Str::slugify('hello---world'));
        $this->assertSame('a-b',                 Str::slugify('a!!!b'));
        $this->assertSame('leading-trailing',    Str::slugify('  leading  trailing  '));
    }

    public function testSlugifyHashAndSymbols(): void
    {
        $this->assertSame('hashtag',       Str::slugify('#hashtag'));
        $this->assertSame('deep_learning', Str::slugify('#deep_learning'));
        $this->assertSame('mention',       Str::slugify('@mention'));
        $this->assertSame('a-b',           Str::slugify('a&b'));
        $this->assertSame('50',            Str::slugify('50%'));
    }

    public function testSlugifyDiacritics(): void
    {
        $this->assertSame('caffe',   Str::slugify('caffè'));
        $this->assertSame('perche',  Str::slugify('perché'));
        $this->assertSame('piu',     Str::slugify('più'));
        $this->assertSame('naive',   Str::slugify('naïve'));
        $this->assertSame('resume',  Str::slugify('résumé'));
        $this->assertSame('umlaut',  Str::slugify('Ümlaut'));
        $this->assertSame('strasse', Str::slugify('Straße'));
    }

    public function testSlugifyEdgeCases(): void
    {
        $this->assertSame('',     Str::slugify(''),    'empty stays empty');
        $this->assertSame('',     Str::slugify('!!!'), 'pure punctuation collapses to empty');
        $this->assertSame('',     Str::slugify('---'), 'pure dashes collapse to empty');
        $this->assertSame('a',    Str::slugify('a'));
        $this->assertSame('a',    Str::slugify('-a-'));
        $this->assertSame('_a_',  Str::slugify('_a_'),  'underscore-wrapped is preserved');
    }

    public function testSlugifyNumeric(): void
    {
        $this->assertSame('2024',     Str::slugify('2024'));
        $this->assertSame('covid-19', Str::slugify('covid-19'));
        $this->assertSame('v2-0',     Str::slugify('v2.0'));
    }

    // -----------------------------------------------------------------
    // Str::safeHexColor
    // -----------------------------------------------------------------

    public function testSafeHexColorValid(): void
    {
        $this->assertSame('#abc',     Str::safeHexColor('#abc'));
        $this->assertSame('#abc',     Str::safeHexColor('#ABC'));   
        $this->assertSame('#a1b2c3',  Str::safeHexColor('#a1b2c3'));
        $this->assertSame('#ffffff',  Str::safeHexColor('#FFFFFF'));
        $this->assertSame('#000000',  Str::safeHexColor('  #000000  '), 'trimmed');
    }

    public function testSafeHexColorRejected(): void
    {
        $default = '#3b82f6';
        $this->assertSame($default, Str::safeHexColor('red'),         'named color rejected');
        $this->assertSame($default, Str::safeHexColor('#12345'),      '5-digit rejected');
        $this->assertSame($default, Str::safeHexColor('#1234567'),    '7-digit rejected');
        $this->assertSame($default, Str::safeHexColor(''),            'empty rejected');
        $this->assertSame($default, Str::safeHexColor(null),          'null rejected');
        $this->assertSame($default, Str::safeHexColor('rgb(255,0,0)'),'rgb() rejected');
    }

    public function testSafeHexColorXssPayloads(): void
    {
        // The whole point of this validator: XSS payloads are rejected
        // before they ever reach config.json or the HTML renderer.
        $default = '#3b82f6';
        $this->assertSame($default, Str::safeHexColor('#fff; background: url(javascript:alert(1))'));
        $this->assertSame($default, Str::safeHexColor('</style><script>alert(1)</script>'));
    }

    public function testSafeHexColorCustomDefault(): void
    {
        $this->assertSame('#abc', Str::safeHexColor('#abc',    '#000'), 'valid uses given default');
        $this->assertSame('#000', Str::safeHexColor('garbage', '#000'), 'invalid uses given default');
    }

    // -----------------------------------------------------------------
    // Str::clampDisplayName
    // -----------------------------------------------------------------

    public function testClampDisplayName(): void
    {
        $this->assertSame('Telepage',           Str::clampDisplayName('Telepage'));
        $this->assertSame('spaced',             Str::clampDisplayName('   spaced   '));
        $this->assertSame('Telepage',           Str::clampDisplayName(''),   'empty uses default');
        $this->assertSame('Telepage',           Str::clampDisplayName(null), 'null uses default');
        $this->assertSame('xxxxxxxxxx',         Str::clampDisplayName(str_repeat('x', 200), 10), 'clamped to maxLen');
        $this->assertSame('namewithcontrols',   Str::clampDisplayName("name\x00with\x01controls"));
        // clampDisplayName does NOT escape HTML — the caller (renderer) is responsible.
        $this->assertSame('<b>bold</b>',        Str::clampDisplayName('<b>bold</b>'));
        $this->assertSame('Caffè & Cornetti',   Str::clampDisplayName('Caffè & Cornetti'));
    }

    // -----------------------------------------------------------------
    // Str::safeHost
    // -----------------------------------------------------------------

    public function testSafeHostAccepts(): void
    {
        $this->assertSame('example.com',         Str::safeHost('example.com'));
        $this->assertSame('sub.example.com',     Str::safeHost('sub.example.com'));
        $this->assertSame('example.com:8080',    Str::safeHost('example.com:8080'));
        $this->assertSame('localhost',           Str::safeHost('localhost'));
        $this->assertSame('127.0.0.1',           Str::safeHost('127.0.0.1'));
        $this->assertSame('127.0.0.1:443',       Str::safeHost('127.0.0.1:443'));
        $this->assertSame('[::1]',               Str::safeHost('[::1]'));
        $this->assertSame('[::1]:443',           Str::safeHost('[::1]:443'));
        $this->assertSame('Example.COM',         Str::safeHost('Example.COM'),
            'case preserved (servers compare case-insensitively)');
    }

    public function testSafeHostRejects(): void
    {
        // The whole point: block Host-header injection vectors.
        $this->assertSame('localhost', Str::safeHost('evil.com/path'),                'path rejected');
        $this->assertSame('localhost', Str::safeHost('evil.com?x=1'),                 'query rejected');
        $this->assertSame('localhost', Str::safeHost('evil.com#frag'),                'fragment rejected');
        $this->assertSame('localhost', Str::safeHost("evil.com\r\nSet-Cookie: x"),    'CRLF injection rejected');
        $this->assertSame('localhost', Str::safeHost('evil.com:80:81'),               'double port rejected');
        $this->assertSame('localhost', Str::safeHost('evil com'),                     'space rejected');
        $this->assertSame('localhost', Str::safeHost('javascript:alert(1)'),          'scheme prefix rejected');
        $this->assertSame('localhost', Str::safeHost('   '),                          'whitespace-only uses fallback');
        $this->assertSame('localhost', Str::safeHost(''),                             'empty uses fallback');
        $this->assertSame('localhost', Str::safeHost(null),                           'null uses fallback');
        $this->assertSame('localhost', Str::safeHost(str_repeat('a', 300)),           'overlong rejected');
    }

    public function testSafeHostCustomFallback(): void
    {
        $this->assertSame('valid.com', Str::safeHost('valid.com', 'back.up'));
        $this->assertSame('back.up',   Str::safeHost('bad!!host', 'back.up'));
    }
}
