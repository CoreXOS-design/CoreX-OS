# Investigation — false "no response" auto opt-out on imported contacts (HFC / agency 1)

**Date:** 2026-08-17 · **Status:** READ-ONLY investigation complete — remediation HELD for Johan's explicit go (consent/POPIA data) · **Scope:** live `nexus_os`, agency 1

## TL;DR

- **632 of 647** contacts flagged `no_response` marketing opt-out on HFC (agency 1) are **wrongly flagged** — auto-opted-out for "no response" when **no message was ever actually delivered** to them.
- Root cause: the no-response lapse keys off the **pitch signal** (`seller_outreach_sends.outcome = 'sent'` + a clock started at pitch-compose time), **not off an actually-delivered message**. For WhatsApp — 648 of 649 of these sends — `outcome='sent'` means only that a *click-to-chat pitch was composed*, **never that a message was delivered** (AT-323: CoreX cannot confirm WhatsApp delivery; the truthful signal is the mirrored `communications.send_status = 'sent'`, set only when the agent confirms "Yes I sent it").
- The pitches were a **bulk blast at/near import** (378/649 sends on the same calendar day as the contact's `created_at`; 472/649 in 2026-07). So in effect the clock ran from import, exactly as reported.
- Nothing else writes this flag: the ONLY writer of contact `messaging_opt_out_kind='no_response'` is the scheduled command `outreach:recompute-no-response` (all 647 carry `messaging_opt_out_source='system:no_response'`).

## 1. The mechanism

**Scheduled command:** `app/Console/Commands/RecomputeOutreachNoResponse.php` (`outreach:recompute-no-response`), wired in `routes/console.php:324` → `dailyAt('04:15')`.

It lapses a contact to a marketing-only opt-out with `kind=no_response` (via `MarketingConsentService::optOutContact(blockAll:false, kind:no_response)`) when ALL hold:
- `outreach_permission_asked_at` is set and older than the agency window (`RecomputeOutreachNoResponse.php:49, 73`);
- the latest `SellerOutreachSend.outcome === 'sent'` and `first_clicked_at IS NULL` (`:80-92`);
- not in a live transaction (`:95`).

**Window:** `AgencyContactSettings::outreachNoResponseDays()` → column `outreach_no_response_days` (default 7). Agency-configurable; not the bug.

**Clock start:** `Contact::markOutreachPending()` (`app/Models/Contact.php:1227`) stamps `outreach_permission_asked_at`. Its **only** caller is `app/Services/SellerOutreach/SellerOutreachSenderService.php:167` — fired when a **pitch is composed/sent**, inside the send transaction.

## 2. Window start — the exact defect (file:line)

The clock is started at **pitch-compose time**, and the lapse gate treats the **pitch outcome** as proof we contacted someone:

- **`SellerOutreachSenderService.php:167`** — `markOutreachPending()` runs for every composed pitch. For WhatsApp (`Communication::CHANNEL_WHATSAPP`) the same block (`:156-159`) deliberately marks the mirrored `Communication` as `send_status = not_delivered` because **CoreX cannot confirm a click-to-chat was actually sent** — yet the no-response clock is started anyway.
- **`RecomputeOutreachNoResponse.php:87-92`** — the "we sent something" gate accepts `SellerOutreachSend::OUTCOME_SENT`. Per the model's own AT-323 contract, for WhatsApp that is the *prospecting/pitched* signal, **not** a delivery signal.

So the window measures from "pitch composed", and the send-gate is satisfied by "pitch composed" — neither requires an actually-delivered message. You cannot be "no response" if nothing was ever sent.

## 3. Damage on HFC (agency 1) — quantified (live `nexus_os`, read-only)

| Metric | Count |
|---|---|
| Total agency-1 contacts (not deleted) | 8,973 |
| Opted-out (any kind) | 741 |
| — `declined` (explicit) | 85 |
| — **`no_response` (auto)** | **647** |
| `no_response` with `source='system:no_response'` | 647 / 647 |
| `no_response` sends by channel | **648 WhatsApp, 1 email** |
| Sends same calendar day as contact import | 378 / 649 |
| **With delivery evidence** (email OR WhatsApp comm `send_status='sent'`) | **15** |
| **WRONGLY FLAGGED (no delivered message)** | **632** |
| — no linked communication at all (pre-AT-323, unconfirmable) | 623 |
| — linked communication explicitly `not_delivered` | 10 |
| Active `marketing_suppressions` (`source='system:no_response'`) | 806 rows / 648 contacts / 806 identifiers |
| `contact_consent_records` revoked by the lapse | 0 (imported contacts held no prior marketing consent) |

**Headline: 632 contacts on agency 1 are wrongly opted-out "no response" with no evidence any message was ever delivered.** (Conservative — the 15 with delivery evidence are excluded even though some may also be arguable.)

## 4. Root cause + the logic fix

**Root cause:** the no-response lifecycle is anchored on *pitching* rather than *delivery*. `outreach_permission_asked_at` is stamped at pitch-compose, and the lapse accepts `SellerOutreachSend.outcome='sent'`, which for WhatsApp click-to-chat does not mean delivered. A bulk pitch blast at import therefore auto-opts-out contacts who were never actually messaged.

**Fix (start the clock from an actually-delivered outreach; never lapse without one):**

1. **Clock start = confirmed delivery, not pitch-compose.** Move/gate `markOutreachPending()` so `outreach_permission_asked_at` is set only when a message is *actually sent*:
   - Email (system-sent via `Mail::send`) → confirmed at send → start clock then (current email behaviour is fine).
   - WhatsApp → start the clock at the "did you send? → Yes" confirmation that flips `communications.send_status` to `sent` (AT-323), **not** at pitch-compose. If the agent never confirms, the clock never starts.
   - Net effect: **no delivered message ⇒ `asked_at` never set ⇒ never a lapse candidate.**
2. **Belt-and-braces gate in `RecomputeOutreachNoResponse`:** require the latest send to have delivery evidence — a linked `communication` with `send_status='sent'` (WhatsApp) or an email send — and measure the window from that delivery time, not `asked_at`. Reject `outcome='sent'` alone for WhatsApp.

Either point closes it; doing both is defence-in-depth. This is a code change for the QA1 branch (no schema change needed).

## 5. Remediation proposal for the wrongly-flagged 632 — HELD for Johan's go

**Goal:** reverse the wrongful auto opt-out for the 632, reversibly and snapshot-backed, touching ONLY system-lapsed `no_response` records (never the 85 explicit `declined`, never any human opt-out).

**Selection (exact):** agency 1, `messaging_opt_out_kind='no_response'` AND `messaging_opt_out_source='system:no_response'` AND no delivery-evidence send (as defined in §3). Snapshot the full set first.

**Reversal (all reversible; `optOutContact` wrote across 3 places):**
1. **Snapshot** the 632 contacts' opt-out triplet (`messaging_opt_out_at, _reason, _recorded_by_user_id, _source, _kind`) and the `marketing_suppressions` ids to a backup table (e.g. `_backup_no_response_reversal_20260817`) or CSV in storage.
2. **Contacts:** clear `messaging_opt_out_at, messaging_opt_out_reason, messaging_opt_out_recorded_by_user_id, messaging_opt_out_source, messaging_opt_out_kind` → NULL (restores to INITIAL — NOT opted-in, just un-suppressed). `messaging_all_blocked` was never set (blockAll=false), so nothing to undo there.
3. **`marketing_suppressions`:** set `lifted_at = now()` (the model's built-in reversible lift) on the 806 `system:no_response` rows for these contacts — otherwise a re-import stays blocked agency-wide (AT-49).
4. **`contact_consent_records`:** nothing to restore (0 were revoked).
5. **(optional) `seller_outreach_sends`:** the command also flipped the latest send `outcome` `sent→no_response` (`RecomputeOutreachNoResponse.php:120-124`). Reverting to `sent` is cosmetic for consent but keeps the audit tidy; include in the snapshot either way.

**Guardrails:** run inside a transaction; dry-run count first (must equal 632); build as an idempotent, reversible artisan command (e.g. `outreach:reverse-false-no-response {--agency=} {--dry-run} {--apply}`) with the snapshot written before any write; **do NOT auto-opt-in** — only remove the wrongful suppression. Ship the §4 code fix in the same change so the next 04:15 run does not re-lapse them.

**⛔ HOLD:** no consent flag is touched until Johan gives an explicit go. This document is the record; the reversal command is not written yet.

## Evidence / method
Read-only probes against live `nexus_os` (agency 1), since removed from the live serving dir. Code refs are against live `/corex` @ `main` (`afe6abb01`). No data was modified.
