# Seller live link — what we actually hold, 2026-08-24

**Read-only. No code changed, no data touched.** Every number below is a live `COUNT()`/
sample query against staging. Johan's ask: enhance `/property/live/{token}` to be visual
and carry everything a seller needs to see — before designing anything, find out what data
actually exists to build it from, and the real fill rate, not the theoretical one.

Baseline for every fill-rate figure below: **333 active-status properties** (`Active` /
`NewListing` / `Reduced`, not soft-deleted) out of 9,646 non-deleted properties total. A
few figures are all-time (noted where relevant).

---

## PART 1 — Availability audit

### Viewing history & viewing feedback

**HAVE**, and richer than what's currently shown. `calendar_event_feedback` joined via
`calendar_event_links` (`role = 'subject_property'`) — one row per feedback submission,
with `outcome_option_id`, `concern_option_ids`, `seller_visible_notes` (text, meant to be
shown externally) and `internal_notes` (never shown externally — the split already exists
at the data layer). `PropertyIntelligenceService::getRecentViewings()` already assembles
this per-viewing, WITH buyer names attached — unused by the seller page today, and **not
safe to reuse as-is** (see Part 2 — it returns real buyer identities, built for internal
agent use).

**FILL RATE: 29.9%.** 127 viewing-linked calendar events all-time; only 38 have at least
one captured feedback row. 78 distinct properties (23% of the active universe) have ever
had a recorded viewing at all. 64 feedback rows total, 63 of them seller-visible (not
`internal_only`).

**This is the number that should drive layout, not intuition**: a viewing-feedback section
built as the centrepiece will be empty on roughly 7 of every 10 properties that have even
been viewed, and most active listings have no recorded viewing at all yet. It has to be
designed to look complete and honest at zero, not just decorated when populated.

### Enquiry & lead counts

**HAVE**, two layers:
- `portal_leads` — individual enquiry records (name/email/phone/message — buyer PII, see
  Part 2), `listing_id` FK to `properties`. **529 rows all-time, 136/333 active properties
  (41%) have at least one, 524 of the 529 landed in the last 90 days** — this is a live,
  actively-flowing table, not a legacy one.
- `property_portal_metrics.total_leads` — daily aggregate lead COUNT from the P24 stats
  API (separate from the individual `portal_leads` rows, a coarser number but with deeper
  history). Same coverage as the views figure below.

### Listing / portal / client views

**HAVE, P24 only.** `property_portal_metrics` (populated daily by `P24StatsService`) —
`view_count` per property per day. **242/333 active properties (73%) have some P24/PP
metric row all-time; 154/333 (46%) have one in the last 30 days**, summing to 19,639 P24
views across those properties in that window — real, substantial numbers where present.
Private Property has **no views/statistics API at all** (confirmed in the service's own
docblock) — PP coverage (125 properties) is lifecycle events and webhook leads only, never
a view count. Any "views" figure on the seller page must say P24, not claim a total the
data can't back.

### Price history & reductions

**HAVE, via `property_audit_log`** (`event_category = 'property'`, `event_type =
'price_changed'`) — NOT a dedicated price-history table (`properties.price` is a single
current value with no history column of its own). Each row carries `old_values`/
`new_values` JSON **and a pre-built human-readable `human_summary`** (e.g. "Price changed
from R 899,000 to R 799,000") — genuinely ready to render, not raw data needing
interpretation.

**FILL RATE: 11.7%.** 39/333 active properties have at least one recorded price-change
event. Corroborated by `properties.status_label = 'Reduced Price'`: 42/333 (12.6%) are
currently flagged reduced — same order of magnitude, two different mechanisms agreeing.
Low, but real and well-formed when present — most active listings simply haven't had a
price change, which is expected, not a data gap.

### Days on market

**HAVE, 100% coverage — no fill-rate problem.** Already computed identically in three
places (`getComplianceStatus()`, `getComparableListings()`, `getLatestMarketPosition()`)
via the same fallback chain: `listed_date` → `p24_activated_at` → `pp_activated_at` →
`published_at` → `created_at`. Always resolves to something, so this is the one figure the
page can always show with total confidence.

### Marketing activity

**Table exists (`property_marketing_activities`, rich enum: `portal_listed`,
`portal_renewed`, `photos_refreshed`, `price_adjusted`, `show_day_held`, `social_share`,
`featured_upgrade`, `marketing_email`, `other`) — but functionally DON'T HAVE today.**
233 rows exist, but **100% of them are `activity_type = 'other'`**, and inspecting the
payload: every single row is `{"action": "marked_sold", ...}` or `{"action":
"sold_by_third_party", ...}` from a bulk sold-import job (225 of 233 rows logged by one
system user). **Zero rows exist for any of the genuinely useful types** — no
`portal_listed`, no `photos_refreshed`, no `show_day_held`, no `price_adjusted`, no
`social_share`. And since a concluded (sold) property no longer reaches this page at all
(today's earlier fix), the only rows that exist belong to properties that would never show
this section anyway. **This section is effectively always empty for any property a seller
would actually be looking at right now.** The schema is ready; nothing writes to it for
real marketing actions. Wiring this up for real is separate work, not a data-modelling gap
— it needs something (agent action, portal sync, photo pipeline) to actually log an event
when it happens.

### Buyer match counts (Core Match demand)

**HAVE, live-computed — the strongest data on the page**, and the exact mechanism cc4 uses
for the suburb report: `MatchingService::matchesForProperty()`, the same canonical engine
the internal Core Matches tab uses (not a separate/weaker copy). Sampled 12 random active
properties live: **10 of 12 had at least one matching buyer** (range 0–11 matches), average
compute time **14ms per property** — fast enough to run on every page load without caching.
`PropertyIntelligenceService::getBuyerInterestSignals()` already wraps this into the right
shape (buyer id/name/tier/score) — built, but **currently called from nowhere** (dead code
today). It returns real buyer names and must be re-shaped before it ever reaches a seller
(Part 2).

### Agent activity & seller-facing notes

**HAVE**, two sources already used by the current page:
- `property_recommendations` — agent (or auto-derived) insights, gated by
  `seller_visible = true` + `seller_facing_title` set. **FILL RATE: 4.5%** (15/333 active
  properties have a live one). Low by nature — this is meant to be occasional, notable
  guidance, not a permanent fixture, so a low number here isn't a gap, it's the right shape.
- `calendar_event_feedback.seller_visible_notes` — free-text notes an agent can attach to a
  specific viewing for the seller to read, distinct from `internal_notes`. Covered by the
  29.9% viewing-feedback fill rate above.

### Market presentation (currently on the page, worth flagging)

**Currently DON'T HAVE, despite a whole section existing for it.** 138 presentations exist
on staging, but **0 have `listing_id` set, and 0 are `status = 'finalized'`** — the seller
page's query (`getPresentations($id, sellerView: true)`) requires both. This section has
**never rendered anything on real data.** Whatever links a presentation back to its subject
property in practice doesn't set the column this query reads — a real gap, separate from
today's task, worth its own look but not solved here.

---

## PART 2 — The privacy boundary

### What the page shows today, confirmed by reading the controller and view directly

`SellerLinkController::show()` passes the **full** `Contact` model for the seller into the
view (`'seller' => $contact`), but the template only ever prints `$seller->first_name` (the
greeting: *"Hi Sarah, here's what's happening..."*). Nothing else about the seller's own
contact record renders. The agent's name and a two-letter avatar render; no agent contact
details beyond what the shared agent-card component already shows on every other public
page. Comparable listings show **other properties'** title/suburb/price — never a buyer.
Viewing feedback today is **counts only** (`total_viewings`, `total_feedback_rows`) — no
buyer names, no comments, nothing anonymisable because nothing identifying is shown at all
yet. **Today's page is privacy-safe, but only because it's thin — it doesn't yet show
enough to have made a mistake.** The moment feedback content, viewings, or buyer matches
get added, that safety has to be built deliberately, not inherited.

### Where the line sits — this is a design constraint, not a suggestion

The page is reached by an unguessable token, generated for one seller, but **the URL can be
forwarded** — by the seller, by accident, screenshotted, whatever. So the design has to
assume **the audience is "probably the seller, possibly anyone they showed it to,"** never
"definitely the seller." That rules out putting anything on the page a seller could see but
a stranger holding a forwarded link must not — there's no login between the token and the
page to enforce a tighter audience.

**The line, concretely:**

- **Buyer/enquirer identity — never, in any form.** Not a name, not an initial, not "a
  buyer from Ramsgate" if that's identifying in a small suburb. `getRecentViewings()` and
  `getBuyerInterestSignals()` both return real names today and **must be stripped down to
  counts and anonymous tiers before they reach this page** — a new seller-safe shape, not a
  reuse of the internal-facing methods as-is. "4 people viewed, here's what 3 of them said"
  — yes. "Sarah M. said the kitchen felt small" — no, even though the seller is entitled to
  know the kitchen comment happened.
- **Enquirer contact details (`portal_leads.name/email/phone/message`) — never.** A raw
  enquiry count ("6 enquiries this month") is fine; the enquiry table's actual PII fields
  are not, for the same forwarded-link reason.
- **Feedback content — yes, with the existing `seller_visible_notes` / `internal_notes`
  split already doing the filtering.** That distinction already exists precisely so a
  seller-facing surface can trust it; use it as the gate, don't re-derive a new one.
- **Buyer match count and tier ("11 matching buyers, 3 of them hot") — yes, as a number and
  a tier label, never a name or contact path.** This is exactly the anonymisation
  `getBuyerInterestSignals()` needs before reuse.
- **Price, days on market, mandate status, marketing activity, comparable stock — yes**,
  all already about the property or the market, not about a person.
- **The seller's own first name in the greeting — acceptable, matches what's there today.**
  A forwarded link revealing "this report is addressed to Sarah" is a much smaller leak
  than anything about a buyer, and removing it would make the page feel colder for the
  actual seller for a marginal privacy gain. Keep as-is.

---

## PART 3 — The proposal (not built — for review)

### The seller's single most important question, in order

Johan's framing gives the order directly: **"is anyone looking at my house" first, "what
are they saying" second, "what is my agent doing about it" third.** The current page leads
with a generic hero and a 2-stat grid (viewings, days listed) — buries the strongest signal
(buyer demand) at the very bottom disguised as "similar properties," and never surfaces it
doesn't currently call `getBuyerInterestSignals()` at all. Reordering around the actual
question, not the current build order:

1. **Hero, kept**: agency-branded, seller's first name, agent card — this part already
   works and needs no change in substance, only placement relative to what follows.
2. **"Is anyone looking at my house" — buyer demand, first, big.** The Core Match buyer
   count and tier breakdown (anonymised, per Part 2) is the strongest, fastest, most
   reliably-populated real signal on the whole page (10/12 sampled properties had ≥1
   match, 14ms compute) — it should be the first number a seller sees, not the last. Pair
   it with portal views (P24, honestly labelled) and enquiry count for the fuller "who's
   looking" picture.
3. **"What are they saying" — viewing feedback, second.** Given the 29.9% fill rate, this
   section has to be designed to read as complete and reassuring at zero ("No viewings
   recorded yet — here's what happens next") rather than a blank hole, and to show
   `seller_visible_notes` content (not just counts) when present, anonymised per Part 2.
4. **"What is my agent doing" — marketing activity + agent insights, third.** Marketing
   activity needs the write-side gap flagged in Part 1 fixed before this section is
   meaningful (today it would be empty on every real listing); agent insights (4.5% fill)
   slot in here when present, omitted cleanly when not.
5. **Market position — price, days on market, comparable stock, price-change history when
   present** (11.7% fill, worth showing as a small timeline strip when populated, silent
   when not). Keeps sellers grounded in where their listing sits, without leading with it.
6. **Listing status / compliance strip** — kept as today, lower down; it's operational
   detail, not the emotional headline of the page.
7. **Agent card + company footer** — kept as today, unchanged.

The "Market presentation" section should come out of the layout entirely until the
`listing_id` linkage gap (Part 1) is separately fixed — an always-empty section is worse
than no section.

**Multi-tenant branding**: unchanged from today's pattern — `$agency->default_color` /
`icon_color` / `button_color` / `logo_path`, resolved from the property's own `agency_id`,
same as the rest of today's fixes. No hardcoded agency anywhere on this page today, and the
proposal doesn't introduce any.

Awaiting sign-off on this shape before any building starts.
