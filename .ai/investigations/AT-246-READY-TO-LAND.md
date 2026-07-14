# AT-246 — READY TO LAND

_2026-07-14. m3. Branch `AT-246` (off Staging). MODE: BUILD (Johan's word stands from creation;
investigation-first satisfied). Awaiting conductor GO for dual-deploy._

## The investigation flipped the ticket's premise (why investigate-first mattered)
The ticket says `seller_outreach_sends.owner_user_id` / `source_ref`, but that table attributes via
`agent_id` and has no `source_ref`. The real defect is on **`communications`** — the only table with
both `owner_user_id` and `source_ref`. **Live: 241/649 rows NULL-owner, all 241 `source_ref LIKE
'manual:user:NN'` → 100% backfillable, zero non-backfillable.** Full analysis:
`.ai/investigations/AT-246-wa-send-owner-attribution.md`.

## What shipped (the 4-part class fix)

**1. Write-path attribution (root cause).**
`app/Services/Communications/OutboundProvisionalLogger.php::log()` wrote the sender into `source_ref`
('manual:user:NN') but never set the first-class `owner_user_id` column — its sibling
`logDistribution()` already did. Added `'owner_user_id' => $userId` to the create array. All four
callers pass a real id; a null sender legitimately stays null. This closes the class at the one writer.

**2. Reversible, guarded backfill.**
- `app/Services/Communications/ManualSendOwnerBackfiller.php` — `heal()` / `revert()`.
- `database/migrations/2026_08_02_000001_backfill_communications_owner_user_id_at246.php` — creates a
  marker ledger, calls `heal()`, logs before/after counts; `down()` calls `revert()` (re-NULLs exactly
  the healed ids) and drops the ledger.
- Guards: only `owner_user_id IS NULL`; only `source_ref REGEXP '^manual:user:[0-9]+$'` (skips
  'manual:user:unknown', 'deal_distribution:user:NN', 'wa_device:*'); only where the parsed NN **exists
  in `users`** (FK is `ON DELETE SET NULL` — a merge can hard-delete a user; leave those NULL, never
  FK-violate); touches `updated_at`, never the send-clock (`occurred_at`/`captured_at`/`provisional_at`).
- **Marker ledger** (not the report's predicate-only `down()`): once the write-path fix is live a fresh
  manual send carries the identical `owner_user_id == NN(source_ref)` shape, so a predicate-based
  reversal would wrongly re-NULL new real sends. The ledger makes `down()` re-NULL *exactly* the rows
  this backfill changed. Idempotent both ways.

**3. Consumer audit — no changes required.**
Every reader of `communications.owner_user_id` reads it correctly (none falls back to parsing
`source_ref`): `Communication::scopeVisibleTo` (the core harm — NULL-owner rows are invisible to their
own sender), the "from" label, contact thread list, access-grant ownership + eligible-approver list, WA
consent joins, agent-merge reassignment. After backfill the 241 sends become visible to their sender,
render the real name, and make the sender an eligible approver. **No numeric stat was undercounting** —
there is no owner-keyed "sends per agent" tally today (the per-contact tile count is owner-independent).
So the ticket's "undercount" is real but is a visibility/attribution loss, not a wrong number on a report.

**4. Recurrence prevention — write-path guard, NOT `NOT NULL`.**
Agency-level mailbox/device ingest legitimately writes NULL owner (AT-122 contract: "ingest never fails
on an unresolved owner"), so a `NOT NULL` constraint would break ingest. The invariant "a manual agent
send has an owner" is enforced at the single writer (fix #1) — verified by test.

## Double-count (ticket note)
`contact_outreach_log` mirrors `seller_outreach_sends`. Separately, `SellerOutreachSenderService::send()`
writes a `seller_outreach_sends` row AND a mirror provisional `communications` row in one transaction,
reconciled in place by `text_hash` — no double count within `communications`, and no stat sums both
tables. Latent risk only; the backfill also realigns `seller_outreach_sends.agent_id` with the twin
`communications.owner_user_id` for those mirror rows.

## Verify chain (input paths proven)
- `php -l` clean: OutboundProvisionalLogger, ManualSendOwnerBackfiller, the migration, the test.
- **`ManualSendOwnerAttributionTest` — 5/5 pass, 21 assertions:**
  - `log` stamps owner from the sending agent; `log` with no agent leaves owner null.
  - backfill heals ONLY the valid NULL manual row; skips already-owned, 'unknown', user-gone,
    'deal_distribution:*', 'wa_device:*' (result: updated=1, total_null_manual=2, skipped_no_user=1).
  - idempotent (re-run heals 0); revert re-NULLs exactly the healed row, leaves a natively-owned row
    untouched, clears the ledger.
- The migration executes during the test bootstrap (RefreshDatabase) on top of the committed snapshot.
- **Schema snapshot deliberately NOT re-dumped in this lane** — `schema:dump` from this lane's partial
  test DB produced a lossy 989-line/net-−293 rewrite that would clobber the canonical snapshot (refreshed
  at `c1d326a4` from the full migrated Staging DB). The migration runs correctly on top of the existing
  snapshot. Refresh belongs at the Staging integration point from the full DB, per CLAUDE.md 12a intent.
- Scope (CLAUDE.md #13): single-file test targeting only; no broad suite.

## Deploy plan (on conductor GO)
- Standard recipe, staging then qa1: `git pull` → `migrate --force` (runs the backfill; logs
  before/after — expect **241 healed on live**, staging/qa1 counts per their own data) → clears →
  reload php8.2-fpm → restart worker. No `deploy:sync-reference-data` (no seeder-owned global rows).
- Post-deploy verify: `communications` NULL-owner-manual count → 0 healable remaining; spot-check that
  a previously-NULL send now shows its agent.
- Reversible: `migrate:rollback` re-NULLs exactly the healed rows and drops the ledger.
