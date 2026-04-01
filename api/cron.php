<?php

/**
 * TELEPAGE — api/cron.php
 * Schedulatore per operazioni in background (AI Queue).
 *
 * Esempio Cron Job:
 * /usr/bin/php /path/to/api/cron.php key=TUO_SECRET
 * Oppure via URL:
 * https://yourdomain.com/api/cron.php?key=TUO_SECRET
 *
 * Utilizza lo stesso secret del webhook Telegram per semplicità.
 */

declare(strict_types=1);

define('TELEPAGE_ROOT', dirname(__DIR__));

require_once TELEPAGE_ROOT . '/app/Config.php';
require_once TELEPAGE_ROOT . '/app/DB.php';
require_once TELEPAGE_ROOT . '/app/Logger.php';
require_once TELEPAGE_ROOT . '/app/AIService.php';

// 1. Verifica Sicurezza
$config = Config::get();
$secret = $config['webhook_secret'] ?? '';

if (empty($secret)) {
    die("Errore: Webhook secret non configurato. Configura il bot e il webhook prima di usare il cron.\n");
}

// Supporta sia CLI che GET
$receivedKey = $_GET['key'] ?? '';
if (empty($receivedKey) && PHP_SAPI === 'cli') {
    // In CLI cerca argomento key=...
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

// 2. Processa Coda AI
$limit = 10; // Batch un po' più grande per cron
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

// 3. Risposta (per log cron)
$msg = "[" . date('Y-m-d H:i:s') . "] Elaborati: $processed. Rimanenti in coda: $remaining.\n";
echo $msg;

if ($processed > 0) {
    Logger::system(Logger::INFO, 'Cron AI eseguito', ['processed' => $processed, 'remaining' => $remaining]);
}
