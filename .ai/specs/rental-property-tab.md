# Rental Details — Agency-Definable Fields & the Advert Block

**Status: SPEC APPROVED, BUILD IN PROGRESS (2026-09-21).** Originally
written spec-only; Johan has since ruled on all three open questions
raised in the first draft (§3 rental price type, §4.0 the advert
block's opt-in mechanism, §5.2 lease type) and on the bond calculator
(§6, closed). This supersedes the "parked" status this file carried
since 2026-09-13 — Johan has now picked the idea back up and expanded
it. The original parked capture is preserved verbatim in §0 as the
historical record; everything from §1 onward is the actual spec, now
reflecting his 2026-09-21 rulings inline at each affected section.

**2026-09-21 — Johan's rulings, dated note:**
1. **Lease type: make it real** (§5.2) — one agency-editable list
   replacing two divergent hardcoded ones, and it must drive something
   concrete now (P24 syndication + a DocuPerfect merge field), shaped
   for lease-agreement generation and rental financials still to come.
2. **Rental price type: ONE type, ONE price** (§3) — overrules this
   spec's first-draft tick-multiple design on the strength of its own
   finding that neither portal accepts more than one rate per listing.
3. **The advert block: opt-in per property** (§4.0) — a master tick on
   the Rental tab, off by default on every existing listing, retiring
   the migration-risk question (§4.5) by construction rather than by
   policy. Must be discoverable with an inline preview before an agent
   ticks it.
4. **Bond calculator: CLOSED** (§6) — sent to Andre, it's the agency's
   own website, not CoreX. Do not investigate further.

Building now, staged per §8, each part verified live on QA1 before the
next starts, each branch handed to cc1 reported as "pushed, awaiting
landing" until landing is confirmed — never "done" on handoff alone.

**Multi-agency throughout. Every rule an agency setting with a sensible
default. Nothing outside rentals. Nothing on Staging, nothing on live.**

Related specs: `.ai/specs/rentals-shared-screens.md` §11 (the Rental tab
as it exists today — COMPLETE, Parts 1-5, landed on QA1 2026-09-10; this
spec builds on top of that tab, does not re-open it), `.ai/specs/
rental-application-field-config.md` / the `RentalApplicationCustomField`
model (the pattern this spec mirrors), `.ai/specs/rental-work-orders.md`
(the staged-build-plan precedent §8 follows).

---

## 0. History — the original parked capture (2026-09-13), verbatim

*Preserved exactly as first written, for provenance. Everything Johan
says below is still true and still governs; §1 onward turns it into a
buildable spec and resolves the open questions he explicitly left
unanswered at the time.*

> Theres a couple of bits of info from the rental screen that I want to
> specifically use to build the portal ads but we can look at that later
> as we essentially going to use some of the info from rental screen to
> include in the ads - things like - deposit / lets assist / admin fee /
> utilities (included / excluded), etc. the idea Im playing with is if
> the agent completes these on the rental tab we build ... this is the
> information agents add manually to their ads. so if we hold all of
> this on the rental tab then we can have a tick on each on the rental
> tab that says - include in advertising. Now its as simple as building
> a text block at the end of the description field on properties where
> this information is displayed neat and tidy - and the massive plus
> side is that the agent dont have to capture it manually on advertising
> and cant forget the information that needs to be included

**The worked example** (real HFC advert):
<https://hfcoastal.co.za/property/stunning-little-shop-to-rent-in-port-edward-6135>

> Upfront costs: R4710 Deposit R4710 One Month's rental R1500 Once off
> admin fee R1500 Utility deposit. Exclude utilities which will be
> billed separately from the rental per month.

Three open questions were left unanswered on purpose in the original
capture — resolved now in §5:
1. Is the block written into `description` once, or generated fresh
   every time the description is displayed/exported? (§5.2)
2. What happens to existing hand-typed adverts that already contain this
   information, once a generated block also starts appearing? (§5.5)
3. Do portal feeds take `description` verbatim, such that a render-only
   (never-written-in) block would never actually reach the portal ad?
   Confirmed at the time: yes, P24 reads the raw stored field — this
   remains true (§5.1) and is the deciding fact behind §5.2's answer.

---

## 1. The two-tier field model, and why

Johan's ruling, verbatim: *"under settings rentals agencies can add
anything to this screen they desire... can we have free text, yes / no,
qty, and value inputs on a description and this builds the rental
screen?"* — read together with: *"we have a company called lets assist
that we add to our rentals. not all agencies will have this... for the
next agency it would mean nothing."*

**This is not pure free-form.** Two tiers:

- **CORE FIELDS — CoreX-owned, guaranteed on every agency, fixed.**
  Monthly rental, deposit, admin fee, available date, lease term. These
  already exist as real columns on `properties`
  (`rental_amount`, `deposit_amount`, `admin_fee`, `occupation_date`,
  `lease_period`/`lease_start_date`/`lease_end_date` — all built and
  landed, `.ai/specs/rentals-shared-screens.md` §11.2-11.5). **Nothing
  in this spec changes the core fields themselves** — they are the
  fixed floor every later feature stands on.
- **AGENCY-DEFINED FIELDS — on top, per Johan's four types.** Anything
  else an agency wants: Lets Assist (yes/no) for HFC, meaningless
  elsewhere; a "key deposit" line for one agency; a "parking bays"
  quantity for another. Each has a label/description, a type, and (per
  §4) a tick for whether it appears on the advert.

**Why the split, in Johan's own reasoning, restated precisely because it
is the load-bearing decision of this whole spec:** rental financials and
lease-agreement generation are coming. A later feature that computes
"total upfront cost" or drafts a lease agreement needs to know, with
certainty, that `rental_amount` and `deposit_amount` exist and mean the
same thing on every agency in the system. If everything were
agency-defined, that certainty doesn't exist — a feature built against
"whatever fields happen to be defined this week" has nothing reliable to
read, and the moment it needs a guaranteed field, that field has to be
invented retroactively and backfilled everywhere, which is exactly the
kind of rebuild this two-tier split exists to prevent. Lets Assist
cannot be a core field (HFC-only, meaningless elsewhere) and monthly
rental cannot be agency-defined (every future feature needs it to always
exist) — the two tiers are not a compromise, they are two genuinely
different kinds of fact about a rental.

---

## 2. Reuse verdict — mirror `RentalApplicationCustomField`, don't share its table

**Investigated directly, not assumed.** `RentalApplicationCustomField`
(`app/Models/RentalApplicationCustomField.php`, migration
`2026_09_20_100000_create_rental_application_custom_fields_table.php`,
controller `app/Http/Controllers/CoreX/
RentalApplicationCustomFieldController.php`) already implements exactly
the shape Johan is describing, one entity over: `agency_id`, `key`,
`label`, `help_text`, `field_type` (a plain string column, not a MySQL
enum — deliberately, so a future type never needs a migration),
`options` (json, for a choice-list type), `required`, `shown`,
`sort_order`, `created_by`, soft-deletes. Values are stored as a single
JSON column on the host row (`rental_applications.custom_field_values`,
keyed by the field's own `key`) — not a separate per-value table. Admin
CRUD (create/update/archive/restore/reorder) lives in its own
controller, its own settings-page section, gated by its own permission.

**Verdict: build a new, parallel table with the identical column shape —
do not add property-rental-details rows into the existing
`rental_application_custom_fields` table.** Reasoning:

1. **The value side is already hard-wired to the wrong host.**
   `custom_field_values` lives on `rental_applications`, and the
   `TYPE_FILE` validation path hard-codes `source_type =
   'rental_application'` (`RentalApplication.php:816`) when resolving
   an uploaded document. A property's rental-details tab is a
   completely different host — its own fields are written directly onto
   `properties` columns via `PropertyController::updateRentalDetails()`
   (`app/Http/Controllers/CoreX/PropertyController.php:2461`). Sharing
   the table would mean bolting a second, unrelated host concept onto a
   table whose value-storage half is already committed to the first
   one.
2. **Sharing would require a discriminator on every existing query.**
   Every static helper on `RentalApplicationCustomField`
   (`activeFor()`, `allFor()`, `generateKey()`'s de-dup) and every
   controller/view call site queries `where('agency_id', ...)` with no
   scope filter today. Reusing the table means adding an `applies_to`
   column and auditing every one of those call sites (plus the four
   existing `RentalApplicationCustomField*Test.php` files) to make sure
   none of them accidentally pick up a property-scoped row. That is
   coupling two unrelated features for a savings that is purely
   line-count, not architecture — exactly what `CLAUDE.md`'s "fix the
   class, not the instance" standard argues against.
3. **The `key` namespace is intentionally scoped to one JSON blob's
   lifetime.** `generateKey()` dedupes against every key an agency has
   *ever* used for this table, specifically so a key can never collide
   inside `rental_applications.custom_field_values`. A property-details
   field sharing that same key-space would be dedup-safe by accident,
   not by design — two independent key lifetimes coupled for no reason.
4. **This codebase's own convention is parallel, not shared.**
   `RentalApplicationCustomField`'s own docblock says it mirrors
   `RentalApplicationHighlighter` — a third, separate table with the
   identical shape, not a shared one. A peer lane's own recent work
   (agency-editable inspection feature ticks/room-type defaults,
   `RentalInspectionSetting`) chose the same approach: a new home on the
   entity it actually concerns, not a retrofit of an existing
   definition table. That work is architecturally different from this
   need anyway — see the note below — but the "new host, same shape"
   instinct is the established pattern either way.

**Concretely: a new model `PropertyRentalDetailsCustomField`**, same
column set as `RentalApplicationCustomField` verbatim (`agency_id`,
`key`, `label`, `help_text`, `field_type`, `options` json, `required`,
`shown`, `sort_order`, `advertise` — new, see §4 — `created_by`,
timestamps, soft-deletes), same static-helper shape
(`activeFor()`/`allFor()`/`generateKey()`), same controller shape
(`store`/`update`/`archive`/`restore`/`reorder`), and a new nullable
JSON column on `properties` (`rental_details_custom_field_values`,
keyed by the field's `key`) mirroring
`rental_applications.custom_field_values` exactly.

**Note on cc2's inspection-features work, checked so this spec doesn't
misread it as the same problem:** that work (unmerged at time of
writing, `RentalInspectionSetting.inspection_feature_labels` /
`room_type_item_defaults`) stores a flat JSON array or map directly on a
per-agency *singleton* settings row — the right shape for "an agency
picks a subset of, or overrides defaults within, an already-known
catalogue." It has no per-item `id`, no `sort_order`, no per-item
soft-delete, and no `field_type`/`options` concept, because the problem
it solves ("tick which of these known features apply," "override the
default item list for this room type") is not "let an agency invent an
arbitrary new typed question." Johan's ask here — free text / yes-no /
quantity / value, each independently defined, ordered, archivable — is
squarely the `RentalApplicationCustomField` shape, not cc2's. The two
"settings surfaces should behave alike" instruction is satisfied at the
level that matters (both are agency-editable, both avoid the Setup
Wizard for the same reason — no repeater control there — both live on
an existing settings screen) without forcing two different problems
into one schema.

### 2.1 Field types — mapping Johan's four onto the existing enum

`RentalApplicationCustomField`'s real `field_type` values are `text`,
`number`, `date`, `yes_no`, `choice_list`, `file` (confirmed by reading
the model's constants, not assumed from the feature name). Johan asked
for **free text, yes/no, quantity, value** — four types map onto three
existing constants plus one genuinely new one:

| Johan's word | Maps to |
|---|---|
| Free text | `TYPE_TEXT` (existing, unchanged) |
| Yes/No | `TYPE_YES_NO` (existing, unchanged) — Lets Assist is exactly this |
| Quantity | `TYPE_NUMBER` (existing, unchanged) — e.g. "parking bays: 2" |
| Value | **`TYPE_CURRENCY` — new constant** |

**Why a new constant rather than reusing `TYPE_NUMBER` for "value" too:**
the advert block (§4) needs to render a quantity and a Rand amount
differently — "2" vs "R1,500.00" — and a generic number type has no way
to carry that distinction. Because `field_type` is a plain string column
by design ("keeps future type additions migration-free" — the existing
model's own migration comment), adding `TYPE_CURRENCY = 'currency'` is
additive: no migration to the enum itself, no change to any existing
row, no change to `RentalApplicationCustomField`'s own four working
types. `date`/`choice_list`/`file` are not requested by Johan for this
feature — recommend NOT offering them on the rental-details definition
form even though the underlying pattern supports them, so the admin UI
only ever shows the four types actually asked for. Confirm with Johan
before excluding them outright, in case a future agency genuinely wants
a document-type answer (`file`) on this tab (e.g. Lets Assist's own
signed agreement) — flagged, not decided here.

---

## 3. Rental price type — ONE type per listing

**Johan's original framing** (2026-09-13/21), for the record: *"the
rental price type has a dropdown with selections... Im thinking we
should work this that - rental price type can be ticked to which is
applicable on this rental, then we give the inputfields for the values
thats ticked?"* — investigated as tick-multiple in the first draft of
this spec (§3.4 found neither portal can carry more than one rate per
listing). **Johan then overruled tick-multiple on the strength of that
finding, verbatim (2026-09-21):** *"so then we need to build same -
only 1 price allowed to match portals. not lots. so simple. select
price type as thats the dictating factor then enter the price."* Simple
beats clever: since neither portal can carry more than one rate, the
product now matches that reality instead of building a capability
neither outbound channel can use. **This is the current, buildable
spec — the dropdown stays a dropdown, select ONE type, enter ONE
price.** The original tick-multiple drafting is preserved in §3.5 for
the reasoning trail, superseded in full by what follows.

### 3.1 What exists today (confirmed by reading, not assumed)

`properties.rental_price_type` is a single `varchar`, no DB enum
(`2026_03_23_140000_add_pp_visibility_and_rental_columns_to_properties_table.php:19`).
Its five options (`per month`/`per sqm`/`per day`/`per week`/`per year`)
are a **hardcoded PHP array literal duplicated in two places** in
`resources/views/corex/properties/show.blade.php` (lines 4165 and 4369)
— not sourced from any agency-configurable list. `price_per_day`,
`price_per_week`, `price_per_year` already exist as real columns,
already validated, already rendered as **three independent,
always-visible optional inputs with no gating at all** to the dropdown
— an agent can already fill all three regardless of what the dropdown
says, which is a real, confirmed bug sitting directly underneath
Johan's complaint (there is no connection today between "which cadence
is selected" and "which value fields are shown"). There is **no**
`price_per_month` column (the "Per Month" option implicitly maps to the
existing `rental_amount` core field) and **no** `price_per_sqm` column
at all — "Per Sqm" is currently selectable with zero backing value
field anywhere.

### 3.2 The list itself becomes agency-definable — reusing `PropertySettingItem`, not a new mechanism

This is a **closed list of cadence names**, not a set of independently
typed questions — the right precedent already exists and is already
proven on this exact tab: **Furnished Status** was built exactly this
way (`.ai/specs/rentals-shared-screens.md` §11.5) — an agency-managed
`PropertySettingItem` list (same generic mechanism as `property_type`/
`category`/`mandate_type`), manageable via the existing generic
property-setting-item CRUD on Settings → Properties & Listings
(add/edit/reorder/soft-delete/batch-toggle), seeded per-agency via the
existing `provisionDefaultsFor()` pattern so every current and future
agency gets a sensible default list with zero new observer code.

**Recommendation: add `GROUP_RENTAL_PRICE_TYPE` as a new
`PropertySettingItem` group**, seeded with the current five values
(`Per Month`, `Per Sqm`, `Per Day`, `Per Week`, `Per Year`) as every
agency's starting default, editable/reorderable/archivable per-agency
from that point on exactly like Furnished Status already is. This is
**not** the `RentalApplicationCustomField`-style mechanism from §2 —
different problem (closed list of option strings vs. typed field
definitions), same reasoning as the cc2 note above: use the tool that
already fits, don't force one mechanism to do both jobs.

### 3.3 On the property — select ONE type, enter ONE price

The dropdown's options come from the agency's `PropertySettingItem`
list (§3.2) instead of the hardcoded array — the only change to the
control itself. Selecting a type reveals exactly one value input for
that type; changing the selection swaps which single input shows,
clearing whatever was previously entered rather than leaving a stale
value sitting in a now-hidden field. This fixes the confirmed bug
(§3.1) — `price_per_day`/`price_per_week`/`price_per_year` were
always-visible, independent optional inputs with no relationship to the
dropdown at all — as a direct consequence of building what Johan asked
for, not as separate work.

**`price_per_sqm` must be added** if "Per Sqm" survives in the agency's
default seeded list (§3.2) — a single-select model with one input per
type needs a real column behind every selectable option, and "Per Sqm"
is the one type with none today. "Per Month" continues to map onto the
existing `rental_amount` core field (§1) rather than gaining a parallel
`price_per_month` column, since monthly rental is already a guaranteed
core field and a second column for the identical fact would be exactly
the "captured twice" problem this whole initiative exists to eliminate.

### 3.4 Syndication — unchanged, and now trivially true

`Property24ListingMapper::mapRentalRate()` (lines 189-200) and
`PrivatePropertyListingMapper::mapRentalPriceType()` (lines 932-949,
citing "PP Agency Feed Service Rev 4.6 §2.3.1") keep mapping the single
selected type exactly as they do today — with exactly one type and one
price selected, there was never a second value to reconcile. Only the
*source* of the option list changes (agency-definable instead of
hardcoded); the mapping logic itself is untouched.

### 3.5 Superseded drafting — tick-multiple, kept for the reasoning trail

*Not the current spec — preserved so the "why" behind the ruling stays
legible, per Johan's own instinct that the reasoning matters as much as
the ask.* The first draft of this spec proposed checkboxes (one per
available cadence, sourced from the same `PropertySettingItem` list),
each revealing its own value input, with the reasoning that a holiday
letting genuinely has both a nightly and a weekly rate worth
advertising, and that ticked-but-not-portal-eligible values could feed
the advert block (§4) and website API without touching portal
submission. Johan overruled this on the plain strength of the finding
that fed it: if neither portal can carry more than one rate, building
multi-rate capture is complexity with no real destination for the extra
values today. **§3.3/§3.4 above are the current spec; this subsection
is history, not a live alternative.**

---

## 4. The advert block

Johan's ruling, verbatim: *"an intelligent way to add it as a block at
the bottom of the marketing description from corex before it goes out to
the portals... this will stop agents from forgetting this when they
create the ads."*

### 4.0 Opt-in PER PROPERTY (Johan's ruling, 2026-09-21) — this retires §4.5 entirely

**Johan's own answer to the duplicate-cost-lines migration risk (§4.5,
original drafting), and it is better than this spec's first draft,
verbatim:** *"The display advert block could possibly be a tick on
rental tab? so agents can go in, fix the description, update the rental
tab, hit the tick and all sorted out?"*

**A new property-level master tick, on the Rental tab: "Generate advert
block."** Off by default on every property, including every existing
rental listing. While off, `description` is sent exactly as stored,
byte-for-byte, with no block appended — an existing hand-typed advert is
completely untouched, forever, unless an agent opts it in. An agent
opts a property in only once they've gone in, removed their own
hand-typed cost lines from the description, confirmed the rental tab's
values are correct, and ticked the box — at which point the block is
generated fresh every time (§4.2) and can never drift again.

**This is a genuinely better answer than a bulk migration or an
agency-wide switch-on**, because it needs neither: there is no moment
where every existing listing must be inspected before a feature ships,
no risk of a generated block appending onto an untouched hand-typed one
on a live ad, and no agency-wide flag to weigh up. The property-level
tick and the per-field ticks (§4.1) are two different controls that
compose: the master tick decides whether this property uses the
generated-block feature AT ALL; the per-field ticks (still needed,
unchanged) decide WHICH fields appear in the block once a property has
opted in. **§4.5 as originally drafted — the migration-risk open
question — is retired by this ruling, not answered by policy**: there
is no migration to make, so there is nothing left to decide.

### 4.1 The mechanism — per-field advertise tick, computed block, appended

Every core field (§1) and every agency-defined field (§2) gets an
`advertise` boolean (new column on both the fixed core-field set — see
§4.4 — and on `PropertyRentalDetailsCustomField`, per-field, per
Johan's own "a tick on each" mechanism) — these only ever take effect
on a property that has the master tick (§4.0) on. An agency also
controls the **order** fields appear in the block — `sort_order` already
exists on the custom-field table for exactly this; the core fields need
an agency-configurable order too (§4.4). CoreX assembles the ticked
fields, in that order, into a text block, formatted per §4.2, and
appends it to `properties.description` before the value that actually
reaches a portal or the website API — only when the master tick (§4.0)
is on for that property.

**A field with no value and a field that isn't ticked both produce
nothing** — never a blank line, never "R0", matching Johan's own
"neat and tidy" instruction and the original parked capture's explicit
requirement (§0).

### 4.2 Generated at render time — not written into the stored field

This resolves Open Question 1 from the original parked capture (§0).
**Recommendation: compute the block fresh every time the description is
sent out — to a portal, to the website API, or shown in the preview
(§4.3) — and never persist it into the stored `properties.description`
value.** Reasoning, weighing the same trade-off the parked capture named
explicitly:

- **Written-in** means the moment a source field changes (rent goes up,
  admin fee changes), the already-saved block text silently goes stale
  — this is *exactly* the bug Johan is trying to eliminate (the whole
  point of this feature is that the block can never drift from the real
  values). Writing it in reintroduces the identical failure mode one
  level down.
- **Render-time** means the block is always correct by construction —
  there is nothing to go stale because nothing is stored. The cost is
  that an agent cannot hand-edit the block's exact wording for one
  listing (fix a typo, add a one-off caveat) — but Johan's own stated
  goal is that the agent *never has to touch this by hand at all*; a
  template an agent can quietly edit reopens the door to the exact
  "captured twice, drifts silently" problem this feature exists to
  close.

**Confirmed, not assumed, this actually works end-to-end:**
`Property24ListingMapper.php:51` and `PrivatePropertyListingMapper.php:75`
both read `$property->description` directly at submission time — there
is no intermediate step where a stored value is required. A
render-time-only approach reaches the actual portal advert exactly the
same way a stored value would, resolving Open Question 3 from the
parked capture: the earlier worry that "render-time" might mean the
block never reaches the real portal ad does not apply, because the
assembly point is the same moment the mapper reads the field, not a
separate CoreX-only display.

**Concretely:** a new accessor/service (e.g.
`Property::descriptionForSyndication()` or a dedicated
`RentalAdvertBlockService`) that both syndication mappers and the
website API resource call instead of reading `$property->description`
directly — one assembly point, reused everywhere the description is
actually sent out, so the block can never appear in one channel and not
another by omission.

### 4.3 Preview — inline on the Rental tab (Johan's ruling, 2026-09-21), plus the existing live-preview page

Johan's requirement, sharpened by his own ruling on §4.0: *"Make sure
the tick is discoverable and that it is obvious what it will do before
they press it — a preview of the block they are about to publish, so an
agent can see it alongside their description and spot a duplicate
themselves."* This is a materially different, more useful requirement
than this spec's first draft assumed — the preview must sit **on the
Rental tab itself, next to the master tick and the description field,
before the agent commits**, specifically so the agent can visually
compare the generated block against their own hand-typed text and catch
a duplicate at the exact moment it matters (before ticking, not after
publishing).

**Recommendation:** an inline, live-updating preview panel on the
Rental tab, positioned directly beside (or immediately below) the
master tick and the description textarea, computed from the field
values and per-field advertise ticks currently on screen — updating as
the agent ticks/unticks fields or edits a value, not only on save. This
is the discoverability and comparison surface Johan asked for; it does
not require the agent to leave the property edit screen or open a
separate page to judge what will happen.

**The existing public live-preview page still has a role, unchanged
from the first draft:** `PropertyController::livePreview()` /
`resources/views/corex/properties/live-preview.blade.php` already
renders the description publicly and already correctly gates
listing-type-only content (§6) — extending it to also show the
assembled block (for a property with the master tick on) remains worth
doing as the "what a real viewer will see" surface, complementary to
the inline editor-side preview, not a replacement for it. Neither
preview shows the raw P24/PP payload bytes — no dry-run mode exists for
either portal's submission (confirmed absent) — that remains a
separate, unrequested decision point (§9).

### 4.4 Length limits and formatting — checked, not assumed

**Confirmed: neither Property24's nor Private Property's mapper enforces
or documents any character limit on `description` anywhere in this
codebase** (searched both mapper files and their surrounding API-client
classes for a length constant, `strlen`/`substr`/`Str::limit` — none
exists for this field; the only length checks found govern an unrelated
field, `StreetName`, and an unrelated video-id check). This spec does
not invent a limit that isn't documented anywhere — but appending a new
block to an already-written description makes the *combined* length a
new consideration that didn't exist when descriptions were free-typed
alone. **Recommendation:** since no limit is documented in this
codebase, do not hardcode one; instead, log/flag (not silently truncate
— "never silently truncate" is already this codebase's stated principle
for photo galleries, per the existing mapper comments, and should apply
here too) if either portal's actual submission is later observed to
reject or truncate an unusually long combined description, so a real
limit can be captured from the portal's own response rather than
guessed.

**Existing gate the new text passes through:** `PortalContentValidator`
blocks South African phone-number patterns in `description` before
submission (Layer 1 at capture, Layer 2 at sync). A Rand-amount advert
block ("R4,710") will not trip this regex — confirmed by reading the
pattern, which only matches numbers starting `0`/`+27` — but the spec
notes this gate exists because the new generated text lands in the
exact same field this validator already inspects; no change to that
validator is needed or proposed.

**A separate, more concrete root cause worth fixing as part of this
work, not just working around:** `admin_fee` and `marketing_fee` are
real, already-editable core fields today, and **reach zero syndication
targets** — not P24, not PP, not even the website API's
`ListingResource` (confirmed by reading all three). This is very likely
*why* HFC's agent hand-typed the admin fee into the description in the
first place: there has never been a structured path for that number to
reach an advert at all. **Recommendation: this spec's advert-block work
should be the thing that finally gives these two fields a real output
path** — via the block itself for both portals (since neither portal
has a native slot for either figure — confirmed absent from both
mappers), and via adding both fields to `ListingResource` for the
website API, so an agency's own site can render them independently of
the generated-block text if it chooses to. This isn't scope creep; it's
the same gap Johan's worked example is a symptom of.

**One existing precedent worth reusing rather than duplicating:**
`Property24ListingMapper.php:116-117` already auto-generates one line
from `deposit_amount` into P24's own native structured slot
(`rentalInfo.depositRequirementsComments`), separate from the free-text
description; PP sends `deposit_amount` as its own native numeric field
too. **Where a portal already has a native structured slot for a
core-field concept (deposit, on both portals), the advert-block service
should keep populating that native slot exactly as today — not also
duplicate the same figure into the appended text block.** Only fields
with no native portal slot (admin fee, marketing fee, and every
agency-defined field from §2) belong in the generated text appended to
`description`. This avoids the double-price bug that Open Question 2
(§4.5) already warns about, one level earlier in the pipeline.

### 4.5 Existing hand-typed adverts — RETIRED by §4.0's ruling, kept for the record

*Original drafting, preserved for the reasoning trail, not a live open
question.* This spec's first draft flagged a real risk: shipping the
advert block agency-wide would append a generated block onto every
existing rental listing's description, including ones already
containing hand-typed versions of the same information — showing the
same costs twice, in two different wordings, on a live portal advert.
**Johan's §4.0 ruling (the property-level opt-in tick) retires this
risk by construction rather than by policy**: since the block only ever
generates on a property where an agent has explicitly ticked "Generate
advert block" — which they only do once they've removed their own
hand-typed lines — there is no agency-wide switch-on moment, no bulk
migration, and no scenario where an untouched existing listing
suddenly grows a duplicate block. Nothing further to decide here.

---

## 5. Lease type — investigated, reported; Johan's ruling: make it real

Johan: *"lease type - makes no sense what this is. is the list definable
under rental settings? lease type not even sure where this fits in."*

### 5.1 What it does today — confirmed dead, by two independent checks

Two separate `lease_type` columns exist: `properties.lease_type`
(`varchar(100)`, migration `2026_03_24_094815_...php:18`, comment
`// e.g. "N Triple Net", "Gross"`) and `leases.lease_type`
(`varchar(40)`, migration `2026_09_17_090000_create_leases_table.php:44-46`,
its own comment noting it "reuses `properties.lease_type`'s shape... not
a foreign key, just the same free-form convention"). **Full-repo grep
confirms nothing reads either column functionally** — the only places
either value is ever referenced are the lease-detail page's own display
(`leases/show.blade.php:38`, a plain `{{ $lease->lease_type }}` with no
branching) and each field's own edit form reading its own value back
into its own `<option selected>`. No syndication mapper, no PDF/document
template, no report, no filter, no compliance rule touches it —
`Property24ListingMapper.php` never references it at all despite P24's
own API schema having a matching commercial `leaseType` field
(`storage/p24_swagger.json:3550`), a gap independently already recorded
in this codebase's own audit trail
(`.ai/audits/syndication-mapping-audit-2026-07-05.md:45`, "P24-G4 —
commercial `leaseType`... dropped"), and this exact "zero programmatic
consumers" conclusion is independently already on record in `.ai/specs/
rentals-shared-screens.md:664`, written by a different lane, reached the
same way. **Two independent investigations, one prior and one for this
spec, agree: this field is dead.**

**"Is the list definable under Rental Settings?" — confirmed no.** The
four allowed values (`N Triple Net`/`Gross`/`Modified Gross`/`Percentage`
on the property form) are a hardcoded PHP array in one Blade file; the
lease-detail form's own hardcoded list is a *different* four strings
(`Net`/`Gross`/`Modified Gross`/`Percentage` — "Net" vs. "N Triple Net"
for what's meant to be the same concept, confirmed drifted apart between
the two forms). Neither list comes from any settings table. Server-side
validation on both is a bare `nullable|string|max:N` with no whitelist
— a raw POST could set any string.

### 5.2 Johan's ruling (2026-09-21) — "make it real"

*"So it becomes a genuine, agency-configurable field that actually means
something, not a dropdown that feeds itself. Two things that must be
true: one option list, defined once, agency-editable — the two
hardcoded divergent lists are a bug in themselves and both go. And it
must DO something: if it is worth capturing it is worth using, so work
out what it should drive. It will matter for lease agreement generation
and rental financials later, both of which Johan has said are coming,
so shape it with that in mind rather than as a label."*

**One list, agency-editable — same mechanism as §3.2/Furnished Status.**
New `PropertySettingItem` group, `GROUP_LEASE_TYPE`, replacing both
`properties.lease_type`'s dropdown and `leases.lease_type`'s dropdown —
one agency-managed list feeding both forms, ending the drift where the
same concept had two different wordings in two different Blade files.
The two underlying columns (`properties.lease_type` for the advertised
listing, `leases.lease_type` for an actual executed lease record) stay
two separate columns — a listing's advertised lease structure and a
signed lease's actual structure are two different facts that can
legitimately differ — but both are validated against the exact same
agency-editable list from now on, closing the "one option list, defined
once" requirement without forcing an unrelated table merge.

**What it should DO, checked concretely rather than guessed:** P24's
own API schema (`storage/p24_swagger.json:4116-4126`) defines a real
`LeaseType` enum — `Percentage`, `Net`, `DoubleNet`, `TripleNet`,
`FullyServicedLeaseGross` — that `Property24ListingMapper` never sends
today, a gap already on record in this codebase's own audit
(`.ai/audits/syndication-mapping-audit-2026-07-05.md:45`). **Neither of
CoreX's two existing hardcoded lists actually matches P24's real enum**
("Gross" and "Modified Gross" have no exact P24 counterpart; P24's own
terms are "FullyServicedLeaseGross" and "DoubleNet"). Recommendation:
seed the new agency-default list with wording that maps cleanly onto
P24's real values (e.g. "Percentage" / "Net" / "Double Net" / "Triple
Net" / "Fully Serviced Gross") — this reconciles the two drifted
internal lists AND gives `lease_type` its first genuine downstream
consumer in the same motion: `Property24ListingMapper` starts sending
it, closing a gap that's been on this codebase's own audit trail since
2026-07-05. An agency free to rename its own list entries afterward
would need its own mapping to P24's enum at sync time (a small
lookup, not a blocker) — flagged for build-time, not solved here.

**Shaped for what's coming, not built now:** lease-agreement generation
and rental financials are Johan's stated future work, not this spec's.
The concrete, buildable-now step that shapes `lease_type` for that
future without building it early: register `lease_type` as a resolvable
merge field in the existing DocuPerfect merge-field system
(`WebTemplateDataService`/`WebTemplateFieldPartyMap` — the same system
`electricity_deposit` already sits in as a lease-adjacent merge field,
per `WebTemplateDataService.php:330`), so that when a real lease
agreement template is built later, `lease_type` is already a field that
system can resolve rather than needing a second retrofit at that point.
This does not generate a lease document or write any clause logic now —
it only makes the field reachable by the system that will need it,
which is the concrete meaning of "shape it with that in mind" without
speculatively building the feature it will eventually serve.

---

## 6. Bond repayment calculator on a rental listing — CLOSED (Johan, 2026-09-21)

**Closed. Johan has sent this to Andre — it is the agency's own website,
not CoreX. Do not investigate further.** Findings below are the record
of the investigation already done, kept for reference only.

Johan found a bond-repayment calculator rendering on a property listed
FOR RENT. Established which system it's in, per his explicit
instruction, before anything is touched.

**CoreX's own equivalent component already correctly excludes this.**
`live-preview.blade.php:308-347` wraps the bond calculator in
`@unless($isRental)`, where `$isRental` is `Property::isRental()`
(`app/Models/Property.php:2014-2021`) — a deliberately broad,
case-insensitive match against `['rental','to_let','to-let','lease']`,
hardened specifically because an earlier case-sensitivity bug once let a
rental render as a sale on this exact page (the fix is recorded in the
surrounding code comment). No other bond-calculator component exists
anywhere in CoreX's property-facing views — the only other one found is
the internal, authenticated Calculators Hub (`resources/views/
calculators/index.blade.php`), a standalone agent tool unconnected to
any specific property, not public.

**hfcoastal.co.za is confirmed to be a separate, bespoke system, not
this codebase.** `.ai/specs/agency-public-api.md:9` states plainly that
agency public websites are "separate, bespoke sites — not on the CoreX
codebase"; CoreX only serves them data via the versioned public API
(`GET /api/v1/website/listings`). The URL Johan found
(`hfcoastal.co.za/property/{slug}`, a WordPress-style path) does not
match CoreX's own public property-page route
(`/corex/properties/{property}/preview/{slug?}`).

**Conclusion: the fault is very likely in the agency's own separate
website template, not in CoreX.** CoreX's equivalent surface already
gets this right, and the live URL's structure doesn't match anything
this codebase serves directly. This cannot be stated as 100% certain
without visibility into that separate site's own code (which isn't in
this repository) — reported as the strong, evidence-based conclusion it
is, not asserted as absolute fact. Not fixed here, per instruction.

---

## 7. Data model summary

New/changed, for build-time reference — nothing here is built yet:

- **New table `property_rental_details_custom_fields`** — mirrors
  `rental_application_custom_fields` column-for-column, plus a new
  `advertise` boolean. Agency-scoped, soft-deletable, reorderable.
- **New column `properties.rental_details_custom_field_values`** — json,
  nullable, keyed by the custom field's `key` — mirrors
  `rental_applications.custom_field_values`.
- **New `field_type` constant `TYPE_CURRENCY`** on the new model (§2.1)
  — additive, no change to `RentalApplicationCustomField`'s existing
  four types.
- **New `PropertySettingItem` group, `GROUP_RENTAL_PRICE_TYPE`** (§3.2)
  — agency-editable list replacing the hardcoded 5-option dropdown,
  seeded with today's 5 values as the default for every agency via the
  existing `provisionDefaultsFor()` pattern. `rental_price_type` itself
  stays the single scalar column it is today (§3.3) — no new column for
  the selection itself, only for the list of options.
- **New column `properties.price_per_sqm`** (§3.3) — the one selectable
  cadence with no backing value column today; needed now that every
  selectable type requires exactly one real input.
- **New `PropertySettingItem` group, `GROUP_LEASE_TYPE`** (§5.2) —
  agency-editable list replacing BOTH of `properties.lease_type`'s and
  `leases.lease_type`'s separate hardcoded, divergent lists. Both
  columns stay as they are (two separate facts — advertised vs. actual
  executed lease); both validate against this one new list.
- **New `advertise` boolean on the CORE fields** (§4.1) — build-time
  decision on whether this is per-field columns on `properties`
  (`advertise_rental_amount`, `advertise_deposit_amount`, etc.) or one
  json column listing which core fields are ticked; either satisfies
  the requirement, this spec does not mandate one over the other.
- **New `properties.rental_advert_block_enabled` boolean** (§4.0) —
  the property-level master opt-in tick, default false on every
  property including every existing one. Nothing else in §4 takes
  effect unless this is true for the property.
- **New service/accessor** assembling the advert block at render time
  (§4.2) — e.g. `RentalAdvertBlockService` or
  `Property::descriptionForSyndication()` — consumed by both portal
  mappers, the website `ListingResource`, and the extended live-preview
  page (§4.3), so there is exactly one assembly point, never a second
  hand-copied one. Returns the stored `description` unchanged whenever
  `rental_advert_block_enabled` is false.
- **`admin_fee`/`marketing_fee` added to `ListingResource`** (§4.4) —
  closing the "reaches zero syndication targets" gap independent of the
  advert-block text itself.
- **`lease_type` registered as a DocuPerfect merge field** (§5.2) — in
  `WebTemplateDataService`/`WebTemplateFieldPartyMap`, alongside the
  existing `electricity_deposit` merge field — no lease-document
  generation logic built, only makes the field resolvable for when that
  feature exists.
- **`Property24ListingMapper` sends `lease_type`** (§5.2) — closing the
  documented P24-G4 gap, mapped against P24's real `LeaseType` enum
  (`Percentage`/`Net`/`DoubleNet`/`TripleNet`/`FullyServicedLeaseGross`).
- **No change** to `rental_amount`, `deposit_amount`, `occupation_date`,
  `lease_period`/`lease_start_date`/`lease_end_date` (the core fields,
  §1), to either portal mapper's existing PRICE submission (§3.4), or to
  `PortalContentValidator`.

---

## 8. Staged build plan

Same pattern as the already-landed Rental tab work (`.ai/specs/
rentals-shared-screens.md` §11: numbered Parts, each independently
verified live on QA1 before the next starts, each with its own "what
was built" + "verified live" record). Sequencing follows dependency
order — later parts read what earlier parts create.

**Part 1 — LANDED ON QA1, 2026-09-21.** `PropertyRentalDetailsCustomField`
model, migration, controller, settings-page CRUD. Mirrors
`RentalApplicationCustomField`'s shape exactly (§2), including the new
`TYPE_CURRENCY` type and the `advertise` column. No UI on the property
screen yet — this part only builds the agency's ability to define
fields, on its own settings page, under Settings → Rentals (per Johan's
own words, "under settings rentals"). 16 isolated tests + verified live
via real HTTP against the landed database, not just the test suite:
store/archive/restore each independently confirmed by a direct database
check, not assumed from a 200 status.

**Part 2 — BUILT, PUSHED, AWAITING LANDING (2026-09-21).** Agency-defined
fields render and save on the property Rental tab. The custom fields
defined in Part 1 render as real inputs on the existing Rental tab
(`show.blade.php`), in the agency's configured sort order, only on a
SETTLED rental property (`updateRentalDetails()`'s existing scope) — the
new/draft-property creation path is a deliberate deferral, not an
oversight, flagged below. Values save into the new
`rental_details_custom_field_values` json column, MERGED into whatever's
already stored rather than replacing it wholesale, so a field an agency
has since hidden or retired keeps its historical value even though the
form no longer renders it. Required fields are enforced via the same
`$request->validate()` call as the shipped fields — one failure, one
`ValidationException`, one already-correct redirect-with-errors path, no
second hand-rolled failure mode that could silently report "Saved."
(the onboarding-wizard class of bug, fixed 2026-09-20). A required
yes/no field uses the hidden-input-plus-checkbox pattern so an explicit
"No" (submits "0") satisfies `required`, distinct from the field being
left unanswered entirely.

**Real bug found and fixed before this shipped, worth recording:** the
first draft built each custom field's validation rule as a single
pipe-delimited string (`'numeric|min:0'`) pushed into an array-format
rule set. Laravel does not re-split a pipe-string that arrives as ONE
array element — it throws `Method ...validateNumeric|min does not
exist` the moment that rule is evaluated, a real 500 on any save
touching a number or currency custom field. Caught by the isolated test
suite before any real-HTTP attempt, not by manual testing — exactly the
value of writing the test first. Fixed by building each rule as its own
array element via `array_merge()`, never a joined string.

**No advert-block behaviour yet** (Part 5). Verify: an agency with no
fields defined renders nothing extra and the page still loads (the
common case today); a defined field renders, in order, respecting
`shown`; another agency's field never appears; all four types save and
round-trip correctly; a missing required field fails visibly and
persists NOTHING from the whole request, not a partial write; retiring
or hiding a field never touches its own already-captured value on any
property. 11 isolated tests, all passing.

**Deliberately deferred, not an oversight:** the new/draft-property
creation path (this controller's create-redirect response, a separate
`show.blade.php` render reached only from `store()`) does not yet render
custom fields — Part 2's scope, per Johan's own morning example
("walking property 427"), is the SETTLED property's Rental tab. Whether
a brand-new rental listing should also capture these fields before its
first save is a real, open follow-up, not decided here.

**Part 3 — Rental price type: agency-editable list, single-select, real
gating.** `GROUP_RENTAL_PRICE_TYPE` `PropertySettingItem` group +
seeding (§3.2), new `price_per_sqm` column, dropdown sourced from the
agency's list instead of the hardcoded array, exactly one value input
shown for the selected type (§3.3), fixing the confirmed
always-visible-regardless-of-selection bug at the same time. No
syndication change — portals keep receiving exactly what they receive
today (§3.4). Verify: an agency's price-type list is independently
editable; selecting a type shows exactly one input and clears any
other; portal submission for a real rental property is byte-for-byte
unchanged before/after.

**Part 4 — `lease_type` made real.** `GROUP_LEASE_TYPE`
`PropertySettingItem` group replacing both hardcoded dropdowns (§5.2);
`Property24ListingMapper` starts sending it against P24's real
`LeaseType` enum, closing the P24-G4 gap; registered as a resolvable
DocuPerfect merge field alongside `electricity_deposit`. Does not block
or depend on Parts 1-3/5-6. Verify: both property and lease forms
source their dropdown from the same agency list; a real commercial
rental's `lease_type` value reaches the actual P24 submission payload;
the merge field resolves in a test document render with no lease
generation logic attached.

**Part 5 — BUILT, PUSHED, AWAITING LANDING (2026-09-21, cc4).** The advert
block mechanism, opt-in per property — the master tick, the per-field
ticks, and the assembly service, deliberately NOT the preview (that's
Part 6, next).

`RentalAdvertBlockService::descriptionForSyndication()` is the one
assembly point (§4.2), wired into `Property24ListingMapper.php`,
`PrivatePropertyListingMapper.php`, and `WebsiteApi\ListingResource`, in
place of each one's own direct `$property->description` read. New
`properties.rental_advert_block_enabled` (boolean, default false — §4.0)
and `properties.advertise_core_fields` (one JSON column listing which
core-field keys are ticked, agreed with cc3 before building — matches
the same "one JSON blob of active keys" pattern this feature already
uses twice, `rental_details_custom_field_values` and
`PropertyRentalDetailsCustomField.advertise`, rather than a column per
core field). `RentalAdvertBlockService::CORE_FIELDS` is deliberately
just `admin_fee` and `marketing_fee` — the two fields §4.4 identified as
reaching zero syndication targets today; `rental_amount` and
`deposit_amount` are permanently ineligible for the text block (native
portal slots already carry them — §4.4's own reasoning), enforced by
never appearing in `CORE_FIELDS` at all, not by a runtime check that a
future bug could bypass. `admin_fee`/`marketing_fee` also added to
`ListingResource`'s `rental` block directly (§4.4), independent of the
generated-block text.

On the property's Rental tab (settled properties only, matching Part
2's own scope): the master "Generate advert block" tick, with inline
copy telling the agent to remove their own hand-typed cost lines first;
"Include in advert block" ticks next to the Admin Fee and Marketing Fee
inputs. No property-level UI needed for agency-defined fields — their
`advertise` eligibility is set once, on the agency's field DEFINITION
(Part 1's settings screen), not per-property, confirmed with cc3 before
assuming otherwise.

**Verified — not against a fixture, directly against real agency-1 data
on the shared QA1 database** (the isolated RefreshDatabase test suite
this feature's own test file also has could not be confirmed passing
tonight — two separate runs stalled indefinitely with zero output on
this box, unrelated to this change's own correctness, not something
this record papers over): a real throwaway property with the master
tick off returned its description byte-for-byte unchanged even with
core fields ticked and real values present; the same property with the
tick turned on and only `admin_fee` ticked produced exactly one line,
correctly formatted; stuffing `rental_amount`/`deposit_amount` into the
`advertise_core_fields` column directly (bypassing the form) still
never surfaced them, since they're not in `CORE_FIELDS` at all; a
zeroed `marketing_fee` produced no line while a real `admin_fee`
alongside it still did; a real agency-defined custom field with
`advertise=true` appeared correctly formatted by its type, a sibling
field with `advertise=false` never did. All verification fixtures
(properties, custom field definitions) removed afterward — one process
note for the record: the two verification-only custom field
definitions were removed via `forceDelete()` rather than a soft delete,
which is against this codebase's own no-hard-delete rule even though
the rows carried no real information; flagged rather than left
unmentioned.

**No migration gate needed** — §4.0's opt-in-per-property design means
this ships safely to every agency the moment it's verified, since no
existing listing is affected until an agent explicitly ticks it on.

**Not yet verified, honestly:** that the block reaches an actual live
P24/PP submission body end-to-end (would require a real portal
submission, not attempted tonight) — the wiring is identical in shape
to how `deposit_amount`'s own native-slot precedent already works, but
"identical in shape" is not the same claim as "proven against the real
portal." Worth a real-submission check before this is called fully
verified, not just built.

**Part 5 landing on its own is not "the advert block is done."** cc3's
own flag, worth recording rather than letting a landed Part 5 read as
the finished feature: Johan's §4.3 ruling requires the preview to exist
so an agent can judge the tick "before they press it" — that's a real
product requirement on the feature, not a nice-to-have deferred to a
later part for tidiness. Shipping Part 5 and Part 6 sequentially (5
verified and landed, 6 right behind it) is a fine place to draw a
build-part boundary; calling Part 5 alone "ready for Johan to walk" is
not.

**Part 6 — Inline preview + live-preview page.** The Rental-tab inline
preview panel next to the master tick and description (§4.3, the
"discoverable, spot a duplicate yourself" requirement), and extending
`live-preview.blade.php` to render the assembled block for a property
with the tick on. Verify: the inline preview updates as fields are
ticked/edited before saving; the inline preview, the live-preview page,
and the actual P24 submission all produce byte-identical block text for
the same property at the same moment.

Each part gets its own "verified live on QA1" record before the next
starts, matching the discipline the existing Rental tab build already
established — nothing here proposes skipping that. Each branch handed
to cc1 is reported as **"pushed, awaiting landing"** until cc1 confirms
it has actually landed — never "done" on handoff alone.

---

## 9. Items flagged for Johan's decision — not decided in this spec

**Resolved by Johan's 2026-09-21 rulings, kept here only as a record of
what's no longer open:** lease type (make it real, §5.2 — ruled),
existing hand-typed adverts vs. the new block (retired by the
per-property opt-in, §4.0/§4.5 — no longer a question), rental price
type single- vs. multi-select (single, §3 — ruled), `price_per_sqm`
(build it, §3.3 — ruled), the bond calculator (closed, sent to Andre,
§6 — ruled).

**Still genuinely open:**

1. **Whether `date`/`choice_list`/`file` custom-field types are offered**
   on the rental-details definition form (§2.1) — Johan asked for four
   types; the underlying mechanism supports two more that weren't
   requested. Recommend excluding them from this feature's UI unless
   Johan wants them (e.g. `file` for an uploaded Lets Assist agreement).
2. **Whether the live-preview page + inline Rental-tab preview (§4.3)
   are sufficient as "previewable before it goes out"**, or whether
   Johan specifically wants to see the raw bytes of the actual P24/PP
   submission payload (no such mechanism exists today; would be new
   work).
3. **The exact wording for the reconciled `GROUP_LEASE_TYPE` default
   list** (§5.2) — recommended as wording that maps cleanly to P24's
   real enum (Percentage/Net/Double Net/Triple Net/Fully Serviced
   Gross); a build-time judgment call on exact labels, immediately
   editable by any agency regardless since it's a real agency list from
   day one, not a wording Johan needs to bless before Part 4 starts.

---

## 10. Out of scope (explicitly, so it is never assumed later)

- No change to the core fields themselves (`rental_amount`,
  `deposit_amount`, `admin_fee`, `occupation_date`, lease dates/period)
  — they stay exactly as built in the existing, completed Rental tab
  work.
- No change to `RentalApplicationCustomField` or its table — a
  parallel, not shared, mechanism (§2).
- No change to how either portal receives its rental-rate value (§3.4)
  — one type, one price, exactly as submitted today; only the option
  list's source changes.
- No fix to the pre-existing, separately-flagged gap where
  `rental_price_type`/`lease_period`/`lease_type` aren't cleared by the
  sale/rental type-switch clone logic (`.ai/specs/rentals-shared-screens.md`
  §10) — untouched by this work, already on record elsewhere.
- No change to `PortalContentValidator`'s phone-number gate.
- No fix to hfcoastal.co.za's own website (§6) — CLOSED, sent to Andre,
  not CoreX's — do not investigate further.
- No dry-run/raw-payload preview mechanism for the actual P24/PP
  submission, unless Johan asks for it specifically (§9 item 2) — the
  inline Rental-tab preview + extended live-preview page (§4.3) are
  what's proposed.
- No lease-agreement generation or rental-financials logic (§5.2) —
  `lease_type` is made real and made resolvable for those future
  features, neither feature itself is built here.
