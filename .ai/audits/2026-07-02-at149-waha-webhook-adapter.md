# AT-149 — WAHA → WaArchiveIngestor webhook adapter

**Jira:** AT-149 · **Date:** 2026-07-02 · **Type:** build (STAGING, HOLD for Johan) · **Relates:** AT-138 (server-side session), AT-143 (WAHA Core), AT-148 (voice-note media)
**Branch:** `AT-148-wa-voice-note-media` (stacked — depends on AT-148's `media[].url` seam).

The last piece connecting the 24/7 server-side WhatsApp session to the CoreX
Communication Archive. WAHA holds the session; this is the thin mapper that turns
WAHA's per-message webhook into the ingestor's `messages[]` item and hands it to
the **existing** `WaArchiveIngestor` — reimplementing nothing.

## Flow

```
WAHA session ──webhook POST──▶ /communications/wa/webhook
   [VerifyWahaWebhook middleware — HMAC-SHA512 over raw body, or shared secret; fail closed]
   ─▶ WaSessionWebhookController
        event ∈ {message, message.any}?  else 200 ignore
        resolve WAHA session → CommunicationWaDevice (agency + owning agent)
        WahaWebhookAdapter::map(payload) → messages[] item  (null = noise/malformed → 200 drop)
   ─▶ WaArchiveIngestor::ingest($device, $item)   [UNCHANGED]
        AT-122 match-first · AT-133 @lid guard · AT-136 consent · AT-135 body ·
        AT-148 media[].url download · occurred_at tz · thread · CommunicationLink
```

## Mapping (WAHA payload → ingestor item)

| ingestor item | source | notes |
|---|---|---|
| `message_id` | `payload.id` | dedup key |
| `chat_id` (thread) | `payload.from` (inbound) / `payload.to` (outbound) | the counterpart @lid chat |
| `direction` | `fromMe` / `_data.Info.IsFromMe` | out\|in |
| `counterpart_phone` (PRIMARY match) | `_data.Info.SenderAlt` stripped of `@s.whatsapp.net` | inbound only; outbound uses `payload.to` (SenderAlt would be the agent themself) |
| `counterpart_lid` (FALLBACK) | the counterpart jid | AT-133 resolver fires ONLY when the real phone is absent — @lid is now the fallback, not the primary |
| `text` | `body` \|\| `_data.Message.conversation` | |
| `timestamp` | `payload.timestamp` \|\| `_data.Info.Timestamp` | |
| `media[]` | `payload.media{url,mimetype,filename}` + `_data.Message.audioMessage.seconds` | → the AT-148 `media[].url` download seam |
| `name` | `_data.Info.PushName` | |

## Key decisions

- **Session attribution.** The ingestor requires a `CommunicationWaDevice` (agency +
  owning agent). WAHA identifies the session by name (`payload`.envelope `session`),
  so a nullable `communication_wa_devices.waha_session` column links one WAHA session
  to one device row. `device_token` relaxed to nullable — a server-session link has no
  per-device bearer token. A device row is now EITHER an extension device (token) OR a
  session link (session). Additive migration, no hard deletes. **No device matches the
  session → skip (can't attribute → never archive to a wrong agency).**
- **Auth.** `VerifyWahaWebhook` — WAHA-native HMAC-SHA512 over the raw body
  (`X-Webhook-Hmac`) OR a shared-secret header (`X-Webhook-Secret` / `Bearer`), both
  keyed off `communications.waha.webhook_secret`. **Fail closed** — no secret configured
  or neither verifies → 401. Never accepts an unauthenticated POST.
- **Noise filter (proven required, AT-138).** `status@broadcast`, `@g.us`, `IsGroup`
  dropped in the adapter BEFORE ingestion (and the ingestor keeps its own guard —
  defence in depth).
- **Robustness.** Malformed / partial / unknown-session / mapping-error / ingest-error
  → logged + **200** skip. The ONLY non-200 is the auth 401. A 500 would make WAHA
  retry-storm the same bad payload.
- **Outbound.** SenderAlt is the agent themself on `fromMe`, so outbound derives the
  counterpart from `payload.to`. Server-session outbound capture is secondary to the
  provisional-reconcile path; unmatched outbound simply drops (no wrong-contact match).

## Verification — `tests/Feature/Communications/WaSessionWebhookTest.php` — 8 passed, 42 assertions

- inbound text → mapped → **archived**; `direction=inbound`, `thread_key`=the @lid,
  `body_text` correct, `from_identifier`=`27713510291` (**zero raw @lid leakage**),
  linked to the matched contact.
- **HMAC-signed** payload accepted (WAHA-native path, raw-body signature).
- **voice note** with `media.url` → adapter → AT-148 `storeMedia` downloads (Http fake) →
  attachment `media_status=stored`, `duration=7`, mime `audio/…`, on the mounted-volume
  path `communications/{agency}/attachment/…`.
- `status@broadcast` **dropped**; group (`@g.us` + `IsGroup`) **dropped**; 0 rows archived.
- malformed (no id/from) → drop; non-array payload → skip; `session.status` event → ignored;
  **no 500** in any case.
- unknown session → skipped, not archived.
- **unauthenticated** POST (no secret / wrong secret) → **401**, 0 rows archived.

Migration applied on dev DB; schema snapshot regenerated (contains `waha_session`);
route `communications.wa.webhook` resolves.

## Pending Johan (live end-to-end — NOT done here)

Did NOT restart the capture session, surface a QR, or link a number. To go live on staging:
1. Set `WAHA_WEBHOOK_SECRET` in the env (and configure WAHA's webhook `hmac.key` OR a
   custom header with the same secret).
2. Point WAHA's webhook `url` at `…/communications/wa/webhook`, events `message` (or
   `message.any`).
3. Set `communication_wa_devices.waha_session` = the WAHA session name for the linked
   agent's device row.
4. Link the number in WAHA + send a test message / voice note → it archives + plays.
