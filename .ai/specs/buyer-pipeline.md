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
