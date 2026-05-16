<?php

/**
 * TELEPAGE — bin/migrate-v112.php
 *
 * One-time migration for upgrading from v1.1.x to v1.1.2.
 *
 * Adds:
 *   1. ai_processing_since DATETIME column to contents (cron overlap fix)
 *   2. UNIQUE index on (telegram_message_id, telegram_chat_id) (Telegram
 *      retry deduplication at the DB layer)
 *
 * Safe to run multiple times (IF NOT EXISTS / column-exists check).
 *
 * Usage:
 *   CLI:     php bin/migrate-v112.php
 *   Browser: upload to web root, visit from localhost, delete after.
 */

declare(strict_types=1);

define('TELEPAGE_ROOT', dirname(__DIR__));
require_once TELEPAGE_ROOT . '/vendor/autoload.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
    $allowed = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (!$allowed) {
        http_response_code(403);
        die("403 Forbidden — run from localhost or delete this file after use.\n");
    }
}

$log = static function (string $msg) use ($isCli): void {
    echo $msg . "\n";
};

$log('Telepage v1.1.2 migration');
$log('=========================');

if (!Config::isInstalled()) {
    $log('ERROR: Telepage is not installed.');
    exit(1);
}

$pdo = DB::get();

// -----------------------------------------------------------------------
// Step 1 — add ai_processing_since column if missing
// -----------------------------------------------------------------------
$log('Step 1: adding ai_processing_since column…');

$cols = $pdo->query('PRAGMA table_info(contents)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (in_array('ai_processing_since', $cols, true)) {
    $log('         Already exists — skipping.');
} else {
    $pdo->exec('ALTER TABLE contents ADD COLUMN ai_processing_since DATETIME DEFAULT NULL');
    $log('         OK');
}

// -----------------------------------------------------------------------
// Step 1b — add ai_retry_count column if missing
// -----------------------------------------------------------------------
$log('Step 1b: adding ai_retry_count column…');
$cols = $pdo->query('PRAGMA table_info(contents)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (in_array('ai_retry_count', $cols, true)) {
    $log('         Already exists — skipping.');
} else {
    $pdo->exec('ALTER TABLE contents ADD COLUMN ai_retry_count INTEGER DEFAULT 0');
    $log('         OK');
}

// -----------------------------------------------------------------------
// Step 1c — add next_retry_at column if missing
// -----------------------------------------------------------------------
$log('Step 1c: adding next_retry_at column…');
$cols = $pdo->query('PRAGMA table_info(contents)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (in_array('next_retry_at', $cols, true)) {
    $log('         Already exists — skipping.');
} else {
    $pdo->exec('ALTER TABLE contents ADD COLUMN next_retry_at DATETIME DEFAULT NULL');
    $log('         OK');
}

// -----------------------------------------------------------------------
// Step 2 — add UNIQUE index on (telegram_message_id, telegram_chat_id)
// -----------------------------------------------------------------------
$log('Step 2: adding unique index on (telegram_message_id, telegram_chat_id)…');

$indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='contents'")->fetchAll(PDO::FETCH_COLUMN);
if (in_array('idx_contents_tg_unique', $indexes, true)) {
    $log('         Already exists — skipping.');
} else {
    // Check for duplicates first — the index creation would fail if there
    // are existing duplicate (message_id, chat_id) pairs.
    $dups = (int) $pdo->query(
        'SELECT COUNT(*) FROM (
            SELECT telegram_message_id, telegram_chat_id
            FROM contents
            WHERE telegram_message_id IS NOT NULL
            GROUP BY telegram_message_id, telegram_chat_id
            HAVING COUNT(*) > 1
        )'
    )->fetchColumn();

    if ($dups > 0) {
        $log("         WARNING: {$dups} duplicate (message_id, chat_id) pairs found.");
        $log('         Keeping the newest row for each duplicate and removing the rest…');
        $pdo->exec(
            'DELETE FROM contents
             WHERE telegram_message_id IS NOT NULL
               AND id NOT IN (
                   SELECT MAX(id)
                   FROM contents
                   WHERE telegram_message_id IS NOT NULL
                   GROUP BY telegram_message_id, telegram_chat_id
               )'
        );
        $log('         Duplicates removed.');
    }

    $pdo->exec(
        'CREATE UNIQUE INDEX idx_contents_tg_unique
         ON contents(telegram_message_id, telegram_chat_id)
         WHERE telegram_message_id IS NOT NULL'
    );
    $log('         OK');
}

$log('');
$log('Migration complete.');
$log('You can delete this file: bin/migrate-v112.php');
