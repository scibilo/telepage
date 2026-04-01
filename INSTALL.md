# Installing Telepage

This guide walks you through a complete installation, step by step. It assumes no technical background beyond being able to upload files via FTP.

---

## Before You Start

You need:

1. **A web hosting account** with PHP 8.1+ and HTTPS (most standard hosting plans qualify)
2. **A Telegram channel** — it can be public or private, but your bot must be an admin
3. **A Telegram bot token** — free, takes 2 minutes to get (instructions below)
4. **Optionally: a Google Gemini API key** for AI auto-tagging (free tier available)

---

## Step 1 — Get Your Telegram Bot Token

1. Open Telegram and search for **@BotFather**
2. Send the command `/newbot`
3. Choose a name for your bot (e.g. "My Channel Bot")
4. Choose a username for your bot (must end in `bot`, e.g. `mychannelbot`)
5. BotFather will reply with a token that looks like: `1234567890:AAExxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`
6. **Copy and save this token** — you'll need it during installation

Then add your bot to your channel:
1. Open your Telegram channel
2. Go to channel settings → Administrators
3. Add your bot as an administrator
4. Grant it at least **Post Messages** and **Edit Messages** permissions

---

## Step 2 — Get Your Channel ID

Your channel ID is a number that looks like `-1001234567890`.

**For public channels:**
Your channel username (e.g. `@mychannel`) works as the ID. You can use it directly.

**For private channels:**
1. Forward any message from your channel to **@userinfobot** on Telegram
2. It will reply with the channel ID

---

## Step 3 — Upload Telepage to Your Server

1. Download the latest `telepage-v1.x.zip` from the [Releases page](https://github.com/yourusername/telepage/releases)
2. Unzip it on your computer
3. Upload the entire `telepage/` folder to your web server via FTP
   - You can rename the folder (e.g. `news/`, `mychannel/`, or place it in the root)
   - Example result: `public_html/mychannel/`
4. Make sure these folders are **writable** by the web server:
   - `data/`
   - `assets/media/`
   - On cPanel: File Manager → right-click folder → Permissions → set to `755`

---

## Step 4 — Run the Installation Wizard

Open a browser and go to:
```
https://yoursite.com/mychannel/install/
```

You'll see a 5-step wizard:

### Step 1 — Branding
- **Site Name:** the name shown in the header and browser tab
- **Language:** the interface language for your visitors
- Click **Next**

### Step 2 — Database
- The database path is filled in automatically — leave it as-is unless you know what you're doing
- Click **Check & Next** to verify everything is writable

### Step 3 — Telegram & AI
- **Bot Token:** paste the token from BotFather
- **Channel ID:** paste your channel username or numeric ID
- **Gemini API Key** *(optional)*: if you want automatic AI tags and summaries, paste your key here. Leave blank to skip — you can add it later in Settings.
- Click **Verify & Next** — the wizard will test your bot token

### Step 4 — Admin Account
- Choose a **username** and **password** for the admin panel
- The password must be at least 8 characters
- A confirmation field and caps-lock warning help you avoid typos

### Step 5 — Done
- Your site is installed
- Click **Go to Admin Panel** to log in

---

## Step 5 — Activate the Webhook

After logging into the admin panel:

1. Click **Set Webhook** on the Dashboard
2. You should see "✓ Webhook is already set" with your URL
3. From this moment, every new post you publish on Telegram will appear on your site automatically within a few seconds

---

## Step 6 (Optional) — Import Your History

If your channel already has posts, use the **History Scanner** to import them.

See [docs/HISTORY-SCANNER.md](HISTORY-SCANNER.md) for a full walkthrough.

---

## Step 7 (Optional) — Configure AI Tagging

If you entered a Gemini API key during installation, AI is already enabled.

If you skipped it:
1. Go to **Settings → AI (Gemini)**
2. Paste your API key (get one free at [aistudio.google.com](https://aistudio.google.com))
3. Enable **Auto-Tag** and optionally **Auto-Summary**
4. Save
5. Click **Re-queue AI** on the Dashboard, then **Process AI** to tag all existing content

New posts will be tagged automatically from this point forward.

---

## Troubleshooting

### "Webhook is not configured" after install

Go to Settings, verify the **Custom Webhook URL** field contains your full site URL (e.g. `https://yoursite.com/mychannel`). Save, then click **Set Webhook** on the Dashboard.

### Posts appear on Telegram but not on the site

1. Check that the webhook is active (green "✓ Attivo" on Dashboard)
2. If not, click **Set Webhook**
3. If it keeps disconnecting, check your hosting logs — some servers block outgoing requests from Telegram's IP range

### Images are missing on some posts

Some websites (Instagram, Facebook, Amazon) block automated image scraping. Use **Fix Images** on the Dashboard to retry. For posts that will never have a preview (Instagram), this is expected behavior — the link still works.

### The admin panel shows no content after import

Click **Re-queue AI** then **Process AI** — the content counter refreshes after processing. If the count is still 0, check Settings → Database path.

### I see an error 500 after uploading

Check that:
- The `data/` folder exists and is writable (`chmod 755`)
- Your PHP version is 8.1 or higher (check with your host)
- The `.htaccess` file was uploaded (it's hidden by default — enable "show hidden files" in your FTP client)

---

## Upgrading

When a new version is released:

1. Download the new zip
2. Upload all files **except** `config.json` and the `data/` folder
3. No database migration needed — the schema is managed automatically

---

## Uninstalling

To remove Telepage completely:
1. Delete the folder from your server
2. Optionally, delete the webhook via BotFather: send `/setwebhook` and clear the URL

Your Telegram channel is not affected in any way.
