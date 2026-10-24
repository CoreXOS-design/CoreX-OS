## 14. Soft-deleted related-record render fix (2026-10-05) — BUILD_STANDARD §4/§6

Confirmed bug on QA1: `/corex/leases/2` 500'd. Lease #2's property (#1092) had
been soft-deleted 2026-06-25. `Lease::property()` was a plain `belongsTo`
(excludes trashed), so `$lease->property` resolved to `null`; the Lease Hub's
"Link landlord" fallback (`leases/show.blade.php`) passed that `null` straight
into `route('corex.properties.show', $lease->property)`, which throws trying
to resolve the `{property}` route parameter — never a graceful 404, a hard 500
on page load.

Fixed as a **class**, not the one instance (BUILD_STANDARD §6): every BelongsTo
relation across the rentals models that points at Property/Lease/Contact/
supplier now carries `->withTrashed()`, matching the pre-existing
`RentalWorkOrder::property()` precedent (the one relation in this family that
already had it). The parent record is never null just because it was
archived; the trashed object loads and `->trashed()` tells the view so.

**Models changed:** `Lease` (`property()`, `rentalApplication()`,
`previousLease()`, `renewedLease()`), `LeaseTenant` (`lease()`, `contact()`),
`RentalFaultReport` (`property()`, `lease()`, `reportedByContact()`),
`RentalWorkOrder` (`lease()`, `supplier()`, `reportedByContact()` —
`property()` already had it), `RentalWorkOrderQuote` (`supplier()`),
`RentalJobCard` (`property()`, `lease()`), `RentalInspection` (`lease()`,
`property()`), `RentalInspectionItem` (`property()`), `RentalNotice`
(`lease()`), `RentalInventory` (`property()`, `lease()`), `RentalApplication`
(`contact()`, `property()`).

**View rule applied everywhere one of these relations renders:** show the
address/name with an `(archived)` marker; never link to that record's own
`show` route if it's archived — default route-model binding 404s on a
trashed record, so a link there is a dead end even though it no longer
crashes. Fixed this way: `leases/show.blade.php` (the landlord-link fallback,
the previous/renewed-lease links), `leases/index.blade.php` (the landlord
cell), `components/rental-context-bar.blade.php` (the shared bar included by
leases/show, fault-reports/show, work-orders/show, job-cards/show,
inspections/show — property/lease/inventory/documents chips and the
landlord-link all gated on `->trashed()`), `rental-notices/show.blade.php`
(two separate unguarded `route()` calls — the property line and the
"Back to Lease Hub" link off `$notice->lease`, found during the sweep, same
bug class, previously undetected because no soft-deleted lease had ever hit
a notice before), `rental-inventories/partials/_related-inventories.blade.php`
(shared by leases/show and rental-inspections/show), `rental-job-cards/show.blade.php`
(the quote-screen "Link landlord" fallback), plus archived-marker display
fixes on the fault-reports/work-orders/job-cards/inspections/notices/
rental-applications list and show screens, and the Command Centre queue row
partial. `rental-applications` views also gained `?->` nullsafe access on
`$application->contact` (was a bare `->`, logged a PHP warning rather than
crashing, but inconsistent with every other contact access in the same
files).

**Proof:** `tests/Feature/Rentals/SoftDeletedRelatedRecordRenderTest.php` —
one test per screen (leases show/index, fault reports, work orders, job
cards, inspections, notices — including a soft-deleted *lease* variant, not
just property — rental applications with both property and contact
soft-deleted, the Command Centre), each asserting 200 against a fixture with
a soft-deleted property/lease/tenant contact attached, plus a dedicated
regression case reproducing the exact lease-with-archived-property shape
that 500'd on QA1. 12/12 passing. Verified over real HTTP against QA1 after
deploy: `GET /corex/leases/2` → 200 (previously 500).

**Not touched, considered and ruled out of scope:** `LeaseEscalation::lease()`
and `LeaseEvent::lease()` (reverse BelongsTo, never dereferenced from a
render path in this sweep — `$lease->escalations`/`$lease->events` are the
only call sites and are HasMany, unaffected); `Lease::branch()` (Branch
archiving is a separate, much rarer admin action, not part of this bug
class — flagged, not fixed, per BUILD_STANDARD §2 "report, don't silently
expand scope").
- `app/Services/Rentals/LeasePropertyResolver.php` — comma/slash normalisation

---
