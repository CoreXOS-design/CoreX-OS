# Portal Agent Mismatch Guard — spec

Status: APPROVED 2026-09-30 (Johan: "build it on staging") · Built on Staging · Modules: P24 syndication, PP syndication

## 1. Why (business requirement)

On 2026-09-30 Barbara Jackson put Unit 402 Glyndale Sands (property #4464) back on the market.
Private Property accepted it. Property24 rejected it with "Some of the specified agents are not
active. AgentIds: 191056". The listing had been imported from P24 in June under Johan's P24
agent profile (191056). That profile is now inactive on P24. CoreX's "back on market" path only
changes the listing status (PUT .../status?listingStatus=BackOnMarket). It never sends who the
agent is, so P24 kept Johan's name on the listing and refused it. The same thing happened to
#2448 (2026-09-18) and #4625 (2026-09-28).

Agents must never hit a raw portal error they can't act on. Before CoreX sends a listing to a
portal, it checks that the agent the portal will show is the CoreX listing agent, and that this
agent is able to be listed.

Decision (Johan, 2026-09-30): when the portal has a different agent, **warn and ask first**.
Do not switch automatically.

## 2. Pillars

- Reads: **Property** (listing agent, portal refs), **Agent** (`users.is_active`, `p24_agent_id`,
  PP agent id).
- Writes back: **Property** (the agent each portal currently holds, plus the warning state).

## 3. Data model

Migration on `properties`:
- `p24_portal_agent_ids` (json, nullable): the P24 agent ids P24 currently holds for this
  listing. Set on every successful P24 listing POST (the `contactAgentIds` that were sent) and on
  P24 import (the agents on the imported listing).
- `pp_portal_agent_id` (string, nullable): the PP AgentId last accepted for this listing. Set on
  every successful PP UpdateListing.
- Backfill: set from the P24 import data where it is still available. Otherwise leave NULL,
  meaning "unknown". Unknown is handled in §5.4.
- Run `schema:dump` and strip the DEFINER clauses, per CLAUDE.md §12a.

## 4. Checks, run before every portal send (submit, refresh, back on market)

| # | Condition | What the user sees | Send? |
|---|-----------|--------------------|-------|
| A | Listing agent is inactive or archived in CoreX | "Barbara Jackson is no longer active in CoreX. Change the listing agent on this listing before sending it to Property24." | Blocked |
| C | Portal holds a different agent than the CoreX listing agent | Confirm dialog: "Property24 has this listing under **Johan Reichel**. Send it under **Barbara Jackson** instead?" with the buttons [Yes, switch to Barbara] and [Cancel] | Only after Yes |
| D | Portal rejects an agent as inactive anyway (the portal agent was unknown to CoreX) | The same dialog as C, naming the rejected agent if CoreX can resolve the id to a user | Only after Yes |

"Yes, switch" for **back on market**: CoreX sends the full listing with the current listing
agent, then sets the status to BackOnMarket. A plain status flip can never fix the agent, and
that is the root cause of the incident. For submit and refresh, the normal listing POST already
carries the current agent, so Yes simply proceeds.

Not a separate check: a listing agent with no profile on the portal. Both
submit paths already register the agent on the portal automatically before
sending (`ensureAgentRegistered`), and a failure there is already reported as
"Agent registration failed: …". Adding a second warning would duplicate it.

The confirm dialog is the amber box inside the portal's panel on the listing
(`partials/portal-agent-conflict.blade.php`), with the buttons "Yes, send under
<listing agent>" and "Cancel". A stopped send returns HTTP 409 with `agent_conflict`.

## 5. Rules

1. **The person clicking is irrelevant.** Listings always go out under the CoreX listing agent.
   An admin syndicating an agent's listing is normal and never produces a warning.
2. **Refresh cost contract.** The checks read only CoreX's own stored data. They add zero portal
   calls. The extra listing POST in §4 "back on market" happens only after the user confirms a
   switch, and it is budgeted as a changed refresh, not a no-op.
3. **Unattended sends** (Refresh All, scheduled jobs, event-driven re-syndication): nobody is
   there to confirm, so CoreX never switches the agent on its own. The listing is skipped for
   that portal and flagged ("Agent differs on Property24. Open the listing to confirm.") in its
   syndication panel and in the listings list filter below.
4. **Unknown portal agent (NULL):** CoreX can't compare, so the send proceeds. If the portal then
   rejects the agent, rule D applies. After any successful send the stored value becomes known.

## 6. UI

- The syndication panel on the listing (existing) shows the dialog and warnings. No new page.
- My Listings list: a "Portal agent" tile (`?filter=portal_agent`), shown only while the count
  is above zero, and a "Portal agent" badge on the card and on the row. Both read the stored
  `*_agent_conflict` columns (a send that was stopped). Existing search, sort, scoping and
  pagination stay unchanged, and the count uses the same role scope as the "Awaiting approval" tile.

## 7. Permissions and scoping

- Confirming a switch requires the existing syndicate permission for that listing. No new key.
- Scoping is unchanged. The listing's existing OWN/BRANCH/AGENCY scope applies to the panel, the
  filter and the endpoints.
- There is no new agency setting, so there is nothing to add to the Setup Wizard.

## 8. Acceptance criteria

0. B was dropped as a separate check (see §4 note).
1. Re-running Barbara's case (portal holds 191056, listing agent Barbara 532703, back on market)
   shows dialog C naming Johan and Barbara. Yes sends the full listing with 532703 and then
   BackOnMarket. Cancel sends nothing.
2. An inactive listing agent blocks the send with message A. No portal call is made.
4. Johan syndicating Barbara's listing, where the portal already has Barbara: no warning, one call.
5. An unchanged refresh with matching agents still costs exactly one P24 call
   (Property24RefreshCostTest stays green).
6. Refresh All with a mismatched listing: skipped, flagged, and the other listings are unaffected.
7. PP: the same checks, using `pp_portal_agent_id`.

## 9. Files (expected)

- `app/Services/Syndication/Property24/Property24SyndicationService.php`: checks,
  switch-then-reactivate path, recording `p24_portal_agent_ids`.
- The PP syndication service: the same checks, recording `pp_portal_agent_id`.
- The P24 import (`BackfillP24ImportedStock` / `ConfirmP24PropertyRowJob`): record the imported
  agents.
- The listing syndication panel view and JS: dialog and warnings. My Listings: filter and badge.
- Migration, schema snapshot.
- Tests: `tests/Feature/Syndication/PortalAgentMismatchGuardTest.php` (new), plus the existing
  `Property24RefreshCostTest.php`.

## 10. Out of scope (reported, not built)

- **Taking a listing OFF Property24 (deactivate / sold / withdrawn)** fails in the same way
  when P24 holds it under an inactive agent (#2448 on 2026-09-18, #4625 on 2026-09-28).
  Those listings stay live on P24 after they are sold. The same "switch the agent" answer
  does not fit, because nobody wants a sold listing re-sent, so this needs its own decision.
- **A P24 listing left at "error" with a P24 reference shows no buttons at all** (no Submit,
  Refresh or Reactivate) for any error that is not an agent problem. For agent problems, this
  build adds the switch button. The general dead end is a separate fix.
