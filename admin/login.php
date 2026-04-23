<?php

/**
 * TELEPAGE — admin/login.php
 * Administrator authentication.
 *
 * Security rules:
 *  - Session expires after 8h, session_regenerate_id(true) after login
 *  - Brute force protection: block after 5 attempts (rate_limits table)
 *  - Cookies: httponly + samesite=Strict
 *  - All admin forms include a CSRF token
 */

declare(strict_types=1);

define('TELEPAGE_ROOT', dirname(__DIR__));

require_once TELEPAGE_ROOT . '/app/bootstrap.php';
Bootstrap::init(Bootstrap::MODE_HTML);

require_once TELEPAGE_ROOT . '/app/Config.php';
require_once TELEPAGE_ROOT . '/app/DB.php';
require_once TELEPAGE_ROOT . '/app/Logger.php';
require_once TELEPAGE_ROOT . '/app/Security/Session.php';

// If not installed, redirect to setup wizard
if (!Config::isInstalled()) {
    header('Location: ../install/index.php');
    exit;
}

ini_set('session.gc_maxlifetime', '28800'); // 8 hours
Session::start();

// Already logged in?
if (!empty($_SESSION['admin_logged_in']) && !empty($_SESSION['admin_user'])) {
    header('Location: index.php');
    exit;
}

// Helper: get client IP
function clientIp(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
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

// -----------------------------------------------------------------------
// Brute-force protection
// -----------------------------------------------------------------------
//
// Two-axis rate limiting protects both against single-IP brute-force and
// distributed attacks via IP rotation:
//
//   Per-(IP, username): MAX_PER_IP_PER_USER attempts from the same IP
//     against the same username in LOCK_PER_IP seconds. Key in the
//     rate_limits table: ip=<ip>, endpoint="login:<username>"
//
//   Global per-username: MAX_GLOBAL_PER_USER attempts against the same
//     username from ANY IP in LOCK_GLOBAL seconds. Key in rate_limits:
//     ip="GLOBAL", endpoint="login_global:<username>"
//
// A login attempt is rejected if EITHER limit is reached. A successful
// login clears both entries for that (ip, username) pair but leaves
// other username entries untouched.
//
// Username is lowercased and length-clamped before use to normalise
// keys and prevent table-stuffing with long garbage values.

const MAX_PER_IP_PER_USER   = 5;
const LOCK_PER_IP           = 900;  // 15 minutes
const MAX_GLOBAL_PER_USER   = 30;
const LOCK_GLOBAL           = 1800; // 30 minutes
const USERNAME_MAX_LENGTH   = 64;

/**
 * Normalises a username to a stable rate-limit key component.
 * Empty input returns an empty string; callers should short-circuit.
 */
function normaliseUsername(string $username): string
{
    $u = strtolower(trim($username));
    return substr($u, 0, USERNAME_MAX_LENGTH);
}

/**
 * Fetches a single rate_limits row, or null if none.
 */
function getRateLimitRecord(string $ip, string $endpoint): ?array
{
    return DB::fetchOne(
        'SELECT * FROM rate_limits WHERE ip = :ip AND endpoint = :ep',
        [':ip' => $ip, ':ep' => $endpoint]
    );
}

/**
 * Returns true if (ip, endpoint) counter is at or above max and still
 * inside the window. Transparently drops an expired window.
 */
function rateLimitHit(string $ip, string $endpoint, int $max, int $window): bool
{
    $rec = getRateLimitRecord($ip, $endpoint);
    if (!$rec) {
        return false;
    }

    $windowAge = time() - (int) $rec['window_start'];
    if ($windowAge > $window) {
        // Window expired — reset silently.
        DB::query(
            'DELETE FROM rate_limits WHERE ip = :ip AND endpoint = :ep',
            [':ip' => $ip, ':ep' => $endpoint]
        );
        return false;
    }

    return (int) $rec['hit_count'] >= $max;
}

/**
 * Returns true if either the per-(ip, username) OR the global-per-username
 * limit is currently exceeded for this login attempt.
 */
function isBlocked(string $ip, string $username): bool
{
    if ($username === '') {
        return false;
    }
    return rateLimitHit($ip,      "login:{$username}",        MAX_PER_IP_PER_USER, LOCK_PER_IP)
        || rateLimitHit('GLOBAL', "login_global:{$username}", MAX_GLOBAL_PER_USER, LOCK_GLOBAL);
}

/**
 * Increments both counters (per-IP+user and global-per-user) for a failed
 * login attempt. Starts a fresh window on INSERT (ON CONFLICT upgrades
 * the count, preserving the existing window_start so each window is a
 * true rolling window from the first failure, not from the latest).
 */
function recordAttempt(string $ip, string $username): void
{
    if ($username === '') {
        return;
    }
    $now = time();

    // (ip, username) counter
    DB::query(
        'INSERT INTO rate_limits (ip, endpoint, hit_count, window_start)
         VALUES (:ip, :ep, 1, :now)
         ON CONFLICT(ip, endpoint) DO UPDATE SET hit_count = hit_count + 1',
        [':ip' => $ip, ':ep' => "login:{$username}", ':now' => $now]
    );

    // Global per-username counter
    DB::query(
        'INSERT INTO rate_limits (ip, endpoint, hit_count, window_start)
         VALUES (:ip, :ep, 1, :now)
         ON CONFLICT(ip, endpoint) DO UPDATE SET hit_count = hit_count + 1',
        [':ip' => 'GLOBAL', ':ep' => "login_global:{$username}", ':now' => $now]
    );
}

/**
 * Clears BOTH counters for this (ip, username) pair on a successful
 * login. Other IPs that were racking up attempts on the same username
 * are left untouched — if an attacker happened to be probing 'admin'
 * during a legitimate login, their per-(ip, user) counter keeps going.
 */
function clearAttempts(string $ip, string $username): void
{
    if ($username === '') {
        return;
    }
    DB::query(
        'DELETE FROM rate_limits WHERE ip = :ip AND endpoint = :ep',
        [':ip' => $ip, ':ep' => "login:{$username}"]
    );
    DB::query(
        'DELETE FROM rate_limits WHERE ip = :ip AND endpoint = :ep',
        [':ip' => 'GLOBAL', ':ep' => "login_global:{$username}"]
    );
}

// -----------------------------------------------------------------------
// CSRF token
// -----------------------------------------------------------------------

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

// -----------------------------------------------------------------------
// POST — process login
// -----------------------------------------------------------------------

$error  = '';
$ip     = clientIp();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify CSRF token
    $csrfReceived = $_POST['csrf_token'] ?? '';
    $usernameRaw  = trim($_POST['username'] ?? '');
    $username     = normaliseUsername($usernameRaw);

    if (!hash_equals($csrfToken, $csrfReceived)) {
        $error = 'Invalid security token. Please reload the page.';
    } elseif ($username === '' || empty($_POST['password'])) {
        $error = 'Username and password are required.';
    } elseif (isBlocked($ip, $username)) {
        $error = 'Too many failed attempts for this account. Please try again later.';
        Logger::admin(Logger::WARNING, 'Login blocked: rate limit exceeded', [
            'ip'       => $ip,
            'username' => $username,
        ]);
    } else {
        $password = $_POST['password'] ?? '';

        $admin = DB::fetchOne(
            'SELECT id, username, password_hash FROM admins WHERE lower(username) = :u',
            [':u' => $username]
        );

        if ($admin && password_verify($password, $admin['password_hash'])) {
            // ✓ Login successful

            // Regenerate session ID
            session_regenerate_id(true);

            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user']      = $admin['username'];
            $_SESSION['admin_id']        = $admin['id'];
            $_SESSION['login_time']      = time();

            // Reset both counters for this (ip, username) pair.
            clearAttempts($ip, $username);

            Logger::admin(Logger::INFO, 'Login successful', ['username' => $admin['username']]);

            header('Location: index.php');
            exit;
        } else {
            // ✗ Login failed
            recordAttempt($ip, $username);

            $rec       = getRateLimitRecord($ip, "login:{$username}");
            $remaining = MAX_PER_IP_PER_USER - (int) ($rec['hit_count'] ?? 0);

            if ($remaining <= 0) {
                $error = 'Account temporarily locked after too many failed attempts.';
            } else {
                $error = "Invalid credentials. {$remaining} attempt" . ($remaining === 1 ? ' remaining.' : 's remaining.');
            }

            Logger::admin(Logger::WARNING, 'Failed login attempt', [
                'username' => $username,
                'ip'       => $ip,
            ]);
        }
    }
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$config  = Config::get();
$appName = $config['app_name'] ?? 'Telepage';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Login — <?= e($appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:      #0a0f1e;
            --surface: #1a2236;
            --border:  #2a3654;
            --text:    #e2e8f0;
            --muted:   #8899bb;
            --accent:  #4f7eff;
            --accent2: #6c47ff;
            --error:   #ef4444;
            --success: #22c55e;
        }

        body {
            min-height: 100vh;
            background: var(--bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', system-ui, sans-serif;
            color: var(--text);
            padding: 16px;
            background-image:
                radial-gradient(ellipse at 20% 50%, rgba(79,126,255,.08) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 20%, rgba(108,71,255,.08) 0%, transparent 50%);
        }

        .login-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 40px 36px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 24px 64px rgba(0,0,0,.4);
        }

        .brand {
            text-align: center;
            margin-bottom: 32px;
        }
        .brand-logo {
            width: 56px; height: 56px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 16px;
            box-shadow: 0 8px 24px rgba(79,126,255,.3);
        }
        .brand-name {
            font-size: 22px;
            font-weight: 700;
            background: linear-gradient(135deg, #a5beff, #c4b5fd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .brand-sub { font-size: 13px; color: var(--muted); margin-top: 4px; }

        .form-group { margin-bottom: 18px; }

        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .05em;
            margin-bottom: 8px;
        }

        input[type=text],
        input[type=password] {
            width: 100%;
            padding: 12px 16px;
            background: rgba(255,255,255,.04);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-family: inherit;
            font-size: 14px;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }
        input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(79,126,255,.15);
        }

        .alert-error {
            background: rgba(239,68,68,.1);
            border: 1px solid rgba(239,68,68,.3);
            color: #fca5a5;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 14px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }

        .btn-login {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: #fff;
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all .2s;
            box-shadow: 0 4px 16px rgba(79,126,255,.3);
            margin-top: 8px;
        }
        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(79,126,255,.4);
        }
        .btn-login:active { transform: translateY(0); }
        .btn-login:disabled { opacity: .6; cursor: not-allowed; transform: none; }

        .footer-link {
            text-align: center;
            margin-top: 24px;
            font-size: 13px;
            color: var(--muted);
        }
        .footer-link a { color: var(--accent); text-decoration: none; }
        .footer-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="login-box">
    <div class="brand">
        <div class="brand-logo">📡</div>
        <div class="brand-name"><?= e($appName) ?></div>
        <div class="brand-sub">Administration panel</div>
    </div>

    <?php if ($error): ?>
    <div class="alert-error" role="alert">
        <span>⚠</span>
        <span><?= e($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="post" action="" id="login-form" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

        <div class="form-group">
            <label for="username">Username</label>
            <input
                type="text"
                id="username"
                name="username"
                value="<?= e($_POST['username'] ?? '') ?>"
                autocomplete="username"
                autofocus
                required
            >
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                autocomplete="current-password"
                required
            >
        </div>

        <button type="submit" class="btn-login" id="btn-submit">Sign in</button>
    </form>

    <div class="footer-link">
        <a href="../index.php">← Back to site</a>
    </div>
</div>

<script>
document.getElementById('login-form').addEventListener('submit', function() {
    const btn = document.getElementById('btn-submit');
    btn.disabled = true;
    btn.textContent = 'Signing in...';
});
</script>

</body>
</html>
