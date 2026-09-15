# Core Matches — Module Spec

> Status: Approved 2026-04-28
> Owner: Andre / Johan
> Pillars: Property + Contact + Agent (+ Deal via bridge)
> Replaces: ad-hoc manual matching that existed before this spec

---

## 1. Why This Module Exists

A real estate agency lives or dies on the speed at which a new property reaches the right buyer. Today's matches feature is **passive, contact-anchored, and unscored**: an agent must remember to open a contact, fill in a 12-field form, click Run, and manually share the result via WhatsApp. New listings sit invisible until somebody checks. We are rebuilding this to be **property-triggered, scored, ranked, and pushed** — the system tells the agent "your buyer wants this property" the moment a property is created or repriced.

## 2. Business Requirement

- The instant a property is created, repriced, or returns to active status, every active `ContactMatch` whose criteria it satisfies must be considered, scored, and surfaced — without an agent clicking anything.
- Matches must rank from best fit to weakest fit so an agent can act on the top ones first.
- Buyers/tenants must be able to react ("interested", "not for me") on the public shared page, and the agent must see those reactions next to the contact.
- A match that converts to a viewing or offer must seed a Deal — not be retyped.
- Stale matches (no engagement, contact bought elsewhere) must self-archive so the system stays honest.
- All of the above must respect agency multi-tenancy. Today's `contact_matches` table has **no `agency_id` column** — that is a tenancy bug we close with this spec.

## 3. Pillars

| Pillar | Read | Write |
|---|---|---|
| Property | criteria filters; published+active only | n/a |
| Contact  | owner of match, criteria source | feedback rolled into engagement_score |
| Agent (User) | match.created_by_user_id, listing agent receives notification | notifications + activity log |
| Deal | "Convert to viewing" creates a Deal seeded with property + contact + agent | new Deal row |

## 4. Data Model

### 4.1 `contact_matches` — extend existing table

New migration adds:

| Column | Type | Purpose |
|---|---|---|
| `agency_id` | FK agencies, nullable | Multi-tenancy. Backfilled from contact.agency_id. |
| `status` | enum: `active`, `paused`, `fulfilled`, `expired` | Lifecycle. Default `active`. |
| `suburbs` | json | Multi-suburb list. Existing `suburb` kept for backward compat & free-text fallback. |
| `must_have_features` | json | e.g. `["pool","sea_view","pet_friendly"]`. Hard filter. |
| `nice_to_have_features` | json | Bonus score, not a filter. |
| `last_engaged_at` | datetime, null | Last time contact viewed or reacted on shared page. |
| `auto_archive_at` | date, null | If set, command archives on this date. |
| `name` | string, null | Optional agent-facing label ("3-bed Margate sale"). |

Indexes: `(agency_id, status)`, `(contact_id)`, `(price_min, price_max)`, `(suburb)`.

### 4.2 `contact_match_feedback` — new table

Per (match, property) reaction from the contact via the public share page.

```
id, contact_match_id, property_id, reaction enum('interested','not_interested','saved')
note text null, created_at, updated_at
unique (contact_match_id, property_id)
```

### 4.3 `contact_match_notifications` — new table

Tracks which (match, property) pairs have already triggered an agent notification, to avoid duplicates when properties are saved repeatedly.

```
id, contact_match_id, property_id, score smallint, notified_user_id FK users,
notification_id uuid null, created_at
unique (contact_match_id, property_id)
```

### 4.4 Permissions

Already defined: `access_core_matches`, `core_matches.view`. Add:
- `core_matches.manage` — edit, archive, restore
- `core_matches.convert_to_deal` — gate the Deal bridge

Update `config/corex-permissions.php` and the role defaults that already grant `access_core_matches`.

## 5. Scoring (`MatchingService::score(Property, ContactMatch): int 0..100`)

Hard filters (return 0 / no match):
- listing_type mismatch
- property status not matchable — see §5.2, `Property::isMatchableStatus()` (the list below was a stale simplification, never the real vocabulary; do not re-list values here again)
- explicit `hidden_property_ids` membership
- price > price_max OR price < price_min
- beds < beds_min, baths < baths_min, garages < garages_min
- floor/erf out of declared range
- any **must_have_features** missing on the property

Weighted score (sum to 100):

| Weight | Signal |
|---|---|
| 25 | Price fit — closeness to midpoint of `[price_min, price_max]` (linear decay) |
| 20 | Suburb fit — exact in `suburbs` array = 20, partial/contains = 10, miss = 0 |
| 15 | Bed/bath fit — exact desired = 15, +1/+2 over = 10, default = 5 |
| 10 | Property type / category match (exact = 10, null = 5) |
| 15 | Nice-to-have features — proportion present × 15 |
| 10 | Freshness — listed within 14 days = 10, 30 = 6, 60 = 3, older = 0 |
| 5  | Engagement bonus — contact already gave 'interested' on similar = 5 |

Score < 40 → not surfaced. 40-60 → surfaced as "weak". 60-80 "good". 80+ "strong".

## 5.1 Relaxed Matching & Tiers (added 2026-05-22)

Core Matches no longer show only exact (100%) matches. `MatchingService::propertiesForMatch()`
runs in **relaxed mode by default**: the numeric criteria are widened into a tolerance band
in SQL so a near-miss survives to the scoring stage, where `score()` decays it.

| Criterion | Hard / Relaxed | Relaxed band |
|---|---|---|
| `listing_type` | HARD | never relaxed — sale ≠ rental |
| property `status` | HARD | non-matchable statuses always excluded — see §5.2 |
| suburb (`p24_suburb_id`) | HARD | buyer's chosen suburb(s) only |
| `must_have_features` | HARD | score() returns 0 if any missing |
| price_min / price_max | Relaxed | ±30% band |
| beds_min / baths_min / garages_min | Relaxed | allow 1 short |
| floor / erf size | Relaxed | ±30% band |

After scoring, anything below **`MIN_SCORE_TO_DISPLAY` = 50** is dropped. Each surfaced
property carries `match_score` (0-100) and `match_tier`:

- `strong` — score ≥ 80
- `good`   — score 65-79
- `fair`   — score 50-64

`ClientMatchResolver` is now a thin facade over `MatchingService` — one scorer, one
filter implementation for the agent web page, agent mobile app, buyer portal and the
public shared page. Pass `['relaxed' => false]` to `propertiesForMatch()` for the legacy
exact-bound behaviour.

## 5.2 Matchable status — ONE canonical definition (fixed 2026-09-15)

Live bug, found by Falan/Johan and reported as "drafts now included on Core
Matches." Investigated the buyer-facing side first, per explicit
instruction, before anything else: **draft was already correctly excluded
everywhere** — confirmed live, empirically, against a real draft property
and a real wishlist. It was not reaching a buyer.

**What was actually broken**: `prospecting` and `not_selling` were treated
as matchable when they should never have been — ingested-but-unmandated
stock (deeds/MIC ingest) the agency doesn't hold the mandate on. Confirmed
empirically: **560 of 842 properties (66%) in the agency-wide matchable
candidate pool** were prospecting/not_selling. Same root cause as the
rental `to_let` gap (found the same day, fixed in the same pass — see
below): the matching engine's idea of which statuses are matchable was
wrong AND maintained in more than one place. `Property::OFF_MARKET_STATUSES`
already had the correct, broader list; `MatchingService` maintained its own
separately-drifted copy (`NON_MATCHABLE_STATUSES`), and
`CoreMatchReasonClassifier` (landed the same day) had copy-pasted a THIRD,
identically-drifted copy.

**The fix — define it once**: `Property::isMatchableStatus(?string $status): bool`
and `Property::matchingExcludedStatusList(): array` are now THE single
canonical source. Every caller (`MatchingService::isMatchableStatus()`,
`MatchingService::propertiesForMatch()`'s SQL, `MatchingService::matchableCandidatePool()`'s
SQL, `CoreMatchReasonClassifier`) delegates to it — none maintain their own
copy any more.

**The full matchable/not-matchable call, by status** (real data, not the
enum — sale-side counts shown; see the fix's own commit for the full
vocabulary + rental-side counts):

| Status | Matchable | Why |
|---|---|---|
| active, for_sale, to_let | Yes | Genuinely on-market. **Active is not the same as advertised** (Johan's own correction) — an agency can hold a genuine mandate and be told not to market it; that property must still match. Matching NEVER filters on syndication/portal/advertising flags, only the base `status` column — proven by a dedicated test (`test_active_but_not_advertised_still_matches`) using a property with every syndication flag off. |
| draft | No | Incomplete record. Already correct; unchanged. |
| prospecting, not_selling | No | **The live bug, fixed here.** Ingested-but-unmandated — no real mandate. |
| under_offer, pending | No | On-market for every other purpose (`isOnMarket()` still returns true), but already spoken for — offering it to a new buyer sets up a disappointment. New: `Property::MATCHING_EXCLUDED_ON_MARKET_STATUSES`, deliberately kept separate from `OFF_MARKET_STATUSES` so those other consumers are unaffected. |
| sold, sold_by_3rd_party, transferred, withdrawn, expired, cancelled, unavailable, archived, let_out, rented | No | Off-market/terminal; already correct (rented preserved from the matching engine's own prior list — flagged as arguably belonging in `OFF_MARKET_STATUSES` itself for every other consumer too, not changed there in this pass, blast radius not audited). |

**`to_let` for rentals — included in this same pass**, per instruction: the
identical defect class as prospecting/not_selling, found earlier the same
day (only 7 of 560 rental listings carried a status the engine recognised
at all before this). Never formally ruled on by Johan for rentals
specifically — told to him as fixed alongside the sale-side fix, his to
overrule if he wants it handled separately. `MatchingService::STATUS_BY_LISTING_TYPE['rental']`
now includes `to_let`.

**Tests**: `tests/Feature/Matching/MatchableStatusVocabularyTest.php` — 8
tests pinning the whole vocabulary, including the case Johan specifically
named as most likely to break (active-but-not-advertised still matches)
and a consistency check that `MatchingService::isMatchableStatus()` can
never drift from the canonical definition again. Broader regression run
(Matching/, ThirdPartySaleExclusions, CoreMatchReasonClassifier,
BuyerPipeline, Prospecting matcher): 75/76 — the one failure a
pre-existing, unrelated test-construction bug (`BuyerPipelineKanbanCountsMatchFilterTest`
manually builds a `Request` bypassing the router), untouched by this
change.

**Verified live on QA1**: see the deploy/verification note appended once
that walk has actually been run — proving a prospecting property no
longer appears for a buyer who previously matched it, and an
active-but-not-advertised property still does.

## 6. Flow — Property-Triggered (the new behaviour)

```
Property::saved fires
  → PropertyObserver::saved()
  → If status active & published & price set → dispatch MatchPropertyJob($property)

MatchPropertyJob (queued)
  → MatchingService::candidatesForProperty($property)
       GUARD: MatchingService::isMatchableStatus($property->status) — an
       off-market property (sold/transferred/rented/let_out/withdrawn/expired/
       cancelled/unavailable/archived/draft/pending) returns NO candidates and
       fires NOTHING. Case-insensitive (status is stored mixed-case, e.g.
       P24 writes 'Sold'/'Withdrawn'). NULL/blank status = matchable.
       Returns active ContactMatches in same agency where hard filters pass.
  → For each candidate:
       score = MatchingService::score($property, $match)
       if score < min_score_to_notify skip
       if (match,property) already in contact_match_notifications skip (dedup)
       insert contact_match_notifications row — MUST set agency_id explicitly
       (the job runs with no Auth::user(), so BelongsToAgency cannot infer it;
       omitting it = NOT NULL insert failure = dedup row never written =
       every re-save re-notifies. Take agency_id off the property.)
       notify $match->createdBy via NewPropertyMatchNotification
       → channel: DATABASE ONLY (real-time bell). NO per-match email.
  → Touch each candidate's `last_engaged_at` with NULL — only updates on user action
```

### 6.1 Match eligibility — single source of truth

`MatchingService::isMatchableStatus(?string): bool` is THE predicate for whether
a property may match, used in BOTH directions (candidatesForProperty /
matchesForProperty for the property→match path, and propertiesForMatch for the
match→property path). It replaced the earlier split, case-sensitive
`EXCLUDED_FOR_NOTIFY` / `EXCLUDED_FOR_DISPLAY` lists that disagreed (notify was
missing `let_out`/`expired`/`cancelled`/`unavailable`) and let 769 `Sold`
listings and every `let_out` rental leak into match emails. One list
(`NON_MATCHABLE_STATUSES`), one normalised predicate, every entry point routed
through it. Fix-the-class, not the instance.

### 6.2 Match email digest — one email per agent per day

The per-match email (fired inside MatchPropertyJob) flooded inboxes: a bulk
import or a property re-save fanned out one email per (property, contact). Match
emails are now COALESCED, mirroring the calendar-digest rule ("one email per
user, never one per item"):

- `NewPropertyMatchNotification::via()` = `['database']` only — the bell stays
  real-time; no email is sent from the notification.
- `contact_match_notifications.emailed_at` (nullable) turns the dedup ledger into
  the digest queue: NULL = surfaced (bell fired) but not yet emailed.
- `corex:matches:send-digests` (scheduled daily 07:00, `onOneServer`) sweeps NULL
  rows, groups per agent then per contact, and sends ONE `MatchDigestMail`
  carrying every new match. It RE-CHECKS `isMatchableStatus` (and soft-delete) at
  send time, so a match whose listing went off-market overnight is dropped from
  the email. Every swept row is stamped `emailed_at` (included, off-market, or
  email-off) so nothing re-sends. Agents with match email off keep the bell,
  their rows are stamped without sending. Respects the same `notify_email` gate.

## 7. Flow — Contact-Triggered (existing form, kept + improved)

Agent opens contact → "New Match" → multi-suburb chips + must-have / nice-to-have toggles → Save → results sorted by score, with score badge per property → Share via WhatsApp deep link (existing).

## 8. Flow — Public Shared Page

Existing `shared.match` route. Additions:
- Each property card gets three buttons: 👍 Interested · 💾 Save · 👎 Not for me.
- Click POSTs to `shared.match.feedback` → writes `contact_match_feedback` and updates `contact_matches.last_engaged_at = now()`.
- Agent sees an "Engagement" column on `corex.core-matches.index` showing newest reactions.

## 9. Flow — Deal Bridge

Property page Core Matches tab and the match results page each get a "Convert to Viewing" button per row. Action:
- Route: `POST /corex/contacts/{contact}/matches/{match}/convert/{property}`
- Creates a `Deal` (V1) draft with `property_id`, primary contact, agent = the match's createdBy, deal_type from listing_type. Status `draft`.
- Logs activity on the contact.
- Marks the match `status = fulfilled` if user confirms in modal.
- Redirects to the Deal edit page.

(V2 deal bridge is out of scope of this spec — we'll add it once V2 is the live module.)

## 10. Lifecycle / Auto-archive

`php artisan corex:matches:archive-stale` (daily at 03:00):
- A match with no `last_engaged_at` for 90 days → `expired`.
- A match where the contact has a registered Deal in the last 60 days as buyer/tenant → `fulfilled`.
- A match marked `paused` by user does not expire automatically.

## 11. UI Placement

| Where | What |
|---|---|
| Sidebar → Core Matches (already exists) | Now shows badge with count of unread match notifications |
| Contact page → Matches tab | List of this contact's matches, status, score-sorted properties under each |
| Property page → Core Matches tab | List of contacts whose match this property satisfies, sorted by score |
| Public shared page | Score badge + 3 reaction buttons per property card |
| Top nav notification bell | NewPropertyMatchNotification appears with link to property |
| Settings → Feature Toggles → Core Matches | existing toggles + new: notify_agent_in_app, notify_agent_email, min_score_to_notify (default 60) |

## 12. Files

### New
- `database/migrations/2026_04_28_100001_extend_contact_matches.php`
- `database/migrations/2026_04_28_100002_create_contact_match_feedback_table.php`
- `database/migrations/2026_04_28_100003_create_contact_match_notifications_table.php`
- `database/migrations/2026_07_03_120000_add_emailed_at_to_contact_match_notifications.php` — digest queue column
- `app/Services/Matching/MatchingService.php`
- `app/Jobs/MatchPropertyJob.php`
- `app/Notifications/NewPropertyMatchNotification.php`
- `app/Console/Commands/Matches/SendMatchDigests.php` — daily digest command
- `app/Mail/Matches/MatchDigestMail.php` — the one-per-agent digest email
- `resources/views/emails/matches/digest.blade.php` — digest template
- `app/Models/ContactMatchFeedback.php`
- `app/Models/ContactMatchNotification.php`
- `app/Console/Commands/ArchiveStaleMatches.php`

### Modified
- `app/Models/ContactMatch.php` — BelongsToAgency, status, scopes, helpers
- `app/Http/Controllers/CoreX/ContactMatchController.php` — multi-suburb, score sort, status, Deal bridge
- `app/Http/Controllers/SharedMatchController.php` — feedback action
- `app/Http/Controllers/CoreX/PropertyController.php` — replace in-memory filter with `MatchingService::matchesForProperty()`
- `app/Observers/PropertyObserver.php` — dispatch MatchPropertyJob
- `routes/web.php` — feedback + convert routes
- `resources/views/corex/core-matches/index.blade.php` — engagement column
- `resources/views/corex/contacts/match-results.blade.php` — score badges, multi-suburb chips
- `resources/views/shared/match.blade.php` — feedback buttons
- `resources/views/corex/properties/show.blade.php` — score-sorted matches tab + convert button
- `routes/console.php` — schedule archive-stale daily
- `config/corex-permissions.php` — new permission keys

## 13. Acceptance Criteria

1. Creating a property that fits 3 active matches in the same agency creates 3 `contact_match_notifications` rows and 3 in-app notifications to the relevant agents within 30 seconds.
2. Re-saving the same property without criteria-affecting changes does NOT create duplicate notifications.
3. A match whose contact lives in another agency is **never** considered (verified by tenancy test).
4. Property page Core Matches tab loads in < 200ms with 5,000 active matches in DB (no PHP-side `::all()->filter()`).
5. Match results page sorts by score desc; scores visible as a coloured badge.
6. Clicking 👍 on the public page increments engagement; agent sees the reaction on the contact's Matches tab.
7. "Convert to Viewing" creates a Deal with property + contact + agent pre-filled and redirects to Deal edit.
8. The archive-stale command moves a match with 91-day-old `last_engaged_at` to `expired`.
9. `dev-check.ps1` passes with 0 new failures. `php -l` clean on every changed file.
10. Sidebar Core Matches badge shows unread notification count and clears on visit.

## 14. Out of Scope (deferred)

- Ellie hook ("draft match from this conversation") — separate spec.
- Geographic radius / map-based suburb selection — phase 2.
- Deal V2 bridge — picked up when V2 is the default deal module.
- Buyer-portal account login (currently public-token shared link) — phase 3.
