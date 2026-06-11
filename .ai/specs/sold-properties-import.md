# Spec: Sold Properties Import

**Status:** In build (AT-24) — 2026-06-11
**Owner:** Andre
**Pillars:** Property (primary), Agent (User), Deal-adjacent (sold record)

---

## What this feature does and why

Home Finders Coastal exports a spreadsheet of **sold** listings from their old
portal (Propcon/P24-style export). Each row is one sold property, including an
embedded **Primary Photo** image in the first column and a free-text **Agents**
column. Today there is no way to bring this historical sold stock into CoreX in
bulk — agents would have to re-key every property by hand.

This feature adds a **super-admin-only** bulk importer on the Properties page
that reads the `.xlsx`, creates a real `Property` record per row (the Property
pillar — Agency Stock, not Tracked Properties), assigns the matched CoreX
agent(s), marks each property **Sold** (status + `property_sold_records` row),
and uses the embedded Primary Photo as the listing image.

This connects historical sold data to the Property pillar so it feeds
Presentations (sold comps), agent performance, and suburb intelligence — rather
than living as a dead spreadsheet.

---

## Pillar connections

| Pillar | Read | Write |
|--------|------|-------|
| Property | match-free (always create per chosen policy) | creates `properties` row, `images_json`, marks `status = sold` |
| Agent (User) | matches `Agents` cell against `users.name` / email | sets `properties.agent_id` (+ `pp_second_agent_id` for a 2nd agent) |
| Sold record | — | inserts `property_sold_records` (sold_price, sold_date, source) |

---

## Source file shape (`Listings.xlsx`)

Header row 1, data rows 2..N. Embedded JPEG drawing anchored in column **A**
per data row (one image per property). Columns:

| Col | Header | Maps to |
|-----|--------|---------|
| A | Primary Photo | embedded image → `images_json[0]` |
| B | Address (multi-line) | `title`, `address`, `street_number`/`street_name` |
| C | Category | `category` |
| D | Type | `property_type` |
| E | Status | (always treated as Sold) |
| F | Status Type | `listing_type` (Sales→sale, else rental) |
| G | Price | `price` (int, commas stripped) + sold record `sold_price` |
| H | Region | `suburb`, `city`, `province` (comma split) |
| I | Mandate | `mandate_type` |
| L | Bed | `beds` |
| M | Bath | `baths` |
| N | Garage | `garages` |
| P | Floor Size | `size_m2` |
| Q | Erf Size | `erf_size_m2` |
| R | Rates | `rates_taxes` |
| S | Levy | `levy` |
| T | Keywords | `features_json` (flat string array) |
| U | Tags | `description` |
| V | Reference Code | `external_id` |
| W | Code | `p24_listing_number` |
| AA | Listed | `listed_date` |
| Z | Modified | sold-record `sold_date` (proxy; falls back to Listed, then today) |
| AC | Expire | `expiry_date` |
| AD | Agents | matched to `users` → `agent_id`, `pp_second_agent_id` |

Columns are resolved by **header name** (case/space-insensitive), not by fixed
letter, so column re-ordering in future exports does not break the importer.

---

## Decisions (confirmed with product owner, 2026-06-11)

1. **Access:** Super-admins only (`isOwnerRole()` / `super_admin` middleware).
   No agency-level permission key — gating is role-based like other owner tools.
2. **Re-import policy:** Always create new. No dedupe on Reference/Code.
3. **Unmatched agent:** Import the property anyway with `agent_id` left null,
   and surface the unmatched name in the result summary.

---

## Data model / migrations

No new tables or columns. Reuses:
- `properties` (Property pillar, Agency Stock tier)
- `property_sold_records` (existing — `2026_05_06_000012`)
- `property_marketing_activities` (existing — logs `marked_sold`)

`agency_id` auto-fills via `BelongsToAgency` from the acting super-admin's
`effectiveAgencyId()` (the agency switched into — enforced by the existing
`agency.required` middleware on the properties route group).

---

## UI placement and navigation

- Button **"Import Sold"** in the Properties index header
  (`resources/views/corex/properties/index.blade.php`), rendered only when
  `auth()->user()->isOwnerRole()`.
- Upload page at `GET /corex/properties/import-sold`
  (`resources/views/corex/properties/import-sold.blade.php`): file input +
  explanation + (after POST) a result panel listing created count, skipped
  rows, and unmatched agent names.

No new sidebar entry — the importer is an action on the existing Properties
page (which already has a sidebar entry), reached via the header button.

---

## User flow

1. Super-admin opens Properties → clicks **Import Sold**.
2. Selects the `.xlsx` and submits.
3. Server parses each row, matches agents, extracts the Primary Photo,
   creates a Sold `Property`, writes a `property_sold_records` row.
4. Result page shows: N created, list of per-row issues, unmatched agent names.

---

## Permissions required

- Route group middleware: `permission:access_properties`, `agency.required`
  (inherited) **plus** `super_admin` on the import routes.
- View button gated by `isOwnerRole()`.

---

## Acceptance criteria

- [ ] A super-admin sees the **Import Sold** button; non-owners do not.
- [ ] Uploading `Listings.xlsx` creates one `Property` per data row.
- [ ] Each created property has `status = 'sold'` and a matching
      `property_sold_records` row (sold_price, sold_date, source = manual).
- [ ] The agent named in the `Agents` cell is set as `agent_id`; a second
      named agent (when present) is set as `pp_second_agent_id`.
- [ ] The embedded Primary Photo is stored under
      `storage/app/public/properties/{id}/` and is `images_json[0]`.
- [ ] Address, price, beds/baths/garages, sizes, rates/levy, mandate, type,
      category, reference codes, and dates are populated.
- [ ] Unmatched agent names are reported, the row still imports.
- [ ] Non-owner POST to the import route gets 403.
- [ ] Feature test green; `scripts/dev-check.ps1` passes with 0 new failures.

---

## Files to create / modify

**Create**
- `app/Services/Properties/SoldPropertyImporter.php` — parse + create.
- `app/Http/Controllers/CoreX/SoldPropertyImportController.php` — form + run.
- `resources/views/corex/properties/import-sold.blade.php` — upload + results.
- `tests/Feature/Properties/SoldPropertyImportTest.php` — coverage.

**Modify**
- `routes/web.php` — add `import-sold` (GET) + `import-sold.run` (POST) under
  the properties group, gated `super_admin`.
- `resources/views/corex/properties/index.blade.php` — header button.
