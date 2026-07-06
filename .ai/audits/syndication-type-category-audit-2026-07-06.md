# Syndication Type/Category Audit — PP + P24

**Date:** 2026-07-06
**Author:** Johan (with Claude)
**Trigger:** Property 2391 (Vacant Land / Plot) rejected by Private Property with
`PP60 - The attributes are insufficient. Bathrooms is a mandatory attribute for
residential listings`, while the CoreX UI showed the unrelated "Complex/Scheme
name is required."
**Scope:** Both portal mappers — `PrivatePropertyListingMapper` and
`Property24ListingMapper` — for the bug CLASS: a listing is rejected or
mis-syndicated because the mapper derives category/type from the wrong CoreX
field, or emits an attribute VALUE outside the portal's accepted vocabulary.
**Method:** Read back each portal's live, authoritative vocabulary and diff the
mappers against it ("read back, don't guess"). PP read-back via
`GetFullDetailsOfAllListingsByBranch` (329 live HFC listings). P24 uses numeric
type IDs from its own `GET /listing/v53/property-types`.

---

## 1. The bug (property 2391)

CoreX stores **two independent fields**: `category`
(Residential/Commercial/Industrial/Retirement/Holiday/Project) and
`property_type` (House / Apartment / Flat / Townhouse / Vacant Land / Plot /
Farm / Commercial Property / Industrial Property). A vacant-land plot legitimately
has `category = Residential` (it is residentially-zoned land) and
`property_type = Vacant Land / Plot`. **The land/farm signal lives only in
`property_type`.**

`PrivatePropertyListingMapper::mapCategory()` read `category` ALONE. PP's four top
categories are Residential / Commercial / Land / Farms — CoreX's `category`
vocabulary has no Land or Farms value, so **every plot and every farm fell through
to `Category=Residential`**, was given `HomeType=House`, and had
`Bedrooms/Bathrooms/Garages` force-sent at 0. PP then correctly rejected it:
residential listings require bathrooms.

Two defects, one visible symptom:

| # | Defect | Effect |
|---|--------|--------|
| A | PP category derived from `category` only, never `property_type` | Every plot/farm syndicated as Residential → PP60 bathrooms rejection |
| B | `PpFaultTranslator` hardcoded `PP60` → "Complex/Scheme name is required" | PP60 is a GENERIC "insufficient" code PP reuses; real reason ("Bathrooms mandatory") was hidden, UI contradicted PP's own email |

---

## 2. Private Property — findings

### Live vocabulary read-back (329 listings)

- **Categories in use:** `Residential` 288, `Land` 39, `Commercial` 3. (No Farms yet.)
- **HomeType:** `apartment` (lc), `house` (lc), `Townhouse`, `duplex` (lc), `Penthouse` — PP is case-tolerant on HomeType.
- **LandType:** `Residential Land` ×39 — **spaced Title Case**. PP rejected camelCase `VacantLand` with `PP106 - Invalid attribute values supplied`.
- **BusinessType:** `Commercial`, `Bed And Breakfast` — **multi-word values are spaced Title Case**.
- **FarmType:** none live.

**Key convention discovered:** PP stores multi-word attribute VALUES as **spaced
Title Case** (`Residential Land`, `Bed And Breakfast`). Any camelCase multi-word
value is a `PP106` trap.

### Issues found & fixed

| ID | Issue | Status |
|----|-------|--------|
| PP-T1 | Category derived from `category` col only → plots/farms sent as Residential | **FIXED** — new `resolvePpCategory(Property)` derives from `property_type` first (vacant land/plot/stand→Land, farm/smallholding→Farms, commercial/industrial/office/retail→Commercial), falls back to `category`. Single source of truth used by both `map()` and `buildAttributes()`. |
| PP-T2 | `Bedrooms/Bathrooms/Garages` force-sent (even 0) for ALL categories | **FIXED** — forced only for Residential; Land/Farms/Commercial send a count only when > 0. |
| PP-T3 | `LandType` value `VacantLand` rejected (PP106) | **FIXED** — `mapLandType` now returns `Residential Land` (confirmed) / `Commercial Land` / `Industrial Land` / `Agricultural Land`. |
| PP-T4 | Exact-key type maps silently defaulted human-facing vocab (`Apartment / Flat`→`House`) | **FIXED** — `mapPropertyType`/`mapLandType`/`mapFarmType`/`mapBusinessType` switched to substring matching. |
| PP-T5 | camelCase multi-word `FarmType`/`BusinessType` values (`SmallHolding`, `MixedUse`, `GameFarm`, `WineFarm`) — latent PP106 (unreachable from current vocab, would fire if vocab expands) | **FIXED (inferred)** — spelled spaced Title Case (`Small Holding`, `Mixed Use`, `Game Farm`, `Wine Farm`). See residual item R1. |
| PP-T6 | `PpFaultTranslator` masked PP60's real message | **FIXED** — PP60 no longer overridden; PP's own (human-readable) text surfaced. |
| PP-T7 | No pre-flight guard for a residential listing with 0 bathrooms | **FIXED** — `validate()` blocks it with an actionable message that points to the property-type fix. |

### Post-fix coverage matrix (every CoreX property_type)

| property_type | PP category | PP type attr = value | Baths forced | P24 id |
|---|---|---|---|---|
| House | Residential | HomeType = House | yes | 4 |
| Apartment / Flat | Residential | HomeType = Apartment | yes | 5 |
| Townhouse | Residential | HomeType = Townhouse | yes | 6 |
| Vacant Land / Plot | Land | LandType = Residential Land | no | 8 |
| Farm | Farms | FarmType = Farm | no | 10 |
| Commercial Property | Commercial | BusinessType = Commercial | no | 11 |
| Industrial Property | Commercial | BusinessType = Industrial | no | 12 |

Confirmed live: property 2391 re-pushed → PP `Successful`.

---

## 3. Property24 — findings

**P24 is structurally immune to this bug class.** Reasons:

1. **Type derives from `property_type`, never `category`.**
   `resolvePropertyTypeId($property->property_type)` with robust normalisation
   (lowercase, punctuation→space, collapse) and substring matching — handles
   "Apartment / Flat", "Apartment/Flat", "apartment-flat" identically.
2. **Numeric type IDs, not free strings.** P24's `propertyTypeId` (4 House,
   5 Apartment, 6 Townhouse, 8 Vacant Land, 10 Farm, 11 Commercial, 12 Industrial)
   comes from P24's own `GET /listing/v53/property-types` — there is no
   string-vocabulary to mis-spell, so the `VacantLand`-style PP106 trap cannot
   exist.
3. **No forced structural counts.** `bedrooms` and `bathrooms` are emitted ONLY
   when > 0 (`buildPropertyFeatures` lines 272, 282). A vacant-land plot therefore
   never sends a phantom `bathrooms: 0`, so P24 cannot reject it the way PP did.
   `propertyTypeId=8` (Vacant Land) is a distinct P24 type that does not require
   bedroom/bathroom info.

No P24 fix required for this class. (Inverse risk — P24 possibly requiring a
bathroom for a residential type with 0 baths — is out of scope here and has not
manifested; the new PP `validate()` guard would also catch that data shape
pre-flight since both mappers run off the same property.)

---

## 4. Structural prevention (so this cannot recur silently)

- **`tests/Unit/Syndication/SyndicationTypeCoverageTest.php`** — drives the ENTIRE
  real CoreX `property_type` input space through BOTH mappers and asserts, per
  type: PP resolves a valid category (property_type-aware), emits exactly one
  category-appropriate type attribute, the PP value is not camelCase multi-word
  (PP106 guard via `/[a-z][A-Z]/`), counts are forced only for residential, and
  P24 resolves a valid numeric type ID. Adding a `property_type` option without
  wiring it through now fails a test, mechanically, before it reaches a portal.
- **`tests/Unit/PrivateProperty/PpLandCategoryTest.php`** — focused assertions on
  the 2391 shape + the `validate()` 0-bathroom guard.
- **`PpFaultTranslatorTest`** — PP60 now asserted to surface PP's real message
  (both the address and the bathrooms variants).

---

## 5. Residual / open items

| ID | Item | Risk | Recommendation |
|----|------|------|----------------|
| R1 | `FarmType` and multi-word `BusinessType` values (`Small Holding`, `Game Farm`, `Wine Farm`, `Mixed Use`, `Industrial`) are **inferred** from PP's spaced convention, not live-confirmed — no farm/industrial listing exists on the branch to read back | Low (unreachable from current CoreX property_type vocab except `Industrial`) | Confirm on the first live farm/industrial push, or against PP's Appendix A if obtained. |
| R2 | PP's **Appendix A** (the authoritative attribute datatype + value catalogue) is not in the repo | Medium | Obtain the PDF/doc from PP and commit a machine-readable extract to `storage/`; several audits have now had to reconstruct it from live read-backs. |
| R3 | Carry-over from `syndication-mapping-audit-2026-07-05.md`: PP `ScenicView` trigger vocab; P24 content gaps (zoneType, virtual tour, commercial leaseType) | Low (content gaps, no rejection) | Track separately. |

---

## 6. Files changed

- `app/Services/PrivateProperty/PrivatePropertyListingMapper.php` — `resolvePpCategory()`, category-aware `buildAttributes()`, residential-only forced counts, spaced type-value vocab, `validate()` 0-bath guard.
- `app/Services/PrivateProperty/PpFaultTranslator.php` — remove PP60 override.
- `tests/Unit/Syndication/SyndicationTypeCoverageTest.php` — new cross-mapper guard.
- `tests/Unit/PrivateProperty/PpLandCategoryTest.php` — new.
- `tests/Unit/PrivateProperty/PpFaultTranslatorTest.php` — updated PP60 assertion.

Related: `.ai/audits/syndication-mapping-audit-2026-07-05.md`,
`.ai/audits/syndication-bug-sweep-2026-06-20.md`.
