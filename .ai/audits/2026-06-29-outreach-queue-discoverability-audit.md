# Outreach Queue / Scheduled WhatsApp — Discoverability Audit

**Date:** 2026-06-29 · **Type:** read-only discoverability audit · **Basis for:** `.ai/specs/outreach-queue.md`

## Headline
The ban-safe architecture is already locked in: every WhatsApp path is **click-to-chat deep-link →
agent taps Send manually**, never a programmatic send. The seller-outreach path already persists the
fully-rendered message and already gates consent at compose/send time. Missing: (1) a deferred
`due_at` dimension, (2) a work-the-list queue UI, (3) message persistence for the MIC/map "bare-link"
paths that store nothing today, (4) a configurable send-window lock.

## 1. WA send path per entry point — all deep-link, agent taps Send
Two schemes, per-agency: `https://wa.me/{phone}?text=...` (web, default) vs
`whatsapp://send?phone=...&text=...` (app). Toggle: `app/Models/Agency.php:36` (`WHATSAPP_LAUNCH_WEB`
default) + migration `2026_05_14_120001_add_whatsapp_launch_mode_to_agencies_table.php`. No
`web.whatsapp.com/send` construction anywhere (only the Chrome capture extension references it). No
server-side WA dispatch anywhere — email is the only `Mail::to()`-sent channel
(`SellerOutreachSenderService.php:163-170`).

- **(a) Contact** — inline quick box `corex/contacts/show.blade.php:304-310` `sendWa()`
  (`whatsapp://send?...` → `window.location.href`, 0→27 at `:305-306`, `increment()` analytics only);
  AND the Seller-Outreach Composer (routes `web.php:2023-2029`; link in
  `SellerOutreachSenderService::whatsappUrl() :183-198`; `ComposerController::submit() :259-279`
  returns `client_url`; launched `compose.blade.php:159-162`; manual-open fallback `sent.blade.php:19-26`).
- **(b) Map** — `corex/map/index.blade.php` actions `<a target="_blank">` (`:3019-3062`):
  `scheme_owners` direct `wa.me` + text (`:1876-1877`, **NO 0→27**, digits-only strip);
  `tracked_properties` routes into Composer (`:1900-1910`). Launch logging only:
  `Map/MapActivityController.php`.
- **(c) MIC** — plain `wa.me` anchors / `window.open`: core-match `match-results.blade.php:24`
  (0→27 `:8-11`); MIC slideover `_slideover-header.blade.php:27`, `_listing-row.blade.php:47`,
  `_slideover-buyer-row.blade.php:20` (bare, no `?text`); prospecting
  `_buyer-matches-panel.blade.php:122-128` (bare).

## 2. Is there an outreach queue today? — ABSENT
Outreach is one-contact-at-a-time. Composer routes single-entity only (`web.php:2021-2065`); no
campaign/blast/batch/bulk route. WhatsApp Outreach Summary board (AT-91) is a read-only counts matrix
(`WhatsappOutreachSummaryController.php:21-38`, `WhatsappOutreachSummaryService::board() :38-99`).
Outreach Canvassing board is reporting (`OutreachCanvassingController.php:28-73`). Tables are all
logging/archive: `seller_outreach_sends` (record of already-sent; `sent_at`, no `scheduled_at`/queued
status), `contact_outreach_log`, `communications`, `communication_pending`. No prepared-but-unsent
queue table.

## 3. Message composition & persistence — path-dependent (the crux)
Templates live in `seller_outreach_templates` (`SellerOutreachTemplate.php:19-29`), seeded:
AT-47/48 consent templates `HfcConsentTemplatesSeeder.php:145-169` (carry `{opt_out_link}` + STOP);
pitch templates `SellerOutreachTemplatesSeeder.php:85-145`; free-form override
`SellerOutreachComposerService.php:43-44,83-84`. Merge-fields (20 tokens) + `{?field}…{/field}`:
`SellerOutreachComposerService.php:227-307`; tracking/opt-out URLs substituted at send
(`SellerOutreachSenderService.php:81-86`).
- **Persists body:** seller-outreach (consent + pitch incl. MIC seller pitch) →
  `seller_outreach_sends.body_snapshot` written at send-click before WA invoked
  (`SellerOutreachSenderService::send() :95-120`); also mirrors to `communications` +
  `contact_outreach_log`. Contact comms-tile quick-send + mobile core-match share persist via
  `OutboundProvisionalLogger`.
- **Persists NOTHING:** desktop Core-Matches buyer share (`match-results.blade.php:22-26`), MIC
  buyer-match WA links, all map `wa.me` launches (at most an `agent_activity_events` audit row, no
  body — `MapWhatsAppLaunched.php:41-49`).

## 4. Consent gating — compile-time snapshot; queue must re-validate at dispatch
Engine `app/Services/SellerOutreach/MarketingConsentService.php:31` keeps four stores in sync
(`contact_consent_records`, `contacts.messaging_opt_out_*` triplet + `messaging_all_blocked`,
`contacts.opt_out_{email,sms,whatsapp,call}`, `marketing_suppressions`). Reads:
`isContactSuppressed() :233`, `isIdentifierSuppressed() :246`. Three-state master axis
`Contact::communicationStatus() :668` (`opted_in`/`marketing_opted_out`/`all_blocked` +
`transaction_only`); AT-81 5-state `outreachConsentState() :701`. Gated at compose in
`SellerOutreachComposerService::composeContext() :101-105` via `OutreachContext::isSendable() :46-49`;
enforced `ComposerController::submit() :190-205`; defense-in-depth `SellerOutreachSenderService::send()
:51-63`. GAP: `whatsappUrl() :183` and `sent()` re-launch (`ComposerController:282`) do NO consent
check. Consent is snapshotted at compose — a deferred queue MUST re-run the gate at surface/send.
Recommend a single `canMarketTo(Contact, channel)` on MarketingConsentService.

## 5. Scheduling infra to reuse
Scheduler in `routes/console.php` (Laravel 11). No generic `scheduled_at`/`surface_at` column.
Reusable patterns: **claim-and-sweep** `agency_webhook_deliveries.next_retry_at` (migration
`2026_06_02_100002:36,47`) swept by `RetryDueWebhookDeliveries.php:26-39` (`webhooks:retry-due`,
every minute — `where('next_retry_at','<=',now())` then claims by nulling before dispatch). UI
surfacing: `command_tasks.due_date` + `CommandTask` scopes (`:140-162`) + "Today" cockpit + 
`ProcessReminders.php` (`command-center:reminders`, 5-min window sweep, `metadata.reminder_sent`
de-dup). Existing outreach cadence: `outreach:recompute-no-response` daily 04:15 (`console.php:186`).

## 6. Proposed `outreach_queue` data shape
BelongsToAgency + SoftDeletes; cols: contact_id, property_id (nullable), agent_id, channel, source
(contact/map/mic), template_id (nullable), body_snapshot (REQUIRED — MIC/map persist nothing),
due_at (indexed), status (pending/surfaced/sent/dropped/expired/cancelled), claimed_at/surfaced_at/
sent_at/dropped_reason. On send → create `seller_outreach_sends` (reuse PPRA evidence/opt-out/tracking).

## Gaps the spec must close
1. Persist `body_snapshot` for MIC/map paths (store nothing today).
2. Re-validate consent at surface/dequeue (today's gate is compose-time snapshot).
3. Unify phone normalization (map `scheme_owners` skips 0→27, `index.blade.php:1877`).
4. Add the configurable send-window lock on every dispatch surface (AT-117 step 1).
