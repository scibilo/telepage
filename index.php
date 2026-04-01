<?php
/**
 * TELEPAGE — index.php
 * Frontend pubblico principale.
 * Caricamento asincrono contenuti via AJAX (api/contents.php).
 */

declare(strict_types=1);

define('TELEPAGE_ROOT', __DIR__);

// Cache e sessione PRIMA di qualsiasi output
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_name('tp_' . substr(md5(TELEPAGE_ROOT), 0, 12));
session_start();

// No-cache: forza sempre contenuto fresco (sidebar admin, tag aggiornati)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('Surrogate-Control: no-store'); // Varnish/CDN
header('X-Accel-Expires: 0');          // Nginx proxy cache
header('Vary: Cookie, Accept-Encoding'); // LiteSpeed/Cloudflare

require_once TELEPAGE_ROOT . '/app/Config.php';
require_once TELEPAGE_ROOT . '/app/DB.php';

// Se non installato, vai al wizard
if (!Config::isInstalled()) {
    header('Location: install/index.php');
    exit;
}

// Controlla se l'admin è loggato (per cestino e link admin)
$isAdmin = !empty($_SESSION['admin_logged_in']);

$config = Config::get();
$langCode = $config['language'] ?? 'it';

// Carica lingua
$langFile = TELEPAGE_ROOT . "/lang/{$langCode}.php";
$lang = file_exists($langFile) ? require $langFile : require TELEPAGE_ROOT . '/lang/it.php';

// SEO e Meta
$appName = $config['app_name'] ?? 'Telepage';
$themeColor = $config['theme_color'] ?? '#3b82f6';
$logoPath = $config['logo_path'] ?? 'assets/img/logo.png';

// Estrai parametri per stato iniziale (se presenti nell'URL)
$initialTag  = trim($_GET['tag'] ?? '');
$initialType = trim($_GET['type'] ?? '');
$initialSearch = trim($_GET['q'] ?? '');

// Fetch tags per la sidebar (quelli con usage > 0)
// Mostra solo tag che hanno contenuti attivi associati
// Genera colore vivace deterministico dal nome tag (usato se color è il default grigio)
function tagColor(string $name, string $dbColor): string {
    if ($dbColor !== '#6c757d' && $dbColor !== '') return $dbColor;
    $colors = ['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#10b981','#06b6d4','#f97316','#84cc16','#e11d48','#6366f1'];
    // Stesso algoritmo del JS: somma charcode
    $hash = 0;
    for ($i = 0; $i < mb_strlen($name); $i++) {
        $hash = (($hash << 5) - $hash) + mb_ord(mb_substr($name, $i, 1));
        $hash = $hash & 0x7FFFFFFF; // mantieni positivo
    }
    return $colors[$hash % count($colors)];
}

$tags = DB::fetchAll('SELECT t.name, t.slug, t.color, COUNT(ct.content_id) as usage_count FROM tags t JOIN content_tags ct ON ct.tag_id = t.id JOIN contents c ON c.id = ct.content_id WHERE c.is_deleted = 0 GROUP BY t.id ORDER BY usage_count DESC, t.name ASC LIMIT 100');

?>
<!DOCTYPE html>
<html lang="<?= $langCode ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title><?= htmlspecialchars($appName) ?> — Telegram Hub</title>
    <meta name="description" content="<?= htmlspecialchars($lang['footer_text']) ?>">
    
    <!-- Theme & Icons -->
    <meta name="theme-color" content="<?= $themeColor ?>">
<?php
$faviconPath = file_exists(TELEPAGE_ROOT . '/assets/img/favicon-32.png')
    ? 'assets/img/favicon-32.png'
    : $logoPath;
$faviconV = $config['logo_updated'] ?? filemtime(TELEPAGE_ROOT . '/' . $faviconPath);
?>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $faviconPath ?>?v=<?= $faviconV ?>">
    <link rel="icon" type="image/png" sizes="64x64" href="assets/img/favicon-64.png?v=<?= $faviconV ?>">
    <link rel="shortcut icon" href="<?= $faviconPath ?>?v=<?= $faviconV ?>">
    
    <!-- Deregistra Service Worker se presente (causa problemi di cache) -->
    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistrations().then(function(registrations) {
            for (let reg of registrations) {
                reg.unregister();
            }
        });
        caches.keys().then(function(names) {
            for (let name of names) caches.delete(name);
        });
    }
    </script>
    <!-- Cache busting -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <!-- Styles -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(TELEPAGE_ROOT.'/assets/css/style.css') ?>">
    <?php if ($isAdmin): ?>
    <meta name="csrf" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <?php endif; ?>
    
    <!-- Scripting Config -->
    <script>
        window.TELEPAGE_CONFIG = {
            isAdmin: <?= $isAdmin ? 'true' : 'false' ?>,
            appName: "<?= addslashes($appName) ?>",
            lang: <?= json_encode($lang) ?>,
            initialTag: "<?= addslashes($initialTag) ?>",
            initialType: "<?= addslashes($initialType) ?>",
            initialSearch: "<?= addslashes($initialSearch) ?>",
            paginationType: "<?= $config['pagination_type'] ?? 'classic' ?>",
            accentColor: "<?= $themeColor ?>"
        };
    </script>
</head>
<?php $siteTheme = $config['site_theme'] ?? 'dark'; ?>
<body class="theme-<?= htmlspecialchars($siteTheme) ?>" style="--accent-color: <?= $themeColor ?>; --accent-glow: rgba(<?= hexToRgb($themeColor) ?>, 0.3);">

    <div class="app-layout">
        
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <header class="header">
                <a href="index.php" class="brand">
                    <?php if (!empty($config['logo_updated']) && file_exists(TELEPAGE_ROOT . '/assets/img/logo.png')): ?>
                    <img src="assets/img/logo.png?v=<?= $config['logo_updated'] ?>"
                         alt="<?= htmlspecialchars($appName) ?>"
                         class="brand-logo">
                    <?php else: ?>
                    <div class="brand-icon">📡</div>
                    <?php endif; ?>
                    <div class="brand-name"><?= htmlspecialchars($appName) ?></div>
                </a>

                <div class="search-wrapper">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="search-input" class="search-input" 
                           placeholder="<?= htmlspecialchars($lang['search_placeholder']) ?>"
                           value="<?= htmlspecialchars($initialSearch) ?>">
                </div>
            </header>

            <!-- Tag Cloud -->
            <section>
                <h3 class="section-title"><?= htmlspecialchars($lang['tags']) ?></h3>
                <?php if (count($tags) > 8): ?>
                <input type="text" id="tag-search" class="search-input" placeholder="Cerca tag..."
                       style="font-size:12px;padding:6px 10px;margin-bottom:8px;width:100%"
                       oninput="filterTagList(this.value)">
                <?php endif; ?>
                <div class="tag-cloud" id="tag-cloud">
                    <a href="#" class="tag-btn <?= !$initialTag ? 'active' : '' ?>" data-tag=""><?= htmlspecialchars($lang['filter_all']) ?></a>
                    <?php foreach($tags as $i => $t): ?>
                        <a href="#" class="tag-btn <?= $initialTag === $t['slug'] ? 'active' : '' ?> <?= $i >= 12 ? 'tag-overflow' : '' ?>"
                           data-tag="<?= htmlspecialchars($t['slug']) ?>"
                           data-name="<?= htmlspecialchars($t['name']) ?>"
                           style="--tag-color: <?= tagColor($t['name'], $t['color']) ?><?= $i >= 12 ? ';display:none' : '' ?>;">
                            #<?= htmlspecialchars($t['name']) ?>
                            <span style="opacity:.5;font-size:.85em">(<?= (int)$t['usage_count'] ?>)</span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (count($tags) > 12): ?>
                    <a href="#" id="tag-show-more" class="tag-btn" style="background:rgba(255,255,255,.05);font-size:11px"
                       onclick="showAllTags(event)">+<?= count($tags) - 12 ?> altri</a>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Type Filters -->
            <section>
                <h3 class="section-title"><?= htmlspecialchars($lang['filter_type']) ?></h3>
                <div class="type-filter" id="type-filters">
                    <a href="#" class="filter-item <?= !$initialType ? 'active' : '' ?>" data-type="">
                        <span>🌐</span> <?= htmlspecialchars($lang['filter_all']) ?>
                    </a>
                    <a href="#" class="filter-item <?= $initialType === 'link' ? 'active' : '' ?>" data-type="link">
                        <span>🔗</span> <?= htmlspecialchars($lang['type_link']) ?>
                    </a>
                    <a href="#" class="filter-item <?= $initialType === 'youtube' ? 'active' : '' ?>" data-type="youtube">
                        <span>📺</span> <?= htmlspecialchars($lang['type_youtube']) ?>
                    </a>
                    <a href="#" class="filter-item <?= $initialType === 'tiktok' ? 'active' : '' ?>" data-type="tiktok">
                        <span>🎵</span> <?= htmlspecialchars($lang['type_tiktok']) ?>
                    </a>
                    <a href="#" class="filter-item <?= $initialType === 'photo' ? 'active' : '' ?>" data-type="photo">
                        <span>🖼️</span> <?= htmlspecialchars($lang['type_photo']) ?>
                    </a>
                    <a href="#" class="filter-item <?= $initialType === 'video' ? 'active' : '' ?>" data-type="video">
                        <span>🎥</span> <?= htmlspecialchars($lang['type_video']) ?>
                    </a>
                </div>
            </section>

            <!-- Date filter -->
            <section>
                <h3 class="section-title">📅 Data</h3>
                <div style="display:flex;flex-direction:column;gap:8px">
                    <input type="date" id="filter-date-from" class="search-input" style="font-size:12px;padding:8px" placeholder="Da">
                    <input type="date" id="filter-date-to" class="search-input" style="font-size:12px;padding:8px" placeholder="A">
                    <button class="btn-reset-filters" onclick="resetFilters()">
                        ✕ Reset tutti i filtri
                    </button>
                </div>
            </section>

            <footer style="margin-top:40px;color:var(--text-muted);font-size:11px;display:flex;flex-direction:column;gap:8px">
                <a href="admin/<?= $isAdmin ? 'index' : 'login' ?>.php"
                   style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:rgba(255,255,255,.04);color:var(--text-muted);border:1px solid var(--border);border-radius:6px;text-decoration:none;font-size:11px;font-weight:500;width:fit-content"
                   onmouseover="this.style.borderColor='var(--accent-color)';this.style.color='var(--accent-color)'"
                   onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text-muted)'">
                    🔒 <?= $isAdmin ? 'Pannello Admin' : 'Login Admin' ?>
                </a>
                <p style="margin:0">&copy; <?= date('Y') ?> <?= htmlspecialchars($appName) ?></p>
                <p style="margin:0;opacity:.5">
                    Powered by <a href="https://github.com/telepage" target="_blank"
                       style="color:inherit;text-decoration:none;font-weight:600"
                       onmouseover="this.style.color='var(--accent-color)'"
                       onmouseout="this.style.color='inherit'">Telepage</a>
                </p>
            </footer>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <div class="container">
                

                <div id="results-info" style="margin-bottom: 24px; font-size: 14px; color: var(--text-muted); display: none;">
                    <span id="results-count">0</span> <?= htmlspecialchars($lang['results_found']) ?>
                </div>

                <!-- Grid -->
                <div class="content-grid" id="content-grid">
                    <!-- Cards will be injected here via JS -->
                </div>

                <!-- Pagination / Load More -->
                <div id="pagination-container" class="pagination"></div>


            </div>
        </main>
    </div>

    <!-- Scripts -->
    <script src="assets/js/app.js?v=<?= filemtime(TELEPAGE_ROOT.'/assets/js/app.js') ?>"></script>

</body>
</html>
<?php
/**
 * Helper to convert hex to rgb for CSS variable (glow)
 */
function hexToRgb(string $hex): string {
    $hex = str_replace("#", "", $hex);
    if(strlen($hex) == 3) {
        $r = hexdec(substr($hex, 0, 1).substr($hex, 0, 1));
        $g = hexdec(substr($hex, 1, 1).substr($hex, 1, 1));
        $b = hexdec(substr($hex, 2, 1).substr($hex, 2, 1));
    } else {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
    }
    return "$r, $g, $b";
}
?>
