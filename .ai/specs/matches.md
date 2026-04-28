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
- property status in ['sold','withdrawn','draft']
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

## 6. Flow — Property-Triggered (the new behaviour)

```
Property::saved fires
  → PropertyObserver::saved()
  → If status active & published & price set → dispatch MatchPropertyJob($property)

MatchPropertyJob (queued)
  → MatchingService::candidatesForProperty($property)
       returns active ContactMatches in same agency where
       hard filters pass (SQL query, indexed)
  → For each candidate:
       score = MatchingService::score($property, $match)
       if score < 40 skip
       if (match,property) already in contact_match_notifications skip
       insert contact_match_notifications row
       notify $match->createdBy via NewPropertyMatchNotification
       (channel: database always; mail if user opted in)
  → Touch each candidate's `last_engaged_at` with NULL — only updates on user action
```

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
- `app/Services/Matching/MatchingService.php`
- `app/Jobs/MatchPropertyJob.php`
- `app/Notifications/NewPropertyMatchNotification.php`
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
