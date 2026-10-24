## 1. Investigation — what exists today (confirmed against live QA1 data, not assumed)

Johan's own account, quoted in the task: *"leases are a REPLACE, NOT EXTEND: three unlinked schemas,
partly not multi-agency-aware, frozen since February 2026, with 58 legacy rows that migrate across,
nothing deleted."* Confirmed/corrected below — the 58-row figure is exactly right; the "three schemas"
count is off by one.

### 1.1 Four existing schemas, not three

| Schema | Table | Model | Property link | Agency-aware | Live rows (QA1) | Last touched |
|---|---|---|---|---|---|---|
| A | `rentals` | `Rental.php` | **None** — free-text `lease_address` only | No — `BelongsToBranch` only, no `agency_id` | **59 total, 58 not soft-deleted** | 2026-07-14 (scoping patch, not a feature) |
| B | `properties` (columns) | `Property.php` | N/A — same row | Yes, fully | 6 have `lease_start_date`, 4 have `lease_end_date`, 950 have `rental_amount` (of 9,143 properties) | 2026-09-10 (Rental tab UI move) |
| C | `lease_records` | `Docuperfect\LeaseRecord.php` | Nullable `property_id`, **no FK constraint** | **No** — documented gap, patched only at query layer (`LeaseRecord.php:89-91`) | 2 | 2026-07-21 (security patch) |
| D | `rental_properties` | `Rental\RentalProperty.php` | **None** — free-text address | Added Aug 2026, absent before | 2 | — |

**"58 legacy rows" is confirmed, exactly, and is schema A (`rentals`) only.** No other schema comes
close, individually or combined. **"Three unlinked schemas" is off by one — there are four.** Johan
most likely wasn't aware of schema D, a low-traffic DocuPerfect landlord-prefill helper — it's real,
live code (used by `RentalDivisionController` and document prefill), but it carries **no tenant and no
lease dates at all**. It is not actually lease-shaped in substance, and I'd exclude it from "58 rows
migrate across" framing — flagged explicitly in §6 rather than silently folded in.

**"Frozen since February 2026" is approximately right for feature work, not literally true.** Both
`rentals` and `lease_records` received commits as recently as July 2026 — but both were
scoping/security patches (closing a cross-tenant leak), not lease functionality. Schema B
(`properties`' own columns) is the exception: actively touched as recently as 2026-09-10.

### 1.2 A signed lease document — structured data exists, but the auto-population is broken today

`SignatureService::createLeaseRecord()` (`app/Services/Docuperfect/SignatureService.php:5359-5401`)
is real: on a signed document detected as a lease (keyword match on name/type —
`isLeaseDocument()`, line 5332), it reads the document's typed `fields_json` (structured merge-field
values, not just rendered text — confirmed, `docuperfect_documents.fields_json`) and writes a typed
`LeaseRecord` row.

**I checked this myself and it's broken for at least the flagship lease template.**
`extractLeaseFields()` (line 5406-5417) looks for the key `lease_start_date` (or `commencement_date`/
`start_date`); the actual field on `lease-agreement-popi-v8.blade.php:485` is named `lease_start`.
None of the three candidates the extractor checks match. This is very likely why only 2 `lease_records`
exist against 58 manually-captured `rentals` rows — the mechanism is real in shape but doesn't reliably
fire.

**Conclusion for this spec:** a signed lease document CAN populate structured lease data automatically
— the pattern (typed field storage → keyword-triggered extraction → typed record) is sound and worth
keeping — but it needs to be rebuilt correctly against the new `Lease` model (matching real field names
per template, not a fixed guess-list), not repointed as-is. Until that's rebuilt, an agent retypes the
terms; this spec does not assume auto-population works on day one.

### 1.3 What rental-application approval creates today, and what changes

`RentalApplicationController::linkTenantProperty()` (line 1102-1199) is agent-triggered (never
automatic on `approve()`), resolves a scoped `Property` and the applicant's `Contact`, and writes a
`contact_property` pivot row (role `tenant`) via `ContactPropertyLinker::link()`.

**I confirmed the uniqueness constraint myself**: `(contact_id, property_id)` is the unique key on
`contact_property` — role is NOT part of it, meaning a contact holds exactly one role per property,
ever (`ContactPropertyLinker.php:96-98`, comment confirms this is Johan's own rule). This means
multiple *different* contacts CAN each hold `tenant` against the same property today (joint tenants
are not blocked by this constraint) — but there is no date-range, no lease-period scoping, and no way
to tell which tenancy a given `tenant` link belonged to once a property has had several. This is
exactly the gap Johan is describing.

**Is swapping this to "create a Lease" easy?** Reasonably, with two real gaps, not a rewrite:
1. The method already has the right trigger point and already resolves the right Property + Contact —
   extending it to also create a `Lease` row is a contained change at one call site.
2. **Gap 1** — no rent amount or lease dates flow through this action today. `approved_rental_amount`
   is populated on only 66 of 423 applications; there is no lease start/end date captured anywhere in
   the application flow (`rental_term_months` describes the applicant's *requested* duration, not a
   lease's actual dates). New UI/data-capture is needed here, not just a rename.
3. **Gap 2** — the property-status-flip to `let_out` on tenant-link was already built once and
   deliberately pulled (documented in `.ai/specs/rental-applications.md:12579-12614`) after an
   investigation found the off-market/portal-delist path doesn't safely cover a rented lifecycle.
   Building `Lease` does not resolve this pulled decision on its own — whatever eventually consumes
   "a Lease is now active" (a status flip, a portal re-tag) still needs that separate decision made,
   or it risks reintroducing the same live-portal-withdrawal incident that got it pulled the first
   time. **Not decided in this spec — flagged for whoever builds this integration.**

### 1.3a Gap 1 closed, 2026-09-15 (conductor ruling, "C") — Gap 2 deliberately untouched

> **SUPERSEDED 2026-10-07 (cc2, Johan's QA1 test):** linking a property to an approved application no longer creates a lease. It used to create lease 93 already ACTIVE, rent copied from the approved amount, with no agreement. See §15.25 — the approved application's "Continue to the lease" opens the §15.3 capture screen; the lease, the application's property link and the tenant link are all written only when that screen is completed.

> **Superseded 2026-10-07 (cleanup, cc2):** the "do NOT touch property status" carve-out below was closed by Johan's Gate 2 approval of AT-444 item 7 — see §12.5 point 1: activating a lease (including the one created here) lets the property out. `LeaseFromApprovalTest` asserts `let_out`, not "untouched".

Johan's ruling: build the lease creation on approval, capturing rent, deposit and dates in that flow,
with one explicit carve-out — do NOT touch property status. That is Gap 2 above, and it stays
Johan's call to make, pending.

`linkTenantProperty()` now additionally accepts `rental_amount`, `deposit_amount`, `lease_start_date`,
`lease_end_date` in the same request, and — when they're actually provided — creates a `Lease` +
`LeaseTenant` row (source `rental_application`, `rental_application_id` set) after the existing
`contact_property` link succeeds, and activates it via `LeaseActivationService`. Idempotent: a resubmit
or a race never creates a second `Lease` for the same application
(`Lease::where('rental_application_id', ...)->exists()` guard). If activation fails (a genuine overlap
— another lease already active on that property), the Lease is still created and the rent/deposit/dates
the agent just typed are never lost, just left as `draft` with a warning surfaced on screen naming the
conflict, rather than silently discarded.

The property-status code path (§0's "Deliberately does NOT touch `$property->status`" comment) is
completely untouched by this change — the new lease-creation block sits entirely separate from it in
the method, and the existing comment explaining why status stays untouched was left exactly as it was.

### 1.3b REGRESSION, found by cc1's baseline check 2026-09-16, and the fix

The first version of §1.3a made `rental_amount` and `lease_start_date` **required** on
`linkTenantProperty()`. That is the same endpoint the tenant-link flow has always used — every
pre-existing caller (`RentalApplicationTenantPropertyLinkTest`'s five tests, and by extension any real
caller that only ever knew about `property_id`) posts only `property_id`. With the new fields required,
those requests failed validation before the `contact_property` pivot was ever written — **and the
controller still redirected**, so the caller saw "success" while nothing happened. A silent failure on
an existing, working flow, caught only because cc1 baseline-checked the pre-existing suite against the
branch rather than trusting the new tests alone.

**Caller audit, done properly this time** (exhaustive grep across `*.php`/`*.blade.php`/`*.js` for the
route name and controller method — see the fix commit for the exact command): exactly one production
caller exists — the form in `resources/views/corex/rental-applications/view-readonly.blade.php`, the
same one §1.3a's own build modified to always send lease terms. No other blade view, no JS/fetch call,
no mobile API endpoint, no other controller references this route. Two test files reference it: the
restored pre-existing suite, and this feature's own new one.

**The fix:** `rental_amount`, `deposit_amount`, `lease_start_date` are all `nullable` again — validated
for shape when present (`required_with` cross-validation so a half-formed submission, one of the two
required-together fields without the other, is rejected rather than silently creating a broken lease),
never required outright. Lease creation is gated on `$request->filled('rental_amount') &&
$request->filled('lease_start_date')` — checked via `Request::filled()`, not `$validated` key
presence, since Laravel's `validate()` does not reliably include a key for a `nullable` field that was
never submitted at all. A caller that sends only `property_id` gets exactly the pre-existing behaviour:
the tenant link, and nothing else. The one real caller (the approval screen) still always sends both
fields together via required HTML attributes, so in real use approval still creates the lease — the
whole point of §1.3a still holds; only every OTHER possible caller of this shared endpoint is protected
from this class of failure now, not just the one this feature happened to touch.

Verified by walking BOTH shapes for real against `corex_qa1_walkthrough` (never live QA1), checked at
the database level rather than trusting the redirect status alone — the exact thing that hid the bug
the first time: a with-terms POST and a without-terms POST, each confirmed via a direct query
afterward (`contact_property` row exists either way; `leases` row exists only for the with-terms case).
Automated: the restored pre-existing suite (5/5, unweakened, not a single assertion changed) plus four
tests on this feature's own suite covering both shapes explicitly, including the exact "no lease terms
→ tenant link works, no Lease created" case that was broken.

### 1.4 The Rental tab today — Johan's description is aspirational, not current state

Read directly (`resources/views/corex/properties/show.blade.php:4049-4299`, governing spec
`.ai/specs/rentals-shared-screens.md` §11, status COMPLETE 2026-09-10). The tab renders exactly 17
fields — monthly rental, deposit, rental price type, lease start/end date, lease period/type, price
per day/week/year, has-deposit, commission %, admin fee, marketing fee, furnished status, availability
date, water/electricity/levies-included. **No tenant, no landlord, no party information, no link to
any lease/tenancy record anywhere on this tab.** The governing spec's own gap table confirms it
plainly: *"Tenant link (who currently leases it)... Not built — never in scope for any of Parts 1-5."*
Johan's description ("the lease / parties / and whatever else") is the tab's *intended future state*
once Leases exist — this spec is what fills that gap, not a correction of a wrong prior claim.

---
