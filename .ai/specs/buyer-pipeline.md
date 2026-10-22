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
  this buyer?" — nothing for a Won buyer, nothing for a buyer an agent placed by hand recently (AT-74), otherwise
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

## When a deal falls through, its buyer leaves Won (AT-500, 2026-10-22, Johan)
- **Rule:** Won lasts only while the buyer is on a **live** deal (see above). When the deal is **declined**
  (`accepted_status` → D), **archived** (soft-deleted), or the buyer is **taken off the deal** on the DR2 edit, the
  buyer leaves Won **unless they are a buyer on another live deal**. They go back to the active pipeline,
  recomputed from their activity into **New / Warm / Cold** — never straight into Lost, even if idle (a cold
  buyer who has just lost a deal is for the agent to work, not to write off).
- Audited in `buyer_state_transitions` with reason **`deal_fell_through`** (from `won`). That reason also gets the
  AT-74 protection window (`isManualPlacementProtected`), so the nightly job does not decay them the same night.
- The reverse holds: re-opening a declined deal, or restoring an archived one, makes its buyers Won again.
- A buyer who is Won with **no deal at all** (marked Won by linking them to a property on the property page) is
  left alone by any deal ending — there is no deal for them to have fallen through. (Open point: a buyer who is
  Won through BOTH a property-page link and a now-ended deal does leave Won.)
- Where: `BuyerStateService::reconcileDealBuyers()` / `revertWonAfterDealEnded()` / `revertWonForBuyers()`,
  called from `DealObserver` (updated D↔other, deleted, restored — failure-isolated) and `DealRegisterController::syncDealParties`.
  Migration `2026_10_22_090000_…` adds the enum value (reads the live column definition; works on every box).
- **Core Matches follow Won/Lost with nothing to restore:** the exclusion is read live from `buyer_state`
  (`CoreMatchBuyerGate`, see `core-matches.md`), so a buyer sent back to the active board is matched again, and
  their saved searches were never touched.
- Tests: `tests/Feature/Buyers/BuyerWonFallsThroughTest.php`, `tests/Feature/Dr2/Dr2CaptureTest.php`
  (`…removing_a_buyer_from_a_deal_takes_them_off_won…`).
