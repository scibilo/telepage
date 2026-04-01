# 📡 Telepage — Turn Your Telegram Channel into a Website

**Transform any Telegram channel into a fast, searchable, SEO-friendly website — in under 5 minutes.**

No MySQL. No Node.js. Works on any shared hosting with PHP 8.1+.

![Telepage — recipes demo site](https://raw.githubusercontent.com/scibilo/telepage/main/assets/img/promo/screenshot-ricette.png)

---

## The Problem

Telegram is a great publishing tool — but it's a **walled garden**:

- Content is **invisible to Google**
- Old posts are **impossible to find**
- Readers **need the app** to see your work
- There's **no archive**, no search, no categories

## The Solution

Telepage bridges Telegram and the open web. Every post you publish becomes a searchable web page — automatically.

```
You post on Telegram  →  Webhook fires instantly  →  Card appears on your site
```

---

## Features

### ⚡ Real-Time Sync
Every new Telegram post appears on your site within seconds via webhooks. No cron jobs, no manual steps.

### 🤖 AI Auto-Tagging *(Optional)*
Connect a Google Gemini API key and every post gets automatic tags and a summary. Free tier available.

### 📜 History Scanner
Already have hundreds of posts? Import your entire archive — even years of past content — by scanning backwards through your channel's message history.

### 🏷️ Smart Tag Navigation
Hashtags in your Telegram posts become clickable, color-coded filters. `#recipe` becomes a tag. Readers browse by topic instantly.

### 🔍 Full-Text Search
Search across titles, descriptions, and AI summaries. Results update as you type.

### 🎨 Visual Themes
6 built-in themes (Dark, Ocean, Forest, Sunset, Rose, Slate) plus a full color picker. Change the look in one click.

### 📱 Mobile-First
Responsive card grid that works on phones, tablets, and desktops.

### 🔒 Isolated Sessions
Multiple Telepage installations on the same domain have completely separate admin sessions and credentials.

### 🌐 Multilingual
Interface in English, Italian, Spanish, French, and German.

---

## Requirements

| | Minimum |
|---|---|
| PHP | 8.1+ |
| Extensions | `pdo_sqlite`, `curl`, `mbstring`, `gd` |
| HTTPS | Required (Telegram webhooks mandate SSL) |
| Database | None — SQLite is built in |

Works on shared hosting (cPanel, Aruba, SiteGround). No root access required.

---

## Quick Install

1. Download the latest release zip and upload it to your server
2. Visit `https://yoursite.com/yourfolder/install/`
3. Complete the 5-step wizard — bot token, channel ID, optional AI key, admin password
4. Click **Set Webhook** in the dashboard
5. Done — your site is live

Full guide → [INSTALL.md](INSTALL.md)

---

## Screenshots

**Recipes site — 952 posts, colored tags, AI summaries, full-text search**

![Recipes screenshot](https://raw.githubusercontent.com/scibilo/telepage/main/assets/img/promo/screenshot-ricette.png)

**Science/News site — card grid with tag filters**

![Telepage screenshot](https://raw.githubusercontent.com/scibilo/telepage/main/assets/img/promo/screenshot-telepage.png)

---

## How It Works

```
┌─────────────────┐    Webhook     ┌──────────────────────┐
│  Telegram       │ ─────────────► │  Your Server         │
│  Channel        │                │  PHP 8.1 + SQLite    │
└─────────────────┘                └──────────────────────┘
       │                                      │
  You post links,                    Scrapes metadata
  photos, #hashtags                  AI tags & summary
                                     Stores in DB
                                              │
                                   ┌──────────────────────┐
                                   │  Your Website         │
                                   │  Card grid            │
                                   │  Search & tag filters │
                                   │  SEO-ready pages      │
                                   └──────────────────────┘
```

---

## Admin Panel Tools

| Tool | What it does |
|---|---|
| **Set Webhook** | Connects your bot — do this once after install |
| **Sync Now** | Manually fetch messages missed while webhook was offline |
| **History Scanner** | Import the full archive of past posts |
| **Process AI** | Auto-tag all content in one click (loops until done) |
| **Fix Images** | Retry downloading missing preview images |
| **Optimize DB** | Run SQLite VACUUM to reclaim disk space |
| **Backup** | Download your database as a `.sqlite` file |
| **Cleanup** | Permanently delete soft-deleted content |

---

## Multiple Channels

Each installation is completely independent:

```
yoursite.com/news/       ← Channel A  (own DB, own admin)
yoursite.com/recipes/    ← Channel B  (own DB, own admin)
yoursite.com/tech/       ← Channel C  (own DB, own admin)
```

---

## Known Limitations

| Platform | Status |
|---|---|
| YouTube | ✅ Full support — thumbnail + title |
| TikTok | ✅ Works via oEmbed |
| Web links | ✅ Open Graph scraping |
| Instagram / Facebook | ⚠️ URL saved, no preview (login wall) |
| Amazon | ✅ Usually works; shared hosting IPs may hit rate limits |

---

## License

[MIT](LICENSE) — free to use, modify, and deploy.

---

## Contributing

Issues and pull requests welcome. Open an issue before submitting large changes.

## Support

- [Issues](https://github.com/scibilo/telepage/issues) — bugs and feature requests
- [INSTALL.md](INSTALL.md) — setup problems
- [docs/HISTORY-SCANNER.md](docs/HISTORY-SCANNER.md) — import questions

---

*Pure PHP 8.1 + SQLite + vanilla JS. No frameworks. No build step. Upload and run.*
