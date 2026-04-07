# Changelog

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
