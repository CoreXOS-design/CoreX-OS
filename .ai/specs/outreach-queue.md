# CoreX OS — Outreach Queue (Deferred WhatsApp) Specification

**Spec ID:** AT-XX (assign on ticket creation)
**Status:** Draft — ready for build
**Author:** Johan (product architect) + Claude (senior engineer)
**Date:** 2026-06-29
**Depends on / reuses:** Seller-Outreach module (AT-49), MarketingConsentService + opt-out engine
(AT-49/50), three-state contact communication status (AT-50), AT-81 outreach sub-states,
`seller_outreach_sends` send-record pipeline, the `next_retry_at` due-time sweep pattern.
**Audit basis:** `.ai/audits/2026-06-29-outreach-queue-discoverability-audit.md`

---

## 1. Purpose & Doctrine

An agent wants to **prepare WhatsApp outreach the night before and work through it the next
morning** — e.g. line up 20 messages tonight, set them due 8am, then at 8am rapid-fire down a
ready list instead of composing from scratch. The Outreach Queue front-loads the thinking work
(who to message, what to say) into a quiet evening, leaving the morning as fast, tap-through
sending.

**The locked architectural truth (do not re-open):** sending is **deep-link + agent-taps-send**.
CoreX opens a pre-filled WhatsApp chat (`wa.me/...` or `whatsapp://send?...` per the existing
per-agency scheme); the agent physically presses Send inside WhatsApp. **There is NO programmatic
send, no private WA service, no Cloud API.** A service pressing send — even agent-triggered, even
one at a time — reintroduces WhatsApp ban risk on HFC's primary business number, which is
catastrophic. The human pressing send is what keeps it ToS-safe and ban-free.

**Therefore "scheduling" does NOT mean auto-send.** It means: deferred *preparation* + a *due-time*
at which the prepared message *surfaces* in the agent's queue, ready to be sent by hand. CoreX
schedules *when the work appears*, never *the send itself*. This must be stated plainly in the UI
so no agent expects messages to fire on their own.

**Two compliance/data requirements are load-bearing (see §4, §5), not optional polish.**

---

## 2. Entry Points & Flow

**Prepare (any time — typically evening):** from a contact, the map, or the MIC, an agent prepares
a WhatsApp outreach message and **adds it to the queue with a due-time** (default options: "tomorrow
8am", "later today", custom datetime). The fully-rendered message body is persisted at this moment.

**Surface (at due-time):** a minute-by-minute sweep flips due rows from `pending` → `surfaced`,
**re-validating consent first** (§4). Consent-revoked rows are `dropped`, never surfaced.

**Work the list (agent UI):** the agent opens the Outreach Queue. Surfaced rows are a work-list.
Each row → deep-links to the pre-filled WhatsApp chat (existing `whatsappUrl()` builder) → agent
taps Send in WhatsApp → returns → marks the row sent → next row → repeat until empty.

**Record (on send):** marking a row sent creates a `seller_outreach_sends` record (reusing the
existing PPRA send-evidence, opt-out token, and tracking pipeline — do NOT fork it).

---

## 3. Preparation & Message Persistence

**The message body MUST be persisted at prepare time** (`body_snapshot`), for every source. This is
critical because the sources differ today:
- **Seller-Outreach composer path** (contact pitch, MIC seller "I have a buyer" pitch): already
  renders + persists the body. The queue reuses this.
- **MIC buyer-match WA, Core-Matches share, map `wa.me` launches**: these persist NOTHING today —
  they compose-in-browser-and-fire. For these to feed the queue, the rendered text MUST be captured
  and stored as `body_snapshot` at prepare time. There is nothing to capture later.

**Composition** reuses the existing template engine (`seller_outreach_templates`, merge-fields,
`{?field}…{/field}` optional segments, free-form `$bodyOverride`). Tracking/opt-out URLs that are
normally substituted at send time must be substituted at the correct moment — see §4 (some tokens,
like `{opt_out_link}`, should resolve at SEND/surface time, not prepare time, so the token is fresh).

**Phone normalization:** all queued numbers must be normalized to dialable form (0→27 etc.). Fix the
map `scheme_owners` path which currently skips 0→27 (audit §1) — unify normalization so every queued
number is reliably dialable regardless of source.

---

## 4. Send-Window Lock + Consent at Dispatch (THE SPINE)

Two separate concerns, deliberately kept separate: **WHEN** an agent may dispatch (the send-window,
a setting) and **WHO** may be marketed to (the existing consent engine). The feature does not need to
solve compliance in code — it makes the window a configurable agency setting and reuses the consent
engine that already works.

### 4a. Agency-configurable send-window (the timing lock)

Permitted send days + per-day start/end times are an **agency setting**, not hardcoded law. CoreX
ships sensible, legally-correct DEFAULTS but the agency owns the values — if the regulation differs
or changes, the agency adjusts a setting, no code change, and CoreX makes no hardcoded legal claim.

**Default values** (match SA CPA direct-marketing permitted times — agency-editable):
- Mon–Fri: 08:00–20:00
- Saturday: 09:00–13:00
- Sunday & public holidays: OFF (no sending)

**The lock applies on DISPATCH, from EVERY outreach surface — not only the queue.** The agent's
"Open WhatsApp / send" action (contact, map, MIC, and the queue) is blocked outside the configured
window — an agent cannot fire outreach at 21:00 from anywhere. Outside the window, the send action
is disabled with a clear message ("Outreach sending is allowed Mon–Fri 08:00–20:00, Sat 09:00–13:00 —
schedule this for the next window"), and the natural path becomes: add to the queue with a due-time
inside the next window. This is why the queue exists — prepare in closed hours, send when it opens.

**Dispatch-time is the compliance anchor (not receipt).** The regulation puts the onus on the
marketer to prove *dispatch* was in-window (receipt timing is irrelevant). So: (1) the lock gates the
dispatch action, and (2) CoreX TIMESTAMPS the in-window dispatch as the agency's evidence (recorded
on the `seller_outreach_sends` row). Public holidays: the default treats SA public holidays as off —
use the existing public-holiday source if one exists in CoreX, else a configurable holiday list
(agency setting); if none available at build time, ship weekday/Saturday window first and note
public-holiday handling as a fast-follow (flag it, don't silently skip).

### 4b. Consent at dispatch (reuse the existing engine — keep it simple)

Marketability is the consent engine's job, and it already works. At the **dispatch action**, re-check
the contact is still marketable via the existing engine — a contact who opted out between queueing
and sending must not be sendable. Use a single consolidated predicate
`canMarketTo(Contact $contact, string $channel): bool` on `MarketingConsentService` wrapping the
existing checks (`isContactSuppressed`, three-state `communicationStatus()`, AT-81 outreach sub-state,
`Contact::canSendVia($channel)`) so the queue (and the rest of outreach) has one clean call. A queued
row that fails `canMarketTo()` at dispatch is `dropped` (status, with `dropped_reason`), never sent.

This is a normal reuse of the existing engine at the send action — NOT a heavy per-surface
re-validation framework. The setting handles timing; the consent engine handles marketability; both
already exist. The `{opt_out_link}` token resolves at dispatch so each message carries a current
opt-out path.

> **Out of scope / future refinement:** the NCC opt-out registry "verification window" cadence (e.g.
> how often a sanitised contact must be re-checked against the NCC registry once it opens July 2026)
> is a separate compliance-engine concern, NOT a dependency of this feature. The consent engine gates
> marketability today; optimising NCC re-check cadence is a later, separate ticket. Do not block this
> build on it.

---

## 5. Surfacing Mechanism (reuse, don't invent)

Reuse the proven **claim-and-sweep** due-time pattern (`agency_webhook_deliveries.next_retry_at` →
`RetryDueWebhookDeliveries`, audit §5):
- `due_at` indexed column.
- An every-minute scheduled command sweeps `where('due_at','<=',now())` for `status=pending`,
  **claims each row** (set `claimed_at` / flip status) before processing to avoid double-fire,
  runs `canMarketTo()`, then sets `surfaced` (or `dropped`).
- For the agent UI, model the work-list surfacing on the `command_tasks` / "Today" cockpit pattern
  (scopes + cache bust on write) so queued items appear in an agent surface consistently with the
  rest of CoreX.

Register the sweep in `routes/console.php` alongside existing outreach cadence
(`outreach:recompute-no-response`).

---

## 6. The Queue UI (work-the-list)

A dedicated **Outreach Queue** screen (with a nav entry — hard rule):
- Lists the agent's `surfaced` rows (and optionally upcoming `pending` rows in a separate
  "scheduled" section so the agent can see/edit what's lined up).
- Each surfaced row shows: contact name, the prepared message (preview), source badge
  (contact/map/MIC), property context if any, and a primary **"Open WhatsApp"** action.
- "Open WhatsApp" → deep-links via the existing `whatsappUrl()` builder (per-agency scheme) to the
  pre-filled chat. Agent taps Send in WhatsApp.
- On return, the agent marks the row **Sent** (this records to `seller_outreach_sends`). Honest
  status: CoreX can only confirm the chat was *opened*, not that the message was *sent* — so "sent"
  is an agent-confirmed action (or optimistic-on-open with a confirm), and analytics must reflect
  that honesty.
- Rows that were `dropped` (consent revoked) are not shown as actionable; optionally a small
  "removed — opted out" note for transparency.
- Editing/cancelling a `pending` (not-yet-surfaced) row is allowed; cancel = soft-delete/archive.

**Prepare-to-queue entry points:** add "Schedule / add to queue" alongside the existing "send now"
WhatsApp actions on the contact, map, and MIC surfaces — same compose, plus a due-time picker.

---

## 7. Data Model

New table `outreach_queue` — `BelongsToAgency` (auto agency_id), `SoftDeletes`:

- `id`, `agency_id`
- `contact_id` → contacts (the consent subject)
- `property_id` → properties (nullable — listing/match context)
- `agent_id` → users (who prepared / works the row)
- `channel` (whatsapp — mirror the seller-outreach channel enum; built to extend)
- `source` (contact / map / mic)
- `template_id` → seller_outreach_templates (nullable)
- `body_snapshot` (the prepared, merge-rendered text — REQUIRED; MIC/map persist nothing today)
- `due_at` (indexed — the deferred surface time)
- `status` (pending / surfaced / sent / dropped / expired / cancelled)
- `claimed_at`, `surfaced_at`, `sent_at`, `dropped_reason`
- timestamps + softDeletes

**On send → create a `seller_outreach_sends` row** so the existing PPRA send-record, opt-out token,
and tracking pipeline are reused, not forked. The queue is the *preparation/surfacing* layer; the
send-record remains the canonical evidence layer.

---

## 8. Configurability (hard rule)

Agency-configurable settings, sensible defaults:
- **Send-window (the spine — see §4a):** permitted days + per-day start/end times. Default Mon–Fri
  08:00–20:00, Sat 09:00–13:00, Sun/public-holidays off. Agency owns the values.
- Default due-time presets (e.g. "tomorrow 08:00" = the next window open) and whether custom
  datetimes are allowed (custom times must still fall inside the send-window).
- Optional cap on queue size / messages per agent per surface window (default: none, or a sane
  limit) — to keep volume in defensible, non-spammy territory per the compliance posture.
- `expired` handling: a surfaced-but-never-sent row's lifetime before it auto-expires (default: end
  of the current send-window day) so stale rows don't linger and get sent days late to a possibly
  now-opted-out contact.
- Reuse the existing per-agency WhatsApp launch scheme (web vs app) — no new toggle.

---

## 9. Robustness & Hard Rules

- **No hard deletes** — cancel/expire/drop = soft-delete or status transition, archived & auditable.
- **AgencyScope** on the table and every query — queue rows never cross agencies.
- **Consent re-validation is mandatory** at surface AND send (§4) — a row that fails `canMarketTo()`
  at any check is dropped, never sent.
- **Claim-before-process** in the sweep to prevent double-surface/double-fire.
- Whole input space: contact with no phone (cannot queue — clear error); message with empty body
  (reject at prepare); due_at in the past (surface immediately on next sweep); contact opted out
  *before* queueing (block at prepare, same as compose-time gate today); opted out *after* queueing
  (drop at surface).
- Phone normalization unified (0→27 across all sources incl. the map scheme_owners gap).

---

## 10. Out of Scope (this build)

- **Any programmatic/automated WhatsApp send.** Explicitly excluded by the locked architecture —
  agent always taps Send. This is not a limitation to "fix later"; it is the design.
- WhatsApp Cloud API / template-based official sending — a separate strategic decision (the
  template-driven path reshapes outreach UX and is not this feature).
- Email-channel scheduling — email IS server-sendable, so a true scheduled email send is a different
  (and simpler) feature; could reuse this queue's due-time model but is not in this build.
- Bulk/blast composition — this is per-contact preparation worked as a list, NOT a one-click
  fire-all (which would be both a ban pattern and a compliance risk).

---

## 11. Build Order

1. **Send-window setting + dispatch lock (§4a) FIRST** — the agency-configurable permitted
   days/times setting (defaults: Mon–Fri 08:00–20:00, Sat 09:00–13:00, Sun/holidays off) + a shared
   "is sending allowed right now" guard applied to the dispatch action on EVERY outreach surface
   (contact, map, MIC). This stands alone and is valuable immediately — agents can't send outside
   permitted hours from anywhere. Plus in-window dispatch timestamping for evidence.
2. `canMarketTo(Contact, channel)` consolidated consent predicate on MarketingConsentService (§4b) —
   one clean call wrapping the existing checks; used at dispatch.
3. `outreach_queue` table + model (BelongsToAgency, SoftDeletes).
4. Prepare-to-queue on the seller-outreach composer path (already persists body) — the cleanest
   first source — with a due-time picker constrained to the send-window.
5. The claim-and-sweep command (reuse `next_retry_at` pattern) → surface due rows, re-check
   `canMarketTo()` at surface, drop the opted-out. Register in console.php.
6. The Outreach Queue UI (work-the-list, deep-link via whatsappUrl, mark-sent → seller_outreach_sends).
7. Extend prepare-to-queue to MIC and map sources (capturing body_snapshot, since they persist
   nothing today) + fix the map 0→27 normalization gap.
8. Configurability (due-time presets, expiry, optional caps), nav link, full lifecycle CRUD,
   robustness pass.
