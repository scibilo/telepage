<?php

/**
 * TELEPAGE — api/cron.php
 * Scheduler for background operations (AI Queue).
 *
 * Invocation (in order of preference):
 *
 *   CLI:      php /path/to/api/cron.php key=YOUR_SECRET
 *   HTTP:     curl -H "X-Cron-Key: YOUR_SECRET" https://yoursite/api/cron.php
 *   HTTP old: https://yoursite/api/cron.php?key=YOUR_SECRET   (deprecated)
 *
 * Authentication:
 *   - `cron_secret` (config.json) is the dedicated secret for this endpoint.
 *   - Falls back to `webhook_secret` with a deprecation log if cron_secret
 *     is missing. Existing installations keep working; new installations
 *     (install wizard) will set cron_secret explicitly.
 *
 * Security notes:
 *   - The `?key=...` query-string form is supported for backward
 *     compatibility but logs a deprecation warning on each invocation:
 *     query strings end up in Apache access logs, browser history,
 *     bookmarks, and Referer headers. Prefer the X-Cron-Key header.
 */

declare(strict_types=1);

define('TELEPAGE_ROOT', dirname(__DIR__));

require_once TELEPAGE_ROOT . '/vendor/autoload.php';

Bootstrap::init(Bootstrap::MODE_JSON);

// -----------------------------------------------------------------------
// 1. Security check
// -----------------------------------------------------------------------
$config = Config::get();

// A5: prefer cron_secret, fall back to webhook_secret. Fallback emits
// a one-line deprecation warning so an operator reading the log knows
// to rotate. New installations generate cron_secret at install time.
$secret = $config['cron_secret'] ?? '';
$usingFallback = false;
if ($secret === '') {
    $secret = $config['webhook_secret'] ?? '';
    $usingFallback = ($secret !== '');
}

if ($secret === '') {
    http_response_code(503);
    die("Errore: Nessun secret configurato per il cron. Configura il bot prima di usare il cron.\n");
}

if ($usingFallback) {
    Logger::system(
        Logger::WARNING,
        'Cron using webhook_secret as fallback — set a dedicated cron_secret in config.json',
        []
    );
}

// A4: accept the key from three sources, in priority order:
//   (a) X-Cron-Key header         — preferred, not logged by Apache
//   (b) CLI argument key=...      — preferred for cron(1) jobs
//   (c) ?key=... query string     — deprecated, logs a warning
$receivedKey   = '';
$keyFromQuery  = false;

// Apache turns 'X-Cron-Key' into HTTP_X_CRON_KEY.
if (!empty($_SERVER['HTTP_X_CRON_KEY'])) {
    $receivedKey = $_SERVER['HTTP_X_CRON_KEY'];
} elseif (PHP_SAPI === 'cli') {
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with((string) $arg, 'key=')) {
            $receivedKey = substr($arg, 4);
            break;
        }
    }
} elseif (!empty($_GET['key'])) {
    $receivedKey  = $_GET['key'];
    $keyFromQuery = true;
}

if (!hash_equals($secret, $receivedKey)) {
    http_response_code(403);
    // Do not echo the received value — it may end up in logs if the
    // attacker supplied a valid-looking string from an internal IP.
    Logger::system(Logger::WARNING, 'Cron denied: invalid or missing key', [
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? 'cli',
        'has_key'  => $receivedKey !== '',
    ]);
    die("Accesso negato: Secret key non valida.\n");
}

if ($keyFromQuery) {
    // Key was correct but passed via query string. Warn so the operator
    // moves to the header form. We log once per invocation, not per
    // request — cron fires only every few minutes in practice.
    Logger::system(
        Logger::WARNING,
        'Cron invoked with ?key= query string — prefer X-Cron-Key header (query strings leak into access logs, browser history, and Referer)',
        ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli']
    );
}

// -----------------------------------------------------------------------
// 2. Process AI queue
//
// Overlap protection: two cron invocations running simultaneously
// (e.g. a slow Gemini call causes the next cron tick to fire before
// the previous one finishes) would both see ai_processed=0 rows and
// process the same content twice.
//
// Fix: mark rows as "in progress" atomically with BEGIN IMMEDIATE
// before processing them. BEGIN IMMEDIATE acquires a write lock
// immediately, so a concurrent cron that tries the same UPDATE sees
// the rows already claimed and gets an empty batch.
//
// ai_processing_since stores the timestamp of when processing started.
// If a cron invocation dies mid-run (Gemini timeout, OOM, etc.) the
// row stays at ai_processed=0 with a stale ai_processing_since. A
// 10-minute staleness window resets it so the next cron can retry.
// -----------------------------------------------------------------------

$limit       = 10;
$staleMinutes = 10;
$pdo         = DB::get();

// Reset stale "in progress" rows (claimed but never finished).
$pdo->exec(
    "UPDATE contents
     SET ai_processing_since = NULL
     WHERE ai_processed = 0
       AND is_deleted   = 0
       AND ai_processing_since IS NOT NULL
       AND ai_processing_since < datetime('now', '-{$staleMinutes} minutes')"
);

// Claim a batch atomically.
$pdo->exec('BEGIN IMMEDIATE');
$queue = DB::fetchAll(
    'SELECT id FROM contents
     WHERE ai_processed = 0
       AND is_deleted   = 0
       AND ai_processing_since IS NULL
     LIMIT :lim',
    [':lim' => $limit]
);

if (!empty($queue)) {
    $ids = implode(',', array_column($queue, 'id'));
    $pdo->exec(
        "UPDATE contents
         SET ai_processing_since = datetime('now')
         WHERE id IN ({$ids})"
    );
}
$pdo->exec('COMMIT');

// Process the claimed rows.
$processed = 0;
foreach ($queue as $item) {
    if (AIService::processContent((int) $item['id'])) {
        $processed++;
    }
    // Clear the processing lock whether it succeeded or failed —
    // AIService::processContent() already sets ai_processed=1 or 2.
    // We clear ai_processing_since so the column is tidy.
    DB::query(
        'UPDATE contents SET ai_processing_since = NULL WHERE id = :id',
        [':id' => $item['id']]
    );
}

$remaining = (int) DB::fetchScalar(
    'SELECT COUNT(*) FROM contents WHERE ai_processed=0 AND is_deleted=0'
);

// -----------------------------------------------------------------------
// 3. Response (for cron log)
// -----------------------------------------------------------------------
$msg = "[" . date('Y-m-d H:i:s') . "] Elaborati: $processed. Rimanenti in coda: $remaining.\n";
echo $msg;

if ($processed > 0) {
    Logger::system(Logger::INFO, 'Cron AI eseguito', ['processed' => $processed, 'remaining' => $remaining]);
}
