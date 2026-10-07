# Structured address matching

**Status:** Step 1 (this spec) — see §14 for what has landed. QA1 only. Nothing goes to Staging or live without Johan's order.
**Pillars:** Property (`properties`), Contact is untouched, Deal untouched, Agent: the extension's pre-check, the Deeds screen and the review list are agent/admin surfaces over the Property pillar and Tracked Properties (MIC).
**Origin:** design `/tmp/qa1-cc4-matching-design-2026-10-07.md` (Part 2 of the web-extension / deeds-capture matching work) and the conductor's go of 2026-10-07 with five decisions (§2).
**Related specs:** `deeds-capture.md` §9.10–9.11 (Part 1 guards this build absorbs), `mic-complete-spec.md` §3.2.1 (address history), `other-agency-stock.md`, `multi-tenancy.md`.

---

## 1. What this is and why

CoreX decides "is this the same property?" in more than ten separate places, each parsing loose text its own way, and
compares the suburb as a string. On QA1 / Staging that produces: 186 / 322 captures not linked to a property (32 / 59 of
them clearly already exist), 25 / 30 promoted captures that disagree with their property on number, street or suburb,
15 / 19 records that swallowed several addresses, 17 / 39 captures sitting beside a second property with the same address.
Our own properties are not clean either: only 33% have the street number in its own column.

This build gives every address ONE layout and ONE reader, and every "same property?" question ONE scorer with three answers:

| Tier | Meaning | Agent sees |
|---|---|---|
| **EXACT — same property** | link / "Already in CoreX" | extension: "Already in CoreX — matched on …" |
| **POSSIBLE — agent confirms** | some identifying columns agree, none contradict | shown side by side; agent decides |
| **STREET-ONLY — other property on this street** | same street + suburb, number differs or missing | information, never "same" |

A different street number, unit/section, or erf(+portion) **always** blocks "same". A wrong auto-link is worse than a duplicate.

## 2. Decisions (from the conductor's brief, 2026-10-07 — relayed, not Johan's verbatim)

1. **Neighbouring suburbs that share streets** (Uvongo / Uvongo Beach): "possible match — agent confirms", never automatically the same.
2. **Promote finds only a possible existing property:** ask the agent, showing both side by side. Never silently create a second property.
3. **Duplicate property records** (one physical property on 2+ records): NOT in this build. Johan will later want an admin "show duplicates and merge" tool;
   this build must leave the structured columns and the match output (§6.4) easy for that tool to reuse, and must create no new duplicates.
4. **The "could not read this address" review list:** admins only (agency admin scope), with search / sort / filter per the design standard; nothing deleted.
5. **Match strictness thresholds:** system settings with sensible defaults, admin-only, **not in the Setup Wizard** (this is Johan's call for non-negotiable #10a — recorded in `agency-onboarding-setup.md` "Deliberately NOT in the wizard").

Also: keep the CMA **LPI code** (erf, portion, township) and match on **erf + portion first**. The backfill runs on QA1 only, dry-run first with counts,
**fills only empty columns, overwrites nothing**. If a step would change what an agent sees in the extension beyond the three tiers above, stop and ask.

## 3. Data model

Nothing existing is changed or dropped. Every new column is nullable. **No existing column is ever overwritten by the backfill or by a writer** — the structured
layout lives in NEW columns beside the raw ones; the only existing columns the layer fills are the ones that are EMPTY (`street_number`, `unit_number`, `complex_name`).

| Column | `properties` | `tracked_properties` | `tracked_property_addresses` | Meaning |
|---|---|---|---|---|
| `p24_suburb_id` | exists | **new** (+ `p24_city_id`) | **new** | the suburb as the Property24 suburb (FK-style, indexed) |
| `street_core` | **new** | **new** | **new** | street name without number, type, unit prefix or pollution, normalised ("grindewald") |
| `street_type` | **new** | **new** | **new** | canonical type: street, road, avenue, drive, close, … (English + Afrikaans: straat, laan, weg, …) |
| `scheme_number` | **new** | exists | — | registered sectional scheme (SS) number |
| `erf_portion` | exists | **new** | — | stand portion ("0", "1", …) |
| `township` | **new** | **new** | — | township code from the LPI (4 chars) |
| `lpi_code` | **new** | **new** | — | the full 21-character Surveyor-General code |
| `address_raw` | **new** | **new** | **new** | the address text as received/held before parsing — audit only, set once |
| `address_parse_status` | **new** (idx) | **new** (idx) | — | `parsed` · `review` · `unparseable` · `manual` · `dismissed` |
| `address_parse_note` | **new** | **new** | — | short reason when not `parsed` |

Existing columns used as inputs: `street_number`, `street_name` (raw — may still carry number/type/"Cadastral Extent … M²"), `unit_number`, `complex_name`,
`suburb`, `suburb_normalised`, `erf_number`, `scheme_name`, `section_number`.

Indexes: `(agency_id, p24_suburb_id, street_core, street_number)` on all three tables; `(agency_id, erf_number, erf_portion)` on `tracked_properties`;
`(agency_id, address_parse_status)` on `properties` and `tracked_properties`; `(agency_id, lpi_code)` on both.

**`suburb_aliases`** (global reference data, no `agency_id`): `alias_normalised` (unique), `canonical_normalised`, `p24_suburb_id` (nullable), `note`, `source`.
Seeded by the migration from `config/property-suburb-aliases.php` (Leisure Bay = Three Hills) and registered in `deploy:sync-reference-data` so it travels with a deploy.
Aliases are evidence-seeded, never guessed: a Cape Town agency's suburbs are not blocked on a KZN file.

**`address_match_settings`** (per agency): `agency_id` (unique) + the five settings in §9. No row = the defaults.

## 4. One reader — `App\Services\Address\AddressParser`

Pure (no DB), idempotent: the same input always yields the same output. Inputs (all optional): `address`, `street_number`, `street_name`, `unit_number`,
`complex_name`, `suburb`, `situated_at`, `lpi_code`, `erf_number`, `erf_portion`, `scheme_number`, `scheme_name`, `section_number`, `cma_street_number`, `estate`.

1. **LPI** (`App\Services\Address\LpiCode`): 21 characters = registration division (4) · township (4) · erf (8) · portion (5). `n0et03630000132900000` ⇒ division `n0et`, township `0363`,
   erf `1329`, portion `0`. An unreadable code is ignored, never guessed. An LPI erf that disagrees with a stored erf is a `review` reason.
2. **Street text** — from `street_name` and/or `address`: any "Cadastral Extent … M²" tail is cut; segments split on newline / comma / double space; the segment that carries a leading number
   and a street word is the street line; "Unit 5" / "Flat 12" / "Door 3" lift into `unit_number`; any other earlier segment is the complex. The street number is the explicit
   `street_number` when valid, else the leading "12", "12A", "1/3", "12-14" of the street line; **two different numbers found → `review`, never a pick.**
3. **Street name** — number and unit prefix removed, ordinals normalised ("First" = "1st"), apostrophes dropped, abbreviations expanded, the trailing type moved to `street_type`
   (`StreetTypes`, one table, English + Afrikaans), the remainder is `street_core`. No leading number = a street name only; no number is ever invented.
4. **Suburb** — `SuburbResolver`: normalised spelling (apostrophes, Saint/St, "-on-sea") → `suburb_aliases` → `P24Suburb::lookup` (province, then nearest centroid ≤ 25 km). **Not resolvable ⇒ `review`, never guessed.**
5. **Status** — `parsed` (an identity is recoverable and nothing conflicts), `review` (found something but the suburb is unresolved, or two sources disagree, or the record's history names several addresses),
   `unparseable` (no street, no scheme and no erf). A street with no number is `parsed` (vacant land and estates are real).

## 5. Where the structured columns are written

- **Writers (step 4):** `Property::saving`, `TrackedProperty::creating/updating`, `TrackedPropertyAddress::creating`, `TrackedPropertyMatchOrCreateService::canonicalFactsForWrite`
  and the CMA report parsers' `makeAddress` all call the one parser. Derived columns are recomputed when an address source column changes; the existing empty columns are filled; nothing existing is overwritten.
  A parser failure is logged and absorbed — it never blocks a save.
- **Backfill (step 7):** `address:backfill-structured --dry-run` first. Per agency, chunked and resumable; parses what is already held; fills **only empty** columns; copies the old text to `address_raw`; sets the status.
  Refuses to run outside QA/local/testing. The dry run prints counts per status and per reason.

## 6. One scorer — `App\Services\Address\AddressMatcher`

### 6.1 Facts
`AddressFacts` is built from a Property, a TrackedProperty, a TrackedPropertyAddress or a capture payload. Rows not yet backfilled are parsed on the fly, so the scorer is correct before and after the backfill.

### 6.2 Per-column verdicts
`agree` · `differ` · `missing` (either side blank — never a mismatch) · `neighbour` (suburb only: the two are Property24 neighbours) · `compatible` (street type when either side has none).
Columns: **lpi**, **erf(+portion)**, **scheme**, **unit/section**, **suburb**, **street**, **number**, **street type**, **gps ≤ radius** (corroboration only — many rows in one suburb share a pin).

### 6.3 Tiers
- **EXACT:** same LPI · OR erf + portion (both given and equal, or both absent) + same suburb · OR scheme (number, else name) + unit/section + same suburb · OR street number + street + same suburb with no unit conflict and compatible street types.
- **POSSIBLE** (no veto, ≥ `possible_min_agreeing_columns` identifying groups agree, and an *anchor* agrees — erf, scheme+unit, or number+street): number + street in a **neighbouring** suburb; number + street + suburb with the street **type** differing on both sides; scheme + unit with the suburb differing; erf agreeing with an unknown portion; a unit on one side only.
- **STREET-ONLY:** street + suburb agree, number differs or is missing.
- **VETO** (caps at "different property"): street number, unit/section, erf, portion, or LPI, populated on both sides and different — or a suburb on both sides that is neither the same nor a neighbour.

### 6.4 Output (what the later merge tool reuses)
`['tier' => exact|possible|street_only|none, 'score' => 0..100, 'columns' => [name => verdict], 'veto' => [names], 'reasons' => [plain sentences], 'matched_on' => [names]]`.
The score is a ranking aid only (weights: lpi/erf 40, scheme 25, unit 15, suburb 15 / neighbour 7, street 15, number 15, type 3, gps 5); the tier is the decision.

### 6.5 Consumers, moved one at a time (each with its own test file)
`resolveMatch` (tracked ingest + pre-check), `findExistingStock`, `resolvePropertyMatch` (promote), `PropertyDuplicateMatchEvidence`, `DeedsCaptureLinkService`,
`ContactAddressPropertyGuard`, `MapPinService`, `MicPropertyReconciliationService`, `PropertyCmaPropagationService`, `ProspectingStockMatchService`.
Candidate retrieval is by indexed sets (agency + suburb + street, agency + erf, agency + scheme) — no `limit(50)`, no unordered `first()`. Existing method names and return shapes stay; labels the Deeds screen and tests rely on (`3_erf_suburb`, `4_normalised_address`, …) stay.

## 7. What the agent sees (the three tiers — nothing more)

- **Extension pre-check:** EXACT ⇒ "Already in CoreX — … Matched on: …" (Open in CoreX / Pull anyway / Cancel); POSSIBLE ⇒ "Possible match — is this the same property?" with the candidate and a tick/cross per column
  (Yes, same — open it / No, different — continue the capture / Cancel; the decision is logged); STREET-ONLY ⇒ the grey "Other property on this street" note after capture. Response stays backward compatible: old extension builds keep working.
- **Promote (decision 2):** EXACT ⇒ links as today. **POSSIBLE only ⇒ promote stops and asks**: both records side by side, "Same property — link to it" / "Different property — create a new one"; nothing is created or linked until the agent chooses. No candidate ⇒ creates, as today.

## 8. Backfill report
Before/after counts per status; per reason (suburb unresolved / numbers disagree / several addresses / no street); the §2 queries of the design re-run; a sample for an admin to eyeball.

## 9. Settings (admin-only, defaults, NOT in the Setup Wizard)

Page `/corex/settings/prospecting/address-matching` (inside the Prospecting Setup group, permission `prospecting_setup.manage`, agency admin). Stored in `address_match_settings`.

| Setting | Default | Range |
|---|---|---|
| `rule_erf_exact` / `rule_scheme_exact` / `rule_street_exact` — each EXACT rule on/off | on | bool |
| `possible_min_agreeing_columns` | 2 | 2–4 |
| `neighbour_suburb_credit` | `possible` | `possible` · `ignore` (never "same") |
| `gps_radius_m` | 25 | 5–100 |
| `unit_missing_on_one_side` | `possible` | `possible` · `different` |

**Deliberately NOT in the wizard** (Johan's decision, recorded for non-negotiable #10a): these are expert strictness knobs with safe defaults.

## 10. The "could not read this address" review list

Screen `/corex/address-review` — **agency admins only** (permission `address_review.manage`, role default admin; scoping = the agency, enforced at the query layer, direct-URL access by id blocked).
Rows: tracked properties and properties whose `address_parse_status` is `review` or `unparseable` (one row per record, kind shown).
- **Search:** street name, street number, suburb, complex, erf, record id. **Sort:** record kind, address, suburb, status, reason, last updated (default: newest-updated first, then id). **Filter:** status, reason, kind, date range (updated). **Pagination:** 25. **Empty states:** "Nothing needs a look — every address was read" vs "No rows match these filters".
- **Actions:** open the record; **Fix** (edit number / street / unit / complex / suburb on the structured form → re-parsed, status `manual`); **Dismiss** (status `dismissed`, with **Restore** back to review). No create (rows derive from data), **no delete anywhere** — a merged record is flagged, never split or removed.
- Navigation entry in the same build (Admin).

## 11. Extension 3.9.0
Payload gains `lpi_code`, `situated_at`, `cma_street_number`, `estate` (read by label, already on the page). Banners per §7. The server keeps parsing raw fields, so an agent on an older build is unaffected. Version bump, rebuilt zip, node harness checks.

## 12. Test matrix (real-shaped fixtures, no live scraping)
Grindewald 19 / 29 / 21 (number veto, type missing "Grindewald" = "Grindewald Drive", "Grindewald Road" = possible); sectional scheme + unit (unit 5 vs 7 never same; scheme names repeating across towns); freehold erf + portion
(1166 Lynne Avenue six portions; LPI `n0et03630000132900000`); Uvongo / Uvongo Beach (possible, never same); Saint/St Michaels On Sea (same); the 373 pollution line ("4 Garden Place   Cadastral Extent  1 605 M²"); "61 Colin Street, Uvongo Beach" ×6;
Staging TP27 San Miguel 71 Leisure Crest ↔ Glenmore (possible); numbers lifted from the street name; Afrikaans street types and a non-KZN suburb; each optional field missing; idempotent re-parse; a soft-deleted candidate ignored.

## 13. Deliberately NOT in this build
Merging duplicate property records (decision 3); the Pull-as-my-own-listing flow; presentations/comps `SuburbMatcher` word-stripping beyond what step 6 lists; Staging/live rollout.

## 14. Steps and status

| # | Step | Status |
|---|---|---|
| 1 | Spec | landed |
| 2 | Migrations + `suburb_aliases` + `address_match_settings` + `deploy:sync-reference-data` (SuburbAliasSeeder) + schema snapshot | landed (QA1) |
| 3 | `AddressParser` + `StreetTypes` + `SuburbResolver` + `LpiCode` + `AddressStructurer`, unit-tested | landed (QA1) |
| 4 | Writers wired: `Property::saving`, `TrackedProperty::creating/updating`, `TrackedPropertyAddress::creating`, `canonicalFactsForWrite` (+ `lpi_code`, `erf_portion`), CMA report parsers' `makeAddress`, the capture endpoints (LPI from the property block or `source_ref`) | landed (QA1) |
| 5 | Scorer (`AddressFacts`, `AddressMatchScorer`, `AddressMatcher`); `resolveMatch` strategies 3 / 3b / 4, `findExistingStock`, `findSameStreetOthers`, `resolvePropertyMatch`, the Deeds evidence panel, the pre-check response (additive `tier` / `matched_on` / `columns`, possible tracked matches); promote asks on a possible match (Deeds screen and MIC) | landed (QA1) |
| 6 | Other consumers: `DeedsCaptureLinkService`, `ContactAddressPropertyGuard`, `MapPinService` fold, `MicPropertyReconciliationService`, `PropertyCmaPropagationService`, `ProspectingStockMatchService` pass 2 — each with its own test file | landed (QA1) |
| 7 | Backfill command `address:backfill-structured` (dry run first; QA/local/testing only) + QA1 dry run and run | landed and run on QA1 (counts in the build report) |
| 8 | Review list + settings page (navigation, permission, CRUD standard) | landed (QA1) |
| 9 | Extension 3.9.0 | landed (QA1) |
| 10 | QA1 walkthrough for Johan + final report | done — `/tmp/qa1-cc4-matching-build-2026-10-07.md` |

### 14.1 What the build found and decided on the way (steps 2–5)

- **`properties.erf_portion` defaults to `'0'`** (the whole stand). "No portion given" and `'0'` are therefore compatible for the exact erf rule; `'1'` against `'0'` is a veto; a portion given on one side only is *possible* ("portion not known").
- **Strategies 3 / 3b / 4 of the tracked matcher are now one scored pass** (labels `3_erf_suburb`, `3b_scheme_section`, `4_normalised_address` kept — the Deeds screen and the decision log read them). Strategies 0 (address history), 1 (source ref), 2 (GPS) and 5 (loose token overlap, still vetoed by `numbersConflict`, which gained the portion and LPI vetoes) are unchanged: an ingest path with no human in the loop keeps linking only what it linked before.
- **A unit on both sides that differs is a veto everywhere**, including the freehold-erf link. An older test fixture that relied on two different artificial unit numbers merging was rewritten (the rule is Johan's: a different number, unit or erf always blocks "same").
- **Promote (decision 2)** is enforced inside `promoteToStock()` (`$askOnPossible`) so every agent-facing promote — Deeds screen and MIC — asks; programmatic callers (the rental take-on import) behave as before. `$linkToPropertyId` carries the agent's "same — this one".
- **The structured layer sits beside the raw columns** — `street_core` / `street_type` are new, `street_name` is never rewritten; only EMPTY existing columns are filled (`street_number`, `unit_number`, `complex_name`, `scheme_number`, `erf_portion`, `erf_number`, `properties.p24_suburb_id`, and — since 2026-10-07 — `properties.street_name`, see §14.3).
- `SuburbResolver` memoisation is off by default (a stale memo could hand back an id from a row that no longer exists; `properties.p24_suburb_id` has a foreign key) and on only inside the backfill.

- **"Nothing to read" is not "could not read".** A record that holds no address text at all (the ~32,000 Property24 / PrivateProperty captures carry a suburb and nothing else) gets an EMPTY parse status, not `unparseable`; the admin Address Review list therefore holds only addresses that exist and could not be read. Found by the QA1 dry run (32,234 of 39,998 captured rows would otherwise have been listed).

### 14.2 Step 6 — what each consumer does differently now

| Consumer | Before | Now |
|---|---|---|
| `DeedsCaptureLinkService` (owner lookup for a property being pitched) | "possible deed" tier compared the **first word** of the suburb ("Port Edward" = "Port Shepstone"); confirmed tier compared erf / street-name columns exactly | confirmed tier = the scorer's EXACT tier (GPS ~5 m stays); possible tier = same street number in the same **or Property24-neighbouring** suburb. Only when the suburb is not a Property24 suburb at all does the first-word rule still apply, so the real "Ramsgate / Ramsgate Beach" case cannot vanish |
| `ContactAddressPropertyGuard` (stock side) | exact normalised street name incl. type + raw number column | scorer EXACT; a unit on one side only still warns, a conflicting unit vetoes (as before) |
| `MapPinService` T-pin fold | key `number|street name incl. type|suburb`, number only from its own column | key `number|street core|suburb`, the number read from whichever column holds it; a street type written on both sides must agree; cache key bumped to `v2` |
| `MicPropertyReconciliationService` | promoted-sibling lookup by exact erf / street-name columns | scorer EXACT, siblings already promoted, newest promotion first |
| `PropertyCmaPropagationService` | exact text, then any 2 shared words (no number veto); erf with a suburb *substring* | scorer EXACT, a single POSSIBLE; the 2-word pass only for candidates the scorer does not veto; erf needs the **same** suburb |
| `ProspectingStockMatchService` pass 2 (portal listing -> "In stock") | raw suburb equality + number + a non-generic word | scorer EXACT on on-market stock; the "no readable number, no fuzzy match" ruling kept; the generic-word list retired. Neighbouring suburbs stay a POSSIBLE match, never the badge |

Not moved (deliberate): `OnMarketStockService` / `ProspectingListing::normalizeAddress` keep their documented "Uvongo never meets Uvongo Beach" suburb semantics (a different question — counting on-market stock per suburb); the presentation comps `SuburbMatcher` (word-stripping for comp geocoding) and `DealPropertyLinkService` / `PortalInventoryGuard` (own fuzzy scorers over deals and portal inventory) are listed in the design and left for a later pass — they do not feed the tracked/stock matcher.

### 14.3 Bug fix 2026-10-07 — a typed address lost its street name ("12 Beach Road" → "12")

- **Symptom:** a property saved with only a free-text address showed just the house number everywhere `address` is read (list, Filing register, signed-document file names, core-match PDFs, seller emails, AI summary, CMA subject, rental-application picker).
- **Cause:** introduced by steps 2–5 (`9e0586412`). `Property::saving` ran the structurer, which lifted the NUMBER out of the typed text into the empty `street_number` column but left `street_name` empty; `PropertyObserver::saving` (AT-266 "one truth") then re-derived `address` from the parts — number only → `"12"`. Staging (no structurer) never filled the number, the parts stayed empty, the composition was empty and the typed address was left alone.
- **Fix (one place, every save path):** the structurer fills an EMPTY `properties.street_name` with the street **as typed** (new parser output `street_name_typed` — the match key `street_core` drops apostrophes and turns "Saint" into "St", so it is never displayed) whenever it fills the number. Nothing an agent typed in a column is overwritten. `Property::saving` runs the structurer **before** it builds `street_name_normalised`, so the normalised cache sees the filled street. Every Property create/edit/wizard/import/API/Other-Agency-Stock path is an Eloquent save and goes through this one hook; `saveQuietly` writers (portal stamps, geo backfill) never write address columns.
- **Rule the fix keeps:** a column the structurer fills is always filled *together with* the columns `address` is derived from — a number without its street is never written alone.
- **Backfill note:** `address:backfill-structured` shares `updatesFor()`, so a later run also fills an empty `properties.street_name` — which is what repairs older damaged rows. Not run as part of this fix (no data repair).
- **Not changed (reported):** tracked-property rows keep their own street handling (`TrackedPropertyAddress` normalises `street_name` on its own save).
- Tests: `tests/Feature/Properties/PropertyTypedAddressKeepsStreetTest.php` (number only, street only, "12A", unit + number + street, complex names, no number, apostrophe/Saint, suburb in the line, parts-only wizard shape, nothing overwritten).
