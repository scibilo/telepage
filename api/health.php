<?php

/**
 * TELEPAGE — api/health.php
 *
 * Public health-check endpoint for external uptime monitoring tools
 * (UptimeRobot, healthchecks.io, Better Uptime, internal Nagios, etc).
 *
 * GET /api/health.php
 *
 * Status codes:
 *   200 — all checks ok or degraded (the install can still serve the
 *         public feed; some non-critical subsystem flagged a warning)
 *   503 — at least one CRITICAL check failed (DB unreachable, install
 *         not complete). The site likely cannot serve content.
 *
 * Response body (always JSON):
 *
 *   {
 *     "status": "ok" | "degraded" | "error",
 *     "checks": {
 *       "db":        "ok" | "error",
 *       "installed": "ok" | "error",
 *       "webhook":   "configured" | "missing",
 *       "ai_queue":  "ok" | "backlog"
 *     }
 *   }
 *
 * No internal details are exposed: no version strings, no filesystem
 * paths, no counts (which would let a probe fingerprint the site
 * size or growth rate), no last-error messages. Each check is a
 * boolean-ish enum.
 *
 * Rate-limited at 30 req/min per IP via the same checkPublicRateLimit()
 * shared with api/contents.php — a determined attacker could otherwise
 * poll this endpoint to track AI cron health and infer site activity
 * patterns. 30/min is generous for any real uptime tool (most poll
 * once per minute or less).
 */

declare(strict_types=1);

define('TELEPAGE_ROOT', dirname(__DIR__));

require_once TELEPAGE_ROOT . '/vendor/autoload.php';

Bootstrap::init(Bootstrap::MODE_JSON);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
// Health endpoint is read-only — explicitly NOT exposing CORS for
// browser callers. Server-to-server uptime tools don't care.
header('X-Robots-Tag: noindex, nofollow');

// Rate limit. Reuse the existing public limiter at half the rate.
$ip = clientIp();
if (!checkPublicRateLimit($ip, 30)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'checks' => ['rate_limit' => 'exceeded']]);
    exit;
}

// Backlog threshold for the AI queue. Above this, mark the queue as
// backlog (degraded) — typically means the cron job has stopped
// firing. Picked to be well above any normal end-of-day batch size.
const AI_QUEUE_BACKLOG_THRESHOLD = 100;

$checks  = [];
$overall = 'ok';

// -----------------------------------------------------------------------
// Check 1 — DB reachable. SELECT 1 is portable across SQLite versions
// and doesn't depend on schema state.
// -----------------------------------------------------------------------
try {
    $dbPing = DB::fetchScalar('SELECT 1');
    $checks['db'] = ($dbPing == 1) ? 'ok' : 'error';
    if ($checks['db'] === 'error') $overall = 'error';
} catch (Throwable $e) {
    $checks['db'] = 'error';
    $overall = 'error';
}

// -----------------------------------------------------------------------
// Check 2 — Install completed. Without this, the public feed and
// admin both refuse to serve, so it's a critical signal.
// -----------------------------------------------------------------------
$checks['installed'] = Config::isInstalled() ? 'ok' : 'error';
if ($checks['installed'] === 'error') $overall = 'error';

// -----------------------------------------------------------------------
// Check 3 — Webhook configured. Not critical (the public feed works
// without it), but a freshly installed site that never finished the
// Telegram setup will show 'missing' here. Degraded, not error.
// -----------------------------------------------------------------------
$config = Config::get();
$checks['webhook'] = !empty($config['webhook_secret']) ? 'configured' : 'missing';
if ($checks['webhook'] === 'missing' && $overall === 'ok') $overall = 'degraded';

// -----------------------------------------------------------------------
// Check 4 — AI queue depth. If the cron job has stopped, contents
// pile up with ai_processed=0 indefinitely. Crossing the threshold
// is a degraded signal — the site still serves, just without fresh
// AI summaries. Skipped entirely if AI is disabled in config.
// -----------------------------------------------------------------------
if (!empty($config['ai_enabled'])) {
    try {
        $pending = (int) DB::fetchScalar(
            'SELECT COUNT(*) FROM contents WHERE ai_processed = 0 AND is_deleted = 0'
        );
        $checks['ai_queue'] = ($pending > AI_QUEUE_BACKLOG_THRESHOLD) ? 'backlog' : 'ok';
        if ($checks['ai_queue'] === 'backlog' && $overall === 'ok') $overall = 'degraded';
    } catch (Throwable $e) {
        // If the DB itself broke we already flagged 'db: error'; the
        // ai_queue check is informational only, so a swallowed
        // exception here keeps the response shape predictable.
        $checks['ai_queue'] = 'error';
    }
} else {
    $checks['ai_queue'] = 'disabled';
}

// -----------------------------------------------------------------------
// Response
// -----------------------------------------------------------------------
http_response_code($overall === 'error' ? 503 : 200);
echo json_encode([
    'status' => $overall,
    'checks' => $checks,
]);
