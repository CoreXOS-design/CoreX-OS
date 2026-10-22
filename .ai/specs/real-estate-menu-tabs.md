# Real Estate menu — merge pages into tabbed hubs

> Status: **BUILT on branch AT-501-Style-Updates (2026-10-22, Andre's go). Not committed / not on QA2 yet.** Johan has not yet
> reviewed this spec (CLAUDE.md "Spec-First Rule": both must align) — he must see it before it leaves QA2.
> Owner: Andre · Drafted 2026-10-22
> Pattern copied from: AT-466 Rentals → Notices (`app/Support/Rentals/NoticesTabs.php`, `resources/views/corex/notices/_tabs.blade.php`)

## 1. Business requirement

The Real Estate menu has **22 items** in one flat list. Daily work, admin clean-up tools, reports and
settings sit side by side. A new user — and above all a prospect watching a demo — sees a wall of
links and concludes CoreX is too big to learn.

This change cuts the Real Estate menu from **22 items to 7** by turning groups of related pages into
one menu item each, which opens a page with a tab bar across the top. **No screen is removed, no
screen's content changes, no one gains or loses access to anything.** It is a navigation change only.

## 2. The rule every hub follows (copied from Rentals → Notices, AT-466)

1. **Each tab is the existing screen on its existing route with its existing access check.** Page
   addresses do not change, so every email, notification, bookmark, favourite, toast, mobile-app link,
   tour and Ellie link keeps working untouched.
2. **A tab is visible exactly when its old menu item was visible** — the same feature switch,
   permission(s) and setting checks, copied as-is from the sidebar. Nothing is tightened or loosened.
3. **The hub's menu item shows when at least one of its tabs is visible**, and lands on the first tab
   the user may open. A user who could see only one of the old items gets a one-tab bar.
4. **The tab bar renders only on the sales Real Estate entry of a page.** Several of these screens are
   shared with Rentals and Auctions (the "lens" mechanism, `session('corex.lens.*')`); when the page is
   opened through a Rentals or Auctions route, no Real Estate tab bar is shown and nothing about those
   menus changes.
5. **Menu search still finds the old names.** Typing "Outreach Queue", "Imported stock", "Refresh
   Requests", etc. into the sidebar search still lands on that tab (same `old` label mechanism as
   NoticesTabs).
6. **The hub's menu item lights up on every tab's routes** (and the detail pages that lit up the old
   item), so the user never loses their place in the menu.
7. Badges move with their page: the Refresh Requests amber count shows on its tab, and on the
   Presentations menu item.

## 3. The new Real Estate menu

| # | Menu item | Tabs (in order) — each is today's page on today's route | Replaces old items |
|---|---|---|---|
| 1 | **Market intelligence** | Work · Opportunities · Analyse · Market Pulse · Portal Alerts · Team · Importer *(all existing tabs)* · **Deeds capture** · **Address review** · **Stale claims** | Market intelligence, Deeds Capture, Address Review, Stale claims review |
| 2 | **Properties** | Properties · **Imported stock** · **Map** | Properties, Imported stock, Map |
| 3 | **Contacts** | *(unchanged, no tabs)* | Contacts |
| 4 | **Buyers** | Buyer pipeline · Core Matches · Portal leads · Viewing packs | Buyer Pipeline, Core Matches, Portal Leads, Viewing Packs |
| 5 | **Outreach** | Activity · Consent *(both already on Outreach & Canvassing)* · **Queue** | Outreach & Canvassing, WhatsApp Outreach, Outreach Queue |
| 6 | **Road to Sold** | *(unchanged)* — header gains a **Settings** button for users who may open it | Road to Sold, Road to Sold settings |
| 7 | **Presentations** | Presentations · Analytics · Outcomes · Refresh requests · Commercial evaluations | Presentations, Analytics, Outcomes, Refresh Requests, Commercial Evaluations |

**Removed menu items with no tab:** WhatsApp Outreach (it is already, in full, the Consent tab of
Outreach & Canvassing — same service, same permission) and Road to Sold settings (already reachable
from the Settings page rail and, after this change, from a Settings button on Road to Sold).
Both routes stay; nothing links to a dead page.

### 3.1 Gates per tab (copied verbatim from today's sidebar — do not change)

| Hub | Tab | Route | Feature | Permission / condition |
|---|---|---|---|---|
| MI | existing 7 tabs | as in `market-intelligence/partials/tabs.blade.php` | `prospecting` | `access_prospecting` (+ `mic.view_team` / `mic.upload_reports` as today) |
| MI | Deeds capture | `corex.deeds-capture.index` (confirm name at build) | `deeds-capture` | `deeds_capture.access` |
| MI | Address review | `corex.address-review.index` (confirm name at build) | `address-review` | `address_review.manage` |
| MI | Stale claims | `market-intelligence.stale-review` | `prospecting` | `prospecting_setup.manage` |
| Properties | Properties | `corex.properties.index` | `properties` (core) + `config('features.properties')` | `access_properties` |
| Properties | Imported stock | `corex.properties.imported-stock` | `imported-stock` | `access_imported_stock` |
| Properties | Map | `corex.map.index` | `properties` | `access_properties` |
| Buyers | Buyer pipeline | `command-center.buyers.pipeline` | `buyer-pipeline` | *(none today — see §7)* |
| Buyers | Core Matches | `corex.core-matches.index` | `core-matches` | `access_core_matches` AND `access_contacts` AND setting `matches_enabled` |
| Buyers | Portal leads | `corex.portal-leads.index` | `portal-leads` | `access_portal_leads` |
| Buyers | Viewing packs | `corex.viewing-packs.index` | `viewing-packs` | `access_viewing_packs` |
| Outreach | Activity / Consent | `corex.outreach-canvassing.index` (`?tab=activity|consent`) | `outreach` | `outreach.summary.view` |
| Outreach | Queue | `corex.outreach-queue.index` | `outreach` | `outreach_queue.view` |
| Presentations | Presentations / Analytics / Outcomes / Refresh requests | `presentations.index`, `corex.presentations.analytics.index`, `corex.presentations.outcomes.index`, `corex.presentations.refresh-requests.index` | `presentations` + `config('features.presentations')` | `access_presentations` |
| Presentations | Commercial evaluations | `commercial-evaluations.index` | `commercial-evaluations` | `access_commercial_evaluations` |

Note the MI-hub tabs Deeds capture and Address review keep their own routes **outside** the MI
route group's `access_prospecting` middleware — a user with deeds access but no prospecting access
must still reach Deeds capture (they see a bar with only the tabs open to them; rule 3).

## 4. Pillars

Navigation only. Reads no pillar data and writes none. Every tab is an existing screen that already
connects to its pillars (Property, Contact, Deal, Agent) — unchanged.

## 5. Data model / migrations / settings

None. No new table, column, permission key, feature key or agency setting — so nothing for the Setup
Wizard (CLAUDE.md §10a not triggered) and nothing for `config/corex-permissions.php`.

## 6. Build shape

- **One generic tab-hub mechanism**, not five copies: a small base class (tabs = label, route, feature,
  permission(s), optional extra condition, old name; `forUser()`, `landingUrl()`) plus one tab-bar Blade
  partial/component, and one class per hub listing its tabs. NoticesTabs is the reference; it stays as
  it is (Rentals is out of scope).
- The existing MI tab partial is re-pointed at the MI hub class so MI has one tab list, not two.
- Outreach: the Queue tab is added to the Outreach & Canvassing tab bar as a link to the queue route,
  and the queue page shows the same bar. The Activity/Consent switching stays as it is.
- Sidebar: the 22 items are replaced by the 7 hub items. All existing `@feature` / `@permission` wraps
  are preserved through the hub classes so `SidebarFeatureCoverage` / `FeatureNavGuardCoverageTest`
  still pass. Each hub's gate is "any tab visible".
- Updated in the same change: Ellie's navigation atlas (`config/corex-navigation-atlas.php`) and the
  guided tours that walk the sidebar (`app/Support/Tours/TourRegistry.php`, `defs/real-estate-core.php`,
  `defs/presentations.php`, `defs/outreach-queue.php`) so no tour step points at a menu item that is
  gone. Tour steps that point at on-page anchors are unaffected.
- The demo sidebar curation (`demo_hidden_sidebar`, keyed by path) keeps working because paths are
  unchanged; hiding a tab's path in the curator hides that tab.

### 6.1 List-screen standard (CLAUDE.md rule 8)

No new list screen is created. Each tab is an existing list that keeps its own search, sort, filters,
pagination, empty state and own/branch/agency scoping exactly as today. The hub adds none and removes
none.

### 6.2 Multi-agency

Hub names and tab labels are neutral product wording — nothing HFC-specific. Tabs follow each agency's
own feature switches, so an agency with Road to Sold or Deeds Capture switched off simply never sees
those tabs (and a hub with every tab off disappears from the menu).

## 7. Existing gaps found during investigation — REPORTED, NOT fixed by this change

These were found while mapping the pages. Fixing them would change who can see what, which this
change explicitly does not do. Each needs its own ticket and decision:

1. **Buyer Pipeline (sales) has no permission check** — only the feature switch. Any signed-in user of
   an agency with the feature on can open it. (The Rentals twin uses `buyer_pipeline.view`.)
2. **Core Matches route and menu disagree**: the route checks `access_contacts`; the menu checks
   `access_core_matches` + `access_contacts` + `matches_enabled`. A user without `access_core_matches`
   can open it by URL.
3. ~~Presentation Analytics / Outcomes and Deeds Capture / Address Review open with their feature off~~ —
   **WRONG, withdrawn (2026-10-22 switch audit):** all four return 404 with the feature off; the app-wide
   `EnforceFeatureRoutes` middleware locks every route a feature owns. The full, measured list of screens that do
   still open by direct URL is in `.ai/audits/tab-hub-switch-audit-2026-10-22.md`.

## 8. User flow

1. Agent opens Real Estate → sees 7 items.
2. Clicks **Buyers** → lands on Buyer pipeline (or the first Buyers tab they may open).
3. Tab bar across the top: Buyer pipeline · Core Matches · Portal leads · Viewing packs. Clicking a
   tab opens that screen exactly as it looks today, with the same bar on top and that tab active.
4. A "new portal lead" email still opens Portal leads directly — now with the Buyers bar on top.

## 9. Acceptance criteria

1. Real Estate shows at most 7 items for a user with every permission and feature; fewer when features
   or permissions remove whole hubs.
2. For every role in the default role set, the set of screens reachable from the menu (hub + tabs) is
   **identical** to the set reachable before. Proven by a test that enumerates the old sidebar gates and
   the new tab gates per role and asserts equality.
3. Every old page address still opens the same screen with the same data (no route renamed or removed).
4. Opening Properties / Core Matches / Buyer pipeline through **Rentals** or **Auctions** shows no Real
   Estate tab bar, and the Rentals/Auctions menus highlight exactly as before.
5. Sidebar search for each of the 15 old item names finds its tab.
6. Existing favourites to any of the old pages still render and open.
7. The Refresh requests count shows on its tab and on the Presentations menu item, and matches today's.
8. Turning a feature off for an agency removes its tab; turning off every tab of a hub removes the hub.
9. `SidebarFeatureCoverage` / `FeatureNavGuardCoverageTest` pass.
10. Visual check on localhost of every hub and every tab (incl. the full-height Map page, where the bar
    must not break the map layout), light and dark theme, phone width.

## 10. Files (expected)

- New: `app/Support/Navigation/TabHub.php` (base), one class per hub
  (`MarketIntelligenceTabs`, `PropertiesTabs`, `BuyersTabs`, `OutreachTabs`, `PresentationsTabs`),
  one shared tab-bar partial.
- Modified: `resources/views/layouts/corex-sidebar.blade.php` (Real Estate block only);
  `corex/market-intelligence/partials/tabs.blade.php`; the main view of each tabbed screen (to include
  the bar, sales entry only); `road-to-sold/index.blade.php` (Settings button);
  `config/corex-navigation-atlas.php`; the tour defs listed in §6;
  `app/Support/Navigation/SidebarFeatureCoverage.php` if its exemptions reference removed items.
- Tests: new hub visibility/equivalence test (criterion 2), lens/no-bar test (criterion 4).

## 10a. As built (2026-10-22)

- Mechanism: `App\Support\Navigation\TabHub` (base) + `TabHubs` (the list) + `Hubs\{MarketIntelligence,Properties,Buyers,Outreach,Presentations}Tabs`.
  Landing routes `corex.hubs.<key>` (`/corex/hub/<key>`, `TabHubController`): first tab the user may open, `?tab=` honoured,
  403 when none. Tab bar: `resources/views/corex/partials/_hub-tabs.blade.php` (MIC tab-bar styling, scrolls sideways on phones).
- Menu label (Andre, 22 Oct 2026): item 1 reads **Real Estate Features** (it opens MIC and its tabs); "Market intelligence" stays its menu-search alias (`data-nav-alias`) and Ellie's category for MIC is "Real Estate › Real Estate Features". The MIC screens' own headings are unchanged.
- Outreach tabs keep the screen's own labels: **Activity Feed · Consent Funnel · Queue**. Activity Feed / Consent Funnel still switch
  in place without a reload (the page's own Alpine `tab`); the old WhatsApp Outreach route shows the bar with Consent Funnel active.
- Road to Sold's settings pages now light the Road to Sold item; the Settings button shows on the sales track only (the old item only
  ever opened the sales settings).
- Sidebar guard: `SidebarFeatureCoverage` exempts `corex.hubs.*` (a hub has no single switch — each tab carries its feature key);
  `FeatureNavGuardCoverageTest` accepts a feature gated by a hub tab and checks every hub tab names a real feature or a core screen.
  8 entries came off `sidebar-nav-allowlist.json` — those screens now light exactly one item.
- Ellie's atlas: the moved screens' category now names where they live (e.g. "Real Estate › Buyers"). Guided tours needed no change —
  none targets a sidebar item; every tour step targets an on-page anchor on an unchanged route.
- Consequence of §7 gap 1 (Buyer Pipeline has no permission check): the Buyers item shows for every signed-in user of an agency with
  the buyer-pipeline feature on — exactly as the old Buyer Pipeline item did.
- Proof: `tests/Feature/Navigation/RealEstateMenuTabsTest.php` (7 tests — incl. the equivalence test: 5 user types × 25 tabs, each tab
  visible exactly when its old item was), `RoadToSoldPageTest::test_the_settings_button_shows_only_to_someone_who_may_manage_the_settings`,
  the sidebar guard tests, and every touched screen rendered on localhost with the right tab marked (Rentals entries show no bar).
- Switches (2026-10-22, Andre asked): `tests/Feature/Navigation/TabHubSwitchesTest.php` walks EVERY tab of EVERY hub - switching its feature off under Settings -> Features (through the real store, incl. the core-matches switchboard setting) hides it, taking each of its permissions away in Role Manager hides it, and putting either back brings it back; the rendered tab bars and the menu search follow, and a menu item disappears once all its tabs are off. Guided tours: all 166 elements the tours on these screens point at were checked on the rendered pages before and after - none lost.
- Pre-existing, unrelated failures seen while proving this (reported, not touched): `FeatureNavGuardCoverageTest` "Alerts" link with
  no toggle + the cross-link check failing on Windows paths; `SidebarNavMappingTest` two `corex.rental-inventories.*inspection-photos`
  NO_ITEM routes; the MIC Work page's render-gate error in `[miSlideover].close()`. All fail identically on the code before this change.

## 11. Deliberately NOT in this change

- Contacts, Road to Sold content, Map content — unchanged.
- Rentals, Auctions and every other sidebar group — unchanged (a separate pass, if wanted).
- Starter packs / default-off features and the Work/Admin split (steps 1 and 2 of the 2026-10-22
  navigation investigation) — separate specs.
- The four gaps in §7.
