# Dashboard menu — merge pages into tabbed hubs

> Status: **BUILT on branch AT-501-Style-Updates (2026-10-22, Andre: "do the same for the dashboard"). Not committed / not on QA2.**
> Johan has not yet reviewed this spec — he must see it before it leaves QA2.
> Owner: Andre · Same mechanism and rules as `.ai/specs/real-estate-menu-tabs.md` (§2 there applies here word for word).

## 1. Business requirement

The Dashboard menu had **12 items**, six of them performance reports with near-identical names (My Performance, Performance,
Branch Report, Agency Report, Oversight, Lost Deals) and two of them calendar screens. A new user — or a prospect in a demo — sees
a long list before they have done anything. This cuts it to **6 items**. No screen is removed, no screen's content changes, nobody
gains or loses access. Navigation only.

## 2. The new Dashboard menu

| # | Menu item | Tabs (in order) — each is today's page on today's route | Replaces old items |
|---|---|---|---|
| 1 | **Today** | *(unchanged, no tabs — it is the home screen)* | Today |
| 2 | **Notifications** | *(unchanged; renamed from "Alerts" — AT-509, Andre 27 Oct 2026)* | Alerts |
| 3 | **Calendar** | Calendar · Invitations | Calendar, Invitations |
| 4 | **Tasks** | *(unchanged)* | Tasks |
| 5 | **Performance** | Performance · My Performance · Branch Report · Agency Report · Oversight · Lost Deals | the six report items |
| 6 | **User Settings** | *(unchanged)* | User Settings |

The Performance item lands on the "Performance" tab (this week / this month) — the screen its name already pointed at; the other
reports are one click away.

### 2.1 Gates per tab (copied verbatim from the sidebar before the change — not changed)

| Hub | Tab | Route | Feature | Permission / condition |
|---|---|---|---|---|
| Calendar | Calendar | `command-center.calendar` | core | `command_center.calendar.view` |
| Calendar | Invitations | `command-center.calendar.invitations` | core | none (the old item checked nothing) |
| Performance | Performance | `command-center.performance` | `performance-dashboards` | — |
| Performance | My Performance | `command-center.reporting.agent` | `performance-dashboards` | — |
| Performance | Branch Report | `command-center.reporting.branch` | `performance-dashboards` | `dashboard.oversight.view` |
| Performance | Agency Report | `command-center.reporting.agency` | `performance-dashboards` | role is admin / super_admin / owner |
| Performance | Oversight | `corex.dashboard.oversight` | `performance-dashboards` | `dashboard.oversight.view` |
| Performance | Lost Deals | `command-center.lost-deals` | `performance-dashboards` | role is admin / super_admin / owner AND `command_center.settings` |

The pending-invitations count (red) moved from the Invitations item onto its tab and onto the Calendar item.

## 3. Pillars, data, settings, list standard, multi-agency

Navigation only — no pillar data read or written, no migration, no permission or feature key, no agency setting (so nothing for
the Setup Wizard), no new list screen (each tab keeps its own search/sort/filter/scoping). Labels are the screens' existing,
neutral names.

## 4. As built

- `App\Support\Navigation\Hubs\CalendarTabs`, `Hubs\PerformanceTabs`, registered in `TabHubs`; landing routes `corex.hubs.calendar`,
  `corex.hubs.performance`. All hub landing routes now live at `/corex/hub/<key>` (were `/corex/real-estate/hub/<key>` before
  either change was committed — a Dashboard hub is not Real Estate).
- `TabHub::badgeColor()` — the Calendar count keeps the old item's red; Presentations keeps its amber.
- Tab-bar option names are prefixed (`hubActiveTab`, `hubAlpineTabs`, `hubFlush`) because an `@include` also sees the host
  page's own variables and `$active` is a common one. `hubFlush` drops the space under the bar on the dense Calendar header strip.
- Sidebar: the two landing routes resolve to the Dashboard panel; the Real Estate panel's resolver lists its five hubs by name
  (it had `corex.hubs.*`, which would have opened Real Estate for the Dashboard hubs).
- Ellie's atlas: Calendar → "Dashboard › Calendar", Performance → "Dashboard › Performance".
- Proof: `tests/Feature/Navigation/DashboardMenuTabsTest.php` (equivalence: 5 user types × 8 tabs, each visible exactly when its
  old item was; the 6-item menu; landing incl. a user without the calendar permission landing on Invitations; tab marked; menu
  search finds the old names). The sidebar guard tests and RealEstateMenuTabsTest re-run green apart from their pre-existing
  failures. Every Dashboard screen rendered on localhost with the right tab marked; Today shows no bar.
- Switches (2026-10-22, Andre asked): `tests/Feature/Navigation/TabHubSwitchesTest.php` walks EVERY tab of EVERY hub - switching its feature off under Settings -> Features (through the real store, incl. the core-matches switchboard setting) hides it, taking each of its permissions away in Role Manager hides it, and putting either back brings it back; the rendered tab bars and the menu search follow, and a menu item disappears once all its tabs are off. Guided tours: all 166 elements the tours on these screens point at were checked on the rendered pages before and after - none lost.
- Pre-existing, not touched: the Calendar page's render-gate errors (12 — `window.location.replace` / scroll measuring in the test
  DOM) are identical on the code before this change.

## 5. Noted for a later decision (not changed)

- "My Performance" and "Performance" now sit side by side as tabs and the names are easy to confuse ("Performance" = this week /
  this month; "My Performance" = the last N days report). Renaming either is a wording change for Johan, not part of this merge.
