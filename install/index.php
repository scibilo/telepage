<?php
/**
 * TELEPAGE — install/index.php
 * 5-step Installation Wizard (Standalone file).
 * International Version (POST-Redirect-GET pattern).
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

define('TELEPAGE_ROOT', dirname(__DIR__));

require_once TELEPAGE_ROOT . '/app/Config.php';
require_once TELEPAGE_ROOT . '/app/DB.php';
require_once TELEPAGE_ROOT . '/app/Security/Session.php';

// -----------------------------------------------------------------------
// Session management
// -----------------------------------------------------------------------
Session::start();
$hasConfig    = file_exists(dirname(__DIR__) . '/config.json');
$currentStep  = (int)($_GET['step'] ?? 1);

if (!$hasConfig && $currentStep === 1 && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Nuova installazione: pulisci sessione residua
    session_unset();
    session_destroy();
    Session::start();
    $_SESSION['install'] = [];

    // Detach any residual webhook if there is an old token in the session or previous config
    // (impedisce che Telegram invii dati durante la reinstallazione)
    // Cannot do anything without a token — the user will have to wait for the old webhook to expire (max 60s)
}
if (!isset($_SESSION['install'])) {
    $_SESSION['install'] = [];
}

// -----------------------------------------------------------------------
// Guard — status page if already installed
// -----------------------------------------------------------------------
$config = Config::get();
$isInstalled = !empty($config['installed']) && $config['installed'] === true;

$errors  = [];
$success = [];

// If already installed and no reset/status request, redirect to the site
if ($isInstalled && !isset($_POST['action']) && !isset($_GET['status'])) {
    header('Location: ../index.php');
    exit;
}

// Handle Reset Action
if ($isInstalled && isset($_POST['action']) && $_POST['action'] === 'hard_reset') {
    if (!file_exists(TELEPAGE_ROOT . '/reset.txt')) {
        $errors[] = 'Security Challenge Failed: Create "reset.txt" in root.';
    } else {
        factoryReset();
        @unlink(TELEPAGE_ROOT . '/reset.txt');
        header('Location: index.php?step=1&reset=ok');
        exit;
    }
}

function factoryReset(): void {
    $config = Config::get();
    $dbPath = $config['db_path'] ?? (TELEPAGE_ROOT . '/data/app.sqlite');
    if (file_exists($dbPath)) @unlink($dbPath);
    if (file_exists($dbPath . '-wal')) @unlink($dbPath . '-wal');
    if (file_exists($dbPath . '-shm')) @unlink($dbPath . '-shm');
    $mediaDir = TELEPAGE_ROOT . '/assets/media';
    if (is_dir($mediaDir)) {
        $files = glob($mediaDir . '/*');
        foreach ($files as $f) if (is_file($f)) @unlink($f);
    }
    $configFile = TELEPAGE_ROOT . '/config.json';
    if (file_exists($configFile)) @unlink($configFile);
    $_SESSION['install'] = [];
    session_unset();
}

// -----------------------------------------------------------------------
// Step Logic (POST-Redirect-GET)
// -----------------------------------------------------------------------
$step = (int) ($_GET['step'] ?? 1);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isInstalled) {
    $postStep = (int) ($_POST['step'] ?? 0);
    
    if ($postStep === 1) {
        $_SESSION['install']['site_language'] = $_POST['site_language'] ?? 'en';
        $_SESSION['install']['app_name']      = trim($_POST['app_name'] ?? 'Telepage');
        $_SESSION['install']['theme_color']    = trim($_POST['theme_color'] ?? '#3b82f6');
        header('Location: index.php?step=2');
        exit;
    }

    if ($postStep === 2) {
        $dbPath = trim($_POST['db_path'] ?? (TELEPAGE_ROOT . '/data/app.sqlite'));
        $dbDir  = dirname($dbPath);
        if (strpos(realpath($dbDir) ?: $dbDir, TELEPAGE_ROOT) !== 0) {
            $_SESSION['install_error'] = 'Invalid path: Database must be inside project root.';
            header('Location: index.php?step=2');
            exit;
        }
        if (!is_dir($dbDir)) @mkdir($dbDir, 0755, true);
        try {
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->exec('PRAGMA journal_mode=WAL');
            $_SESSION['install']['db_path'] = $dbPath;
            header('Location: index.php?step=3');
            exit;
        } catch (Exception $e) {
            $_SESSION['install_error'] = 'DB Error: ' . $e->getMessage();
            header('Location: index.php?step=2');
            exit;
        }
    }

    if ($postStep === 3) {
        $botToken  = trim($_POST['bot_token'] ?? '');
        $channelId = trim($_POST['channel_id'] ?? '');
        if (empty($botToken)) {
            $_SESSION['install_error'] = 'Bot Token is required.';
            header('Location: index.php?step=3');
            exit;
        }
        $verify = telegramRequest($botToken, 'getMe');
        if (!$verify['ok']) {
            $_SESSION['install_error'] = 'Invalid Bot Token: ' . ($verify['description'] ?? 'error');
            header('Location: index.php?step=3');
            exit;
        }
        $_SESSION['install']['bot_token']      = $botToken;
        $_SESSION['install']['channel_id']     = $channelId;
        $_SESSION['install']['webhook_secret'] = bin2hex(random_bytes(24));
        $_SESSION['install']['gemini_api_key'] = trim($_POST['gemini_api_key'] ?? '');
        header('Location: index.php?step=4');
        exit;
    }

    if ($postStep === 4) {
        $username = trim($_POST['username'] ?? 'admin');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';
        if (strlen($password) < 8) {
            $_SESSION['install_error'] = 'Password troppo corta (min 8 caratteri).';
            header('Location: index.php?step=4');
            exit;
        }
        if ($password !== $confirm) {
            $_SESSION['install_error'] = 'Le password non coincidono.';
            header('Location: index.php?step=4');
            exit;
        }
        $_SESSION['install']['admin_username']      = $username;
        $_SESSION['install']['admin_password_hash'] = password_hash($password, PASSWORD_BCRYPT);
        header('Location: index.php?step=5');
        exit;
    }

    if ($postStep === 5 && ($_POST['action'] ?? '') === 'finalize') {
        $res = finalizeInstallation();
        if (isset($res['error'])) {
            $_SESSION['install_error'] = $res['error'];
            header('Location: index.php?step=5');
        } else {
            $_SESSION['install_success'] = true;
            $_SESSION['install_base_url'] = $res['base_url'];
            // Pulizia dati sensibili dalla sessione (DOPO finalizeInstallation che ne ha bisogno)
            unset($_SESSION['install']['admin_password_hash']);
            // bot_token e webhook_secret rimangono per eventuali debug, verranno puliti al logout
            header('Location: index.php?step=5');
        }
        exit;
    }
}

// Consume errors/success from session
if (isset($_SESSION['install_error'])) {
    $errors[] = $_SESSION['install_error'];
    unset($_SESSION['install_error']);
}
if (isset($_GET['reset']) && $_GET['reset'] === 'ok') {
    $success[] = 'System reset successfully. Start fresh!';
}

// -----------------------------------------------------------------------
// Helpers & Sub-logic
// -----------------------------------------------------------------------
function checkRequirements(): array {
    $checks = [];
    $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
    $checks[] = ['label' => 'PHP ' . PHP_VERSION, 'ok' => $phpOk, 'detail' => $phpOk ? 'OK' : '8.1+ req'];
    $pdoOk = extension_loaded('pdo_sqlite');
    $checks[] = ['label' => 'PDO SQLite', 'ok' => $pdoOk, 'detail' => $pdoOk ? 'OK' : 'Missing'];
    $curlOk = extension_loaded('curl');
    $checks[] = ['label' => 'cURL', 'ok' => $curlOk, 'detail' => $curlOk ? 'OK' : 'Missing'];
    $dataDir = TELEPAGE_ROOT . '/data';
    if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
    $dataWrite = is_writable($dataDir);
    $checks[] = ['label' => 'data/ folder', 'ok' => $dataWrite, 'detail' => $dataWrite ? 'Writable' : 'Not writable by the webserver user'];
    $configWrite = is_writable(file_exists(TELEPAGE_ROOT . '/config.json') ? TELEPAGE_ROOT . '/config.json' : TELEPAGE_ROOT);
    $checks[] = ['label' => 'config.json', 'ok' => $configWrite, 'detail' => $configWrite ? 'Writable' : 'Project root not writable by the webserver user'];

    // Verify .htaccess is actually honoured by the webserver.
    //
    // The repo ships a .htaccess that denies HTTP access to .json/.md/.log
    // etc., but Apache silently ignores .htaccess when AllowOverride is
    // set to None, and nginx ignores it entirely. Without this check
    // a fresh install could expose config.json (telegram_bot_token,
    // gemini_api_key, webhook_secret) to any HTTP client that guesses
    // the path.
    //
    // Technique: drop a temporary canary file matching the .htaccess
    // block rule, then fetch it back via the public HTTP URL. If the
    // response body equals the canary content, .htaccess is NOT being
    // applied. If the fetch fails (403/404/timeout/connection refused)
    // we treat it as safe enough. If the fetch cannot be performed at
    // all (cURL missing, detectBaseUrl unreachable via loopback on
    // hardened hosts), we return an 'unknown' state rather than a
    // false green.
    $checks[] = htaccessEffectiveCheck();

    return $checks;
}

/**
 * Drops a canary .json file next to config.json and tries to GET it over
 * HTTP. Returns a check row shaped like the others.
 */
function htaccessEffectiveCheck(): array {
    $label = '.htaccess enforcement';

    if (!extension_loaded('curl')) {
        return ['label' => $label, 'ok' => false, 'detail' => 'Cannot verify — cURL missing'];
    }

    $canaryName = '.telepage-canary-' . bin2hex(random_bytes(6)) . '.json';
    $canaryPath = TELEPAGE_ROOT . '/' . $canaryName;
    $canaryBody = 'CANARY_' . bin2hex(random_bytes(8));

    if (@file_put_contents($canaryPath, $canaryBody) === false) {
        // Not writable here is already covered by the config.json check
        // above; we still don't want to claim safety we haven't proven.
        return ['label' => $label, 'ok' => false, 'detail' => 'Cannot verify — project root not writable for canary'];
    }

    $url = rtrim(getBaseUrl(), '/') . '/' . $canaryName;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false, // self-signed dev certs are common at install time
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    @unlink($canaryPath);

    if ($body === false || $code === 0) {
        // Loopback blocked, DNS not resolving the public host from inside
        // the server, firewall denying self-requests, etc. We don't know.
        $hint = $err !== '' ? " ({$err})" : '';
        return ['label' => $label, 'ok' => false, 'detail' => 'Cannot verify — self-fetch failed' . $hint . '. Manually confirm that opening ' . $url . ' in a browser does NOT show the file content.'];
    }

    if ($code >= 200 && $code < 300 && $body === $canaryBody) {
        // The file was served verbatim: .htaccess is NOT being applied.
        return ['label' => $label, 'ok' => false, 'detail' => 'CRITICAL: .json files are publicly readable. Set AllowOverride to All for this directory in Apache config, or on nginx add a location block that denies .json / .md / .log / .lock.'];
    }

    // Any other code (403, 404, 5xx, wrong body) is good enough.
    return ['label' => $label, 'ok' => true, 'detail' => 'OK (canary blocked with HTTP ' . $code . ')'];
}

function finalizeInstallation(): array {
    $sess   = $_SESSION['install'];
    $dbPath = $sess['db_path'] ?? (TELEPAGE_ROOT . '/data/app.sqlite');
    if (empty($dbPath)) $dbPath = TELEPAGE_ROOT . '/data/app.sqlite';

    // Detect the public URL from $_SERVER BEFORE building $configData,
    // so 'custom_webhook_url' stores the right value from the first save.
    // Previously this line came AFTER $configData was built, leaving
    // $baseUrl undefined at that point and 'custom_webhook_url' silently
    // null — the webhook then registered but the value wasn't persisted
    // for the admin UI to show, forcing a second save from Settings.
    $baseUrl = getBaseUrl();

    $configData = [
        'app_name' => $sess['app_name'], 'theme_color' => $sess['theme_color'],
        'db_path' => $dbPath, 'telegram_bot_token' => $sess['bot_token'],
        'telegram_channel_id' => $sess['channel_id'], 'webhook_secret' => $sess['webhook_secret'],
        'language' => $sess['site_language'], 'installed' => true,
        'gemini_api_key' => $sess['gemini_api_key'] ?? '',
        'ai_enabled' => !empty($sess['gemini_api_key']),
        'ai_auto_tag' => !empty($sess['gemini_api_key']),
        'ai_auto_summary' => false,
        'custom_webhook_url' => $baseUrl,
        'download_media' => true
    ];
    try {
        Config::save($configData);
        DB::reset(); DB::initSchema();
        DB::query('INSERT INTO admins (username, password_hash) VALUES (:u, :h) ON CONFLICT(username) DO UPDATE SET password_hash=excluded.password_hash', [':u' => $sess['admin_username'], ':h' => $sess['admin_password_hash']]);
        // Prima cancella webhook e svuota coda pendente (drop_pending_updates: true)
        // Evita che messaggi bufferizzati da Telegram arrivino subito dopo l'installazione
        telegramRequest($sess['bot_token'], 'deleteWebhook', ['drop_pending_updates' => true]);
        sleep(1); // Attendi che Telegram processi la cancellazione
        // Poi registra il nuovo webhook
        telegramRequest($sess['bot_token'], 'setWebhook', ['url' => $baseUrl.'/api/webhook.php', 'secret_token' => $sess['webhook_secret'], 'allowed_updates' => ['channel_post', 'edited_channel_post']]);
        return ['ok' => true, 'base_url' => $baseUrl];
    } catch (Exception $e) { return ['error' => $e->getMessage()]; }
}

function telegramRequest($token, $method, $params = []) {
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res ?: '{}', true);
}

function getBaseUrl(): string {
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
}
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$requirements = ($step === 1) ? checkRequirements() : [];
$allReqMet = true;
foreach($requirements as $r) if(!$r['ok']) $allReqMet = false;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Telepage — Installation Wizard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg: #0a0f1e; --surface: #1a2236; --border: #2a3654; --text: #e2e8f0; --accent: #3b82f6; --success: #22c55e; --error: #ef4444; }
        body { background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif; display: flex; flex-direction: column; align-items: center; padding: 40px 16px; margin: 0; min-height: 100vh; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 32px; width: 100%; max-width: 600px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .btn { padding: 12px 24px; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; transition: 0.2s; font-size: 14px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-primary { background: var(--accent); color: white; }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: #8899bb; }
        .form-control { width: 100%; padding: 12px; background: #0a0f1e; border: 1px solid var(--border); border-radius: 8px; color: white; margin-bottom: 20px; font-family: inherit; }
        .alert { padding: 14px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; }
        .alert-error { background: rgba(239, 68, 68, 0.1); border: 1px solid var(--error); color: #fca5a5; }
        .req-item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border); }
        .badge { padding: 2px 8px; border-radius: 12px; font-size: 12px; font-weight: 700; }
        .badge-ok { background: var(--success); color: white; }
        .badge-fail { background: var(--error); color: white; }
        .step-nav { display: flex; justify-content: space-between; margin-top: 30px; }
    </style>
</head>
<body>

    <h1 style="margin-bottom: 40px; font-weight: 800; letter-spacing: -0.02em;">📡 Telepage</h1>

    <div class="card">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><?= implode('<br>', $errors) ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert" style="background: rgba(34, 197, 94, 0.1); border: 1px solid var(--success); color: #86efac;"><?= implode('<br>', $success) ?></div>
        <?php endif; ?>

        <?php if ($isInstalled): ?>
            <div style="text-align: center;">
                <h2 style="color: var(--success); margin-bottom: 20px;">✓ Site Active</h2>
                <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 40px;">
                    <a href="../admin/login.php" class="btn btn-primary">Go to Admin Login</a>
                    <a href="../index.php" class="btn btn-outline">Visit Website</a>
                </div>
                <div style="border-top: 1px solid var(--border); padding-top: 30px;">
                    <h3 style="color: var(--error); font-size: 16px;">System Reset</h3>
                    <p style="font-size: 13px; color: #8899bb; margin-bottom: 20px;">Delete all data and configuration?</p>
                    <?php if (!file_exists(TELEPAGE_ROOT . '/reset.txt')): ?>
                        <div class="alert" style="background: rgba(245, 158, 11, 0.05); font-size: 12px; border: 1px solid var(--border);">Create <code>reset.txt</code> in root to unlock reset.</div>
                    <?php else: ?>
                        <form method="POST"><input type="hidden" name="action" value="hard_reset">
                        <button type="submit" class="btn" style="background: var(--error); color: white; width:100%">Execute Total Reset</button></form>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($step === 1): ?>
            <h2>Step 1: Branding & Language</h2>
            <form method="POST">
                <input type="hidden" name="step" value="1">
                <label style="display:block;margin-bottom:8px;font-size:13px;font-weight:600">Website Name:</label>
                <input type="text" name="app_name" class="form-control" value="<?= e($_SESSION['install']['app_name'] ?? 'Telepage') ?>" required>
                
                <div style="display:flex; gap:15px; margin-bottom: 20px;">
                    <div style="flex:1">
                        <label style="display:block;margin-bottom:8px;font-size:13px;font-weight:600">Site Language:</label>
                        <select name="site_language" class="form-control">
                            <option value="en" <?= ($_SESSION['install']['site_language']??'') === 'en' ? 'selected':'' ?>>English (Global)</option>
                            <option value="it" <?= ($_SESSION['install']['site_language']??'') === 'it' ? 'selected':'' ?>>Italiano</option>
                            <option value="es" <?= ($_SESSION['install']['site_language']??'') === 'es' ? 'selected':'' ?>>Español</option>
                            <option value="fr" <?= ($_SESSION['install']['site_language']??'') === 'fr' ? 'selected':'' ?>>Français</option>
                            <option value="de" <?= ($_SESSION['install']['site_language']??'') === 'de' ? 'selected':'' ?>>Deutsch</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:8px;font-size:13px;font-weight:600">Accent Color:</label>
                        <input type="color" name="theme_color" class="form-control" style="width:70px; padding:2px; height:42px" value="<?= e($_SESSION['install']['theme_color'] ?? '#3b82f6') ?>">
                    </div>
                </div>

                <div style="margin: 20px 0; font-size: 13px;">
                    <strong>Requirements:</strong>
                    <?php foreach ($requirements as $req): ?>
                        <div class="req-item"><span><?= $req['label'] ?></span><span class="badge <?= $req['ok']?'badge-ok':'badge-fail' ?>"><?= $req['ok']?'OK':'FAIL' ?></span></div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%" <?= !$allReqMet ? 'disabled':'' ?>>Next: Database Setup →</button>
            </form>

        <?php elseif ($step === 2): ?>
            <h2>Step 2: Database Setup</h2>
            <form method="POST">
                <input type="hidden" name="step" value="2">
                <label style="display:block;margin-bottom:8px;font-size:13px;font-weight:600">SQLite Database Path:</label>
                <input type="text" name="db_path" class="form-control" value="<?= e($_SESSION['install']['db_path'] ?? TELEPAGE_ROOT . '/data/app.sqlite') ?>">
                <div class="step-nav">
                    <a href="?step=1" class="btn btn-outline">← Back</a>
                    <button type="submit" class="btn btn-primary">Connect & Next →</button>
                </div>
            </form>

        <?php elseif ($step === 3): ?>
            <h2>Step 3: Telegram Integration</h2>
            <form method="POST">
                <input type="hidden" name="step" value="3">
                <label style="display:block;margin-bottom:8px;font-size:13px;font-weight:600">Telegram Bot Token:</label>
                <input type="text" name="bot_token" class="form-control" value="<?= e($_SESSION['install']['bot_token'] ?? '') ?>" placeholder="123456:ABC...">
                <label style="display:block;margin-bottom:8px;font-size:13px;font-weight:600">Channel Username/ID (Opzionale):</label>
                <input type="text" name="channel_id" class="form-control" value="<?= e($_SESSION['install']['channel_id'] ?? '') ?>" placeholder="@mychannel o -1001234567890">

                <div style="border-top:1px solid var(--border);margin:24px 0;padding-top:20px">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                        <span style="font-size:16px">🤖</span>
                        <div>
                            <strong style="font-size:13px">Google Gemini AI</strong>
                            <span style="font-size:11px;color:#8899bb;margin-left:8px">(Opzionale — puoi configurarla dopo)</span>
                        </div>
                    </div>
                    <p style="font-size:12px;color:#8899bb;margin-bottom:12px">
                        Con l'AI otterrai: tag automatici sui contenuti, riassunti nelle card, ricerca semantica.<br>
                        Ottieni la chiave gratis su <a href="https://aistudio.google.com" target="_blank" style="color:var(--accent)">aistudio.google.com</a>
                    </p>
                    <label style="display:block;margin-bottom:8px;font-size:13px;font-weight:600">Gemini API Key:</label>
                    <input type="text" name="gemini_api_key" class="form-control" value="<?= e($_SESSION['install']['gemini_api_key'] ?? '') ?>" placeholder="AIza...">
                </div>

                <div class="step-nav">
                    <a href="?step=2" class="btn btn-outline">← Back</a>
                    <button type="submit" class="btn btn-primary">Verify & Next →</button>
                </div>
            </form>

        <?php elseif ($step === 4): ?>
            <h2>Step 4: Admin Account</h2>
            <form method="POST">
                <input type="hidden" name="step" value="4">
                <label style="display:block;margin-bottom:8px;font-size:13px;font-weight:600">Admin Username:</label>
                <input type="text" name="username" class="form-control" value="<?= e($_SESSION['install']['admin_username'] ?? 'admin') ?>">
                <label style="display:block;margin-bottom:8px;font-size:13px;font-weight:600">Admin Password:</label>
                <div style="position:relative">
                    <input type="password" name="password" id="pwd1" class="form-control" placeholder="Min 8 characters" oninput="checkMatch()" onkeydown="checkCaps(event)">
                </div>
                <label style="display:block;margin:12px 0 8px;font-size:13px;font-weight:600">Conferma Password:</label>
                <input type="password" name="password_confirm" id="pwd2" class="form-control" placeholder="Ripeti la password" oninput="checkMatch()">
                <div id="pwd-msg" style="font-size:12px;margin-top:6px;display:none"></div>
                <div id="caps-msg" style="font-size:12px;color:#f59e0b;margin-top:6px;display:none">⚠️ CAPS LOCK on</div>
                <div class="step-nav">
                    <a href="?step=3" class="btn btn-outline">← Back</a>
                    <button type="submit" id="btn-next" class="btn btn-primary" disabled>Preview & Finish →</button>
                </div>
                <script>
                function checkMatch() {
                    const p1 = document.getElementById("pwd1").value;
                    const p2 = document.getElementById("pwd2").value;
                    const msg = document.getElementById("pwd-msg");
                    const btn = document.getElementById("btn-next");
                    msg.style.display = "block";
                    if (p1.length < 8) {
                        msg.style.color = "#ef4444"; msg.textContent = "Password troppo corta (min 8 caratteri)"; btn.disabled = true;
                    } else if (p2 && p1 !== p2) {
                        msg.style.color = "#ef4444"; msg.textContent = "Le password non coincidono"; btn.disabled = true;
                    } else if (p1 === p2 && p1.length >= 8) {
                        msg.style.color = "#22c55e"; msg.textContent = "✓ Password valida"; btn.disabled = false;
                    } else {
                        msg.style.display = "none"; btn.disabled = true;
                    }
                }
                function checkCaps(e) {
                    const caps = document.getElementById("caps-msg");
                    caps.style.display = e.getModifierState && e.getModifierState("CapsLock") ? "block" : "none";
                }
                document.addEventListener("keydown", checkCaps);
                </script>
            </form>

        <?php elseif ($step === 5): ?>
            <div style="text-align: center;">
                <?php if (isset($_SESSION['install_success'])): ?>
                    <h2 style="color:var(--success)">🎉 Installation Complete!</h2>
                    <div style="background:#0a0f1e; border: 1px solid #22c55e; padding:16px; border-radius:8px; text-align:left; margin:16px 0; font-size:13px;">
                        <strong>URL Sito:</strong> <a href="<?= e($_SESSION['install_base_url']) ?>/" style="color:var(--accent)"><?= e($_SESSION['install_base_url']) ?>/</a><br>
                        <strong>Admin:</strong> <a href="<?= e($_SESSION['install_base_url']) ?>/admin/login.php" style="color:var(--accent)"><?= e($_SESSION['install_base_url']) ?>/admin/</a><br>
                        <strong>Utente:</strong> <?= e($_SESSION['install']['admin_username'] ?? 'admin') ?>
                    </div>
                    <div style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);padding:14px;border-radius:8px;font-size:12px;color:#8899bb;margin-bottom:16px">
                        <strong style="color:#e2e8f0">Prossimi passi (facoltativi):</strong><br>
                        1. Accedi al pannello admin<br>
                        2. Vai in <strong>History Scanner</strong> per importare i messaggi storici<br>
                        3. Se hai inserito la API Key Gemini, vai in <strong>Dashboard → Ri-accoda AI → Process AI</strong>
                    </div>
                    <a href="../admin/login.php" class="btn btn-primary" style="width:100%;margin-bottom:10px">🔒 Accedi al Pannello Admin</a>
                    <a href="../index.php" class="btn btn-outline" style="width:100%">🌐 Vai al Sito</a>
                <?php else: ?>
                    <h2>Step 5: Ready to Finalize?</h2>
                    <p style="color:#8899bb; margin-bottom:30px">Press the button below to write configuration and create the database.</p>
                    <form method="POST">
                        <input type="hidden" name="step" value="5"><input type="hidden" name="action" value="finalize">
                        <div class="step-nav">
                            <a href="?step=4" class="btn btn-outline">← Back</a>
                            <button type="submit" class="btn btn-primary" style="background:var(--success)">Complete Installation</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>
