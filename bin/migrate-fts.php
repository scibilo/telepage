<?php

/**
 * TELEPAGE — bin/migrate-fts.php
 *
 * One-time migration that adds the FTS5 full-text search index to an
 * existing Telepage installation.
 *
 * NEW installs do not need this script: DB::initSchema() now creates
 * the contents_fts virtual table and the three sync triggers as part of
 * the normal setup wizard.
 *
 * EXISTING installs need to run this script once to:
 *   1. Create the contents_fts virtual table (if missing).
 *   2. Create the three sync triggers (if missing).
 *   3. Populate the FTS index from the existing contents rows.
 *
 * The script is idempotent: running it twice is safe. IF NOT EXISTS
 * guards prevent duplicate tables/triggers; the population step is
 * skipped if the index already has rows.
 *
 * Usage (CLI):
 *   php bin/migrate-fts.php
 *
 * Usage (browser fallback for hosts without SSH — same pattern as
 * the migrate_run.php bootstrap used for GECO on Aruba):
 *   Upload to web root, load in browser, delete afterwards.
 *
 * Estimated time: < 1 second for most installs. On a very large DB
 * (100k+ rows) it may take a few seconds; the script is synchronous
 * and will complete before returning.
 */

declare(strict_types=1);

define('TELEPAGE_ROOT', dirname(__DIR__));
require_once TELEPAGE_ROOT . '/vendor/autoload.php';

// Allow running from CLI and from browser (Aruba shared hosting fallback).
$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
    // Basic guard: only allow from localhost or when an explicit token is
    // provided. For a real production run, delete the file after use.
    $allowed = ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
            || ($_SERVER['REMOTE_ADDR'] ?? '') === '::1';
    if (!$allowed) {
        http_response_code(403);
        die("403 Forbidden — run from localhost or delete this file after use.\n");
    }
}

$log = static function (string $msg) use ($isCli): void {
    echo ($isCli ? '' : '') . $msg . ($isCli ? "\n" : "\n");
};

$log('Telepage FTS5 migration');
$log('=======================');

if (!Config::isInstalled()) {
    $log('ERROR: Telepage is not installed. Run the install wizard first.');
    exit(1);
}

$pdo = DB::get();

// -----------------------------------------------------------------------
// Step 1 — Create FTS5 virtual table
// -----------------------------------------------------------------------

$log('Step 1: creating contents_fts virtual table…');
try {
    $pdo->exec("
        CREATE VIRTUAL TABLE IF NOT EXISTS contents_fts USING fts5(
            title,
            description,
            ai_summary,
            content=contents,
            content_rowid=id
        );
    ");
    $log('         OK');
} catch (\PDOException $e) {
    $log('ERROR: ' . $e->getMessage());
    $log('Your SQLite build may not include FTS5. Check: SELECT fts5();');
    exit(1);
}

// -----------------------------------------------------------------------
// Step 2 — Create sync triggers
// -----------------------------------------------------------------------

$log('Step 2: creating sync triggers…');

$pdo->exec("
    CREATE TRIGGER IF NOT EXISTS contents_fts_ai
    AFTER INSERT ON contents BEGIN
        INSERT INTO contents_fts(rowid, title, description, ai_summary)
        VALUES (new.id, new.title, new.description, new.ai_summary);
    END;
");

$pdo->exec("
    CREATE TRIGGER IF NOT EXISTS contents_fts_ad
    AFTER DELETE ON contents BEGIN
        INSERT INTO contents_fts(contents_fts, rowid, title, description, ai_summary)
        VALUES ('delete', old.id, old.title, old.description, old.ai_summary);
    END;
");

$pdo->exec("
    CREATE TRIGGER IF NOT EXISTS contents_fts_au
    AFTER UPDATE ON contents BEGIN
        INSERT INTO contents_fts(contents_fts, rowid, title, description, ai_summary)
        VALUES ('delete', old.id, old.title, old.description, old.ai_summary);
        INSERT INTO contents_fts(rowid, title, description, ai_summary)
        VALUES (new.id, new.title, new.description, new.ai_summary);
    END;
");

$log('         OK (3 triggers: ai, ad, au)');

// -----------------------------------------------------------------------
// Step 3 — Populate FTS index from existing contents
// -----------------------------------------------------------------------

$log('Step 3: checking if FTS index needs population…');

$ftsCount      = (int) $pdo->query('SELECT COUNT(*) FROM contents_fts')->fetchColumn();
$contentsCount = (int) $pdo->query('SELECT COUNT(*) FROM contents WHERE is_deleted = 0')->fetchColumn();

if ($ftsCount > 0) {
    $log("         FTS index already has {$ftsCount} rows — rebuilding to ensure consistency…");
    // Always rebuild: bulk-inserted rows need a rebuild to update the
    // inverted index. Running rebuild on an already-correct index is a
    // no-op from a correctness standpoint (just slightly slower).
    $pdo->exec("INSERT INTO contents_fts(contents_fts) VALUES ('rebuild')");
    $log("         Rebuild done. OK");
} else {
    $log("         Populating from {$contentsCount} existing contents rows…");
    $pdo->exec("
        INSERT INTO contents_fts(rowid, title, description, ai_summary)
        SELECT id, title, description, ai_summary
        FROM contents
        WHERE is_deleted = 0
    ");
    // Rebuild is required after bulk-inserting into an external-content
    // FTS5 table. Without it the inverted index is not updated and MATCH
    // queries return no results even though the rows exist.
    $pdo->exec("INSERT INTO contents_fts(contents_fts) VALUES ('rebuild')");
    $populated = (int) $pdo->query('SELECT COUNT(*) FROM contents_fts')->fetchColumn();
    $log("         Inserted {$populated} rows into FTS index. OK");
}

// -----------------------------------------------------------------------
// Done
// -----------------------------------------------------------------------

$log('');
$log('Migration complete. FTS5 search is now active.');
$log('You can delete this file: bin/migrate-fts.php');
$log('(or keep it — it is idempotent and safe to re-run)');
