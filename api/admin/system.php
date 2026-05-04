<?php

/**
 * TELEPAGE — api/admin/system.php
 *
 * Admin API module: system management.
 * Included by api/admin.php after auth, CSRF, and rate-limit checks.
 *
 * Actions: stats, logs, cleanup, reset_database, upload_logo,
 *          fix_images, backup, optimize, save_settings,
 *          set_webhook, sync_telegram, factory_reset
 */

declare(strict_types=1);

match ($action) {
    'stats'          => actionStats(),
    'logs'           => actionLogs(),
    'cleanup'        => actionCleanup(),
    'reset_database' => actionResetDatabase(),
    'upload_logo'    => actionUploadLogo(),
    'fix_images'     => actionFixImages(),
    'backup'         => actionBackup(),
    'optimize'       => actionOptimize(),
    'save_settings'  => actionSaveSettings(),
    'set_webhook'    => actionSetWebhook(),
    'sync_telegram'  => actionSyncTelegram(),
    'factory_reset'  => actionFactoryReset(),
    default          => jsonError(400, "Unknown action: {$action}"),
};

// -----------------------------------------------------------------------

/** GET /api/admin.php?action=stats */
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

/** GET /api/admin.php?action=logs&level=error&category=ai&page=1 */
function actionLogs(): void
{
    $level    = $_GET['level']    ?? null;
    $category = $_GET['category'] ?? null;
    $page     = max(1, (int) ($_GET['page'] ?? 1));
    $perPage  = min(100, max(10, (int) ($_GET['per_page'] ?? 50)));

    $result = Logger::fetch($level, $category, $page, $perPage);
    jsonOk($result);
}

/** POST /api/admin.php?action=cleanup */
function actionCleanup(): void
{
    requirePost();

    $deleted = DB::fetchAll('SELECT id, image FROM contents WHERE is_deleted=1');
    $count   = 0;
    $files   = 0;

    foreach ($deleted as $row) {
        $image = $row['image'] ?? '';
        if (!empty($image) && str_starts_with($image, 'assets/media/')) {
            $fullPath = TELEPAGE_ROOT . '/' . $image;
            $realPath = realpath($fullPath);
            $mediaDir = realpath(TELEPAGE_ROOT . '/assets/media');
            if ($realPath && $mediaDir && str_starts_with($realPath, $mediaDir)) {
                if (unlink($realPath)) {
                    $files++;
                }
            }
        }
    }

    $stmt  = DB::query('DELETE FROM contents WHERE is_deleted=1');
    $count = $stmt->rowCount();

    $cutoff = time() - 3600;
    $rlStmt = DB::query(
        'DELETE FROM rate_limits WHERE window_start < :cutoff',
        [':cutoff' => $cutoff]
    );
    $rateLimitsDeleted = $rlStmt->rowCount();

    Logger::admin(Logger::INFO, 'Cleanup done', [
        'records'     => $count,
        'files'       => $files,
        'rate_limits' => $rateLimitsDeleted,
    ]);
    jsonOk([
        'deleted_records'     => $count,
        'deleted_files'       => $files,
        'deleted_rate_limits' => $rateLimitsDeleted,
    ]);
}

/** POST /api/admin.php?action=reset_database  body: {confirm: 'RESET'} */
function actionResetDatabase(): void
{
    requirePost();

    $confirm = bodyParam('confirm') ?? '';
    if ($confirm !== 'RESET') {
        Logger::admin(Logger::WARNING, 'reset_database attempt without confirmation');
        jsonError(400, "confirm field must be exactly 'RESET'");
    }

    Logger::admin(Logger::WARNING, 'DATABASE RESET executed');

    $pdo = DB::get();

    $mediaDir = TELEPAGE_ROOT . '/assets/media';
    if (is_dir($mediaDir)) {
        $files = glob($mediaDir . '/*');
        foreach ($files ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    $tables = ['content_tags', 'contents', 'tags', 'import_cursors', 'logs', 'rate_limits'];
    foreach ($tables as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }
    $pdo->exec('DELETE FROM sqlite_sequence WHERE name != "admins"');
    $pdo->exec('VACUUM');

    session_regenerate_id(true);

    jsonOk(['reset' => true]);
}

/** POST /api/admin.php?action=upload_logo */
function actionUploadLogo(): void
{
    if (empty($_FILES['logo'])) jsonError(400, 'No file uploaded');
    $file = $_FILES['logo'];

    if ($file['error'] !== UPLOAD_ERR_OK) jsonError(400, 'File upload error');

    $mime = mime_content_type($file['tmp_name']);
    if (!str_starts_with($mime, 'image/')) jsonError(400, 'The file is not a valid image');

    if (!extension_loaded('gd')) {
        jsonError(501, 'Server is missing the GD extension; logo upload is disabled. Install php-gd to enable.');
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
    Config::update(['logo_updated' => time()]);
    jsonOk(['path' => 'assets/img/logo.png']);
}

/** POST /api/admin.php?action=factory_reset  body: {confirm: 'FACTORY RESET'} */
function actionFactoryReset(): void
{
    requirePost();
    $confirm = bodyParam('confirm') ?? '';
    if ($confirm !== 'FACTORY RESET') {
        jsonError(400, "Wrong confirmation. Type 'FACTORY RESET'");
    }

    Logger::admin(Logger::WARNING, 'FACTORY RESET INITIATED');

    try {
        TelegramBot::deleteWebhook();
    } catch (Throwable) {}

    $mediaDir = TELEPAGE_ROOT . '/assets/media';
    if (is_dir($mediaDir)) {
        $files = glob($mediaDir . '/*');
        foreach ($files ?: [] as $f) if (is_file($f)) @unlink($f);
    }

    $dbPath = Config::getKey('db_path', '');
    if (!empty($dbPath) && file_exists($dbPath)) {
        DB::reset();
        @unlink($dbPath);
        @unlink($dbPath . '-wal');
        @unlink($dbPath . '-shm');
    }

    $configFile = TELEPAGE_ROOT . '/config.json';
    if (file_exists($configFile)) {
        @unlink($configFile);
    }

    session_destroy();
    jsonOk(['factory_reset' => true]);
}

/** POST /api/admin.php?action=fix_images  body: {limit: 5} */
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

/** POST /api/admin.php?action=backup */
function actionBackup(): void
{
    requirePost();

    $dbPath    = Config::getKey('db_path', '');
    $backupDir = TELEPAGE_ROOT . '/data/backups';

    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0750, true);
        file_put_contents($backupDir . '/.htaccess', "Deny from all\n");
    }

    $filename   = 'backup_' . date('Y-m-d_His') . '.sqlite';
    $backupPath = $backupDir . '/' . $filename;

    if (!copy($dbPath, $backupPath)) {
        jsonError(500, 'Could not create backup');
    }

    Logger::admin(Logger::INFO, 'Backup created', ['file' => $filename]);
    jsonOk(['file' => $filename, 'size' => filesize($backupPath)]);
}

/** POST /api/admin.php?action=optimize */
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

/** POST /api/admin.php?action=save_settings */
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
            if (in_array($key, ['ai_enabled', 'ai_auto_tag', 'ai_auto_summary', 'download_media'])) {
                $updates[$key] = (bool) $body[$key];
            } elseif ($key === 'items_per_page') {
                $updates[$key] = in_array((int) $body[$key], [12, 24, 48, 96]) ? (int) $body[$key] : 12;
            } elseif ($key === 'pagination_type') {
                $updates[$key] = in_array($body[$key], ['classic', 'enhanced', 'loadmore', 'infinite'])
                    ? $body[$key]
                    : 'classic';
            } elseif ($key === 'theme_color') {
                $updates[$key] = Str::safeHexColor((string) $body[$key]);
            } elseif ($key === 'app_name') {
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

/** POST /api/admin.php?action=set_webhook */
function actionSetWebhook(): void
{
    requirePost();

    $config  = Config::get();
    $baseUrl = $config['custom_webhook_url'] ?? bodyParam('base_url') ?? detectBaseUrl();

    if (empty($config['custom_webhook_url'])) {
        $detected = detectBaseUrl();
        Config::update(['custom_webhook_url' => $detected]);
        $baseUrl = $detected;
        Logger::admin(Logger::INFO, 'custom_webhook_url auto-saved', ['url' => $detected]);
    }

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
    $result['webhook_url_used'] = $webhookUrl;
    jsonOk($result);
}

/** POST /api/admin.php?action=sync_telegram */
function actionSyncTelegram(): void
{
    requirePost();

    $config = Config::get();

    TelegramBot::deleteWebhook();

    $updates   = TelegramBot::getUpdates(0, 100);
    $processed = 0;

    foreach ($updates as $update) {
        if (TelegramBot::handleUpdate($update)) {
            $processed++;
        }
    }

    $savedUrl   = $config['custom_webhook_url'] ?? '';
    $baseUrl    = !empty($savedUrl) ? rtrim($savedUrl, '/') : detectBaseUrl();
    $webhookUrl = $baseUrl . '/api/webhook.php';
    $secret     = $config['webhook_secret'] ?? '';
    if (!empty($secret)) {
        TelegramBot::setWebhook($webhookUrl, $secret);
    } else {
        Logger::admin(Logger::WARNING, 'sync_telegram: webhook_secret empty, webhook not re-attached');
    }

    Logger::admin(Logger::INFO, 'Telegram sync', ['updates' => count($updates), 'processed' => $processed]);
    jsonOk(['updates_received' => count($updates), 'processed' => $processed]);
}
