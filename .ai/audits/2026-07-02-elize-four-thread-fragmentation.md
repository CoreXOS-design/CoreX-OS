# Investigation — Elize (contact 10298) shows FOUR WhatsApp threads

**Date:** 2026-07-02 · **Type:** investigation ONLY (no code, Johan approves before any fix) · **Env:** staging (`hfc_staging`) · **Relates:** AT-138 (group/broadcast noise filter), AT-148/149 (held branches carrying the filter), AT-127 (thread visibility)

## TL;DR — the exact cause

It is **NOT** one conversation fragmenting, **NOT** an @lid/phone mismatch, and **NOT** an
extension-vs-WAHA mismatch. It is a **missing group-chat noise filter on the deployed
staging ingestor.** Three of Elize's four "threads" are **WhatsApp GROUP chats** she is a
participant in; only one is her real 1:1 conversation. Because the deployed
`WaArchiveIngestor` on staging has **no group filter**, each group message whose sender
resolved to a CoreX contact was archived and linked to that contact — so every group
Elize is in surfaces on her contact record as a separate "thread".

## 1. How `thread_key` is computed

WhatsApp `thread_key` is stored **verbatim as the WhatsApp chat id** — no normalisation,
no contact/agent/date/session input:

- `app/Services/Communications/WaArchiveIngestor.php:49` — `$chatId = (string) ($msg['chat_id'] ?? '')`
- `app/Services/Communications/WaArchiveIngestor.php:194` — `'thread_key' => $chatId`

Spec confirms this is by design: `.ai/specs/claude_communication_archive_spec.md:53`
(“WA chat id (per contact/group)”) and `:106` (“thread_key=chat id”). So one thread =
one WhatsApp chat. **The chat id for a 1:1 is the counterpart's @lid; for a group it is
the group's `…@g.us` id.** AT-127 governs *visibility* grouping only, not derivation.

The contact LINK is separate from the thread key: the ingestor resolves the message
**sender** to a contact (`WaArchiveIngestor.php:110` → `ContactIdentifierResolver::resolve($matchNumber)`)
and links the archived row to that contact (`:241`). So a message can be **keyed by a
group** yet **linked to the individual sender** — which is exactly what happened.

## 2. Why FOUR — the four Elize thread records (staging)

| thread_key | msgs | last msg | kind | distinct contacts linked | source |
|---|---|---|---|---|---|
| `120363406141318837@g.us` | 2 | 01 Jul 20:10 | **GROUP** | 2 (10298, 16069) | `wa_device:11` (extension) |
| `27783098955-1467809184@g.us` | 3 | 01 Jul 19:26 | **GROUP** | 2 (10298, 16069) | `wa_device:9/11` (extension) |
| `222758646611979@lid` | 22 | 01 Jul 16:00 | **1:1 (real)** | 1 (10298 only) | `wa_device:7/8/9/10/11` (extension) |
| `27766185578-1456235253@g.us` | 2 | 01 Jul 14:02 | **GROUP** | **7** (8625, 8996, 10298, 10455, 14293, 16069, 16188) | `wa_device:11` (extension) |

The four times in the report (20:10 / 19:26 / 16:00 / 14:02) map exactly to these rows.

**What differs between the four keys:** three keys end in `@g.us` (group chats) and one
ends in `@lid` (the 1:1). The distinct-contact count is the proof: the real 1:1 links to
**only Elize**; the group threads link to **2, 2 and 7** different CoreX contacts (the
group members). A 7-contact "thread" cannot be a 1:1 conversation — it is a group.

Elize's from_identifier is `27713510291` on all four (her number was the sender that
matched), and the group messages' `external_id` even embeds her @lid
(`…_222758646611979@lid`) — i.e. these are Elize's own posts *inside* those groups,
captured and mis-attributed as separate conversations.

## 3. Extension vs WAHA — all four are EXTENSION

- Every thread's `source_ref` is `wa_device:*` (the Chrome-extension capture path). None
  is WAHA.
- The AT-149 WAHA adapter is **not deployed on staging**: `WahaWebhookAdapter.php` and
  `WaSessionWebhookController.php` do not exist under `/corex-staging`. So no WAHA rows
  exist and the two paths cannot have keyed the same conversation differently here.
- The two paths would in fact key identically (both store the raw chat id), so this is
  not a path-mismatch class of bug.

## 4. @lid vs phone — NOT the cause

The real 1:1 is keyed **only** by `222758646611979@lid` (22 messages, one contact) — there
is **no** `@c.us`/phone-keyed duplicate of it. Archive-wide: **80** `@lid` messages,
**0** `@c.us` messages. So no conversation is keyed both ways; the @lid/phone-split class
does not occur in this data. The 1:1 conversation is intact as a single thread.

## 5. Intended behaviour vs what's happening

- **Spec (original):** `thread_key` = the WA chat id, one thread per chat “per contact/group”.
- **AT-138 refinement (empirically validated, in the audit + memory):** the ingestor MUST
  **skip `status@broadcast` and `IsGroup`** — WhatsApp capture is for **1:1 contact
  conversations** (FICA), groups/broadcasts are noise and must never be archived.
- **What's happening on staging:** the deployed `WaArchiveIngestor` has **no such filter**
  (`grep` for `isNoiseChat` / `@g.us` / `status@broadcast` / `is_group` in
  `/corex-staging/app/Services/Communications/WaArchiveIngestor.php` → none). So group and
  broadcast messages are ingested and linked per-participant. The filter WAS built — it
  lives in `WaArchiveIngestor::isNoiseChat()` on the held **AT-148** branch and in the
  AT-149 `WahaWebhookAdapter` — but neither is on Staging yet.

**Blast radius (staging, archive-wide, not just Elize):** 41 group messages across **5**
group threads + **15** `status@broadcast` messages are archived and attached to whichever
contacts participated. Every group member sees the group as an extra thread.

## Recommended fix (for approval — NO code yet)

**A. Prevent (root cause).** Deploy the group/broadcast noise filter to the authoritative
server-side ingestion gate. It already exists — `WaArchiveIngestor::isNoiseChat()` (drops
`thread_key`/chat_id ending `@g.us`, `status@broadcast`, or `is_group==true`) on the AT-148
branch, and mirrored in the AT-149 WAHA adapter. Promoting AT-148 (or cherry-picking just
the guard) stops all future group/broadcast ingestion, regardless of capture path. The
server is the authoritative gate even with the extension deleted.

**B. Remediate the existing noise (NO hard deletes).** The 5 group threads + broadcast
messages already archived should stop surfacing on contact records. Recommended: a one-off,
agency-scoped, logged **soft-purge** (set `communications.purged_at` + a
`purged_reason` like `group_chat_not_captured` / `broadcast_not_captured`) for WhatsApp
rows whose `thread_key` ends `@g.us` or contains `status@broadcast`. The archive viewer
already filters on `notPurged()`, so the spurious threads disappear from Elize (and every
other participant) while the rows remain fully recoverable — consistent with the
no-hard-delete rule. This is a sweep across ALL affected contacts, not an Elize-only fix.

**C. Do NOT re-key or merge.** The real 1:1 (`@lid`) is already correct and whole; nothing
needs merging. The group threads must be removed from capture, not folded into the 1:1.

**Open question for Johan:** confirm groups/broadcasts should be purged from the existing
archive (recommended), vs left in place and merely hidden by the go-forward filter. Also
confirm the same remediation runs on live before/after the WAHA cutover.

---

## RESOLUTION — approved A + B on STAGING (2026-07-02, AT-151)

Johan approved A + B on **staging only**; C confirmed (no re-key/merge); live is a
separate later step (no live changes). Shipped on branch `AT-151-wa-group-broadcast-filter`
→ **Staging merge `c3f49437`**, deployed to `/corex-staging` (php8.2-fpm reloaded, worker
restarted). NOT promoted to live.

**A — prevent (deployed).** `WaArchiveIngestor::ingest()` now calls `isNoiseChat($chatId,$msg)`
right after the id/chat validation → `RESULT_DROPPED` for `thread_key` ending `@g.us`,
containing `status@broadcast`, or `is_group==true`. This is the server-side authoritative
gate — it runs for every capture path (extension today, WAHA adapter later). Proven on the
deployed staging code (rolled-back tinker): a `@g.us` and a `status@broadcast` message both
returned `dropped`, 0 rows created.

**B — remediate (run).** New `communications:purge-wa-noise` command (agency-scopable,
`--dry-run`, logged) soft-purged **56 WhatsApp messages across 6 group/broadcast threads**
(agency 1) — `purged_at` + `purged_reason='group_broadcast_noise'`. NO hard deletes; rows
retained and recoverable; content bytes untouched.

**Verify (staging):**
- Elize (10298) now shows **only** `222758646611979@lid` (22 msgs) — the three group
  threads (20:10 / 19:26 / 14:02) no longer surface.
- Archive-wide unpurged group/broadcast rows: **0**.
- 1:1 `@lid` messages still visible/intact: **80**.
- 56 rows purged + retained (queryable, `purged_reason=group_broadcast_noise`).
- Test `WaGroupBroadcastFilterTest` 3/3 (15 assertions).

**LIVE (read-only, nothing changed):** `nexus_os` has **123** WhatsApp comms (63 visible),
and **every** visible one has `thread_key = NULL` — there are **0** `@g.us` / `status@broadcast`
/ `@lid` / `@c.us` rows. So live has **no group/broadcast leak** to remediate: the inbound
capture pipeline that produces `@lid`/`@g.us` keys only runs on Staging; live's WA rows are
manual/provisional outbound with null thread_key. Johan decides live remediation later (there
is currently nothing to purge) once real inbound capture goes live behind the filter.
