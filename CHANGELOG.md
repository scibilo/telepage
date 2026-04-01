# Changelog

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
