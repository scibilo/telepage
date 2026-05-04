# Telepage — Installation Guide

## Requirements

- PHP 8.1+
- PDO SQLite extension
- cURL extension
- mbstring extension
- A web server with HTTPS (required by Telegram for webhooks)
- Shared hosting or VPS — **no SSH required** for standard deployment

## Fresh Installation

1. **Download** the latest release zip from [GitHub Releases](https://github.com/scibilo/telepage/releases)
2. **Extract** the zip and upload the `telepage/` folder to your hosting via FTP / cPanel File Manager
3. **Make sure `config.json` is NOT present** in the folder — the setup wizard will create it
4. **Open the wizard** in your browser: `https://yourdomain.com/telepage/install/`
5. **Follow the 5 steps** in the wizard

The release zip includes `vendor/` (Composer dependencies) pre-built — no need to run `composer install` on the server.

## Upgrading from v1.0.x

1. **Back up** `config.json` and `data/app.sqlite` (your configuration and all content)
2. **Extract the zip over** your existing installation — `config.json` and `data/` are intentionally excluded from the zip and will not be overwritten
3. **Run the FTS5 migration** to enable full-text search on existing content:
   - **CLI** (if SSH is available): `php bin/migrate-fts.php`
   - **Browser fallback** (shared hosting): upload `bin/migrate-fts.php` to web root, visit `https://yourdomain.com/migrate-fts.php` from localhost, then delete the file

## Multiple Sites (one site per channel)

Copy the folder with a different name for each channel:
- `telepage-cooking/`
- `telepage-tech/`
- `telepage-news/`

Each folder is a fully independent installation with its own `config.json` and database.

## After Installation

1. Set up the Telegram webhook from the admin dashboard → Settings → Set Webhook
2. Use the **History Scanner** to import existing channel messages
3. New messages arrive automatically via webhook in real time

## Important Notes

- **Never commit `config.json`** to a public repository — it contains your Telegram bot token and other secrets
- The `data/` directory contains your SQLite database — keep it backed up
- To reset an installation: create a `reset.txt` file in the root folder, then visit `install/index.php`
- The admin panel is at `/admin/` — bookmark it after setup

## Developer Setup (local)

```bash
git clone https://github.com/scibilo/telepage.git
cd telepage
composer install
php bin/dev-install.php      # creates config.json with dev defaults
composer test                # run the PHPUnit test suite
composer phpstan             # run static analysis
```
