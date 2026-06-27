# P24 featureTags / tags / propertyFeatures — Contract Reference

**Status:** Phase 1 — investigation only. NO mapper change until Johan approves.
**Author:** generated 2026-06-26 from `storage/p24_swagger.json` (ExDev Listing Service v53) + live P24 rendering observations on listing #1322 (CoreX property 1322 / P24 117137318).
**Why:** featureTags has been built wrong twice. This is the single source of truth for how P24 structures property features, so Phase 2 maps to it exactly instead of guessing.

> **Legend for every claim below:**
> - **[CONTRACT]** — stated by the swagger (authoritative, not an inference).
> - **[OBSERVED]** — seen on the live P24 frontend for #1322 (the swagger does NOT document frontend section placement).
> - **[OPEN]** — not resolvable from the swagger; needs Johan's eyeball or P24 support before we rely on it.

The swagger is a **data contract only**. It defines the JSON shape and, per Tag, *whether* a tag shows "associated with a listing" vs "associated with a feature description" — but it does **not** name the frontend sections ("Rooms", "Other Features", etc.). There are **zero** `featureTags` request/response examples in the whole document (1 occurrence = the schema definition). So section placement is established by [OBSERVED]/[OPEN], not invented.

---

## 1. `Listing.featureTags[]` entry schema — every field  [CONTRACT]

`components.schemas.FeatureTag` (`additionalProperties: false`):

| Field | Type | Nullable | Contract meaning | Swagger example |
|-------|------|----------|------------------|-----------------|
| `featureType` | `FeatureType` enum | no | The category/room this feature-tag belongs to. | `Bedroom` |
| `tags` | `Tag[]` | yes (optional) | "A list of tags that describes the feature." The structured features. | `["Shower","Toilet","Basin"]` |
| `description` | `string` | yes (optional) | **"A description of the feature."** | **`"Shower, Toilet and Basin"`** |

Schema-level description: *"This type represents combinations of FeatureType and Tag values. Feature tags are used to give descriptions for individual rooms/features."*

### 1a. How the row is labelled vs valued
- **Row label = `featureType`** [OBSERVED + inferred from CONTRACT]. P24 derives the heading from the enum member (Bedroom → "Bedroom", DiningRoom → "Dining Room"). The label is NOT taken from `description`.
- **Row value = the features** — `tags[]` (structured) and/or `description` (the human-readable feature summary). The canonical example proves it: `description = "Shower, Toilet and Basin"` is a **feature list**, not a room name.

### 1b. THE DUPLICATION BUG (Q1) — root cause, contract-grounded
Our mapper sets **`description` = the unit label ("Bedroom 1")**:
```
{ featureType: "Bedroom", tags: ["TiledFloors","Fan","TVPort"], description: "Bedroom 1" }
```
That is a misuse of `description`. P24 already labels the row "Bedroom" from `featureType`, then prints our `description` ("Bedroom 1") → the room name appears **twice** ("Bedroom" heading + "Bedroom 1" value), pushing/duplicating the actual features. Per [CONTRACT], `description` must carry the **features text** (e.g. `"Tiled Floors, Fan, TV Port"`) or be **omitted** — never the room name/number. The room identity is `featureType`'s job.

**Note:** the contract provides **no field to number rooms** (Bedroom 1 vs Bedroom 2). Multiple bedrooms = multiple `featureType:"Bedroom"` entries; P24 lists them. Narrative per-room text belongs in `PropertyFeatures.bedroomsDescription` (a single string), not in featureTags `description`. → **[OPEN] for Johan:** do we want N discrete "Bedroom" rows (one per unit, each with its tags) or one consolidated Bedroom row? The contract supports both; pick the display you want.

---

## 2. `FeatureType` enum — every member  [CONTRACT]

Full enum (23 members):

`Bedroom, Bar, Bathroom, Closet, DiningRoom, FamilyTVRoom, Garage, Garden, Kitchen, Lounge, Loft, Office, Outbuilding, Pool, EntranceHall, Parking, Security, SewingRoom, SpecialFeature, TemperatureControl, UtilityRoom, BraaiRoom, Other`

| Member | Kind | Notes |
|--------|------|-------|
| Bedroom, Bathroom, Kitchen, Lounge, DiningRoom, FamilyTVRoom, Garage, Garden, Pool, EntranceHall, Parking, Office, Loft, Bar, Closet, SewingRoom, UtilityRoom, BraaiRoom, Outbuilding | **Room types** | Render under named **Rooms** [OBSERVED]. |
| **Security** | category | Not a room — a feature *category*. Frontend section [OPEN]. |
| **TemperatureControl** | category | Climate (e.g. air-con). Frontend section [OPEN]. |
| **SpecialFeature** | category | Misc. Frontend section [OPEN]. |
| **Other** | category | Valid enum member — **we did NOT invent it.** BUT on #1322 it renders as a stray **"Other" room row** [OBSERVED], NOT the "Other Features" amenity section. → Do not use `featureType: "Other"` to mean the "Other Features" section. |

**Answer to Q2:** there is **no FeatureType that produces the "Other Features" amenity section.** "Other Features" comes from a different structure entirely — see §3.

---

## 3. Where "Other Features" actually comes from (Q2/Q3)  [CONTRACT + OBSERVED]

On #1322, "Other Features" correctly shows **Wheelchair Accessible** and **Backup Water**. Those are **`PropertyFeatures` structured booleans**, not tags and not featureTags:

- `PropertyFeatures.isWheelchairAccessible: boolean` → "Wheelchair Accessible" [CONTRACT + OBSERVED]
- `PropertyFeatures.hasBackupWater: boolean` → "Backup Water" [CONTRACT + OBSERVED]

`PropertyFeatures` is the typed amenity surface. Relevant fields (all [CONTRACT]):
`isWheelchairAccessible, hasGenerator, hasBackupWater, garden, pool, flatlet, secondHouse, hasStandaloneBuilding, numberOfFloors, petsAllowed, furnishedStatus, internetAccess{adsl,fibre,satellite,vdsl,…}, sustainabilityInfo{solarPanels,solarGeyser,gasGeyser,waterTank,borehole,backupBatteryOrInverter}, outsideArea{balcony,courtyard,roofArea}, parking{carport,secureParking,…}, kitchens{}, bathrooms{}, publicTransport{}, receptionRooms, studies, domesticRooms, …`

Our `buildPropertyFeatures()` ALREADY maps a large set of CoreX features into these (Wheelchair Friendly→isWheelchairAccessible, Generator→hasGenerator, Backup Water/Water Tank/Borehole→hasBackupWater, Solar/Inverter→sustainabilityInfo, Garden/Pool/Flatlet/Pets/Furnished/Internet/OutsideArea/Parking, etc.). **This is why those already display correctly under "Other Features."**

### 3a. The gap — amenities with NO PropertyFeatures field
`PropertyFeatures` has **no security object and no generic-amenity field**. There is **no `SecurityInfo` / `TemperatureControlInfo` schema** (confirmed absent). So these CoreX global amenities have **nowhere structured to go**:
`Alarm System, Burglar Bars, Electric Fence, Electric Gate, Security Gate, 24 Hour Access, Totally Walled, Gated Community, Intercom, CCTV, TV Port, Telephone Port, Internet Port`, etc.

Per [CONTRACT], each of these IS a `Tag` enum member of **Type: "listing feature"**, flagged `Shown when associated with a listing: yes` AND `Shown when associated with a feature description: yes`. So the contract says they can ride **top-level `tags[]`** (listing-level) OR a **`featureTags[].tags`** entry (feature-description-level). The contract does **not** say which frontend section either lands in. [OPEN]

### 3b. What we know about each global-amenity path on #1322
| Path tried | Result | Source |
|-----------|--------|--------|
| `featureType: "Other"` + tags | renders as a stray **"Other" room row** — wrong | [OBSERVED] |
| top-level `tags[]` (connectivity **ports** only) | renders as a **phantom room** ("TV Port" room on #1322) — wrong | [OBSERVED, 2026-06-27] — **RESOLVED**, see §3c |
| top-level `tags[]` (security/descriptive: Alarm, CCTV, Sea, …) | rides the listing as a feature — correct | [OBSERVED] |
| `PropertyFeatures` boolean | renders under **"Other Features"** — correct | [OBSERVED] |

### 3c. RESOLVED (2026-06-27) — connectivity ports are room-detail ONLY
The connectivity-port Tags **`InternetPort`, `TelephonePort`, `TVPort`** are room-detail descriptors (the `*RoomOptions` neighbourhood of the Tag enum). On #1322 a **global** "TV Port" (selected on the connectivity feature screen, not attached to a room) flowed to top-level `tags[]` and P24 rendered it as a standalone **"TV Port" room**. These three Tags now NEVER ride top-level `tags[]` — they are emitted ONLY inside a room's `featureTags[].tags`, where buildFeatureTags() already places them when the feature is attached to a space. Enforced by `Property24ListingMapper::ROOM_DETAIL_ONLY_TAGS` (filtered in `buildTags()`); kept in `FEATURE_TAG_MAP` so the per-room path is unaffected. This closes Option A vs Option B (§4b.2) **for the port family only**: top-level tags[] is rejected for ports; the security/descriptive family is unchanged (top-level tags[] is correct for those).

---

## 4. How each thing SHOULD be sent (Q4) — proposed, for approval

> Phase 2 will implement whatever Johan approves here. Nothing below is coded yet.

### 4a. Per-room feature (e.g. TV Port on Bedroom 1)  [CONTRACT-safe]
```jsonc
{ "featureType": "Bedroom", "tags": ["TiledFloors","Fan","TVPort"] }   // description OMITTED
// or, if a human summary is wanted:
{ "featureType": "Bedroom", "tags": ["TiledFloors","Fan","TVPort"], "description": "Tiled Floors, Fan, TV Port" }
```
**Never** `"description": "Bedroom 1"`. (Fixes the duplication bug with high confidence — pure contract.)

### 4b. Global / property-wide amenity → "Other Features"
**Decision rule (proposed):**
1. **If a `PropertyFeatures` field exists for it → set that field.** (Proven path to "Other Features.") Most amenities already covered by `buildPropertyFeatures()`; audit for any missing mappings (e.g. confirm Generator/Wheelchair/etc. fire).
2. **If NO `PropertyFeatures` field exists** (security/connectivity family: Alarm, Burglar Bars, Electric Fence/Gate, 24hr Access, CCTV, Intercom, TV Port-global, ports) → the contract gives only `tags[]` or `featureTags[].tags`. **[OPEN] — needs Johan/P24 confirmation which of these P24 files under "Other Features":**
   - **Option A:** top-level `tags[]` (Type "listing feature", `Shown with listing: yes`). Simplest, contract-clean. Re-confirm where it renders on #1322 (earlier "Rooms" observation may have been the `featureType:Other` entry, not `tags[]`).
   - **Option B:** `featureTags[]` with `featureType: "Security"` (for Alarm/BurglarBars/Fence/Gate/CCTV/24hr) and `featureType: "TemperatureControl"` (air-con). These are real category enum members and may render under their own headings.
   - **NOT** `featureType: "Other"` (proven stray-room).

### 4c. Room types with no features  [CONTRACT-safe — already fixed, confirm keep]
Omit. A `FeatureTag` with empty `tags` and no real description conveys nothing and renders as "Bedroom 1 = Bedroom 1". Current behaviour (suppress empty rooms) is correct — keep.

---

## 5. The two concrete decisions needed from Johan before Phase 2
1. **Duplication (4a):** confirm we drop the room-label `description`. Discrete per-unit rows or one consolidated row per room type? (contract supports both)
2. **Global amenities with no PropertyFeatures field (4b.2):** Option A (top-level `tags[]`) or Option B (`featureType: Security`/`TemperatureControl`)? This is [OPEN] — ideally verified by pushing ONE test listing and eyeballing P24, since the swagger does not specify the section.

Everything in §3 (PropertyFeatures → Other Features) and §4a/§4c is contract-grounded and low-risk; §4b.2 is the only genuinely open call and is the one that has bitten us — so we resolve it by observation, not assumption.
