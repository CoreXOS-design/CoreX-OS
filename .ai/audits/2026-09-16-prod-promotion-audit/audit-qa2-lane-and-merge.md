# Audit — QA2 lane promotion to Staging/Prod (merge 9b3e787c3 + follow-ups)

Date: 2026-09-16 · Branch audited: `Prod` at 57407d5a5 · Read-only (no files changed, no tests run).
Range: 6545f0262 (common base) → 57407d5a5. Commits in scope: 9b3e787c3, 67499edca, 337c734d8, fb9fc5ee3, 8c0876f3d, b133c4e34, f444a6e1b, 991441103, 77eb85cac, 113972085 (+ 747ac18c3 merge carrying 67499edca, 57407d5a5 = CLAUDE.md only).

Method: `git show 9b3e787c3 -- <file>` (combined diff) + current file for each hand-resolved file; every `route('…')` in the seven resolved views checked against `php artisan route:list --json` (2,464 names); div open/close counts; conflict-marker grep; per-view element inventory (anchors, buttons, inputs, selects, forms, route names, click handlers, `{{ }}` expressions) diffed base→QA2 tip and QA2 tip→Prod for every view the lane touched.

---

## Ranked findings

| # | Sev | Area | Status |
|---|-----|------|--------|
| F1 | HIGH | AT-419: P24 imports with status `Rented` never reach Imported Stock; they stay on Properties and count as On Market | CONFIRMED, pre-existing on QA2 parent (the "let_out not rented" reported item) |
| F2 | MEDIUM | AT-419: property opened from Imported Stock — Back goes to Properties (where the row is not listed) and the sidebar lights "Properties" | CONFIRMED, pre-existing on QA2 parent |
| F3 | MEDIUM | Process: AT-419 and AT-420 specs are still "Draft — awaiting Johan's sign-off" while the code is on Prod | CONFIRMED |
| F4 | LOW | AT-393: "Back to Today" (Performance) and "Back to Dashboard" (User Settings) links removed in an unlabelled `fix` commit with no recorded instruction | CONFIRMED removal; authorisation unknown |
| F5 | LOW | Contacts agent-picker interpolates `branch` as a bare JS identifier (Rentals → Contacts, Branch pill): Alpine console errors, picker checkmarks/highlight don't render, filtering unaffected | CONFIRMED (code); effect from Alpine 3 error semantics |
| F6 | LOW | Imported Stock (and Properties) list has no date-range filter; empty-state copy on Imported Stock is the generic "No properties yet." | CONFIRMED, pre-existing |
| F7 | LOW | Task counts fix: mobile task list/summary and Today widget now silently exclude automation-sourced property tasks everywhere (intended, but no screen shows them any more) | CONFIRMED behaviour change, by design |
| F8 | INFO | RentalsContactsScopeDefaultTest: 3 × 403 failures pre-date the merge (Johan's lane) — not investigated here | Reported by the merge commit; unverified |

No BLOCKER found. The merge resolution itself is clean.

---

## 1. Merge resolution correctness (9b3e787c3 + 67499edca) — VERIFIED CLEAN

Checked: no conflict markers anywhere in `app/ resources/ routes/ config/`; every `<div>` balances in all seven resolved views (properties/index 79/79, properties/show 787/787, contacts/index 91/91, match-results 33/33, buyers/detail 125/125, buyers/pipeline 36/36, sidebar 124/124).

| File | Resolution | Verdict |
|------|-----------|---------|
| `app/Models/Property.php` | Staging's `MATCHING_EXCLUDED_ON_MARKET_STATUSES` / `isMatchableStatus()` / `matchingExcludedStatusList()` (L137-186) kept alongside QA2's Imported Stock scopes (L188-252). `isSoldByThirdPartyStatus()` referenced by the new method exists (L1639). | OK |
| `app/Http/Controllers/CoreX/PropertyController.php` | `$indexRouteName = $request->route()->getName()` (L69) drives every self-route; three session keys (L90-92); `$importedStock` partition at L155-159; view receives `importedStock`, `isRentalEntry`, `indexRouteName` (L515). Audit fix removed the dead `$ROUTE_NAME` alias. | OK |
| `resources/views/corex/properties/index.blade.php` | `$indexRoute = $indexRouteName ?? 'corex.properties.index'` (L11); 7 `route($indexRoute…)` uses; zero hard-coded `corex.properties.index`/`imported-stock` left. Rental KPI tiles + Imported Stock tile suppression both present (L225-252). | OK |
| `app/Http/Controllers/CoreX/ContactController.php` | After 67499edca: `'branch'` is part of `$hasAgentFilter` (L145) and handled before the `(int)` cast (L155-165); search narrows within it; `dataScope` passed to the view (L314). `$selectedAgent` uses `firstWhere('id', (int) …)` so `'branch'` → null (no query on a string). | OK (see F5 for the JS side) |
| `resources/views/corex/contacts/index.blade.php` | QA2 frozen filter cap with Staging's route-aware action (`$contactsRoute`, L111/115), rental type label, Branch pill (L237-244, rentals only), Agency/All pill, sort + direction selects (rentals only), export links carry `rental=1` (L42/47). | OK |
| `resources/views/corex/properties/show.blade.php` | Identity strip + Staging's lens-aware Back (`$backRoute`/`$backLabel`, L48-51, L83-88); AT-402 Rental tab reactivity on the sticky tab bar (L1291-1296). `$isNew` defined in-view (L8). | OK (see F2) |
| `resources/views/command-center/buyers/detail.blade.php` | One `$backBoard`/`$backRoute` definition (L16-18), used once; tenant/buyer vocabulary kept. | OK |
| `resources/views/corex/contacts/match-results.blade.php` | "Edit criteria" rendered once (action bar, L71-77); Saved date kept (L65-67). | OK |
| `resources/views/layouts/corex-sidebar.blade.php` | Properties active only when `corex.properties.*` AND not imported-stock AND no rentals lens (L834); Imported Stock item gated `@permission('access_imported_stock')` (L845-849); Rentals → Properties (L1114). | OK |
| `resources/views/command-center/buyers/pipeline.blade.php` | Single flex wrapper (L28); vocabulary block (L5-23); all board links use `$indexRouteName ?? 'command-center.buyers.pipeline'` (BuyerPipelineController passes it, L39). | OK |
| `resources/views/corex/core-matches/all.blade.php` (deleted) | Route `corex.core-matches.all` / `corex.rentals.core-matches.all` still exist (routes/web.php:4038-4059) but both hit `ContactMatchController::allView()` → `renderBoard()` → `view('corex.core-matches.index')` (L84-87, L458). No `view('corex.core-matches.all')` anywhere; only route-name strings remain (ContactMatchController L115-124). | OK — no dangling reference |

Route names: 277 distinct `route('…')` names across the seven views; 275 resolve. The two that do not — `admin.listings.import` (sidebar L1549) and `corex.admin.deal-link-review.index` (sidebar L1464) — sit inside `@if(false …)` blocks (L1451, L1548) and never execute. Pre-existing, harmless.

Filter round-trip: properties list persists filters per entry point in session and re-derives links from `$indexRoute`; pagination uses `->appends($this->paginationQuery())` (PropertyController L436); contacts Branch pill is now carried by the hidden `agent_id` input through search/type submits (view L200-202, controller L145-165).

---

## 2. The three "reported, not changed" items

### (a) Contacts agent-picker bare identifiers — F5, LOW, CONFIRMED
`resources/views/corex/contacts/index.blade.php`:
- L333 `<template x-if="!{{ $filterAgentId ? $filterAgentId : 0 }}">`
- L344 `:style="({{ $filterAgentId ?: 0 }} === agent.id ? …)"`
- L346 `:onmouseout="({{ … }} === agent.id ? …)"`
- L356 `<template x-if="{{ … }} === agent.id">`

Root cause: the PHP value is interpolated unquoted, so `'branch'` becomes the JS expression `!branch` / `branch === agent.id`. Reachable from the UI only on Rentals → Contacts via the Branch pill (L238); `'unassigned'` has no pill in this view (URL-only).
User-visible effect: Alpine 3 catches expression errors per directive (console "Alpine Expression Error: branch is not defined", ~2 + 2×N agents), does not abort the component. The "All agents" tick and the per-agent tick/highlight in the picker modal are not rendered; the list itself still filters correctly because `pickAgent()` submits the form (L95-101). Not a live functional break.

### (b) Imported Stock has no lens; Back returns to Properties — F2, MEDIUM, CONFIRMED
`PropertyController::index()` L76 `session(['corex.lens.properties' => $isRentalEntry])` — `importedStock()` (L37-40) routes through `index()` with `$importedStock = true` but the lens is the rentals boolean only, so entering Imported Stock stores `false`. `properties/show.blade.php` L48-51 chooses only between `corex.rentals.properties.index` and `corex.properties.index`; sidebar L834 lights "Properties".
User-visible: from Imported Stock → open a withdrawn/sold import → "Back to Properties" lands on the Properties list, which by construction (`scopeExcludingImportedOffMarket`) does not contain that row; the user loses their place and the wrong nav item is highlighted. No data effect. Live on Prod now.

### (c) `let_out` listed, `rented` not — F1, HIGH, CONFIRMED
Vocabulary facts (all `app/Models/Property.php`):
- `OFF_MARKET_STATUSES` L57-61: sold, sold_by_3rd_party, transferred, withdrawn, expired, cancelled, let_out, draft, archived, unavailable, prospecting, not_selling — **no `rented`**.
- `importedStockStatuses()` L210-215 = OFF_MARKET minus draft/prospecting/not_selling → the same 9 statuses as the old constant; **no `rented`**.
- `rented` IS a real stored status: `app/Services/Importer/P24ListingsCsvParser.php` L132/L226-234 `normaliseStatus()` maps CSV `rented` → `Rented`, and that value is written to `properties.status` (ConfirmP24PropertyRowJob L84). PropertyController L263-268 and L313 already special-case the "legacy capitalised 'Rented'" for the Rentals lens; `CONCLUDED_STATUSES` (L1520), `P24_ON_PORTAL_TERMINAL_STATUSES` (L320) and the outbound mapper (Property24ListingMapper L1614) all treat `rented` as terminal.
- The spec `.ai/specs/at419-imported-stock.md` L28 says: "Every non-active status (withdrawn, sold, expired, cancelled, **rented**, etc.) from an import lands on Imported Stock".

Verdict: a P24-imported rental whose CSV status is `rented` lands on **Properties**, not Imported Stock (the `LOWER(status) NOT IN (…)` test in `scopeExcludingImportedOffMarket` passes it through), and because `rented` is also absent from `OFF_MARKET_STATUSES` it is counted in the "On Market"/"Available" KPI tile and treated as marketable by `isOnMarket()`. Rows do **not** vanish; they leak into the wrong list and inflate the on-market figure. Real on Prod now; the fix is a vocabulary decision (`rented` into `OFF_MARKET_STATUSES`, which Johan's lane comment at L127-135 already flags as "arguably belongs… flagged, not changed").

---

## 3. AT-419 Imported Stock page

- Scoping: same `index()` path — AgencyScope (global) + `applyRoleScope()` own/branch/agency (L214) + agent multi-select; the Imported Stock partition is an additional `where`, never a widening. OK.
- Permission: route group `permission:access_imported_stock` (routes/web.php L3703-3705, registered ahead of the `/{property}` wildcard); key defined `config/corex-permissions.php` L368, granted at L901/L1003/L1075; sidebar gated L845-849. Direct URL without the key → 403 (test `test_imported_stock_route_denied_without_its_own_permission`). OK.
- §1b: search (`searchAddress`, L290), sort whitelist + stated default (`newest` / agency `status_priority`, L52-54, L331-337), status filter, pagination (`properties_per_page`, clamped, L430-436), empty state (view L753-767). Missing: date-range filter (pre-existing for Properties) and an Imported-Stock-specific empty-state sentence — F6, LOW.
- KPI/count consistency after b133c4e34: `$stats` is computed on `(clone $query)` after the partition (L296-323), so any tile would match the list; on Imported Stock the tiles are hidden (view L252 `@unless($importedStock)`), so nothing can mismatch. The derived list equals the old constant exactly (same 9 values, same order — verified against `OFF_MARKET_STATUSES` order); zero remaining references to `IMPORTED_STOCK_STATUSES` in app/resources/routes/tests/config. OK.
- Spec status: still "Draft — awaiting Johan's sign-off before build starts" (L3) — F3.

---

## 4. AT-420 branch archive wizard + reassignment

- Soft delete: `Branch` uses `SoftDeletes` (app/Models/Branch.php L9/L13); archive is `$branch->delete()` inside the transaction (BranchAssignmentController L253). No `forceDelete` anywhere in the flow. OK.
- Restore: `POST admin/branches/{branch}/restore` (`admin.branches.restore`, routes L551-553, `withTrashed()`), `restoreBranch()` L404-416 uses `Branch::onlyTrashed()->findOrFail($id)` → `restore()` → `BranchRestored` event. Test `test_restore_of_an_active_branch_is_not_found`. OK.
- Deals stay stamped: `Deal::branchAttributionSql()` = `COALESCE(deals.branch_id, users.branch_id)` (Deal.php L442-445) + `agentIdsAttributedTo()` (L453); tests L238/L261/L276 cover before/after/legacy. Nothing in the archive transaction touches deals. OK.
- Reassignment validity: targets restricted to `Branch::selectable()` (active) in the same agency and ≠ the archived branch (L174-178), all-or-nothing (L189-197), each target re-validated (L199-208), and re-fetched with `selectable()->findOrFail` inside the transaction (L219). Moves via Eloquent `save()` so `UserObserver` writes `user_branch_history` (L224-225); legacy pivot synced (L227-229); managed-branch rows retargeted (L231, L236-244). OK.
- Permissions: both routes `permission:access_branch_assignments` (routes L547-553) + `authorizeAdmin()` in each action; test L367. Spec §4.3 says no new key — consistent. OK.
- Events: `Branch\BranchArchived` and `Branch\BranchRestored` registered explicitly in `AppServiceProvider` L688-689 (discovery is off — correct); constructor signatures match the `event(new …)` calls (BranchArchived: branch, actorUserId, movedUserIds; AgentBranchAssigned extended with reason/fromBranchId). Listeners are sync (`LogAgentEvent`). OK.
- Archived-branch listings: `_archive-wizard` takes `$targets` (active) and excludes self (L17); `AgencyController` L304-308 restricts the archived list to `$agency->id` even with global scopes dropped. OK.
- Spec status: "Draft — awaiting Johan's sign-off" (L4) — F3.

---

## 5. AT-393 restyles — "restyle must not change features"

Per-view element inventory, base 6545f0262 → QA2 tip 0af61e857, then → Prod 57407d5a5. Everything not listed below is count-neutral or purely additive (filter bars added per Andre's standing "add if missing" rule: viewing-packs, triage, stale-review, outreach-canvassing).

| View | Change | Verdict |
|------|--------|---------|
| `command-center/tasks/index.blade.php` | Whole "At Risk" strip removed (overdue+today chips with direct links, Hide/Show toggle, `$atRisk`, localStorage key) | Intentional — commit 4ef97c740 quotes Andre: "remove the Show At Risk (2) thing at the top…". Not a violation. |
| `command-center/performance.blade.php` L24-27 (base) | `<a href="{{ route('corex.dashboard') }}">Back to Today</a>` removed | **F4** — commit bdce97cc6 ("fix", 2026-09-14, no body). Navigation still reachable via sidebar. |
| `command-center/user-settings.blade.php` L15-18 (base) | `Back to Dashboard` link removed | **F4** — same commit. |
| `presentations/show.blade.php` | Two identical `presentations.analysis ?refresh=1` links (base L68 and L91) collapsed to one (Prod L76) | Dedupe, no loss. |
| `commission/dashboard.blade.php` (My Earnings) | Cap progress/total now 0-dp (L74), fee total and description still shown (L156, L160, `Str::limit(…, 60)`) | Retained. |
| `corex/communications/comms-suspense.blade.php` | Subject, from, body, attachments, suggested/resolved deal all still rendered (L95, L125, L152-174, L203-214) | Retained. |
| `agent/daily-v2.blade.php` | Points total still shown (L273-276), form inputs identical (8 inputs, 2 forms) | Retained. |
| `agent/daily-summary/index.blade.php` | Drill href moved into `$drill()` helper (L18), same route/params | Retained. |
| `agent/portal.blade.php` | `branch?->name` → `branch?->display_name` (AT-420 archived suffix) | Feature-neutral. |
| `command-center/today.blade.php` | Grouped-card `x-text`s gone; day-timeline layout with calendar links added | Andre-selected layout (memory: option 3 of 5). |
| `corex/properties/show.blade.php` | `info.rental` section toggle gone at Prod — replaced by Johan's AT-402 "Rental" tab (L1301, L4139) on Staging | Not the restyle. |
| `corex/contacts/index.blade.php`, `properties/index.blade.php`, `presentations/index.blade.php`, `deeds-capture/index.blade.php`, `buyers/*`, `portal-leads`, `comm-archive`, `mailboxes` | Element counts identical or additive; route names unchanged (only entry-point-aware) | OK |

---

## 6. Follow-up fixes

- **8c0876f3d gallery jobs** — `DownloadP24RowImagesJob` L145-152 and `DownloadPortalPropertyImages` L130-136 call `syncGalleryCategoriesLocked()` right after `saveQuietly()`; the locked sync re-reads `withoutGlobalScopes()->lockForUpdate()` in its own transaction (Property.php L2771-2783), so no nested-transaction hazard, and the master list is already persisted when it reads. Queues: `DownloadP24RowImagesJob` → `p24images` (L68, served); `DownloadPortalPropertyImages` → default (no `onQueue`/`$queue`, served). OK.
- **f444a6e1b mobile read path** — `buildGalleryCategories()` runs `syncGalleryCategories()` on `clone $property`; that method never saves (docblock + body L2697-2765, no `save/update/DB::`), and attribute arrays copy by value, so `galleryFingerprint()` on the original is untouched. `imageMatchKey()` delegates to `Property::imageMatchKey()` (exists, L2663). OK.
- **337c734d8 proforma** — `Agency` has `SoftDeletes` and no tenancy global scope (Agency.php L9/L15), so dropping `withoutGlobalScopes()` only reinstates the not-trashed filter; 77eb85cac corrects the test to the true fallback behaviour. OK.
- **fb9fc5ee3 task counts** — `getOpenTasks/getOverdueTasks/getSummary` now all read `boardQuery()`; consumers: CommandCenterApiController L135-137, L561-573; DashboardController L183-184; TaskController L47 (`getBoardSummary` = `getSummary`). `scopeWithoutPropertyAutomation` (CommandTask L187-194) drops automation-sourced, property-bound, non-deal tasks. Consistent — see F7 for the product consequence.
- **991441103 today clamp** — `itemEndMin` is a `const` in `commandCentre()`'s closure (today.blade.php L219) used by both getters (L252, L269). Pure refactor. OK.
- **113972085 schema snapshot** — 0 `DEFINER` clauses, no BOM, 0 `-CREATE TABLE` lines (no dropped tables), `p24_imported_at` present (3 refs), all 1,340 migration files present as rows. Four snapshot rows without a file on disk (`2026_02_26_700001_…`, `2026_02_26_151853_…`, `2026_05_23_090900_add_agency_id_to_p24_listings_table`, `2026_08_01_130002_seed_capture_bond_attorney_master_step`) are pre-existing and harmless (they only mark migrations as run).

---

## 7. Gates

- Portal-sync refresh-cost contract: across the range only `app/Services/Syndication/Property24/P24LeadService.php` (Johan's 4e4d1a48c) and `app/Services/PrivateProperty/PpLeadService.php` changed. Neither is in `scripts/dev-check.ps1` §7's gated list (L229-234: Property24SyndicationService, ListingMapper, ApiClient, PP SyndicationService/ListingMapper, SubmitListingToProperty24). No `tests/Feature/Syndication/` diff was required; none present. OK.
- E-sign pipeline gate: none of the ten CLAUDE.md pipeline files changed in the range. (`tests/Feature/Docuperfect/SigningView/RecipientPropertyLinkRelinkTest.php` +103 is unrelated.) OK.

---

## Recommendations (for the conductor / Johan, plain language)

1. **F1** — Decide that a P24 listing marked "rented" is off the market. Then it moves to Imported Stock and stops counting as available on Properties. One vocabulary change; the AT-419 scopes derive from it automatically.
2. **F2** — Remember "came from Imported Stock" the same way "came from Rentals" is remembered, so Back and the sidebar return there.
3. **F3** — Mark the AT-419 and AT-420 specs approved (or record that they were), since the builds are live.
4. **F4** — Confirm with Andre whether dropping the two "Back to…" buttons was asked for; if not, restore them.
5. **F5** — Quote the interpolated value in the four Alpine expressions in the contacts agent picker (cosmetic today).
