# Content Sync — How It Works

## New posts

Every new post published on your Telegram channel appears on your Telepage site automatically within seconds, via webhook. No action required.

## Edited posts

When you edit a post on Telegram, the webhook receives the update and Telepage updates the stored content automatically.

## Deleted posts

When you delete a post on Telegram, **Telepage does not remove it automatically**. This is intentional.

Telegram's Bot API does not send deletion events to webhooks. The bot has no way to know a message was deleted unless it actively scans for it.

### How to remove deleted content

**Option 1 — From the public site (quickest)**
If you are logged in as admin, hover over any card and click the 🗑 trash icon that appears. The post moves to the trash.

**Option 2 — From the admin panel**
Go to **Admin → Contents**, find the post and click **Delete**. The post moves to the trash.

**Option 3 — Bulk cleanup**
Go to **Admin → Dashboard → Cleanup** to permanently delete everything in the trash at once.

### Why soft-delete?

Deleted content is not immediately removed from the database — it is marked as deleted and hidden from the public site. This allows you to recover accidental deletions before running Cleanup.

---

## History Scanner and deletions

The History Scanner imports messages by scanning message IDs. If a message was deleted on Telegram before you ran the scanner, it will not be imported — the scanner skips IDs that return no content from Telegram.

If a message was already imported and then deleted on Telegram, the scanner will not remove it from your database. Use the manual deletion methods above.

---

## Summary

| Event on Telegram | Telepage behavior |
|---|---|
| New post published | ✅ Appears automatically via webhook |
| Post edited | ✅ Updated automatically via webhook |
| Post deleted | ⚠️ Must be removed manually from admin panel |
| Post deleted before scanner ran | ✅ Not imported (skipped automatically) |
