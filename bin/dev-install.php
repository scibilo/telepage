<?php
/**
 * TELEPAGE — bin/dev-install.php
 * 
 * One-shot installer for local development ONLY.
 * Bypasses the web wizard (which requires a valid Telegram bot token).
 * 
 * Creates config.json and a fresh SQLite DB with an admin user.
 * 
 * Usage:
 *     php bin/dev-install.php
 * 
 * Then log in at http://localhost:8000/admin/login.php with:
 *     username: admin
 *     password: devpassword
 * 
 * This file must NEVER run in production. Refuses to execute if
 * config.json already exists, to avoid accidentally overwriting
 * a real installation.
 */

declare(strict_types=1);

define('TELEPAGE_ROOT', dirname(__DIR__));

require_once TELEPAGE_ROOT . '/app/Config.php';
require_once TELEPAGE_ROOT . '/app/DB.php';

// Safety: refuse to run if already installed
$configPath = TELEPAGE_ROOT . '/config.json';
if (file_exists($configPath)) {
    fwrite(STDERR, "ERROR: config.json already exists at {$configPath}\n");
    fwrite(STDERR, "This script is for first-time local dev setup only.\n");
    fwrite(STDERR, "If you want to reset, delete config.json and data/app.sqlite first.\n");
    exit(1);
}

// Safety: refuse if not running from CLI
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script must be run from the command line.\n";
    exit(1);
}

echo "Telepage DEV installer — local only\n";
echo "====================================\n\n";

// 1. Write config.json with dev defaults
$config = [
    'app_name'            => 'Telepage DEV',
    'theme_color'         => '#3b82f6',
    'site_theme'          => 'dark',
    'logo_path'           => 'assets/img/logo.png',
    'db_path'             => TELEPAGE_ROOT . '/data/app.sqlite',
    'telegram_bot_token'  => '0000000000:DEV_FAKE_TOKEN_DO_NOT_USE',
    'telegram_channel_id' => '-1000000000000',
    'webhook_secret'      => bin2hex(random_bytes(24)),
    'cron_secret'         => bin2hex(random_bytes(24)),
    'language'            => 'en',
    'installed'           => true,
    'gemini_api_key'      => '',
    'ai_enabled'          => false,
    'ai_auto_tag'         => false,
    'ai_auto_summary'     => false,
    'items_per_page'      => 12,
    'pagination_type'     => 'classic',
    'custom_webhook_url'  => '',
    'download_media'      => false,  // No real token = no downloads anyway
];

Config::save($config);
echo "✓ config.json written\n";

// 2. Ensure data/ directory exists
$dataDir = TELEPAGE_ROOT . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
    echo "✓ data/ directory created\n";
}

// 3. Init DB schema
DB::reset();
DB::initSchema();
echo "✓ DB schema initialised\n";

// 4. Create admin user
$username = 'admin';
$password = 'devpassword';
$hash     = password_hash($password, PASSWORD_BCRYPT);

DB::query(
    'INSERT INTO admins (username, password_hash) VALUES (:u, :h)
     ON CONFLICT(username) DO UPDATE SET password_hash = excluded.password_hash',
    [':u' => $username, ':h' => $hash]
);
echo "✓ Admin user created\n";

// 5. Optional: seed a few sample contents so the dashboard is not empty
$samples = [
    [
        'url'          => 'https://example.com/article-1',
        'title'        => 'Sample article for dev testing',
        'description'  => 'This is seed data created by bin/dev-install.php.',
        'content_type' => 'link',
        'source_domain'=> 'example.com',
        'favicon'      => 'https://www.google.com/s2/favicons?domain=example.com',
    ],
    [
        'url'          => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'title'        => 'Sample YouTube video',
        'description'  => 'Classic.',
        'content_type' => 'youtube',
        'source_domain'=> 'youtube.com',
        'favicon'      => 'https://www.google.com/s2/favicons?domain=youtube.com',
    ],
    [
        'url'          => 'https://news.example.org/story',
        'title'        => 'Another seed item',
        'description'  => 'Second test content.',
        'content_type' => 'link',
        'source_domain'=> 'news.example.org',
        'favicon'      => '',
    ],
];

foreach ($samples as $i => $s) {
    DB::query(
        'INSERT INTO contents (url, title, description, content_type, source_domain, favicon,
                               telegram_message_id, telegram_chat_id,
                               ai_processed, is_deleted, created_at, updated_at)
         VALUES (:url, :title, :desc, :ct, :sd, :fv,
                 :mid, :cid, 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
        [
            ':url'   => $s['url'],
            ':title' => $s['title'],
            ':desc'  => $s['description'],
            ':ct'    => $s['content_type'],
            ':sd'    => $s['source_domain'],
            ':fv'    => $s['favicon'],
            ':mid'   => 9000 + $i,
            ':cid'   => $config['telegram_channel_id'],
        ]
    );
}
echo "✓ " . count($samples) . " sample contents seeded\n";

// 6. Seed a sample tag and link it to the first content
DB::query("INSERT INTO tags (name, slug, color, source, usage_count) VALUES ('dev', 'dev', '#ec4899', 'manual', 1)");
DB::query('INSERT INTO content_tags (content_id, tag_id) VALUES (1, 1)');
echo "✓ Sample tag created\n";

echo "\n";
echo "Done. Login credentials:\n";
echo "    username: {$username}\n";
echo "    password: {$password}\n";
echo "\n";
echo "-- Serving the app ------------------------------------------------\n";
echo "\n";
echo "Option A — PHP built-in server (quick, no config):\n";
echo "    php -S localhost:8000\n";
echo "    http://localhost:8000/admin/login.php\n";
echo "\n";
echo "Option B — Apache with mod_userdir (more realistic):\n";
echo "    http://localhost/~yourname/telepage/admin/login.php\n";
echo "\n";
echo "-- IMPORTANT (Apache / nginx only) --------------------------------\n";
echo "\n";
echo "The SQLite DB, data/ directory AND config.json were just created\n";
echo "by YOUR user. Apache and nginx run as a different user (typically\n";
echo "www-data), which means they can READ these files but cannot WRITE\n";
echo "to them. POST endpoints will silently fail with errors like:\n";
echo "  - 'attempt to write a readonly database' (data/app.sqlite)\n";
echo "  - 'cannot write config.json.tmp.XXXX'     (config.json updates)\n";
echo "\n";
echo "Two common fixes:\n";
echo "\n";
echo "  (A) Narrow fix — chown just the writable locations:\n";
echo "      sudo chown -R www-data:www-data " . TELEPAGE_ROOT . "/data/\n";
echo "      sudo chown www-data:www-data " . TELEPAGE_ROOT . "/config.json\n";
echo "      (note: you then need sudo to re-run dev-install.php)\n";
echo "\n";
echo "  (B) Group fix — add yourself to www-data and chmod g+rwX:\n";
echo "      sudo usermod -aG www-data \$(whoami)\n";
echo "      sudo chgrp -R www-data " . TELEPAGE_ROOT . "\n";
echo "      sudo chmod -R g+rwX " . TELEPAGE_ROOT . "\n";
echo "      sudo find " . TELEPAGE_ROOT . " -type d -exec chmod g+s {} \\;\n";
echo "      # then log out and log back in for the group to activate\n";
echo "\n";
echo "(If you use the PHP built-in server, none of this applies: it\n";
echo "runs as your own user and can read/write everywhere you can.)\n";
echo "\n";
