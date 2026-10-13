# Road to Sold (and, later, Road to Rented)

**Jira: AT-454** — one ticket for the whole build (BUILD_STANDARD §8). Commits are prefixed `AT-454:`.
Branch/lane convention: lane 1 = `cc1-…`, lane 2 = `cc2-…`, lane 3 = `cc3-…`. Only cc1 writes to `origin/QA1` (STANDARDS −1l).

Status: **spec v1, 2026-10-13, lane 1.** Section 1 is Johan's approved business rules, verbatim — do not edit it.
Sections 2+ are the technical design. Section 2 is lane 1's contract (read this first if you are lane 2 or 3).
Governing spec for every `AT-454:` commit: this file.

---

## 1. Business rules (approved by Johan 13 Oct 2026 07:19) — VERBATIM

ROAD TO SOLD - BUSINESS RULES (approved by Johan 13 Oct 2026 07:19). These are the rules; the technical design is yours within them. QA1 only. Nothing to Staging.

WHAT IT IS
- One NEW page "Road to Sold" with its own sidebar link (does NOT replace the Today page). Every agent starts the day there: a list of properties to phone to win stock, a target, and a summary bar showing on-track or behind.
- Road to Rented comes later and must reuse the same engine: build everything with a "track" dimension (sales now, rentals later), no sales-only hardcoding.
- Every threshold/window/rule is an agency setting with a default. Never hardcoded. Multi-agency safe (agency_id on every table, BelongsToAgency). Soft delete only. Own/branch/agency scoping at the query layer via PermissionService::getDataScope + scopeVisibleTo + EnforcesRecordVisibility; "own" uses User::dataIdentityIds().

AREAS
- Agency pool = existing Prospecting Setup suburbs (towns / p24_suburbs). NEW: suburbs assigned per agent (admin/BM screen: agents x suburbs). Several agents may share a suburb.
- Areas come first, not a fence: list loads the agent's suburbs first, then carries on into the agency's other suburbs if the day is not filled. Agency setting "Lock agents to their areas" default OFF; ON = only own suburbs.
- Agent with no areas: with lock ON gets no list and admin sees a warning on settings; with lock OFF falls through to agency suburbs.
- Each area shows last P24 / PP refresh date; older than N days (default 7) prompts a refresh. Refresh stays manual.

TARGETS
- Per agent per weekday (Mon-Fri; Sat/Sun available, default 0). Grid: agents x weekdays, "copy to all days", "copy to all agents". Agency default (5 Mon-Fri) for agents without their own row.
- Public holidays (PublicHolidayService) and approved leave carry no target. Month target = sum over working days.
- "Add to my day": agent adds extra from the same selections; recorded and reported separately as over-and-above.
- Missed items: agency setting - show as missed (default) OR roll over to next working day.

THE DAILY LIST
- Built overnight per agent for the next working day (plus on-demand build if missing when the page opens).
- Fill order: (1) follow-ups due today, they COUNT toward the target; (2) new properties from the selections in the agency's order until target reached; (3) inside each selection, properties WITH an address first, without address only when not enough; (4) never suggest: held by another agent, do-not-contact, already a CoreX listing of ANOTHER agent.
- Every property counts toward target whether or not an owner is on file. Finding property + owner is the work.
- Selections (agency can switch each on/off and order them; default order as listed): 1 My buyers stock sourcing; 2 Agency buyers stock sourcing; 3 Hottest sales pockets (buyer demand by suburb + agency's own leads and sales by suburb); 4 Price drops; 5 New on market (listed within X days, default 14, needs a REAL listed date); 6 Long on market (more than X days, default 90); 7 Owner already known (MIC properties with a known owner, shown again up to an agency-set quantity); 8 My expired stock (my own CoreX listings whose mandate expired - pitch again; listing agent only); 9 My stock that is not selling (my own active listings: days on market high, views and leads low; thresholds are agency settings; listing agent only).
- An agent NEVER gets another agent's stock.
- Other feeds shown as separate blocks under the call list, not counted against the call target: buyer follow-ups, lead follow-ups, expiring mandates.

WORKING A PROPERTY
- Row shows: address, price, days on market, listing agency, why suggested, owner + phone if known, last outcome. "Find the owner" links to existing owner paths (CMA Info capture, TVA, manual link).
- One button "Contacted client" -> popup -> pick outcome. Agency-defined outcome list (rename, add, switch off, re-time). Each outcome: counts as attempt or conversation; next action (return after N days / agent-picked date / known expiry date else agent-picked / move to tagged list / never again / calendar appointment) and delay.
- Seeded outcomes: No answer (attempt, +7 days); Spoke - valuation booked (conversation, calendar appointment, leaves call list); Spoke - call back (conversation, agent-picked date); Spoke - not interested (conversation, agent-picked date); Listed with another agency, open mandate (conversation, stays callable, agent-picked date); Mandated with another agency, sole mandate (conversation, expiry date if known else agent-picked); Wrong number (attempt, tagged, moves to "find the right contact" list); Do not contact (conversation, never again for any agent, also sets the contact's call opt-out).
- "Valuation booked" offered on every conversation outcome. Attempts and conversations counted separately.
- N no-answers in a row (default 3): drops off the agent's list, shows on the manager's report. "No answer" filter for when the owner phones back. Wrong-number and no-owner properties grouped as their own workable lists.
- Every outcome is history on the property and the owner contact. Conversation outcomes move the contact's last_contacted; attempts do not.
- HOLD: capturing an outcome gives that agent the property for 30 days (agency setting); not suggested to other agents; each new outcome restarts it; lapses -> free.

SUMMARY BAR
- Per step: should-be-by-today (this month) vs actual. Steps (each switchable): Calls made, Conversations, Valuations booked, Valuations done, Mandates signed, Listings live. Social shares OFF - do not build. Financial line later.
- Green on/ahead, amber under 90%, red under 70% (agency setting). Click a step -> rows behind it.

REPORT
- One interactive report reusing PeriodResolver, Period, PeriodComparison, _period-selector, x-performance-delta, the drill-down modal and its JSON envelope. Add "last 30 days". Periods: today, last 7 days, this month, last 30 days, custom, compare. Per agent: target, worked, attempts, conversations, each outcome, valuations booked, extra over target, missed/rolled, dropped after no-answers. Drill-down on every figure. Print, PDF, XLSX.

SETTINGS: one Road to Sold settings page + set-up wizard step. Role-manager keys + corex-features row + sidebar link + sync-permissions per the standard module checklist.

SHARED CONTRACT BETWEEN THE THREE LANES (do not rename without telling the conductor)
- Table prefix rts_. Lane 1 (you, cc1) owns: settings, rts_agent_areas, rts_weekday_targets, RoadToSold\WorkdayService (isWorkday(user,date), targetFor(user,date), monthTargetToDate(user,date)), RoadToSold\SettingsService (typed getters for every setting above, including the ones lanes 2 and 3 read - publish that list in the spec first).
- Lane 2 (cc2) owns: rts_list_items (agency_id, branch_id, user_id, list_date, track, tracked_property_id nullable, prospecting_listing_id nullable, property_id nullable, selection, origin auto|follow_up|added|rollover, position, status open|worked|missed|rolled), RoadToSold\ListBuilderService (buildFor(user,date), addToDay(user,selection,count)), the selections, the real listed date, the one "has an address" rule.
- Lane 3 (cc3) owns: rts_outcome_definitions, rts_contact_outcomes (call log), rts_holds, RoadToSold\OutcomeService, RoadToSold\HoldService (isHeldByOther), the page (route road-to-sold.index, sidebar link), summary bar, report.
- Code to the interface; stub until the other lane pushes. Own worktree off origin/QA1, small commits prefixed "RTS:", scripts/lane-test.sh (one lane tests at a time - queue), merge to QA1, scripts/qa-deploy.sh. Test data only; archive proof data on exit. No Monitor/sleep-chain commands.

(Conductor note 13 Oct: the commit prefix in the last bullet is superseded — use `AT-454:`.)

---

## 2. Lane 1 contract — what lanes 2 and 3 code against

Namespace `App\Services\RoadToSold\`. Every method takes/returns plain PHP types. **`track`** is `'sales'` (now) or `'rentals'` (later) and defaults to `'sales'` everywhere; nothing in lane 1 code branches on sales-only logic.

### 2.1 `SettingsService`

`SettingsService::forAgency(int $agencyId, string $track = 'sales'): self` — memoised per request. `$agencyId <= 0` returns an unsaved in-memory instance of the defaults and never writes (STANDARDS Rule 17). Use `SettingsService::forUser(User $u, string $track='sales')` which resolves `$u->effectiveAgencyId() ?: 0`.

| Getter | Returns | Default | Read by |
|---|---|---|---|
| `lockAgentsToAreas()` | bool | `false` | lane 2 |
| `areaRefreshWarnDays()` | int (1–365) | `7` | lane 1 screen, lane 3 page |
| `missedItemsMode()` | `'show_missed'`\|`'roll_over'` | `'show_missed'` | lane 2, lane 3 |
| `rollsOver()` | bool (`missedItemsMode()==='roll_over'`) | `false` | lane 2 |
| `selections()` | ordered list of `['key','label','enabled']` | all nine on, default order below | lane 2 |
| `enabledSelectionKeys()` | ordered `string[]` | nine keys | lane 2 |
| `selectionEnabled(string $key)` | bool | true | lane 2 |
| `newOnMarketDays()` | int (1–365) | `14` | lane 2 |
| `longOnMarketDays()` | int (1–3650) | `90` | lane 2 |
| `ownerKnownQuantity()` | int (0–100) | `3` | lane 2 |
| `stuckStockMinDaysOnMarket()` | int | `60` | lane 2 (selection 9) |
| `stuckStockMaxViews()` | int | `30` | lane 2 (selection 9) |
| `stuckStockMaxLeads()` | int | `2` | lane 2 (selection 9) |
| `noAnswerDropAfter()` | int (1–20) | `3` | lane 3 |
| `holdDays()` | int (1–365) | `30` | lane 3 (HoldService) |
| `summarySteps()` | ordered list of `['key','label','enabled']` | six on | lane 3 |
| `summaryStepEnabled(string $key)` | bool | true | lane 3 |
| `summaryGreenFromPct()` | int | `90` | lane 3 |
| `summaryRedBelowPct()` | int | `70` | lane 3 |
| `statusForPct(float $pct)` | `'green'`\|`'amber'`\|`'red'` | green ≥ 90, red < 70, else amber | lane 3 |
| `defaultWeekdayTargets()` | `array<int,int>` keyed 1..7 (ISO, 1=Mon … 7=Sun) | `[1=>5,2=>5,3=>5,4=>5,5=>5,6=>0,7=>0]` | `WorkdayService` |

Stable keys (do not rename — they are stored):
- **Selection keys, default order:** `my_buyers_sourcing`, `agency_buyers_sourcing`, `hot_pockets`, `price_drops`, `new_on_market`, `long_on_market`, `owner_known`, `my_expired_stock`, `my_stock_not_selling`.
- **Summary step keys:** `calls_made`, `conversations`, `valuations_booked`, `valuations_done`, `mandates_signed`, `listings_live`. (Social shares: not built. Financial line: later.)

Defaults that the brief did not state and lane 1 chose (all are editable agency settings, none are Johan's call): `ownerKnownQuantity` 3, `stuckStock*` 60 days / 30 views / 2 leads. Lane 2 may read them; if lane 2 needs a different setting, ask lane 1 — do not add columns to `rts_settings` from another lane.

### 2.2 `WorkdayService`

`WorkdayService::make()` (container-resolved; stateless apart from a per-request memo of holidays and leave).

| Method | Meaning |
|---|---|
| `isWorkday(User $u, CarbonInterface\|string $date, string $track='sales'): bool` | `true` only if the date is **not** a public holiday, the user has **no approved/taken whole-day leave** covering it, **and** the resolved weekday target is > 0. |
| `targetFor(User $u, CarbonInterface\|string $date, string $track='sales'): int` | `0` on a public holiday or whole-day leave; otherwise the user's own `rts_weekday_targets` row for that weekday, else the agency default for that weekday. |
| `monthTargetToDate(User $u, CarbonInterface\|string $date, string $track='sales'): int` | Sum of `targetFor` from the 1st of `$date`'s month through `$date` inclusive ("should be by today"). |
| `monthTarget(User $u, CarbonInterface\|string $date, string $track='sales'): int` | Sum of `targetFor` over the whole month (full-month figure beside the to-date one). |
| `nextWorkday(User $u, CarbonInterface\|string $from, string $track='sales'): ?Carbon` | First date **after** `$from` where `isWorkday` is true, searching up to 60 days; `null` if none (e.g. a new agent with every weekday at 0). Needed by lane 2 (overnight build, roll-over). |

Sources: `PublicHolidayService::isPublicHoliday` (global table `public_holidays`, country `ZA` — the service default; the table is South-Africa-only today, noted for the second-agency test); `leave_applications` (`user_id`, `status IN ('approved','taken')`, `deleted_at IS NULL`, `start_date <= d <= end_date`). Half-day leave: see open question Q1.

### 2.3 `AreaService`

| Method | Meaning |
|---|---|
| `poolFor(int $agencyId): Collection` | The agency's Prospecting Setup suburbs (rows of `p24_suburbs` reached through the agency's `towns`/`ProspectingConfigurationService`; resolved and confirmed at build step 2). Each item: `p24_suburb_id`, `name`, `name_normalised`, `town`. |
| `suburbIdsFor(User $u, string $track='sales'): int[]` | The user's assigned `p24_suburb_id`s (non-archived), `[]` if none. |
| `suburbNamesNormalisedFor(User $u, string $track='sales'): string[]` | Same, as `suburb_normalised` strings, for matching `prospecting_listings.suburb` (free text) and `tracked_properties.suburb_normalised`. Lane 2 matches `tracked_properties` on `p24_suburb_id` where present, else on the normalised name. |
| `hasAreas(User $u, string $track='sales'): bool` | |
| `agentsWithoutAreas(int $agencyId, string $track='sales'): Collection` | Active selling users in the agency with zero areas (drives the settings warning when lock is ON). |
| `lastRefreshed(int $agencyId, int $p24SuburbId): array{p24:?Carbon,pp:?Carbon}` | Per portal. P24 = `minion_capture_areas.last_captured_at` (where a row exists); fallback and PP = `MAX(prospecting_listings.last_seen_at)` for that agency, `portal_source` 'p24'/'pp', matched on the suburb's normalised name. `null` = never. Confirmed at build step 2. |
| `isStale(?Carbon $at, int $warnDays): bool` | `null` counts as stale. |

Lock behaviour is **applied by lane 2** (`ListBuilderService`) using `lockAgentsToAreas()` + `suburbIdsFor()`; lane 1 only supplies data and the settings warning.

---

## 3. Lane 1 data model

All tables: `agency_id` NOT NULL FK + `BelongsToAgency`; `branch_id` nullable + `BelongsToBranch` where the row belongs to a person; `track` varchar(20) NOT NULL default `'sales'`; `softDeletes`; `timestamps`. No hard deletes anywhere. No agency-1 defaults, no HFC wording.

### 3.1 `rts_settings` — one row per (agency, track)
`id`, `agency_id`, `track`, then:
`lock_agents_to_areas` tinyint(1) default 0; `area_refresh_warn_days` smallint default 7; `missed_items_mode` varchar(20) default `show_missed`; `selections` json (ordered `[{key,enabled}]`); `new_on_market_days` smallint default 14; `long_on_market_days` smallint default 90; `owner_known_quantity` smallint default 3; `stuck_stock_min_days` smallint default 60; `stuck_stock_max_views` smallint default 30; `stuck_stock_max_leads` smallint default 2; `no_answer_drop_after` smallint default 3; `hold_days` smallint default 30; `summary_steps` json (ordered `[{key,enabled}]`); `summary_green_from_pct` smallint default 90; `summary_red_below_pct` smallint default 70; `default_weekday_targets` json (`{"1":5,…,"7":0}`); `updated_by_user_id` nullable.
Unique `(agency_id, track)` (soft delete is not used on this table — it is a singleton; "reset to defaults" overwrites the values, no archive). Missing row ⇒ `SettingsService` returns defaults and creates the row on first save, never on read. Every change is written to the audit log with before/after (same helper the other agency settings use — confirmed at build).

### 3.2 `rts_agent_areas` — agent ↔ suburb
`id`, `agency_id`, `branch_id` (copied from the agent at write time), `track`, `user_id` FK users, `p24_suburb_id` bigint unsigned (matches the project's existing no-FK convention on `tracked_properties.p24_suburb_id`; validated against the pool in code), `assigned_by_user_id`, `deleted_at`.
Unique `(agency_id, track, user_id, p24_suburb_id)`. Because the unique index ignores soft-deletes (BUILD_STANDARD §5a), **assign = `withTrashed()->firstOrNew` + `restore()` when trashed**, never a bare `create`. Index `(agency_id, track, p24_suburb_id)` for the by-suburb view.
Several agents may share a suburb (no uniqueness across users).

### 3.3 `rts_weekday_targets` — an agent's own weekday numbers
`id`, `agency_id`, `branch_id`, `track`, `user_id` FK users, `weekday` tinyint (ISO 1=Mon … 7=Sun), `target` smallint unsigned (0–200), `set_by_user_id`, `deleted_at`.
Unique `(agency_id, track, user_id, weekday)`; same withTrashed-restore rule. **A missing/archived row means "use the agency default for that weekday"; a row with `target = 0` means "this agent really has zero that day".** The agency default lives in `rts_settings.default_weekday_targets` (so there is no NULL-user row and no NULL-in-unique-index problem).

---

## 4. Permissions, feature row, navigation, wizard

**Module/scope key:** `road_to_sold` (registered in `PermissionService` scope registry beside `targets`/`daily_activity`; no-grant default = own). Data scope is read with `PermissionService::getDataScope($user,'road_to_sold')` → `own` | `branch` | `all` | `null`.

**Permission keys (`config/corex-permissions.php`, section `road-to-sold`, module `road_to_sold`):**
| Key | Type | Meaning | Default roles |
|---|---|---|---|
| `access_road_to_sold` | access | See the Road to Sold page and nav (lane 3 uses it) | agent, branch_manager, admin, office_admin |
| `road_to_sold.view` | scope row | Own / branch / agency breadth of everything Road to Sold shows | agent=own, branch_manager=branch, admin=all |
| `road_to_sold.manage_settings` | action | Edit the settings page | admin (and owner) |
| `road_to_sold.manage_areas` | action | Assign suburbs to agents | admin, branch_manager (within their scope) |
| `road_to_sold.manage_targets` | action | Edit weekday targets | admin, branch_manager (within their scope) |

Role-default seeding only affects fresh installs; existing agencies are reconciled by `corex:sync-permissions` (step 1 includes running it on QA1 and saying which roles got which keys).

**Feature row** (`config/corex-features.php`): key `road-to-sold`, label "Road to Sold", category "Prospecting & Outreach", `default => true`, `depends_on => ['prospecting']`, `nav_permission => ['access_road_to_sold']`, `sidebar_section => 'real-estate'`, `route_names => ['road-to-sold.*']`, `view_dirs => ['road-to-sold/']`, plus plain-English `explain` / `affects`. Road to Rented later gets its own key (`road-to-rented`) from the same engine.

**Sidebar:** lane 3 owns the main "Road to Sold" link (`road-to-sold.index`). Lane 1 owns the "Road to Sold settings" link in the same group (gated `@feature('road-to-sold')` + `road_to_sold.manage_settings`). Both links are wrapped in `@feature('road-to-sold')` and the group hides itself when its children are all off.

**Wizard (rule 10a):** new step key `road-to-sold` in `config/agency-onboarding-copy.php`; each control carries `explain` + `affects` and wires the same saver the settings page uses (guarding every boolean write with `$request->has()` — onboarding spec §6.1). Controls: lock agents to areas, area refresh warning days, missed-items mode, default weekday targets, no-answer drop count, hold days. The nine selections (on/off/order) and the colour thresholds are also on the page; whether the advanced ones stay out of the wizard is Johan's call — see Q3. Per-person data (which suburbs each agent has, each agent's own weekday numbers) are records, not settings, and are handled on their own screens; the wizard step links to them.

---

## 5. Screens, routes, search/sort/filter, scoping

All screens are server-rendered Blade (Alpine for the grid UX). **Lane 1 ships no JSON endpoints**; if one becomes necessary it lives under `/api/v1/road-to-sold/*` with a `->name()` (non-negotiable #7). UI follows UI_DESIGN_SYSTEM.md (tokens via `var(--token, #fallback)`); every visible label is plain English (STANDARDS F.8); every destructive action confirms; every list has a real empty state; usable on a phone.

Routes (web, `auth`, `feature:road-to-sold`; names `road-to-sold.settings.*`):

| Method + URI | Name | Purpose | Permission |
|---|---|---|---|
| GET `/road-to-sold/settings` | `.index` | Settings hub, tabs `?tab=general\|areas\|targets` | `road_to_sold.manage_settings` (areas tab: `manage_areas`; targets tab: `manage_targets`) — a user with any of the three can open the hub |
| PUT `/road-to-sold/settings/general` | `.general.update` | Save settings | manage_settings |
| POST `/road-to-sold/settings/general/reset` | `.general.reset` | Reset to defaults (confirm) | manage_settings |
| GET `/road-to-sold/settings/areas` | `.areas.index` | Agents × suburbs | manage_areas |
| GET `/road-to-sold/settings/areas/by-suburb` | `.areas.by-suburb` | Suburbs × agents + last-refreshed | manage_areas |
| POST `/road-to-sold/settings/areas/agents/{user}` | `.areas.sync` | Replace this agent's suburb set (add/remove in one save) | manage_areas |
| DELETE `/road-to-sold/settings/areas/{area}` | `.areas.archive` | Unassign one suburb (soft) | manage_areas |
| POST `/road-to-sold/settings/areas/{area}/restore` | `.areas.restore` | Re-assign an archived one (`{area}` bound `withTrashed`) | manage_areas |
| GET `/road-to-sold/settings/targets` | `.targets.index` | Agents × weekdays grid | manage_targets |
| PUT `/road-to-sold/settings/targets/default` | `.targets.default` | Agency default row | manage_targets |
| PUT `/road-to-sold/settings/targets/agents/{user}` | `.targets.agent` | Save one agent's week | manage_targets |
| POST `/road-to-sold/settings/targets/agents/{user}/copy-days` | `.targets.copy-days` | One value to all days (the form field is what is copied) | manage_targets |
| POST `/road-to-sold/settings/targets/copy-to-agents` | `.targets.copy-agents` | Copy a chosen agent's week (or the agency default) to every agent in scope (confirm) | manage_targets |
| DELETE `/road-to-sold/settings/targets/agents/{user}` | `.targets.clear` | Clear the agent's own numbers (archive all their rows → back to agency default) | manage_targets |
| POST `/road-to-sold/settings/targets/agents/{user}/restore` | `.targets.restore` | Bring the cleared week back | manage_targets |

### 5.1 General tab (no list)
Fields: lock toggle; refresh-warning days; missed-items mode (radio); nine selections as a reorderable on/off list; three number thresholds + owner-known quantity; stuck-stock thresholds; no-answer count; hold days; six summary steps on/off; green-from % and red-below %. Warnings shown on this tab: **"N agents have no areas and the lock is on — they will get no list"** with the agent names linking to the Areas tab; stale-areas count.

### 5.2 Areas — agents × suburbs (main view)
- **Rows:** active users in the viewer's scope who can use Road to Sold.
- **Search (named fields):** agent name, agent email, suburb name, town name.
- **Filters:** branch (only when the agency has more than one branch / viewer's scope allows), town, "agents with no areas", "areas not refreshed in N days", status (active assignments / archived assignments).
- **Sort:** agent name (**default A→Z**), branch, number of areas, oldest refresh. Pagination 25 agents per page.
- **Cell:** a chip list of the agent's suburbs, each with the refresh badge ("P24 3 days ago · PP never"), amber when older than the warn setting; an **Add suburbs** picker (searchable, town-grouped, pool only) and a per-chip remove with confirmation; archived chips shown under the "archived" status filter with **Restore**.
- **Empty states:** "No agents match your search" (filter) vs "No agents can use Road to Sold yet — give agents the Road to Sold permission in Role Manager" (genuinely empty).
- **By-suburb view:** columns suburb, town, agents assigned (count + names), P24 last refreshed, PP last refreshed, stale flag. Search suburb/town/agent; filter town, stale only, unassigned only; **sort default suburb A→Z**, plus stalest first, most agents; 50 per page. A stale suburb shows **"Refresh now"** that opens the existing manual capture path (P24 Auto-Import / Chrome capture) — refresh itself stays manual and outside this feature.

### 5.3 Targets — agents × weekdays
- **Top row:** "Agency default" (Mon–Fri 5, Sat/Sun 0) — applies to agents with no own number for that day; shown as a placeholder in every agent cell that has no own number so it is visible *why* the number is what it is.
- **Rows:** active users in scope. **Columns:** Mon … Sun, week total, "uses default / custom" badge, this-month target (from `WorkdayService`, shows holidays/leave effect in a tooltip).
- **Search:** agent name, email. **Filters:** branch, "custom numbers only" / "default only", status (active / cleared). **Sort:** agent name (**default A→Z**), branch, week total. Pagination 25.
- **Actions:** per row "same number every day", "copy this week to every agent in scope" (confirm: "This replaces N agents' numbers"), "clear my own numbers" (confirm), restore.
- **Empty state:** same two-case copy as Areas.

### 5.4 Scoping, per screen (query layer, not UI)
- Viewer's breadth = `getDataScope($user,'road_to_sold')`. `all` = whole agency (still `AgencyScope`-bounded); `branch` = `effectiveBranchId()`; `own` = `dataIdentityIds()`; `null` = nothing (404/empty). A pure `own` viewer who holds a `manage_*` key still only edits themselves, and `manage_targets` for `own` scope is refused on self-edit (agents do not set their own target) — only `branch`/`all` viewers edit others.
- `RtsAgentArea::scopeVisibleTo`, `RtsWeekdayTarget::scopeVisibleTo` implement the three branches exactly like `DailyActivity::scopeVisibleTo`; the agent lists on both grids go through the same scope on `users`.
- **Every** `{user}` / `{area}` route re-checks with `EnforcesRecordVisibility::assertVisible()` (archived included) → 404 for a record outside scope, so a direct URL by id is blocked.
- Posting suburb ids not in the agency pool → rejected with a plain message; posting a user outside scope → 404.
- No export or download exists in lane 1 (nothing to scope); lane 3's report scopes its own.

### 5.5 Domain events / pillars
Pillars: Agent (User) ↔ Property (suburb pool). No cross-pillar reactivity is introduced by lane 1 (consumers read through the services above), so no domain-event emission. Soft-delete/restore only.

---

## 6. Input space (BUILD_STANDARD §2/§3) — prevent or absorb

| Input | Rule |
|---|---|
| Target cell blank | Absorb: no own row for that day (agency default applies). |
| Target `0` | Valid: own row with 0 (agent genuinely off that day). |
| Target negative / non-numeric / > 200 | Prevent: validation message "Enter a whole number from 0 to 200". |
| Whitespace / "5 " / "05" | Trim, cast to int. |
| Agency default row blank | Prevent: all seven days required (0 is fine). |
| Number settings out of range / non-numeric | Prevent with range message; blank = keep current (never write NULL to a NOT NULL column). |
| green-from ≤ red-below | Prevent: "Green must start higher than red". |
| Selections order/enabled payload with unknown or missing keys | Absorb: unknown dropped; missing appended disabled at the end; duplicates collapsed; if the posted list enables nothing, accept (agency may switch all off — list is then follow-ups only; a note says so). |
| Summary steps payload | Same absorb rules. |
| Assign a suburb already assigned (active) | Absorb: no-op. Already assigned-then-archived: restore the row (§5a). |
| Assign a suburb not in the agency pool / from another agency | Prevent: rejected. |
| Suburb later removed from Prospecting Setup | Absorb: the assignment stays, shown greyed "no longer in your Prospecting setup", ignored by `suburbIdsFor`. |
| Agent deactivated / role loses access | Absorb: hidden from the grids, rows kept; `suburbIdsFor` unaffected. |
| User outside viewer's scope | 404 (not 403) so existence is not revealed. |
| Save with no change | Absorb: success message, no audit noise. |
| Two admins save the same grid | Last write wins per cell; every changed cell is audited. |
| Agent has every weekday at 0 | Valid; `nextWorkday` returns null; consumers must handle it (lane 2: no list, "no working days set" message). |
| Settings read when no row exists / agency id 0 | Absorb: defaults, nothing persisted. |

Test matrix (each piece): happy path with all fields; each optional field omitted individually; the lazy shortcut (open the page, tick one box, save); one malformed value per validated field; the **create → archive → recreate** pass on `rts_agent_areas` and `rts_weekday_targets` (§5a); deleted-related-record (agent soft-deleted, suburb gone from the pool); idempotent re-save; cross-agency 404 and out-of-branch 404 by direct URL; second-agency test (a second agency with different settings/targets sees none of the first's, and none of HFC's wording).

---

## 7. Build order (lane 1) and what is clickable after each deploy

1. `rts_settings` + `SettingsService`, settings hub skeleton + General tab, permissions (`corex:sync-permissions`), feature row, Settings link in the sidebar.
2. `rts_agent_areas` + `AreaService` + Areas screens (agents × suburbs, by-suburb) with refresh dates and the lock warning.
3. `rts_weekday_targets` + Targets grid with copy actions and agency default.
4. `WorkdayService` (holidays + approved leave) with tests; target preview on the grid uses it.
5. Wizard step.

Deploys: after each numbered piece, via cc1 (`git push origin HEAD:QA1` then `scripts/qa-deploy.sh`). Migrations are applied by the deploy, never by a worktree (STANDARDS −1g). Schema snapshot regenerated once per batch with `scripts/schema-dump.sh`. Tests via `scripts/lane-test.sh` one file at a time.

---

## 8. Open questions (lane 1 → conductor; each has a default so building is not blocked)

- **Q1 (business):** An agent on a **half-day** of approved leave — does the half day carry the full target, half, or none? *Default built:* half-day leave still carries the full target (only whole-day leave zeroes it). Easy to change in one place.
- **Q2 (business):** "Green on/ahead, amber under 90%, red under 70%" — read as green at 90% and above, amber from 70% up to just under 90%, red below 70%. *Default built:* as stated, both numbers editable.
- **Q3 (rule 10a):** Which settings stay out of the wizard step? *Default built:* the wizard shows lock, refresh days, missed-items mode, agency default targets, no-answer count and hold days; selections order, thresholds and colour limits are on the settings page only. This is Johan's call; recorded here as the proposed "Deliberately NOT in the wizard" list until he says otherwise.
- **Q4 (data):** PP last-refreshed has no dedicated table (only P24 has `minion_capture_areas.last_captured_at`). *Default built:* PP (and P24 where no minion row exists) is derived from the newest captured listing for that suburb (`prospecting_listings.last_seen_at`). If Johan wants a recorded PP refresh date, that needs a separate piece of work.
- **Q5 (process, for the conductor):** STANDARDS −1l says only cc1 writes to `origin/QA1`, but the brief tells lanes 2 and 3 to "merge to QA1". This spec assumes lanes 2 and 3 push their own branches and cc1 lands them; confirm.
