# Rental Menu / Shared-Screen Lens — Implementation Investigation

Date: 2026-09-09. Read-only investigation, no code changed. Johan's settled architecture:
"yes we use the same for everything, and we just lock down what it shows so everything
always works with 1 fix." One set of screens (Contacts, Properties, Core Matches, Pipeline),
each with a rental lens, not duplicate screens.

This is a notes file for whoever writes the eventual `.ai/specs/` spec — not the spec itself.

---

## 1. The rental/sale flag

- **Property**: `properties.listing_type` (`'sale'|'rental'`) — reliable, present since
  migration `2026_03_24_093448_add_listing_type_to_properties_table.php`, normalised by
  `2026_08_30_000007_normalise_property_listing_type_canon.php`. `Property::isRental()`
  helper exists and is already used in the properties index view.
- **ContactMatch**: `contact_matches.listing_type` — reliable, same two values, present
  since original table creation (`2026_03_07_100001_create_contact_matches_table.php:15`,
  `default('sale')`). `MatchingService.php:338-345` already hard-filters on it (strict
  equality — "sale vs rental are different markets").
- **Contact**: no flat column. Determined relationally via `ContactType::CANONICAL`
  (`seller|buyer|lessor|lessee`) and `contact_property.role` pivot values
  (`landlord|lessor` vs `seller|owner`/`buyer`). Consistent and queryable, just not a
  single column — this is fine, not a defect, but the lens filter for Contacts must go
  through the relational path (`whereHas('properties', ...role...)` or `ContactType`),
  not a `listing_type` column that doesn't exist on this model.
- **Deal**: **no rental value exists today, anywhere.** `deals.deal_type` and
  `deal_pipeline_templates.deal_type` are native MySQL `ENUM('bond','cash','sale_of_2nd')`
  — sale financing method, not sale-vs-rental. Adding `rental` requires a real
  `ALTER TABLE ... MODIFY COLUMN` migration on both. **This is the one place the flag is
  missing outright, not inconsistent — flag this now, first thing to fix, before Pipeline
  work starts.**
- Verdict: the flag is reliable and consistent everywhere it exists. Properties and
  ContactMatch have it as a real column; Contact derives it relationally (fine); Deal
  doesn't have it at all yet (real gap, first fix).

## 2. Per area

### Contacts — cheapest of the four, no session risk
- Single query-build point: `ContactController::index()` builds one `$query` object,
  every filter chains onto it before `->paginate()`. One more `if` block is the whole change.
- **No session persistence at all** in this controller (confirmed via grep — zero
  `session(` calls). Nothing to fight, unlike Properties.
- No existing ceiling on `?type=` today — any user can request any of the 4 canonical
  types via the URL. A hard lock would be genuinely new code (a `$lockedListingSide`
  resolved server-side from the ROUTE, not the request — never overridable).
- Sales-side risk: isolated. No other controller/view reuses this query-building code.
  `export()` is a separate action that would need the same lock applied separately if
  rentals ever needs export too.
- Blade dropdown (`corex/contacts/index.blade.php:479-483`) already iterates a
  `$contactTypes` collection the controller builds — hiding buyer/seller in a locked
  rental context is filtering that collection server-side before it reaches the view.
  No Blade change needed.
- Minimum change: add `lockedListingSide` param, resolved from route; when set, filter
  `$contactTypes` to lessor/lessee before passing to view, and chain one
  `whereHas('properties', ...)` clause onto `$query` in the same spot the existing
  `type` filter lives.

### Properties — most-already-built UI, but a real session leak risk
- `listing_type` is already a working, user-togglable filter
  (`resources/views/corex/properties/index.blade.php:355-358` — "For Sale"/"For Rental"
  dropdown, live today) — most cosmetically finished of the four areas.
- **But** it's stored in a MUTABLE SESSION KEY: `PropertyController::index()` has
  `$SESSION_KEY = 'corex.properties.filters'` and a `$FILTER_KEYS` array (13 keys,
  includes `listing_type`) that persists the WHOLE filter set across navigation until
  `?clear=1` or session end, with bare-visit-redirects-to-saved-filters behaviour.
- **This is the leak risk**: a naive "rental menu" that just defaults
  `listing_type=rental` can be silently overridden by a stale `sale` value already sitting
  in the user's saved session filters, or the user can flip it straight back via the
  existing dropdown. A locked rental context needs a hard, route-resolved override applied
  AFTER (or instead of) the session-restore logic — the session mechanism must never be
  allowed to set the locked value, and the dropdown itself should not render (or should be
  disabled) in the locked context, mirroring the Contacts approach.
- Minimum change: same `lockedListingSide`-from-route pattern as Contacts, applied as an
  unconditional `->where('listing_type', $locked)` that ignores both `$request->query()`
  and the session-restored value entirely when set; hide the For Sale/For Rental dropdown
  in the locked view.

### Core Matches — actually the cheapest: the scope already exists, unused
- Two independent query-build points, both in `ContactMatchController.php`, no
  `SharedMatchController` involvement (that serves the separate buyer-facing portal):
  - `index()` (`:65-70`) — agent's own list, zero `listing_type` filter today.
  - `allView()` (`:121-133`) — branch/agency oversight list, same, zero filter today.
- `results()` (`:232-247`) needs NO change — already correctly locked per-match via
  `ClientMatchResolver` → `MatchingService::propertiesForMatch()`'s existing strict
  `listing_type` equality (`MatchingService.php:338-345`), reinforced by a belt-and-braces
  PHP filter in `match-results.blade.php:140-157`.
- No session persistence in either `index()` or `allView()` (confirmed, zero `session(`
  hits) — no leak risk to design around.
- No client-overridable `listing_type` param exists on the list screens today — nothing
  to clamp, this is a clean net-new addition.
- Generation-time (which properties match a match record — already enforced) and
  display-time (which match records show on the list — not enforced) are two different,
  non-conflicting layers; adding a display-time filter doesn't touch or duplicate the
  generation-time one.
- **Minimum change: call the EXISTING, already-written, currently-UNUSED
  `ContactMatch::scopeForListingType()` (`app/Models/ContactMatch.php:171-174`) in two
  places — `index()` line ~68 and `allView()` line ~124.** Two call sites, zero new
  scope code. This is the cheapest single change of any of the four areas.
- Minor follow-up (copy check, not query-layer risk): list-screen contact cards should be
  checked for hardcoded "Buyer/Seller" language that would look wrong for lessor/lessee
  contacts once a rental lens is live.

### Pipeline — most expensive, and the premise needs correcting
- **Correction to earlier assumption**: `DealV2Controller` is soft-retired.
  `index/overview/create/createWizard/show/edit` all early-return `dr2RetiredRedirect()`
  (lines 44/87/196/205/402/611). Only write-side actions (`store/update/destroy`,
  document endpoints, search, `exportCsv`) are still live and used internally — this is
  NOT the user-facing pipeline anymore.
- **The real live Pipeline** is `App\Http\Controllers\Dr2\PipelineController` /
  `PipelineTimelineController` / `PipelineListController`, built on the **`deals`** table
  (DR1/DR2 shared, `routes/web.php:816-819`), with `DealStepInstance`/DealV2 pipeline-step
  tables layered on as a pure tracking overlay. The classic board view is itself retired
  (`PipelineController.php:60-67`) — live surfaces are Timeline + List only.
- `deal_type` is largely vestigial on the live path: AT-334 replaced simple
  deal_type→template selection with a composable "Deal Structure" assembled from
  `Dr2ConditionCatalog` (bond/cash/sale_of_another conditions) via `DealStructureAssembler`.
- Schema cost: `deals.deal_type` and `deal_pipeline_templates.deal_type` are native
  MySQL ENUMs — adding `rental` needs a real `ALTER TABLE ... MODIFY COLUMN` migration on
  both, not just a validation-rule edit. `deal_type` is already nullable so a rental row
  wouldn't hit a NOT NULL wall.
- **The actual cost is content, not plumbing.** `Dr2ConditionCatalog::CONDITION_ORDER`
  (bond/cash/sale_of_another) and every step the assembler generates is 100%
  sale-transaction-shaped — bond application/approval, guarantees, deposit, proof of
  funds, transfer/registration. Zero lease/rental condition keys exist anywhere. A locked
  rental lens today would show either an empty pipeline or force-reuse of steps that make
  no sense for a lease (no "bond approval" on a rental).
- Genuine positive precedent already shipped in this exact module:
  `DealRegisterController::propertyContacts()` (lines 876-896) is already
  listing-type-aware — a rental property's landlord/lessor pulls through as the seller-side
  party via `Property::sellerSidePivotRolesForListingType()`/`buyerSidePivotRolesForListingType()`,
  proving the "one implementation, rental-aware via canonical maps on Property" pattern
  already works in production, just not yet extended to the step-generation engine.
- **Honest cost split: filter/lock plumbing ≈ 10-15% of the work. New lease-shaped
  pipeline content (lease signing, deposit, move-in inspection, renewal/expiry conditions
  authored into the condition catalog) ≈ 85-90%.** Pipeline is NOT a cheap first slice —
  it needs real content-authoring work before a rental lens on it means anything.

## 3. The menu / lens persistence

- **Recommendation: route-based lens, not session-based.**
- Precedent considered and rejected: agency-switching (`session(['active_agency_id' => ...])`,
  set in `AgencySwitcherController::switch()`, read in `AgencyScope`/`BranchScope`/
  `ContactScope`/`DealBranchScope`/`BelongsToAgency`). PHP sessions are per-login-cookie,
  not per-tab — two tabs under one login share one session. Tolerated for agency-switching
  because it's a deliberate, infrequent action. A rentals-vs-sales lens is the opposite:
  an agent plausibly has a sales tab and a rentals tab open side by side, switching
  constantly — a session-based lens would reproduce exactly the cross-tab leak Johan is
  worried about, in the highest-frequency-switching case in the codebase.
- **The correct model, already proven in production tonight**: the `$viewerRole` pattern
  from the rental-applications agent/authoriser merge. `$viewerRole` is a literal
  hardcoded string set INSIDE each controller method, never read from session or request —
  `RentalApplicationReviewController.php:192: $viewerRole = 'agent';` vs
  `RentalApplicationAuthorisationController.php:254: $viewerRole = 'authoriser';` — two
  distinct route trees, two controllers, one shared Blade view branching purely on that
  variable. No query string, no session flag, nothing user-editable in the loop.
- Applied to rentals: a `/corex/rentals/{contacts,properties,matches,pipeline}` route
  group whose controller actions hard-set `context = 'rental'` server-side (or call the
  existing sales controller action with that context injected), never derivable from
  anything the user can edit. This is the same mechanism as the "locked from route" answer
  given independently for Contacts and Properties above — one consistent pattern across
  all four areas.
- **Sidebar mechanics**: `corex-sidebar.blade.php` has no generic data-driven nav loop —
  each panel is hand-written Blade with hardcoded `<a href="{{ route(...) }}">` links.
  Adding a fourth set of sub-links is mechanically trivial (copy the existing block,
  4 more `<a>` lines).
- **Naming flag for Johan, not an engineering obstacle**: there would then be up to
  THREE things labelled "Rentals" in the sidebar — the existing rental-applications nav
  group (`corex-sidebar.blade.php:1757-1837`), the legacy hidden Rentals system
  (`:1866-1893`, parked per Johan's own standing instruction, currently unhidden for his
  QA1 evaluation), and this new rental-lens menu. Needs deliberate disambiguation in the
  spec — Johan's call on labelling/grouping, not an engineering decision.

## 4. Sizing, honestly, and cheapest-first

| Area | Query-layer lock | Sales-side risk | Cost driver |
|---|---|---|---|
| **Core Matches** | 2 call sites, existing unused scope (`ContactMatch::scopeForListingType()`) | None found | Plumbing only — cheapest |
| **Contacts** | 1 insertion point, net-new lock (no ceiling exists today) | Isolated, no shared callers | Plumbing only — cheap |
| **Properties** | 1 insertion point, but must defeat an existing mutable session-filter mechanism | Must not regress the working session-filter UX for sales | Plumbing + one real design decision (session vs. hard route override) — cheap-to-moderate |
| **Pipeline** | Real, but on the WRONG controller (DealV2 is retired) — actual target is `Dr2\PipelineController` on `deals` | None found at the code level (rental deals don't touch sale-only logic paths yet) | Enum widening (real migration) + **85-90% is new lease-shaped pipeline content**, not plumbing — most expensive by far |

**Recommendation: build Core Matches first.** It has the least risk (no session
mechanism to defeat, no shared callers, nothing user-overridable to clamp) and the
smallest diff (two call sites onto an already-written scope). It's a genuine, complete,
demonstrable proof of the "one screen, query-layer-locked, one fix forever" pattern that
Johan can review before the other three are built. Contacts is the next-cheapest and a
reasonable second. Properties needs one real design decision about the session-filter
mechanism, made once, then it's cheap. Pipeline should be built last and budgeted
separately — its cost is almost entirely new business content (lease-stage steps), not
engineering the lens itself, and that content-authoring work has not been scoped or
spec'd at all yet.

## 5. Existing spec — confirmed, none exists

`.ai/specs/*.md` mtimes are uniform (2026-09-09 17:15:35, a bulk checkout event) except
`rental-applications.md` (actively being worked on separately). Full-text search for
"rental menu"/"rental contacts"/"rental properties lens"/"rental pipeline"/"rental lens"
across all specs finds only one hit: `.ai/specs/deals.md:29` —
`"Rental pipeline view (visual: mandate → lease → active → renewal)"` — the same
one-line placeholder already found in `.ai/ROADMAP.md:37-39`, not a spec.
`.ai/CHAT_STARTER.md`'s "Recent decisions log" (checked back through 2026-07-21) has no
entry for this architecture decision. **Nothing exists beyond the one-line placeholder
already reported — confirmed, not duplicated.**

## Also flagged (from earlier this session, still relevant)

The legacy Rentals system (`RentalsController`, `Rental`/`RentalAmountVersion`/
`Rental\RentalProperty`/`RentalAgent` models, `Rental\RentalDivisionController`) already
owns the words "leases," "rental property," and "Active/Expired Leases" — real,
functioning, currently sitting in the sidebar's Hidden panel (unhidden for Johan's own
QA1 evaluation, PARKED per his standing instruction — leave exactly as is). Any rental-menu
spec must explicitly decide build-on / replace / retire for this system before work starts,
since it overlaps directly with what Johan described.
