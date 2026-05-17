<?php

/**
 * TELEPAGE — app/Http.php
 *
 * Helpers shared between unauthenticated HTTP endpoints (api/contents.php,
 * api/health.php). Extracted from api/contents.php where they originally
 * lived as inline functions; consolidated here so a new public endpoint
 * can simply require this file instead of duplicating the rate-limiter
 * or copy-pasting the IP detection logic.
 */

declare(strict_types=1);

if (!function_exists('checkPublicRateLimit')) {

    /**
     * Sliding-minute rate limit for an unauthenticated endpoint.
     *
     * @param string $ip       The client IP — typically clientIp().
     * @param int    $maxHits  Limit per 60s window. Default 60 matches
     *                         the historic api/contents.php behaviour.
     * @param string $endpoint Bucket key in the rate_limits table.
     *                         Use a distinct value per endpoint so the
     *                         feed and the healthcheck don't share a
     *                         counter (otherwise an uptime tool polling
     *                         once a minute would slowly burn the
     *                         feed's quota).
     */
    function checkPublicRateLimit(string $ip, int $maxHits = 60, string $endpoint = 'public_api'): bool
    {
        try {
            $rec = DB::fetchOne(
                'SELECT hit_count, window_start FROM rate_limits WHERE ip=:ip AND endpoint=:ep',
                [':ip' => $ip, ':ep' => $endpoint]
            );

            $now = time();

            if ($rec) {
                $age = $now - (int) $rec['window_start'];
                if ($age > 60) {
                    DB::query(
                        'UPDATE rate_limits SET hit_count=1, window_start=:now WHERE ip=:ip AND endpoint=:ep',
                        [':now' => $now, ':ip' => $ip, ':ep' => $endpoint]
                    );
                    return true;
                }
                if ((int) $rec['hit_count'] >= $maxHits) {
                    return false;
                }
                DB::query(
                    'UPDATE rate_limits SET hit_count=hit_count+1 WHERE ip=:ip AND endpoint=:ep',
                    [':ip' => $ip, ':ep' => $endpoint]
                );
            } else {
                DB::query(
                    'INSERT INTO rate_limits (ip, endpoint, hit_count, window_start) VALUES (:ip,:ep,1,:now)',
                    [':ip' => $ip, ':ep' => $endpoint, ':now' => $now]
                );
            }

            return true;
        } catch (Throwable) {
            // Fail open — never block legitimate traffic because the
            // rate-limit bookkeeping itself errored. Worst case is a
            // request that should have been blocked goes through; the
            // alternative (failing closed on a bookkeeping glitch)
            // would take the site offline.
            return true;
        }
    }
}

if (!function_exists('clientIp')) {

    /**
     * Best-effort client IP detection. Honours the standard reverse-
     * proxy headers (Cloudflare, generic X-Forwarded-For, X-Real-IP)
     * before falling back to REMOTE_ADDR. Each candidate is validated
     * with FILTER_VALIDATE_IP so a forged header full of garbage
     * doesn't poison the rate-limit table key.
     */
    function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
            $v = $_SERVER[$h] ?? '';
            if ($v) {
                $ip = trim(explode(',', $v)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}

if (!function_exists('fts5EscapeQuery')) {

    /**
     * Escapes a user-supplied string for use as an FTS5 MATCH query.
     *
     * Strategy: split the input into individual tokens and AND them
     * together. Each token is wrapped in double-quotes (FTS5 quoted-term
     * syntax) so special characters (*, ", ^, etc.) are treated as
     * literals rather than FTS5 operators.
     *
     * The LAST token gets a trailing * (prefix search) so that partial
     * words typed by the user match while they are still typing:
     *   "chatg"  → "chatg*"   matches "chatgpt", "chatgpt4", etc.
     *   "deep l" → "deep" "l*" matches "deep learning", "deep logic", etc.
     *
     * Completed tokens (all but the last) are exact matches so earlier
     * words don't expand unexpectedly.
     *
     * Examples:
     *   "intelligenza"        → "intelligenza*"
     *   "deep learning"       → "deep" "learning*"
     *   "l'IA"                → "l" "IA*"
     *   "chatg"               → "chatg*"   (finds chatgpt)
     *
     * Empty tokens (from multiple spaces, punctuation runs) are dropped.
     */
    function fts5EscapeQuery(string $query): string
    {
        // Strip control characters that could confuse the tokenizer.
        $query = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $query) ?? $query;

        // Split on whitespace and common punctuation used as word separators
        // in Italian/English content (apostrophe, dash, slash, etc.).
        $tokens = preg_split('/[\s\'\-\/\\\\]+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($tokens)) {
            return '';
        }

        $parts = [];
        $last  = count($tokens) - 1;

        foreach ($tokens as $i => $token) {
            if ($token === '') continue;
            $escaped = str_replace('"', '""', $token);
            // Add prefix wildcard to the last token for live-search UX.
            // FTS5 prefix search: "tok*" matches any token starting with "tok".
            // Note: the * must be OUTSIDE the quotes in FTS5 syntax.
            if ($i === $last) {
                $parts[] = '"' . $escaped . '"*';
            } else {
                $parts[] = '"' . $escaped . '"';
            }
        }

        return implode(' ', $parts);
    }
}

if (!function_exists('detectBaseUrl')) {

    /**
     * Detects the base URL of the Telepage installation.
     *
     * Works from any entry-point (index.php, api/webhook.php,
     * api/admin.php, etc.) by using TELEPAGE_ROOT as the anchor
     * instead of __FILE__, making it context-independent.
     *
     * Returns a URL without trailing slash, e.g.:
     *   https://example.com/telepage
     *   https://example.com
     */
    function detectBaseUrl(): string
    {
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'     ||
            ($_SERVER['HTTP_X_FORWARDED_SSL']   ?? '') === 'on'        ||
            ($_SERVER['HTTP_X_FORWARDED_PORT']  ?? '') === '443'
        );

        $scheme = $isHttps ? 'https' : 'http';

        $serverName = $_SERVER['SERVER_NAME'] ?? '';
        $httpHost   = $_SERVER['HTTP_HOST']   ?? '';
        $host = ($serverName !== '' && $serverName !== '_' && $serverName !== 'default')
            ? Str::safeHost($serverName, 'localhost')
            : Str::safeHost($httpHost, 'localhost');

        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        $appRoot = rtrim(defined('TELEPAGE_ROOT') ? TELEPAGE_ROOT : dirname(__DIR__), '/');

        if ($docRoot !== '' && str_starts_with($appRoot, $docRoot)) {
            $webPath = substr($appRoot, strlen($docRoot));
        } else {
            $script  = $_SERVER['SCRIPT_NAME'] ?? '';
            $webPath = rtrim(dirname($script), '/');
            // If called from a subdirectory (e.g. api/webhook.php), go up
            // to the installation root by counting path components relative
            // to TELEPAGE_ROOT.
            if (defined('TELEPAGE_ROOT')) {
                $depth = substr_count(
                    str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: ''),
                    '/',
                ) - substr_count(
                    str_replace('\\', '/', $appRoot),
                    '/'
                );
                // Simpler fallback: use dirname() on SCRIPT_NAME $depth-1 times
                for ($i = 1; $i < $depth; $i++) {
                    $webPath = rtrim(dirname($webPath), '/');
                }
            }
        }

        return $scheme . '://' . $host . $webPath;
    }
}
