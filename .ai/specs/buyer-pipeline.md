# Buyer Pipeline — manager actions and agent display

_New 2026-10-07 (Johan). The board itself (New / Warm / Cold / Lost, Won section,
scope toggle, filters) is described in `core-matches.md` ("Update buyer pipeline") and
`2026-08-20-buyers-report.md`; this file records the agent rules._

## Whose buyer is it
The card/row agent is the contact's primary agent (`contacts.agent_id`) — the FIRST agent to
receive the buyer's lead, changed afterwards only by a user by hand (see `contacts.md`,
"Who the primary agent is"). A later lead to another agent never changes it.

## Move to another agent (managers only)
- Button "Move to another agent" on every Kanban card and list row; shown only when the
  viewer holds `core_matches.reassign` (branch manager / admin). Agents never see it.
- Scoping: the board's own — only buyers the viewer already reaches under ContactScope
  (own / branch / agency) are listed, and the route
  (`POST /corex/core-matches/buyers/{contact}/reassign`) binds the contact through the same scopes
  (foreign agency 404, agent 403). Direct-URL access is blocked, not just hidden.
- Popup: choose an active agent of the agency + a required reason. The move sets the primary
  agent and moves ALL of the buyer's saved searches, in one transaction, logged in the contact
  history (who / when / from / to). Same popup as Core Matches ("Move buyer").
- Nothing else on the pipeline reassigns; there is no bulk reassign.

## Drag-to-Lost and the assistant rule (2026-10-07, Johan)
- Dragging a card to **Lost** opens the shared Mark-Lost dialog on the board (reason required; notes and
  "what did the buyer say" optional; same endpoint and validation as the buyer page and Core Matches). Cancel
  closes it and the card has not moved. `PATCH …/state` no longer accepts `lost` (422): a buyer can only
  become Lost with a recorded reason.
- Move actions (`state`, `mark-lost`, "Move to another agent") apply the assistant rule: an assistant who can
  see a colleague's buyer cannot move, lose or reassign them (403, and no drag handle / button on that card).
  Tests: `tests/Feature/CoreMatches/BuyerPipelineLostAndAssistantTest.php`.

## Acceptance
Tests: `tests/Feature/CoreMatches/BuyerPrimaryAgentRulingsTest.php`
(`test_the_buyer_pipeline_offers_move_to_another_agent_to_managers_only` and the move tests).

## Won stays Won (AT-500, 2026-10-22)
- **Bug:** every buyer marked Won (linked to a DR2 deal) was put back on the active board at 04:00 the
  next morning. The nightly `buyers:recompute-states` re-derived New/Warm/Cold/Lost for all buyers and had
  no Won check — the guard existed only in `BuyerStateService::recomputeState()`, which the command never called.
- **Rule (one place):** `BuyerStateService::nextAutoState()` answers "what may the automatic recompute do to
  this buyer?" — nothing for a Won buyer, nothing for a Lost buyer (added later in AT-500), nothing for a buyer an agent placed by hand recently (AT-74), otherwise
  the freshly derived state. `recomputeState()`, `buyers:recompute-states` and its `--dry-run` all ask it, so a
  rule added there applies everywhere. `markActivity()` also leaves a Won buyer alone (the activity is still logged).
- Tests: `tests/Feature/Buyers/BuyerWonStaysWonTest.php`.

## Won on any live DR2 deal (AT-500, 2026-10-22)
- **Rule (Johan):** a buyer named on a **live** DR2 deal is Won. Live = pending, granted or registered;
  a declined deal (`accepted_status = 'D'`) or an archived (soft-deleted) one is not live
  (`BuyerStateService::dealIsLive()` / `isOnLiveDeal()` — the one definition).
- The DR2 save (`DealRegisterController`, right after the deal's buyers are written) now calls
  `BuyerStateService::markBuyersWonForLiveDeal()`. Before this, Won came only from the property-link event, so it
  was missed when the buyer was already linked to the property in another role (no new link, no event) and when the
  deal had no property yet. The existing link is never re-roled. Sellers are never marked Won.
- Tests: `tests/Feature/Dr2/Dr2CaptureTest.php` (`…already_linked_to_the_property_in_another_role…`,
  `…no_property_still_marks_its_buyer_won`, `…seller_on_the_deal_is_not_marked_won`).

## When a deal ends: fell through vs corrected (AT-500, 2026-10-22, Johan — final ruling)
**Won lasts only while the buyer is on a live deal.** A **live** deal is pending, granted or registered; a
declined or archived one is not. **A buyer on a live deal is Won even if they are Lost now — Lost never blocks
linking** (a Lost buyer named on a live deal, or on a declined deal that is re-opened, becomes Won again).

When a Won buyer's deal ends, WHERE they go depends on WHY. This distinction is deliberate:

| What happened | Meaning | The buyer goes to | Audit reason |
|---|---|---|---|
| Deal marked **Declined** | The purchase **fell through** | **Lost** | `deal_fell_through` |
| Buyer **removed from the deal**, or the deal **archived** | An agent **corrected** a capture mistake — nothing fell through | **Back to the board**: recomputed from their activity into **New / Warm / Cold** (never straight to Lost) | `deal_corrected` |
| Deal **re-opened** (D → P/G/R) or **restored** from the archive | The deal is live again | **Won** | `property_linked` (the Won write) |

- **Exception, both rows:** if the buyer is also on **another live deal** they stay Won.
- A buyer sent back to the board by a correction also gets the AT-74 protection window (the nightly does not decay
  them the same night). A buyer sent to Lost by a fall-through stays Lost until a person moves them or a live deal
  makes them Won again.
- A Won buyer with **no deal at all** (a hand-made Won) is left alone by any deal ending.
- Open point: a declined deal that is later archived leaves its (already Lost) buyer in Lost — archiving does not
  re-open the question. Say if an archive of a declined deal should also return the buyer to the board.
- Where: `BuyerStateService::reconcileDealBuyers()` / `releaseWonBuyer()` / `releaseWonForBuyers()`, called from
  `DealObserver` (updated D↔other, deleted, restored — failure-isolated) and `DealRegisterController::syncDealParties`.
  Migrations `2026_10_22_090000_…` (`deal_fell_through`) and `2026_10_22_110000_…` (`deal_corrected`) add the enum values
  (they read the live column definition, so they are safe on every box).
- **Core Matches follow Won/Lost with nothing to restore:** the exclusion is read live from `buyer_state`
  (`CoreMatchBuyerGate`, see `core-matches.md`), so Won and Lost buyers are not matched, and a buyer corrected back
  to the board is matched again. Their saved searches are never touched.
- Tests: `tests/Feature/Buyers/BuyerWonFallsThroughTest.php`, `tests/Feature/Dr2/Dr2CaptureTest.php`
  (`…removing_a_buyer_from_a_deal…`).

## The nightly (04:00) follows the same rules (AT-500, 2026-10-22, Johan: "so we don't have the stuff-up again")
`buyers:recompute-states` goes through `BuyerStateService` and nothing else:
1. **Self-heal** (same selectors as `buyers:backfill-won`): a buyer on a live deal who is not Won → **Won**; a Won
   buyer with no live deal and not moved there by hand → **Lost** if their deal was declined (`deal_fell_through`),
   otherwise **back to the board** (`won_without_live_deal`). Every change is audited with its reason.
2. **Recompute** everyone else from activity — but **never a Won buyer, never a Lost buyer** (a manual Lost, or a deal
   that fell through, is not revived by recent activity), and never a buyer an agent placed by hand recently.
3. **One summary line** to the console and the log: `moved_to_won / moved_to_lost / back_to_board / recomputed / untouched`.
It is idempotent: the second run changes nothing. Test: `tests/Feature/Buyers/BuyerNightlyFollowsTheRulesTest.php`.
The back-fill's part 2 uses the same `healWonWithoutBasis()`, so it also sends a declined-deal Won buyer to Lost.

## Won back-fill counts live deals only (AT-500, 2026-10-22)
`php artisan buyers:backfill-won [--dry-run] [--agency=N]` moves existing buyers who are on a **live** DR2 deal
(pending / granted / registered; not declined, not archived) to Won. A buyer whose only deals fell through is not
moved. `--any-deal` widens the count to declined deals too — for a comparison dry-run only. Buyers linked only on
the property page (no deal) are no longer candidates; they become Won going forward by the live listener.
Held until Johan orders the real run (manual, not part of deploy). Test: `tests/Feature/Buyers/BuyersBackfillWonLiveDealsTest.php`.

## Won only via a live deal or by hand — the hard rule (AT-500, 2026-10-22, Johan)
- **A buyer leaves the pipeline only two ways.** **Won** = the buyer is on a live/registered DR2 deal, **or** an agent
  moved them there by hand. **Lost** = manual. Nothing else moves a buyer to Won.
- **Linking a contact to a property no longer marks anyone Won** — property page ("Add contact" / "Link existing"),
  contact page ("Link to property"), imports, leads, mobile. `MarkBuyerWonOnPropertyLink` was removed and
  unhooked; `ContactLinkedToProperty` itself is unchanged (other listeners use it). DR2 is unaffected: the DR2 save
  calls `BuyerStateService::markBuyersWonForLiveDeal()` directly (and re-opening/restoring a deal re-marks Won via
  `reconcileDealBuyers()`).
- **Won column empty-state** now reads "No won buyers yet. When a buyer is linked to a deal in the Deal Register,
  they move here automatically."
- **Note:** the board has no manual "move to Won" control today (`updateState` accepts new/warm/cold only); the rule
  keeps a hand-made Won (audit reason `manual_override`) safe from the back-fill for when one exists.
- **Back-fill part 2** (`buyers:backfill-won`, same command, `--dry-run` shows both counts): any buyer who is Won with
  NO live deal **and** whose most recent move into Won was not `manual_override` goes back on the board, recomputed to
  New/Warm/Cold (never Lost), audited as `won_without_live_deal` — except a buyer whose deal was **declined**, who goes to **Lost** (`deal_fell_through`) (migration `2026_10_22_100000_…`; it also gets the
  AT-74 protection window). Idempotent: a second run changes nothing.
- Tests: `tests/Feature/Buyers/BuyerWonOnlyViaDealOrManualTest.php`, `tests/Feature/Buyers/BuyersBackfillWonLiveDealsTest.php`
  (part 2), `tests/Feature/Dr2/Dr2CaptureTest.php` (`…with_a_property_marks_its_buyer_won_without_the_link_listener`).
