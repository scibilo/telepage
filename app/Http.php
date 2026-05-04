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
     * FTS5 uses a mini query language where double-quotes delimit phrases,
     * asterisks are prefix operators, and bare terms are ANDed together.
     * To match the old LIKE '%q%' semantics as closely as possible we wrap
     * the entire input in double-quotes (phrase search), escaping any
     * embedded double-quotes by doubling them (the FTS5 spec for quoted
     * strings). This means:
     *
     *   "deep learning" → finds rows containing the phrase "deep learning"
     *   "PHP"           → finds rows containing the word "PHP"
     *
     * One difference from LIKE: LIKE '%q%' matches substrings within words
     * (e.g. LIKE '%lear%' matches "learning"), while FTS5 phrase search
     * matches whole tokens. Tokenisation splits on whitespace and
     * punctuation, so "learn" matches "learning" via the stemmer only if
     * the unicode61 tokenizer is configured with stemming — which we do
     * not configure here. For Telepage's typical content (URLs, titles,
     * descriptions) whole-word matching is the right default: searching
     * "PHP" should not match "PHPBB" or mid-word fragments.
     */
    function fts5EscapeQuery(string $query): string
    {
        // Escape embedded double-quotes by doubling them.
        $escaped = str_replace('"', '""', $query);
        return '"' . $escaped . '"';
    }
}
