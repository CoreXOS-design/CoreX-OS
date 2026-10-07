# CMA / Deeds Capture — spec (phase 1)

**Status:** Built (QA1) — 2026-08-12. Lead lane: cc1 (CoreX side). cc5 builds the Chrome extension against §2.

## 1. What it is
A one-click "ingest into CoreX" from a third-party CMA / deeds lookup page (CMA Info,
cmainfo.co.za) that shows a property's deeds data + owner identity. Shared plumbing with
prospecting (tracked_properties + TrackedPropertyMatchOrCreateService), but a SEPARATE
user-facing screen ("Deeds Capture"). Deeds captures are filtered OUT of MIC Opportunities.

Two plays: (1) prospecting from MIC — capture → suspense → Pitch Now promotes;
(2) own stock — capture → confirm on the Deeds Capture screen → create property + owner.

## 2. Capture endpoint + payload contract (for cc5)

```
POST /api/v1/deeds-capture
Authorization: Bearer <sanctum personal access token>   (same token flow as portal-capture)
Content-Type: application/json

{
  "source": "cmainfo",
  "captures": [                          // batch; 1+ per request
    {
      "source_ref": "cmainfo:<stable-id>",   // REQUIRED — idempotency + match-or-create source ref (stable per property)
      "property": {
        "deeds_office": null, "scheme_name": null, "scheme_number": null, "section_number": null,
        "erf_number": null, "address": null, "street_number": null, "street_name": null,
        "unit_number": null, "complex_name": null,
        "suburb": null,            // primary match key
        "municipality": null,      // stored as tracked_properties.town
        "province": null, "latitude": null, "longitude": null,
        "section_extent_m2": null, // stored as cadastral_extent
        "property_type": null, "title_deed_number": null
      },
      "owner": {
        "name": "John Smith",      // REQUIRED — person or company name
        "id_number": null,         // SA ID or company reg — THE JOIN KEY (phase-2 phone fill keys on it)
        "id_type": "sa_id"         // "sa_id" | "company_reg" | null
      },
      "sale": {
        "sale_price": null, "sale_date": null, "registered_date": null,
        "bond_holder": null, "bond_amount": null, "sale_type": null
      }
    }
  ]
}

Response 200: { "ok": true, "results": [ { "source_ref", "tracked_property_id", "owner_contact_id", "created" } ] }
Errors: 401 unauthenticated · 422 validation · per-row failure → results[].error (batch never hard-fails on one row)
```

## 3. Storage mapping
- tracked_property (match-or-create, source 'deeds_capture'): address parts, suburb, town(=municipality),
  province, lat/lng, erf_number, title_deed_number, cadastral_extent(=section_extent_m2), property_type,
  last_known_sold_price(=sale_price), last_known_sold_date(=sale_date). New columns: deeds_office, scheme_name,
  scheme_number, section_number, bond_holder, bond_amount, sale_type, deeds_registered_date, capture_kind.
- `capture_kind='deeds_capture'` is stamped ONLY when the deeds capture CREATES the tracked_property (deeds data
  enriching an existing prospecting TP leaves it in Opportunities).
- owner → contacts (name + id_number + id_type; phone LEFT EMPTY), deduped on id_number; linked via
  tracked_properties.owner_contact_id. On promote → contact_property role='owner'.
- contacts.id_type = 'sa_id' | 'company_reg'; the owner ID always lives in contacts.id_number.

## 4. Screen
`GET /corex/deeds-capture` (permission `deeds_capture.access`, own sidebar item) — lists un-promoted deeds
captures; `POST /corex/deeds-capture/{trackedProperty}/promote` → promoteToStock + owner link. Phone-fill for
the Virtual Agent is a placeholder (phase 2).

## 5. Deliberately NOT in phase 1
Virtual Agent phone-fill (phase 2), market_data_points metric history for sale/bond, a per-field edit/confirm UI.


---

## 6. Johan's decisions — 2026-08-18 (BINDING, do not re-ask)

### 6.1 Freehold vs sectional are DIFFERENT field sets on cmainfo
Observed live on the real site, both types confirmed:

| | Freehold (e.g. 39 Bairn Street) | Sectional (e.g. Uvongo Breeze section 2) |
|---|---|---|
| identity | LPI Code, Erf no | Scheme no + Section number |
| location extras | Township, Zoning, Zoning Description | Scheme name, Situated at, Flat number |
| size | Extent, Cadastral extent | Section extent |
| common to both | Deeds Office, Address, Suburb, Municipality, Province, GPS, Type, Usage | same |

A sectional property has NO LPI Code row and NO Erf no row. A freehold has no scheme/section rows.
Any identity, freshness or match mechanism that assumes LPI Code exists is broken for every
sectional property, and for every transition into or out of one.

### 6.2 "Situated at" is NOT the unit's erf number
On a sectional panel "Situated at: 658 UVONGO" is the erf the WHOLE SCHEME stands on, plus its
township. It must NEVER be stored as the unit's erf_number. A sectional unit has no erf of its own;
its identity is scheme number + section number. Storing the scheme erf per-unit collapses every unit
in a scheme onto one another (and onto any freehold of the same erf in the same suburb) through the
erf+suburb match strategy. This is the root of the "SS and FH get mixed up" class of bug.

### 6.3 Display format (Johan's words)
- Freehold: `Erf 234, 37 Bairn Road, Uvongo Beach` — or `37 Bairn Road, Erf 234, Uvongo Beach`.
  Either ordering is acceptable; pick one and be consistent.
- Sectional: `Section 2, Uvongo Breeze, 18 Lilliecrona Drive, Beacon Rocks`.
- Flat number: cmainfo never populates it in practice (Johan checked and could find no example).
  Do not build the display around it. Tolerate it if it ever appears; never require it.

### 6.4 Extents — FREEHOLD AND SECTIONAL ARE NOT THE SAME FIELD

Johan, verbatim and CORRECTING an earlier wrong entry in this spec:
"ss and fh have different places to capture it on the actual property record — and it should be
sent through that way to properties once we get there — and please don't treat them as the same."

- Freehold `Extent` = the ERF SIZE. Carries through to the property record's erf-size field.
- Sectional `Section extent` = the SECTION / UNIT size. It is NOT an erf size. It must NEVER be
  written into the erf-size field. It carries through to the property record's own sectional
  section-size field, which is a DIFFERENT field.
- The two must stay separate end to end: on the capture record, in the payload, and on the
  property record after promotion. Do not merge them into one "size" field for convenience,
  and do not let one fall back to the other when the property type's own field is empty.
- Freehold `Cadastral extent` — agents work with erf size, not cadastral. Keep it on the capture
  record where it already has a home; do NOT surface it as the property's size and do NOT add a
  new home for it on the property record yet. Johan: "if it gets requested we will need to
  upgrade property records as well to have a place to save cadastral, but not important now."
- A sectional property has no Extent and no Cadastral extent; a freehold has no Section extent.
  Absent is absent — never substitute across types.

### 6.5 Ingestion scope
ONLY the Property Information and Sale Information sections are ingested. Municipal Valuation,
Accommodation Residential/Commercial, Renovations, Servitudes, Vicinity Sales, Valuations and
Documents are deliberately OUT.

### 6.6 GPS
cmainfo renders longitude first then latitude, with hemisphere as a trailing letter and never a sign:
`30.391273°E   30.842466°S` means longitude +30.391273, latitude -30.842466. Assign by the direction
letter, never by position. If the letters are absent or ambiguous, store neither coordinate. A wrong
coordinate silently mis-matches two different properties; a missing one is recoverable.

---

## 7. Multi-owner / ownership-history capture — spec (2026-08-19, Johan)

**Status:** SPEC ONLY. Not built. Blocked on cc6's promote/ingest mapping commit landing first —
see §7.15. Extension-side parsing (splitting the raw cmainfo cell text, the ID-reveal fix) is
cc6's lane; this section defines the contract cc6 builds to and CoreX's own build (payload
handling, grouping/classification, storage, promote-time linking).

### 7.0 What triggered this

Johan read a real cmainfo panel directly (SEESKULP scheme 257/1987, Section 4, 60 Colin Drive,
Uvongo Beach) and found the Owner / Owner's ID / Title Deed fields are each a **semicolon-joined
list that aligns positionally across all three** — not the simple 1-or-2-owner case §2/§3 already
handle. The real panel data (used as the worked example throughout this section):

```
Owner:      1) WILKEN JOHAN 82.7397%              6) WILKEN JOHAN 1.9178%
            2) WILKEN HESTER JOHANNA CATHARINA     7) WILKEN HESTER JOHANNA CATHARINA
            3) WILKEN HESTER JOHANNA CATHARINA     8) FISHER RONALD GEORGE 98.0822%
            4) WILKEN JOHAN 15.3424%               9) FISHER LUCILLE 0.9589%
            5) STEVE DU TOIT TRUST-TRUSTEES 1.9178% 10) SEE-SKULP TRUST-TRUSTEES

Owner's ID: 1) 581111*******   4) 581111*******   7) 620117*******   10) (empty)
            2) 620117*******   5) IT 1203/91       8) 290527*******
            3) 620117*******   6) 581111*******    9) 340427*******

Title Deed: 1) ST39075/2003 82.7397%   6) ST6815/1993 1.9178%
            2) ST39075/2003           7) ST6815/1993
            3) ST39074/2003           8) ST4830/1993 98.0822%
            4) ST39074/2003 15.3424%  9) ST4830/1993 0.9589%
            5) ST39073/2003 1.9178%   10) ST257-4...

Panel also shows: Sale Price R 90 000, Sale Date 2003/01/15, Registered Date 2003/07/11.
```

Every claim below about what this data means was checked against the running code and, where
DB-checkable, against a real captured record (`tracked_properties` id 11565, the SEESKULP Section
1 record from the earlier read-only audit) before being written down — not taken on trust.

### 7.1 What the data actually represents (verified)

- **It is a transfer history, not a snapshot of current co-owners.** The three 2003 deeds
  (ST39075, ST39074, ST39073) sum to 82.7397 + 15.3424 + 1.9178 = **99.9999 ≈ 100%**, and 2003
  matches the panel's own Sale Date (2003/01/15) and Registered Date (2003/07/11) years. The two
  1993 deeds (ST6815, ST4830) are an earlier transfer — the Fishers sold in 1993. This is exactly
  Johan's read, confirmed by the arithmetic and the date cross-check, not merely asserted.
- **An entry sharing a deed with another entry, where one carries a percentage and the other does
  not, is a joint holding of that ONE share** — not two shares. Confirmed against §7.6's worked
  math: summing per-row instead of per-distinct-share pushes the current group to ~200%, which is
  wrong; summing per distinct share value within each deed gives ~100%, which matches the panel.
- **Owners are not always natural persons.** "STEVE DU TOIT TRUST-TRUSTEES" carries `IT 1203/91` —
  a trust registration number, not an SA ID. Checked against
  `app/Rules/SouthAfricanIdNumber::isValid()` (`app/Rules/SouthAfricanIdNumber.php:41`, requires
  exactly 13 digits) — `IT 1203/91` fails that check outright, confirming it was never meant to be
  read as an SA ID. Checked against `App\Support\OwnerEntityClassifier` — its `WEAK_COMPANY_PATTERNS`
  already includes `/\bTRUST\b/i` (`app/Support/OwnerEntityClassifier.php:63`), so a name-only
  classification of "STEVE DU TOIT TRUST-TRUSTEES" and "SEE-SKULP TRUST-TRUSTEES" as entities
  already works today with **zero changes** to that class — see §7.7.
- **The ID list is masked here** (`581111*******`). Checked against
  `public/chrome-extension/portal-capture/content-cmainfo.js:630` (`revealOwnerIdIfNeeded()`) —
  its own comment block (lines 605-628) already flags this as unconfirmed for anything beyond a
  single owner cell ("Not yet confirmed live: whether a second click re-masks the value"). The
  existing null-if-still-masked rule (`content-cmainfo.js:1286-1296`, "sending null instead of the
  partial value") is correct and must be kept — see §7.4. Whether the reveal path actually unmasks
  all ten positions here is cc6's problem to fix or report; this spec assumes it may not, and
  degrades safely either way (§7.4, §7.9).
- **The lists can carry a trailing empty entry** — the ID list here ends `... 340427******* ;`,
  i.e. a 10th, empty ID slot (which correctly pairs with position 10's `SEE-SKULP TRUST-TRUSTEES`,
  the one owner in this panel with no ID captured at all). Confirmed by counting: all three raw
  lists have exactly 10 slots once this trailing empty is counted as a slot, not dropped as noise
  — dropping it would misalign every position after it. §7.4 is explicit about this.
- **`tracked_property_owners` cannot currently store any of this.** Checked the live schema
  (`database/schema/mysql-schema.sql:12559-12574`): columns are `id, tracked_property_id,
  contact_id, name, id_number, id_type, is_primary, role, created_at, updated_at`. No share, no
  deed reference, no current/past flag. §7.10 is the smallest additive migration that adds exactly
  those three things. **Entity-vs-person needs no new column** — `contacts.contact_kind` /
  `entity_name` / `entity_reg_no` (`database/schema/mysql-schema.sql:3841-3844`) already exist and
  are already used by `Api\DeedsCaptureController::resolveEntityOwnerContact()`
  (`app/Http/Controllers/Api/DeedsCaptureController.php:425-456`) for exactly this purpose.

### 7.2 Contract boundary — who builds what

- **cc6 (extension, `public/chrome-extension/portal-capture/content-cmainfo.js`):** fix/confirm
  `revealOwnerIdIfNeeded()` unmasks every owner-ID position, not just the first; capture the three
  raw cell strings (Owner, Owner's ID, Title Deed) **verbatim, unsplit** when the Owner cell
  contains more than one entry (i.e. contains a `;`); send them as the new `ownership_history_raw`
  payload object (§7.3). No grouping, no year math, no joint-detection, no share summing in JS —
  none of that is cc6's job under this spec.
- **CoreX (this spec, `app/Http/Controllers/Api/DeedsCaptureController.php` + new
  `App\Services\Prospecting\OwnershipHistoryParser` service + migrations):** all splitting,
  pairing, masking, classification, grouping, date cross-checking, joint-detection, summing, and
  fail-closed decisions. Business logic this consequential (Johan: "wrong owners on a property
  record are worse than none") belongs in one server-side, PHPUnit-testable place — not
  duplicated or drifted between JS and PHP. This also means the server, not the extension, is the
  authoritative check for the "list-length mismatch → fail closed" rule (§7.9): CoreX receives the
  raw strings and does its own count, independent of whatever the extension's own read of the page
  produced. Matches this codebase's existing pattern of the server being the authoritative gate
  (e.g. `TvaContactCaptureController.php:92`, the company-scraping block re-checked server-side
  even though the extension already filters client-side).
- **Why not extend the existing `owners[]` array instead:** `owners[]` (§2, §3) is share-blind by
  design and is working, live, unmodified traffic for the common single/simple-multi-owner case.
  Rebuilding its contract risks that live path. `ownership_history_raw` is a **new, optional**
  payload field, present only when cmainfo's Owner cell has more than one `;`-separated entry.
  When present, it is authoritative for ownership on that capture and `owners[]` is ignored for
  ownership purposes (the existing `owners[]` array may still be sent for backward compatibility
  with any other consumer, but CoreX's ownership-linking logic reads only from
  `ownership_history_raw` when it exists). When absent, today's `owners[]` path runs completely
  unchanged. Zero risk to existing captures.

### 7.3 New payload field

Added to each entry in `POST /api/v1/deeds-capture`'s `captures[]` (§2), alongside the existing
`property` / `owners` / `sale` objects:

```
"ownership_history_raw": {                 // OPTIONAL — present only when the Owner cell has >1 entry
  "owner_names":  "WILKEN JOHAN 82.7397% ; WILKEN HESTER JOHANNA CATHARINA ; ... ; SEE-SKULP TRUST-TRUSTEES",
  "owner_ids":    "581111******* ; 620117******* ; ... ; 340427******* ;",
  "title_deeds":  "ST39075/2003 82.7397% ; ST39075/2003 ; ... ; ST257-4...",
}
```

All three values are the raw cell text, verbatim, unsplit, un-stripped — exactly what cmainfo
rendered (masked IDs included; CoreX does the un-masking check, §7.4). `sale.sale_date` and
`sale.registered_date` already exist in the contract (§2) and are reused unchanged for the
current-vs-past date cross-check (§7.5) — no new field needed there.

Validation addition to `Api\DeedsCaptureController::store()`
(`app/Http/Controllers/Api/DeedsCaptureController.php:78-119`):
```
'captures.*.ownership_history_raw'               => 'nullable|array',
'captures.*.ownership_history_raw.owner_names'   => 'nullable|string|max:4000',
'captures.*.ownership_history_raw.owner_ids'     => 'nullable|string|max:2000',
'captures.*.ownership_history_raw.title_deeds'   => 'nullable|string|max:2000',
```

### 7.4 Parse contract

Implemented once, in the new `OwnershipHistoryParser` service, called from `ingestOne()` only when
`ownership_history_raw` is present:

1. **Split** each of the three raw strings on `;`, trim each segment.
2. **Drop exactly one trailing empty segment per list, if present** (the "ends with ` ;`" case) —
   this is a real, expected slot (§7.1's position-10 example), not noise; do not drop more than
   one trailing empty, and do not drop empties that aren't trailing (an empty in the middle is a
   genuine gap and stays as an empty segment, preserving position).
3. **Length check — fail closed on ownership if unequal.** After step 2, `count(owner_names) ===
   count(owner_ids) === count(title_deeds)` must hold. If not: **do not guess the pairing.** Skip
   §7.5-§7.8 entirely for this capture; the property itself still captures normally via the
   existing `matchOrCreate()` path (§7.9 defines exactly what does and doesn't happen).
4. **Per position, extract:**
   - `name` — strip a trailing share token (reuse the existing regex,
     `content-cmainfo.js:1319`'s `OWNERSHIP_SHARE_TOKEN`, ported to PHP:
     `/^(\d{1,3}([.,]\d+)?%|\d+\/\d+)$/`) from the end of the owner-name segment.
   - `share_pct` — the trailing share token itself, parsed to a float, from **either** the
     owner-name segment or the title-deed segment (both carry it redundantly in the real data —
     §7.1's table shows every row where one has it, so does the other). If they disagree (a
     malformed page render), treat this position's `share_pct` as unrecoverable — leave it null
     rather than guessing which one is right; §7.9 covers the downstream effect.
   - `id_raw` — the ID segment, whitespace-collapsed. **If it contains `*`, set `id_number` to
     null** — this is the existing, correct rule (`content-cmainfo.js:1286-1296`) re-applied
     server-side, since CoreX is now the one doing the splitting for this path and cannot assume
     the extension's own single-value check ran across every position of a combined raw string.
   - `id_kind` — classify `id_raw` (when not masked/empty): exactly 13 digits →
     `sa_id` (still passed through `SouthAfricanIdNumber::isValid()` the normal way downstream —
     an invalid-but-present SA ID is still a person's ID, not a company, per
     `OwnerEntityClassifier`'s existing step 3); matches `/^IT\s*\d+\/\d{2,4}$/i` → `trust_reg`
     (**new** id_type value, §7.7); matches the existing CIPC shape `\d{4}\/\d{6}\/\d{2}` →
     `company_reg` (existing value, unchanged); anything else → left as a raw, untyped string (not
     discarded — an unrecognised ID shape still dedupes correctly at the raw-string level, it just
     doesn't get a confident type).
   - `deed_reference` — the title-deed segment with its trailing share token (if any) stripped,
     e.g. `ST39075/2003`.
   - `deed_year` — parsed from `deed_reference` via `/\/(\d{4})$/`. **Only a 4-digit year counts.**
     A deed segment that doesn't match this shape at all (§7.1's position 10, `ST257-4...`) is
     **excluded from grouping** (§7.5) — that one owner is still captured as a row (§7.9, "row-level
     exclusion"), just with no deed/year/current-past classification, logged as a warning, never
     silently dropped from the capture entirely.

### 7.5 Current-vs-past classification

1. Group the classifiable positions (i.e. those with a valid `deed_year`, §7.4) by `deed_reference`.
2. For each **distinct `deed_year`** present, that year is a *generation*.
3. The **current generation** is the one whose year equals the year of `sale.registered_date` if
   present, else `sale.sale_date`. (Registered date is the legally definitive "ownership took
   effect" date in SA conveyancing; sale date is the fallback when registration isn't recorded.)
4. **If no generation's year matches either date's year → fail closed on ownership** (§7.9,
   `date_mismatch`). This is deliberately strict per Johan's instruction — do not pick the latest
   year as a guess when the panel disagrees with all of them.
5. Every position in the current generation → `ownership_status = 'current'`. Every position in
   every other generation → `ownership_status = 'past'`. A position excluded at step 4 of §7.4
   (unparseable deed) gets **no** `ownership_status` — it is captured with `deed_reference = null`,
   `ownership_status = null`, and is never linked at promote time either way (§7.11) since there's
   nothing to classify it by.

### 7.6 Joint-holder rule and the share-sum check (worked example)

Within a deed-reference group (all positions sharing the same `deed_reference`):

- Collect the **distinct non-null `share_pct` values** present in that group.
- **Exactly one distinct value** → every position in the group is a joint holder of that one
  share; propagate that value onto every row's stored `share_pct` (so Hester's row for
  `ST39075/2003`, which arrived with no percentage of its own, is stored as `82.7397`, matching
  Johan's — the couple jointly hold one 82.7397% interest, not two).
- **More than one distinct value** → each position keeps its own value; these are separately
  apportioned co-owners on the same instrument, not a joint holding (§7.1's Fisher example:
  `ST4830/1993` carries 98.0822% and 0.9589% as two distinct people's separate shares).
- **The current-group total = the sum of each current-generation deed's own contribution**, where
  a deed's contribution is the sum of ITS OWN distinct non-null share values (from the rule above)
  — summing **per distinct value per deed**, never per row. This is what makes "the percentage
  must not be double-counted" true by construction rather than by a special joint-detection branch:

  | Deed (current gen.) | Rows | Distinct values | Deed's contribution |
  |---|---|---|---|
  | ST39075/2003 | Johan 82.7397%, Hester (blank→82.7397%) | {82.7397} | 82.7397 |
  | ST39074/2003 | Hester (blank→15.3424%), Johan 15.3424% | {15.3424} | 15.3424 |
  | ST39073/2003 | Steve du Toit Trust 1.9178% | {1.9178} | 1.9178 |
  | **Total** | | | **99.9999 ≈ 100%** ✓ |

  (Summing per-row instead — 82.7397+82.7397+15.3424+15.3424+1.9178 = 198.6720 — is exactly the
  double-count Johan flagged. This table is the regression case for that.)
- **Tolerance:** current-group total within **99.5%–100.5%** passes silently. Outside that band →
  §7.9 `share_mismatch` (a **warning**, not a fail-closed — the current owners are still captured
  and linked; the capture is flagged for a human look).
- This check applies **only to the current generation.** Past-generation totals are informational
  and never validated or gated (Johan: "sum the current group's shares" — past groups are history,
  not a thing that needs to add up for today's ownership to be correct).

### 7.7 Entity owners

- `OwnerEntityClassifier::isEntity()` (`app/Support/OwnerEntityClassifier.php:75`) already
  classifies "…TRUST-TRUSTEES" names as entities via its existing `WEAK_COMPANY_PATTERNS` (`TRUST`)
  — **confirmed, no code change needed there** for name-based detection.
  Two additions are needed alongside it:
  1. **New `id_type` value `trust_reg`** — added to the validation rule (§7.3) and to
     `OwnerEntityClassifier::isEntity()`'s step 1 (the explicit-signal check currently reading
     `$idType === 'company_reg' || self::looksLikeCipcReg($id)`, extend to also accept
     `$idType === 'trust_reg'`) so a trust is recognised as an entity from its ID shape alone, not
     only from its name — defence in depth for a trust name that doesn't happen to contain the
     literal word "TRUST".
  2. **Routing:** exactly as `resolveEntityOwnerContact()` already does for `company_reg`
     (`app/Http/Controllers/Api/DeedsCaptureController.php:425-456`) — the trust registration
     number (`IT 1203/91`) is stored on the linked Contact as `entity_reg_no`, **never** as
     `id_number`/`id_type='sa_id'`. No new Contact-table column — `entity_reg_no` already exists
     and is already entity-registration-number-shaped (a free varchar, no CIPC-only format
     constraint). On `tracked_property_owners`, `id_number` continues to carry the raw registration
     string (matching the existing, unchanged behaviour for `company_reg` owners on that table —
     see §7.10's note on why this isn't being "fixed" here).
- **CMA gives no trustee/director names for a trust owner** — per Johan, do not invent them. The
  entity Contact is created exactly as `resolveEntityOwnerContact()` already does for a company:
  name + registration number only, no represented-natural-person link. That link (a future
  trustee/director capture) is the same deferred seam already documented at
  `app/Http/Controllers/Api/DeedsCaptureController.php:362-373` for companies — trusts slot into
  the identical seam, not a new one.

### 7.8 Dedupe

No new dedupe logic — reuse `resolveOwnerContact()` / `resolveEntityOwnerContact()`
(`app/Http/Controllers/Api/DeedsCaptureController.php:363-456`) exactly as they run today, called
once per §7.4-parsed position, in position order. Because each call performs an immediate
`Contact::create()` or finds-and-returns an existing match via
`ContactDuplicateService::findDuplicatesForIdentifiers()` (a live DB read, not a batched/deferred
write), Johan Wilken's three positions (1, 4, 6) naturally resolve to **the same `contact_id`** —
the second and third calls find the contact the first call just created, within the same request/
transaction. This is the existing mechanism, unchanged; §7's job is only to make sure it gets
called once per parsed position (current AND past — a past owner is still a real contact, §7.11)
with the position's own `name` / `id_raw` / `id_kind`, the same inputs it already accepts today.
An owner position with a null `id_number` (still masked after reveal, or a genuinely ID-less
entry like §7.1's position 10) does not dedupe against anything and always inserts a fresh
contact — this is already today's behaviour for ID-less owners (§3) and is not changed by this
spec.

### 7.9 Fail-closed / warning cases — enumerated

| # | Condition | Action | Stored on `tracked_properties` |
|---|---|---|---|
| 1 | `owner_names` / `owner_ids` / `title_deeds` segment counts unequal after §7.4 step 2 | **Fail closed.** No `tracked_property_owners` rows created for this capture. `owner_contact_id` left null. Property/facts capture proceeds normally (§7.1's last point). | `ownership_parse_status='failed'`, `ownership_parse_note='Owner/ID/Deed list lengths did not match (owner=<n>, id=<n>, deed=<n>) — captured without owners; needs manual entry.'` |
| 2 | No deed-year generation matches `sale.registered_date` (or `sale.sale_date` fallback) year | **Fail closed**, same as #1. | `ownership_parse_status='failed'`, `ownership_parse_note='Deed-year groups (<years found>) didn't match the panel's sale/registered date (<year>) — captured without owners; needs manual entry.'` |
| 3 | Current-generation share total outside 99.5%–100.5% | **Warning only** — current owners ARE captured and linked normally (§7.11). | `ownership_parse_status='warning'`, `ownership_parse_note='Current ownership shares summed to <total>%, not ~100% — review before relying on the split.'` |
| 4 | One or more positions have an unparseable `deed_reference` (§7.4 step 4's last bullet) | **Row-level exclusion only** — that position is still captured as an owner row (name/ID/dedupe all still apply) but with `deed_reference=null`, `ownership_status=null`, and is excluded from grouping/summing and from promote-time linking (§7.11) since it can't be classified current or past. Does **not** fail the rest of the capture. | `ownership_parse_status` downgraded to `'warning'` if it was `'ok'`; note appended: `'<n> owner position(s) had a deed reference that didn't match the expected format and were excluded from ownership classification: <names>.'` |
| 5 | An ID segment still contains `*` after §7.4's masking check | **Absorbed, not fail-closed** — matches existing single-owner behaviour (§3): that position's `id_number` is null, it still captures and links as an owner (current or past, per its `ownership_status`), just without ID-based dedupe for that position. | No change to `ownership_parse_status` — this is normal, expected, already-handled behaviour, not a new failure mode. |
| 6 | `ownership_history_raw` absent entirely | Not applicable — §7 doesn't run; today's `owners[]` path (§2, §3) runs completely unchanged. | `ownership_parse_status` stays at its column default `'ok'` (nothing to report). |

Cases 1 and 2 are the two Johan named explicitly ("do not guess the pairing… fail closed" /
"if those two signals disagree… fail closed"). Cases 4 and 5 are this spec's own calls, made
because a single malformed row or a single still-masked ID inside an otherwise clean, equal-length
dataset is a materially different, narrower failure than the two structural ones Johan named —
nuking the whole ownership block over one bad cell would contradict the same "worse than none"
principle in the other direction, discarding nine good rows to protect against one bad one. Both
are stated here precisely so they're visible decisions, not silent ones.

### 7.10 Data model changes

**`tracked_property_owners`** — smallest additive migration covering the three things Johan asked
this table be checked against (share, deed reference, current-vs-past); entity-vs-person needs
none (already on the linked Contact, §7.1's last point):

```php
Schema::table('tracked_property_owners', function (Blueprint $table) {
    $table->decimal('ownership_share_pct', 7, 4)->nullable()->after('id_type');
    $table->string('deed_reference', 100)->nullable()->after('ownership_share_pct');
    $table->string('ownership_status', 20)->default('current')->after('deed_reference'); // 'current' | 'past'
});
```

Existing rows default to `ownership_status='current'` — correct and harmless: every prior capture
was always single-generation (no transfer-history parsing existed before this spec), so "current"
is the accurate backfill for 100% of existing data, no data migration script needed. Model
constants to add on `TrackedPropertyOwner`
(`app/Models/Prospecting/TrackedPropertyOwner.php:33-34`, next to the existing `ROLE_OWNER` /
`ROLE_DIRECTOR`): `OWNERSHIP_CURRENT = 'current'`, `OWNERSHIP_PAST = 'past'`.

**`tracked_properties`** — two columns to carry the §7.9 outcome where an agent (eventually) sees
it; this spec only defines storage, not the UI surfacing (that's the deferred deeds-capture screen
work, §7.15):

```php
Schema::table('tracked_properties', function (Blueprint $table) {
    $table->string('ownership_parse_status', 20)->default('ok')->after('deeds_captured_at'); // 'ok' | 'warning' | 'failed'
    $table->text('ownership_parse_note')->nullable()->after('ownership_parse_status');
});
```

**`contacts`** — no migration. `id_type` is a free `varchar(20)` (`database/schema/
mysql-schema.sql:3841`), not a DB-level enum — `trust_reg` is a new *value*, not a new *column*,
enforced only at the application validation layer (§7.3, §7.7).

**Note on `tracked_property_owners.id_number` for entity owners:** today, `syncOwners()`
(`app/Http/Controllers/Api/DeedsCaptureController.php:342-361`) writes whatever raw ID string was
resolved into that column regardless of person/entity, even though the linked Contact correctly
routes an entity's registration number to `entity_reg_no` instead. This is existing, pre-this-spec
behaviour (already true for `company_reg` owners) — §7 keeps it as-is for `trust_reg` too, for
consistency with the established pattern. Not a new inconsistency introduced here, and not this
task's scope to fix.

### 7.11 How promote() creates and links contacts

No change to the promote/ingest **workflow** itself (that's the separate, still-queued
two-step-workflow rework from the earlier audit report,
`.ai/audits/2026-08-19-deeds-capture-workflow-and-refresh-plan.md`) — only to what the existing
owner-linking loop reads. Today's loop
(`app/Http/Controllers/CoreX/DeedsCaptureController.php:374-383`):

```php
$ownerContactIds = $trackedProperty->owners()->pluck('contact_id')->filter()->unique();
...
foreach ($ownerContactIds as $contactId) {
    DB::table('contact_property')->updateOrInsert(
        ['contact_id' => $contactId, 'property_id' => $property->id],
        ['role' => 'owner', 'updated_at' => now(), 'created_at' => now()],
    );
}
```

changes to filter on `ownership_status`:

```php
$ownerContactIds = $trackedProperty->owners()
    ->where('ownership_status', TrackedPropertyOwner::OWNERSHIP_CURRENT)
    ->pluck('contact_id')->filter()->unique();
```

- **Current owners** → linked via `contact_property` (`role='owner'`) exactly as today — this is
  the only branch that produces that link.
- **Past owners** → their Contact rows exist (created at capture time, §7.8, same as current
  owners) and their `tracked_property_owners` rows exist with `ownership_status='past'`, but they
  **never** appear in the query above and therefore **never** get a `contact_property` row. This
  is the literal mechanism behind Johan's decision #2 ("NOT owners of it") — not a filter applied
  somewhere in the UI that could be bypassed or forgotten, but a structural absence of the only
  link that would make them show up as an owner anywhere in CoreX. An agent working the property
  record has no path to "an agent must never be able to mistake a 1993 seller for the person to
  phone" being violated, because the seller was never linked as an owner in the first place.
- Rows with `ownership_status=null` (the §7.9 case-4 row-level exclusion) are likewise never
  linked — same query, same mechanism, no special-case code needed.
- For **display**, "who used to own this" is answered by querying
  `tracked_property_owners->where('tracked_property_id', $tp->id)->where('ownership_status',
  'past')` directly — that's a read the future deeds-capture-screen work can add; not built here.

### 7.12 Worked example — traced end to end

Using §7.0's real SEESKULP Section 4 data:

| Position | Name | ID (raw) | ID kind | Deed | Share | Generation | `ownership_status` |
|---|---|---|---|---|---|---|---|
| 1 | Wilken Johan | 581111\*\*\*\*\*\*\* → **null** (masked) | — | ST39075/2003 | 82.7397 | 2003 | current |
| 2 | Wilken Hester J.C. | 620117\*\*\*\*\*\*\* → **null** | — | ST39075/2003 | 82.7397 (propagated) | 2003 | current |
| 3 | Wilken Hester J.C. | null | — | ST39074/2003 | 15.3424 (propagated) | 2003 | current |
| 4 | Wilken Johan | null | — | ST39074/2003 | 15.3424 | 2003 | current |
| 5 | Steve du Toit Trust-Trustees | IT 1203/91 | `trust_reg` → entity | ST39073/2003 | 1.9178 | 2003 | current |
| 6 | Wilken Johan | null | — | ST6815/1993 | 1.9178 | 1993 | past |
| 7 | Wilken Hester J.C. | null | — | ST6815/1993 | 1.9178 (propagated) | 1993 | past |
| 8 | Fisher Ronald George | 290527\*\*\*\*\*\*\* → **null** | — | ST4830/1993 | 98.0822 | 1993 | past |
| 9 | Fisher Lucille | 340427\*\*\*\*\*\*\* → **null** | — | ST4830/1993 | 0.9589 | 1993 | past |
| 10 | See-Skulp Trust-Trustees | (empty) | — (name-only entity via TRUST marker) | unparseable (`ST257-4...`) | null | — | **null** (excluded, §7.9 case 4) |

(All IDs shown masked here per §7.0's real data — every one nulls out under §7.4's rule unless
cc6's reveal fix genuinely unmasks all ten positions live. The classification/grouping/summing
above does not depend on IDs being present — only on names, shares, and deed references — so this
capture proceeds and classifies correctly even in the worst case where none of the IDs unmask.
Dedupe (§7.8) is the only thing that degrades: without IDs, "Wilken Johan" positions 1/4/6 dedupe
by exact name-string match within `resolveOwnerContact()`'s existing fallback path instead of by
ID — already today's behaviour for any ID-less owner, not new to this spec.)

Result: 3 current `tracked_property_owners` rows get linked to the property as owners on promote
(Johan Wilken, Hester Wilken, Steve du Toit Trust); 4 past rows are captured as contacts and
history but never linked as owners (Johan Wilken and Hester Wilken again — same contacts, extra
rows for the 1993 deed — plus Ronald and Lucille Fisher); 1 row (See-Skulp Trust-Trustees) is
captured with no classification, logged as a warning, and is neither current nor past.
`ownership_parse_status='warning'` (from case 4 — the one unparseable deed), current-group total
99.9999% (within tolerance, no case-3 warning stacked on top).

### 7.13 Acceptance criteria

- [ ] A capture whose Owner cell has no `;` behaves identically to today — zero change to the
      `owners[]` path, verified by re-running the existing deeds-capture test suite unchanged.
- [ ] The §7.12 worked example, submitted as a real payload, produces exactly the table above:
      3 current owners linked to the promoted property, 4 past-owner contacts created but not
      linked, 1 excluded row, `ownership_parse_status='warning'` with a note naming the excluded
      row.
- [ ] List-length mismatch (e.g. one list short by one entry after trailing-empty drop) produces
      `ownership_parse_status='failed'`, zero `tracked_property_owners` rows, and the property
      still captures via `matchOrCreate()`.
- [ ] Deed-year vs sale/registered-date disagreement (a synthetic case: all deeds dated 1993, but
      `sale.registered_date` is 2010) produces the same `failed` outcome.
- [ ] A current-group share total of, say, 60% (synthetic under-capture) produces `warning`, not
      `failed` — current owners are still linked.
- [ ] Johan Wilken (or any name appearing 3×) resolves to exactly one `contacts` row and exactly
      three `tracked_property_owners` rows (one per deed position), each with the correct
      `deed_reference`/`ownership_share_pct`/`ownership_status` for that position.
- [ ] A trust owner (`IT 1203/91`) creates a Contact with `contact_kind='entity'`,
      `entity_reg_no='IT 1203/91'`, and `id_number` **not** set to that value.
- [ ] A past owner never appears in `contact_property` for the promoted property (query
      `contact_property` after promote, assert the Fishers/past-Wilken-rows are absent).
- [ ] Every masked ID (`*` present) resolves to `id_number = null`, never a partial value, for
      every position — not just the first.
- [ ] `php -l` clean on every changed file; single most-relevant test file run per
      CLAUDE.md Non-negotiable #13 (no broad suite without Johan's go-ahead).

### 7.14 Explicitly out of scope / deferred

- Surfacing `ownership_parse_status`/`ownership_parse_note` on the Deeds Capture screen itself —
  storage only here; display is deferred to (and should be batched with) the already-queued
  two-step-workflow rework (`.ai/audits/2026-08-19-deeds-capture-workflow-and-refresh-plan.md`),
  since both touch the same card markup.
- Fixing `tracked_properties.title_deed_number` (still a single raw-string dump, §Problem 4 of the
  earlier audit) — left exactly as-is; not this task.
- Trustee/director names for a trust owner — CMA doesn't give them; not invented (Johan's decision
  #3).
- Century-guessing for a 2-digit deed year — a `deed_reference` without a clean 4-digit year is
  treated as unparseable (§7.4), not heuristically resolved.
- Chrome extension changes of any kind — cc6's lane (§7.2).

### 7.15 Files to create or modify (when build is authorised)

| File | Change | Gate |
|---|---|---|
| `database/migrations/xxxx_add_ownership_fields_to_tracked_property_owners.php` | new migration, §7.10 | Clear to build any time — new table, no collision |
| `database/migrations/xxxx_add_ownership_parse_status_to_tracked_properties.php` | new migration, §7.10 | Clear to build any time |
| `app/Models/Prospecting/TrackedPropertyOwner.php` | add `ownership_share_pct`, `deed_reference`, `ownership_status` to `$fillable`; add the two constants | Clear to build any time |
| `app/Support/OwnerEntityClassifier.php` | extend step 1 to accept `idType === 'trust_reg'` | Clear to build any time — additive, no existing behaviour changes |
| `app/Services/Prospecting/OwnershipHistoryParser.php` | **new** service, §7.4-§7.9 | Clear to build any time — new file |
| `app/Http/Controllers/Api/DeedsCaptureController.php` | add `ownership_history_raw` validation (§7.3); call the new parser from `ingestOne()`; extend `syncOwners()` to write the three new columns | **Wait for Johan's go-ahead** — this is squarely "the deeds-capture controller" he named; confirm it's clear of cc6's concurrent edit before touching |
| `app/Http/Controllers/CoreX/DeedsCaptureController.php` | `promote()`'s owner-linking loop, §7.11 | **Wait for Johan's go-ahead** — same file the queued workflow-rework and cc6's mapping work both touch |
| `database/schema/mysql-schema.sql` | re-dump after the two new migrations land (CLAUDE.md #12a) | After migrations, same session |
| `tests/Feature/...DeedsCapture...` | new tests per §7.13 | Alongside the controller/service changes |

Nothing in this list has been touched. This section is the spec only, per Johan's instruction —
build starts when he says so.

---

## 8. Screen layout — Queue + inspector (Andre, 2026-09-13)

**Status:** Built on branch `today-day-timeline` (view only — no controller, route, model or data
change). Not deployed, not promoted.

### 8.1 What was wrong
The screen was a banner, a filter bar, and up to 30 tall cards stacked underneath. Each card carried
the property, its owners/directors/previous owners, an owner-conflict box, the side-by-side comparison
table, the "what this capture changed" log and the Virtual Agent numbers — so one page ran for many
screens and the agent lost their place after every action (each action is a PRG reload).

### 8.2 What it is now
The page never scrolls. Five layouts were drafted on a design canvas
(https://claude.ai/code/artifact/5680d913-6884-4b5f-aba7-aef3cd3c3023); Andre chose **1 · Queue +
inspector** and asked for the property header to use the full inspector width ("stretch it out,
otherwise it is very squished").

```
┌ page banner (contacts LIST header, corex-page-banner, full-bleed) ───────────┐  frozen
│ Deeds Capture + one line         Own/Branch/All · search · agent · Search    │
├ flash (success / info) ──────────────────────────────────────────────────────┤  frozen
├──────────────┬───────────────────────────────────────────────────────────────┤
│ QUEUE 380px  │ INSPECTOR (selected capture)                                  │
│ "N waiting"  │ header, full width, frozen: PROPERTY eyebrow → address (lg,   │
│ + count tags │ bold) → scraped by → status sentence → why → take-rule line → │
│              │ "No — different property" → details lines → ACTION ROW        │
│ row per      ├───────────────────────────────────────────────────────────────┤
│ capture:     │ body, scrolls inside itself: owners / directors / previous    │
│ address      │ owners / owner conflict → comparison panel (with its Same /   │
│ owner ·      │ Different buttons) → parse status → what this capture changed │
│ scraped by   │ → Virtual Agent numbers                                       │
│ + one tag    │                                                               │
│ (scrolls)    │                                                               │
│ pagination   │                                                               │
└──────────────┴───────────────────────────────────────────────────────────────┘
```

- **Frame:** `w-full h-full flex flex-col` inside the layout's `<main id="appScroll">`, the same
  frozen-header / inner-scroll pattern as the contact page (AT-393). The queue list carries
  `data-scroll-region` so the global scroll preserve/restore keeps the agent's place in the queue
  across the PRG reload every action causes. Both scroll regions (queue, inspector body) use
  `.corex-brand-scroll` — the sidebar's slim brand-tinted scrollbar (Andre, 2026-09-13).
- **Header (Andre, 2026-09-13: "match the contact page header, header and filter being one"):**
  the contacts LIST page header (`corex.contacts.index`) — the flat, full-bleed `corex-page-banner`
  with the title + one line of context on the left — and the whole filter form (Own/Branch/All,
  search, agent picker, Search, Clear) on the right of that same banner, not in its own card. NOT
  the contact *record* header (`corex.contacts._header`, the surface card with a facts strip) — a
  first cut used that and was reverted the same day.
- **Queue row** = headline (sectional headline swap from §6.3 unchanged; wraps, never truncates),
  `first owner [+ N more] · scraped by <name>`, and ONE tag: `Blocked` (take rule `active_blocked`),
  `Owner differs` (open owner conflict), `Same property?` (confident match to stock), `Possible match`
  (ambiguous / GPS-only candidates), `On file` (tracked property predates this capture), else `New`.
  Derived from the same flags the inspector header prints in full, in severity order, so row and
  detail can never disagree. Queue header shows the total waiting and non-zero group counts
  (new / matches / need a look / numbers).
- **Standalone TVA captures** (no matching property) queue up after the properties as their own
  rows + panels, tagged `Numbers`; the standalone Ingest form is unchanged.
- **Inspector** = the existing card, re-flowed. Every capture's panel is rendered (hidden via
  `x-show` until selected) so every form, every `form=""` attribute wiring TVA tick-boxes into the
  promote form, and every `confirm()` keep working exactly as before. The right-hand "Action" column
  became the action row under the address; nothing else in the card changed.
- **Selection** lives in Alpine (`selected`), keyed `tp-<id>` / `tva-<id>`, remembered per
  path+query in `sessionStorage`. On any form submit inside the inspector (not a cancelled
  `confirm()`), the NEXT row's id is remembered so the reload lands on the next capture rather than
  back at the top. A remembered id that no longer exists falls back to the first row.
- **Empty state** renders only when there are neither captures nor standalone TVA captures.
- **Mobile:** panes stack (`flex-col lg:flex-row`), queue capped at 40vh.

### 8.3 Deliberately unchanged
Controller, routes, permissions, pagination (30), every action, every label Johan set, every
comment explaining why. No new setting → nothing for the Setup Wizard (CLAUDE.md #10a).

### 8.4 Acceptance
- [x] Page does not scroll at 1440×900; queue and inspector body scroll inside themselves.
- [x] Selecting a row swaps the inspector; the address sits on its own full-width line.
- [x] After Remove / Add / Same / Different, the reload lands on the next row (or the same row if it
      is still there), never at the top.
- [x] `tests/Feature/Prospecting/DeedsCaptureDismissTest.php` green (renders both a property row and
      a standalone TVA row, then asserts they vanish after dismiss).

---

## 9. Pre-check before the paid ID reveal (2026-10-05, Johan — built)

**Status:** Built, QA1-pending Johan's go-ahead. Branch
`cma-deeds-precheck-2026-10-05`. Prior investigation:
`/tmp/cma-extension-precheck-investigation-2026-10-05.md`.

### 9.0 The problem and the fact that shaped the fix

Agencies pay CMA Info **R3 per owner ID, at the moment that ID is revealed** on the
property panel (clicking the "eye" icon that unmasks `560728*******`). Everything else
on the panel — Property Information (LPI Code, Erf no, Scheme no/Section number,
Address, Suburb, Municipality, GPS, extents, Type) and the non-ID parts of Sale
Information (Owner **name**, Title Deed **number**, Sale Price/Date, Bond Holder/Amount)
— is free.

Before this build, `content-cmainfo.js`'s `extractSaleInformation()` called
`revealOwnerIdIfNeeded()` — the function that synthetically clicks every visible
`i.fa.fa-eye` icon — **unconditionally**, as its first line, every time the agent
pressed the single "Capture to CoreX" button. The agent never touched the reveal
themselves; the extension did it automatically, with no gate, before CoreX's own
duplicate check (which only ran AFTER the paid POST had already landed) ever had a
chance to say "we already have this."

### 9.1 What changed

`content-cmainfo.js`'s `onCaptureClick()` now runs in two phases:

1. **Free phase** — `ensureSectionExpanded` + `extractPropertyInformation()` (unchanged,
   already free) + a **new** `extractFreeSaleFields()` that reads Owner (name) and Title
   Deed from the Sale Information panel **without** calling `revealOwnerIdIfNeeded()`.
   `buildPreCheckPayload()` (new) maps these into the same `property`/`owners` shape
   `buildDeedsCapturePayload()` already uses, plus `source_ref` (via the existing,
   unchanged `buildSourceRef()`).
2. **Gate** — `POST /api/v1/deeds-capture/check-duplicate` (new). Response `status`:
   - `not_found` → proceeds straight into the existing `extractDeed()` →
     `revealOwnerIdIfNeeded()` → `buildDeedsCapturePayload()` → `POST /api/v1/deeds-capture`
     path, byte-for-byte unchanged from before this build.
   - `exists` / `possible_match` → a new on-page banner (mirrors the P24/PP popup's
     `duplicateWarning` Confirm/Cancel pattern) shows what matched, who captured it and
     when, and three actions: **Open in CoreX** / **Pull anyway** / **Cancel**. The
     reveal only ever happens after "Pull anyway".
   - Request fails/times out → "Could not check CoreX — Try again / Pull anyway". Never
     reveals on a failure either; "Try again" re-sends the same pre-check payload
     (no re-extraction needed — the free phase already ran once).

Multi-owner properties: ONE pre-check call per property (all free owner names sent
together in the `owners[]` array), before any ID anywhere on that property is opened.

### 9.2 New endpoint — `POST /api/v1/deeds-capture/check-duplicate`

`App\Http\Controllers\Api\DeedsCaptureController::checkDuplicate()` — Sanctum-authed,
same `effectiveAgencyId()` resolution as `store()`. Side-effect-free: wraps
`TrackedPropertyMatchOrCreateService::findExistingMatch()` (already existed, already
read-only) via a private `buildFreePropertyFacts()` mapping — a deliberate, separate
duplicate of `ingestOne()`'s own `$facts` mapping (property-only subset) so this new
endpoint can never change `store()`'s existing, audited behaviour. No new matching
*strategy* was added to the resolver.

Request: `source_ref` (optional, built client-side exactly like a real capture's would
be), `property.*` (the free Property Information fields), `owners[]` (name + optional
`id_type` hint — no ID, since none exists yet at this point).

Response:
```
{ "status": "exists" | "possible_match" | "not_found",
  "matches": [ { tracked_property_id, address, captured_by, captured_at, captured_at_human,
                 reason, match_type: "structural"|"owner_name", confident, matched_fields, deeplink } ] }
```
`status` is `exists` only when a *confident* structural match is found (every
`resolveMatch()` strategy except the untiebroken Strategy-5 tie); otherwise
`possible_match` when anything (structural-but-uncertain, or the owner-name signal below)
was found; `not_found` when nothing was.

**Cross-agency:** never revealed — `$agencyId` comes from the authenticated token exactly
like `store()`, and every query inside the matcher is filtered to that one agency; there
is no broader lookup to opt out of (proven by test, §9.5).

### 9.3 New matching signal — owner name + address (Johan's addition, item 2)

`TrackedPropertyMatchOrCreateService::findPossibleOwnerMatches()` (new, public) — NOT
part of `resolveMatch()`'s structural chain; a separate, independent signal that never
promotes to a confident `exists`, only ever `possible_match`. Agency-scoped the same way
every other query in the service is (join through `tracked_properties`, since
`tracked_property_owners` carries no `agency_id` of its own).

Rule: a natural-person name matches on **order-independent, >=2-shared-token** overlap
(CMA's cell is surname-first; a stored Contact's name may not be); a juristic
entity/trust/company name (`OwnerEntityClassifier::isEntity()`) matches on **exact
normalised registered name** instead, never token-reordered. Either way, owner-name
alone is never enough — at least one property-side signal must ALSO match: same
suburb, same sectional scheme (`scheme_number`, or `complex_name`/`scheme_name` when no
number is known), or same street name. `matched_fields` on the response names exactly
which ones fired.

### 9.4 Deep link — "Open in CoreX"

`resources/views/corex/deeds-capture/index.blade.php`'s selection Alpine component now
reads `?open=tp-<id>` on `init()` (new), winning over the remembered `sessionStorage`
row. The pre-check's `deeplink` field is built as
`route('corex.deeds-capture.index', ['scope' => 'all', 'open' => 'tp-<id>'])` — `scope=all`
asks for the broadest view the clamped Own/Branch/All ceiling actually grants (never
wider than the viewer's real permission).

**Known limit, not solved here:** the queue's `ids` are whatever the current
page/filter/pagination already rendered — a match on a different page, a different
filter, or outside the viewer's own scope ceiling simply won't be in `ids`, and the link
silently falls back to the first row instead. Fine for "open the thing I was just warned
about" (same session, same agency); not a general-purpose record finder.

### 9.5 Logging — `agent_activity_events`

No new table. `AgentActivityEvent` (existing, append-only, `agency_id`+`user_id`+
`event_type`+`subject_type/id`+`payload` — built for exactly this kind of thing, see
its own docblock) gets one row per outcome:
`deeds_capture.precheck.{not_found,possible_match,exists,pulled_anyway,cancelled}`. The
first three are logged inside `checkDuplicate()` itself; the last two are logged by a
second new endpoint, `POST /api/v1/deeds-capture/check-duplicate/decision`
(`logPrecheckDecision()`) — the agent's banner decision, which `checkDuplicate()` cannot
know since it happens in the browser after the response. This is what lets "avoided
reveals" (and therefore avoided R3 charges) be reported later: `pulled_anyway` + `exists`/
`possible_match` without `pulled_anyway` tells you how many warnings led to a skip.

### 9.6 Extension version

`manifest.json` bumped **3.7.7 → 3.8.0** — a real behaviour change (gates the paid
reveal), not a patch, same convention as the 3.6.5 → 3.7.0 bump for the `scripting`
permission addition. **No new permission, no new host** — the pre-check calls the same
`corexos.co.za` host the extension already talks to. Publish steps (not done as part of
this build):
1. `scripts/package-chrome-extension.sh` (rebuild the zip at
   `public/downloads/portal-capture-extension.zip`).
2. Chrome Web Store dashboard → upload the new zip → since no permission/host changed,
   this should route through Chrome's abbreviated review (no new permissions requested),
   not a full review.
3. Existing installs auto-update on Chrome's normal cadence; nothing forces an
   immediate update — an agent on the old 3.7.7 build keeps the unconditional reveal
   until Chrome updates them.

### 9.10 Street-number correctness and the second source (2026-10-07, extension 3.8.1)

**Trigger.** Johan's first test of 3.8.0 on QA1: a click on **19 Grindewald Drive**, Uvongo
came back *"29 Grindewald Drive … Address is a close text match on this street and suburb —
no other property on this street was a candidate"*, while CoreX already held that exact
property (QA1 property 6113, "19 Grindewald"). Wrong twice.

**Root causes (QA1 rows read, not guessed).**
1. **Only `tracked_properties` was searched.** Property 6113 is agency stock created directly;
   no earlier capture of it exists, so the pre-check could not see it. (Its one linked tracked
   record, 373, is itself corrupt — see "Not fixed here".)
2. **The street-number veto read the structured column only.** Tracked property 392 is stored
   `street_number = NULL`, `street_name = "29 Grindewald Drive"` (old cmainfo import: number
   inside the name). `numbersConflict()` saw a NULL column, did not veto, and strategy 5 (token
   overlap, which drops tokens under 3 characters — i.e. every street number) matched on
   "grindewald drive" alone. 111 tracked properties on QA1 are stored that way. The real capture
   path (`matchOrCreate`) shares `resolveMatch()`, so a real capture of 19 would have been
   merged into the record for 29.
3. **"Grindewald" ≠ "Grindewald Drive"** to the address normaliser (no street-type handling),
   so even a properties search by normalised street would have missed 6113.

**Rules (BINDING).**
- An exact **street number + street + suburb** (or **erf + suburb** for freehold, or **scheme +
  section** for sectional) always wins and is searched in **both** places CoreX holds a property:
  agency stock (`properties`) and earlier captures (`tracked_properties`).
- A **different street number is never the same property.** The number is read from the
  structured column, or — when that is blank — from the numbers written in the street/address text
  (`streetNumberSet()`: leading number of each line/comma segment, so "4 Villa-Del-Mei⏎35
  Grindewald Drive" states 4 and 35). A conflict is declared only when both sides state numbers
  and none agree. This fixes the class for every caller of `resolveMatch()` (pre-check and
  real captures), not just this address.
- A **loose street-text (strategy 5) hit** is reported as a duplicate only if the street number
  is confirmed equal on both sides. When the click carries a number and the hit's number is
  different or unknown it is **not a duplicate**: it is returned in `same_street`.
- **Street type may be missing on one side** ("Grindewald" vs "Grindewald Drive", "Dr" vs "Drive"):
  still confident. If both sides name a *different* type (Road vs Drive) → `possible_match` only. A
  unit/section the capture carries but the property lacks → `possible_match` only; a differing unit
  is a different property.

**Endpoint contract change** (`POST /api/v1/deeds-capture/check-duplicate`, additive):
- `matches[]` now also holds `source: "property"` rows (`property_id`, `address`, `summary`,
  `confident`, `deeplink` → `corex.properties.show`); every row has a `source`
  (`tracked_property` | `property`). Confident rows sort first (the banner shows `matches[0]`).
- **`same_street[]`** (new, informational): other properties on the same street with a different
  or unrecorded number — `{source, id, address, number_known}`, max 5. Never part of `status`,
  never blocks.
- **Scope:** a property the caller cannot open (own/branch/agency data scope + the Other Agency
  Stock role gate) is still reported — a duplicate must not be silently missed — but as "A property
  already held in your agency", no address, no deep link. Other agencies' rows are never read.
- `agent_activity_events` payload gains `same_street_count` and `loose_hit_ignored`.

**Extension 3.8.1** (`manifest.json` 3.8.0 → 3.8.1; no permission or host change, so the same
abbreviated store review as 3.8.0): the banner uses the server's `summary` for property matches;
a `same_street` answer on a `not_found` shows a non-blocking note next to "Captured ✓"
(*"Other property on this street in CoreX (different number): 29 Grindewald Drive, UVONGO"*) and the
capture proceeds exactly as before — it never opens the duplicate banner and never skips.

**Not fixed here (reported).**
- QA1 tracked property **373** is a "Frankenstein" record: four different cmainfo reports
  (1 Como Drive, 4 Garden Place, 15 Meriel Road, 36 Grindewald Drive) collapsed into one, and it is
  promoted to property 6113 (19 Grindewald). Data repair is a separate, deliberate job.
- `resolvePropertyMatch()` (promote-to-stock) still compares `street_name_normalised` exactly, so a
  promotion of a capture "19 Grindewald Drive" would not link to property "19 Grindewald" and would
  create a second property. Same street-type tolerance should be applied there in its own change.
  **Fixed 2026-10-07 — see §9.11.**
- Suburb differences ("Uvongo" vs "Uvongo Beach") are not bridged. **Handled 2026-10-07 — see §9.11**
  (spelling twins are one suburb; neighbouring P24 suburbs are "possible match" only).

**Tests.** `tests/Feature/Prospecting/DeedsCapturePrecheckStreetNumberTest.php` (18 cases incl. both
Grindewald rows as they exist on QA1); `deeds-cleanslate.test.cjs` (+9 checks).

### 9.11 Promote link, suburb spelling and the 373 merge guard (2026-10-07, extension unchanged at 3.8.1)

Closes items 2, 3 and 1 of the §9.10 "not fixed" list (item 4, the stale download zip, is also done:
`public/downloads/portal-capture-extension.zip` was rebuilt with `scripts/package-chrome-extension.sh`
from the 3.8.1 source; it is NOT published to the Chrome Web Store).

**Promote link (`resolvePropertyMatch()`, address fallback).** It no longer compares the street-name
column exactly. It runs the same street comparison as the pre-check — one private method,
`stockByStreetNumber()`, now shared by `findExistingStock()` (pre-check) and the promote link — so the
two can no longer disagree: street number + street name + suburb, street type may be missing on one
side, the number may live in the street text of an old row, a different number or unit never matches.
Only a *confident* hit is linked; a street-type clash (Road/Drive) or a neighbouring-suburb hit is
"possible" and Promote creates the new property instead of guessing. `PropertyDuplicateMatchEvidence`
(Deeds-screen side-by-side panel) calls the public `addressFallbackCandidates()` so the panel lists exactly
what Promote acts on.

**Suburb.** `TrackedPropertyAddress::suburbSpellingKeys()` returns the set of stored `suburb_normalised`
strings that are the same suburb by spelling alone ("saint X" <-> "st X", plus `config/property-suburb-aliases.php`
groups); the matcher queries `whereIn(...)` so existing rows need no backfill. Uvongo and Uvongo Beach are
**two real Property24 suburbs** (p24_id 6359 and 33106, ~1,100 and ~300 properties on QA1, each listing the
other in `p24_suburbs.surrounding_ids`) that share streets — they are never merged; `neighbouringSuburbKeys()`
makes a street-number + street match under the neighbouring suburb a "possible match" in the pre-check only.

**373 merge guard.** Root cause of tracked property 373 (four streets in one record, QA1): the CMA report
parsers (`CmaInfoPropertyValuationParser`, `…VicinitySaleParser`, `…SectionalTitleSalesParser`) put the whole
printed line into `street_name` ("4 Garden Place   Cadastral Extent  1 605 M²") with `street_number` NULL, and
strategy 5 (token overlap) accepts any candidate sharing two words in the suburb — "cadastral" and "extent" are
two words. Fix: strategy 5 now also requires the street names to agree (`streetsAgree()`: number, unit, street
type and the "Cadastral Extent … M²" tail ignored). The existing record 373 is NOT repaired (QA1 demo data).
Not fixed here: the parsers still store the whole line in `street_name` — replaced by the structured-address
proposal (Part 2 report), not patched.

**Tests.** `tests/Feature/Prospecting/MatchingPromoteLinkAndMergeGuardTest.php` (13 cases; mutation-checked).
**Not in the wizard / no setting added** (CLAUDE.md #10a does not apply).

### 9.7 Deliberately unchanged
`extractDeed()`, `extractSaleInformation()`, `revealOwnerIdIfNeeded()`,
`buildDeedsCapturePayload()`, `DeedsCaptureController::store()`/`ingestOne()` — all
byte-for-byte unchanged. The gate sits entirely in front of them in
`onCaptureClick()`; the existing, audited capture path itself was never touched.

### 9.8 Files touched
| File | Change |
|---|---|
| `app/Services/Prospecting/TrackedPropertyMatchOrCreateService.php` | + `describeLastMatch()`, `findPossibleOwnerMatches()` (new, additive methods only) |
| `app/Http/Controllers/Api/DeedsCaptureController.php` | + `checkDuplicate()`, `logPrecheckDecision()`, `buildFreePropertyFacts()`, `formatMatch()` (new methods only; `store()`/`ingestOne()` untouched) |
| `routes/api.php` | + 2 routes next to the existing `/deeds-capture` route |
| `resources/views/corex/deeds-capture/index.blade.php` | `init()` reads `?open=` (§9.4) |
| `public/chrome-extension/portal-capture/content-cmainfo.js` | `onCaptureClick()` restructured; + `extractFreeSaleFields()`, `buildPreCheckPayload()`, banner functions, `logPrecheckDecision()` |
| `public/chrome-extension/portal-capture/background.js` | + `handleCheckDeedsDuplicate()`, `handleLogDeedsPrecheckDecision()`, message routing |
| `public/chrome-extension/portal-capture/manifest.json` | version 3.7.7 → 3.8.0 (→ 3.8.1, §9.10) |
| `tests/Feature/Prospecting/DeedsCapturePrecheckTest.php` | new |
| `public/chrome-extension/portal-capture/tests/deeds-cleanslate.test.cjs` | + pre-check gate test section |

### 9.9 Deliberately NOT in the wizard
No new agency setting — the pre-check is unconditional behaviour, not a configurable
toggle. CLAUDE.md #10a does not apply.
