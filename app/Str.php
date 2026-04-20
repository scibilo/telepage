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
     * Returns a sanitized hostname (+ optional port) from the client-provided
     * input. Rejects anything that isn't a plain host[:port] and returns the
     * fallback instead.
     *
     * Valid:    "example.com", "sub.example.com:8080", "localhost", "127.0.0.1"
     * Rejected: "evil.com/path", "evil.com?x=1", "evil.com\r\nSet-Cookie: x",
     *           "javascript:alert(1)", "evil.com:80:81", trailing whitespace,
     *           whatever else a crafted Host: header could smuggle.
     *
     * Important: this is a SYNTACTIC check. It does NOT verify that the host
     * is one of the server's canonical names; that would require an explicit
     * allowed_hosts list in config. What it prevents is injection of control
     * characters and URL components that could cascade into log lines,
     * webhook URLs, or redirect targets.
     */
    public static function safeHost(?string $value, string $fallback = 'localhost'): string
    {
        if ($value === null) {
            return $fallback;
        }
        $v = trim($value);
        if ($v === '') {
            return $fallback;
        }
        // Hostname + optional port, nothing else. The hostname itself is a
        // pragmatic superset of RFC 952/1123: letters, digits, dots, dashes,
        // plus colons and square brackets for IPv6 literals like "[::1]:443".
        // Length capped defensively; browsers cap Host around 253 chars.
        if (strlen($v) > 253) {
            return $fallback;
        }
        if (!preg_match('/^(?:\[[0-9a-fA-F:]+\]|[a-zA-Z0-9.\-]+)(?::\d{1,5})?$/', $v)) {
            return $fallback;
        }
        return $v;
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
