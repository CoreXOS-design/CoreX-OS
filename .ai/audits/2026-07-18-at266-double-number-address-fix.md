# AT-266 — outreach wrong-address: the doubled house number (root fix)

> Lane m1 · branch `AT-144-buyer-demand-template` · QA1 (`corex_qa1`, real data). 2026-07-18.

## Where AT-266 already stood (do-not-rebuild check)

AT-266's outreach fix was **already shipped and live** before this session:

- `OutreachAddress` prefers the property's **address of record** and gates blank/incomplete addresses (`isIncomplete()`, both full-title and sectional shapes) — commits `862f8f68` + `544fb443`, present on **main / Staging / QA1** and this branch.
- `PropertyObserver::saving()` derives `properties.address` from the structured parts ("one truth").
- The 07-15 reconciliation repaired 2,143 rows; 13 unfixable imported rows were soft-archived (ticket comment 2026-07-17).

Every address-derived outreach merge field routes through `OutreachAddress` (`SellerOutreachComposerService::buildMergeFields()` — `property_address = $address->displayAddress()`, `property_suburb = $address->suburb`). No raw-column leak. **The outreach fix was not rebuilt.**

## The residual defect this session found

`properties.address` is derived from `Property::composeAddressFromParts()`, and that composer — plus `Property::buildDisplayAddress()` — concatenated `street_number . ' ' . street_name` **with no double-number guard**, unlike `OutreachAddress::composeStreetFromColumns()`. When machine-written parts carried the number in **both** columns, the derived `address` doubled it. Because `OutreachAddress` prefers the (now-junk) address of record, its own guard was dead.

The reconciliation had not caught these: it checks `address == composed(parts)` using the **same un-guarded composer**, so a self-consistent-but-wrong row reads as "coherent". Measured the wrong noun.

### Live evidence (qa1 = live snapshot) — 3 rows, all agencies

| id | status | parts (number / name) | stored `address` (junk) | fixed |
|----|--------|-----------------------|-------------------------|-------|
| 1900 | withdrawn | 16 / "16 Lilliecrona Boulevard" | `16 16 Lilliecrona Boulevard` | `Unit 4, 16 Lilliecrona Boulevard` |
| 5114 | withdrawn | 21 / "21 Crown Road" | `21 21 Crown Road` | `Unit 1, 21 Crown Road` |
| 3748 | withdrawn | 1 / "1 Brooke Gardens" | `1 1 Brooke Gardens` | `Unit 20, 1 Brooke Gardens` |

(The fix also restored the unit each junk address had dropped.)

## The fix (class-level, `app/Models/Property.php`)

One shared guarded composer `Property::composeStreetLine()` — number + name, but never prepend the number when `street_name` already opens with it as a whole token (mirrors `OutreachAddress::composeStreetFromColumns()`). Both `composeAddressFromParts()` and `buildDisplayAddress()` now call it, so all Property address composers agree and the derived `address` can never re-acquire a doubled number. Clean rows unchanged.

Test: `PropertyAddressOneTruthTest::test_a_number_already_in_the_street_name_is_not_prepended_twice`.
(The CC1 lane's PHPUnit bootstrap hangs — known env issue; the fix is proven functionally below. Test lands for CI.)

## Verification (Tinker, qa1, real data)

- The 3 rows recompose correctly via the fixed methods (table above).
- **End-to-end**, reading the corrected **stored** address: property #1900 → outreach `{property_address}` = `Unit 4, 16 Lilliecrona Boulevard, Beacon Rocks` (was `16 16 Lilliecrona Boulevard`).
- Clean row #1290 (`614 Piet Uys Road`) — `composeStreetLine()` unchanged: `614 Piet Uys Road`.

## Data backfill

3 rows re-derived on **QA1** (reversible snapshot `storage/app/private/at266/double-number-backfill.json`; reverse = restore `address_before` per id). **Live is report-first** — awaiting Johan's word; the same 3 rows (all withdrawn) will correct on live either by this backfill or automatically on their next part-touching save now that the composer is guarded.

## Spec

`.ai/specs/seller-outreach-spec.md` §11.3b.
