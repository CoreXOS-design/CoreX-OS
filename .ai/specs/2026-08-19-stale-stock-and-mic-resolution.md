# Proposal: stale-stock rule + close the MIC duplicate-claim loop

**For Johan — 2026-08-19. Investigation + proposal. Nothing built. He is away; this is
what he should read first when he's back.**

---

## 1. What he actually said (verbatim, not paraphrased)

> "ok, on the what makes stock stale? Heres my take - if a property is not active and
> being advertised, or has not been advertised in the last month, and has not been
> worked with for a week then it can be treated as available to prospect. yes we will
> have the record but the ingest or link a deed button should then show the property
> record, not just go silent and now show anything.
> So if you look at the deeds screen it shows with the deed linked indicator.
> now the whole spiderweb to untangle here - in mic a property gets claimed because
> its not on our books. so an agent will claim it. only to find out later that this
> property already exists. and yes this could be because of address on portal ads
> differing from what we have in properties, and can only be recognized when the cma
> data is pulled in. Which means now that we have identified that the property is on
> our records we use the criteria above to determine if it can be worked with or not.
> And the next part we would then need on the contact mic screen is to link to the
> current property, and mark it that its stock so it can be removed from mic, not
> just released for the next agent to work with.
> think think think. ............. We need to find the best way to do this that we do
> not waste agent time, and remove stock from the mic."

**The core waste, restated:** an agent claims a property in MIC because it looks
unowned, does real work on it, and only later — when CMA/deeds data arrives — the
system discovers the agency already has it on the books under a different address
string. That discovery currently leads nowhere: the property gets silently promoted
(or the claim gets released) and the next agent can repeat the exact same wasted
effort. The loop never closes.

He wants three things joined up: (1) a stale-stock rule that decides whether an
already-owned property is fair game to re-prospect, (2) the deed/ingest screen
surfacing the matched property instead of going silent, (3) a control on the MIC
screen that links a claim to the existing property and removes it from the pool
permanently, not just releases it.

This confirms his read is correct — investigation below traces the exact code and
confirms every part of it against real data, not memory.

---

## 2. Investigation

### 2.1 Does the data exist to answer "advertised / last-advertised / last-worked"?

**Short answer: yes for advertised and last-advertised, at two different layers
depending on whether the property is already promoted to stock. "Last-worked" is the
weak one — the data exists but there is no single canonical column; several
candidates exist and none is definitively "the" answer. Flagging that loudly per your
instruction, not picking one silently.**

**Layer A — before promotion (a `prospecting_listings` row, sourced from P24/PP):**

| Question | Column | Notes |
|---|---|---|
| Is it currently advertised? | `prospecting_listings.is_active` (tinyint) | |
| Portal-reported status | `prospecting_listings.portal_status` (varchar) + `portal_status_changed_at` | when the portal itself flipped status |
| When did the scraper last see it live? | `prospecting_listings.last_seen_at` (datetime, NOT NULL) | the closest thing to "when was it last advertised" for an unowned listing |

**Layer B — after promotion (a `properties` row, agency stock):**

| Question | Column / mechanism | Notes |
|---|---|---|
| Is it on-market right now? | `Property::isOnMarket()` / `scopeOnMarket()` — `status NOT IN OFF_MARKET_STATUSES` | **The single canonical definition already in the codebase** (`app/Models/Property.php:56-68`). `OFF_MARKET_STATUSES = ['sold','transferred','withdrawn','expired','cancelled','let_out','draft','archived','unavailable']`. Reusing this, not inventing a new one, is the obvious move — see §2.5 on why `'draft'` sitting in this list matters. |
| When last actively syndicated? | `p24_last_submitted_at`, `pp_last_submitted_at`, `p24_activated_at`, `pp_activated_at`, `p24_listing_last_synced_at`, `pp_listing_last_synced_at` | Multiple real timestamps; "last advertised" for owned stock most naturally = the most recent of the submitted/activated ones. |

**"Last worked" — the genuinely weak spot:**

- `properties.last_activity_at` exists and is actively maintained (`PropertyObserver`,
  `AutoEventService::onPropertyUpdated()` bump it on every property change) and is
  already used as a staleness signal elsewhere — `PropertyHealthCalculator`,
  `CommandCentreService` (14-day idle threshold), `AgentActivityFilter`. **But this
  column only exists on `properties` — a property that isn't owned yet (still a
  `prospecting_listings` / `tracked_properties` row under someone's claim) has no
  equivalent.**
- For a claim in progress, the closest things are on `prospecting_claims`:
  `claimed_at`, `pitched_at`, `feedback_at`, `last_updated_at`, plus simply whether
  `is_active = 1` at all. None of these is labelled "worked" and none is bumped by a
  single canonical "an agent touched this" event — they're bumped by specific claim
  lifecycle transitions (claim, pitch, feedback).

**This is a real gap, not a design choice I'm allowed to paper over: "has this been
worked with in the last week" has no ready-made column for a property that's still
just a claim.** The two honest options are (a) treat "worked" as "there is an
`is_active=1` claim on it at all, regardless of age" — i.e. a live claim always blocks
staleness, full stop, or (b) treat "worked" as the most recent of
`claimed_at`/`pitched_at`/`feedback_at`/`last_updated_at` on the active claim. These
give different answers for a claim that's sat untouched for 6 days after being
claimed but before any contact was logged. **This needs your decision — see §5.**

### 2.2 The rule's ambiguity — resolved with an explicit interpretation, not silently

Your sentence: *"if a property is not active and being advertised, or has not been
advertised in the last month, and has not been worked with for a week"* parses more
than one way. Two candidate readings:

- **Reading A (what I believe you mean):** `(NOT [currently active+advertised] OR NOT
  [advertised in the last 30 days]) AND (NOT [worked in the last 7 days])`. The first
  half is really one test ("is this currently being marketed?") expressed two ways —
  either it's not active right now, or it hasn't been pushed in the last month. The
  trailing "and has not been worked with for a week" is a second, independent,
  always-required gate: even a listing that's gone completely quiet on marketing is
  NOT stale if a human is actively on it.
- **Reading B (literal left-to-right, standard OR/AND precedence):** `(NOT active AND
  advertised) OR (NOT advertised-in-month AND NOT worked-in-week)`. This reads "not
  active and being advertised" as its own compound condition (a property that is
  BOTH not-active AND currently-advertised — an unusual combination, e.g. delisted on
  our side but the portal hasn't caught up), which is a much narrower and stranger
  trigger than what the rest of the message is about.

**I'm going with Reading A.** It matches the stated intent (avoid wasting agent time,
protect anyone actively working a property) and reads naturally as "is this being
marketed?" OR'd two ways, AND'd with "is anyone actively on it?" Reading B produces an
odd edge case that doesn't obviously serve the goal. **Flagging this explicitly for
your confirmation — I am not building on Reading A until you say it's right.**

### 2.3 Address matching: is CMA data genuinely the earliest point a match can be caught?

**No — and this is the single most important finding in this investigation.**

There are, today, **two completely separate, independently-written matchers** that
answer "does this already exist in our stock," and they run at very different times:

1. **`OnMarketStockService::identitySets()`** — the CURRENT claim-time guard (added
   2026-08-18, "MIC CRISIS #1", `app/Http/Controllers/CoreX/MarketIntelligenceController.php:2946-2952`
   calls it via `stockMapForListings()`). It matches a prospecting listing to our
   stock **only** by exact `portal_ref` match OR exact `normalized_address` string
   match, and **only against ON-MARKET properties** (`OnMarketStockService.php:1-30`,
   its own doc comment: *"a prospecting listing is our stock when its portal_ref
   exactly matches... OR its normalized_address exactly matches... gated to ON-MARKET
   properties only"*). This is fast and safe but genuinely misses exactly the case
   you describe — a portal ad's address string that doesn't literally match ours.

2. **`TrackedPropertyMatchOrCreateService::resolvePropertyMatch()`** — the FUZZY
   matcher (scheme+section, then erf+suburb, then normalised street+suburb —
   `TrackedPropertyMatchOrCreateService.php:985-1033`). This is exactly the kind of
   matching that would catch "417 on the portal ad, but we hold it under erf 381" or
   a differently-formatted street name. **It is a `private` method called from
   exactly one place: inside `promoteToStock()`** (grep confirms zero other callers).
   `promoteToStock()` is invoked from `DeedsCaptureController::promote()` — i.e. it
   only runs when an agent has already captured a deed and clicks promote/ingest.

So: your instinct that recognition "can only happen when CMA data is pulled" is
**true of the system as it stands today**, but only because the fuzzy matcher happens
to be wired to fire exclusively at that late moment — **not because fuzzy matching
requires CMA/deed data to work.** `resolvePropertyMatch()`'s erf+suburb and
street+suburb branches only need `erf_number`/`street_number`/`street_name`/`suburb`
on the `TrackedProperty`, which for many listings are already populated straight from
the P24/PP capture, before any deed is ever pulled. **The logic to catch this earlier
already exists and works — it is simply invoked too late.** Moving an equivalent
fuzzy check to claim time (or even to prospecting-listing ingestion time) is the
highest-leverage single change available — see §4.4.

### 2.4 Does the ingest/link-a-deed button really "go silent"? — confirmed

Read `DeedsCaptureController::promote()` in full (`app/Http/Controllers/CoreX/DeedsCaptureController.php:440-560`).
Whether `promoteToStock()` takes the CREATE branch (brand-new property) or the
REFRESH branch (matched an existing property via `resolvePropertyMatch()`), the
response is **identical**: a flash message reading *"Promoted to a property and
linked the owner(s)[. Ingested N contact value(s).]"*, redirecting back to the deeds
index. **There is no distinction anywhere in the response between "created new" and
"matched and refreshed existing."** No property id, address, or "already yours"
indicator is surfaced either way. Your description is accurate, verified in the
actual controller code, not just recalled.

### 2.5 MIC claim/release — traced, and the "remove permanently" concept does not exist yet

- **Claim** (`MarketIntelligenceController::claim()`) already runs the exact-match
  `OnMarketStockService` guard (§2.3) and blocks with *"This is already your agency's
  own stock..."* if it hits. It does **not** run the fuzzy `resolvePropertyMatch()`
  matcher at all.
- **Release** — `prospecting_claims.status` transitions to `not_interested` or `lost`
  (the two closing statuses per `ProspectingClaim::STATUSES`,
  `app/Models/ProspectingClaim.php:81-89`), `is_active` flips to `0`,
  `released_at`/`release_reason` are recorded. The listing then simply reappears
  in the claimable pool for the next agent — there is no separate "resolved,
  already ours" terminal state. `prospecting_listings.matched_property_id` is the
  only field that removes a listing from the pool permanently
  (`ProspectingListingStateEnricher.php:160`: *"matched_property_id never set) — the
  pool exclusion mirrors this"*), and nothing in the claim/release flow ever sets it.
- **So: no, there is currently no way to close a claim as "turned out to be ours" —
  only "not interested" / "lost," both of which return the listing to the pool.**
  Building exactly this is what §4.3 proposes.

### 2.6 Cross-check against the 417 Von Baumbach wrong-property-linking bug

**Same family of problem, and there is a real dependency, not just a resemblance.**
Both `TrackedPropertyMatchOrCreateService::resolveMatch()` (TP-to-TP, the Von
Baumbach investigation) and `resolvePropertyMatch()` (TP-to-Property, this proposal)
are erf/street/suburb matchers built the same way, in the same file, by the same
author. §4.4 below recommends invoking `resolvePropertyMatch()`-style matching
**earlier** (at claim time) precisely because it's more accurate than the exact-match
guard it would sit alongside. **That recommendation's value is bounded by how
trustworthy the underlying erf/street data is** — the Von Baumbach investigation
found a TrackedProperty (`id=468`) whose own `erf_number` and `street_number` columns
don't agree with each other because at least four distinct P24 listings (417, 386,
383, 381 Von Baumbach Avenue) were matched onto the same TrackedProperty row on
2026-06-03. If claim-time matching leans on erf_number the way `resolvePropertyMatch()`
does today, a Frankenstein-conflated TP would produce a **false** "already ours" block
at claim time instead of a false negative at promote time — worse, because it would
happen earlier and be harder for an agent to argue with. **Recommendation: whatever
data-quality fix comes out of the Von Baumbach investigation (deduping/repairing
conflated TPs, tightening the erf veto) should land before or alongside moving
resolvePropertyMatch()-style checking to claim time, not after.** They are
independent bugs, as Johan believes, but the stale-stock fix's safety depends on the
other investigation's outcome.

---

## 3. Proposal

### 3.1 The stale rule (Reading A, pending your confirmation on §2.2)

```
STALE = ( NOT currently_advertised  OR  NOT advertised_within(30 days) )
        AND
        ( NOT worked_within(7 days) )
```

Concretely, once a matching Property row is found:

- `currently_advertised` = `Property::isOnMarket()` (the existing scope — reused,
  not reinvented).
- `advertised_within(30 days)` = most recent of `p24_last_submitted_at`,
  `pp_last_submitted_at`, `p24_activated_at`, `pp_activated_at` is within 30 days.
- `worked_within(7 days)` — **undecided, see §5 Q1.** Candidate definition: an
  active (`is_active=1`) `prospecting_claims` row exists on the matched property's
  linked listing(s), OR the most recent of `claimed_at`/`pitched_at`/`feedback_at`/
  `last_updated_at` on the most recent claim (active or not) is within 7 days.

**STALE → treat as fair game to re-prospect** (a new claim can be opened even though
the record exists). **NOT STALE → block/warn, name who holds it.**

### 3.2 What the agent sees, at each of the three moments

**Moment 1 — about to claim something already on the books.** Extend the existing
claim-time guard (`MarketIntelligenceController::claim()`) to also run a
`resolvePropertyMatch()`-equivalent fuzzy check (not just the exact-match
`OnMarketStockService`), gated behind the fix in §2.6. If a match is found:
 - **NOT stale** (per §3.1) → block the claim outright, same shape as today's "already
   your agency's own stock" message, but now catching fuzzy matches too, and naming
   who currently holds it if it's live stock (agent on the property record).
 - **Stale** → do NOT block. Let the claim proceed, but surface the match on the claim
   screen ("This looks like it may already be property #X — link instead of duplicate
   if that's confirmed") so the agent has the option without being forced through it.

**Moment 2 — a match is discovered later (deed/CMA pull).** This is §3.3's build: the
ingest/promote response stops being silent and shows the resolved property record
directly, whichever branch fired (CREATE or REFRESH) — with an explicit "matched
existing property #X" indicator when it's the REFRESH branch, mirroring the "deed
linked" indicator already on the deeds screen you referenced.

**Moment 3 — the contact/MIC screen, once a match is confirmed.** A new control:
**"Link to existing property — mark as stock."** This is §3.3.

### 3.3 "Mark as stock, remove from MIC" — the flow

1. Agent (or the system, at claim/promote time per Moments 1-2) confirms the
   prospecting listing is the same physical property as an existing `properties`
   row.
2. Action writes: `prospecting_listings.matched_property_id` = the confirmed
   property id (this is the SAME field `OnMarketStockService`/`ProspectingListingStateEnricher`
   already use to exclude a listing from the pool — reusing the existing exclusion
   mechanism, not building a parallel one).
3. Any open `prospecting_claims` row on that listing closes with a **new terminal
   status** distinct from `not_interested`/`lost` — e.g. `already_stock` — recording
   who confirmed it and when. This is the "removed, not released" outcome: unlike
   `not_interested`/`lost`, this status must **not** return the listing to the
   claimable pool (today, both closing statuses do — this needs a new pool-exclusion
   check, or simply rely on `matched_property_id` now being set, since the pool
   exclusion already keys off that field).
4. Whoever holds the live stock (the property's `agent_id`) is named on the listing
   so the claiming agent — or anyone who lands on it again — sees who to talk to,
   not just "unavailable."
5. **Can it come back?** Only if the property itself later goes genuinely stale
   again (§3.1) — e.g. it comes off-market and stays off-market for 30+ days with no
   one working it. That re-opens it to the pool exactly the way any other stale
   record would. This needs an explicit decision from you — see §5 Q3.

### 3.4 Where the agent-time waste actually goes, and the one change that removes the most of it

The waste has two distinct sources, and they are not equal:

1. **Discovery happens too late.** An agent can claim, research, and start pitching a
   property that turns out to already be ours — potentially days of work — because
   the ONLY check at claim time is exact-string matching (§2.3), and the fuzzy check
   that would catch it doesn't run until deed/CMA capture, which may be well into
   the agent's own workflow or may never happen at all if they never pull a deed.
2. **Discovery leads nowhere.** Even when the fuzzy match IS found (at promote time),
   nothing surfaces it (§2.4) and nothing closes the loop for the pool (§2.5) — so
   the *next* agent repeats the same wasted claim.

**Source 1 is where the time actually goes** — it's the difference between an agent
never starting wasted work at all versus an agent who already spent real hours before
the system tells them. **The single highest-leverage change is moving
`resolvePropertyMatch()`-equivalent fuzzy matching from promote-time (its only
current call site) to claim-time**, run inside the same guard that already exists in
`MarketIntelligenceController::claim()` (§3.2 Moment 1). Source 2 (§3.3, §3.4 Moments
2-3) matters and should still be built — it stops the *next* agent's waste and gives
Source-1's block something concrete to point at ("held by property #X, agent Y") —
but it doesn't prevent the *first* agent's waste the way catching it at claim time
does.

---

## 4. On Bug 2 (draft-status stock escaping the on-market filter) — and why it matters here

Confirmed, exact line: `TrackedPropertyMatchOrCreateService::promoteToStock()`, the
CREATE branch (no existing match found), hardcodes `'status' => 'draft'`
(`TrackedPropertyMatchOrCreateService.php`, in the `Property::create()` call). `Property::OFF_MARKET_STATUSES`
includes `'draft'` — so a freshly-promoted, never-yet-activated property is
immediately excluded from `scopeOnMarket()`/`isOnMarket()`.

**This is not the same bug as Von Baumbach, but it is not fully independent of THIS
proposal either.** `OnMarketStockService`'s claim-guard is explicitly "gated to
ON-MARKET properties only" (§2.3) — so a draft property is invisible to the SAME
claim-time guard this proposal wants to strengthen. A property promoted today under
Bug 2's current behaviour is claimable again in MIC tomorrow, by design of the very
guard §3.2 relies on. **Any claim-time matching this proposal adds must NOT be gated
to on-market-only the way the current exact-match guard is** — it needs to also catch
draft (and any other off-market-but-genuinely-ours) stock, or Bug 2 will keep
punching a hole straight through the fix proposed here. This is a scope note for
whoever builds §3.2, not a request to fix Bug 2 in this document — Bug 2 itself is a
one-line fix (don't default new promotions to `'draft'`, or explicitly widen the
claim-guard to include it) and is Johan's call on which.

---

## 5. Open questions — Johan must decide, not invented here

1. **"Worked with" definition (§2.1).** Is a live, untouched claim (`is_active=1`,
   no contact logged yet) enough on its own to block staleness, or must there be
   recent activity (`claimed_at`/`pitched_at`/`feedback_at`/`last_updated_at` within
   7 days) even on an active claim? These give different answers for a claim sitting
   idle for 6 days.
2. **Reading A vs B (§2.2).** Confirm the rule is `(not-active OR not-advertised-in-
   month) AND not-worked-in-week`, not the literal left-to-right parse.
3. **Can a "marked as stock" listing ever return to the pool (§3.3.5)?** If the
   property later goes genuinely stale, should it silently re-enter the pool, or
   require a human decision each time?
4. **Where does the claim-time fuzzy check run for listings that AREN'T sectional/
   erf-identifiable yet** — i.e. a bare P24 capture with only an address string, no
   erf, no deed pulled? `resolvePropertyMatch()`'s address-fallback branch can run on
   just `street_number`+`street_name`+`suburb`, which most P24 captures do have — but
   its accuracy there is exactly what the Von Baumbach investigation is stress-
   testing. Confirm you want claim-time checking to include the address-only branch,
   not just erf-based matching, once that data-quality work lands.
5. **Sequencing with Von Baumbach (§2.6) and Bug 2 (§4).** Confirm the intended build
   order — my recommendation is: Von Baumbach data-quality fix → Bug 2 (draft-status)
   fix → this proposal's claim-time matching, in that order, since each later step's
   correctness depends on the one before it.

---

## 6. What this document is not

This is a proposal to react to, same footing as the earlier compose-screen merge
proposal. Nothing described above has been built. No migration, no controller
change, no view change has been made. It requires your decisions on §5 before any
build prompt should be written against it.
