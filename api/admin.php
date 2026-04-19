<?php

/**
 * TELEPAGE — api/admin.php
 * Authenticated admin API. Requires an active PHP session.
 *
 * Every action is logged with IP and timestamp.
 * reset_database requires confirm='RESET'.
 * Soft delete: is_deleted=1.
 * Physical files deleted only on cleanup.
 */

declare(strict_types=1);

define('TELEPAGE_ROOT', dirname(__DIR__));

require_once TELEPAGE_ROOT . '/app/bootstrap.php';
Bootstrap::init(Bootstrap::MODE_JSON);

require_once TELEPAGE_ROOT . '/app/Config.php';
require_once TELEPAGE_ROOT . '/app/DB.php';
require_once TELEPAGE_ROOT . '/app/Logger.php';
require_once TELEPAGE_ROOT . '/app/Security/CsrfGuard.php';
require_once TELEPAGE_ROOT . '/app/Str.php';
require_once TELEPAGE_ROOT . '/app/TelegramBot.php';
require_once TELEPAGE_ROOT . '/app/Scraper.php';

// -----------------------------------------------------------------------
// 1. Authentication — first instruction
// -----------------------------------------------------------------------

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
// Isolated session per installation: prevents two instances on the same domain from sharing a session
session_name('tp_' . substr(hash('sha256', TELEPAGE_ROOT), 0, 16));
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    jsonError(401, 'Unauthenticated');
}

// Session expiry: 8 hours
if (!empty($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 28800) {
    session_destroy();
    jsonError(401, 'Session expired');
}

// Verify the admin record still exists. Closes the window where a
// leaked session remains valid after the admin row has been deleted
// or the database reset. See admin/_auth.php for the matching check
// on page loads.
$adminId = (int) ($_SESSION['admin_id'] ?? 0);
if ($adminId <= 0 || !DB::fetchOne('SELECT id FROM admins WHERE id = :id', [':id' => $adminId])) {
    session_destroy();
    jsonError(401, 'Session no longer valid');
}

// CSRF: reject any write request without a valid X-CSRF-Token header.
// GET/HEAD pass through untouched (handled inside verifyForWrite).
CsrfGuard::verifyForWrite();

// Rate limiting admin: 120 req/min (admin operations like bulk delete need headroom)
$ip = clientIp();
if (!checkRateLimit($ip, 'admin', 120, 60)) {
    jsonError(429, 'Rate limit exceeded — max 120 req/min');
}

// -----------------------------------------------------------------------
// 2. Dispatch action
// -----------------------------------------------------------------------

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');
header('Content-Type: application/json; charset=UTF-8');

match ($action) {
    'stats'            => actionStats(),
    'logs'             => actionLogs(),
    'delete_content'   => actionDeleteContent(),
    'restore_content'  => actionRestoreContent(),
    'cleanup'          => actionCleanup(),
    'reset_database'   => actionResetDatabase(),
    'upload_logo'      => actionUploadLogo(),
    'fix_images'       => actionFixImages(),
    'backup'           => actionBackup(),
    'optimize'         => actionOptimize(),
    'save_settings'    => actionSaveSettings(),
    'set_webhook'      => actionSetWebhook(),
    'sync_telegram'    => actionSyncTelegram(),
    'import_json'      => actionImportJson(),
    'import_status'    => actionImportStatus(),
    'process_ai_queue' => actionProcessAiQueue(),
    'factory_reset'    => actionFactoryReset(),
    'scan_get_start'   => actionScanGetStart(),
    'scan_batch'       => actionScanBatch(),
    'scan_stats'       => actionScanStats(),
    'requeue_ai'       => actionRequeueAi(),
    'list_ai_models'   => actionListAiModels(),
    'test_ai_content'  => actionTestAiContent(),
    // Manual cataloguing
    'tags_list'        => actionTagsList(),
    'save_tag'         => actionSaveTag(),
    'delete_tag'       => actionDeleteTag(),
    'contents_list'    => actionContentsList(),
    'get_content'      => actionGetContent(),
    'save_content'     => actionSaveContent(),
    default            => jsonError(400, "Unknown action: {$action}"),
};

// -----------------------------------------------------------------------
// Actions
// -----------------------------------------------------------------------

/**
 * GET /api/admin.php?action=stats
 * General system statistics.
 */
function actionStats(): void
{
    $data = [
        'contents_total'    => (int) DB::fetchScalar('SELECT COUNT(*) FROM contents WHERE is_deleted=0'),
        'contents_deleted'  => (int) DB::fetchScalar('SELECT COUNT(*) FROM contents WHERE is_deleted=1'),
        'ai_pending'        => (int) DB::fetchScalar('SELECT COUNT(*) FROM contents WHERE ai_processed=0 AND is_deleted=0'),
        'ai_done'           => (int) DB::fetchScalar('SELECT COUNT(*) FROM contents WHERE ai_processed=1 AND is_deleted=0'),
        'ai_failed'         => (int) DB::fetchScalar('SELECT COUNT(*) FROM contents WHERE ai_processed=2 AND is_deleted=0'),
        'no_image'          => (int) DB::fetchScalar("SELECT COUNT(*) FROM contents WHERE (image IS NULL OR image='') AND is_deleted=0"),
        'tags_total'        => (int) DB::fetchScalar('SELECT COUNT(*) FROM tags'),
        'db_size_bytes'     => (int) filesize(Config::getKey('db_path', '')),
        'by_type'           => DB::fetchAll('SELECT content_type, COUNT(*) as cnt FROM contents WHERE is_deleted=0 GROUP BY content_type ORDER BY cnt DESC'),
        'top_tags'          => DB::fetchAll('SELECT name, slug, color, usage_count FROM tags ORDER BY usage_count DESC LIMIT 10'),
        'webhook_info'      => TelegramBot::getWebhookInfo(),
    ];

    jsonOk($data);
}

/**
 * GET /api/admin.php?action=logs&level=error&category=ai&page=1
 */
function actionLogs(): void
{
    $level    = $_GET['level']    ?? null;
    $category = $_GET['category'] ?? null;
    $page     = max(1, (int) ($_GET['page'] ?? 1));
    $perPage  = min(100, max(10, (int) ($_GET['per_page'] ?? 50)));

    $result = Logger::fetch($level, $category, $page, $perPage);
    jsonOk($result);
}

/**
 * POST /api/admin.php?action=delete_content  body: {id: N}
 * Soft delete: sets is_deleted=1, does not delete the file.
 */
function actionDeleteContent(): void
{
    requirePost();
    $id = (int) (bodyParam('id') ?? 0);
    if ($id <= 0) {
        jsonError(400, 'Invalid ID');
    }

    DB::query('UPDATE contents SET is_deleted=1, updated_at=CURRENT_TIMESTAMP WHERE id=:id', [':id' => $id]);
    Logger::admin(Logger::INFO, 'Soft delete content', ['content_id' => $id]);
    jsonOk(['deleted' => $id]);
}

/**
 * POST /api/admin.php?action=restore_content  body: {id: N}
 */
function actionRestoreContent(): void
{
    requirePost();
    $id = (int) (bodyParam('id') ?? 0);
    if ($id <= 0) {
        jsonError(400, 'Invalid ID');
    }

    DB::query('UPDATE contents SET is_deleted=0, updated_at=CURRENT_TIMESTAMP WHERE id=:id', [':id' => $id]);
    Logger::admin(Logger::INFO, 'Restore content', ['content_id' => $id]);
    jsonOk(['restored' => $id]);
}

/**
 * POST /api/admin.php?action=cleanup
 * Permanently deletes trashed contents and their physical files.
 */
function actionCleanup(): void
{
    requirePost();

    $deleted = DB::fetchAll('SELECT id, image FROM contents WHERE is_deleted=1');
    $count   = 0;
    $files   = 0;

    foreach ($deleted as $row) {
        // Delete physical file if it exists in assets/media/
        $image = $row['image'] ?? '';
        if (!empty($image) && str_starts_with($image, 'assets/media/')) {
            $fullPath = TELEPAGE_ROOT . '/' . $image;
            // Path traversal check
            $realPath = realpath($fullPath);
            $mediaDir = realpath(TELEPAGE_ROOT . '/assets/media');
            if ($realPath && $mediaDir && str_starts_with($realPath, $mediaDir)) {
                if (unlink($realPath)) {
                    $files++;
                }
            }
        }
    }

    // Delete records
    $stmt = DB::query('DELETE FROM contents WHERE is_deleted=1');
    $count = $stmt->rowCount();

    // Garbage-collect expired rate_limits rows. Keep only windows that
    // started in the last hour — anything older is for sure expired for
    // every rate policy we currently enforce (max LOCK_GLOBAL = 1800s).
    $cutoff = time() - 3600;
    $rlStmt = DB::query(
        'DELETE FROM rate_limits WHERE window_start < :cutoff',
        [':cutoff' => $cutoff]
    );
    $rateLimitsDeleted = $rlStmt->rowCount();

    Logger::admin(Logger::INFO, 'Cleanup done', [
        'records'      => $count,
        'files'        => $files,
        'rate_limits'  => $rateLimitsDeleted,
    ]);
    jsonOk([
        'deleted_records'      => $count,
        'deleted_files'        => $files,
        'deleted_rate_limits'  => $rateLimitsDeleted,
    ]);
}

/**
 * POST /api/admin.php?action=reset_database  body: {confirm: 'RESET'}
 * Full reset — requires text confirmation.
 */
function actionResetDatabase(): void
{
    requirePost();

    $confirm = bodyParam('confirm') ?? '';
    if ($confirm !== 'RESET') {
        Logger::admin(Logger::WARNING, 'reset_database attempt without confirmation');
        jsonError(400, "confirm field must be exactly 'RESET'");
    }

    // Log BEFORE deleting
    Logger::admin(Logger::WARNING, 'DATABASE RESET executed');

    $pdo = DB::get();

    // Delete all media files
    $mediaDir = TELEPAGE_ROOT . '/assets/media';
    if (is_dir($mediaDir)) {
        $files = glob($mediaDir . '/*');
        foreach ($files ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    // Empty all tables
    $tables = ['content_tags', 'contents', 'tags', 'import_cursors', 'logs', 'rate_limits'];
    foreach ($tables as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }
    $pdo->exec('DELETE FROM sqlite_sequence WHERE name != "admins"');
    $pdo->exec('VACUUM');

    // Rotate the session ID so that any cookie that was in circulation
    // before the reset (e.g. stolen or leaked) no longer matches the
    // current session. The admin who triggered the reset keeps their
    // access via the new ID; any other copy of the old cookie is
    // rejected on next request because the session row it points to
    // has been destroyed by regenerate_id(true).
    session_regenerate_id(true);

    jsonOk(['reset' => true]);
}

/**
 * POST /api/admin.php?action=upload_logo
 * Receives an image, resizes it (GD) and saves it as logo.png.
 */
function actionUploadLogo(): void
{
    if (empty($_FILES['logo'])) jsonError(400, 'No file uploaded');
    $file = $_FILES['logo'];

    if ($file['error'] !== UPLOAD_ERR_OK) jsonError(400, 'File upload error');

    // Verify type
    $mime = mime_content_type($file['tmp_name']);
    if (!str_starts_with($mime, 'image/')) jsonError(400, 'The file is not a valid image');

    // Resize with GD
    if (!extension_loaded('gd')) {
        // Fallback: plain copy if GD is unavailable
        $dest = 'assets/img/logo.png';
        move_uploaded_file($file['tmp_name'], TELEPAGE_ROOT . '/' . $dest);
        jsonOk(['path' => $dest, 'note' => 'GD not available, file copied without resizing']);
    }

    $src = null;
    if ($mime === 'image/jpeg') $src = imagecreatefromjpeg($file['tmp_name']);
    elseif ($mime === 'image/png') $src = imagecreatefrompng($file['tmp_name']);
    elseif ($mime === 'image/webp') $src = imagecreatefromwebp($file['tmp_name']);
    else jsonError(400, 'Unsupported image format (use JPG, PNG or WEBP)');

    if (!$src) jsonError(500, 'Cannot process the image');

    $w = imagesx($src);
    $h = imagesy($src);
    $max = 256;

    // Resize proportionally without square canvas (preserves aspect ratio)
    if ($w > $max || $h > $max) {
        if ($w >= $h) { $newW = $max; $newH = (int)round($h * ($max / $w)); }
        else           { $newH = $max; $newW = (int)round($w * ($max / $h)); }
        $resized = imagecreatetruecolor($newW, $newH);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        imagecopyresampled($resized, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($src);
        $src = $resized;
    }

    $destPath = TELEPAGE_ROOT . '/assets/img/logo.png';
    if (!is_dir(dirname($destPath))) mkdir(dirname($destPath), 0755, true);
    imagepng($src, $destPath, 6);

    // Square favicon with proportionally centred logo
    $srcW = imagesx($src); $srcH = imagesy($src);
    foreach ([32, 64] as $size) {
        $fav = imagecreatetruecolor($size, $size);
        imagealphablending($fav, false);
        imagesavealpha($fav, true);
        imagefill($fav, 0, 0, imagecolorallocatealpha($fav, 0, 0, 0, 127));
        $scale = min($size / $srcW, $size / $srcH);
        $dW = (int)($srcW * $scale); $dH = (int)($srcH * $scale);
        $oX = (int)(($size - $dW) / 2); $oY = (int)(($size - $dH) / 2);
        imagecopyresampled($fav, $src, $oX, $oY, 0, 0, $dW, $dH, $srcW, $srcH);
        imagepng($fav, TELEPAGE_ROOT . "/assets/img/favicon-{$size}.png");
        imagedestroy($fav);
    }

    imagedestroy($src);

    // Update config timestamp for cache-busting
    Config::update(['logo_updated' => time()]);

    jsonOk(['path' => 'assets/img/logo.png']);
}

/**
 * POST /api/admin.php?action=factory_reset  body: {confirm: 'FACTORY RESET'}
 * Factory reset: deletes EVERYTHING, including the configuration.
 */
function actionFactoryReset(): void
{
    requirePost();
    $confirm = bodyParam('confirm') ?? '';
    if ($confirm !== 'FACTORY RESET') {
        jsonError(400, "Wrong confirmation. Type 'FACTORY RESET'");
    }

    Logger::admin(Logger::WARNING, 'FACTORY RESET INITIATED');

    // 1. Telegram Webhook (attempt to detach before losing the token)
    try {
        TelegramBot::deleteWebhook();
    } catch (Throwable) {}

    // 2. Clear Media
    $mediaDir = TELEPAGE_ROOT . '/assets/media';
    if (is_dir($mediaDir)) {
        $files = glob($mediaDir . '/*');
        foreach ($files ?: [] as $f) if (is_file($f)) @unlink($f);
    }

    // 3. Clear database file
    $dbPath = Config::getKey('db_path', '');
    if (!empty($dbPath) && file_exists($dbPath)) {
        // Close PDO connection
        DB::reset(); 
        @unlink($dbPath);
        @unlink($dbPath . '-wal');
        @unlink($dbPath . '-shm');
    }

    // 4. Delete Config
    $configFile = TELEPAGE_ROOT . '/config.json';
    if (file_exists($configFile)) {
        @unlink($configFile);
    }

    // Destroy session
    session_destroy();

    jsonOk(['factory_reset' => true]);
}

/**
 * POST /api/admin.php?action=fix_images  body: {limit: 5}
 * Re-scrapes missing images (does not delete, corrects).
 */
function actionFixImages(): void
{
    requirePost();
    $limit = min(20, max(1, (int) (bodyParam('limit') ?? 5)));

    $rows = DB::fetchAll(
        "SELECT id, url FROM contents
         WHERE (image IS NULL OR image='') AND url IS NOT NULL AND url != '' AND is_deleted=0
         LIMIT :lim",
        [':lim' => $limit]
    );

    $fixed = 0;
    foreach ($rows as $row) {
        $meta = Scraper::fetch($row['url']);
        if (!empty($meta['image'])) {
            DB::query(
                'UPDATE contents SET image=:img, image_source=:src, updated_at=CURRENT_TIMESTAMP WHERE id=:id',
                [':img' => $meta['image'], ':src' => $meta['image_source'], ':id' => $row['id']]
            );
            $fixed++;
        }
    }

    Logger::admin(Logger::INFO, 'Fix images', ['checked' => count($rows), 'fixed' => $fixed]);
    jsonOk(['checked' => count($rows), 'fixed' => $fixed]);
}

/**
 * POST /api/admin.php?action=backup
 * Creates a SQLite copy in data/backups/.
 */
function actionBackup(): void
{
    requirePost();

    $dbPath     = Config::getKey('db_path', '');
    $backupDir  = TELEPAGE_ROOT . '/data/backups';

    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0750, true);
        file_put_contents($backupDir . '/.htaccess', "Deny from all\n");
    }

    // Filename: backup_YYYY-MM-DD_HHmmss.sqlite
    $filename   = 'backup_' . date('Y-m-d_His') . '.sqlite';
    $backupPath = $backupDir . '/' . $filename;

    if (!copy($dbPath, $backupPath)) {
        jsonError(500, 'Could not create backup');
    }

    Logger::admin(Logger::INFO, 'Backup created', ['file' => $filename]);
    jsonOk(['file' => $filename, 'size' => filesize($backupPath)]);
}

/**
 * POST /api/admin.php?action=optimize
 * VACUUM + ANALYZE su SQLite.
 */
function actionOptimize(): void
{
    requirePost();
    $pdo = DB::get();
    $pdo->exec('PRAGMA optimize');
    $pdo->exec('VACUUM');
    $pdo->exec('ANALYZE');
    Logger::admin(Logger::INFO, 'DB optimised');
    jsonOk(['optimized' => true]);
}

/**
 * POST /api/admin.php?action=save_settings  body: JSON con chiavi config
 */
function actionSaveSettings(): void
{
    requirePost();

    $allowed = [
        'app_name', 'theme_color', 'site_theme', 'logo_path',
        'telegram_bot_token', 'telegram_channel_id', 'custom_webhook_url',
        'gemini_api_key', 'ai_enabled', 'ai_auto_tag', 'ai_auto_summary',
        'items_per_page', 'pagination_type', 'language', 'download_media',
    ];

    $body    = getJsonBody();
    $updates = [];

    foreach ($allowed as $key) {
        if (array_key_exists($key, $body)) {
            // Booleans
            if (in_array($key, ['ai_enabled', 'ai_auto_tag', 'ai_auto_summary', 'download_media'])) {
                $updates[$key] = (bool) $body[$key];
            } elseif ($key === 'items_per_page') {
                $updates[$key] = in_array((int) $body[$key], [12, 24, 48, 96]) ? (int) $body[$key] : 12;
            } elseif ($key === 'pagination_type') {
                $updates[$key] = in_array($body[$key], ['classic', 'enhanced', 'loadmore', 'infinite'])
                    ? $body[$key]
                    : 'classic';
            } elseif ($key === 'theme_color') {
                // Must be #rgb or #rrggbb. Anything else falls back to the
                // stock accent colour — this blocks XSS via CSS injection
                // (a value like '; url(javascript:...) //' would otherwise
                // end up inside <body style="--accent-color: …;">).
                $updates[$key] = Str::safeHexColor((string) $body[$key]);
            } elseif ($key === 'app_name') {
                // Length-clamped and stripped of control chars. HTML escaping
                // happens at render time via e()/htmlspecialchars — this
                // just prevents storing absurdly long or malformed names.
                $updates[$key] = Str::clampDisplayName((string) $body[$key]);
            } else {
                $updates[$key] = (string) $body[$key];
            }
        }
    }

    Config::update($updates);
    Logger::admin(Logger::INFO, 'Settings saved', array_keys($updates));
    jsonOk(['saved' => array_keys($updates)]);
}

/**
 * POST /api/admin.php?action=set_webhook
 * Sets or verifies the Telegram webhook.
 */
function actionSetWebhook(): void
{
    requirePost();

    $config  = Config::get();
    $baseUrl = $config['custom_webhook_url'] ?? bodyParam('base_url') ?? detectBaseUrl();

    // Auto-save custom_webhook_url if not yet configured
    if (empty($config['custom_webhook_url'])) {
        $detected = detectBaseUrl();
        Config::update(['custom_webhook_url' => $detected]);
        $baseUrl = $detected;
        Logger::admin(Logger::INFO, 'custom_webhook_url auto-saved', ['url' => $detected]);
    }
    
    // Smart HTTPS Force: if URL is http, try https anyway for Telegram
    $finalUrl = $baseUrl;
    if (str_starts_with($baseUrl, 'http://')) {
        $finalUrl = str_replace('http://', 'https://', $baseUrl);
    }

    $webhookUrl = rtrim($finalUrl, '/') . '/api/webhook.php';

    $secret = $config['webhook_secret'] ?? '';
    if (empty($secret)) {
        $secret = bin2hex(random_bytes(24));
        Config::update(['webhook_secret' => $secret]);
    }

    $result = TelegramBot::setWebhook($webhookUrl, $secret);
    Logger::admin(Logger::INFO, 'set_webhook', ['url' => $webhookUrl, 'ok' => $result['ok'] ?? false]);
    // Include the URL used in the response for visible debug in admin console
    $result['webhook_url_used'] = $webhookUrl;
    jsonOk($result);
}

/**
 * POST /api/admin.php?action=sync_telegram
 * Detach webhook → getUpdates → re-attach.
 */
function actionSyncTelegram(): void
{
    requirePost();

    $config = Config::get();

    // 1. Detach webhook
    TelegramBot::deleteWebhook();

    // 2. getUpdates
    $updates = TelegramBot::getUpdates(0, 100);
    $processed = 0;

    foreach ($updates as $update) {
        if (TelegramBot::handleUpdate($update)) {
            $processed++;
        }
    }

    // 3. Always re-attach the webhook after getUpdates
    $savedUrl   = $config['custom_webhook_url'] ?? '';
    $baseUrl    = !empty($savedUrl) ? rtrim($savedUrl, '/') : detectBaseUrl();
    $webhookUrl = $baseUrl . '/api/webhook.php';
    $secret     = $config['webhook_secret'] ?? '';
    if (!empty($secret)) {
        // Always re-attach — if savedUrl is empty use detectBaseUrl (which forces HTTPS)
        TelegramBot::setWebhook($webhookUrl, $secret);
    } else {
        Logger::admin(Logger::WARNING, 'sync_telegram: webhook_secret empty, webhook not re-attached');
    }

    Logger::admin(Logger::INFO, 'Telegram sync', ['updates' => count($updates), 'processed' => $processed]);
    jsonOk(['updates_received' => count($updates), 'processed' => $processed]);
}

// -----------------------------------------------------------------------
// TAGS CRUD
// -----------------------------------------------------------------------

/** GET /api/admin.php?action=tags_list */
function actionTagsList(): void
{
    $tags = DB::fetchAll('SELECT * FROM tags ORDER BY name ASC');
    jsonOk($tags);
}

/** POST /api/admin.php?action=save_tag  body: {id, name, slug, color, source} */
function actionSaveTag(): void
{
    requirePost();
    $data = getJsonBody();
    $id    = (int) ($data['id'] ?? 0);
    $name  = trim($data['name'] ?? '');
    $color = trim($data['color'] ?? '#3b82f6');
    $src   = trim($data['source'] ?? 'manual');

    if (empty($name)) {
        jsonError(400, 'Name is required');
    }

    // Slug is always derived server-side from name — the client-side
    // tpSlugify() just previews what we'll compute here. We don't trust
    // whatever slug the client posts: it could be arbitrary (path-like,
    // duplicate of another tag's slug, etc.).
    $slug = Str::slugify($name);
    if (empty($slug)) {
        jsonError(400, 'Name produces an empty slug (only punctuation?). Use alphanumeric characters.');
    }

    if ($id > 0) {
        DB::query(
            'UPDATE tags SET name=:n, slug=:s, color=:c, source=:src WHERE id=:id',
            [':n' => $name, ':s' => $slug, ':c' => $color, ':src' => $src, ':id' => $id]
        );
    } else {
        DB::query(
            'INSERT INTO tags (name, slug, color, source) VALUES (:n, :s, :c, :src)',
            [':n' => $name, ':s' => $slug, ':c' => $color, ':src' => $src]
        );
        $id = (int) DB::lastInsertId();
    }

    Logger::admin(Logger::INFO, 'Tag saved', ['id' => $id, 'name' => $name]);
    jsonOk(['id' => $id]);
}

/** POST /api/admin.php?action=delete_tag  body: {id} */
function actionDeleteTag(): void
{
    requirePost();
    $id = (int) (bodyParam('id') ?? 0);
    if ($id <= 0) jsonError(400, 'Invalid ID');

    DB::query('DELETE FROM tags WHERE id = :id', [':id' => $id]);
    Logger::admin(Logger::WARNING, 'Tag deleted', ['id' => $id]);
    jsonOk(['deleted' => $id]);
}

// -----------------------------------------------------------------------
// CONTENTS CRUD
// -----------------------------------------------------------------------

/** GET /api/admin.php?action=contents_list&page=1&q=... */
function actionContentsList(): void
{
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 50;
    $offset  = ($page - 1) * $perPage;
    $search  = trim($_GET['q'] ?? '');

    $where = ['1=1'];
    $params = [];
    if (!empty($search)) {
        $where[] = '(title LIKE :q OR description LIKE :q)';
        $params[':q'] = "%$search%";
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $total = (int) DB::fetchScalar("SELECT COUNT(*) FROM contents $whereSql", $params);
    $rows  = DB::fetchAll(
        "SELECT id, title, url, content_type, is_deleted, created_at
         FROM contents $whereSql
         ORDER BY created_at DESC LIMIT :l OFFSET :o",
        array_merge($params, [':l' => $perPage, ':o' => $offset])
    );

    jsonOk([
        'items' => $rows,
        'total' => $total,
        'pages' => ceil($total / $perPage)
    ]);
}

/** GET /api/admin.php?action=get_content&id=N */
function actionGetContent(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) jsonError(400, 'Invalid ID');

    $content = DB::fetchOne('SELECT * FROM contents WHERE id = :id', [':id' => $id]);
    if (!$content) jsonError(404, 'Content not found');

    // Fetch associated tags
    $tags = DB::fetchAll(
        'SELECT t.id, t.name, t.slug FROM tags t
         JOIN content_tags ct ON ct.tag_id = t.id
         WHERE ct.content_id = :id',
        [':id' => $id]
    );
    $content['tags'] = $tags;

    jsonOk($content);
}

/** POST /api/admin.php?action=save_content  body: {id, title, description, tags: [id1, id2...]} */
function actionSaveContent(): void
{
    requirePost();
    $data = getJsonBody();
    $id   = (int) ($data['id'] ?? 0);
    if ($id <= 0) jsonError(400, 'Invalid ID');

    $title = trim($data['title'] ?? '');
    $desc  = trim($data['description'] ?? '');
    $tags  = (array) ($data['tags'] ?? []);

    DB::beginTransaction();
    try {
        // Update metadata
        DB::query(
            'UPDATE contents SET title=:t, description=:d, updated_at=CURRENT_TIMESTAMP WHERE id=:id',
            [':t' => $title, ':d' => $desc, ':id' => $id]
        );

        // Sync tags
        DB::query('DELETE FROM content_tags WHERE content_id = :id', [':id' => $id]);
        foreach ($tags as $tagId) {
            DB::query(
                'INSERT OR IGNORE INTO content_tags (content_id, tag_id) VALUES (:cid, :tid)',
                [':cid' => $id, ':tid' => (int) $tagId]
            );
        }

        DB::commit();
        Logger::admin(Logger::INFO, 'Content edited manually', ['id' => $id]);
        jsonOk(['id' => $id]);
    } catch (Throwable $e) {
        DB::rollBack();
        jsonError(500, 'Save error: ' . $e->getMessage());
    }
}

/**
 * POST /api/admin.php?action=import_json  multipart con file 'json_file'
 */
function actionImportJson(): void
{
    requirePost();

    // TelegramImporter class must be included
    require_once TELEPAGE_ROOT . '/app/TelegramImporter.php';

    $file = $_FILES['json_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        jsonError(400, 'File not received or upload error');
    }

    // Verify actual MIME type
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['application/json', 'text/plain', 'text/json'])) {
        jsonError(400, "Invalid file type: {$mime}. Please upload a .json file");
    }

    // Read and parse
    $raw  = file_get_contents($file['tmp_name']);
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['messages'])) {
        jsonError(400, 'Invalid JSON file or not a Telegram Desktop export');
    }

    $dateFrom = isset($_POST['date_from']) ? strtotime($_POST['date_from']) : 0;
    $dateTo   = isset($_POST['date_to'])   ? strtotime($_POST['date_to'])   : PHP_INT_MAX;

    // Start async import (saves cursor, processing happens via polling)
    $result = TelegramImporter::startImport($data['messages'], (int) $dateFrom, (int) $dateTo);
    Logger::admin(Logger::INFO, 'JSON import started', $result);
    jsonOk($result);
}

/**
 * GET /api/admin.php?action=import_status
 */
function actionImportStatus(): void
{
    require_once TELEPAGE_ROOT . '/app/TelegramImporter.php';
    jsonOk(TelegramImporter::getStatus());
}

/**
 * POST /api/admin.php?action=process_ai_queue
 * Processes the next N contents in the AI queue (max 5).
 */
function actionProcessAiQueue(): void
{
    requirePost();
    require_once TELEPAGE_ROOT . '/app/AIService.php';

    $limit = 5; // Reduced batch to avoid timeouts on shared hosting
    $queue = DB::fetchAll(
        'SELECT id FROM contents WHERE ai_processed=0 AND is_deleted=0 LIMIT :lim',
        [':lim' => $limit]
    );

    $processed = 0;
    $errors = [];
    foreach ($queue as $item) {
        $result = AIService::processContent((int) $item['id']);
        if ($result) {
            $processed++;
        }
    }

    // Read latest AI errors from log to display in console
    $recentErrors = DB::fetchAll(
        "SELECT message, context, created_at FROM logs WHERE category='ai' AND level='error' ORDER BY created_at DESC LIMIT 3"
    );

    $remaining = (int) DB::fetchScalar('SELECT COUNT(*) FROM contents WHERE ai_processed=0 AND is_deleted=0');

    jsonOk([
        'processed'     => $processed,
        'remaining'     => $remaining,
        'status'        => $remaining > 0 ? 'running' : 'done',
        'recent_errors' => $recentErrors,
    ]);
}

// -----------------------------------------------------------------------
// HISTORY SCANNER actions
// -----------------------------------------------------------------------

/** GET /api/admin.php?action=scan_get_start */
function actionScanGetStart(): void
{
    require_once TELEPAGE_ROOT . '/app/HistoryScanner.php';
    jsonOk(HistoryScanner::getStartId());
}

/** POST /api/admin.php?action=scan_batch  body: {start_id, batch_size} */
function actionScanBatch(): void
{
    requirePost();
    require_once TELEPAGE_ROOT . '/app/HistoryScanner.php';

    set_time_limit(300); // 5 min — enough for 2000 IDs with rate-limit pauses

    $body      = getJsonBody();
    $startId   = (int) ($body['start_id']   ?? 0);
    $batchSize = min(100, max(10, (int) ($body['batch_size'] ?? 50)));

    if ($startId <= 0) {
        jsonError(400, 'Invalid start_id');
    }

    $result = HistoryScanner::scanBatch($startId, $batchSize);

    // Tell the UI how many contents are pending AI so it can auto-trigger
    // the AI queue as a separate async call (avoids set_time_limit exhaustion
    // when Gemini calls are stacked right after a long scan).
    $config = Config::get();
    $result['ai_pending'] = 0;
    if (!empty($config['ai_enabled']) && (!empty($config['ai_auto_tag']) || !empty($config['ai_auto_summary']))) {
        $result['ai_pending'] = (int) DB::fetchScalar(
            'SELECT COUNT(*) FROM contents WHERE ai_processed=0 AND is_deleted=0'
        );
    }

    Logger::admin(Logger::INFO, 'scan_batch', [
        'start'      => $startId,
        'imported'   => $result['imported'],
        'skipped'    => $result['skipped'],
        'ai_pending' => $result['ai_pending'],
    ]);
    jsonOk($result);
}

/** GET /api/admin.php?action=scan_stats */
function actionScanStats(): void
{
    require_once TELEPAGE_ROOT . '/app/HistoryScanner.php';
    jsonOk(HistoryScanner::getStats());
}

/**
 * POST /api/admin.php?action=requeue_ai
 * Re-queues all contents for AI processing (useful after enabling AI post-import).
 */
function actionRequeueAi(): void
{
    requirePost();
    $count = DB::query(
        "UPDATE contents SET ai_processed=0 WHERE is_deleted=0 AND (ai_processed=1 OR ai_processed=2)"
    )->rowCount();
    Logger::admin(Logger::INFO, 'Requeue AI', ['count' => $count]);
    jsonOk(['requeued' => $count]);
}

// -----------------------------------------------------------------------
// AI CONTENT TEST — single content debug
// -----------------------------------------------------------------------
function actionTestAiContent(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) jsonError(400, 'Missing id parameter');
    require_once TELEPAGE_ROOT . '/app/AIService.php';
    jsonOk(AIService::testContent($id));
}

// -----------------------------------------------------------------------
// AI MODELS LIST — diagnostics
// -----------------------------------------------------------------------
function actionListAiModels(): void
{
    $config = Config::get();
    $key    = $config['gemini_api_key'] ?? '';
    if (empty($key)) { jsonError(400, 'Gemini API key not configured'); }

    // Try both endpoints
    $endpoints = [
        'v1beta' => 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $key,
        'v1'     => 'https://generativelanguage.googleapis.com/v1/models?key=' . $key,
    ];

    $results = [];
    foreach ($endpoints as $version => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>true]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($body, true);
        $models = [];
        if ($code === 200 && !empty($data['models'])) {
            foreach ($data['models'] as $m) {
                if (in_array('generateContent', $m['supportedGenerationMethods'] ?? [])) {
                    $models[] = $m['name'];
                }
            }
        }
        $results[$version] = ['http_code' => $code, 'models_supporting_generateContent' => $models, 'error' => $data['error']['message'] ?? null];
    }
    jsonOk($results);
}

// -----------------------------------------------------------------------
// Helper: Rate limiting
// -----------------------------------------------------------------------

function checkRateLimit(string $ip, string $endpoint, int $maxHits, int $windowSeconds): bool
{
    try {
        $rec = DB::fetchOne(
            'SELECT hit_count, window_start FROM rate_limits WHERE ip=:ip AND endpoint=:ep',
            [':ip' => $ip, ':ep' => $endpoint]
        );

        $now = time();

        if ($rec) {
            $age = $now - (int) $rec['window_start'];
            if ($age > $windowSeconds) {
                // New window
                DB::query(
                    'UPDATE rate_limits SET hit_count=1, window_start=:now WHERE ip=:ip AND endpoint=:ep',
                    [':now' => $now, ':ip' => $ip, ':ep' => $endpoint]
                );
                return true;
            }
            if ((int) $rec['hit_count'] >= $maxHits) {
                return false;
            }
            DB::query(
                'UPDATE rate_limits SET hit_count=hit_count+1 WHERE ip=:ip AND endpoint=:ep',
                [':ip' => $ip, ':ep' => $endpoint]
            );
        } else {
            DB::query(
                'INSERT INTO rate_limits (ip, endpoint, hit_count, window_start) VALUES (:ip,:ep,1,:now)',
                [':ip' => $ip, ':ep' => $endpoint, ':now' => $now]
            );
        }

        return true;
    } catch (Throwable) {
        return true; // On DB error, do not block
    }
}

// -----------------------------------------------------------------------
// Helper: Input / Output
// -----------------------------------------------------------------------

function requirePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError(405, 'Method Not Allowed');
    }
}

/** Reads the JSON or form-encoded body. */
function getJsonBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        return json_decode($raw ?: '{}', true) ?? [];
    }
    return $_POST;
}

/** Reads a parameter from JSON body or POST. */
function bodyParam(string $key): mixed
{
    static $body = null;
    if ($body === null) {
        $body = getJsonBody();
    }
    return $body[$key] ?? $_POST[$key] ?? null;
}

function jsonOk(array $data): never
{
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(int $code, string $message): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function clientIp(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
        $v = $_SERVER[$h] ?? '';
        if ($v) {
            $ip = trim(explode(',', $v)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

function detectBaseUrl(): string
{
    $is_https = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                 ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ||
                 ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on' ||
                 ($_SERVER['HTTP_X_FORWARDED_PORT'] ?? '') === '443');

    $scheme = $is_https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Calculate path from the physical file location
    // TELEPAGE_ROOT is defined as dirname(__DIR__) by the including file
    // so we can derive the web path from the difference between DOCUMENT_ROOT and __DIR__
    $docRoot  = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    $fileDir  = rtrim(dirname(__FILE__), '/'); // = /path/server/telepage/api
    $appRoot  = rtrim(dirname($fileDir), '/'); // = /path/server/telepage

    if (!empty($docRoot) && str_starts_with($appRoot, $docRoot)) {
        $webPath = substr($appRoot, strlen($docRoot));
    } else {
        // Fallback: use SCRIPT_NAME going 3 levels up (api/admin.php → root)
        $script  = $_SERVER['SCRIPT_NAME'] ?? '';
        $webPath = rtrim(dirname(dirname($script)), '/');
    }

    $base = $scheme . '://' . $host . $webPath;
    return str_replace('http://', 'https://', $base);
}
