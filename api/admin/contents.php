<?php

/**
 * TELEPAGE — api/admin/contents.php
 *
 * Admin API module: content management, import, and AI queue.
 * Included by api/admin.php after auth, CSRF, and rate-limit checks.
 *
 * Actions: delete_content, restore_content, contents_list, get_content,
 *          save_content, import_json, import_status, process_ai_queue,
 *          requeue_ai, test_ai_content, list_ai_models
 */

declare(strict_types=1);

match ($action) {
    'delete_content'   => actionDeleteContent(),
    'restore_content'  => actionRestoreContent(),
    'contents_list'    => actionContentsList(),
    'get_content'      => actionGetContent(),
    'save_content'     => actionSaveContent(),
    'import_json'      => actionImportJson(),
    'import_status'    => actionImportStatus(),
    'process_ai_queue' => actionProcessAiQueue(),
    'requeue_ai'       => actionRequeueAi(),
    'test_ai_content'  => actionTestAiContent(),
    'list_ai_models'   => actionListAiModels(),
    default            => jsonError(400, "Unknown action: {$action}"),
};

// -----------------------------------------------------------------------

/** POST /api/admin.php?action=delete_content  body: {id: N} */
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

/** POST /api/admin.php?action=restore_content  body: {id: N} */
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
        $where[]      = 'id IN (SELECT rowid FROM contents_fts WHERE contents_fts MATCH :q)';
        $params[':q'] = fts5EscapeQuery($search);
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
        DB::query(
            'UPDATE contents SET title=:t, description=:d, updated_at=CURRENT_TIMESTAMP WHERE id=:id',
            [':t' => $title, ':d' => $desc, ':id' => $id]
        );

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
        Logger::admin(Logger::ERROR, 'save_content failed', [
            'id'   => $id ?? null,
            'exc'  => get_class($e),
            'msg'  => $e->getMessage(),
            'file' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);
        jsonError(500, 'Save failed — see admin log for details');
    }
}

/** POST /api/admin.php?action=import_json  multipart with file 'json_file' */
function actionImportJson(): void
{
    requirePost();

    $file = $_FILES['json_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        jsonError(400, 'File not received or upload error');
    }

    $maxImportBytes = 50 * 1024 * 1024;

    if (($file['size'] ?? 0) > $maxImportBytes) {
        jsonError(413, 'File too large (max ' . (int)($maxImportBytes / 1024 / 1024) . ' MB). Split the export by date range in Telegram Desktop and import in batches.');
    }

    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['application/json', 'text/plain', 'text/json'])) {
        jsonError(400, "Invalid file type: {$mime}. Please upload a .json file");
    }

    $raw = file_get_contents($file['tmp_name'], false, null, 0, $maxImportBytes + 1);
    if ($raw === false) {
        jsonError(500, 'Could not read uploaded file');
    }
    if (strlen($raw) > $maxImportBytes) {
        jsonError(413, 'File too large (max ' . (int)($maxImportBytes / 1024 / 1024) . ' MB). Split the export by date range in Telegram Desktop and import in batches.');
    }

    $data = json_decode($raw, true);
    unset($raw);
    if (!is_array($data) || !isset($data['messages'])) {
        jsonError(400, 'Invalid JSON file or not a Telegram Desktop export');
    }

    $dateFrom = !empty($_POST['date_from'])
        ? strtotime($_POST['date_from'] . ' 00:00:00')
        : 0;
    $dateTo   = !empty($_POST['date_to'])
        ? strtotime($_POST['date_to']   . ' 23:59:59')
        : PHP_INT_MAX;

    $result = TelegramImporter::startImport($data['messages'], (int) $dateFrom, (int) $dateTo);
    Logger::admin(Logger::INFO, 'JSON import started', $result);
    jsonOk($result);
}

/** GET /api/admin.php?action=import_status */
function actionImportStatus(): void
{
    jsonOk(TelegramImporter::getStatus());
}

/** POST /api/admin.php?action=process_ai_queue */
function actionProcessAiQueue(): void
{
    requirePost();

    $limit = 5;
    $queue = DB::fetchAll(
        'SELECT id FROM contents WHERE ai_processed=0 AND is_deleted=0 LIMIT :lim',
        [':lim' => $limit]
    );

    $processed = 0;
    foreach ($queue as $item) {
        $result = AIService::processContent((int) $item['id']);
        if ($result) {
            $processed++;
        }
    }

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

/** POST /api/admin.php?action=requeue_ai */
function actionRequeueAi(): void
{
    requirePost();
    $count = DB::query(
        "UPDATE contents SET ai_processed=0 WHERE is_deleted=0 AND (ai_processed=1 OR ai_processed=2)"
    )->rowCount();
    Logger::admin(Logger::INFO, 'Requeue AI', ['count' => $count]);
    jsonOk(['requeued' => $count]);
}

/** GET /api/admin.php?action=test_ai_content&id=N */
function actionTestAiContent(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) jsonError(400, 'Missing id parameter');
    jsonOk(AIService::testContent($id));
}

/** GET /api/admin.php?action=list_ai_models */
function actionListAiModels(): void
{
    $config = Config::get();
    $key    = $config['gemini_api_key'] ?? '';
    if (empty($key)) { jsonError(400, 'Gemini API key not configured'); }

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
        $results[$version] = [
            'http_code' => $code,
            'models_supporting_generateContent' => $models,
            'error' => $data['error']['message'] ?? null,
        ];
    }
    jsonOk($results);
}
