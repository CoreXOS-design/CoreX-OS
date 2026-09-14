# Portal Inventory Guard

> Status: SPEC — built on `AT-portal-inventory-guard`, awaiting QA1.
> Author: session 2026-09-12. Origin: live incident on Home Finders Coastal's
> Private Property branch (see §1).

---

## 1. Why this exists — the incident

Home Finders Coastal's Private Property branch was fed by their **previous
system** before CoreX. Those listings live on PP keyed by the old system's own
`PropertyId` values (1.3M–1.5M range). CoreX submits with `PropertyId = CoreX
property id`. PP keys every listing by `(PropertyId, ListingType)`, so CoreX's
submission was never an update to the pre-existing listing — it was **a second,
independent listing for the same physical property**.

Two consequences, both found live on 2026-09-12:

| Symptom | Count found | What Johan saw |
|---|---|---|
| Same property advertised twice | 10 | Property 3814 appeared twice on PP |
| Advertised listing CoreX cannot control | 43 | Property 4017 showed "Under Offer" on PP while CoreX had it sold and never syndicated |

The second class is the dangerous one: CoreX addresses PP by CoreX property id,
so a listing PP holds under a foreign id is **permanently outside CoreX's
reach** — it cannot be repriced, flagged under-offer, or taken down when the
property sells. `DesyndicatePropertyFromPortalsJob` never fires for it because
no CoreX property points at it.

PP offers `UpdateUniqueListingID` to re-key an existing listing onto our id, but
its `PrivatePropertyListingId` argument is an **encrypted** PP-internal id we are
never given (verified live: passing the `T…` reference returns
`System.Exception: Could not decrypt data`). **Adoption is therefore not
available to us.** The only remediation is: publish from CoreX, then deactivate
the legacy listing.

**This will recur for every new agency that signs up with an existing portal
account.** That is what this spec prevents.

---

## 2. Pillars

- **Property** — reads `properties` (address, price, status, `pp_*`), writes
  nothing. The guard is read-only with respect to property data.
- **Agent** — surfaces the conflict to the agent attempting syndication.

No new pillar data. This is a safety rail over the existing syndication path.

---

## 3. Data model

**No migration.** The portal inventory is a *derived read-back*, not domain
data, so it is cached (`Cache` store, key `portal-inventory:pp:{agency_id}`,
TTL 7 days) rather than tabled. A stale or absent snapshot must never block
normal work — see §6 fail-open rule.

Normalised snapshot entry:

```
portal_id      string   the portal's PropertyId (ours = CoreX property id)
listing_type   string   Sale | Rental
status         string   ForSale | ToLet | PendingOffer | Sold | Inactive
advertised     bool     status ∈ {ForSale, ToLet, PendingOffer}
street_number  string
unit_number    string
complex        string
suburb         string
price          int
headline       string
```

---

## 4. Behaviour

### 4.1 Snapshot

`PortalInventoryGuard::snapshot(Agency, bool $refresh = false)` returns the
cached inventory, calling `GetFullDetailsOfAllListingsByBranch` only when the
cache is cold or `$refresh` is set. One call, whole branch.

### 4.2 Matching

A portal listing matches a CoreX property when **any** rule holds. All three
were validated by hand against the live branch on 2026-09-12 before being coded:

- **R1** — street number, unit number and complex name all equal (all non-empty).
  Catches the case where the two records disagree on suburb.
  *(Live proof: PP#1563764 "Beacon Rocks" vs CoreX #1360 "Uvongo Beach" — same
  complex `Laguna La Crete`, unit 1, street 1. Suburb-based matching missed it.)*
- **R2** — street number, suburb and price all equal.
- **R3** — headline identical (normalised, > 25 chars) and price equal.

Matching is scoped to the **same listing type**. A sale and a rental of the same
unit are a legitimate pair, not a duplicate — three such pairs exist live and
must never be flagged.

### 4.3 Classification (`classify`)

- `orphan_advertised` — advertised, `portal_id` is not a property of this
  agency. CoreX cannot control it.
- `stale_advertised` — advertised, CoreX-owned, but the CoreX property is
  archived or off-market. The 4017 symptom, CoreX-side.
- `duplicates` — an `orphan_advertised` entry that matches a CoreX-owned
  advertised entry. The 3814 symptom.

### 4.4 Guard (`conflictFor`)

Returns the offending snapshot entry when **all** hold:

1. the property has never been published to PP (`pp_ref` empty) — an update to
   a listing we already own is never a conflict;
2. a snapshot exists for the agency;
3. an entry matches the property (§4.2) on the same listing type;
4. that entry is `advertised`;
5. that entry's `portal_id` is not a property of this agency.

Otherwise `null`.

---

## 5. UI placement and navigation

None. This is a guard on an existing flow plus a CLI report. The conflict
surfaces as the failure message on the **existing** "Send to Private Property"
action (`SyndicationController`), which already renders
`pp_last_error` — no new page, so Non-negotiable #2 is not engaged.

---

## 6. User flow

**Agency onboarding (the preventive path).** When an agency's PP credentials are
first saved, run `pp:audit-inventory --agency=N --refresh`. Anything under
`orphan_advertised` is pre-existing portal stock CoreX did not create. Decide per
listing: publish the CoreX property and retire the legacy listing, or retire it
outright.

**Agent syndicating a property (the safety net).** If the portal already
advertises that address under an id CoreX does not own, the submit is refused
with:

> Private Property already advertises this property under a listing CoreX does
> not control (portal reference {id}). Publishing now would create a second
> advert for the same property. Retire the existing listing first.

**Fail-open rule.** No snapshot ⇒ no block. A cold cache must never stop an
agent working. The onboarding step is what guarantees a snapshot exists for a
new agency; the guard is the net beneath it, not the mechanism.

---

## 7. Permissions

The command is CLI-only (no route, no permission key). The guard runs inside the
existing submit path and inherits `SyndicationController`'s existing checks.

---

## 8. Acceptance criteria

1. `classify()` against the live Home Finders Coastal branch reports the same
   buckets the manual 2026-09-12 audit produced.
2. A sale + rental pair at one address is **not** reported as a duplicate.
3. `conflictFor()` returns null when the property already has a `pp_ref`.
4. `conflictFor()` returns null when no snapshot is cached.
5. `conflictFor()` returns the entry when an advertised orphan matches on any
   of R1/R2/R3 and the listing type agrees.
6. `submitListing()` refuses and records `pp_last_error` when a conflict exists,
   without calling the portal.

---

## 9. Files

Create:
- `app/Services/Syndication/PortalInventoryGuard.php`
- `app/Console/Commands/Syndication/AuditPortalInventory.php`
- `tests/Feature/Syndication/PortalInventoryGuardTest.php`

Modify:
- `app/Services/PrivateProperty/PrivatePropertySyndicationService.php` — guard call.

---

## 10. Deliberately NOT in scope

- **Adoption of legacy listings.** PP's `UpdateUniqueListingID` needs an
  encrypted id we are not given. Do not build against it without PP supplying
  the id.
- **Property24.** The same class of defect is plausible there; P24's read-back
  differs and needs its own investigation. Not assumed, not built blind.
- **Auto-retiring orphans.** Deactivating a live advert is a business decision.
  The command reports; a human decides.
