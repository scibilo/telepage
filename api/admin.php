<?php

/**
 * TELEPAGE — api/admin.php
 *
 * Authenticated admin API dispatcher. Requires an active PHP session.
 *
 * This file handles ONLY cross-cutting concerns:
 *   - Autoload and bootstrap
 *   - Session validation + expiry
 *   - CSRF verification
 *   - Rate limiting
 *   - Action → module routing
 *
 * All action logic lives in api/admin/{module}.php:
 *   - system.php   (stats, logs, cleanup, reset_database, upload_logo,
 *                   fix_images, backup, optimize, save_settings,
 *                   set_webhook, sync_telegram, factory_reset)
 *   - contents.php (delete_content, restore_content, contents_list,
 *                   get_content, save_content, import_json,
 *                   import_status, process_ai_queue, requeue_ai,
 *                   test_ai_content, list_ai_models)
 *   - tags.php     (tags_list, save_tag, delete_tag)
 *   - scanner.php  (scan_get_start, scan_batch, scan_stats)
 *
 * Shared helpers (jsonOk, jsonError, requirePost, etc.) live in
 * api/admin/helpers.php, included before any module.
 *
 * The public URL /api/admin.php?action=X is unchanged — zero frontend
 * changes required.
 */

declare(strict_types=1);

define('TELEPAGE_ROOT', dirname(__DIR__));
require_once TELEPAGE_ROOT . '/vendor/autoload.php';
Bootstrap::init(Bootstrap::MODE_JSON);

// -----------------------------------------------------------------------
// Shared helpers (jsonOk, jsonError, requirePost, checkRateLimit, ...)
// Must be loaded before auth so jsonError() is available for 401s.
// -----------------------------------------------------------------------

require_once __DIR__ . '/admin/helpers.php';

// -----------------------------------------------------------------------
// 1. Authentication
// -----------------------------------------------------------------------

Session::start();

if (empty($_SESSION['admin_logged_in'])) {
    jsonError(401, 'Unauthenticated');
}

if (!empty($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 28800) {
    session_destroy();
    jsonError(401, 'Session expired');
}

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
if ($adminId <= 0 || !DB::fetchOne('SELECT id FROM admins WHERE id = :id', [':id' => $adminId])) {
    session_destroy();
    jsonError(401, 'Session no longer valid');
}

// -----------------------------------------------------------------------
// 2. CSRF
// -----------------------------------------------------------------------

CsrfGuard::verifyForWrite();

// -----------------------------------------------------------------------
// 3. Rate limit
// -----------------------------------------------------------------------

$ip = clientIp();
if (!checkRateLimit($ip, 'admin', 120, 60)) {
    jsonError(429, 'Rate limit exceeded — max 120 req/min');
}

// -----------------------------------------------------------------------
// 4. Dispatch
// -----------------------------------------------------------------------

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');
header('Content-Type: application/json; charset=UTF-8');

$module = match (true) {
    in_array($action, [
        'stats', 'logs', 'cleanup', 'reset_database', 'upload_logo',
        'fix_images', 'backup', 'optimize', 'save_settings',
        'set_webhook', 'sync_telegram', 'factory_reset',
    ], true) => 'system',

    in_array($action, [
        'delete_content', 'restore_content', 'contents_list',
        'get_content', 'save_content', 'import_json', 'import_status',
        'process_ai_queue', 'requeue_ai', 'test_ai_content', 'list_ai_models',
    ], true) => 'contents',

    in_array($action, [
        'tags_list', 'save_tag', 'delete_tag',
    ], true) => 'tags',

    in_array($action, [
        'scan_get_start', 'scan_batch', 'scan_stats',
    ], true) => 'scanner',

    default => null,
};

if ($module === null) {
    jsonError(400, "Unknown action: {$action}");
}

require_once __DIR__ . "/admin/{$module}.php";
