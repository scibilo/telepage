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

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} test(s)\n";
    exit(1);
}
echo "All tests passed\n";
exit(0);
