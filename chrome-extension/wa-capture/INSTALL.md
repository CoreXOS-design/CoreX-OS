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
   `https://corex.hfcoastal.co.za`) and paste the **device token** → Save.
5. Open `https://web.whatsapp.com` and use WhatsApp normally. New messages in
   open chats are captured automatically.

To stop capturing, click **Revoke** on the device in My Portal → WhatsApp
Capture (the token stops working immediately).
