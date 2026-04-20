<?php
/**
 * TELEPAGE — tests/StrTest.php
 * Dependency-free tests for Str::slugify().
 * Run: php tests/StrTest.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/Str.php';

$failures = 0;
function assertEq(string $actual, string $expected, string $label): void {
    global $failures;
    if ($actual === $expected) { echo "  ✓ {$label}\n"; }
    else { echo "  ✗ {$label}: expected '{$expected}', got '{$actual}'\n"; $failures++; }
}

echo "Str::slugify tests\n";

echo "\n[basic]\n";
assertEq(Str::slugify('Hello World'),          'hello-world',     'spaces → dash');
assertEq(Str::slugify('Deep Learning'),        'deep-learning',   'title case → lower + dash');
assertEq(Str::slugify('already-a-slug'),       'already-a-slug',  'already clean');
assertEq(Str::slugify('ALL CAPS'),             'all-caps',        'uppercase → lower');

echo "\n[underscore preserved]\n";
assertEq(Str::slugify('deep_learning'),        'deep_learning',   'underscore preserved');
assertEq(Str::slugify('machine_learning_101'), 'machine_learning_101', 'mixed underscores+digits');

echo "\n[collapse]\n";
assertEq(Str::slugify('hello  world'),         'hello-world',     'double space collapses');
assertEq(Str::slugify('hello---world'),        'hello-world',     'multi-dash collapses');
assertEq(Str::slugify('a!!!b'),                'a-b',             'consecutive punct collapses');
assertEq(Str::slugify('  leading  trailing  '),'leading-trailing','leading/trailing space trimmed');

echo "\n[hash and symbols]\n";
assertEq(Str::slugify('#hashtag'),             'hashtag',         'hash stripped');
assertEq(Str::slugify('#deep_learning'),       'deep_learning',   'hash + underscore tag');
assertEq(Str::slugify('@mention'),             'mention',         'at stripped');
assertEq(Str::slugify('a&b'),                  'a-b',             'ampersand → dash');
assertEq(Str::slugify('50%'),                  '50',              'percent stripped');

echo "\n[diacritics (transliterate)]\n";
assertEq(Str::slugify('caffè'),                'caffe',           'grave accent');
assertEq(Str::slugify('perché'),               'perche',          'acute accent');
assertEq(Str::slugify('più'),                  'piu',             'italian più');
assertEq(Str::slugify('naïve'),                'naive',           'diaeresis');
assertEq(Str::slugify('résumé'),               'resume',          'french acute');
assertEq(Str::slugify('Ümlaut'),               'umlaut',          'german umlaut uppercase');
assertEq(Str::slugify('Straße'),               'strasse',         'german eszett');

echo "\n[edge cases]\n";
assertEq(Str::slugify(''),                     '',                'empty stays empty');
assertEq(Str::slugify('!!!'),                  '',                'pure punctuation collapses to empty');
assertEq(Str::slugify('---'),                  '',                'pure dashes collapse to empty');
assertEq(Str::slugify('a'),                    'a',               'single char');
assertEq(Str::slugify('-a-'),                  'a',               'dash-wrapped');
assertEq(Str::slugify('_a_'),                  '_a_',             'underscore-wrapped (preserved)');

echo "\n[numeric]\n";
assertEq(Str::slugify('2024'),                 '2024',            'pure digits');
assertEq(Str::slugify('covid-19'),             'covid-19',        'word-number');
assertEq(Str::slugify('v2.0'),                 'v2-0',            'dot → dash');

echo "\n[safeHexColor]\n";
assertEq(Str::safeHexColor('#3b82f6'),                         '#3b82f6', 'valid 6-digit');
assertEq(Str::safeHexColor('#FFF'),                            '#fff',    '3-digit normalized to lowercase');
assertEq(Str::safeHexColor('#ABC123'),                         '#abc123', 'mixed case normalized');
assertEq(Str::safeHexColor('  #000000  '),                     '#000000', 'trimmed');
assertEq(Str::safeHexColor('red'),                             '#3b82f6', 'named color rejected (default)');
assertEq(Str::safeHexColor('#12345'),                          '#3b82f6', '5-digit rejected');
assertEq(Str::safeHexColor('#1234567'),                        '#3b82f6', '7-digit rejected');
assertEq(Str::safeHexColor(''),                                '#3b82f6', 'empty rejected');
assertEq(Str::safeHexColor(null),                              '#3b82f6', 'null rejected');
assertEq(Str::safeHexColor('rgb(255,0,0)'),                    '#3b82f6', 'rgb() rejected');
// The whole point of this validator: XSS payloads are rejected before
// they ever reach config.json or the HTML renderer.
assertEq(Str::safeHexColor('#fff; background: url(javascript:alert(1))'), '#3b82f6', 'CSS injection rejected');
assertEq(Str::safeHexColor('</style><script>alert(1)</script>'),          '#3b82f6', 'HTML break-out rejected');
assertEq(Str::safeHexColor('#abc', '#000'),                    '#abc',    'valid uses given default');
assertEq(Str::safeHexColor('garbage', '#000'),                 '#000',    'invalid uses given default');

echo "\n[clampDisplayName]\n";
assertEq(Str::clampDisplayName('Telepage'),                   'Telepage',           'short pass-through');
assertEq(Str::clampDisplayName('   spaced   '),               'spaced',             'trimmed');
assertEq(Str::clampDisplayName(''),                           'Telepage',           'empty uses default');
assertEq(Str::clampDisplayName(null),                         'Telepage',           'null uses default');
assertEq(Str::clampDisplayName(str_repeat('x', 200), 10),     'xxxxxxxxxx',         'clamped to maxLen');
assertEq(Str::clampDisplayName("name\x00with\x01controls"),   'namewithcontrols',   'control chars stripped');
// clampDisplayName does NOT escape HTML — the caller is responsible.
// It just prevents storing DoS-sized names or raw control chars.
assertEq(Str::clampDisplayName('<b>bold</b>'),                '<b>bold</b>',        'HTML left intact (escaped at render time)');
assertEq(Str::clampDisplayName('Caffè & Cornetti'),           'Caffè & Cornetti',   'unicode + ampersand preserved');

echo "\n[safeHost]\n";
assertEq(Str::safeHost('example.com'),                 'example.com',         'plain hostname');
assertEq(Str::safeHost('sub.example.com'),             'sub.example.com',     'subdomain');
assertEq(Str::safeHost('example.com:8080'),            'example.com:8080',    'hostname with port');
assertEq(Str::safeHost('localhost'),                   'localhost',           'localhost');
assertEq(Str::safeHost('127.0.0.1'),                   '127.0.0.1',           'ipv4');
assertEq(Str::safeHost('127.0.0.1:443'),               '127.0.0.1:443',       'ipv4 with port');
assertEq(Str::safeHost('[::1]'),                       '[::1]',               'ipv6 literal');
assertEq(Str::safeHost('[::1]:443'),                   '[::1]:443',           'ipv6 literal with port');
assertEq(Str::safeHost('Example.COM'),                 'Example.COM',         'case preserved (servers compare case-insensitively)');
// Rejections — these are the whole point: block Host-header injection.
assertEq(Str::safeHost('evil.com/path'),               'localhost',           'path rejected');
assertEq(Str::safeHost('evil.com?x=1'),                'localhost',           'query rejected');
assertEq(Str::safeHost('evil.com#frag'),               'localhost',           'fragment rejected');
assertEq(Str::safeHost("evil.com\r\nSet-Cookie: x"),   'localhost',           'CRLF injection rejected');
assertEq(Str::safeHost('evil.com:80:81'),              'localhost',           'double port rejected');
assertEq(Str::safeHost('evil com'),                    'localhost',           'space rejected');
assertEq(Str::safeHost('javascript:alert(1)'),         'localhost',           'scheme prefix rejected');
assertEq(Str::safeHost('   '),                         'localhost',           'whitespace-only uses fallback');
assertEq(Str::safeHost(''),                            'localhost',           'empty uses fallback');
assertEq(Str::safeHost(null),                          'localhost',           'null uses fallback');
assertEq(Str::safeHost(str_repeat('a', 300)),          'localhost',           'overlong rejected');
assertEq(Str::safeHost('valid.com', 'back.up'),        'valid.com',           'valid passes through with custom fallback');
assertEq(Str::safeHost('bad!!host', 'back.up'),        'back.up',             'invalid uses custom fallback');

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} test(s)\n";
    exit(1);
}
echo "All tests passed\n";
exit(0);
