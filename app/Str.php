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
     * Validates a hex color string like '#rgb' or '#rrggbb'. Returns the
     * normalized lowercase value, or the provided default if invalid.
     *
     * This is used both at save time (to reject malicious input before
     * it reaches config.json) AND at render time (to guard against
     * any pre-existing bad value ever reaching an HTML/CSS attribute).
     */
    public static function safeHexColor(?string $value, string $default = '#3b82f6'): string
    {
        if ($value === null) {
            return $default;
        }
        $v = strtolower(trim($value));
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $v)) {
            return $v;
        }
        return $default;
    }

    /**
     * Clamps a user-provided display name to a sensible length. Does
     * NOT escape HTML — callers must still run htmlspecialchars() at
     * output time.
     */
    public static function clampDisplayName(?string $value, int $maxLen = 128, string $default = 'Telepage'): string
    {
        if ($value === null) {
            return $default;
        }
        $v = trim($value);
        if ($v === '') {
            return $default;
        }
        // Strip control characters that could break HTML attributes even
        // through htmlspecialchars (e.g. NUL bytes).
        $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? $v;
        if ($v === '') {
            return $default;
        }
        return mb_substr($v, 0, $maxLen, 'UTF-8');
    }

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
