<?php
/**
 * TELEPAGE — app/Str.php
 * Shared string utilities.
 *
 * Goal: replace 4 divergent slugify implementations (AIService,
 * TelegramBot, HistoryScanner inline, admin/tags.php JS) with one
 * canonical version. Previously the same hashtag could produce
 * different slugs depending on which code path scanned it, creating
 * duplicate rows in the `tags` table and silently mis-tagging posts.
 *
 * Policy:
 *   1. lowercase (mb_strtolower for unicode safety)
 *   2. transliterate diacritics → ASCII (caffè → caffe, perché → perche)
 *   3. collapse any run of chars outside [a-z0-9_-] into a single '-'
 *   4. collapse consecutive dashes into a single '-'
 *   5. trim leading/trailing '-'
 *
 * Examples:
 *   "Deep Learning"    → "deep-learning"
 *   "#deep_learning"   → "deep_learning"      (underscores preserved)
 *   "hello  world"     → "hello-world"
 *   "caffè"            → "caffe"
 *   "perché"           → "perche"
 *   "più & più"        → "piu-piu"
 *   "!!!"              → ""                   (caller must handle empty)
 */

declare(strict_types=1);

class Str
{
    /**
     * Canonical slugification. Returns an empty string if the input
     * collapses to nothing (all punctuation, whitespace, etc.) — callers
     * MUST check for empty before using the result as a DB key.
     */
    public static function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = self::transliterate($text);
        // Replace any char that isn't [a-z0-9_-] with a single dash.
        $text = preg_replace('/[^a-z0-9_\-]+/', '-', $text) ?? $text;
        // Collapse runs of dashes.
        $text = preg_replace('/-+/', '-', $text) ?? $text;
        return trim($text, '-');
    }

    /**
     * Strips diacritics by converting to ASCII through iconv.
     * Falls back to the original string if iconv is not available or
     * the locale can't do the transliteration — the regex in slugify()
     * will then just drop the unknown chars.
     */
    private static function transliterate(string $text): string
    {
        if (!function_exists('iconv')) {
            return $text;
        }

        // iconv's TRANSLIT behaviour depends on the current locale.
        // Force a UTF-8 locale so 'café' → 'cafe' instead of '?'.
        $oldLocale = setlocale(LC_CTYPE, '0');
        setlocale(LC_CTYPE, 'en_US.UTF-8', 'C.UTF-8');

        $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        if ($oldLocale !== false) {
            setlocale(LC_CTYPE, $oldLocale);
        }

        return ($out !== false && $out !== '') ? $out : $text;
    }
}
