# Investigation — "Development" as a listing option for P24 and Private Property

**Date:** 2026-09-19
**Requested by:** Johan — *"for PP and P24 the development section I want to add that option to CoreX, so P24 has For Sale, For Rent and Development. At the moment we do not do development, so investigate the API etc we need to connect that up."*
**Status:** Investigation only. **No code was changed.** (Session opened on branch `Prod`; nothing was written to the app, no outbound portal calls were made.)

---

## 0. Headline

**"Development" is not a third listing type on either portal.** On both P24 and Private
Property, a development is a **container** — a named project with a developer, a suburb, a
price range and a hero image — and the things inside it are still ordinary **For Sale** or
**For Rent** listings that point *at* that container.

That matters because the obvious build ("add a third value to `listing_type`") is the wrong
shape three times over:

1. **P24's feed contract will not accept it.** Their `listingType` enum is a closed
   two-member list, `Sale | Rental`.
2. **PP's feed contract will not accept it.** Their `ListingType` enum is
   `Unknown | Sale | Rental | Both`, and their WSDL has no development concept at all.
3. **CoreX would silently mis-handle it.** `listing_type` is a hard binary across ~540
   references in ~133 files (plus 154 test files), and "sale" is expressed everywhere as
   *"not rental"* — so a third value would quietly inherit every sale behaviour rather than
   failing loudly.

The correct shape — **Development as a container, units as normal listings linked to it** —
is also the one that unlocks P24 immediately, because P24 already has a field for exactly
that link and CoreX already has the column to hold it.

---

## 1. Property24 — what the API actually supports

### 1.1 Source of truth

The full vendor contract is already in the repo: **`storage/p24_swagger.json`** — OpenAPI 3,
277,198 bytes, `info.title: "Listing Service v53"`, 33 paths, 73 schemas. This is the same
ExDev Listing Service v53 that CoreX syndicates to today
(`app/Services/Syndication/Property24/Property24ApiClient.php`, base path
`/listing/{apiVersion}` with `apiVersion = 'v53'`).

Two sibling files exist but are **0 bytes — empty**, so they carry no information:
`storage/p24_dev_swagger.json`, `storage/p24_location_swagger.json`.

### 1.2 `listingType` is a closed two-member enum

`components.schemas.ListingType`:

```json
{
  "enum": ["Sale", "Rental"],
  "type": "string",
  "description": "This enumeration type represents the options available for a listing.<p>Members:</p><ul><li><em>Sale</em></li><li><em>Rental</em></li></ul>"
}
```

There is no `Development` member. A third value cannot be sent on v53.

Note also `Property24ListingMapper::mapListingType()` ends `default => 'Sale'` — so a new
CoreX value would be **silently coerced to Sale**, not rejected.

### 1.3 How P24 *does* model a development — three affordances, none built in CoreX

| # | Affordance | Where | Direction |
|---|---|---|---|
| 1 | `Listing.developmentId` — *"ID of a development the listing is in, if applicable. Optional."* (`int32`, nullable) | On the listing payload we already POST | **We send it** |
| 2 | `GET /listing/v53/developments` → `DevelopmentIdentifier[]` — *"Fetches a list of development identifiers."* | `operationId: FetchDevelopments`, no parameters | **Read-only** |
| 3 | `Tag: NewDevelopment` — *"New Development. Type: property descriptive. Shown when associated with a listing: yes."* Applies to House, Apartment, Townhouse, Vacant Land, Farm, Commercial, Industrial. | The `tags` array we already send | **We send it** |

`DevelopmentIdentifier` is deliberately thin — it identifies, it does not define:

```json
{ "id": 1234, "isOnPortal": true, "name": "Fawlty Towers",
  "suburbName": "Rondebosch", "suburbId": 1234, "developerName": "Basil Developments" }
```

### 1.4 The decisive constraint: the feed cannot CREATE a development

Every write endpoint in the entire v53 contract:

```
POST /listing/v53/echo-compressed
POST /listing/v53/listings
PUT  /listing/v53/listings/{listingNumber}/status
PUT  /listing/v53/agencies
POST /listing/v53/agents
PUT  /listing/v53/agents
PUT  /listing/v53/agents/{agentId}/profile-picture
```

There is **no POST/PUT/PATCH/DELETE for developments**. The development record is created on
P24's side — by P24 / the developer's own P24 arrangement — and the feed can only (a) list
what exists and (b) attach listings to one by id.

This matches how the rest of the market works: Prop Data's P24 Developments integration
documentation instructs clients to *"indicate this to your P24 CRM"*, notes *"additional
charges may apply to your current P24 account"*, and says P24 sends *an export of your data
to map your listings to the P24 development listings*. So development creation and mapping
is an **account/commercial step with P24**, not an API call.

### 1.5 Good news — half the plumbing already exists inbound

`properties.development_id` **already exists**:

- `database/migrations/2026_07_17_200000_add_p24_import_completeness_fields_to_properties.php:26`
  — `$table->string('development_id', 64)->nullable()->after('lightstone_id');`
- Populated by the P24 CSV importer — `app/Services/Importer/P24ListingsCsvParser.php:151`
  — `'development_id' => trim((string)($raw['DevelopmentId'] ?? '')) ?: null,`
- Fillable at `app/Models/Property.php:643`; in the confirm path at
  `app/Jobs/ConfirmP24PropertyRowJob.php:97`.

But it is **never sent back out** — `developmentId` appears nowhere in the outbound mapper,
and `GET /developments` has no client method and no caller anywhere in `app/`, `routes/` or
`config/`. Likewise `NewDevelopment` is never emitted (absent from the mapper's `TAG_MAP` /
`FEATURE_TAG_MAP`). Prior audits already recorded both gaps as known-unmapped:
`.ai/audits/2026-06-26-p24-full-audit.md:68,94`,
`.ai/audits/2026-07-07-portal-data-gap-analysis.md:78,98`,
`.ai/audits/syndication-mapping-audit-2026-07-05.md:53`.

So for P24, the work is small and additive: read the development list, let an agent pick one,
store the id (column exists), send it on the payload, optionally add the `NewDevelopment` tag.
**No change to `listing_type` at all.**

---

## 2. Private Property — no development support in the feed we hold

### 2.1 Source of truth

**`storage/pp-agentimport.wsdl`** — 2,566 lines, the full PP AgentImport **Rev 4.6** contract
(service address `https://services.privateproperty.co.za/AgentImport/AgentImport.asmx`).

### 2.2 The `ListingType` enumeration, verbatim (`pp-agentimport.wsdl:16-23`)

```xml
<s:simpleType name="ListingType">
  <s:restriction base="s:string">
    <s:enumeration value="Unknown" />
    <s:enumeration value="Sale" />
    <s:enumeration value="Rental" />
    <s:enumeration value="Both" />
  </s:restriction>
</s:simpleType>
```

No `Development`. And because `PrivatePropertySoapClient` runs PHP's `SoapClient` in WSDL
mode, an out-of-enum string is rejected **client-side** before it ever leaves our box.

### 2.3 Nothing development-shaped hides in the adjacent enums either

- `Category` — `Residential | Land | Farms | Commercial`
- `PropertyStatus` — `ForSale | ToLet | PendingOffer | Sold | Inactive | Archived`
- `MandateType` — 33 values, all mandate/bank-programme flavours
- `AttributeType` — 70 values (`HomeType`, `BusinessType`, `FarmType`, `LandType`, amenities)

Searches for `develop*`, `scheme`, `project`, `phase` across the WSDL return **zero hits**.
None of the 43 WSDL operations is development-related.

### 2.4 What "scheme" means to PP — an address, not a listing class

PP treats a complex/scheme purely as an address qualifier (`ComplexName` + `UnitNumber`),
enforced by their error **PP60** — *"The address details are insufficient, please add a
Scheme/Complex name"* (`app/Services/PrivateProperty/PpFaultTranslator.php:11,24`;
`PrivatePropertyListingMapper.php:273-278`). That is not a development container.

### 2.5 PP does run a Developments product — just not through our feed

privateproperty.co.za/developments is live, with per-development pages showing name, area,
price range (e.g. *"R 999 900 - R 1 399 900"*), developer and hero image, and a **"List Your
Development"** call to action. It is a **developer-facing product**, loaded outside the
agency feed.

**Conclusion for PP: there is no API path today.** This cannot be built from anything we
hold — it requires Private Property to tell us whether developments can come through the
agency feed at all, and if so on what contract revision. (For reference, the documented Rev
4.6 → 4.7 change list — `.ai/investigations/pp-rev47-shim-investigation-2026-08-01.md:15-18`
— covers only `LeadType`, continuation keys and `ErrorDownloadingImages`. Nothing about
developments.)

---

## 3. CoreX side — why a third `listing_type` value is the wrong build

### 3.1 The current shape

- **Column:** `properties.listing_type`, plain nullable VARCHAR, case-insensitive collation.
  Created `2026_03_24_093448_add_listing_type_to_properties_table.php:12`; made nullable
  `2026_03_31_200000`; normalised onto the canon `2026_08_30_000007`.
- **Canon:** `app/Models/Property.php:1959` — `public const LISTING_TYPES = ['sale', 'rental'];`
  with the docblock *"the ONLY two values that may ever reach the column."*
- **Discriminator:** `Property::isRental()` (`:2014-2021`) is a **boolean**. There is no
  `isSale()`. Sale is the `else` / `default` arm everywhere.
- **Scale:** ~542 occurrences across ~133 non-test files, plus 154 test files.
- `tracked_properties` has **no** listing-type column — a tracked property gains one only on
  promotion.

### 3.2 The two structural blockers

**R1 — the Rentals module is a binary lens.** `routes/web.php:3974-4058` reuses the sales
screens with the type force-locked (`PropertyController.php:136-138` —
`if ($isRentalEntry) { $listingType = 'rental'; }`), plus a boolean session lens at `:78`
and boolean sidebar highlighting. `.ai/specs/rentals-shared-screens.md` §0 makes *"one
screen, one lens boolean"* a governing rule. A third value forces that boolean into an enum
across routes, controller, session keys, sidebar and the spec.

**R2 — a third value would fail silently, not loudly.** Because sale is the default arm, a
Development listing would inherit every sale behaviour without error:

| Behaviour | Site | What a Development would silently get |
|---|---|---|
| Price source | `Property.php:2031-2036`, `:2053-2056` | `price` (sale) |
| Status label | `Property.php:1700-1701` | `"For Sale"` |
| Portal deep links | `Property.php:2187`, `:2240` | `/for-sale/` slug |
| Contact pivot roles | `Property.php:1044-1064`, `:1118-1133` | seller / buyer |
| P24 wire value | `Property24ListingMapper.php:1684-1692` | `'Sale'` (`default =>`) |
| PP wire value | `PrivatePropertyListingMapper.php:745-749` | `'Sale'` |
| Deal type | `ContactMatchController.php:692` | `'sale'` |

### 3.3 Other things that would need changing

- ~13 inline validators `in:sale,rental` (no FormRequest classes for properties —
  validation is inline in `PropertyController` `:964`, `:1366`, `PropertyWizardController:167`, etc.)
- `price_bands.listing_type` is a **real DB enum** `['sale','rental']`
  (`2026_05_13_150005_create_price_bands_table.php:14`) — needs an ALTER.
- `document_types.listing_types` JSON drives the Drive folder set (`DocumentType.php:34-49`).
- The change-type action is a hard two-way toggle with no target picker —
  `PropertyController.php:1724` — `$targetType = $currentType === 'rental' ? 'sale' : 'rental';`
- Hardcoded sale-only reporting filters would make Developments invisible or miscounted:
  `SuburbReportDataService.php:290,589`; `DemandAnalysisService.php:195`;
  `MarketIntelligenceController.php:717`; `OpportunityPocketService.php:73`;
  `ProspectingIntelligenceService.php:63,103,385`.

### 3.4 There is no existing development concept to build on

Nothing in CoreX models a multi-unit scheme as a listing — a development today can only be
entered as N separate `Property` rows. The nearest existing structures are:

- `properties.title_type` enum `['full_title','sectional_title','vacant_land','other']` —
  an **orthogonal** axis (what kind of title), and the most likely place someone would
  mistakenly try to hang "Development".
- `complex_name` / `unit_number` — free-text address components, **no FK** grouping units
  under a parent.
- The sectional-scheme work in `app/Models/MarketReports/SchemeOwner.php` and the map route
  `corex.map.scheme-owner`, which carries the telling comment *"a scheme unit has no
  Property/TrackedProperty of its own yet"* — the codebase already names this gap.
- CoreX's own `category` vocabulary includes **`Project`**, which currently has no PP slot
  and degrades to `Residential` (`PrivatePropertyListingMapper.php:709`).

---

## 4. Recommended shape

**Do not add a third `listing_type`.** Model it the way both portals actually do:

```
Development  (the container: name, developer, suburb, price from/to, hero image,
              p24_development_id)
    └── Property (unit)   listing_type = sale | rental   +   development_id
```

Why this is the right call and not merely the cheap one:

- It is **what P24's contract already expects** — `Listing.developmentId` exists precisely
  to express "this listing is in that development."
- It **matches PP's product model** too, so if/when PP opens a development feed we map onto
  it rather than rebuilding.
- It **leaves the sale/rental binary untouched**, so none of §3's ~540 sites, the Rentals
  lens, the price bands enum or the 154 test files are disturbed.
- It is **honest to the business**: a development genuinely has units that are individually
  for sale or to let, often both. `listing_type` on the unit stays meaningful.

Phasing (smallest useful first):

1. **P24 link-up only.** Call `GET /developments`, cache the list, let an agent pick a
   development on a listing, store the id in the existing `properties.development_id`, send
   it as `developmentId` on the payload, optionally add the `NewDevelopment` tag. No schema
   change beyond what exists. Full CRUD/scoping standards apply to any new screen.
2. **Development as a CoreX entity** (name, developer, price from/to, hero image, units
   list) — only once Johan confirms HFC actually takes development stock.
3. **PP** — blocked on Private Property. Nothing buildable today.

Per BUILD_STANDARD/CLAUDE.md: **a spec must be written and approved before any of this is
built**, and it must state search fields, sort + default, filters and per-screen OWN /
BRANCH / AGENCY scoping up front.

---

## 5. Blockers and open questions

### Must be answered by the vendors (cannot be resolved from the repo)

**Property24 — ask our P24 account manager:**
1. Is the **Developments product enabled** on our P24 account, and what does it cost?
2. Who creates the development record — P24, or the developer's own P24 account? Can our
   agency profile be attached to a development we did not create?
3. Does our feed user have permission on `GET /listing/v53/developments`, and will
   `developmentId` be honoured on our POSTed listings? *(Worth asking explicitly — the
   `getAgent()` 401 recorded in `.ai/specs/p24-syndication.md` shows our credentials do not
   necessarily carry every read scope.)*
4. Is there a newer API version than v53 that adds development write support?

**Private Property — ask our PP account manager:**
5. Can developments be loaded through the **AgentImport agency feed** at all, on any contract
   revision? If yes, which, and is there a WSDL we can have?
6. If not, what is the route for an agency (not the developer) to load a development?

### Internal / operational

7. **This box points at LIVE P24** (`P24_EXDEV_API_URL=https://api.property24.com`,
   `P24_EXDEV_SANDBOX=false`) and the session opened on branch `Prod`. No probe of
   `GET /developments` was run. That probe should be done from a QA lane against the ExDev
   sandbox, not from here.
8. Unknown (not checked — would mean querying live data): how many existing properties
   already carry a non-null `development_id` from past P24 imports. Worth a read-only count
   before design, as it sizes the backfill.
9. `storage/p24_dev_swagger.json` is empty (0 bytes). If a P24 *Development service* swagger
   was ever meant to be captured, it never was — worth asking P24 whether such a service
   exists separately from Listing Service v53.

---

## 6. Evidence index

| Claim | Evidence |
|---|---|
| P24 `listingType` = Sale\|Rental only | `storage/p24_swagger.json` → `components.schemas.ListingType` |
| P24 cannot create developments | full write-endpoint list, §1.4, from the same file |
| P24 development affordances | `Listing.developmentId`; `GET /listing/v53/developments`; `Tag.NewDevelopment` |
| CoreX never sends any of the three | no `developmentId` / `developments` / `NewDevelopment` in `app/`, `routes/`, `config/` |
| `properties.development_id` exists, inbound only | migration `2026_07_17_200000:26`; `P24ListingsCsvParser.php:151`; `Property.php:643` |
| PP has no development concept | `storage/pp-agentimport.wsdl:16-23` + zero `develop*`/`scheme`/`project`/`phase` hits |
| PP wire values in production | `storage/logs/private_property-*.log`, 2026-08-22 → 2026-09-19: 2,973 × `"ListingType":"Sale"`, 246 × `"ListingType":"Rental"`, nothing else |
| CoreX canon is two values | `app/Models/Property.php:1959` |
| Sale is the default arm | `app/Models/Property.php:2014-2021` (`isRental()`, no `isSale()`) |
| Rentals module is a binary lens | `routes/web.php:3974-4058`; `PropertyController.php:136-138` |
| Change-type is a two-way toggle | `PropertyController.php:1724` |
| P24 developments is a commercial step | Prop Data P24 Developments feed documentation |
