<?php

/**
 * TELEPAGE — api/webhook.php
 * Entry point per gli update di Telegram.
 *
 * Regola RB-01: risponde a Telegram entro 5 secondi.
 *               Il processing heavy viene avviato DOPO la risposta.
 * Regola RB-02: valida X-Telegram-Bot-Api-Secret-Token come PRIMA istruzione.
 */

declare(strict_types=1);

// Definisce root del progetto (2 livelli sopra: api/ → telepage/)
define('TELEPAGE_ROOT', dirname(__DIR__));

require_once TELEPAGE_ROOT . '/app/Config.php';
require_once TELEPAGE_ROOT . '/app/DB.php';
require_once TELEPAGE_ROOT . '/app/Logger.php';
require_once TELEPAGE_ROOT . '/app/Scraper.php';
require_once TELEPAGE_ROOT . '/app/TelegramBot.php';

// -----------------------------------------------------------------------
// RB-02 — Valida secret token PRIMA di qualsiasi altra operazione
// -----------------------------------------------------------------------

$config        = Config::get();
$expectedSecret = $config['webhook_secret'] ?? '';

// Deve essere configurato — se vuoto, rifiuta tutto
if (empty($expectedSecret)) {
    http_response_code(403);
    exit('Webhook secret not configured');
}

$receivedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';

if (!hash_equals($expectedSecret, $receivedSecret)) {
    // Log del tentativo non autorizzato
    // Non usiamo Logger qui per evitare dipendenza da DB in caso di attacco flood
    http_response_code(403);
    exit;
}

// -----------------------------------------------------------------------
// Accetta solo POST con JSON body
// -----------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Verifica installazione completata
if (!Config::isInstalled()) {
    http_response_code(503);
    exit;
}

// -----------------------------------------------------------------------
// Leggi e decodifica il body
// -----------------------------------------------------------------------

$rawBody = file_get_contents('php://input');

if (empty($rawBody)) {
    http_response_code(400);
    exit;
}

$update = json_decode($rawBody, true);

if (!is_array($update)) {
    http_response_code(400);
    exit;
}

// -----------------------------------------------------------------------
// RB-01 — Risposta immediata a Telegram (entro 5 secondi)
// Invia 200 OK, poi processa in background
// -----------------------------------------------------------------------

// Chiudi connessione HTTP (tecnica: flush + ignore_user_abort)
ignore_user_abort(true);
header('Content-Type: application/json');
header('Connection: close');
header('Content-Length: 2');
ob_start();
echo '{}';
$size = ob_get_length();

// Aggiorna Content-Length reale
header('Content-Length: ' . $size);
http_response_code(200);

ob_end_flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request(); // PHP-FPM: chiude la connessione immediatamente
} else {
    flush();
    // Per server non-FPM: aumenta max_execution_time per il background processing
    set_time_limit(60);
}

// -----------------------------------------------------------------------
// Processing in background (dopo aver inviato 200 OK)
// -----------------------------------------------------------------------

try {
    $contentId = TelegramBot::handleUpdate($update);
    
    // Se l'ID è valido e l'AI è abilitata, processa subito (siamo già in background)
    if ($contentId && ($config['ai_enabled'] ?? false)) {
        require_once TELEPAGE_ROOT . '/app/AIService.php';
        AIService::processContent($contentId);
    }
} catch (Throwable $e) {
    error_log('[TELEPAGE][WEBHOOK] ' . $e->getMessage());
}
