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

require_once TELEPAGE_ROOT . '/vendor/autoload.php';

Bootstrap::init(Bootstrap::MODE_JSON);

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
// Input parameters (sanitised + validated)
// -----------------------------------------------------------------------
//
// Every query parameter is clamped or whitelisted before it reaches
// the SQL layer. Nothing here is unsafe against injection (all values
// go through PDO named placeholders below), but without caps an
// attacker with authenticated or unauthenticated access to this
// endpoint can amplify a single HTTP request into arbitrarily heavy
// database work:
//
//   GET /api/contents.php?q=<100KB of 'A'>
//     → '%<100KB>%' LIKE scan across three columns
//
// Rate limit above (60 req/min/IP) bounds the frequency; these caps
// bound the per-request work. Both layers are needed.
//
// Invalid values (tag with punctuation, type not in the enum, date
// not matching yyyy-mm-dd) are silently dropped rather than 400'd —
// legitimate callers don't hit this path, and 400 would give
// fingerprinting signal to probes.

const MAX_SEARCH_LEN = 100;     // 'ristrutturazione edilizia 2024' style queries
const MAX_TAG_LEN    = 50;      // matches tags table slug cap

// Whitelist of content types emitted by Scraper::detectContentType()
// and TelegramBot::resolveMedia(). Anything else is not a real type
// in this install.
const CONTENT_TYPE_ENUM = [
    'link', 'photo', 'video', 'document', 'note',
    'youtube', 'tiktok', 'instagram', 'telegram_post',
];

$config  = Config::get();
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(96, max(1, (int) ($_GET['per_page'] ?? $config['items_per_page'] ?? 12)));
$offset  = ($page - 1) * $perPage;

// Search: clamp length, strip control chars (would only ever reach here
// from a handcrafted request — browsers don't emit them).
$search = trim((string) ($_GET['q'] ?? ''));
if ($search !== '') {
    $search = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $search) ?? $search;
    $search = mb_strimwidth($search, 0, MAX_SEARCH_LEN, '', 'UTF-8');
}

// Tag: must look like a slug (lowercase alnum + dash + underscore).
// Str::slugify()'s output always matches this. Anything else means a
// caller sending us something we'd never ourselves produce.
$tagSlug = trim((string) ($_GET['tag'] ?? ''));
if ($tagSlug !== '' && !preg_match('/^[a-z0-9_-]{1,' . MAX_TAG_LEN . '}$/', $tagSlug)) {
    $tagSlug = '';
}

// Type: whitelist of the enum actually used in the DB.
$typeFilter = trim((string) ($_GET['type'] ?? ''));
if ($typeFilter !== '' && !in_array($typeFilter, CONTENT_TYPE_ENUM, true)) {
    $typeFilter = '';
}

// Dates: ISO yyyy-mm-dd only. We don't fight off an obviously wrong
// year like 9999-12-31 here — the query still parametrises, just
// returns nothing — but we do block arbitrary-length strings.
$dateFrom = trim((string) ($_GET['from'] ?? ''));
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
$dateTo = trim((string) ($_GET['to'] ?? ''));
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$semantic = ((int) ($_GET['semantic'] ?? 0)) === 1;

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
// Helpers — moved to app/Http.php so api/health.php can share them.
// -----------------------------------------------------------------------
