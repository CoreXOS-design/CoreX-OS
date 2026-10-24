## 7. The Leases list screen — CRUD/list-screen floor (BUILD_STANDARD §1a-§1d)

New screen, new nav entry, same day as the build. Route group `corex.leases.*`, sidebar entry under
Rentals, alongside the (future) Rental Inspections list.

- **Search** (named fields): property address, tenant name(s), **landlord name(s)** (added below).
- **Sort**: end date, start date, status, property address. **Default: end date ascending (soonest to
  expire first)** — stated explicitly per §1b, chosen because tracking what's coming up for renewal is
  the most common reason to look at this list day to day.
- **Filter**: status (draft/active/expired/cancelled — minimum per §1b), date range (start or end
  date), property, branch.
- **Pagination**: standard page size.
- **Empty state**: distinct copy for "no leases yet on this agency" vs "none matching this filter."
- **Scoping**: `leases`/`lease_tenants`/`lease_escalations` all `use BelongsToAgency` + `AgencyScope`;
  OWN/BRANCH layered on top via the same role pattern already used by rental applications and (planned)
  rental inspections. Direct-URL access to another agency's lease is a 404 via the global scope, not a
  hidden link.

The property's **Rental tab** additionally surfaces the property's currently-active lease (tenant(s),
dates, rent) read-only, sourced from `leases` — this is what fills the gap the tab's own governing spec
already names ("Tenant link... Not built") and is what makes Johan's original description of the tab
("the lease / parties / and whatever else") actually true once this ships.

### 7.1 Landlord shown under tenant in the same cell (2026-10-05)

Johan was testing the list and found the row showed only the tenant — no landlord. Added, same cell,
tenant on top, landlord underneath, same muted/compact text style:

- Sourced from **`Lease::landlordContacts()`** only — never `Property::sellerOwnerContact()`'s
  sole-contact fallback (the AT-444 bug class: a property linked only to its tenant must never show
  that tenant as the landlord). `landlordContacts()` already has this guarantee built in (§ above).
- No landlord linked → muted `"No landlord linked"` text, linking to the property's Contacts tab
  (`corex.properties.show` with `?tab=contacts`) — the existing "Link landlord" destination already
  used by the Lease Hub and the rental context bar.
- **Eager-loaded** (`LeaseController::filteredLeasesQuery()` now loads `property.contacts` alongside
  the existing `tenants.contact`) — `Lease::landlordContacts()` reuses the already-loaded collection
  instead of calling `Property::contactsForRole()` (which always issues a fresh query regardless of
  what's eager-loaded) when the relation is loaded; `Property.php` itself is untouched.
- Landlord name is searchable (same `q` field, OR'd alongside property/tenant) and included in the
  CSV/XLSX export (`Landlord` column, via the new `Lease::landlordNames()` helper — same shape as the
  existing `tenantNames()`).

### 7.2 The New Lease form's Property field is type-to-search (2026-10-06)

**Bug (agents testing on QA1):** on `/corex/leases/create` the Property field was a plain `<select>` filled
with up to 500 rental properties (`create.blade.php`) — it could not be typed into, so with many
properties it was unusable. The list was also built by a bare `Property::where('listing_type','rental')`
inside the view, i.e. not narrowed to the user's own/branch visibility.

**Now:** a type-to-search picker, the same pattern as the rental-application and work-order property
pickers (`RentalApplicationController::searchProperties()` / `RentalWorkOrderController::searchProperties()`).

- **Searches by** (all via the one canonical `Property::scopeSearchAddress()`): street number and name,
  address, suburb, city, complex name, unit / section, **property name (`title`)**, **property number
  (the reference)**, erf number, P24 reference. Several words narrow the result (every word must match
  something), e.g. `beach road`; `unit 14` / `erf 442` bind to that column.
- **Endpoint:** `GET /corex/leases/search-rental-properties` (`corex.leases.search-rental-properties`),
  permission `leases.create` — same gate as `create`/`store`. Returns up to 10 rows
  `{id, label, status, agent, ref}`. Distinct from `corex.leases.search-properties`, which backs the
  LIST screen's filter and only offers properties that already have a lease.
- **Scoping (query layer, never a hidden link):** rental listings only (`listing_type = rental`), then
  `Property::visibleTo($user)` (own / branch / agency per the user's `properties` data scope) on top of
  the global `AgencyScope`. `store()` re-checks the same `visibleTo` rule, so the picker never offers a
  property the save would refuse, and posting an out-of-scope property id directly is a 404.
- **Behaviour:** needs 2+ typed characters (same as the sibling pickers); debounced; stale responses
  are discarded; ArrowUp/ArrowDown move through the results, Enter picks the highlighted one (it does
  not submit the form), Escape closes the list. Editing the text after a pick clears the pick, so the
  box and the posted `property_id` can never disagree.
- **Empty / error states:** no match → "No rental properties match …" (never a blank box); a failed
  request → "Could not search properties just now. Please try again."; submitting with nothing picked
  → inline "Choose a property from the list." (client) / "Please choose a property from the list."
  (server, `property_id.required`).
- **After a validation error** the picked property comes back: `create()` resolves `old('property_id')`
  through the same scoped query, so a property the user can no longer see (or that was archived in the
  meantime) comes back empty and must be re-picked.
- **Unchanged:** opening the form with `?property_id=` (from a property screen / rental application)
  still shows that property as fixed text with no picker.
- **Lease edit has no property field** — `update()` accepts deposit, end date, month-to-month and lease
  type only (the property is fixed at creation, §6a), so there is no second property picker to fix.
- **Proof:** `tests/Feature/Leases/LeaseCreatePropertySearchTest.php`.

### 7.3 New Lease form — fields survive an error, and only a rental property the user may see (2026-10-06)

Three defects found on `/corex/leases/create` while building §7.2, fixed together.

1. **Nothing is lost after a validation error.** A bounce used to clear the tenants and every field except the
   property. Now the tenant list (in the order chosen — the first is the primary), monthly rental, deposit, start
   and end date, month-to-month, lease type and activate-immediately all come back from `old()`. Tenants are
   re-resolved from their ids under the same rule `store()` validates (same agency), so a stale or forged id
   comes back as nothing. A fresh form is blank.
2. **A lease can only be saved on a rental property the user may see.** `store()` validates `property_id`
   against the picker's own query, so a sale listing, another agent's / branch's / agency's property, a made-up
   id and a malformed id all fail the same way with "Please choose a property from the list." (no lease is
   created, nothing to enumerate; previously out-of-scope ids were a 404 and sale listings were accepted).
3. **`?property_id=` pre-fills only a property the picker could have offered.** Out-of-scope, other-agency,
   sale or malformed ids pre-fill nothing and show no error page — the form just opens with the picker.

**One shared query:** `Property::scopeRentalVisibleTo($user)` (rental listings → `visibleTo` own/branch/agency, on
top of the global AgencyScope) is used by the search endpoint, the `store()` validation and the pre-fill, so they
cannot drift. A test asserts the picker and the save agree on every fixture property for every user type.

**Status rule (Johan's ruling, 2026-10-06):** the New Lease search returns **any rental property regardless of
status** — To Let, Rented, Withdrawn, Expired, Draft and so on. Example: an agent phones a withdrawn owner who
agrees to rent the property out; it must be findable and saveable on a lease. There is deliberately **no status
filter** in the query, and each result keeps its status badge (`Property::statusBadge()`) so the agent can tell a
live listing from a dead one. **Sale-only listings (`listing_type` ≠ `rental`) stay excluded**, whatever their
status. Overlap with an existing active lease is still caught at activation (§3.5), not by hiding the property.

**Proof:** `tests/Feature/Leases/LeaseCreatePropertySearchTest.php` (sale listing refused, out-of-scope refused,
other-agency / made-up / array / non-numeric refused, in-scope still saves, picker-vs-save drift guard, withdrawn /
rented / expired rentals findable with badge and saveable, withdrawn sale-only still excluded, pre-fill cases,
full field + tenant restore round trip, forged tenant ids not restored).

---
