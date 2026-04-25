<?php

/**
 * TELEPAGE — api/webhook.php
 * Entry point for Telegram updates.
 *
 * Rule: responds to Telegram within 5 seconds.
 *       Heavy processing starts AFTER the response.
 * Rule: validates X-Telegram-Bot-Api-Secret-Token as the FIRST instruction.
 */

declare(strict_types=1);

// Defines project root (2 levels up: api/ → telepage/)
define('TELEPAGE_ROOT', dirname(__DIR__));

require_once TELEPAGE_ROOT . '/vendor/autoload.php';

Bootstrap::init(Bootstrap::MODE_JSON);

// -----------------------------------------------------------------------
// Validate secret token BEFORE any other operation
// -----------------------------------------------------------------------

$config        = Config::get();
$expectedSecret = $config['webhook_secret'] ?? '';

// Must be configured — if empty, reject everything
if (empty($expectedSecret)) {
    http_response_code(403);
    exit('Webhook secret not configured');
}

$receivedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';

if (!hash_equals($expectedSecret, $receivedSecret)) {
    // Log the unauthorised attempt
    // We don't use Logger here to avoid DB dependency during a flood attack
    http_response_code(403);
    exit;
}

// -----------------------------------------------------------------------
// Accept only POST with JSON body
// -----------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Verify installation is complete
if (!Config::isInstalled()) {
    http_response_code(503);
    exit;
}

// Reject non-JSON content types up front. Telegram always sends
// application/json; anything else is a misconfiguration or a probe,
// not worth allocating the body buffer for.
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (!str_starts_with($contentType, 'application/json')) {
    http_response_code(415);
    exit;
}

// -----------------------------------------------------------------------
// Read and decode the body
// -----------------------------------------------------------------------
//
// Body size cap: Telegram updates in practice stay well below 100 KB,
// with media_group + entity-heavy messages approaching ~500 KB at the
// extreme. We cap at 1 MB which leaves 2× headroom over anything
// legitimate and blocks a compromised-secret attacker from pushing
// multi-megabyte payloads that would each balloon an Apache worker's
// RSS. 413 is the standard status for oversized bodies.

const MAX_WEBHOOK_BODY_BYTES = 1_048_576; // 1 MB

$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : -1;
if ($contentLength > MAX_WEBHOOK_BODY_BYTES) {
    http_response_code(413);
    exit;
}

$rawBody = file_get_contents('php://input', false, null, 0, MAX_WEBHOOK_BODY_BYTES + 1);

if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    exit;
}

// Catch chunked transfers (or missing Content-Length) that turn out to
// exceed the cap: read one byte past the limit, then refuse if any
// data ended up beyond MAX_WEBHOOK_BODY_BYTES.
if (strlen($rawBody) > MAX_WEBHOOK_BODY_BYTES) {
    http_response_code(413);
    exit;
}

$update = json_decode($rawBody, true);

if (!is_array($update)) {
    http_response_code(400);
    exit;
}

// -----------------------------------------------------------------------
// Immediate response to Telegram (within 5 seconds)
// Send 200 OK, then process in background
// -----------------------------------------------------------------------

// Close HTTP connection (technique: flush + ignore_user_abort)
ignore_user_abort(true);
header('Content-Type: application/json');
header('Connection: close');
header('Content-Length: 2');
ob_start();
echo '{}';
$size = ob_get_length();

// Update actual Content-Length
header('Content-Length: ' . $size);
http_response_code(200);

ob_end_flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request(); // PHP-FPM: closes the connection immediately
} else {
    flush();
    // For non-FPM servers: increase max_execution_time for background processing
    set_time_limit(60);
}

// -----------------------------------------------------------------------
// Background processing (after sending 200 OK)
// -----------------------------------------------------------------------

try {
    $contentId = TelegramBot::handleUpdate($update);
    
    // If the ID is valid and AI is enabled, process immediately (we are already in background)
    if ($contentId && ($config['ai_enabled'] ?? false)) {
        AIService::processContent($contentId);
    }
} catch (Throwable $e) {
    error_log('[TELEPAGE][WEBHOOK] ' . $e->getMessage());
}
