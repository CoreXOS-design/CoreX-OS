# AT-266 — Outreach merge fields render WRONG property address from junk parse (INVESTIGATE-ONLY)

**Date:** 2026-07-21 · **Lane:** cc3 · **Status:** cause + files/lines for Johan sign-off. **NO code changed.**
**Symptom:** the seller-outreach `{property_address}` merge field renders an incorrect address
(complex/unit bled into the street, doubled house number, glued multi-line), sourced from
bad/junk-parsed data.

---

## Short answer to "does the render use OutreachAddress's address-of-record path?"

**YES — it does.** And that is exactly why the junk still shows: the render faithfully prints
`properties.address`, but `properties.address` is **itself derived from the junk structured parts**
(it is NOT the clean agent-typed value the AT-266 "address of record wins" fix assumed). The two
compose-time guards only cover the *double-number* and *multi-line* junk classes; the
*scheme-in-street* and *unit-as-number* classes flow straight through into `address` and render.

---

## 1. Where the merge field resolves the address (render path)

- `SellerOutreachComposerService::buildMergeFields()` — `app/Services/SellerOutreach/SellerOutreachComposerService.php:263`
  ```php
  $propertyAddress = $address->displayAddress();      // :263
  ...
  'property_address' => $propertyAddress,             // :332
  ```
- `$address` is an `OutreachAddress` built from the linked Property at `:57-59`
  (`OutreachAddress::fromProperty($property)`).
- `OutreachAddress::displayAddress()` → `streetLine()` —
  `app/Support/SellerOutreach/OutreachAddress.php:131` → `:112-115`:
  ```php
  public function streetLine(): string {
      if ($this->addressOfRecord !== null && $this->addressOfRecord !== '') {
          return self::flatten($this->addressOfRecord);   // address of record WINS
      }
      ... // fallback: compose from structured columns
  }
  ```
  `addressOfRecord` is set from `properties.address` in `fromProperty()` (`OutreachAddress.php:82`).

So the merge field prints `flatten(properties.address)` whenever `properties.address` is non-empty.
The AT-266 "address of record wins" logic **is** in force here.

## 2. Why junk still renders — `properties.address` is derived from the junk parts

The AT-266 OutreachAddress docblock (`OutreachAddress.php:29-51`) assumes `properties.address` is the
**clean agent-typed** value and the structured columns are the dirty ones. That assumption is **broken
by the other half of AT-266**: `properties.address` is machine-DERIVED from those same structured parts.

- `PropertyObserver::saving()` recomposes `address` from the parts whenever any part is dirty —
  `app/Observers/PropertyObserver.php:119-129`:
  ```php
  $addressParts = ['street_number','street_name','complex_name','unit_number','unit_section_block'];
  ... if ($partsDirty) { $composed = $property->composeAddressFromParts();
                         if ($composed !== '') $property->address = $composed; }   // :127-129
  ```
- `Property::composeAddressFromParts()` — `app/Models/Property.php:856` → `composeStreetLine()` `:899`.
- `composeStreetLine()`'s own docblock states the link outright —
  `app/Models/Property.php:885-898`:
  > "…because `address` is derived from this composer that junk reached the **seller-outreach merge
  > field**." The guard it adds only stops the **double-number** class
  > ("21 21 Crown Road"), and only "when the name already starts with the number."

So a property whose `street_name` carries a bled-in complex ("Stafford Close Marine Drive") composes
`properties.address` = "… Stafford Close Marine Drive …", and OutreachAddress prints it verbatim. The
imports also write `address` directly (see §4), so even the direct value can be the junk source string.

**The guards that DO exist and what they miss:**
- `composeStreetLine()` (Property.php:899) + `OutreachAddress::composeStreetFromColumns()`
  (OutreachAddress.php:210) — fix ONLY the double-number class.
- `OutreachAddress::flatten()` (OutreachAddress.php:239) — fixes the glued-multiline class
  ("Umzimkhulu Court40 Bulwer Street" → "Umzimkhulu Court, 40 Bulwer Street").
- **NOT fixed at compose/render time:** *scheme-in-street* (complex inside `street_name`) and
  *unit-as-number* (`street_number` holds the unit). Those are only repaired by a **manual** command:
  `corex:reconcile-property-addresses` (`app/Console/Commands/Properties/ReconcilePropertyAddresses.php:33`,
  logic in `app/Services/Properties/PropertyAddressReconciler.php` — its docblock `:19-33` catalogues
  exactly these junk classes). That command is **not scheduled** (no entry in `routes/console.php`), so
  any property not yet reconciled — or **re-polluted by a fresh import after a reconcile** — renders junk.

## 3. Net root cause (one paragraph)

The outreach merge field correctly prints `properties.address`, but `properties.address` is not a
trustworthy clean value — it is derived from (or directly set to) the same machine-written address
parts the AT-266 OutreachAddress fix tried to route around. The compose-time guards only neutralise the
double-number and multi-line junk classes; the scheme-in-street and unit-as-number classes survive into
`address` and render. Cleaning them depends on a **manual, unscheduled** reconcile command, so junk
persists on any un-reconciled or newly-imported property.

## 4. Where the junk parse originates upstream

Naive address splitters in the import/capture paths write junk into `street_number`/`street_name`
(and `address`) with **no complex/unit awareness** — everything after the leading number lands in
`street_name`:

- **`SoldPropertyImporter::parseAddress()`** — `app/Services/Properties/SoldPropertyImporter.php:550-571`.
  The whole split is one regex: `^\s*(\d+[A-Za-z]?)\s+(.+)$` → `street_number` = leading digits,
  `street_name` = **everything else** (`:565-570`). A source line "26 Stafford Close Marine Drive"
  becomes `street_number=26`, `street_name="Stafford Close Marine Drive"` — the scheme name is now in
  the street. Writer: `:265-267` (`'address' => $parsedAddr['street'] ?: $address`, plus the split
  columns). No complex/unit extraction exists.
- **`P24ListingsCsvParser`** — `app/Services/Importer/P24ListingsCsvParser.php:118-120` (writes
  `address`/`street_number`/`street_name` from the parsed CSV row).
- **`PortalCaptureController`** (Chrome capture / scraped listings) —
  `app/Http/Controllers/Presentation/PortalCaptureController.php:1280-1282`.
- (Match/create ingress funnels these parts through
  `TrackedPropertyMatchOrCreateService` — `app/Services/Prospecting/TrackedPropertyMatchOrCreateService.php:71,218`
  — it consumes the already-parsed parts, so it propagates rather than originates the junk.)

## 5. Files & lines (summary)

| Concern | File:line |
|---|---|
| Merge field builds `property_address` | `SellerOutreachComposerService.php:263`, `:332` |
| Address DTO from Property | `SellerOutreachComposerService.php:57-59`; `OutreachAddress.php:71-89` |
| Render prefers address-of-record | `OutreachAddress.php:112-115` (`streetLine`), `:131` (`displayAddress`), `:82` |
| `address` derived from parts (the leak) | `PropertyObserver.php:119-129` → `Property.php:856` → `:899` |
| Compose guard (double-number only) | `Property.php:899-923`; `OutreachAddress.php:210-232` |
| Multi-line flatten only | `OutreachAddress.php:239-247` |
| Junk-class repair (MANUAL, unscheduled) | `ReconcilePropertyAddresses.php:33`; `PropertyAddressReconciler.php:19-33` |
| **Upstream junk origin (parsers)** | `SoldPropertyImporter.php:550-571` (+ writer `:265-267`); `P24ListingsCsvParser.php:118-120`; `PortalCaptureController.php:1280-1282` |

## 6. For Johan — direction options (NOT built; pick before any code)

1. **Fix the class at compose time** — teach `composeStreetLine()` / `composeAddressFromParts()` (and
   the mirrored `OutreachAddress::composeStreetFromColumns()`) to recognise the scheme-in-street and
   unit-as-number classes (reuse `PropertyAddressReconciler`'s logic), so `properties.address` is clean
   the moment it is composed — no manual command, new imports self-heal. Highest-leverage; touches the
   shared address spine (careful, launch-adjacent).
2. **Fix at the source** — give the parsers (`SoldPropertyImporter::parseAddress` first) complex/unit
   awareness so junk parts are never written. Narrower, but only helps future imports.
3. **Operationalise the reconcile** — schedule `corex:reconcile-property-addresses` and/or run it after
   each import. Cleans existing rows but leaves the naive parsers writing junk (needs #1 or #2 too).

Recommendation: **#1 (compose-time class fix), reusing the reconciler's already-written classifiers**,
because both the merge field and every on-screen address read from the same derived `address`.

**Report-only, out of scope (noticed, not touching):** `corex:reconcile-property-addresses` is not
scheduled anywhere in `routes/console.php`, so the existing remediation only ran manually.
