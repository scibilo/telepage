<?php
/**
 * TELEPAGE — bin/generate-cron-secret.php
 *
 * One-shot migration: generate a dedicated `cron_secret` in config.json
 * for installations that predate A5. Before A5 the cron endpoint reused
 * `webhook_secret`, which meant a single leak exposed both Telegram
 * webhook forgery AND cron-endpoint DoS. After A5 the two are separate.
 *
 * Running this script:
 *   - Adds `cron_secret` to config.json if missing.
 *   - Leaves `cron_secret` alone if already present (idempotent).
 *   - Leaves `webhook_secret` alone in any case.
 *
 * After running:
 *   - Update your cron(1) command (or any external URL pinger) to use
 *     the NEW secret. The new value is printed at the end.
 *
 * Safe to run multiple times. Safe to run on a live installation —
 * the only file touched is config.json, and the write goes through
 * Config::update() which uses flock() against concurrent writers.
 */

declare(strict_types=1);

define('TELEPAGE_ROOT', dirname(__DIR__));

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("This script must be run from the command line.\n");
}

require_once TELEPAGE_ROOT . '/app/Config.php';

if (!Config::isInstalled()) {
    die("Telepage is not installed yet. Run the install wizard first.\n");
}

$config = Config::get();

if (!empty($config['cron_secret'])) {
    echo "cron_secret is already set. Nothing to do.\n";
    echo "Current value: " . $config['cron_secret'] . "\n";
    exit(0);
}

$newSecret = bin2hex(random_bytes(24));
Config::update(['cron_secret' => $newSecret]);

echo "Generated a new cron_secret.\n\n";
echo "New value: {$newSecret}\n\n";
echo "IMPORTANT: update your cron job to use this new secret.\n";
echo "Example:\n";
echo "  curl -H 'X-Cron-Key: {$newSecret}' https://yourdomain/api/cron.php\n";
echo "  # or\n";
echo "  php " . TELEPAGE_ROOT . "/api/cron.php key={$newSecret}\n";
