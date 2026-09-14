# Core Matches expansion — AT-Core-Matches

> Split build: cc3 owns the screen (controller rendering, query, row layout —
> see cc3's sections once landed), cc4 owns the data layer (this document's
> sections below). Both land through cc1 as one combined piece.

## Why

Johan found a real agent-poaching risk in Core Matches: a buyer could sit on
more than one agent's board at once with no record of who received the lead
first, no server-enforced rule about who may move a buyer between agents,
and no distinction between a buyer who's gone quiet and one who's actually
being worked. Six rulings, all now the spec:

1. Only a branch manager or admin can move a buyer between agents. Ever. An
   agent can never reassign a buyer, including to themselves, by any route.
2. A note added, the Last Contacted button, a message sent, and a live link
   shared all reset the working clock.
3. The board is OPEN — assigned agent, first-lead time, last contact, and
   contact notes are visible to everyone.
4. Live links stay LIVE for the buyer — one permanent link, always current
   stock. All share history is INTERNAL.
5. The working window is an agency SETTING, default 7 days, never hardcoded.
6. The buyer stays on the board of every agent who received the lead, and
   the board shows WHO GOT IT FIRST.

## Current-state audit (before this build)

Full findings from the pre-build investigation — six independent forks,
verified against `origin/QA1`, not the spec's own claims:

- `/corex/core-matches` and `/corex/rentals/core-matches` are ONE component
  (same `ContactMatchController::index()`/`allView()`), not two drifted
  builds — distinguished only by route name.
- `ContactMatch` had no `agent_id` — ownership inferred only from
  `created_by_user_id`, no duplicate check against `contact_id`, no
  uniqueness constraint. Two agents could independently hold a match on the
  same buyer with nothing to notice.
- `portal_leads.received_at` already held the portal's own reported time on
  the two pull paths (P24, PP pull); the PP real-time webhook path had no
  queryable timestamp or agent at all — PP's own `leadDateTime` only ever
  landed inside a free-text Contact note.
- No agent_id existed on `portal_leads` at all — per-agent attribution was a
  live join to the property's CURRENT agent (silently rewritable if the
  listing changes hands) for a new contact, or a frozen
  `existing_contact_agent_id` for a returning one.
- A shared Core Matches live link stores only a token + the match's
  criteria, never a property list — every open re-runs a live query. No
  record exists anywhere of what a buyer was actually shown at share-time.
- Soft-deleting the match was the only way it left either screen. The
  existing `status` enum (`active`/`paused`/`fulfilled`/`expired`) is a sort
  key only — nothing filters on it.
- Buyer Pipeline's "Lost" transition fired no event at all — a quiet
  `updateQuietly()` column update plus two audit-table inserts.
  `corex-domain-events-spec.md` documented a `ContactBuyerStatusChanged`
  event that was never built, watching a column (`buyer_status`) that isn't
  the real one (`buyer_state`) — corrected in this build, see below.
- No settings surface existed for Core Matches at all.

## Data model (cc4)

### `contact_matches` — two new columns

- **`agent_id`** (nullable FK → `users`, `nullOnDelete`) — the OWNING agent.
  The one field `ContactMatch::reassignTo()` moves. Distinct from
  `created_by_user_id` (untouched — "who clicked create", historical).
  Backfilled for existing rows as `agent_id = created_by_user_id` — not a
  reconstruction, `created_by_user_id` is already a directly-recorded fact
  for every existing row, just not previously labelled as the owning agent.
- **`set_aside_at`** (nullable timestamp) — set when the buyer's Buyer
  Pipeline state moves to `lost`, cleared automatically when it moves off
  `lost` (ruling 6, Task 6: "set aside, never deleted, comes back if the
  buyer does"). Not a new `status` value — that enum is a sort key only,
  untouched. Screen query is expected to exclude `WHERE set_aside_at IS NOT
  NULL` by default; `scopeNotSetAside()`/`scopeSetAside()` are provided.

New relations: `agent()` (the owning agent), `reassignments()` (latest
first), `shares()` (latest first).

### `portal_leads` — one new column, populated on all three ingestion paths

- **`received_by_user_id`** (nullable FK → `users`, `nullOnDelete`) — the
  agent who received THIS lead, frozen at arrival. Same resolution on all
  three paths: the existing contact's own agent for a returning enquirer
  (identical fact to `existing_contact_agent_id`), the listing's agent at
  that exact moment for a brand-new one. No backfill on existing rows —
  there is no honest way to reconstruct who received an old lead; existing
  rows keep answering the old way.
- To find "who got it first" for a contact:
  `PortalLead::where('contact_id', $id)->orderBy('received_at')->first()`.
- **The PP real-time webhook path** did not create a `portal_leads` row at
  all — it's mirrored into one by `CommandTaskPortalLeadObserver` (triggered
  off `CommandTask::created`, filtered to
  `source_type='private_property_webhook'`), a mechanism the original
  investigation missed entirely (it only checked `PpWebhookController.php`
  itself for `PortalLead` references, found none, and wrongly concluded no
  row was ever created). `received_by_user_id` is now populated there too.
  **Known limitation, not fixed here:** that observer's `received_at`
  remains the CommandTask's `created_at` (our processing time), not PP's own
  `leadDateTime` — the observer only ever sees the reconstructed
  `CommandTask` + `Contact`, never the raw webhook payload, and getting the
  real portal timestamp through would mean either a new column on the
  generic `command_tasks` table or restructuring the controller/observer
  relationship to create the `PortalLead` directly there instead (which
  would also mean retiring the observer's own duplicate-creation logic and
  its buyer-cascade seeding call — a bigger, riskier change than Task 1's
  stated scope). Flagged rather than forced.

### New table: `contact_match_reassignments`

One immutable row per reassignment (ruling 1, Task 3). SoftDeletes from the
first migration per the standing no-hard-deletes rule, though never expected
to need deleting.

| Column | Notes |
|---|---|
| `agency_id` | direct column, required by `BelongsToAgency` |
| `contact_match_id` | FK, cascade on delete |
| `from_agent_id` | nullable FK |
| `to_agent_id` | required FK |
| `moved_by_user_id` | required FK — always a branch_manager/admin, enforced in code, not DB |
| `reason` | required text — Johan's model is the manager has the conversation first, so this is never optional |

Written only through `ContactMatchReassignment::record()`, mirroring
`RentalApplicationStatusHistory`'s append-only pattern.

### New table: `contact_match_shares`

Append-only, INTERNAL-only log (ruling 4: "all share history is internal" —
plural, a log, not a single last-shared timestamp).

| Column | Notes |
|---|---|
| `agency_id` | direct column |
| `contact_match_id` | FK, cascade on delete |
| `shared_by_user_id` | required FK |
| `channel` | nullable enum: copy_link / whatsapp / email / other |
| `shared_at` | timestamp |

Written only through `ContactMatchShare::record()`, which ALSO resets the
buyer's working clock in the same call (see below) — the two are the same
fact from two angles, never recorded separately.

### The reassignment mechanism (ruling 1, Task 3)

`ContactMatch::reassignTo(User $toAgent, User $movedBy, string $reason)`:

1. Throws `ReassignmentNotAuthorizedException` unless
   `$movedBy->hasPermission('core_matches.reassign')` — a NEW permission,
   granted to `branch_manager` + `admin` only (mirrors `contacts
   .reassign_agent`'s existing role placement exactly), never `agent`.
2. Throws `InvalidArgumentException` on an empty reason.
3. Writes the audit row, then updates `agent_id`, inside a transaction.

Server-enforced twice over (BUILD_STANDARD §1c — direct-URL access must be
blocked, not just absent from a menu): the route middleware
(`permission:core_matches.reassign`) AND the model method's own check.

**Route:** `POST /corex/core-matches/{match}/reassign` →
`ContactMatchReassignmentController::reassign`, name
`corex.core-matches.reassign`. One route serves both the sale and rental
screens — the match carries its own `listing_type`.

Deliberately its own controller, not a new method on `ContactMatchController`
(cc3's file, edited in parallel).

### The share-recording action (ruling 4)

`POST /corex/core-matches/{match}/record-share` →
`ContactMatchShareController::record`, name `corex.core-matches.record-share`.
Gated on `core_matches.view` (the same gate as reading the match — sharing
isn't the privileged action, reassignment is). Body: optional `channel`.
Calls `ContactMatchShare::record()`.

### The working clock (ruling 2, Task 4)

No new clock column. Reuses `Contact::last_contacted_at` exactly as found —
it already unifies email + WhatsApp (one `communications` table/model) with
the manual "Last Contacted" button's own `contacted_marked_at`, via
`Contact::recomputeLastContacted()`.

Two NEW triggers added, both calling the existing
`Contact::touchLastContacted()`:

- **A live link shared** — `ContactMatchShare::record()` (new).
- **A note added** — hooked via observer on `ContactNote`'s creation
  (applies to notes generally, not scoped to notes added specifically from
  a future Core Matches screen — narrower scoping would need new
  context-tagging plumbing that's out of scope for this build; flagged as
  an interpretation, not a silent decision).

**"A message sent"** needed no new hook — an outbound, sent communication
already triggers `recomputeLastContacted()` via
`CommunicationSendStatusService`.

**Two caveats carried forward from the original investigation, still true:**
the link from a `communications` row to a `Contact` is a polymorphic pivot
(`communication_links`), not a guaranteed direct foreign key — correctness
depends on identifier-matching having worked, not a guaranteed join.
`PresentationDelivery` and `SellerOutreach\SellerOutreachSend` are separate
communication logs that sit outside `last_contacted_at` entirely — not
folded in here, out of scope for this build.

### The working-window setting (ruling 5, Task 5)

**No new settings table.** Added to the EXISTING `agency_contact_settings`
table/model — it already governs the adjacent Buyer Pipeline day-thresholds
(`buyer_warm_days`/`buyer_cold_days`/`buyer_lost_days`); a second table for
the same kind of fact would be a second source of truth.

- New column: `core_matches_working_window_days` (nullable smallint).
- New constant: `AgencyContactSettings::DEFAULT_CORE_MATCHES_WORKING_WINDOW_DAYS = 7`.
- New resolved-value method: `AgencyContactSettings::coreMatchesWorkingWindowDays(): int`
  (null-safe, clamped 1–90 — same pattern as `buyerKanbanColumnLimit()`).
- Baked into `AgencyContactSettings::forAgency()`'s defaults array for any
  newly-created agency row; an existing row that never re-saves this field
  resolves via the null-safe method instead.
- **Settings screen:** added to the EXISTING Contact Governance settings
  page (`ContactGovernanceController` + `command-center/settings/contact
  -governance.blade.php`) as its own "Core Matches" panel — this page
  already owns the sibling buyer-freshness day-thresholds; a brand-new
  settings page for one field would be exactly the drift this rule exists
  to prevent.
- **Setup Wizard:** NOT added. Its siblings on the same table
  (`buyer_warm_days`/`buyer_cold_days`/`buyer_lost_days`) aren't in the
  wizard either. Adding only the new field while its siblings stay excluded
  would be inconsistent, and CLAUDE.md #10a makes wizard-inclusion
  explicitly Johan's call, not the lane's, when a setting arguably doesn't
  belong in onboarding. **Flagged for a ruling, not decided here** — if he
  wants it in, the sibling three probably should join it in the same pass.

### The removal trigger (Task 6)

Two new domain events, fired explicitly inside
`BuyerStateService::transitionTo()` (its `updateQuietly()` call suppresses
Eloquent model events, so an observer would never fire):

- `Contact\ContactMarkedLostInBuyerPipeline` — fires on transition TO `lost`
  (auto-recompute or manual override). Listener:
  `SetAsideCoreMatchesOnBuyerLost` sets `set_aside_at = now()` on every
  not-yet-set-aside match for that contact.
- `Contact\ContactRestoredFromLostInBuyerPipeline` — fires on transition
  AWAY FROM `lost`. Listener: `RestoreCoreMatchesOnBuyerRestored` clears
  `set_aside_at` on every set-aside match for that contact.

Both registered explicitly in `AppServiceProvider::boot()` (matching the
FICA event pattern — this codebase's dominant convention, not relying on
listener auto-discovery). `corex-domain-events-spec.md`'s stale
`ContactBuyerStatusChanged` entry (never built, wrong column name) is
replaced with these two real ones in the same commit.

### New permission

`core_matches.reassign` — action-type, `core-matches` section, granted to
`branch_manager` + `admin` only (admin via the existing all-minus-exclude
default; `core_matches.reassign` is not on the exclude list). Never granted
to `agent`, `viewer`, `office_admin`, or `assistant`.

## Not built here, reported not fixed

- The master on/off toggle for the whole expansion that cc3 says they were
  told about as its own numbered task — not in the six rulings given to
  cc4. Two independent reads landing on the same gap; needs a ruling, not a
  guess from either lane.
- `PpWebhookController`'s `received_at` (via the observer) stays our
  processing time, not PP's own reported time — see the data-model note
  above.
- `AgencyContactSettings`'s existing buyer-freshness settings
  (`buyer_warm_days`/`buyer_cold_days`/`buyer_lost_days`) are themselves
  absent from the Setup Wizard — pre-existing gap, not introduced or
  widened here, not fixed here either (out of scope).
