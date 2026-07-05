# Syndication Mapping Audit — Property24 & Private Property

**Date:** 2026-07-05
**Author:** Johan (via Claude)
**Scope:** Full correctness audit of both portal mappers — every field/feature/enum each portal expects vs. what CoreX actually sends, and where it is broken.

**Files audited**
- P24 mapper: `app/Services/Syndication/Property24/Property24ListingMapper.php`
- PP mapper: `app/Services/PrivateProperty/PrivatePropertyListingMapper.php`
- Shared trait: `app/Services/Syndication/Concerns/ResolvesPropertyFeatures.php`
- Vocabulary: `config/property-spaces.php`
- Schemas: `storage/p24_swagger.json` (v53), `storage/pp-agentimport.wsdl`, `storage/pp-attributetype-enum.txt` (70 values)

**Method:** every enum string each mapper emits was extracted programmatically and set-differenced against the schema's enum members; nested object shapes/datatypes cross-checked field-by-field. Priority findings re-verified by hand against the live code (marked ✔ verified below).

---

## Headline

Both mappers are **structurally sound** — no finding proves a *guaranteed* whole-listing rejection, and enum spelling / membership / required-field coverage is clean on both sides.

- **P24:** `FEATURE_TAG_MAP` 131/131 tag strings valid, `ROOM_DETAIL_ONLY_TAGS` 40/40 valid and correctly kept out of top-level `tags[]` (phantom-room defense intact). One confirmed value bug (`showLocation`), several real content gaps.
- **PP:** coverage has grown from ~8 to **64 of 70** AttributeTypes. "Yes" boolean format, verbatim enum spelling (incl. PP's own misspellings), location name-mode, and all required structural fields are correct. Two confirmed **silent-drop** bugs, seven space-type gaps, and one datatype risk that needs a live check.

The theme across both is the same class of bug: **flags/counts driven by a `type`/feature string that doesn't match the vocabulary**, so data is silently dropped (not rejected). No agent sees an error; the feature just never appears on the portal.

---

## PROPERTY24

### BROKEN

**P24-B1 — `showLocation` truthiness bug (✔ verified).** `Property24ListingMapper.php:191-193`.
`latitude`/`longitude` are cast `decimal:7` (`Property.php:317-318`) → Laravel returns them as **strings** (`"0.0000000"`). The guard `(bool) ($property->latitude && $property->longitude)` is therefore **always true** (a non-empty string is truthy), so a never-geocoded property emits `showLocation: true`. This is the *exact* string-cast bug already fixed for the `geographicLocation` block at line 82 (comment 78-81) — the numeric fix was applied there but not to this guard.
- **Effect:** low severity, but privacy-adjacent — P24 is told to show the location for a listing that has no coordinates, so it can surface the street address (`streetNumber`/`streetName` are sent at 188-189) that the agent never intended to publish.
- **Fix:** mirror line 82 — `'showLocation' => $property->p24_hide_address ? false : ((float) $property->latitude != 0.0 && (float) $property->longitude != 0.0)`.

**P24-B2 — `furnishedStatus` fabricates "No" from missing data.** `Property24ListingMapper.php:238`. When neither "Furnished" nor "Unfurnished" is selected, the mapper sends `No` (definitively unfurnished) for every such listing. `No` is a valid enum member (no rejection), but it asserts a fact from absence. Consider the neutral enum default if P24 accepts one for this required field. Low severity.

### GAP (P24 accepts it, CoreX has the data, mapper never sends it)

- **P24-G1 — `rentalInfo.rentalRate` never sent (✔ verified).** `map():108` sends only `leasePeriod` + deposit comment. CoreX has `rental_price_type` + `price_per_day/week/year`, but the rate cadence enum (`Month/Week/Day/Year/SquareMetre`) is never emitted → **weekly/daily/short-term rentals publish at P24's default monthly cadence.** Real public-facing correctness bug; highest-value gap.
- **P24-G2 — `propertyInfo.zoneType` never sent.** CoreX has `Property.zone_type`; P24 enum `SingleResidential…MixedUse`. Needs a value map to guarantee enum match.
- **P24-G3 — `eyeSpy360Url` never sent.** CoreX has `virtual_tour_url` (only `youTube` + `matterport` are mapped, lines 55-56) → the "Other Virtual Tour" URL never reaches P24.
- **P24-G4 — commercial `leaseType` + `yardPrice` dropped.** CoreX has `lease_type`, `yard_price`; the commercial block (120-138) omits both.
- **P24-G5 — `complexInfo.unitName` never sent.** CoreX has `unit_section_block`; only `complexName` + `unitNumber` are sent.
- **P24-G6 (soft) — descriptive subtype tags.** The `Tag` enum has Penthouse/GolfEstate/Waterfront/Freestanding/Duplex etc.; CoreX `category`/`title_type` are never translated to them. Needs a curated map.

### OK (verified conformant)
All Listing top-level fields, PropertyInfo (correct `Fee`/`Area` object shapes, scalar `specialLevy`), and all PropertyFeatures: BathroomsInfo half-bath modelling (`baths + 0.5*half_baths`, line 251 — the fix shipped earlier today), ParkingInfo (11 booleans), KitchensInfo, InternetAccessInfo (unconditional emit to clear stale state — deliberate), SustainabilityInfo, OutsideAreasInfo, PublicTransportInfo, `receptionRooms` unconditional emit. Every scalar enum (listingType, status, petsAllowed, area/price units) is a verbatim swagger member.

### UNMAPPABLE (no CoreX source)
`propertyInfo.age`, coverage/pricePerParkingBay, developmentId/lightstoneId/repossessed/noTransferCost/isMultiListing, full AuctionInfo, deep CommercialInfo (GLA/buildingGrade/power/truckAccess/dockLevellers), municipal_valuation, commission/admin/marketing fees.

---

## PRIVATE PROPERTY

### BROKEN — confirmed silent drops

**PP-B1 — `Carports` is dead code, never emitted (✔ verified).** `PrivatePropertyListingMapper.php:449` counts `countSpaces($property, 'Carport')`, but **`Carport` is not a space type** — `config/property-spaces.php:25-35` has no `Carport`; a carport is a *feature* of the `Parking` space (`config/property-spaces.php:69`). The count is therefore always 0 → the `Carports` attribute is never sent even when the property has carports.
- **Fix:** count Parking spaces/units carrying the `Carport` feature (not a space type lookup).

**PP-B2 — `Laundry` uses the wrong space-type string (✔ verified).** `PrivatePropertyListingMapper.php:488` checks `$hasSpace('Laundry')`, but the space type is `Laundry Room` (`config/property-spaces.php:31`), and `Laundry` is not a feature label either (confirmed: no `'Laundry'` in the vocab). So `has('Laundry')` and `hasSpace('Laundry')` are **both always false** → a laundry entered as a `Laundry Room` space never sends the PP `Laundry` flag.
- **Fix:** `$hasSpace('Laundry Room')`.

### BROKEN — RESOLVED 2026-07-05 via live read-back (was: PP106 datatype risk)

**PP-B3 — five attributes emitted in the WRONG Appendix A datatype (✔ verified + FIXED).** `buildAttributes()` emitted `Kitchen`, `Family_TV_Room`, `Study`, `StaffQuarters`, and `Entrance_hall` as **integer** counts, but PP's Appendix A types all five as **boolean "Yes"**.
- **Ground truth:** `GetFullDetailsOfAllListingsByBranch` was called read-only against the live HFC branch (2026-07-05, **327 listings**) and every stored `Attribute.Value` bucketed by datatype:
  - **INTEGER/COUNT:** `Bedrooms`, `Bathrooms`(+.5), `Garages`, `FloorArea`, `LandArea`, `Rates`, `Levies`, `Storeys`, `Lounges`, `DiningAreas`, `Parking`, `Carports`(1–3), `EnSuite`(1–5).
  - **BOOLEAN "Yes":** `Kitchen`(191/191), `Entrance_hall`(37), `StaffQuarters`(16), `Study`(13), `Family_TV_Room`(11) — plus every amenity flag.
- **Why no rejection was ever seen:** PP *coerces* a numeric value to `"Yes"` for boolean attributes — that is the ONLY reason property 6049 survived with `Kitchen="1"`. The audit's "Kitchen is a proven count" anchor was therefore **wrong**: `Kitchen` is a flag; PP silently coerced the integer. Sending the wrong datatype was tolerated, not correct, and is fragile to any PP validation tightening. (The historical PP106 in `private_property-2026-07-02.log` was the *reverse* mismatch — the old `EnSuite="true"` boolean sent to an integer attribute, since fixed to `EnSuite="1"`.)
- **Fix (shipped):** the five moved out of the `$counts` integer loop into present-only `"Yes"` flags, keyed off the same space types (`Kitchen`, `TV Room`, `Study`/`Office`, `Domestic Room`, `Entrance Hall`). `Lounges`/`DiningAreas`/`Parking`/`Carports` stay integer counts (read-back-confirmed). A read-back verification path is now the standard way to settle any future count-vs-flag question.

### GAP — PP defines it, CoreX has the data, mapper doesn't reliably send it

- **PP-G1 — seven amenity flags don't fire when entered as a SPACE (✔ verified).** These flags check `has()` (feature strings) only, but each is also a real space type an agent can add; entered as a space, the flag never fires: `Gym` (:515 / space `Gym`), `Clubhouse` (:514 / `Clubhouse`), `TennisCourt` (:512 / `Tennis Court`), `SquashCourt` (:513 / `Squash Court`), `Jaccuzzi` (:517 / `Jacuzzi`), `Jetty_Berth` (:518 / `Jetty`), `Storage` (:493 / `Storeroom`). Contrast Pool/Garden/Flatlet/Patio/Lapa/Scullery/Wendy House which correctly add `|| $hasSpace(...)`.
  - **Fix:** add `|| $hasSpace('<Type>')` to each of the seven (using the exact `all_space_types` string).
- **PP-G2 — `ScenicView` never fires (data plumbing).** :504 checks `Scenic View/Mountain View/Bush View/Garden View/City View/River View` — **none of these exist in `config/property-spaces.php`.** The enum spelling is correct; the trigger vocabulary doesn't exist, so the attribute is dead. (Also: the live blade feature list in `show.blade.php:6463` diverges from the config file — worth reconciling as its own item.)
- **PP-G3 — `FarmName` (#16) never sent.** For Farms listings the property title could populate it. Minor.

Not gaps: `LeviesAndRates` (#19) intentionally superseded by split `Rates`+`Levies`; `Irrigation_System` (#59) is a PP duplicate of `IrrigationSystem` (#66), which is sent.

### OK (verified correct)
All required WSDL Listing `minOccurs=1` fields emitted (`map():59-97`); Category/MandateType/ListingType/PropertyStatus/Province all valid enum values; **location name-mode** (Suburb+Town+Province, SuburbId disabled) correct with mutual-exclusion validation; **boolean format `"Yes"` everywhere** (`ATTR_PRESENT`, present-only); **verbatim enum spelling incl. PP's misspellings** `Satelite`/`Jaccuzzi`/`ElectrictyIncluded`; **zero non-enum AttributeType strings emitted**; structural integer counts correct.

### UNMAPPABLE / unverifiable-from-repo
`Paving` (#60), `RoofType` (#61), `Finishes` (#62) — no CoreX source. `HomeType/BusinessType/FarmType/LandType` **Values** (e.g. `GardenFlat`, `SmallHolding`, `MixedUse`) are free strings validated against PP's internal vocabulary not in the repo — worth a live confirm.

---

## Prioritized action list

**Fix now (confirmed bugs, low-risk edits):**
1. **PP-B1** `Carports` dead code — carports never syndicate to PP.
2. **PP-B2** `Laundry` wrong space string (`'Laundry'` → `'Laundry Room'`).
3. **PP-G1** seven amenity flags — add `|| $hasSpace(...)` (Gym, Clubhouse, TennisCourt, SquashCourt, Jaccuzzi, Jetty_Berth, Storage).
4. **P24-B1** `showLocation` string-cast — one-line numeric guard (privacy-adjacent).
5. **P24-G1** `rentalInfo.rentalRate` — weekly/daily rentals mis-publish as monthly.

**Verify live before deciding (potential rejection / vocabulary):**
6. **PP-B3 — ✅ DONE 2026-07-05.** Live read-back proved `Kitchen`/`Family_TV_Room`/`Study`/`StaffQuarters`/`Entrance_hall` are boolean `"Yes"` in Appendix A (not counts); all five switched to present-only flags. `Carports`/`Lounges`/`DiningAreas`/`Parking` confirmed integer counts.
7. **PP-G2** `ScenicView` trigger vocabulary + `show.blade.php` vs `config/property-spaces.php` divergence.
8. **PP** `HomeType/BusinessType/FarmType/LandType` Values against PP's vocabulary.

**Content gaps (data loss, no rejection):**
9. P24-G2 zoneType, P24-G3 eyeSpy360Url (virtual tour), P24-G4 commercial leaseType/yardPrice, P24-G5 unitName, P24-G6 subtype tags; PP-G3 FarmName.

**Housekeeping:** P24-B2 `furnishedStatus` unknown→"No".

---

*No changes made in this pass — audit only. Findings marked ✔ were re-verified by hand against the current code; PP-B3 is an explicit unknown pending a live read-back.*
</content>
</invoke>
