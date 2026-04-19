<?php
/**
 * TELEPAGE — admin/_auth.php
 * Shared authentication guard for all admin pages.
 * Include at the top of every admin page (after defining TELEPAGE_ROOT).
 */

if (!defined('TELEPAGE_ROOT')) {
    define('TELEPAGE_ROOT', dirname(__DIR__));
}

require_once TELEPAGE_ROOT . '/app/bootstrap.php';
Bootstrap::init(Bootstrap::MODE_HTML);

require_once TELEPAGE_ROOT . '/app/Config.php';
require_once TELEPAGE_ROOT . '/app/DB.php';
require_once TELEPAGE_ROOT . '/app/Logger.php';
require_once TELEPAGE_ROOT . '/app/Str.php';

if (!Config::isInstalled()) {
    header('Location: ../install/index.php');
    exit;
}

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', '28800');
// Isolated session per installation (prevents cross-login between instances on the same domain)
session_name('tp_' . substr(hash('sha256', TELEPAGE_ROOT), 0, 16));
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

// Session expiry: 8 hours
if (!empty($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 28800) {
    session_destroy();
    header('Location: login.php?expired=1');
    exit;
}

// Global CSRF token for admin pages
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$GLOBALS['csrf_token'] = $_SESSION['csrf_token'];
$GLOBALS['admin_user'] = $_SESSION['admin_user'] ?? 'Admin';
$config = Config::get();
$GLOBALS['app_name'] = $config['app_name'] ?? 'Telepage';
$GLOBALS['theme_color'] = Str::safeHexColor($config['theme_color'] ?? null);

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function adminHeader(string $pageTitle, string $activePage = ''): void {
    $appName    = e($GLOBALS['app_name']);
    $themeColor = e($GLOBALS['theme_color']);
    $adminUser  = e($GLOBALS['admin_user']);
    $csrf       = e($GLOBALS['csrf_token']);
    $apiJsVer   = @filemtime(TELEPAGE_ROOT . '/assets/js/api.js') ?: time();
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{$pageTitle} — {$appName} Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
        --bg: #0a0f1e; --bg2: #111827; --surface: #1a2236; --border: #2a3654;
        --text: #e2e8f0; --muted: #8899bb; --accent: {$themeColor};
        --success: #22c55e; --warning: #f59e0b; --error: #ef4444;
        --sidebar-w: 240px; --radius: 10px;
    }
    body { background: var(--bg); color: var(--text); font-family: 'Inter', system-ui, sans-serif; font-size: 14px; line-height: 1.6; display: flex; min-height: 100vh; }
    /* Sidebar */
    .sidebar { width: var(--sidebar-w); background: var(--bg2); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; bottom: 0; left: 0; z-index: 100; }
    .sidebar-brand { padding: 20px 20px 16px; border-bottom: 1px solid var(--border); }
    .sidebar-logo { font-size: 22px; font-weight: 700; background: linear-gradient(135deg,#a5beff,#c4b5fd); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; display:flex; align-items:center; gap:8px; text-decoration:none; }
    .sidebar-logo span { font-size: 24px; -webkit-text-fill-color: initial; }
    .sidebar-sub { font-size: 11px; color: var(--muted); margin-top: 2px; }
    nav.sidebar-nav { flex: 1; padding: 16px 0; overflow-y: auto; }
    .nav-section { font-size: 10px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; padding: 8px 20px 4px; }
    .nav-link { display: flex; align-items: center; gap: 10px; padding: 9px 20px; color: var(--muted); text-decoration: none; border-left: 3px solid transparent; transition: all .15s; font-size: 13px; font-weight: 500; }
    .nav-link:hover { color: var(--text); background: rgba(255,255,255,.04); }
    .nav-link.active { color: var(--accent); background: rgba(79,126,255,.08); border-left-color: var(--accent); }
    .nav-icon { font-size: 16px; width: 20px; text-align: center; }
    .sidebar-footer { padding: 16px 20px; border-top: 1px solid var(--border); }
    .sidebar-user { font-size: 12px; color: var(--muted); margin-bottom: 8px; }
    /* Main */
    .main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .topbar { padding: 16px 28px; border-bottom: 1px solid var(--border); background: var(--bg2); display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
    .topbar-title { font-size: 18px; font-weight: 700; }
    .topbar-actions { display: flex; gap: 10px; align-items: center; }
    .content { padding: 28px; flex: 1; }
    /* Cards */
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; margin-bottom: 20px; }
    .card-title { font-size: 15px; font-weight: 700; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
    .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
    /* Stat badge */
    .stat { text-align: center; padding: 16px; }
    .stat-val { font-size: 32px; font-weight: 700; line-height: 1; }
    .stat-label { font-size: 12px; color: var(--muted); margin-top: 6px; }
    /* Buttons */
    .btn { display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; border-radius: 7px; font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; border: none; text-decoration: none; transition: all .15s; }
    .btn-primary { background: var(--accent); color: #fff; }
    .btn-primary:hover { filter: brightness(1.1); }
    .btn-danger { background: rgba(239,68,68,.15); color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }
    .btn-danger:hover { background: rgba(239,68,68,.25); }
    .btn-outline { background: transparent; color: var(--muted); border: 1px solid var(--border); }
    .btn-outline:hover { border-color: var(--accent); color: var(--accent); }
    .btn-sm { padding: 5px 12px; font-size: 12px; }
    /* Badge */
    .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
    .badge-success { background: rgba(34,197,94,.15); color: var(--success); }
    .badge-error   { background: rgba(239,68,68,.15);  color: var(--error); }
    .badge-warning { background: rgba(245,158,11,.15); color: var(--warning); }
    .badge-info    { background: rgba(79,126,255,.15); color: var(--accent); }
    /* Console */
    .console { background: #080e1b; border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; font-family: 'JetBrains Mono', monospace; font-size: 12px; height: 200px; overflow-y: auto; }
    .console-line { padding: 1px 0; }
    .console-line.ok  { color: #86efac; }
    .console-line.err { color: #fca5a5; }
    .console-line.warn { color: #fcd34d; }
    .console-line.info { color: #a5beff; }
    /* Responsive */
    @media (max-width: 900px) { .grid-3 { grid-template-columns: 1fr; } }
    @media (max-width: 700px) {
        .sidebar { transform: translateX(-100%); }
        .main { margin-left: 0; }
    }
    </style>
    <meta name="csrf" content="{$csrf}">
    <script src="../assets/js/api.js?v={$apiJsVer}"></script>
</head>
<body>
<aside class="sidebar">
    <div class="sidebar-brand">
        <a href="index.php" class="sidebar-logo"><span>📡</span>{$appName}</a>
        <div class="sidebar-sub">Admin Panel</div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section">Main</div>
        <a href="index.php"    class="nav-link HTML;
HTML;
    $acts = ['dashboard' => 'index.php', 'contents' => 'contents.php', 'tags' => 'tags.php', 'import' => 'import.php', 'scanner' => 'scanner.php', 'settings' => 'settings.php'];
    $icons = ['dashboard' => '📊', 'contents' => '📂', 'tags' => '🏷️', 'import' => '📥', 'scanner' => '📜', 'settings' => '⚙️'];
    $labels = ['dashboard' => 'Dashboard', 'contents' => 'Contents', 'tags' => 'Tags', 'import' => 'Import', 'scanner' => 'History Scanner', 'settings' => 'Settings'];
    foreach ($acts as $key => $href) {
        $cls = $activePage === $key ? ' active' : '';
        echo "<a href=\"{$href}\" class=\"nav-link{$cls}\"><span class=\"nav-icon\">{$icons[$key]}</span>{$labels[$key]}</a>\n";
    }
    echo <<<HTML
        <div class="nav-section">Site</div>
        <a href="../index.php" class="nav-link" target="_blank"><span class="nav-icon">🌐</span>View site</a>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-user">👤 {$adminUser}</div>
        <a href="logout.php" class="btn btn-outline btn-sm" style="width:100%;justify-content:center">Logout</a>
    </div>
</aside>
<div class="main">
<div class="topbar">
    <div class="topbar-title">{$pageTitle}</div>
    <div class="topbar-actions">
        <a href="../index.php" class="btn btn-outline btn-sm" target="_blank">🌐 Site</a>
    </div>
</div>
<div class="content">
HTML;
}

function adminFooter(): void {
    echo '</div></div></body></html>';
}
