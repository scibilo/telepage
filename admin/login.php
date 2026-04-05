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

require_once TELEPAGE_ROOT . '/app/Config.php';
require_once TELEPAGE_ROOT . '/app/DB.php';
require_once TELEPAGE_ROOT . '/app/Logger.php';

// If not installed, redirect to setup wizard
if (!Config::isInstalled()) {
    header('Location: ../install/index.php');
    exit;
}

// Configure secure session
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', '28800'); // 8 hours
// Isolated session per installation (prevents cross-login between instances on the same domain)
session_name('tp_' . substr(hash('sha256', TELEPAGE_ROOT), 0, 16));
session_start();

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

const MAX_ATTEMPTS  = 5;
const LOCK_SECONDS  = 300; // 5 minutes
const RATE_ENDPOINT = 'login';

function getRateLimitRecord(string $ip): ?array
{
    return DB::fetchOne(
        'SELECT * FROM rate_limits WHERE ip = :ip AND endpoint = :ep',
        [':ip' => $ip, ':ep' => RATE_ENDPOINT]
    );
}

function isBlocked(string $ip): bool
{
    $rec = getRateLimitRecord($ip);
    if (!$rec) {
        return false;
    }

    $windowAge = time() - (int) $rec['window_start'];
    if ($windowAge > LOCK_SECONDS) {
        // Window expired — reset
        DB::query(
            'DELETE FROM rate_limits WHERE ip = :ip AND endpoint = :ep',
            [':ip' => $ip, ':ep' => RATE_ENDPOINT]
        );
        return false;
    }

    return (int) $rec['hit_count'] >= MAX_ATTEMPTS;
}

function recordAttempt(string $ip): void
{
    DB::query(
        'INSERT INTO rate_limits (ip, endpoint, hit_count, window_start)
         VALUES (:ip, :ep, 1, :now)
         ON CONFLICT(ip, endpoint) DO UPDATE SET hit_count = hit_count + 1',
        [':ip' => $ip, ':ep' => RATE_ENDPOINT, ':now' => time()]
    );
}

function clearAttempts(string $ip): void
{
    DB::query(
        'DELETE FROM rate_limits WHERE ip = :ip AND endpoint = :ep',
        [':ip' => $ip, ':ep' => RATE_ENDPOINT]
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
    if (!hash_equals($csrfToken, $csrfReceived)) {
        $error = 'Invalid security token. Please reload the page.';
    } elseif (isBlocked($ip)) {
        $error = 'Too many failed attempts. Please try again in 5 minutes.';
        Logger::admin(Logger::WARNING, 'Login blocked: too many attempts', ['ip' => $ip]);
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Username and password are required.';
        } else {
            $admin = DB::fetchOne(
                'SELECT id, username, password_hash FROM admins WHERE username = :u',
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

                // Reset attempt counter
                clearAttempts($ip);

                Logger::admin(Logger::INFO, 'Login successful', ['username' => $username]);

                header('Location: index.php');
                exit;
            } else {
                // ✗ Login failed
                recordAttempt($ip);

                $rec = getRateLimitRecord($ip);
                $remaining = MAX_ATTEMPTS - (int) ($rec['hit_count'] ?? 0);

                if ($remaining <= 0) {
                    $error = 'Account locked for 5 minutes after too many failed attempts.';
                } else {
                    $error = "Invalid credentials. {$remaining} attempt" . ($remaining === 1 ? ' remaining.' : 's remaining.');
                }

                Logger::admin(Logger::WARNING, 'Failed login attempt', ['username' => $username, 'ip' => $ip]);
            }
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
