# Spec: Property — Sold by 3rd Party

**Ticket:** AT-350
**Status:** DRAFT — awaiting Johan's sign-off (no code until signed)
**Owner:** Andre
**Branch:** `AT-350-Properties-add-Sold-by-3rd-party-option`
**Drafted:** 2026-07-30
**Pillars:** Property (primary), Agent (User), Contact (seller, read-only)

---

## 1. What this feature does and why

Home Finders Coastal routinely holds **open mandates**. Under an open mandate a
competing agency can — and does — sell the property out from under us. Today
CoreX gives the agent no honest way to record that. The Status dropdown offers:

| What the agent picks today | What it does | Why it is wrong |
|---|---|---|
| **Sold** | writes `properties.status='sold'` + a `property_sold_records` row, feeds the Properties "Sold" KPI, the Map "our sold stock" layer, the website Sold showcase, the P24 `Sold` badge | **Credits HFC with a sale it did not make.** Inflates agent performance, publishes a competitor's sale as ours on our own website and on Property24 |
| **Withdrawn** | takes it off-market, no sale data retained | **Throws away real market intelligence** — the property demonstrably sold, at a price, on a date, to a buyer, through a named competitor. That is a valid CMA comp and the single most useful loss signal an agency can capture |

So the agent's only two options are to lie or to forget. Both are unacceptable.

This feature adds a **third, honest outcome**: the property sold, we know it
sold, it leaves the market — and CoreX records **who beat us and why**, without
crediting HFC for one cent of it.

**Two distinct things are being captured, and the distinction is the point:**

1. **The sale happened** — a market fact. Price + date + suburb are a legitimate
   CMA comp and suburb-intelligence datapoint no matter who wrote the OTP.
2. **We lost it** — an agency fact. Which competitor, at what price against our
   asking, after how many days on market, on what mandate type, and why. This is
   the loss-analysis dimension Johan asked for ("where the buyer was lost"), and
   it is deliberately kept in its own record so that loss analytics can never be
   confused with, or polluted by, our own sales ledger.

---

## 2. Pillar connections

| Pillar | Reads | Writes back |
|---|---|---|
| **Property** | `status`, `price`, `mandate_type`, `listed_date`, `published_at`, `suburb`, `property_type` | `properties.status = 'sold_by_3rd_party'`; delists from every portal |
| **Agent (User)** | acting user (`auth()->id()`) | `recorded_by_user_id` on the loss record; the agent's performance figures are **explicitly excluded** from this sale |
| **Deal** | — | none. A 3rd-party sale creates **no** Deal and **no** commission record — that is the entire point |
| **Contact** | seller (via `property_contacts`) | none in this build. The seller relationship survives the loss and stays intact for re-listing |

Enriched data flows back out to: CMA comps, suburb intelligence, the Map sold
layer, and a new agency-level Loss Analysis report.

---

## 3. Decisions (confirmed with product owner, 2026-07-30)

| # | Decision | Chosen | Rationale |
|---|---|---|---|
| D1 | Storage shape | **A real status value** `sold_by_3rd_party`, plus a **fix-the-class refactor** of every inline `'sold'` literal | The agent picks it straight from the Status dropdown — it is a lifecycle state, not a hidden checkbox. The refactor is mandatory, not optional: see §7 |
| D2 | Comps | **Write the sold record, exclude from our KPIs** — and keep the loss dimension in a **separate table** so an agency can track who won it and why | Johan: "it should but also separate so that an agency can track stuff like where the buyer was lost etc" |
| D3 | Portals | **Delist / withdraw from P24, Private Property and our own website** | Pushing P24 `Sold` badges a competitor's sale as an HFC sale. `removesFromPortal()` also treats `Sold` as *still on portal*, so the advert would stay live for a property that is gone — the exact defect class of `.ai/audits/p24-sold-not-delisted-2026-07-10.md` |
| D4 | Capture | **Selling agency + price + date, every field optional** | We frequently only hear *that* it sold. A required field would push the agent back to "Withdrawn" and we would lose the intel entirely. Absorb, don't prevent — BUILD_STANDARD §3 |

### D5 — Declared deviation from SYSTEM.md §3 (No Hardcoding)

`loss_reason` is a **PHP constant set** on the new model, NOT a
`PropertySettingItem` group and NOT an `agency_feedback_options` category.

**Why:** loss analytics are only meaningful when the keys are stable across
agencies and across time. A per-agency free-text label list makes
"why do we lose listings?" unanswerable at group level, and makes historical
comparison break the moment an agency renames an option. This mirrors the
established sibling in exactly this domain —
`PresentationOutcome::ALL_CANCELLATION_REASONS`
([app/Models/PresentationOutcome.php:56](../../app/Models/PresentationOutcome.php#L56)) — which
chose a constant set for the identical reason ("preserve analytics integrity").

**Consequence:** this introduces **no new agency setting**, so
CLAUDE.md Non-negotiable #10a (every new setting reaches the Setup Wizard) does
**not** apply and no `config/agency-onboarding-copy.php` change is required.

> **DECIDED 2026-07-30 — Johan: "your call".** The constant set stands, as
> built. If an agency ever needs its own loss vocabulary, the migration path is
> `agency_feedback_options` with `category = 'listing_loss_reason'`, and
> Non-negotiable #10a (Setup Wizard) comes back into scope at that point.

### D6 — Rental sibling: OUT of scope, deliberately

The rental-side equivalent ("Let by another agency") is a real sibling and is
**not** built here. Sale-side was what was asked for, and the rental loss record
carries different fields (lease term, escalation, deposit). Recorded here so the
omission is a decision on the record, not an oversight. Suggested follow-up
ticket: **AT-351 — Let by 3rd Party**.

---

## 4. Data model / migrations

### 4.1 New status setting item (migration backfill — NOT a seeder)

> **Corrected during build.** The first cut of this migration inserted a single
> **global** row (`agency_id` NULL). That is impossible in this table:
> `property_setting_items.agency_id` is **NOT NULL with an FK to `agencies`** —
> `2026_05_23_081000_add_agency_id_to_property_setting_items_table` backfilled the
> originally-nullable column and then hard-locked it. The insert died with
> `Column 'agency_id' cannot be null` and took all 25 tests down with it. The same
> trap is documented at `DemoDataSeeder::backfillPropertyStatusItems()`.

`property_setting_items` gains **one row per agency** — the table is strictly
tenant-owned and has no notion of a shared row. The agency list is derived from
the tenants that **already carry `property_status` items**, not from `agencies`:
that guarantees every `agency_id` is FK-valid, and skips agencies that have never
configured statuses (handing such a tenant a dropdown containing nothing but
"Sold by 3rd Party" is worse than the empty list they have today).

Each row:

```
group      = 'property_status'
name       = 'Sold by 3rd Party'
sort_order = 6           (the same slot as 'Sold')
is_default = 1
active     = 1
```

`sort_order` 6 matches `Sold`'s existing value; `scopeGroup` orders by
`sort_order` then `name`, so the pair renders "Sold", "Sold by 3rd Party" —
adjacent, which is where an agent looks for it.

The Status dropdown slugs the name with
`strtolower(str_replace(' ', '_', $item->name))`
([show.blade.php:2852](../../resources/views/corex/properties/show.blade.php#L2852)),
so the stored value is exactly **`sold_by_3rd_party`**. The name is chosen so the
slug is clean — the `2026_03_30_100001_rename_property_status_items` migration
exists precisely because "For Sale • Reduced Price" slugged to
`for_sale_•_reduced_price`. No bullets, no em-dashes, no punctuation.

**Provisioned by migration backfill**, per BUILD_STANDARD §8 "reference data
travels with the deploy" — seeders do NOT run on a `git pull` deploy (AT-162).
Idempotent per tenant: insert only when absent, matching
`2026_03_05_300003_seed_default_setting_items`.

It is deliberately **not** registered in `deploy:sync-reference-data`: that
command carries GLOBAL reference rows, and this row is tenant-owned.

### 4.1a AT-352 — every agency gets the default property settings (FIXED HERE)

**Gap found while fixing §4.1.** Nothing in CoreX provisioned property settings
for an agency created **after** a given migration. The sets were all one-off
backfills — `2026_03_05_300002` (property_type), `2026_03_05_300003`
(category / status / mandate_type), `2026_06_17_120000` (condition_level) — and
no agency-creation hook touched the table (`AgencyObserver` seeded contact
settings and leave visibility, nothing else; no onboarding service did either).

A tenant onboarded after those ran therefore opened Properties to **empty
required dropdowns** — not just missing "Sold by 3rd Party", but missing *Sold*,
*Withdrawn*, *House*, *Residential*, *Sole* and every condition level — and
could not capture a listing at all until someone configured the system by hand.

**Johan's call, 2026-07-30: "Change that all agencies should get the current
Status as default."** Built for **all five groups**, not statuses alone: the
mechanism is identical and a fix that fills the Status dropdown while leaving
Type, Category, Mandate and Condition empty is the same defect one field over.

**Implementation**

| Piece | Role |
|---|---|
| `PropertySettingItem::DEFAULT_ROWS` | the canonical set, captured verbatim from the three original migrations (property types use the **post-normalisation** names from `2026_05_14_130001`, so a new agency never starts on the retired vocabulary). Array order is sort order. Mirrors `AgencyLeaveVisibilityMatrix::defaultRows()`, the precedent `AgencyObserver` already consumes |
| `PropertySettingItem::provisionDefaultsFor()` | idempotent seeder. Writes via the query builder — `BelongsToAgency`'s `creating` hook force-stamps the **acting** user's agency over an explicit `agency_id`, so seeding a brand-new tenant from an admin's session would file the rows under the admin's own agency (the trap `AgencyObserver` already documents for `AgencyContactSettings`). Returns 0 on `agencyId <= 0` — never a sentinel tenant (Rule 17) |
| `AgencyObserver::created()` | every agency from now on. Wrapped in try/catch: a tenant must not fail to be created because its settings could not be seeded — the settings are recoverable on the next deploy, a half-created agency is not |
| `2026_08_20_000004` | backfills agencies that already exist. Irreversible by design (`down()` is a no-op): these rows are indistinguishable from ones an agency has since adopted or reordered, and deleting every `is_default` row would strip working agencies of the vocabulary their live listings reference |

**The load-bearing rule: seeding is PER GROUP, and only when that group is
completely empty.** An agency that curated its own statuses — renamed "For Sale"
to "On the Market", deleted the auction status it never uses — must never have
those choices silently reinstated by a later deploy. SYSTEM.md §3 exists so the
agency owns its vocabulary. A group holding even one row is treated as
configured and left entirely alone.

That makes §4.1 and §4.1a **complementary by construction**, covering every
agency between them with no overlap and no possibility of overwriting tenant
data:

- `2026_08_20_000001` → agencies that **have** statuses get the one new value.
- `2026_08_20_000004` → agencies that have **none** get the whole default set
  (which now includes "Sold by 3rd Party").

Ordered 000001 before 000004 so an agency seeded by the latter is not then
double-handled.

Covered by `tests/Feature/Properties/PropertySettingDefaultsTest.php`.

### 4.2 New table `property_third_party_sales` — the loss record

One row per **loss event**. A property that is lost, re-listed, and lost again
produces two rows — the history is the asset.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `property_id` | FK → properties, cascade | |
| `agency_id` | FK → agencies, nullable, SET NULL | `BelongsToAgency` |
| `branch_id` | FK → branches, nullable, SET NULL | snapshot of the listing's branch at loss |
| `sold_by_agency` | varchar(200) **nullable** | the competitor. Free text — we do not maintain a competitor register (yet) |
| `sold_price` | decimal(14,2) **nullable** | what they got |
| `sold_date` | date **nullable** | when |
| `our_listing_price` | decimal(14,2) nullable | **snapshot** of `properties.price` at loss |
| `our_mandate_type` | varchar(50) nullable | **snapshot** of `properties.mandate_type` |
| `days_on_market` | int unsigned nullable | computed from `published_at`/`listed_date` |
| `loss_reason` | varchar(50) nullable | constant set — see §4.3 |
| `notes` | text nullable | free text, max 2000 |
| `sold_record_id` | FK → property_sold_records, nullable, SET NULL | the comp this loss produced, when it produced one. An explicit link, not a re-match on `property_id`: a property lost, re-listed and lost again yields TWO genuine sales, and a match-based upsert would overwrite the first comp with the second |
| `recorded_by_user_id` | FK → users, nullable, SET NULL | |
| `recorded_at` | timestamp | |
| `reverted_at` | timestamp **nullable** | stamped when the property leaves the status (re-listed). NULL = the open/current loss |
| `timestamps`, `softDeletes` | | Non-negotiable #1 |

Indexes: `(agency_id, sold_date)`, `(property_id, reverted_at)`,
`(agency_id, loss_reason)`, `(agency_id, sold_by_agency)`.

**Why snapshots (`our_listing_price`, `our_mandate_type`, `days_on_market`) and
not joins:** the property stays editable after the loss — it can be re-priced
and re-listed. A join would silently rewrite history and make
"were we priced above the winner?" answer differently every month.

### 4.3 `loss_reason` constant set

```php
PropertyThirdPartySale::LOSS_REASONS = [
    'competitor_had_buyer'      => 'Competitor already had the buyer',
    'priced_lower'              => 'Competitor priced it lower',
    'open_mandate_race'         => 'Open mandate — competitor got there first',
    'seller_relationship'       => 'Seller relationship with the other agent',
    'our_marketing'             => 'Our marketing / exposure fell short',
    'our_responsiveness'        => 'We were too slow to respond',
    'buyer_lost_to_competitor'  => 'Our buyer bought it through the other agency',
    'unknown'                   => 'Unknown',
    'other'                     => 'Other',
];
```

`buyer_lost_to_competitor` is the "where the buyer was lost" case Johan named:
we had the buyer, the competitor wrote the OTP. It is the single most expensive
loss an agency makes and it deserves its own key.

### 4.4 `property_sold_records` — two columns

| Column | Type | Notes |
|---|---|---|
| `sold_by_third_party` | boolean, default 0, indexed | the exclusion flag |
| `sold_by_agency` | varchar(200) nullable | denormalised for comp display ("Sold — Seeff Margate") |

A row is written **only when both `sold_price` and `sold_date` are supplied** —
a comp with no price and no date is not a comp. `source` stays `'manual'`
(the enum is unchanged; the new boolean carries the distinction).

### 4.5 Schema snapshot

Two new migrations → `DB_DATABASE=hfc_dash_test php artisan schema:dump`, then
`git add database/schema/mysql-schema.sql` in the same commit
(CLAUDE.md §12a; and the schema-dump-from-test-DB memo — a plain `schema:dump`
reads the stale dev DB and silently drops tables).

---

## 5. The fix-the-class refactor (D1's mandatory half)

A new status value is only safe if every "is this listing still live?" check
sees it. `'sold'` is inlined in ~12 places; the ones below currently mean *"not
live — stop chasing / stop advertising"* but enumerate the literals by hand, so
a `sold_by_3rd_party` listing would keep generating chore tasks, mandate-expiry
notices and adverts. Each is repointed at `Property::OFF_MARKET_STATUSES`, the
already-declared single source of truth.

| File | Line | Current | Becomes |
|---|---|---|---|
| `app/Models/Property.php` | 57 | `OFF_MARKET_STATUSES` | **+ `sold_by_3rd_party`** |
| `app/Models/Property.php` | 1135 | `CONCLUDED_STATUSES` | **+ `sold_by_3rd_party`** |
| `app/Models/Property.php` | 1173 | `statusBadge()` — exact-match `['sold','transferred']`, so the new value falls through to the default arm and would badge **"For Sale"** | explicit arm → **`'Sold by 3rd Party'`** |
| `app/Services/Syndication/Property24/Property24ListingMapper.php` | 1602 | `str_contains($status,'sold') => 'Sold'` — the new value **contains** "sold", so it maps to `Sold`, which `removesFromPortal()` says does **not** remove it. Competitor's sale, badged as ours, left live on P24 | explicit arm **before** the generic one → **`'Withdrawn'`** (D3) |
| `resources/views/corex/properties/live-preview.blade.php` | 44 | status map | **+ `['Sold', true]`** (client-facing label stays plain "Sold" — a seller preview is not the place to advertise a competitor) |
| `app/Console/Commands/ExpireMandates.php` | 34 | `whereNotIn('status', ['expired','sold','withdrawn'])` | `OFF_MARKET_STATUSES` |
| `app/Console/Commands/CommandCenter/ScanPropertyNotifications.php` | 25 | `['sold','withdrawn','expired']` | `OFF_MARKET_STATUSES` |
| `app/Services/CommandCenter/AutoEventService.php` | 180, 222 | `['sold','withdrawn','archived']` | `OFF_MARKET_STATUSES` |
| `app/Services/CommandCenter/CommandCentreService.php` | 1464 | `['sold','withdrawn','draft']` | `OFF_MARKET_STATUSES` |
| `app/Services/CommandCenter/OverdueSnapshotService.php` | 32 | `['sold','withdrawn','expired']` | `OFF_MARKET_STATUSES` |
| `app/Services/CommandCenter/PropertyHealthCalculator.php` | 144 | `['sold','withdrawn','archived']` | `OFF_MARKET_STATUSES` |
| `app/Services/Syndication/Website/WebsiteSyndicationService.php` | 96 | `whereNotIn('status', ['draft','sold','withdrawn'])` | `OFF_MARKET_STATUSES` |
| `app/Http/Controllers/Tools/AdManagerController.php` | 120 | 14 hand-cased literals | `OFF_MARKET_STATUSES` compared on `LOWER(status)` (the doubled casing existed because `properties.status` is genuinely mixed-case), **plus `'rented'` appended explicitly** — see the note below |

### Finding raised during the refactor — the `rented` asymmetry (NOT fixed here)

`'rented'` is in `Property::CONCLUDED_STATUSES` but **not** in
`OFF_MARKET_STATUSES`, which carries only the `'let_out'` spelling. So a tenanted
property currently counts as **on market** for `scopeOnMarket()`, the Properties
KPIs and the Map layers.

That looks like a genuine oversight, but adding `'rented'` to
`OFF_MARKET_STATUSES` moves live KPI and map numbers agency-wide, which is well
outside AT-350. So the refactor **preserves the existing behaviour verbatim**:
`AdManagerController` appends `'rented'` explicitly (dropping it would have
started generating adverts for tenanted stock — a regression the constant swap
would otherwise have introduced silently), and `WebsiteSyndicationService`
keeps its pre-existing gap. Raised for Johan as a separate call.

### Sites that are already correct — verified, deliberately NOT touched

These use `where('status','sold')` exactly, so the new value is excluded for
free, which is the behaviour we want:

- `PropertyController:224` — Properties "Sold" KPI (HFC sales only) ✔
- `CompanySettingsController:357` — push-sold ✔
- `WebsiteSyndicationService:109` — website **Sold showcase** ✔ (a competitor's
  sale must never appear in our showcase)
- `MapPinService:383` — "our sold stock" layer ✔
- `MapPinService:424` — off-market layer is `OFF_MARKET_STATUSES` minus `'sold'`,
  so the new value lands here automatically ✔
- `AgentPreviewController:115` — agent's public sold showcase ✔

---

## 6. UI placement and navigation

Non-negotiable #2 — every surface below ships in this build, not later.

1. **Status dropdown** (Property → Mandate & Assignment → Lifecycle) — the new
   option appears automatically from the settings row. **This is the primary
   entry point**: an agent who just picks it and saves must get a complete,
   correct outcome (see §7, the observer path).
2. **"Sold by 3rd Party" action** on the Intelligence tab, beside the existing
   "Mark as Sold" disclosure
   ([show.blade.php:4894](../../resources/views/corex/properties/show.blade.php#L4894)) —
   the rich path: competitor, price, date, loss reason, notes. Same
   `<details>` pattern, styled amber (`--ds-amber`) against Sold's red, so the
   two are never mis-clicked. Shown on the same on-market status set.
3. **Status pill** — sidebar summary + index rows. Amber
   (`--ds-amber`), label **"Sold by 3rd Party"**, `title=` tooltip
   "Sold by another agency — not an HFC sale" (STANDARDS F.8: plain English,
   and a tooltip regardless).
4. **Properties index status filter**
   ([index.blade.php:326](../../resources/views/corex/properties/index.blade.php#L326))
   — new `Sold by 3rd Party` option.
5. **Loss banner** on the property page when an open loss record exists:
   *"Sold by Seeff Margate on 12 Jun 2026 for R 2,150,000 — R 150,000 below our
   asking. Reason: competitor already had the buyer."* with an **Edit** and a
   **Re-list** action. STANDARDS §"No Silent Locks" — the state says what it is
   and offers the way out.
6. **Loss Analysis report** — Properties → Reports → "Lost to Competitors":
   count + value by competitor, by reason, by suburb, by agent, by period; and
   average gap between our asking and their achieved price. **This is the
   deliverable that makes D2's "separate" worth having** — without a report the
   loss record is a write-only field.

No new sidebar entry: every surface hangs off the existing Properties page.

---

## 7. User flow, and the two-paths problem

**Rich path** — Intelligence tab → "Sold by 3rd Party" → fill what is known →
Confirm.

**Lazy path** — Lifecycle → Status → "Sold by 3rd Party" → Save.

Both must produce the *same* system state, or we ship two behaviours for one
outcome. Enforced in `PropertyObserver` on the status transition, so **every**
ingress (web form, importer, API, Tinker, a future bulk action) converges:

```
ON transition INTO 'sold_by_3rd_party':
  1. if no open loss record (reverted_at IS NULL) → create one,
     snapshotting price / mandate_type / days_on_market / branch,
     recorded_by = auth()->id() (nullable — console and queue safe, Rule 17)
  2. delist: P24 + Private Property + own-website syndication  (D3)
  3. write property_sold_records ONLY if price AND date are known  (D2/§4.4)
  4. emit PropertySoldByThirdParty domain event  (Non-negotiable #9)
  5. log a PropertyMarketingActivity — 'sold_by_third_party'

ON transition OUT of 'sold_by_3rd_party' (re-listed):
  stamp reverted_at on the open record. The row is never deleted — the
  loss history is the asset.
```

The duplicate guard in step 1 makes the whole thing **idempotent**: the rich
path creates-then-enriches; re-saving the property changes nothing.

**Domain event** (`.ai/specs/corex-domain-events-spec.md` — Non-negotiable #9
forbids ad-hoc cross-pillar calls):

```
App\Events\Property\PropertySoldByThirdParty
  (propertyId, agencyId, soldByAgency, soldPrice, soldDate, lossReason, recordedByUserId)
```

**As built, the event ships with no bespoke listener** and therefore needs no
`AppServiceProvider` registration: `RecordDomainEvent` already subscribes to
`AbstractDomainEvent` itself, so the loss is audited automatically, and the
portal delist is owned by `PropertyObserver` (the status is in
`OFF_MARKET_STATUSES`) rather than duplicated here — one outcome, one owner.

If a future build DOES add a listener, it must be registered **explicitly in
`AppServiceProvider::boot()`** — event discovery is OFF in this codebase
(AT-261), and a listener in `app/Listeners` does nothing without it. It must
stay **sync**; anything slow queues a Job carrying scalars (a queued listener on
an `AbstractDomainEvent` fatals on the parent's readonly `$eventId`).

---

## 8. Input space & prevent-or-absorb (BUILD_STANDARD §2/§3)

Every capture field is optional (D4), so the empty paths are the *main* paths.

| Input | Decision | Behaviour |
|---|---|---|
| All fields empty ("just mark it") | **Absorb** | Loss record created with nulls. No `property_sold_records` row. Banner reads "Sold by another agency — details not captured" with an **Add details** link. **This is the lazy-but-valid shortcut and it is a first-class path** |
| Price given, date missing | **Absorb** | Loss record keeps the price; **no** comp written (§4.4 needs both). Banner shows the price |
| Date given, price missing | **Absorb** | Same, mirrored |
| `sold_date` in the future | **Prevent** | `before_or_equal:today` — a sale cannot have happened tomorrow. Message: "The sold date can't be in the future." |
| `sold_price` ≤ 0 or non-numeric | **Prevent** | `numeric|min:0`, message in plain English |
| `sold_price` absurd (> R1bn) | **Prevent** | `max:1000000000`, mirroring `cancellation_competitor_price` |
| `sold_by_agency` > 200 chars | **Prevent** | `max:200` |
| Whitespace-only `sold_by_agency` | **Absorb** | trimmed to null — never stored as `"   "`, never a distinct competitor in the report |
| `loss_reason` not in the constant set | **Prevent** | `Rule::in(array_keys(LOSS_REASONS))` |
| Property already `sold_by_3rd_party` | **Absorb** | idempotent — no duplicate record (§7 guard) |
| Property already `sold` (our sale) | **Prevent** | the action is not offered on a concluded listing; server-side re-check returns a clear message. A property cannot be sold by us *and* by them |
| Deleted agent / deleted branch on the record | **Absorb** | all FKs `nullOnDelete`; the report renders "Agent removed" — BUILD_STANDARD §4 |
| Portal delist fails (P24 down) | **Absorb** | status change and loss record **still commit**; the delist is queued and retried. A portal outage must never block an agent from recording the truth. Failure surfaces on the existing syndication panel |
| No agency context (owner not switched in) | **Prevent, clearly** | reject with "Switch into an agency before recording a 3rd-party sale" — never stamp a hardcoded or sentinel `agency_id` (STANDARDS Rule 17) |

---

## 9. Permissions

No new permission key. Recording a 3rd-party sale is an ordinary property write:

- Route group: existing `permission:access_properties` + `agency.required`.
- Controller: existing `authorizeProperty($property, forEdit: true)` — so an
  **assistant** (read-breadth, no write) is correctly refused (AT-267 §7.2).
- The Loss Analysis report reuses the properties permission; per-branch
  visibility follows the existing property scope, so an agent sees their own
  branch's losses and a principal sees the agency's.

---

## 10. Acceptance criteria

**Status & lifecycle**
- [ ] "Sold by 3rd Party" appears in the Status dropdown on a fresh install *and* on an existing install after `migrate` (backfill, not seeder)
- [ ] Selecting it and saving stores exactly `sold_by_3rd_party`
- [ ] `statusBadge()` returns "Sold by 3rd Party" — **not** "For Sale"
- [ ] `isOnMarket()` false; `isConcluded()` true
- [ ] Index status filter returns exactly the 3rd-party-sold set

**Never credited to HFC**
- [ ] Excluded from the Properties "Sold" KPI
- [ ] Excluded from the website Sold showcase and the agent public sold showcase
- [ ] Excluded from the Map "our sold stock" layer; present in the off-market layer
- [ ] No Deal and no commission record created

**Portals (D3)**
- [ ] `getP24Status('sold_by_3rd_party')` returns `'Withdrawn'` — **not** `'Sold'`
- [ ] `removesFromPortal()` true for that value, so the delist path runs
- [ ] Private Property and own-website syndication both delist

**Loss record (D2)**
- [ ] Rich path stores competitor, price, date, reason, notes + snapshots
- [ ] Lazy path (status dropdown only) still creates the loss record — same state
- [ ] Re-saving creates no duplicate (idempotent)
- [ ] Re-listing stamps `reverted_at`; the row is never deleted
- [ ] Loss Analysis report groups by competitor, reason, suburb, agent, period

**Comps (D2)**
- [ ] Price **and** date supplied → `property_sold_records` row with `sold_by_third_party = 1`
- [ ] Either missing → **no** comp row
- [ ] The comp appears in CMA/suburb intelligence, flagged as a 3rd-party sale
- [ ] Existing HFC sold-record queries are unchanged by the new column

**Robustness (BUILD_STANDARD §5 — the matrix in §8 above)**
- [ ] Happy path (all fields)
- [ ] Each optional field omitted, individually
- [ ] Lazy-but-valid shortcut (status only, zero fields) end to end
- [ ] One malformed input rejected per validated field, message plain English
- [ ] Deleted agent / deleted branch renders gracefully
- [ ] Repeat action is idempotent
- [ ] Test data uses real KZN South Coast suburbs, real agency names, real ZAR
      values — never "Test / Test / 0000000000"

**Process**
- [ ] `php -l` clean on every changed file
- [ ] The single most relevant test file passes (CLAUDE.md #13 — **no** broad
      suite without Johan's explicit go-ahead)
- [ ] `schema:dump` re-run **from the test DB** and committed with the migrations
- [ ] Verification report states **which input paths** were proven

---

## 11. Files to create / modify

**Create**
- `database/migrations/…_add_sold_by_3rd_party_status_item.php` — idempotent backfill
- `database/migrations/…_create_property_third_party_sales_table.php`
- `database/migrations/…_add_third_party_flags_to_property_sold_records.php`
- `app/Models/PropertyThirdPartySale.php` — `BelongsToAgency`, `SoftDeletes`, `LOSS_REASONS`
- `app/Events/Property/PropertySoldByThirdParty.php`
- `app/Services/Properties/ThirdPartySaleService.php` — the single write path both UI paths call
- `app/Http/Controllers/CoreX/ThirdPartySaleController.php` — record / update / revert
- `app/Http/Controllers/CoreX/LossAnalysisController.php` + view
- `resources/views/corex/properties/partials/_third-party-sale.blade.php` — action + banner
- `tests/Feature/Properties/SoldByThirdPartyTest.php` — the §8 matrix

**Modify**
- `app/Models/Property.php` — `OFF_MARKET_STATUSES`, `CONCLUDED_STATUSES`, `statusBadge()`
- `app/Observers/PropertyObserver.php` — the transition hook (§7)
- `app/Providers/AppServiceProvider.php` — explicit `Event::listen` (AT-261)
- `app/Services/Syndication/Property24/Property24ListingMapper.php` — explicit arm before the generic `sold`
- `resources/views/corex/properties/show.blade.php` — action, banner, status pill
- `resources/views/corex/properties/index.blade.php` — filter option, row pill
- `resources/views/corex/properties/live-preview.blade.php` — status map entry
- `routes/web.php` — routes under the existing properties group
- The 8 fix-the-class files listed in §5
- `database/schema/mysql-schema.sql` — regenerated
- `.ai/CHAT_STARTER.md` — on landing

---

## 12. Sign-off record (2026-07-30)

All open items are closed:

| Item | Outcome |
|---|---|
| **D1–D4** | Confirmed at spec time; built as specced |
| **D5** — loss reasons: code constant vs agency-configurable | **"Your call"** → the constant set stands. Recorded at §3 D5 |
| **D6** — rental sibling ("Let by another agency") | Remains out of scope. Suggested **AT-351** |
| **§4.1a** — new agencies get no property settings | **"Change that all agencies should get the current Status as default"** → built for all five setting groups (AT-352) |
| **§5** — the `rented` asymmetry in `OFF_MARKET_STATUSES` | **"If it won't cause issues just leave it"** → left as-is. It causes no issue for AT-350 (`sold_by_3rd_party` is in the constant, and `rented` behaves exactly as before — the exclusion is preserved verbatim in `AdManagerController`). Remains a documented gap, not a live defect |
