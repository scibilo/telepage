<?php

/**
 * TELEPAGE — api/admin/tags.php
 *
 * Admin API module: tag management.
 * Included by api/admin.php after auth, CSRF, and rate-limit checks.
 *
 * Actions: tags_list, save_tag, delete_tag
 */

declare(strict_types=1);

match ($action) {
    'tags_list'  => actionTagsList(),
    'save_tag'   => actionSaveTag(),
    'delete_tag' => actionDeleteTag(),
    default      => jsonError(400, "Unknown action: {$action}"),
};

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
