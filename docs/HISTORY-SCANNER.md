# History Scanner — Complete Guide

The History Scanner imports past messages from your Telegram channel into Telepage. Use it when you've just installed Telepage on a channel that already has weeks, months, or years of content.

---

## How It Works

Telegram's Bot API doesn't provide a "get all messages" endpoint. The History Scanner works around this by scanning **backwards through message IDs**.

Every Telegram message has a numeric ID. The scanner starts from a high ID and counts down:

```
Scan from ID 2000 → 1999 → 1998 → ...
  ✓ Found message with a link → import it
  ✗ ID doesn't exist (deleted) → skip
  ✓ Already in DB → skip
```

Each batch imports up to **50 messages** but may attempt many more IDs to find them, because channels have gaps from deleted messages.

---

## The Interface

| Field | Meaning |
|---|---|
| **Contenuti nel DB** | Total posts currently in your database |
| **Importati ora** | Posts imported in this session |
| **Già presenti** | IDs already in the database (skipped) |
| **Message ID corrente** | The ID currently being scanned |
| **Range nel DB** | Lowest → highest message ID already imported |

---

## First Scan (Empty Database)

The scanner estimates the starting ID automatically. Just click **Start Batch** or use **Automatic Mode** to run continuously until complete.

---

## Finding Missing Recent Posts

If your database has content but you know there are newer posts missing:

1. Check **Range nel DB** — note your highest imported ID (e.g. `1120`)
2. Enter a **starting ID** in the "Parti da ID" field — try `[max_id] + 500` first
3. If the batch finds nothing, increase: try `+1000`, `+2000`, `+3000`
4. The scanner will find the right posts within a few attempts

**Example:**
```
Range nel DB: ID 2 → 1120  (your highest post)
Missing posts from March 2026 (seen on Telegram)
→ Enter 1800 in "Parti da ID" → click Start Batch
→ If 0 results: try 2500, then 3500
```

---

## Automatic Mode

Runs batches continuously without clicking. Use for full imports:

1. Set starting ID if needed
2. Click **▶️ Automatic Mode**
3. Walk away — stops automatically when no more messages are found
4. Click **⏸ Stop** anytime to pause

---

## Why Are Some Posts Missing?

This is normal. A post may not be imported if:

- **It was deleted on Telegram** — gaps in the ID sequence are skipped
- **It has no link or media** — plain text posts without URLs don't create cards
- **The ID range wasn't scanned** — run another batch with the right starting ID

Typical result: 90-98% of posts are recovered. The rest are deleted or text-only.

---

## After Scanning

1. Go to **Dashboard**
2. Click **Re-queue AI** → then **🤖 Process AI**
3. Let it run — it loops automatically until all posts have tags and summaries

---

## Troubleshooting

**"Found 0 results"**
The starting ID is wrong. If it's too low, all posts are already in the DB (skipped). If it's too high, there are no messages there. Check your Range nel DB and adjust.

**"Scanner stopped halfway"**
It stops after 300 consecutive empty IDs — this means no more messages exist in that range. Run again with a lower starting ID to fill any gaps.

**"Bot permission error"**
The scanner needs your bot to have **Admin rights** on the channel. Check channel settings → Administrators.

---

## Technical Detail

The scanner uses Telegram's `forwardMessage` API: it forwards each message ID from your channel to your channel (to itself). If the message exists, the content is read. The forwarded copy is immediately deleted. This is why admin rights are required.
