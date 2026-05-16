# Changelog

## [1.1.5] — 2026-05-16

### Fixed

- **FTS5 prefix search** (`app/Http.php`): searching for partial words
  (e.g. "chatg", "intell") returned 0 results because FTS5 matches exact
  tokens only. `fts5EscapeQuery()` now appends `*` to the last token so
  partial words match while the user is typing: `chatg` → `"chatg"*` finds
  chatgpt; `intell` → `"intell"*` finds intelligenza. All tokens except the
  last remain exact matches.

## [1.1.4] — 2026-05-16

### Added

- **Download external OG images locally** (`app/TelegramBot.php`,
  `api/admin/system.php`): when `download_media` is enabled (Lite Mode off),
  scraped `og:image` URLs (TikTok, Instagram, etc.) are now downloaded to
  `assets/media/` at insert time instead of being stored as external URLs.
  Fixes expiring thumbnails caused by signed CDN tokens. Falls back silently
  to the external URL if the download fails (host unreachable, SSRF block,
  non-image response). Also applied to the Fix Images admin action.
  Note: shared hosting providers that block outbound HTTP to CDN IPs
  (e.g. Aruba) will fall back to the external URL automatically.

## [1.1.3] — 2026-05-16

### Added

- **Exponential backoff for Gemini failures** (`app/AIService.php`,
  `api/cron.php`): failed AI processing rows are now retried automatically
  instead of being permanently marked `ai_processed=2`. Two new columns:
  `ai_retry_count` (failure counter) and `next_retry_at` (scheduled retry
  time). Backoff schedule: 5m → 15m → 60m → 360m (6h cap). The cron filter
  respects `next_retry_at` so a bad row can't tarpit the queue. Migration:
  `bin/migrate-v112.php`.

## [1.1.2] — 2026-05-16

### Fixed

- **Telegram retry deduplication** (`app/DB.php`): added a partial unique
  index on `(telegram_message_id, telegram_chat_id) WHERE telegram_message_id
  IS NOT NULL`. Telegram retries a webhook that doesn't return 2xx quickly
  enough; the unique index prevents duplicate rows at the DB layer. Migration:
  `bin/migrate-v112.php`.

- **Cron overlap protection** (`api/cron.php`): `BEGIN IMMEDIATE` + new
  `ai_processing_since` column claims a batch atomically before processing.
  A concurrent cron invocation sees claimed rows and skips them. Stale rows
  (cron died mid-run) are auto-reset after 10 minutes. Migration:
  `bin/migrate-v112.php`.

## [1.1.1] — 2026-05-16

### Fixed

- **Webhook async AI** (`api/webhook.php`): removed the synchronous
  `AIService::processContent()` call from the webhook handler. Calling
  Gemini inline blocked the 200 response for 1–5s; Telegram retries a
  webhook that doesn't respond quickly enough, causing the same update
  to be redelivered and processed twice (duplicate content or double
  tags). The cron endpoint (`api/cron.php`) already handles the
  `ai_processed=0` queue — the inline call was redundant and harmful.
  Credit: u/Alpa_Cino on r/PHP.

## [1.1.0] — 2026-05-04

### Security (audit 2026-04 — all items closed)

- **A1 — Secure session cookies** (`app/Security/Session.php`): extracted
  `Session::start()` helper that sets `Secure`, `HttpOnly`, `SameSite=Lax`
  on every session cookie consistently across all entry-points.
- **A2 — Public API input validation** (`api/contents.php`): capped query
  length, validated tag slug format, whitelisted content_type enum, enforced
  date format on `from`/`to` parameters.
- **A3 — Content-Security-Policy** (`.htaccess`): baseline CSP covering
  `default-src 'self'`, `object-src 'none'`, `frame-ancestors 'self'`,
  `form-action 'self'`, `base-uri 'self'`, `connect-src 'self'` plus
  explicit allow-list for Google Fonts.
- **A4 — Dedicated cron secret** (`api/cron.php`, `bin/generate-cron-secret.php`):
  replaced reliance on `webhook_secret` with a separate `cron_secret`;
  `X-Cron-Key` header required; migration script provided.
- **A5 — Webhook body validation** (`api/webhook.php`): enforced
  `Content-Type: application/json`, 1 MB body cap, rejected non-object
  JSON payloads before processing.
- **B1 — Import OOM cap** (`api/admin/contents.php`): 50 MB hard cap on
  JSON import uploads (both reported size and actual read size).
- **B3 — Error leak scrub** (`api/admin/contents.php`): removed stack
  traces and internal path details from `save_content` error responses.
- **B4 — GD required for logo upload** (`api/admin/system.php`): explicit
  501 with helpful message if the `gd` extension is missing instead of
  falling through to an unvalidated `move_uploaded_file`.
- **B5 — Security headers hardening** (`.htaccess`): added HSTS
  (`env=HTTPS`), `Permissions-Policy` disabling unused browser APIs,
  removed deprecated `X-XSS-Protection`.
- **B2 — FTS5 full-text search** (`app/DB.php`, `api/contents.php`,
  `api/admin/contents.php`, `app/Http.php`, `bin/migrate-fts.php`):
  replaced O(n) `LIKE '%q%'` table scan with an FTS5 virtual table +
  three sync triggers. Existing installs: run `php bin/migrate-fts.php`.

### Added

- **Composer autoload** (`composer.json`, all entry-points): single
  `require vendor/autoload.php` replaces per-file `require_once` chains.
  Deploy: run `composer install --no-dev --optimize-autoloader` locally
  and upload `vendor/` with the rest of the files (or use the release zip
  which bundles `vendor/` pre-built).
- **PHPUnit test suite** (`tests/Unit/`): 37 unit tests covering `Str`,
  `CsrfGuard`, and `UrlValidator`. Run: `composer test`.
- **GitHub Actions CI** (`.github/workflows/ci.yml`): lint + `composer
  validate` + PHPUnit on PHP 8.1, 8.2, 8.3 on every push and PR.
- **PHPStan static analysis** (`phpstan.neon.dist`): level 5, zero errors.
  Run: `composer phpstan`. CI runs it on PHP 8.3.
- **Health endpoint** (`api/health.php`): public `GET /api/health.php`
  returns JSON with db/installed/webhook/ai_queue status. Rate-limited at
  30 req/min. Separate rate-limit bucket from the public feed.
- **Admin API modularised** (`api/admin/`): `api/admin.php` is now a thin
  dispatcher (~100 lines). Action logic split into four domain modules:
  `system.php`, `contents.php`, `tags.php`, `scanner.php`. Public URL
  unchanged. `api/admin/` directory protected against direct HTTP access.
- **Release build script** (`bin/build-release.sh`): generates a
  deployment-ready zip with `vendor/` pre-built and dev/test files
  excluded. Run: `bash bin/build-release.sh`.

### Fixed (static analysis — found by PHPStan)

- `AIService::callGemini()` declared `?array` but always returns `array`
  or throws. Tightened to `array`.
- `TelegramBot::handleUpdate()` had stale `return false` in a `?int`
  method — channel mismatch silently became `0` for callers. Changed to
  `return null`.
- `TelegramBot::upsertContent()` declared `?int`, always returns `int`.
- `TelegramBot::processPost()` cascade from above: `?int` → `int`.
- `HistoryScanner::extractHashtags()` had dead `?? []` after
  `preg_match_all` (PHP guarantees the capture-group offset exists).

## [1.0.4] — 2026-04-05

### Fixed
- **History Scanner — sparse/small channel scan stops too early** (`app/HistoryScanner.php`,
  `api/admin.php`, `admin/scanner.php`): three related issues fixed together:
  1. `has_more` was `$currentId > 0 && $imported >= $batchSize` — always `false` when a
     channel has fewer messages than `BATCH_SIZE` (50), so the UI stopped after the first
     batch. Fixed to `$currentId > 0`.
  2. `MAX_ATTEMPTS` was 300, insufficient to cover 500 IDs when messages are sparse
     (e.g. only 3 messages spread over IDs 1–500). Raised to 2000; `set_time_limit`
     raised from 120 to 300 s accordingly.
  3. The rate-limit sleep fired every 10 *attempts* including non-existent IDs that
     never touch the Telegram API. Moved to fire only after 10 real `forwardMessage`
     API calls — scans over empty ID ranges are now much faster.
- **History Scanner — auto-tag incomplete after scan** (`api/admin.php`, `admin/scanner.php`):
  inline AI processing inside `actionScanBatch` raced against the near-exhausted
  `set_time_limit`, truncating the second content. Replaced with a dedicated async AI
  loop in the scanner UI (same pattern as `processAiAll()` on the dashboard) that fires
  after the batch response is received and loops until the queue is empty.
- **History Scanner — start ID detection** (`app/HistoryScanner.php`): when the
  Telegram webhook is active, `getUpdates` returned HTTP 409 and the fallback
  `max(1000, dbMaxId + 500)` was useless for channels with low message IDs. The fix
  now uses **Option A**: temporarily calls `deleteWebhook`, fetches the latest message
  ID with `getUpdates offset=-1`, then immediately re-attaches the webhook.
  Minimum fallback lowered from 1000 to 100.
- **AI auto-tag / auto-summary stopped working** (`app/AIService.php`): `parseResponse()`
  Attempt 2 used `$clean` before it was defined and had an empty regex. Fixed with
  correct regexes that strip markdown code-fences from Gemini responses.

## [1.0.0] — 2026-04-01

First public release.

### Features
- 5-step installation wizard with bot token verification
- Real-time Telegram webhook sync (new posts appear within seconds)
- History Scanner — full channel archive import via backwards message ID scanning
- AI auto-tagging and summaries via Google Gemini API (optional)
- Smart hashtag → colored tag conversion
- Full-text search across titles, descriptions, and AI summaries
- Tag filter sidebar with collapsible overflow and inline search
- Date range filter and content type filters (Link, YouTube, TikTok, Photo, Video)
- 6 visual themes: Dark, Ocean, Forest, Sunset, Rose, Slate
- Full color accent picker with presets
- Custom logo upload with automatic favicon generation
- Multilingual: English, Italian, Spanish, French, German
- Session isolation — multiple installations on the same domain don't share sessions
- MIT License

### Admin Tools
- Dashboard with system stats and live operations console
- One-click webhook setup and verification
- Process AI with auto-loop (runs until all content is tagged)
- Fix Images — retry failed preview scraping
- SQLite backup and optimize (VACUUM)
- Soft-delete system with trash and cleanup
- History Scanner with manual start ID and automatic mode
