<?php

/**
 * TELEPAGE — api/cron.php
 * Scheduler for background operations (AI Queue).
 *
 * Example Cron Job:
 * /usr/bin/php /path/to/api/cron.php key=YOUR_SECRET
 * Or via URL:
 * https://yourdomain.com/api/cron.php?key=YOUR_SECRET
 *
 * Uses the same secret as the Telegram webhook for simplicity.
 */

declare(strict_types=1);

define('TELEPAGE_ROOT', dirname(__DIR__));

require_once TELEPAGE_ROOT . '/app/Config.php';
require_once TELEPAGE_ROOT . '/app/DB.php';
require_once TELEPAGE_ROOT . '/app/Logger.php';
require_once TELEPAGE_ROOT . '/app/AIService.php';

// 1. Security check
$config = Config::get();
$secret = $config['webhook_secret'] ?? '';

if (empty($secret)) {
    die("Errore: Webhook secret non configurato. Configura il bot e il webhook prima di usare il cron.\n");
}

// Supports both CLI and GET
$receivedKey = $_GET['key'] ?? '';
if (empty($receivedKey) && PHP_SAPI === 'cli') {
    // In CLI, look for key=... argument
    foreach ($argv as $arg) {
        if (str_starts_with($arg, 'key=')) {
            $receivedKey = substr($arg, 4);
            break;
        }
    }
}

if (!hash_equals($secret, $receivedKey)) {
    http_response_code(403);
    die("Accesso negato: Secret key non valida.\n");
}

// 2. Process AI queue
$limit = 10; // Slightly larger batch for cron
$queue = DB::fetchAll(
    'SELECT id FROM contents WHERE ai_processed=0 AND is_deleted=0 LIMIT :lim',
    [':lim' => $limit]
);

$processed = 0;
foreach ($queue as $item) {
    if (AIService::processContent((int) $item['id'])) {
        $processed++;
    }
}

$remaining = (int) DB::fetchScalar('SELECT COUNT(*) FROM contents WHERE ai_processed=0 AND is_deleted=0');

// 3. Response (for cron log)
$msg = "[" . date('Y-m-d H:i:s') . "] Elaborati: $processed. Rimanenti in coda: $remaining.\n";
echo $msg;

if ($processed > 0) {
    Logger::system(Logger::INFO, 'Cron AI eseguito', ['processed' => $processed, 'remaining' => $remaining]);
}
