# AT-267 Finding 3 — record-ownership routing: ready-to-execute remediation

> Status: **scoped, NOT built.** Money-sensitive + per-surface + needs the test lane (no phpunit
> in the QA2 dev lane). This ticket is the investigation so implementation is a clean pickup:
> exact surfaces, exact columns, exact change, exact tests. Author: audit follow-up 2026-07-19.
> Parent: `.ai/audits/assistants-feature-audit-2026-07-19.md` (Finding 3), spec §7.2.

## The bug

An assistant's created records are stamped to the **assistant**, not the Assigned Agent, so:
- **(a) money misroutes** — e.g. `DealV2Controller::store()` defaults the deal's owner to
  `auth()->id()` (`app/Http/Controllers/DealV2/DealV2Controller.php:325`:
  `$data['listing_agent_id'] = $firstListing['user_id'] ?? auth()->id();`). For an assistant that
  is the assistant, so commission/pipeline land on someone with no commission profile.
- **(b) the agent can't see the work** — a normal agent's own-scope is `dataIdentityIds() = [self]`;
  a record owned by the assistant is outside it.

`User::ownershipUserId()` (`app/Models/User.php:837`) was built for exactly this — it returns the
Assigned Agent's id for an assistant, else self — **but is never called on any write path**
(only read-scope in `ResolvesMobileDataScope`).

## The principle (one rule, every surface)

Separate OWNER from ACTOR:
- **Owner column** (the commission/visibility-bearing "whose record is this") ← `$user->ownershipUserId()`
  — the agent for an assistant, self for everyone else.
- **Actor column** (created_by / uploaded_by — "who physically did it") ← `auth()->id()` — stays the
  assistant, and pairs with the `on_behalf_of_user_id` audit trail already shipped (Finding 2).

**Routing owner → agent also fixes (b) for free**: with owner = agent, the record falls inside the
agent's own-scope, so no `dataIdentityIds()` change is needed. Do NOT widen the agent's identity —
that would over-expose. Fix ownership at write time only.

**Guardrail (do not skip):** where the owner is a FORM field (deal `listing_agent_id` /
`selling_agent_id` are `nullable|exists:users,id`, `DealV2Controller.php:278-279`), an assistant must
not be able to assign ownership to an arbitrary agent. For an assistant, ignore the submitted value
and force `ownershipUserId()`. Enforce server-side, not just in the UI.

## Surfaces (money-critical first)

| Surface | Owner col | Actor col | Entry point | Change |
|---|---|---|---|---|
| **DealV2** | `listing_agent_id`, `selling_agent_id` | `created_by_id` | `DealV2Controller::store` (:259; default :323-325) | owner defaults + form input → `ownershipUserId()` for assistants (guardrail); `created_by_id` stays `auth()->id()` |
| **Contact** | `agent_id` (also `second_agent_id`) | `created_by_user_id` | `CoreX\ContactController::store` (agent_id at :1078) | `agent_id` ← `ownershipUserId()` when blank/for assistant; `created_by_user_id` = actor |
| **ContactMatch** | (via contact's agent) | `created_by_user_id` | `CoreX\ContactMatchController` (:168, :302) | already inherits branch from contact; ensure the match's contact/owner resolves to the agent, not the assistant |
| **ViewingPack** | `agent_id` | — | viewing-pack store | `agent_id` ← `ownershipUserId()` |
| **Presentation** | `created_by_user_id` | (same) | presentation create | owner ← `ownershipUserId()`; if a distinct actor column is wanted, add one + the on_behalf trail |
| **CalendarEvent** | `user_id` | — | `CommandCenter\CalendarController` create | `user_id` ← `ownershipUserId()` (so the agent's calendar carries the assistant's bookings) |

Enumerate the rest from the assistant's grantable matrix (everything the agent holds minus the
property-upload lock): notes, tasks, documents, deal remarks/steps, outreach. For each, apply the
same owner/actor split. A create surface with only an actor column (pure audit/log rows) needs no
owner change — it already has the `on_behalf_of` trail.

## Cleanest implementation shape

Prefer a small helper over sprinkling `ownershipUserId()`:
- Add `User::actingOwnerId()` (alias of `ownershipUserId()`) if clearer, and at each store: set the
  owner column to it. Keep the change one line per surface, reviewed per surface.
- Where owner is a form field, clamp: `$ownerId = $user->isAssistant() ? $user->ownershipUserId() : ($data['listing_agent_id'] ?? $user->id);`
- Do NOT try a global observer — owner columns differ per model; an observer that guesses the column
  is how money gets misrouted. Explicit per-surface is the safe form.

## Test plan (must pass before merge — run on a test-capable lane)

For each surface, in a `tests/Feature/Assistants/AssistantOwnershipTest`:
1. Acting as an assistant, create the record.
2. Assert the **owner column = the Assigned Agent's id** (not the assistant's).
3. Assert the **actor column = the assistant's id**, and (Finding 2) `on_behalf_of_user_id` = agent.
4. Assert the **agent can see** the record under own-scope, and a **third-party agent cannot**.
5. Deal-specific: assert commission/pipeline attribute to the agent (the money assertion).
6. Guardrail: an assistant POSTing `listing_agent_id` = some other agent still lands on THEIR agent.

## Why this was not shipped from the QA2 lane

No phpunit here, and this routes real commission — a wrong column or a missed surface misroutes
money and is hard to reverse. Per CoreX "production quality / no shortcuts", it waits for the lane
where the assertions above actually run. Everything needed to execute it is in this ticket.
