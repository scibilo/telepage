<?php

/**
 * TELEPAGE — api/admin.php
 * API admin autenticata. Richiede sessione PHP attiva.
 *
 * Ogni action è loggata con IP e timestamp (RB-13).
 * reset_database richiede confirm='RESET' (RB-05).
 * Soft delete: is_deleted=1 (RB-08).
 * File fisici eliminati solo con cleanup (RB-09).
 */

declare(strict_types=1);

define('TELEPAGE_ROOT', dirname(__DIR__));

require_once TELEPAGE_ROOT . '/app/Config.php';
require_once TELEPAGE_ROOT . '/app/DB.php';
require_once TELEPAGE_ROOT . '/app/Logger.php';
require_once TELEPAGE_ROOT . '/app/TelegramBot.php';
require_once TELEPAGE_ROOT . '/app/Scraper.php';

// -----------------------------------------------------------------------
// 1. Autenticazione — prima istruzione (Sezione 10)
// -----------------------------------------------------------------------

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
// Sessione isolata per installazione: evita che due istanze su stesso dominio condividano la sessione
session_name('tp_' . substr(sha256(TELEPAGE_ROOT), 0, 12));
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    jsonError(401, 'Non autenticato');
}

// Scadenza sessione 8h (RB-14)
if (!empty($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 28800) {
    session_destroy();
    jsonError(401, 'Sessione scaduta');
}

// Rate limiting admin: max 10 req/min per IP (Sezione 10)
$ip = clientIp();
if (!checkRateLimit($ip, 'admin', 10, 60)) {
    jsonError(429, 'Rate limit superato — max 10 req/min');
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
    // Catalogazione Manuale
    'tags_list'        => actionTagsList(),
    'save_tag'         => actionSaveTag(),
    'delete_tag'       => actionDeleteTag(),
    'contents_list'    => actionContentsList(),
    'get_content'      => actionGetContent(),
    'save_content'     => actionSaveContent(),
    default            => jsonError(400, "Action sconosciuta: {$action}"),
};

// -----------------------------------------------------------------------
// Actions
// -----------------------------------------------------------------------

/**
 * GET /api/admin.php?action=stats
 * Statistiche generali del sistema.
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
 * Soft delete (RB-08): imposta is_deleted=1, non elimina file.
 */
function actionDeleteContent(): void
{
    requirePost();
    $id = (int) (bodyParam('id') ?? 0);
    if ($id <= 0) {
        jsonError(400, 'ID non valido');
    }

    DB::query('UPDATE contents SET is_deleted=1, updated_at=CURRENT_TIMESTAMP WHERE id=:id', [':id' => $id]);
    Logger::admin(Logger::INFO, 'Soft delete contenuto', ['content_id' => $id]);
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
        jsonError(400, 'ID non valido');
    }

    DB::query('UPDATE contents SET is_deleted=0, updated_at=CURRENT_TIMESTAMP WHERE id=:id', [':id' => $id]);
    Logger::admin(Logger::INFO, 'Ripristino contenuto', ['content_id' => $id]);
    jsonOk(['restored' => $id]);
}

/**
 * POST /api/admin.php?action=cleanup
 * Elimina definitivamente contenuti nel cestino + file fisici (RB-09).
 */
function actionCleanup(): void
{
    requirePost();

    $deleted = DB::fetchAll('SELECT id, image FROM contents WHERE is_deleted=1');
    $count   = 0;
    $files   = 0;

    foreach ($deleted as $row) {
        // Elimina file fisico se esiste e si trova in assets/media/
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

    // Elimina record
    $stmt = DB::query('DELETE FROM contents WHERE is_deleted=1');
    $count = $stmt->rowCount();

    Logger::admin(Logger::INFO, 'Cleanup eseguito', ['records' => $count, 'files' => $files]);
    jsonOk(['deleted_records' => $count, 'deleted_files' => $files]);
}

/**
 * POST /api/admin.php?action=reset_database  body: {confirm: 'RESET'}
 * Reset completo — richiede conferma testuale (RB-05).
 */
function actionResetDatabase(): void
{
    requirePost();

    $confirm = bodyParam('confirm') ?? '';
    if ($confirm !== 'RESET') {
        Logger::admin(Logger::WARNING, 'Tentativo reset_database senza conferma');
        jsonError(400, "Campo confirm deve valere esattamente 'RESET'");
    }

    // Log PRIMA di eliminare
    Logger::admin(Logger::WARNING, 'RESET DATABASE eseguito');

    $pdo = DB::get();

    // Elimina tutti i file media
    $mediaDir = TELEPAGE_ROOT . '/assets/media';
    if (is_dir($mediaDir)) {
        $files = glob($mediaDir . '/*');
        foreach ($files ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    // Svuota tutte le tabelle
    $tables = ['content_tags', 'contents', 'tags', 'import_cursors', 'logs', 'rate_limits'];
    foreach ($tables as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }
    $pdo->exec('DELETE FROM sqlite_sequence WHERE name != "admins"');
    $pdo->exec('VACUUM');

    jsonOk(['reset' => true]);
}

/**
 * POST /api/admin.php?action=upload_logo
 * Riceve un'immagine, la ridimensiona (GD) e la salva come logo.png
 */
function actionUploadLogo(): void
{
    if (empty($_FILES['logo'])) jsonError(400, 'Nessun file caricato');
    $file = $_FILES['logo'];

    if ($file['error'] !== UPLOAD_ERR_OK) jsonError(400, 'Errore upload file');

    // Verifica tipo
    $mime = mime_content_type($file['tmp_name']);
    if (!str_starts_with($mime, 'image/')) jsonError(400, 'Il file non è un\'immagine valida');

    // Ridimensionamento con GD
    if (!extension_loaded('gd')) {
        // Fallback: copia secca se GD manca
        $dest = 'assets/img/logo.png';
        move_uploaded_file($file['tmp_name'], TELEPAGE_ROOT . '/' . $dest);
        jsonOk(['path' => $dest, 'note' => 'GD non disponibile, file copiato senza ridimensionamento']);
    }

    $src = null;
    if ($mime === 'image/jpeg') $src = imagecreatefromjpeg($file['tmp_name']);
    elseif ($mime === 'image/png') $src = imagecreatefrompng($file['tmp_name']);
    elseif ($mime === 'image/webp') $src = imagecreatefromwebp($file['tmp_name']);
    else jsonError(400, 'Formato immagine non supportato (usa JPG, PNG o WEBP)');

    if (!$src) jsonError(500, 'Impossibile elaborare l\'immagine');

    $w = imagesx($src);
    $h = imagesy($src);
    $max = 256;

    // Ridimensiona proporzionalmente senza canvas quadrato (preserva aspect ratio)
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

    // Favicon quadrata con logo centrato proporzionalmente
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

    // Aggiorna timestamp in config per cache-busting
    Config::update(['logo_updated' => time()]);

    jsonOk(['path' => 'assets/img/logo.png']);
}

/**
 * POST /api/admin.php?action=factory_reset  body: {confirm: 'FACTORY RESET'}
 * Ripristino di fabbrica: elimina TUTTO, inclusa la configurazione.
 */
function actionFactoryReset(): void
{
    requirePost();
    $confirm = bodyParam('confirm') ?? '';
    if ($confirm !== 'FACTORY RESET') {
        jsonError(400, "Conferma errata. Scrivi 'FACTORY RESET'");
    }

    Logger::admin(Logger::WARNING, 'FACTORY RESET AVVIATO');

    // 1. Telegram Webhook (tenta di staccarlo prima di perdere il token)
    try {
        TelegramBot::deleteWebhook();
    } catch (Throwable) {}

    // 2. Clear Media
    $mediaDir = TELEPAGE_ROOT . '/assets/media';
    if (is_dir($mediaDir)) {
        $files = glob($mediaDir . '/*');
        foreach ($files ?: [] as $f) if (is_file($f)) @unlink($f);
    }

    // 3. Clear Database file
    $dbPath = Config::getKey('db_path', '');
    if (!empty($dbPath) && file_exists($dbPath)) {
        // Chiudi connessione PDO
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

    // Distruggi sessione
    session_destroy();

    jsonOk(['factory_reset' => true]);
}

/**
 * POST /api/admin.php?action=fix_images  body: {limit: 5}
 * Re-scraping immagini mancanti (RB-09 logic: non elimina, corregge).
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

    Logger::admin(Logger::INFO, 'Fix immagini', ['checked' => count($rows), 'fixed' => $fixed]);
    jsonOk(['checked' => count($rows), 'fixed' => $fixed]);
}

/**
 * POST /api/admin.php?action=backup
 * Crea copia SQLite in data/backups/ (RB-12).
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

    // Nome file: backup_YYYY-MM-DD_HHmmss.sqlite (RB-12)
    $filename   = 'backup_' . date('Y-m-d_His') . '.sqlite';
    $backupPath = $backupDir . '/' . $filename;

    if (!copy($dbPath, $backupPath)) {
        jsonError(500, 'Impossibile creare il backup');
    }

    Logger::admin(Logger::INFO, 'Backup creato', ['file' => $filename]);
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
    Logger::admin(Logger::INFO, 'DB ottimizzato');
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
            } else {
                $updates[$key] = (string) $body[$key];
            }
        }
    }

    Config::update($updates);
    Logger::admin(Logger::INFO, 'Impostazioni salvate', array_keys($updates));
    jsonOk(['saved' => array_keys($updates)]);
}

/**
 * POST /api/admin.php?action=set_webhook
 * Imposta o verifica il webhook Telegram.
 */
function actionSetWebhook(): void
{
    requirePost();

    $config  = Config::get();
    $baseUrl = $config['custom_webhook_url'] ?? bodyParam('base_url') ?? detectBaseUrl();

    // Auto-salva custom_webhook_url se non ancora configurato
    if (empty($config['custom_webhook_url'])) {
        $detected = detectBaseUrl();
        Config::update(['custom_webhook_url' => $detected]);
        $baseUrl = $detected;
        Logger::admin(Logger::INFO, 'custom_webhook_url auto-salvato', ['url' => $detected]);
    }
    
    // Smart HTTPS Force: Se l'URL è http, prova comunque https per Telegram
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
    // Includi l'URL usato nella risposta per debug visibile nella console admin
    $result['webhook_url_used'] = $webhookUrl;
    jsonOk($result);
}

/**
 * POST /api/admin.php?action=sync_telegram
 * Stacca webhook → getUpdates → riattacca.
 */
function actionSyncTelegram(): void
{
    requirePost();

    $config = Config::get();

    // 1. Stacca webhook
    TelegramBot::deleteWebhook();

    // 2. getUpdates
    $updates = TelegramBot::getUpdates(0, 100);
    $processed = 0;

    foreach ($updates as $update) {
        if (TelegramBot::handleUpdate($update)) {
            $processed++;
        }
    }

    // 3. Riattacca SEMPRE il webhook dopo getUpdates
    $savedUrl   = $config['custom_webhook_url'] ?? '';
    $baseUrl    = !empty($savedUrl) ? rtrim($savedUrl, '/') : detectBaseUrl();
    $webhookUrl = $baseUrl . '/api/webhook.php';
    $secret     = $config['webhook_secret'] ?? '';
    if (!empty($secret)) {
        // Riattacca sempre — se savedUrl è vuoto usa detectBaseUrl (che ora forza HTTPS)
        TelegramBot::setWebhook($webhookUrl, $secret);
    } else {
        Logger::admin(Logger::WARNING, 'sync_telegram: webhook_secret vuoto, webhook non riattaccato');
    }

    Logger::admin(Logger::INFO, 'Sync Telegram', ['updates' => count($updates), 'processed' => $processed]);
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
    $slug  = trim($data['slug'] ?? '');
    $color = trim($data['color'] ?? '#3b82f6');
    $src   = trim($data['source'] ?? 'manual');

    if (empty($name) || empty($slug)) {
        jsonError(400, 'Nome e Slug sono obbligatori');
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

    Logger::admin(Logger::INFO, 'Tag salvato', ['id' => $id, 'name' => $name]);
    jsonOk(['id' => $id]);
}

/** POST /api/admin.php?action=delete_tag  body: {id} */
function actionDeleteTag(): void
{
    requirePost();
    $id = (int) (bodyParam('id') ?? 0);
    if ($id <= 0) jsonError(400, 'ID non valido');

    DB::query('DELETE FROM tags WHERE id = :id', [':id' => $id]);
    Logger::admin(Logger::WARNING, 'Tag eliminato', ['id' => $id]);
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
    if ($id <= 0) jsonError(400, 'ID non valido');

    $content = DB::fetchOne('SELECT * FROM contents WHERE id = :id', [':id' => $id]);
    if (!$content) jsonError(404, 'Contenuto non trovato');

    // Recupera tag associati
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
    if ($id <= 0) jsonError(400, 'ID non valido');

    $title = trim($data['title'] ?? '');
    $desc  = trim($data['description'] ?? '');
    $tags  = (array) ($data['tags'] ?? []);

    DB::beginTransaction();
    try {
        // Aggiorna metadati
        DB::query(
            'UPDATE contents SET title=:t, description=:d, updated_at=CURRENT_TIMESTAMP WHERE id=:id',
            [':t' => $title, ':d' => $desc, ':id' => $id]
        );

        // Sincronizza tag
        DB::query('DELETE FROM content_tags WHERE content_id = :id', [':id' => $id]);
        foreach ($tags as $tagId) {
            DB::query(
                'INSERT OR IGNORE INTO content_tags (content_id, tag_id) VALUES (:cid, :tid)',
                [':cid' => $id, ':tid' => (int) $tagId]
            );
        }

        DB::commit();
        Logger::admin(Logger::INFO, 'Contenuto modificato manualmente', ['id' => $id]);
        jsonOk(['id' => $id]);
    } catch (Throwable $e) {
        DB::rollBack();
        jsonError(500, 'Errore salvataggio: ' . $e->getMessage());
    }
}

/**
 * POST /api/admin.php?action=import_json  multipart con file 'json_file'
 */
function actionImportJson(): void
{
    requirePost();

    // Deve essere inclusa la classe TelegramImporter
    require_once TELEPAGE_ROOT . '/app/TelegramImporter.php';

    $file = $_FILES['json_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        jsonError(400, 'File non ricevuto o errore upload');
    }

    // Verifica MIME reale
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['application/json', 'text/plain', 'text/json'])) {
        jsonError(400, "Tipo file non valido: {$mime}. Carica un file .json");
    }

    // Leggi e parse
    $raw  = file_get_contents($file['tmp_name']);
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['messages'])) {
        jsonError(400, 'File JSON non valido o non è un export Telegram Desktop');
    }

    $dateFrom = isset($_POST['date_from']) ? strtotime($_POST['date_from']) : 0;
    $dateTo   = isset($_POST['date_to'])   ? strtotime($_POST['date_to'])   : PHP_INT_MAX;

    // Avvia import asincrono (salva cursor, il processing avverrà via polling)
    $result = TelegramImporter::startImport($data['messages'], (int) $dateFrom, (int) $dateTo);
    Logger::admin(Logger::INFO, 'Import JSON avviato', $result);
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
 * Processa prossimi N contenuti in coda AI (max 5).
 */
function actionProcessAiQueue(): void
{
    requirePost();
    require_once TELEPAGE_ROOT . '/app/AIService.php';

    $limit = 5; // Batch ridotto per evitare timeout su hosting condivisi
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

    // Leggi gli ultimi errori AI dal log per mostrarli nella console
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

    set_time_limit(120);

    $body      = getJsonBody();
    $startId   = (int) ($body['start_id']   ?? 0);
    $batchSize = min(100, max(10, (int) ($body['batch_size'] ?? 50)));

    if ($startId <= 0) {
        jsonError(400, 'start_id non valido');
    }

    $result = HistoryScanner::scanBatch($startId, $batchSize);
    Logger::admin(Logger::INFO, 'scan_batch', [
        'start'    => $startId,
        'imported' => $result['imported'],
        'skipped'  => $result['skipped'],
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
 * Rimette in coda AI tutti i contenuti (utile dopo aver abilitato AI post-import).
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
// AI CONTENT TEST — debug singolo contenuto
// -----------------------------------------------------------------------
function actionTestAiContent(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) jsonError(400, 'Parametro id mancante');
    require_once TELEPAGE_ROOT . '/app/AIService.php';
    jsonOk(AIService::testContent($id));
}

// -----------------------------------------------------------------------
// AI MODELS LIST — diagnostica
// -----------------------------------------------------------------------
function actionListAiModels(): void
{
    $config = Config::get();
    $key    = $config['gemini_api_key'] ?? '';
    if (empty($key)) { jsonError(400, 'Gemini API key non configurata'); }

    // Prova entrambi gli endpoint
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
                // Nuova finestra
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
        return true; // In caso di errore DB, non bloccare
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

/** Legge il body JSON o form-encoded. */
function getJsonBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        return json_decode($raw ?: '{}', true) ?? [];
    }
    return $_POST;
}

/** Legge un parametro da body JSON o POST. */
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

    // Calcola il path dalla posizione fisica del file
    // TELEPAGE_ROOT è definito come dirname(__DIR__) da chi include questo file
    // quindi possiamo derivare il path web dalla differenza tra DOCUMENT_ROOT e __DIR__
    $docRoot  = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    $fileDir  = rtrim(dirname(__FILE__), '/'); // = /path/server/telepage/api
    $appRoot  = rtrim(dirname($fileDir), '/'); // = /path/server/telepage

    if (!empty($docRoot) && str_starts_with($appRoot, $docRoot)) {
        $webPath = substr($appRoot, strlen($docRoot));
    } else {
        // Fallback: usa SCRIPT_NAME con 3 livelli sopra (api/admin.php → root)
        $script  = $_SERVER['SCRIPT_NAME'] ?? '';
        $webPath = rtrim(dirname(dirname($script)), '/');
    }

    $base = $scheme . '://' . $host . $webPath;
    return str_replace('http://', 'https://', $base);
}
