## 13. AT-439 (Rentals rebuild 1/7, "Foundation") — Part 1 fixes, 2026-10-04

Built strictly from `/tmp/rentals-stage1-investigation.md` (the prior read-only audit's root-cause
findings). Six items landed on `at439-rentals-foundation-2026-10-04` off `QA1`. Everything below is
already live in the code — this section documents what changed and why, it does not propose anything.

**Own/Branch/All scope on the Leases list (§C of the investigation).** `LeaseController::index()`
already called `Lease::visibleTo()` (own/branch/all query-layer scoping existed); it had no UI control
to let a user with a wider ceiling actually choose a narrower/wider view, and no screen told them the
control existed. Fixed: the same "Showing: Own | Branch | All" pill control `rental-applications`
already has (`$scopeOptions` built from `PermissionService::getDataScope($user, 'leases')`, never
offering a wider pill than the user's role permits; highlighted from `$resolvedScope`) now renders on
`corex/leases/index.blade.php`. **Per-record scope guard, added as a class, not per-controller**: a
new trait, `App\Http\Controllers\Concerns\AuthorizesRentalRecordScope::guardRentalRecordScope()`,
mirrors `AuthorizesRentalApplicationAccess::guardRentalApplication()` generalised across Lease/
RentalFaultReport/RentalWorkOrder/RentalInspection (all four shared this exact gap, found and fixed
together per BUILD_STANDARD §6). `LeaseController`'s `show`/`update`/`activate`/`cancel`/`escalate`/
`destroy`/`restore` all now call it before touching the bound `$lease` — previously a user whose list
screen was scoped to `own`/`branch` could still open/mutate any lease in the agency by direct URL/ID.
The guard resolves "branch" exactly the way `Lease::scopeVisibleTo()` already does — `leases.branch_id`
directly (unlike the other three models, which check the record's PROPERTY's branch_id instead — the
guard takes the resolved branch id as a parameter precisely so it never has to guess which column a
given model's own list query actually uses). See `rental-work-orders.md` §(AT-439 addendum) and
`rental-inspections.md` §(AT-439 addendum) for the same fix on Fault Reports/Work Orders/Inspections.

**Renewal-reminder command repointed from the legacy table to the real `leases` table (§E).**
`CheckLeaseExpiry` (`signatures:check-lease-expiry`, daily 06:00, signature/schedule entry unchanged)
previously queried `Docuperfect\LeaseRecord` (2 test-artifact rows) — the real rentals `Lease` model
(the one this spec, `rental-work-orders.md`, `rental-inspections.md`, and `rental-inventory.md` all
hang off) got no automated expiry alert at all. Fixed: the command now queries `Lease` directly
(`withoutGlobalScopes()`, explicit — console commands run with no authenticated user so `AgencyScope`
is already a no-op here, same as `LeaseSetting`'s own existing convention, but made explicit rather
than relied-on). **Expiry is never automatic — and since 7 Oct 2026 the one thing that IS automatic is the switch to month-to-month, by a SEPARATE command (§5.3); this command still never writes lease state (Johan's ruling, corrected 2026-10-04 after an
unscoped verification run of an earlier version of this command auto-flipped three real QA1
leases to `expired`)** — a lease whose `end_date` has passed stays `active` and is only ever
FLAGGED, via the same alert, for the agent to record the real outcome (renewed / month-to-month /
notice / ended); the status change happens only when the agent acts, through
`LeaseRenewalService`/`LeaseActivationService` (AT-444), never from this command (no intermediate
"expiring soon" status exists on `Lease` either, and none is invented here). The command also
iterates agencies EXPLICITLY (`foreach (Agency::all() as $agency)`, each iteration naming
`agency_id` in its own query) rather than one bulk `withoutGlobalScopes()` query implicitly
spanning every agency at once — the explicit loop is what makes the scope visible at the call
site, after the unscoped-bulk-query shape is exactly what let a verification run touch real data
across the whole table in one call. The agency's own
`LeaseSetting::expiryNoticeWindowDaysFor($lease->agency_id)` — already live on the Lease Settings
screen and the onboarding wizard, just never called from this command — now gates how far out the
tiered urgent(≤30)/warning(≤60)/notice alerts start firing, resolved PER LEASE'S OWN `agency_id`, so
one global run correctly serves every agency's own configured window. Recipient stays exactly who it
was before — the agent (`lease->createdByUser`, the real-Lease equivalent of the legacy command's
"the e-sign document's owner") — no tenant/landlord notification added. Delivery stays
database-notification-only; `LeaseExpirationMail` (the legacy, dead email path) was NOT revived.
New notification class `App\Notifications\LeaseExpiryAlert` (NOT a modification of
`LeaseExpirationAlert`, which keeps serving `LeaseRecord` exactly as before — the two models' display
shapes genuinely differ: property address/tenant name are relations on `Lease`, denormalised columns
on `LeaseRecord`). Idempotent via the same 7-day cache-dedup pattern, under a distinct key prefix
(`lease_v2_alert_*`) so it can never collide with the legacy command's own cache keys.

**E-sign → Lease: draft promotion instead of a duplicate (§F).** `SignatureService::
createLeaseFromSignedDocument()` already created a `Lease` row from a completed lease e-sign document
(the field-name mismatch a prior audit flagged — `lease_start_date` vs the real template's
`lease_start` — was already fixed before this build). What it never did: check for an existing
DRAFT lease on the same property to promote. Matching, in order: (1) a draft already linked to the
SAME tenant the document resolves (via the already-resolved `TenantContactResolver` contact) —
takes priority so a draft already tied to a DIFFERENT, known tenant (e.g. a renewal being prepared
alongside a still-active lease) is never silently reassigned; (2) failing that, a draft with NO
tenant linked at all yet — the common case, a draft started manually before any tenant was
decided — but ONLY when exactly one such open draft exists on the property, so an ambiguous
multi-draft property is never guessed at. Fixed: a draft match now gets its terms (`rental_amount`/`start_date`/`end_date`),
`source`, and `source_document_id` set from the signed document and is PROMOTED via
`LeaseActivationService::activate()` — inside a `DB::transaction()` — instead of a second, disconnected
row being created for the same real-world tenancy. If another lease is already active on that
property (a genuine conflict with `LeaseActivationService`'s own one-active-lease guard, not the
common case), the draft's terms/`source_document_id` are still saved so the document stays linked, but
its status is left as-is rather than letting the `ValidationException` escape into the e-sign
completion cascade. `SignatureAuditLog` records `lease_promoted_from_document` vs
`lease_created_from_document` so the two paths stay distinguishable in the audit trail.

**Landlord on a lease — derived accessor, still no column (§G).** `Lease::landlordContacts()`
(N-party — returns every landlord-side contact, never collapses to one) resolves through the
PROPERTY's existing `contact_property` pivot, expressed via the property's own canonical contact-role
keys (`contactsForRole('landlord')` merged with `contactsForRole('lessor')` — the same
`Property::pivotRolesForContactRole()` vocabulary the e-sign wizard's role picker already uses)
rather than a raw hardcoded pivot-role string check written fresh in `Lease.php`. No migration, no new
column — `Lease`'s own class docblock already stated the landlord is meant to be derived from the
property's existing link, never duplicated; this method is that derivation made callable.

**Legacy-migration tokenizer fix — 3 rows only, nothing else attempted (§D).** `Property::
scopeSearchAddress()` tokenises on whitespace only, so a comma/slash stays glued to the adjacent
token (`"Alomsee 4,"` never matches a bare `"4"`). Fixed ONLY in `LeasePropertyResolver::
matchOneByAddress()` — the one method both `leases:migrate-legacy` and the e-sign auto-population
above actually call — by replacing `,`/`/` with a space in the free-text address BEFORE it reaches
the shared scope, rather than widening `scopeSearchAddress()` itself (which also drives every live
property-search box across the app; that is a different, much larger-blast-radius change than this
one legacy-matching path calls for). Verified via `php artisan leases:migrate-legacy --dry-run`
(read-only, zero writes): unresolved `rentals` rows dropped from 54 to 51 — exactly the 3 rows this
fix targets (ids 3, 5, 58) — with no new rows becoming falsely ambiguous. The other 51 (24
bad/missing-address rows, 27 genuine same-complex multi-unit ambiguity) were explicitly NOT
attempted, per the investigation's own warning that any fuzzy/first-candidate matching there risks
silently merging two different units' tenancy history. The live migration itself was not re-run
beyond this dry run — the legacy tables were not written to or deleted from.

### Files changed (§13)

- `app/Http/Controllers/Concerns/AuthorizesRentalRecordScope.php` — new trait
- `app/Http/Controllers/CoreX/LeaseController.php` — scope control + per-record guard
- `resources/views/corex/leases/index.blade.php` — "Showing:" control
- `app/Console/Commands/CheckLeaseExpiry.php` — repointed to `Lease`
- `app/Notifications/LeaseExpiryAlert.php` — new notification class
- `app/Services/Docuperfect/SignatureService.php` — `createLeaseFromSignedDocument()` draft promotion
- `app/Models/Lease.php` — `landlordContacts()`
