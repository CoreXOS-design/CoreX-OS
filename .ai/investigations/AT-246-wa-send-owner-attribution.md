# AT-246 — WhatsApp / comms manual-send owner attribution bug

**Investigation only. No code changed.** Branch `AT-246`. Date 2026-07-14.

Live-probe facts taken as given: on live, **241 of 649 `communications` rows have `owner_user_id` NULL, and ALL 241 carry `source_ref LIKE 'manual:user:NN'`** where NN is a `users.id`. 100% backfillable, zero non-backfillable NULL-owner rows.

---

## 1. Real table — `communications`

The ticket title loosely says `seller_outreach_sends`, but that table attributes via `agent_id` and has no `source_ref`; `contact_outreach_log` attributes via `actor_user_id`. **Only `communications` carries BOTH `owner_user_id` and `source_ref`**, so it is the only table where the "owner NULL but source_ref names the agent" defect can exist.

Schema (`database/schema/mysql-schema.sql`, table `communications`):
- `direction enum('inbound','outbound') NOT NULL`
- `source_ref varchar(512) NULL`
- `owner_user_id bigint unsigned NULL`, FK `comm_owner_user_fk` → `users(id) ON DELETE SET NULL`, indexed `comm_agency_owner_idx (agency_id, owner_user_id)`
- `created_at` / `updated_at` present (nullable timestamps)
- **There is NO `agent_id` / `sender_user_id` column** on `communications`. Before `owner_user_id`, the *only* agent attribution was the string `source_ref`. So `owner_user_id` is the sole first-class attribution column, and for the 241 rows it disagrees with `source_ref`.

---

## 2. Write path (root cause)

**`app/Services/Communications/OutboundProvisionalLogger.php` — `log()` method, lines 36–83.**

This is the single writer of `source_ref = 'manual:user:NN'`:

- Line 65: `'source_ref' => 'manual:user:' . ($userId ?? 'unknown'),`
- The `Communication::create([...])` array (lines 43–66) **never sets `owner_user_id`** → it defaults to NULL.

Contrast with the sibling method `logDistribution()` in the **same file** (lines 101–185), which was written later (AT-158/AT-228) and DOES stamp it:
- Line 136: `'source_ref' => 'deal_distribution:user:' . ($ownerUserId ?? 'unknown'),`
- Line 137: `'owner_user_id' => $ownerUserId,`  ← the correct pattern, missing from `log()`.

So the root cause is a plain omission in `log()`: it threads the sending agent's id into `source_ref` but forgets the `owner_user_id` column. The comment on `logDistribution` even calls this out as an "improvement over `log()`: stamps `owner_user_id` at create" (lines 92–93) — the debt was known and never backported.

**All four callers pass a valid `userId`** (so every `manual:user:NN` NN is real → 100% backfillable, matching the probe):

| Caller | File:line | userId passed |
|---|---|---|
| Seller outreach mirror | `app/Services/SellerOutreach/SellerOutreachSenderService.php:135` | `$context->agent->id` |
| Contact tile quick-send | `app/Http/Controllers/CoreX/ContactController.php:1165` (`incrementChannel`) | `auth()->id()` |
| Mobile WA quick-send | `app/Http/Controllers/Api/MobileContactController.php:233` (`whatsapp`) | `$request->user()->id` |
| Mobile CoreMatch WA | `app/Http/Controllers/Api/MobileCoreMatchController.php:209` | `$request->user()->id` |

**Reconciliation does not heal it either.** `ProvisionalReconciler` (`app/Services/Communications/ProvisionalReconciler.php:113`) promotes a provisional row in place with `'owner_user_id' => $promote['owner_user_id'] ?? $hit->owner_user_id`. If the send is later ingested through a captured WA device / monitored mailbox, the ingestor's `$promote` payload carries `owner_user_id = device/mailbox->user_id` (`WaArchiveIngestor.php:274`, `EmailArchiveIngestor.php:124`) and the row heals. **The 241 stuck-NULL rows are provisional manual sends that were never reconciled** (WA send made outside a captured device, or email never seen in a monitored Sent folder) — they keep the NULL forever.

---

## 3. Migration intent

**`database/migrations/2026_07_11_000001_add_owner_user_id_to_communications_table.php`** (AT-122):

- Adds `owner_user_id` **nullable**, `after('source_ref')`, `constrained('users')` with **`nullOnDelete()`**, plus index `comm_agency_owner_idx (agency_id, owner_user_id)`.
- **No backfill.** The `up()` only alters the table; existing rows are left NULL.
- Author's stated meaning (docblock): "*Records WHICH agent's mailbox/device a message was ingested through* … PROVENANCE ONLY. Nothing reads or gates on it yet — the future AT-118 access gate will key per-agent visibility off this column." Nullable was deliberate: "*a mailbox with no owner (agency-level mailbox) leaves it NULL gracefully and ingest never fails on an unresolved owner.*"
- Intent vs prior attribution: there was **no** prior agent column; `source_ref` was the only pre-existing attribution. `owner_user_id` was meant to become the canonical, queryable owner — the migration just never seeded it from `source_ref`, and `log()` never started writing it.

Since AT-122 landed, **AT-118 now DOES read and gate on it** (see §4) — so what was "provenance nobody reads" is now load-bearing for visibility, which is exactly why the NULLs now bite.

---

## 4. Consumers

Grep of `owner_user_id` across `app/`. "Undercount / harm" = behaviour while the 241 rows are NULL.

| Consumer | File:line | Reads owner_user_id correctly? | Harm from NULL |
|---|---|---|---|
| **Comms visibility gate** (own/branch scope) | `app/Models/Communications/Communication.php:218–307`, esp. **247** (`->where('owner_user_id', $user->id)`) and 245 (branch via `owner.branch_id`) | Yes — this is the design consumer | **Core harm.** Docblock (213–216): "NULL owner_user_id (legacy/outbound provisional rows) … EXCLUDED from own + branch … only visible under 'all'." → **an agent's own manual WA/email sends are invisible to that agent** under the default `own` scope; only an `all`-scope viewer sees them. Attribution + visibility both lost. |
| **Thread "from" label** | `Communication.php:109–116` (`getFromDisplayAttribute`) | Yes | OUTBOUND returns `owner?->name ?: 'Agent'` → the 241 rows render as generic **"Agent"** instead of the real sender's name. |
| **Contact page thread list** | `app/Http/Controllers/CoreX/ContactController.php:355`, `426–427` | Yes (`owner_user_id` → name map + `canManageSubj`) | NULL owner → thread shows **"Unassigned"**; the true sender cannot manage hide-subject on their own thread. |
| **Access-grant "do I own this?"** | `app/Services/Communications/CommsAccessGrantService.php:206`, `311`, `459` | Yes | Owner check fails for the sender → they must *request* access to their own send; and they are treated as non-owner for auto-visibility. |
| **Eligible approvers of a request** | `CommsAccessGrantService.php:487–489` (`whereNotNull('owner_user_id')->pluck`) | Yes | The real sender is **not listed as an eligible approver** for access requests on their own thread — approval falls back to `grant_access` holders only. |
| **WA body consent join** | `app/Services/Communications/WaBodyBackfillService.php:93` (`acc.agent_user_id = communications.owner_user_id`) | Yes | NULL never matches an agent-capture-consent row → body stays withheld. |
| **Embargo / purge consent** | `PurgeEmbargoedWaBodies.php:70`, `WaEmbargoReleaseService.php:63,97,154`, `WaCapturePurgeService.php:66` | Yes | Consent keyed on owner → NULL coerces to user `0`, consent never resolves. |
| **Agent merge / reassignment** | `app/Services/Admin/AgentDeletionService.php:308`, `app/Console/Commands/Communications/ReassignCaptureOwner.php:66,110` | Yes (re-points `owner_user_id`) | On agent merge these rows are **not migrated** (WHERE `owner_user_id = fromId` misses NULL) → attribution stays orphaned across merges. |
| **Per-contact tile count** | `app/Models/Contact.php:1114` (`outboundCommCount`) | **Does NOT use owner_user_id** (counts by `channel` + `direction`) | **No harm** — this count is correct regardless of owner. Worth noting: no consumer currently *counts agent sends by owner_user_id*, so there is no numeric "sends per agent" stat being undercounted today; the harm is visibility/attribution, not a tally. |
| **Agent scorecard** | `app/Services/CommandCenter/AgentScorecardCalculator.php:67–70` | Reads `daily_activity_entries`, **not `communications`** | Not a consumer — no impact. |

Net: every consumer that reads `owner_user_id` reads it *correctly* (none fall back to parsing `source_ref`). The bug is purely that the column is NULL where `source_ref` proves an owner exists — so correct consumers produce wrong results (invisible/unassigned/unapprovable own-sends).

---

## 5. Double-count relationship

- `contact_outreach_log` mirrors `seller_outreach_sends` (ticket's stated pair) — not relevant to `communications`.
- **`communications` ↔ `seller_outreach_sends`:** `SellerOutreachSenderService::send()` writes, **in one transaction**, a `seller_outreach_sends` row (lines 96–120, `agent_id = $context->agent->id`) AND a mirror provisional `communications` row via `provisionalLogger->log(...)` (lines 135–141). They share the rendered body's `text_hash` so `ProvisionalReconciler` promotes the comm **in place** if the real send is later ingested — **no double count *within* `communications`**.
- The two tables measure different surfaces on purpose: `Contact::scopeHasWhatsappOutreach` (`app/Models/Contact.php:920`) and the contact timeline read `seller_outreach_sends`; the comms tile reads `communications`. **No single stat sums both tables**, so there is no active double-count today. The risk is latent: any *future* "agent WA sends" metric that UNIONs both tables would double every outreach send.
- Attribution mismatch to fix: for these mirror rows `seller_outreach_sends.agent_id` is populated but the twin `communications.owner_user_id` is NULL — the two attribution columns disagree for the *same logical send*. The backfill realigns them (and both already agree with `source_ref`'s NN).

---

## 6. Backfill safety

- **FK:** `owner_user_id` → `users(id) ON DELETE SET NULL`. A raw `UPDATE … SET owner_user_id = <NN>` **FK-violates only if NN is absent from `users`**. `User` uses `SoftDeletes` (`app/Models/User.php:23`) → a soft-deleted agent's row still exists → safe. Hard deletes are against policy, but `AgentDeletionService` does physically delete some rows on merge; a defensive backfill **must gate on `WHERE EXISTS (SELECT 1 FROM users WHERE users.id = parsed_NN)`** and leave the row NULL when the user is truly gone. (Probe says 0 non-backfillable, so in practice all resolve — but the guard makes it safe/idempotent.)
- **Parse target:** only rows with `source_ref REGEXP '^manual:user:[0-9]+$'`. Skip `manual:user:unknown` (userId was null — probe: none) and do NOT touch `deal_distribution:user:NN` (already owned at write) or `wa_device:` / `mailbox:` shapes.
- **Scope:** only `WHERE owner_user_id IS NULL` — never overwrite an already-owned row.
- **`updated_at`:** column exists. A raw UPDATE bypasses Eloquent timestamps, so set `updated_at = NOW()` explicitly (honest — the row was corrected) while **never touching `occurred_at` / `captured_at` / `provisional_at`** (those are the send's real clock). Alternatively iterate Eloquent rows and `save()` per row.
- **Reversible:** because the backfill only fills currently-NULL rows, `down()` = set `owner_user_id = NULL WHERE source_ref REGEXP '^manual:user:[0-9]+$' AND owner_user_id IS NOT NULL` (or, safer, capture the changed ids). Idempotent both ways.

---

## 7. Legitimate NULL-owner cases (→ NOT NULL is unsafe; guard is right)

`owner_user_id` legitimately stays NULL for:
- **Agency-level mailbox / device ingest** — the AT-122 migration's explicit design: an ingest through a mailbox/device whose `user_id` is NULL writes `owner_user_id = mailbox->user_id = NULL` (`EmailArchiveIngestor.php:124`, `WaArchiveIngestor.php:274`). This is a real, supported ongoing case, not legacy.
- **Legacy pre-AT-122 rows** never backfilled.
- Note on `direction`: one might expect INBOUND messages to be ownerless, but the ingestors stamp `owner_user_id` for **both** directions (owner = the agent whose mailbox/device the message rode through), so inbound is *not* a reliable NULL class. The genuine NULL class is "ingested through an owner-less agency mailbox/device", regardless of direction.

Because a legitimate ongoing write path (agency-level mailbox ingest) still produces NULL, **a blanket `NOT NULL` constraint would break ingest** and violate the migration's own "ingest never fails on an unresolved owner" guarantee. Recurrence prevention therefore belongs in the **write path**, not the schema.

---

## Recommended 4-part fix plan

**1. Write-path attribution (root-cause fix).**
In `OutboundProvisionalLogger::log()` (`app/Services/Communications/OutboundProvisionalLogger.php`, the `create([...])` at lines 43–66), add `'owner_user_id' => $userId,` — mirroring what `logDistribution()` already does at line 137. `$userId` is exactly the agent whose id already goes into `source_ref`. All four callers pass a real id, so this closes the class at the source. No other writer needs changing.

**2. Backfill migration (reversible, guarded).**
New migration: for `communications` where `owner_user_id IS NULL` and `source_ref REGEXP '^manual:user:[0-9]+$'`, set `owner_user_id` = the parsed NN **only where that NN exists in `users`** (EXISTS guard against the `ON DELETE SET NULL` FK), touching `updated_at` but no send-clock columns. `down()` re-NULLs the same shape. Expected to heal all 241 live rows.

**3. Consumer audit result.**
No consumer changes required. Every reader of `owner_user_id` reads it correctly (§4); none needs a `source_ref` fallback once the column is populated. The per-contact tile count (`outboundCommCount`) is owner-independent and already correct. After backfill, the 241 sends become visible to their own agent (scope `own`), render the real sender name, and make the sender an eligible approver. No numeric report was undercounting (no owner-keyed tally exists yet).

**4. Recurrence prevention — WRITE-PATH GUARD, not NOT NULL.**
Do **not** add `NOT NULL` to `owner_user_id`: agency-level mailbox/device ingest legitimately writes NULL (§7), and the AT-122 contract is "ingest never fails on an unresolved owner." Prevention = fix #1 (stamp at write) plus, if a structural guard is wanted, an assertion/test that every `source_ref LIKE 'manual:user:%'` or `direction = outbound` manual send carries a non-null `owner_user_id`. The invariant "a manual agent send has an owner" is enforced at the one writer, leaving the legitimately-ownerless ingest path free.
