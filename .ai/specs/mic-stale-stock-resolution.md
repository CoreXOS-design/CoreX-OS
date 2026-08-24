# MIC — Resolving a Claim That Turns Out To Already Be On Our Books

**Spec ID:** `mic-stale-stock-resolution`
**Date:** 2026-08-19
**Author:** Claude (spec) — for Johan's review and approval before any build.
**Status:** DRAFT — not approved, not built.
**Business owner:** Johan Reichel
**Related:** `.ai/specs/mic-promoted-property-exclusion-proposal.md` (draft-status exclusion, separate decision); this session's earlier investigation report on the "already in stock" block.

---

## 1. The problem, in one paragraph

An agent finds what looks like a fresh opportunity on Market Intelligence and claims it — reasonably, because the portal ad's address often doesn't match what's already in our records, so there's no way to know it's already ours until the CMA data comes in and resolves it. Today, when that resolution happens, one of two wrong things occurs: either the agent hits a wall — "already on HFC's books" — with no way to know who to talk to, so they give up; or the claim gets released back to the pool, and the *next* agent takes the exact same journey and hits the exact same wall. Either way, nothing is ever recorded, so the same dead end gets walked repeatedly. This spec fixes both: it defines when an existing record is genuinely still ours versus just an old entry nobody closed out, and it makes sure that once MIC has answered that question once, it never has to be answered again for that address.

## 2. The rule, in Johan's words, made real against the data

> "if a property is not active and being advertised, or has not been advertised in the last month, and has not been worked with for a week then it can be treated as available to prospect."

Two conditions, both required for STALE:

**A. The advertising condition** — NOT (currently live on a portal AND refreshed within the last month).
**B. The worked-with condition** — NOT worked with by a human in the last 7 days.

### 2a. What "actively advertised" maps to

- **Currently live:** `p24_ref` set and `p24_syndication_status != 'deactivated'`, OR `pp_ref` set and `pp_syndication_status != 'deactivated'`. This is not a new concept — it's the exact same field `Property::mayBeLiveOnP24()`/`mayBeLiveOnPp()` already use elsewhere in this codebase for "is this portal listing still visible."
- **Last advertised:** the later of `p24_last_submitted_at` / `pp_last_submitted_at` — the timestamp CoreX actually last pushed data to the portal, not a status label.
- **Reliability, checked on QA1's 180 currently-blocking properties:** `p24_last_submitted_at` populated on 95%, `pp_last_submitted_at` on 57%. `p24_syndication_status` is `'active'` on 167 of 180. These are reliable, real fields — not a gamble.

### 2b. What "worked with" maps to — every candidate checked

| Signal | Table | Reliability on QA1's 180 | Verdict |
|---|---|---|---|
| Note added | `property_notes` | Only 3 of 180 have ever had one | Too sparse to rely on alone |
| Audit trail, human-attributed | `property_audit_log` where `user_id IS NOT NULL` | 149 of 295 log rows are human (price changes, edits); the rest (`property_created`, syndication syncs) are system-generated and correctly excluded | **Use this — the single strongest signal**, once filtered to a real user |
| Task interaction | `command_tasks` where `assigned_by IS NOT NULL` | All 180 have tasks, but most are auto-generated compliance/document reminders (`assigned_by` null) | Use only the human-assigned subset |
| Appointment booked | `calendar_events`, using `created_at` (when booked), NOT `event_date` (when it's scheduled to happen — a future date tells you nothing about *when* someone last acted) | 173 of 180 have one ever | Use `created_at` only, or a future-dated appointment booked long ago will wrongly look "recent" |
| Deal activity | `deals` | 0 of 180 — expected, these are still-held properties, not sold ones | Not useful for this specific question |
| Outreach send | `seller_outreach_sends` | 0 of 180 | Not useful here — outreach targets fresh prospects, not established stock |
| `updated_at` on the property row itself | `properties` | 100% populated | **Your instinct was right — too weak alone.** Portal syncs and automated jobs touch this constantly; it does not mean a human did anything. |

**Recommendation:** "worked with in the last 7 days" = any of: a human-attributed `property_audit_log` entry, a `property_notes` entry, a human-assigned `command_tasks` row, or a `calendar_events` row *booked* (not scheduled-for) in that window. Never `updated_at` alone.

## 3. The count — and an honest limitation of QA1's data

Running Johan's rule against the 180 properties that currently trigger "already in stock" on QA1:

**Every single one of the 180 currently reads as "worked with" more than 7 days ago, and every single one currently reads as "not advertised within the last 30 days."** That would say 100% stale, 0% genuinely live — but I don't trust that number and you shouldn't either. Here's why: the most recent portal-submission timestamp anywhere in that set of 180 is **2026-07-17** — 33 days before today (2026-08-19), just outside the 30-day window — and every submission timestamp clusters tightly in a two-week band around early-to-mid July. That's not agents going quiet; that's QA1's portal-sync activity having stopped running around that date. **QA1 cannot honestly answer the "how many are stale" question** — its sync and activity data froze weeks ago, which makes everything look artificially dead. The *rule* is sound and the *fields* are the right ones; the QA1 dataset just isn't live enough to prove the ratio.

**Same queries, ready for live** (read-only, safe to run):

```sql
-- Count under Johan's rule, split stale vs live
SELECT
  CASE
    WHEN NOT (
      ((p24_ref IS NOT NULL AND p24_syndication_status != 'deactivated')
        OR (pp_ref IS NOT NULL AND pp_syndication_status != 'deactivated'))
      AND GREATEST(COALESCE(p24_last_submitted_at,'1970-01-01'), COALESCE(pp_last_submitted_at,'1970-01-01')) >= NOW() - INTERVAL 30 DAY
    )
    AND id NOT IN (
      SELECT property_id FROM property_audit_log WHERE user_id IS NOT NULL AND created_at >= NOW() - INTERVAL 7 DAY
      UNION SELECT property_id FROM property_notes WHERE created_at >= NOW() - INTERVAL 7 DAY
      UNION SELECT property_id FROM command_tasks WHERE assigned_by IS NOT NULL AND created_at >= NOW() - INTERVAL 7 DAY
      UNION SELECT property_id FROM calendar_events WHERE created_at >= NOW() - INTERVAL 7 DAY
    )
    THEN 'STALE — available to prospect'
    ELSE 'LIVE — blocks, name the holder'
  END AS bucket,
  COUNT(*) AS n
FROM properties
WHERE agency_id = <your agency id> AND deleted_at IS NULL
  AND status IN ('active','available','for_sale','to_let')
GROUP BY bucket;
```

That ratio — run on live, where the sync data is real — is the size of the prize Johan asked for.

**How many MIC claims are in this exact situation right now, today:** at least 1, found by checking which active claims resolved to a property that already existed *before* the claim was made (claim #322, property #5522, "1502 Beaumont Drive") rather than one freshly created by the claim itself. That one happens to already have `status='withdrawn'`, so it isn't actually blocked today — a reminder that this check needs to run for real, on every active claim, not be approximated the way I just did it for speed. I'd recommend that exact sweep (every active claim, resolved against Johan's rule) be the first thing run once this ships, specifically to find and resolve any claim already silently sitting in the wrong bucket.

## 4. The workflow this spec commits to building

1. **Agent claims from MIC**, believing it's not on our books. No change here — this is already correct and stays correct.
2. **CMA data comes in and resolves the listing to an existing `Property` record**, via the existing `TrackedPropertyMatchOrCreateService` — the single Match-or-Create entry point stays exactly that; nothing here creates a second path or a duplicate record.
3. **Apply the rule from §2** to that resolved property.
   - **STALE** → the agent is not stopped. The claim continues normally, and it is explicitly **linked** to the existing `Property` — the same record, not a new one — so its history isn't lost and duplication can't happen.
   - **LIVE STOCK** → the agent is stopped immediately, before they invest more time, and told **who holds it** (agent name, same lookup `MapProspectStatusService::resolveAgentName()` already does for the `held`/`other_draft` cases today) so they can go talk to that colleague instead of hitting a dead end with no name attached.
4. **Either way, the MIC entry leaves the canvass list and stays off it** — this is Johan's sharpest point and the one that actually eliminates the waste. Today, a resolved-as-existing claim that gets *released* goes back into the pool, and the next agent repeats the identical claim → CMA-pull → discover → give-up cycle. That must stop being possible.

## 5. The principle: record why, always

**"Released" and "resolved as existing stock" must never look the same, anywhere in the system.** A claim's closure needs a recorded *reason*, not just an inactive flag:

- Today, `prospecting_claims.is_active = false` means only "this is no longer active" — it collapses every closure (agent gave up, manager released it, timer expired, resolved as existing stock) into one indistinguishable state.
- This spec requires a resolution reason on the claim (e.g. `resolved_existing_stock`, alongside the existing `released_at`/`release_reason` mechanism this session already found and used) so that when this listing is looked at again — by any agent, by a manager, by a future audit — the record shows *why* it left the pool, and specifically whether it was because someone already has it. That's what makes the "leaves the list and stays off it" promise in §4 actually enforceable and auditable, not just a query-time filter that could quietly drift.

## 6. Don't go silent — the "Deed linked" pattern, and it may be quick

Johan pointed at a pattern CoreX already has and likes: the compose screen's small green **"Deed linked ✓"** badge (`resources/views/seller-outreach/entry/prospecting-create-contact.blade.php:173`) — an outcome shown on the record, not silence. When CMA resolution finds an existing property, the same treatment applies: **name it, link to it, show it** — not nothing.

**This may be separable and quick.** If the CMA-resolution code path can surface "resolved to Property #X" as a visible badge/link using the same client-side pattern already built for deed-linking (the `linkedDeed` state variable and its badge), that's a small, low-risk, high-value fix that doesn't need to wait for the full stale/live rule to land. I'd want to confirm the exact size of that piece before promising it ships first, but flagging it now so you can decide whether to split it out.

## 7. What a manager needs to see

A count — MIC claims resolved as `resolved_existing_stock` vs total claims, over a period, broken down by stale-vs-live if useful — surfaced wherever managers already look at claim outcomes (the stale-claim review screen this session already found, `StaleClaimController`, is the natural home). This is the number that tells Johan whether his canvass list is genuinely full of fresh opportunities or full of noise from properties already on the books. Not built in this pass — flagged as the reporting requirement this spec exists to eventually feed.

## 8. Risks and what's explicitly NOT decided yet

- **Match-or-Create stays the single entry point** — this spec adds a *decision* (stale vs live) on top of an existing match, it does not add a second way to create or link a property. Confirmed against the code: the `previously_sold`/`previously_held` cases in `MapProspectStatusService` already do exactly this "continue, don't duplicate" pattern today — this spec extends that same pattern with one more case, it doesn't invent a new mechanism.
- **A null `expiry_date` or missing portal-submission timestamp must default to the safe side** — treated as "cannot confirm stale," i.e. still blocks. Never let a missing field silently open a genuinely-held property.
- **`own_draft`/`other_draft` stay untouched** — those already correctly distinguish "someone is mid-workflow on this right now" from "this is just an old record," which is the exact distinction Johan asked me to preserve. The staleness rule in this spec sits alongside that split, not instead of it.
- **Not decided:** whether the row-badge/plain-bookmark-claim path (`OnMarketStockService`, a different rule from the one this spec fixes) should get the same staleness treatment, or whether its broader "anything not explicitly dead" definition is intentional for that surface. Separate call, flagged, not answered here.
- **Not decided:** exact wording/UI for "tell them who holds it" and the resolved-existing-stock badge — needs a design pass once the rule itself is approved.

Nothing in this spec is built. Waiting for you and Johan.
