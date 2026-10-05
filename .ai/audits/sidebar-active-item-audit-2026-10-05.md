# Whole-of-CoreX sidebar active-item/active-group audit — 2026-10-05

Branch: `sidebar-active-audit-2026-10-05` (worktree off `origin/QA1`).
Tool: `app/Support/Navigation/SidebarNavAuditor.php` + CLI wrapper
`scripts/sidebar-nav-audit.php` (re-run: `php scripts/sidebar-nav-audit.php`).
Regression gate: `tests/Feature/Navigation/SidebarNavMappingTest.php` +
`tests/Feature/Navigation/fixtures/sidebar-nav-allowlist.json`.

## 1a. How the sidebar decides today

`resources/views/layouts/corex-sidebar.blade.php`:
- **Group (which panel opens):** one `$activeGroup` elseif chain, lines 111–323
  (pre-fix), 21 branches, first-match-wins, each a `request()->routeIs(pattern,
  ...)` test (occasionally `&& !routeIs(...)` exclusions, or `&& session('corex.lens.*')`
  for 4 shared screens, or a referer-sniff for `corex.dashboard`). `$navGroupParents`
  (line ~327) nests `evaluation` under `hidden` (dead: no visible panel left for it,
  l.2968-2970 — the drill-down was retired 2026-09-19).
- **Item (which link glows):** independently, on EVERY `<a>`, its own
  `request()->routeIs(pattern) ? 'active' : ''`. No shared data with the group
  chain — two hand-maintained systems that can (and do) drift apart.
- **Shared-screen mechanism ("lens"):** `session('corex.lens.properties'|'core_matches'|'pipeline'|'contacts')`,
  set by the owning controller's index()/allView() action on the way in, read by the
  Rentals branch (l.183-230) to decide whether Properties/Core Matches/Contacts/Buyer
  Pipeline open Rentals (session on) or Real Estate/Command Center (session off,
  the default). Already a real, working "which menu did I come from" mechanism —
  just not generalized to a resolver, and undocumented outside code comments.
- **"Back"/referer:** only `corex.dashboard`/`corex.dashboard.oversight` use it
  (l.161-182): if the referer resolves to a `command-center.*` route, open that
  panel; otherwise show the plain top bar.

## 1b. Mechanical table — before fixes (892 authenticated GET web routes, api.* excluded)

| Category | Count |
|---|---|
| No sidebar item lights at all | 263 |
| &nbsp;&nbsp;— of which group ALSO never opens (high severity — lands with nothing active) | 139 |
| &nbsp;&nbsp;— of which the owning group still opens correctly (low severity, cosmetic) | 124 |
| Matched by **more than one** item (double-highlight) | 31 |
| Resolves to a **different group** than its item's own panel | 50 → 13 after fixing the conditional-vs-default resolver bug (see below) |
| Item exists in a panel, but **no chain branch ever opens that panel** | 6 |

**Biggest mechanical trap found in my own tool, stated honestly:** the Rentals
branch's conditions are `patternA || (patternB && session(...)) || ...` — several
OR-terms, only SOME carrying the session gate. A naive "does any positive pattern
match" treated `corex.properties.*` (gated) as if it unconditionally won the whole
branch, which falsely flagged 37 Properties/Core-Matches/Contacts/Buyer-Pipeline
routes as "jumps to Rentals" when they actually default correctly to Real Estate /
Command Center and only go to Rentals with the lens flag set. Fixed in the auditor
by resolving to the first **unconditional** match as the default and surfacing the
conditional branch separately as `conditional_alt_group` — WRONG_GROUP dropped
50 → 13 once this was modeled correctly. Documented as `test_shared_screens_default_outside_rentals...`.

## 1b. Findings by requested flag

1. **Routes lighting NO item** (263): mostly non-navlink utility/settings pages
   (e.g. `admin.activity-mappings.index`, `admin.users`, `admin.dashboard`) that
   were never given a sidebar link at all — separate from, and larger than,
   "jumps to old location." Not fixed here (would mean adding/moving nav entries,
   out of Step 2's scope); flagged for Johan as a possible separate CLAUDE.md
   Non-negotiable #2 follow-up.
2. **Routes landing in a DIFFERENT group than their item's panel** (13, after the
   conditional-resolver fix): all 13 are either (a) genuine duplicate links —
   `deals-v2.pipeline.*` / `deals-v2.suppliers.*` / 2 `admin.settings.*` routes
   each have ONE link in the **Deals** panel (pre-reorg) and ANOTHER in **Deal
   Register Settings** (post "Johan menu reorg", l.268 comment) — the resolver
   opens Deal Register Settings, so the old Deals-panel link is live but
   navigating it never opens its own panel. **Flagged for Johan**: is the old
   Deals-panel link stale and should be removed? Not touched (Step 2 forbids
   moving/removing menu items without explicit authorization). (b) 5
   `market-intelligence.suburb-report*` routes — a tool-parser false positive,
   verified by reading the source: the item's own pattern already excludes
   suburb-report (I added this exclusion, matching the existing `stale-review`
   exclusion idiom one line above it — closes a real, confirmed double-highlight).
3. **Routes matched by MORE THAN ONE item** (31): mostly two sibling links
   sharing a broad/narrow prefix pair (e.g. "RMCP" + "RMCP Dashboard", "Policies"
   + "Policy Register", "Properties" + "Imported Stock") that both light
   together — cosmetic, not "wrong location". Two are the SAME route
   distinguished only by a query string routeIs() cannot see
   (`tools.commission` vs its History & Logs sibling; `docuperfect.esign.myDocuments`
   vs Authorise Documents) — already a documented, accepted limitation in
   `.ai/specs/sidebar-favourites.md` §11a.
4. **Greedy items stealing other sections' pages**: none found as a *new* defect
   distinct from the overlaps already covered in (3); the broad patterns
   (`corex.properties.*`, `compliance.*`, `deals-v2.*`) are intentionally broad
   and already correctly excluded from their chain-level neighbors.
5. **Same screen reachable from two menus** — Properties / Core Matches /
   Contacts / Buyer Pipeline, reachable from both Real Estate (or Command
   Center) and Rentals. **Today**: `session('corex.lens.*')`, set by the owning
   controller's entry action, read only by the Rentals branch of the elseif
   chain — real, working, but informal and undocumented outside code comments,
   and NOT used for per-item highlighting (only for which panel opens).

## 2. Fix (class, not instances)

- `app/Support/Navigation/SidebarNavAuditor.php` — one parser/resolver, used by
  BOTH the CLI tool and the PHPUnit test (no second implementation to drift).
  `resolveActiveGroup()` is the single, documented algorithm for "what does this
  route open by default, and what's its lens-conditional alternate."
- 3 concrete missing-pattern bugs fixed in `corex-sidebar.blade.php`'s resolver
  chain (GROUP_NEVER_OPENS 6 → 0): `tools.cma` widened to `tools.cma*` (Evaluation
  Certificate sub-pages), `admin.media-encryption.*` added to API & Server,
  `corex.rental-notices.*` added to Rentals — each had its own working sidebar
  link already; only the chain pattern was missing, same AT-439-class gap.
- 1 cosmetic double-highlight fixed: Market Intelligence's own item now excludes
  `market-intelligence.suburb-report*`, mirroring the existing `stale-review`
  exclusion.
- No visual redesign; no menu item renamed, moved, or removed.
- `tests/Feature/Navigation/SidebarNavMappingTest.php` (4 tests, 1640 assertions,
  1.8s): fails if any authenticated GET web route resolves to zero or multiple
  sidebar items UNLESS listed in `fixtures/sidebar-nav-allowlist.json` (270
  entries, each with a reason — the frozen, audited baseline above); also fails
  on a STALE allow-list entry (a route fixed since baseline, not pruned) and
  asserts the 3 fixed routes now resolve correctly, and that the 4 shared
  screens default outside Rentals with Rentals as the documented alternate.

## 3. Before/after + 10 worst offenders

| Metric | Before | After |
|---|---|---|
| GROUP_NEVER_OPENS (no panel ever opens — highest severity) | 6 | **0** |
| WRONG_GROUP (opens a different panel than its item's own) | 50 | 13 (all explained, see §1b.2) |
| MULTI_MATCH (double-highlight) | 31 | 30 (1 fixed: suburb-report) |
| NO_ITEM (no link at all for this route) | 263 | 263 (unchanged — separate scope) |

**10 worst offenders to check in the browser** (highest user-visible impact first):

1. `tools.cma.evaluation.mine` / `.authorisations` — **fixed**: My Evaluations /
   Pending Authorisations now open the Agency Tracker panel on direct landing.
2. `corex.rental-notices.index` / `.show` / `.download-document` — **fixed**:
   Rental Notices now opens the Rentals panel.
3. `admin.media-encryption.status` — **fixed**: now opens API & Server.
4. `deals-v2.pipeline.index/create/edit/master` — **unfixed, flagged for Johan**:
   clicking "Pipeline Setup" in the Deals panel lands on a page that opens Deal
   Register Settings instead.
5. `deals-v2.suppliers.index/search` — same duplicate-link pattern as #4.
6. `admin.settings.deal-property-sync.index` — same pattern as #4.
7. `admin.settings.deal-distribution-rules.index` — same pattern as #4.
8. `market-intelligence.suburb-report*` (5 routes) — **fixed**: Market
   Intelligence no longer double-lights alongside Suburb Report.
9. `corex.contacts.show` / `corex.core-matches.index` — verify these still open
   Real Estate by default and Rentals only when arrived at via a Rentals screen
   (the conditional-resolver fix, §1b "biggest mechanical trap").
10. `corex.properties.index/show/edit/create` — same verification as #9; the
    broadest, highest-traffic shared screen in CoreX.

## Known tool limitations (stated, not hidden)

- Per-ITEM `routeIs()` parsing doesn't evaluate compound `&& !routeIs(...)`
  exclusions (only the group chain's parser does) — causes 2 false-positive
  MULTI_MATCH rows (`market-intelligence.suburb-report*`, `.stale-review`)
  where the real code is already correct; both verified by reading the source
  and documented in the allow-list.
- "No item at all" (263) was NOT individually triaged route-by-route — that's a
  CLAUDE.md Non-negotiable #2 question (pages without nav links), not an
  active-state resolver bug, and is out of Step 2's scope.
