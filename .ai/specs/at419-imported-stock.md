# AT-419 — Imported Stock page (P24 importer split by status)

> Status: Built and promoted — QA2 → Staging (9b3e787c3) → Prod, 2026-09-16. Rented-status amendment applied in the prod-audit fix branch (Property::OFF_MARKET_STATUSES now includes rented).

> **Amendment, 2026-09-15 (Andre):** "off-market" for this page is narrower than
> `Property::OFF_MARKET_STATUSES`. A withdrawn/expired/sold P24 import that later gets
> picked up by the unrelated stale-stock/duplicate-resolution pipeline
> (`TrackedPropertyMatchOrCreateService` / `PropertyDuplicateTakeService`) and reclassified
> to `draft` or `prospecting`/`not_selling` has left the "imported off-market P24 stock"
> bucket — it belongs to the Drafts/Prospecting workflow, not here, even though
> `p24_imported_at` is still set. New `Property::IMPORTED_STOCK_STATUSES` (sold,
> sold_by_3rd_party, transferred, withdrawn, expired, cancelled, let_out, archived,
> unavailable) is the actual set used by both AT-419 scopes — `OFF_MARKET_STATUSES` itself
> is untouched. Found live on HFC's restored data: 3 drafts + 7 prospecting rows.

> **Amendment, 2026-09-19 (AT-422):** users had to check two pages (Properties, then Imported
> Stock) whenever they were looking for a specific property. **When a search term is typed on
> the Properties page, results now also include Imported Stock** (imported + off-market, per
> `Property::importedStockStatuses()`), each carrying the same "Imported" tag the Imported Stock
> page uses. **With no search term, nothing changes** — Properties still hides Imported Stock by
> default, and the Imported Stock page is untouched. Everything else about a search is as before:
> the agent filter / "All Agents" choice, role breadth (own/branch/agency, incl. AT-394's read-only
> "Already listed" rows), status/type/price filters and the header tiles all apply to the imported
> rows exactly as they do to every other row. The same applies on Rentals → Properties (same
> controller action). The tag is driven by the property itself (`Property::isImportedStock()` —
> imported AND off-market), so an active imported listing still shows no tag anywhere. The tag
> now also appears in the table (list) view, which previously had no tag on Imported Stock rows.
> Files: `PropertyController::index()`, `Property::isImportedStock()`,
> `resources/views/corex/properties/index.blade.php`, `tests/Feature/Properties/ImportedStockTest.php`.

## 1. What this feature does and why

Today, when an admin confirms a P24 (Property24) CSV import via Admin → Importer, every
row lands in the `properties` table with no split by status — active, withdrawn, sold,
expired, etc. all show up together on the main **Properties** page (Real Estate →
Properties), cluttering it with off-market history.

This feature splits that by status, for imported stock only:

- **Active** imported listings continue to appear on the normal **Properties** page,
  completely unchanged — same columns, same "Listed Date" field, no new tag. Nothing about
  how an active imported listing looks or behaves changes.
- **Every non-active status** (withdrawn, sold, expired, cancelled, rented, etc.) from an
  import instead lives on a new page: **Imported Stock**, under Real Estate.
- On the Imported Stock page only, each record shows an **"Imported"** tag, and instead of
  a "Listed Date" column it shows an **"Imported Date"** — the date the property was
  brought into CoreX by the importer, not a listing date (which P24 imports never actually
  carry — `listed_date` is blank for imported rows today).

**Imported Stock functions exactly like the Properties page otherwise** — same filters,
same agent column and agent (re)assignment, same search, sorting, and row actions. It is
the same feature, scoped to a different slice of `properties`: imported + off-market
instead of everything else. The only visible differences are the "Imported" tag, the
"Imported Date" column standing in for "Listed Date", and the underlying query scope. This
is a filtered sibling of Properties, not a stripped-down or read-only view.

Manually-created properties, and properties from any other source (deeds capture, MIC,
push-syndication, sold-property import, etc.), are entirely unaffected — this only touches
rows created/updated by the P24 CSV importer's confirm step.

## 2. Pillars

**Property** — reads and writes to `properties`. No other pillar touched.

## 3. Data model / migrations

Add one column to `properties`:

| Column | Type | Purpose |
|---|---|---|
| `p24_imported_at` | `timestamp`, nullable | Stamped the moment a property is created/updated by the P24 importer's confirm step. Doubles as both the "this came from the importer" flag and the "Imported Date" value shown on the new page. |

No change to `tracked_properties` — imported stock is agency stock (`properties`), not a
prospecting lead, so it stays in the same tier it's in today; nothing moves tiers.

## 4. UI placement and navigation

New sidebar entry **"Imported Stock"** under the Real Estate section, immediately after
**Properties**, gated by a new permission key so it can be turned on/off per role
independently of general Properties access.

Route group: `corex.properties.imported-stock` (new sub-route alongside the existing
`corex.properties.*` group, same middleware stack: `permission:access_imported_stock`,
`agency.required`, `deny_assistant_property_write`).

## 5. User flow

1. Admin uploads and confirms a P24 import exactly as today (Admin → Importer → Property
   Review → Confirm / Import All). No change to the upload/review/confirm screens.
2. On confirm, the importer stamps `p24_imported_at = now()` on every property row it
   creates or updates — active or not.
3. **Properties** page query excludes rows where `p24_imported_at` is set **and** the
   status is off-market. Active imported rows are unaffected and keep showing there exactly
   as before (no tag, normal Listed Date column, indistinguishable from a manually-captured
   listing).
4. **Imported Stock** page shows rows where `p24_imported_at` is set **and** the status is
   off-market. Each row carries the "Imported" tag and an "Imported Date" column driven by
   `p24_imported_at`. Everything else about the page — filters, search, sorting, the agent
   column, reassigning the agent on a property, whatever row actions Properties offers —
   works identically to the Properties page, applied to this slice of stock.
5. If a property later changes status (e.g. an active imported listing gets marked
   withdrawn through normal CoreX use, not through a re-import), it will start appearing on
   Imported Stock and drop off Properties automatically, since the split is a live query on
   current status + the flag — not a one-time move.

### Backfill (explicitly NOT automatic)

Properties imported by P24 **before** this feature ships keep their current
`p24_imported_at = null` and stay exactly where they are today (on the Properties page,
whatever their status) — this build does not touch historical data.

A new artisan command, `properties:backfill-p24-imported`, is provided for Johan to run
manually whenever he's ready, after checking with the agency:

- Finds properties that were created via a confirmed P24 import row (`p24_import_rows.target_id`)
  and don't yet have `p24_imported_at` set.
- Defaults to a **dry run** — reports how many properties would be stamped and a status
  breakdown, changes nothing.
- Requires an explicit `--apply` flag to actually stamp them, using each import row's own
  confirm timestamp (not "now") so the Imported Date reflects when it was really imported.
- After running with `--apply`, any of those that are off-market will immediately start
  showing on Imported Stock (and disappear from Properties); active ones keep showing on
  Properties, unchanged, exactly as the live-import path behaves.

This command is never run automatically by this build, a deploy, or a scheduled job — it
is a manual, on-demand tool.

## 6. Permissions

New key `access_imported_stock` in `config/corex-permissions.php`, same section/shape as
the existing `access_properties` key, granted to the same roles that currently hold
`access_properties` (so nobody loses visibility they already have, and nobody unexpectedly
gains it).

## 7. A data-quality fix bundled into this build

While researching this, we found an existing inconsistency: the importer sometimes stores
a status like `"Withdrawn"` with a capital letter, and one of the two places CoreX checks
"is this property still active" is case-sensitive while the other isn't. In practice this
means a small number of already-imported withdrawn/sold listings could currently be
miscounted as active in some parts of the system. This build normalizes that check as part
of making the new page's active/non-active split reliable — it's a small correctness fix
riding along with this feature, not a separate change.

## 8. Acceptance criteria

- [ ] New **Imported Stock** page exists under Real Estate, permission-gated, shows only
      off-market properties with `p24_imported_at` set.
- [ ] Imported Stock offers the same filters, search, sorting, agent column, and agent
      (re)assignment as the Properties page — functionally identical, just a different
      slice of stock.
- [ ] Those rows show an "Imported" tag and an "Imported Date" column (from `p24_imported_at`),
      not a Listed Date column.
- [ ] Active imported properties keep appearing only on the Properties page, with no visual
      or behavioural change versus today.
- [ ] New P24 confirms (active or not) stamp `p24_imported_at` automatically going forward.
- [ ] No historical/pre-feature data changes automatically.
- [ ] `properties:backfill-p24-imported` command exists, defaults to dry-run, requires
      `--apply` to write, and correctly splits backfilled rows across both pages by their
      real status afterward.
- [ ] A property whose status changes after import (through normal CoreX edits) moves
      between the two pages automatically, without needing a re-import.
- [ ] Existing test suite for the Properties index and the P24 importer stays green; new
      tests cover the split, the tag/date display, and the backfill command (dry-run and
      `--apply`).

## 9. Files to create or modify

- `database/migrations/..._add_p24_imported_at_to_properties_table.php` (new)
- `app/Jobs/ConfirmP24PropertyRowJob.php` — stamp `p24_imported_at` on confirm
- `app/Models/Property.php` — off-market status normalization fix; scope for
  imported+off-market vs. everything-else
- `app/Http/Controllers/CoreX/PropertyController.php` — exclude imported+off-market from
  the Properties index query
- New controller/action for the Imported Stock page — reuses `PropertyController@index`'s
  filtering, sorting, search, and agent-assignment logic wholesale (same query builder,
  same request handling), just scoped to imported + off-market instead of everything else
- `resources/views/corex/properties/imported-stock/index.blade.php` (new) — same layout,
  columns, filters, and row actions as the Properties index view, with "Imported" tag added
  and "Imported Date" swapped in for "Listed Date"
- `routes/web.php` — new route(s) alongside the existing `corex.properties.` group
- `config/corex-permissions.php` — new `access_imported_stock` key + role grants
- `resources/views/layouts/corex-sidebar.blade.php` — new nav entry under Real Estate
- `app/Console/Commands/BackfillP24ImportedStock.php` (new)
- `tests/Feature/Properties/ImportedStockTest.php` (new)
