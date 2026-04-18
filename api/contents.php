<?php

/**
 * TELEPAGE — api/contents.php
 * Public contents API. No authentication required.
 * Rate limiting: 60 req/min per IP.
 *
 * GET /api/contents.php
 * Parameters: page, q, tag, type, from, to, per_page, semantic
 */

declare(strict_types=1);

define('TELEPAGE_ROOT', dirname(__DIR__));

require_once TELEPAGE_ROOT . '/app/bootstrap.php';
Bootstrap::init(Bootstrap::MODE_JSON);

require_once TELEPAGE_ROOT . '/app/Config.php';
require_once TELEPAGE_ROOT . '/app/DB.php';
require_once TELEPAGE_ROOT . '/app/Logger.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *'); // Public API

// -----------------------------------------------------------------------
// Rate limiting: 60 req/min per IP
// -----------------------------------------------------------------------

$ip = clientIp();
if (!checkPublicRateLimit($ip)) {
    http_response_code(429);
    echo json_encode([
        'ok'    => false,
        'error' => 'Rate limit exceeded. Max 60 requests/minute.',
    ]);
    exit;
}

// -----------------------------------------------------------------------
// Verify installation
// -----------------------------------------------------------------------

if (!Config::isInstalled()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Telepage non ancora installato']);
    exit;
}

// -----------------------------------------------------------------------
// Input parameters (sanitised)
// -----------------------------------------------------------------------

$config  = Config::get();
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(96, max(1, (int) ($_GET['per_page'] ?? $config['items_per_page'] ?? 12)));
$offset  = ($page - 1) * $perPage;

$search   = trim($_GET['q']        ?? '');
$tagSlug  = trim($_GET['tag']      ?? '');
$typeFilter = trim($_GET['type']   ?? '');
$dateFrom   = trim($_GET['from']   ?? '');
$dateTo     = trim($_GET['to']     ?? '');
$semantic   = ((int) ($_GET['semantic'] ?? 0)) === 1;

// -----------------------------------------------------------------------
// Build query
// -----------------------------------------------------------------------

$where  = ['c.is_deleted = 0'];
$params = [];

// Full-text search on title, description, ai_summary
if ($search !== '') {
    $where[]         = '(c.title LIKE :q OR c.description LIKE :q OR c.ai_summary LIKE :q)';
    $params[':q']    = '%' . $search . '%';
}

// Tag filter (JOIN)
$joinTags = '';
if ($tagSlug !== '') {
    $joinTags          = 'JOIN content_tags ct ON ct.content_id = c.id
                          JOIN tags t ON t.id = ct.tag_id AND t.slug = :tag_slug';
    $params[':tag_slug'] = $tagSlug;
}

// Content type filter
if ($typeFilter !== '') {
    $where[]             = 'c.content_type = :type';
    $params[':type']     = $typeFilter;
}

// Date filter
if ($dateFrom !== '') {
    $where[]              = 'DATE(c.created_at) >= :date_from';
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[]             = 'DATE(c.created_at) <= :date_to';
    $params[':date_to']  = $dateTo;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// -----------------------------------------------------------------------
// Count total
// -----------------------------------------------------------------------

$total = (int) DB::fetchScalar(
    "SELECT COUNT(DISTINCT c.id) FROM contents c {$joinTags} {$whereSql}",
    $params
);

// -----------------------------------------------------------------------
// Semantic AI search — fallback if < 3 results
// -----------------------------------------------------------------------

$semanticUsed = false;
if ($semantic && $search !== '' && $total < 3) {
    // Phase 2: AIService::searchSemantic() — placeholder for now
    // Phase 2 will be implemented with Gemini
    $semanticUsed = false;
}

// -----------------------------------------------------------------------
// Fetch contents
// -----------------------------------------------------------------------

$rows = DB::fetchAll(
    "SELECT DISTINCT c.id, c.url, c.title, c.description, c.ai_summary,
            c.image, c.image_source, c.favicon, c.content_type,
            c.source_domain, c.created_at, c.ai_processed
       FROM contents c
     {$joinTags}
     {$whereSql}
     ORDER BY c.created_at DESC
     LIMIT :limit OFFSET :offset",
    array_merge($params, [':limit' => $perPage, ':offset' => $offset])
);

// -----------------------------------------------------------------------
// Fetch tags for each content
// -----------------------------------------------------------------------

$contentIds = array_column($rows, 'id');
$tagsMap    = [];

if (!empty($contentIds)) {
    $placeholders = implode(',', array_fill(0, count($contentIds), '?'));
    $tagRows = DB::fetchAll(
        "SELECT ct.content_id, t.name, t.slug, t.color
           FROM content_tags ct
           JOIN tags t ON t.id = ct.tag_id
          WHERE ct.content_id IN ({$placeholders})
          ORDER BY t.usage_count DESC",
        $contentIds
    );

    foreach ($tagRows as $tr) {
        $tagsMap[$tr['content_id']][] = [
            'name'  => $tr['name'],
            'slug'  => $tr['slug'],
            'color' => $tr['color'],
        ];
    }
}

// -----------------------------------------------------------------------
// Build response
// -----------------------------------------------------------------------

$data = [];
foreach ($rows as $row) {
    $data[] = [
        'id'           => (int) $row['id'],
        'url'          => $row['url'],
        'title'        => $row['title'],
        'description'  => $row['description'],
        'ai_summary'   => $row['ai_summary'],
        'image'        => $row['image'],
        'image_source' => $row['image_source'],
        'favicon'      => $row['favicon'],
        'content_type' => $row['content_type'],
        'source_domain'=> $row['source_domain'],
        'tags'         => $tagsMap[$row['id']] ?? [],
        'created_at'   => $row['created_at'],
        'ai_processed' => (int) $row['ai_processed'],
    ];
}

$totalPages = (int) ceil($total / $perPage);

echo json_encode([
    'ok'   => true,
    'data' => $data,
    'meta' => [
        'total'         => $total,
        'page'          => $page,
        'per_page'      => $perPage,
        'pages'         => $totalPages,
        'has_next'      => $page < $totalPages,
        'semantic_used' => $semanticUsed,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------

function checkPublicRateLimit(string $ip): bool
{
    try {
        $endpoint = 'public_api';
        $rec = DB::fetchOne(
            'SELECT hit_count, window_start FROM rate_limits WHERE ip=:ip AND endpoint=:ep',
            [':ip' => $ip, ':ep' => $endpoint]
        );

        $now = time();

        if ($rec) {
            $age = $now - (int) $rec['window_start'];
            if ($age > 60) {
                DB::query(
                    'UPDATE rate_limits SET hit_count=1, window_start=:now WHERE ip=:ip AND endpoint=:ep',
                    [':now' => $now, ':ip' => $ip, ':ep' => $endpoint]
                );
                return true;
            }
            if ((int) $rec['hit_count'] >= 60) {
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
        return true;
    }
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
