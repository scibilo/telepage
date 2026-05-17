<?php

/**
 * TELEPAGE — bin/migrate-v116.php
 *
 * One-time migration for upgrading to v1.1.6.
 *
 * Adds:
 *   1. processed_updates table for webhook update_id deduplication
 *
 * Safe to run multiple times (IF NOT EXISTS / table-exists check).
 *
 * Usage:
 *   CLI:     php bin/migrate-v116.php
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

$log = static function (string $msg): void {
    echo $msg . "\n";
};

$log('Telepage v1.1.6 migration');
$log('=========================');

if (!Config::isInstalled()) {
    $log('ERROR: Telepage is not installed.');
    exit(1);
}

$pdo = DB::get();

// -----------------------------------------------------------------------
// Step 1 — create processed_updates table if missing
// -----------------------------------------------------------------------
$log('Step 1: creating processed_updates table…');

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
if (in_array('processed_updates', $tables, true)) {
    $log('         Already exists — skipping.');
} else {
    $pdo->exec(
        'CREATE TABLE processed_updates (
            update_id    TEXT NOT NULL PRIMARY KEY,
            processed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $log('         OK');
}

$log('');
$log('Migration complete.');
$log('You can delete this file: bin/migrate-v116.php');
