# CoreX WhatsApp Capture — install (read-only)

This extension captures your **business** WhatsApp Web conversations into the
CoreX Communication Archive. It is **read-only** — it observes the chat DOM that
WhatsApp Web has already rendered and sends it to CoreX. It never sends a
WhatsApp message and never automates the compose box. Only conversations with
people already loaded as CoreX contacts are archived (everything else is dropped
after a short grace window — your personal chats are not retained).

## Install

1. In CoreX, open **My Portal → WhatsApp Capture** and click *Register Device &
   Issue Token*. Copy the token shown (it is shown once).
2. Download `wa-capture-extension.zip` from that page and unzip it.
3. In Chrome go to `chrome://extensions`, enable **Developer mode**, click
   **Load unpacked**, and select the unzipped `wa-capture` folder.
4. Click the extension icon → set your **CoreX URL** (e.g.
   `https://corexos.co.za`) and paste the **device token** → Save.
   If yours still reads `https://corex.hfcoastal.co.za`, change it — that
   hostname now only redirects (it serves no application since 2026-07-17).
   Capture keeps working either way (the redirect preserves POST bodies), but
   every request pays a needless extra hop.
5. Open `https://web.whatsapp.com` and use WhatsApp normally. New messages in
   open chats are captured automatically.

## Options (extension popup)

- **Backfill history for known contacts** (default ON): while WhatsApp Web is
  open and you are not actively using it, the extension quietly walks your chat
  list one chat at a time, paced like a human. For chats whose number is already
  a CoreX contact it backfills the full visible history; for everyone else it
  only captures from now forward. It is still **read-only** — it only opens
  chats (the same click you would make) and never touches the compose box. It
  pauses the moment you start using WhatsApp again and returns you to your chat.
- **Verbose console logging** (default OFF): turn on for troubleshooting, then
  open DevTools → Console.

## What you should see (verifying it works)

Open DevTools (F12) → **Console** on the WhatsApp Web tab. You should see, even
with verbose logging off:

- `[CoreX WA] content script loaded on web.whatsapp.com — debug: … | history sweep: …`
- when you open a chat: `[CoreX WA] sweep[…] chat <jid> — matched N message rows`
- when new messages send: `[CoreX WA] POST ok — status 200 | stats {"archived":1}`

Back in CoreX, **My Portal → WhatsApp Capture** shows the device's
**Last seen** stamp updating, and archived messages appear in the contact's
Communications tab.

To stop capturing, click **Revoke** on the device in My Portal → WhatsApp
Capture (the token stops working immediately).
