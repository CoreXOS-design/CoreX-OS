# AT-104 — P24 suburb/area resolution audit (full stock)

**Date:** 2026-06-26
**Scope:** Confirm that, after the `resolveSuburbId()` fix + backfill, NO property
syndicates a wrong P24 area/suburb. Audit run against the LIVE database.

## What determines the P24 "area"

The P24 `saveListing` payload carries location as a **single integer
`propertyInfo.suburbId`** (`storage/p24_swagger.json:5166`). P24 derives the
city/province from that one ID. Everything else in the payload —
`streetNumber`, `streetName`, `complexInfo`, `geographicLocation` (GPS) — is
free-text/coordinate **passthrough** and does not route the listing's area.
**So the only field that can put a listing in the wrong area is `suburbId`.**
This audit therefore centres on suburbId resolution; address fields carry no
ID-resolution bug class (a wrong GPS pin would be a data-quality issue on the
property row, not a routing defect).

## How suburbId is now resolved (post-fix)

1. **Stored `p24_suburb_id` FK** (chain-verified by `AppliesP24Location`) → its `p24_id`. Authoritative.
2. Else exact name match **disambiguated by city/province**, unique-only.
3. Else fuzzy name match, same constraint, unique-only.
4. Else `null` → `validate()` skips + flags ("Suburb unmapped — manual P24 suburb mapping required"). Never a guess.

## Findings (LIVE)

- **Collision suburb names in `p24_suburbs`: 2,086** (same name, >1 distinct
  P24 id across cities). This is the at-risk universe; "Glenmore" was one of them.
- **Enabled (syndicating) listings: 265.** All 265 resolve via **tier 1
  (stored FK)** — name=0, null=0. Correctness of every live listing therefore
  reduces to correctness of its stored `p24_suburb_id`.
- **Mapped properties (`p24_suburb_id` set): 4,740.**
  - FK pointing at a missing/incomplete `p24_suburbs` row: **0**.
  - **FK-chain drift** (`property.p24_city_id` != the suburb's own
    `p24_city_id`): **0** — every stored mapping is internally consistent.
- **Enabled listings with a COLLISION suburb name: 18 of 265.**
  - City-text unverifiable (city not a P24 node): **0**.
  - **Resolving to a city that disagrees with the property's city text: 0.**
- **Post-backfill drift** (live listing whose last-sent suburbId != corrected): **0**.
- **Enabled listings that now resolve to null (would skip+flag): 0** — nothing
  that was syndicating gets dropped.

### The 18 collision-name enabled listings (all correct)

| suburb | count | resolves to (p24City) |
|--------|-------|-----------------------|
| Glenmore | 8 (1702-range + 2020,3894,5747,5762,5831,5860,5862,5891) | suburbId 10790 → Port Edward |
| Leisure Bay | 6 (1702,1902,1903,2452,3062,5130,5942) | suburbId 6363 → Port Edward |
| Bulwer | 1 (2821) | suburbId 5969 → Durban |
| Rocklands | 1 (3307) | suburbId 18249 → Port Edward |
| Melville | 1 (5900) | suburbId 10881 → Port Shepstone |

Every resolved P24 city matches the property's stated city.

## Conclusion

No property currently sends a wrong P24 area. The collision class (2,086 names;
18 live instances) is fully handled: live listings resolve only via the
chain-verified stored FK, and that FK is internally consistent for all 4,740
mapped properties. The 8 listings that *were* mis-routed (commit `0709f69d`)
have been re-synced to their correct areas; the 6 Leisure Bay listings were
already correct pre-fix.

**One unrelated item:** prop 3894 (Glenmore) now sends the correct suburbId
(10790) but P24 rejects it with "must have one or more agents" — its listing
agent (Johan #22) has `exclude_from_p24 = true`. Agent-exclusion issue, not a
suburb/area issue.

## Method

Read-only audit script (`resolveSuburbId` via reflection + `p24_cities` /
`p24_provinces` / `p24_suburbs` reference maps + last `submit`
`request_payload` per enabled listing). No data mutated by the audit.
