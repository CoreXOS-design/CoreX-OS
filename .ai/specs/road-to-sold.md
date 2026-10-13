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
| `halfDayLeaveHalvesTarget()` | bool | `false` | `WorkdayService` (conductor 13 Oct: half-day leave keeps the full target unless this is on) |
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
| `hotPocketLookbackDays()` | int (1–3650) | `180` | lane 2 (selection 3) — added at lane 2's request (§9.7) |
| `priceDropLookbackDays()` | int (1–3650) | `30` | lane 2 (selection 4) — same |
| `stuckStockLookbackDays()` | int (1–3650) | `30` | lane 2 (selection 9) — same |
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

Sources: `PublicHolidayService::isPublicHoliday` (global table `public_holidays`, country `ZA` — the service default; the table is South-Africa-only today, noted for the second-agency test); `leave_applications` (`user_id`, `status IN ('approved','taken')`, `deleted_at IS NULL`, `start_date <= d <= end_date`). Half-day approved leave (`leave_applications.is_half_day`): full target unless `SettingsService::halfDayLeaveHalvesTarget()` is on, in which case that day's target is halved (rounded to the nearest whole number).

### 2.3 `AreaService`

| Method | Meaning |
|---|---|
| `poolFor(int $agencyId): Collection` | The agency's Prospecting Setup suburbs (rows of `p24_suburbs` reached through the agency's `towns`/`ProspectingConfigurationService`; resolved and confirmed at build step 2). Each item: `p24_suburb_id`, `name`, `name_normalised`, `town`. **Pool as built:** every non-deleted `p24_suburbs` row (with a `p24_id`) whose `p24_city_id` matches one of the agency's non-deleted `towns.p24_city_id`, plus every suburb the agency ticked in `minion_capture_areas`. (The old `town_suburbs` tree is retired — see `AssignMunicipalities`.) Verified on QA1: agency 1 has 41 towns (33 with a P24 city) → 1,839 pool suburbs. |
| `suburbIdsFor(User $u, string $track='sales'): int[]` | The user's assigned `p24_suburb_id`s (non-archived), `[]` if none. |
| `suburbNamesNormalisedFor(User $u, string $track='sales'): string[]` | Same, as `suburb_normalised` strings, for matching `prospecting_listings.suburb` (free text) and `tracked_properties.suburb_normalised`. Lane 2 matches `tracked_properties` on `p24_suburb_id` where present, else on the normalised name. |
| `hasAreas(User $u, string $track='sales'): bool` | |
| `agentsWithoutAreas(int $agencyId, string $track='sales'): Collection` | Active selling users in the agency with zero areas (drives the settings warning when lock is ON). |
| `lastCaptured(int $agencyId, ?array $suburbIds=null): array<int,array{p24:?Carbon,pp:?Carbon}>` | **Built.** Batch form (two small queries for the whole pool). Labelled "last captured" in the UI. |
| `suburbNamesLowerTrimFor(User, track): string[]` | **Built.** Suburb names as `lower(trim())` — the form MIC joins against `prospecting_listings.suburb`. |
| `needsRefresh(array $captured, int $warnDays): bool` | **Built.** True when NEITHER portal was captured within `$warnDays` (never = true). |
| `poolQuery(int $agencyId)` / `idsInPool(int $agencyId, int[] $ids): int[]` / `towns(int $agencyId)` | **Built.** The pool as a query builder (`s.id, s.name, s.p24_city_id, town_name`); membership check; the agency's towns that map to a P24 city. |
| `lastRefreshed(int $agencyId, int $p24SuburbId): array{p24:?Carbon,pp:?Carbon}` | Per portal. P24 = `minion_capture_areas.last_captured_at` (where a row exists); fallback and PP = `MAX(prospecting_listings.last_seen_at)` for that agency, `portal_source` 'p24'/'pp', matched on the suburb's normalised name. `null` = never. Confirmed at build step 2. |
| `isStale(?Carbon $at, int $warnDays): bool` | `null` counts as stale. |

Lock behaviour is **applied by lane 2** (`ListBuilderService`) using `lockAgentsToAreas()` + `suburbIdsFor()`; lane 1 only supplies data and the settings warning.

---

## 3. Lane 1 data model

All tables: `agency_id` NOT NULL FK + `BelongsToAgency`; `branch_id` nullable + `BelongsToBranch` where the row belongs to a person; `track` varchar(20) NOT NULL default `'sales'`; `softDeletes`; `timestamps`. No hard deletes anywhere. No agency-1 defaults, no HFC wording.

### 3.1 `rts_settings` — one row per (agency, track)
`id`, `agency_id`, `track`, then:
`lock_agents_to_areas` tinyint(1) default 0; `half_day_leave_halves_target` tinyint(1) default 0; `area_refresh_warn_days` smallint default 7; `missed_items_mode` varchar(20) default `show_missed`; `selections` json (ordered `[{key,enabled}]`); `new_on_market_days` smallint default 14; `long_on_market_days` smallint default 90; `owner_known_quantity` smallint default 3; `stuck_stock_min_days` smallint default 60; `stuck_stock_max_views` smallint default 30; `stuck_stock_max_leads` int default 2; `hot_pocket_lookback_days` smallint default 180; `price_drop_lookback_days` smallint default 30; `stuck_stock_lookback_days` smallint default 30; `no_answer_drop_after` smallint default 3; `hold_days` smallint default 30; `summary_steps` json (ordered `[{key,enabled}]`); `summary_green_from_pct` smallint default 90; `summary_red_below_pct` smallint default 70; `default_weekday_targets` json (`{"1":5,…,"7":0}`); `updated_by_user_id` nullable.
Unique `(agency_id, track)` (soft delete is not used on this table — it is a singleton; "reset to defaults" overwrites the values, no archive). Missing row ⇒ `SettingsService` returns defaults and creates the row on first save, never on read. Every change is appended to `rts_settings_audit` (`agency_id`, `track`, `changed_by_user_id`, `old_values` json, `new_values` json, `changed_at`) — append-only, never edited or deleted, same shape as `core_match_buyer_state_audit`. A save that changes nothing writes nothing.

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

**Wizard (rule 10a) - BUILT:** new step key `road_to_sold` (after `market_intelligence`, gated on the `road-to-sold` feature) in `config/agency-onboarding-copy.php`; each control carries `explain` + `affects` and wires the same saver the settings page uses (guarding every boolean write with `$request->has()` — onboarding spec §6.1). Controls: lock agents to areas, area refresh warning days, missed-items mode, default weekday targets, no-answer drop count, hold days. The nine selections (on/off/order) and the colour thresholds are also on the page; whether the advanced ones stay out of the wizard is Johan's call — see Q3. Per-person data (which suburbs each agent has, each agent's own weekday numbers) are records, not settings, and are handled on their own screens; the wizard step links to them.

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
| (same route, `?view=suburbs`) | `.areas.index` | Suburbs × agents + "last captured" | manage_areas |
| POST `/road-to-sold/settings/areas/agents/{user}` | `.areas.sync` | Add and/or remove suburbs for one agent in one save (`add[]`, `remove[]` ids) | manage_areas |
| DELETE `/road-to-sold/settings/areas/{area}` | `.areas.archive` | Unassign one suburb (soft) | manage_areas |
| POST `/road-to-sold/settings/areas/{area}/restore` | `.areas.restore` | Re-assign an archived one (`{area}` bound `withTrashed`) | manage_areas |
| GET `/road-to-sold/settings/targets` | `.targets.index` | Agents × weekdays grid + agency default row | manage_targets (or manage_settings, who can edit the default) |
| PUT `/road-to-sold/settings/targets/default` | `.targets.default` | Save the agency default week (all seven days required; audited; same saver as the settings page and the wizard) | manage_settings, or manage_targets with agency-wide (`all`) breadth — a branch-only manager is refused |
| PUT `/road-to-sold/settings/targets/agents/{user}` | `.targets.agent` | Save one agent's week (blank = no own number) | manage_targets, breadth all/branch |
| POST `/road-to-sold/settings/targets/agents/{user}/fill-weekdays` | `.targets.fill-weekdays` | Copy the Monday number onto Tuesday–Friday ("copy to all days" — weekend untouched) | manage_targets |
| POST `/road-to-sold/settings/targets/copy-to-agents` | `.targets.copy-agents` | Give every agent in the viewer's breadth the posted week (a grid row, or the agency default row); blank cells clear that day (confirm) | manage_targets |
| DELETE `/road-to-sold/settings/targets/agents/{user}` | `.targets.clear` | Clear the agent's own numbers (archive → back to agency default) | manage_targets |
| POST `/road-to-sold/settings/targets/agents/{user}/restore` | `.targets.restore` | Bring cleared numbers back | manage_targets |

An `own`-breadth viewer who holds `manage_targets` can look at their own row but every write answers 403 (agents do not set their own targets). Target rows record who set them (`set_by_user_id`); there is no separate audit table for per-agent numbers — the agency default week is audited in `rts_settings_audit`.

### 5.0 Screen-text rule (Johan, 13 Oct 2026) — applies to all three settings screens
Screen space is for data and controls only: no intro sentences under the banner, no explainer paragraphs, no tooltip-length notes in chips or cells; at most one short line per setting. Every button on a screen is the same height (the `cx-equal-btns` scope with `corex-btn-primary` / `corex-btn-outline`), and Save / reset sit side by side on one baseline. Empty states and refusal messages keep one plain sentence. Pinned by tests in `SettingsTest`, `AreasTest`, `TargetsTest`.

### 5.1 General tab (no list)
Fields: lock toggle; refresh-warning days; missed-items mode (radio); nine selections as a reorderable on/off list; three number thresholds + owner-known quantity; stuck-stock thresholds; no-answer count; hold days; six summary steps on/off; green-from % and red-below %. Warnings shown on this tab: **"N agents have no areas and the lock is on — they will get no list"** with the agent names linking to the Areas tab; stale-areas count.

### 5.2 Areas — agents × suburbs (main view)
- **Rows:** active users in the viewer's scope who can use Road to Sold.
- **Search (named fields):** agent name, agent email, suburb name, town name.
- **Filters:** branch (only when the agency has more than one branch / viewer's scope allows), town, "agents with no areas", "areas not refreshed in N days", status (active assignments / archived assignments).
- **Sort:** agent name (**default A→Z**), branch, number of areas, oldest refresh. Pagination 25 agents per page.
- **Cell:** a chip list of the agent's suburbs, each with the refresh badge ("P24 3 days ago · PP never"), amber when older than the warn setting; an **Add suburbs** picker (searchable, town-grouped, pool only) and a per-chip remove with confirmation; archived chips shown under the "archived" status filter with **Restore**.
- **Empty states:** "No agents match your search" (filter) vs "No agents can use Road to Sold yet — give agents the Road to Sold permission in Role Manager" (genuinely empty).
- **By-suburb view:** columns suburb, town, agents assigned (count + names), P24 last refreshed, PP last refreshed, stale flag. Search suburb/town/agent; filter town, stale only, unassigned only; **sort default suburb A→Z**, plus stalest first, most agents; 50 per page. A stale suburb shows **"Refresh now"** that opens the existing manual capture path (P24 Auto-Import / Chrome capture) — refresh itself stays manual and outside this feature. **Built:** the button links to `admin.minion.setup` (P24 Auto-Import, gated by `access_settings`) and is shown only to a viewer who holds that permission; anyone else sees the plain "Needs a refresh" text.

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

## 8. Decisions recorded (conductor, 13 Oct 2026)

- **Half-day leave:** keeps the full target by default; an agency setting "Half-day leave halves the target" (default off) halves it. Built as `half_day_leave_halves_target`.
- **Colour bands:** green at or above the green line (90), red below the red line (70), amber between; both numbers are agency settings.
- **Wizard:** main settings in the wizard step; selection order, selection thresholds and colour limits on the settings page only. Recorded as the "Deliberately NOT in the wizard" list (rule 10a) for this feature: selections order/on-off, the six limits for the sources, the colour lines, and the per-person data (areas, each agent's own numbers).
- **PP/P24 refresh date:** derived from the newest captured listing per suburb, labelled **"last captured"** in the UI (P24 may also use `minion_capture_areas.last_captured_at` when present).
- **Landing on QA1:** each lane lands and deploys its own work to QA1, as the rentals lanes do today; one lane tests at a time (`scripts/lane-test.sh`). Note for the record: STANDARDS.md Standard −1l ("Only cc1 writes to origin/QA1") says otherwise, in the line *"The rule, stated without the gap this time: only cc1 writes to `origin/QA1`. No other lane pushes to `origin/QA1`, ever, for any reason, however small the change"*. The conductor's instruction on 13 Oct overrides it for this build; this lane has not acted on −1l.
- **Defaults chosen by lane 1 and accepted:** owner-known quantity 3; stuck stock 60 days / 30 views / 2 leads; agency default weekday targets stored in the settings row.

---

## 9. Lane 2 (cc2) — the daily list, the nine selections, the real listed date, the one address rule

Governing ticket AT-454. Section 1 is the rulebook; this section is how lane 2 builds to it. Conductor ruling 13 Oct: **the canonical property key is `tracked_properties.id`** (via `TrackedPropertyMatchOrCreateService`). `rts_list_items.tracked_property_id` is always filled when one can be resolved — including the agent's own CoreX stock in selections 8 and 9 — with `prospecting_listing_id` / `property_id` kept beside it for display and links. cc3's holds, call log and "never suggest" marker key on `tracked_property_id`.

### 9.1 What lane 2 delivers (and its pieces)

| # | Piece | Where |
|---|---|---|
| 1 | One "has an address" rule | `ProspectingListing::scopeWithAddress / scopeWithoutAddress / hasAddress` |
| 2 | A real listed date | `prospecting_listings.listed_date`, `listed_date_source`, `prospecting_listing_date_corrections`; `ListedDateRecorder` (OAS import + pull); `mic.listed_date.correct` |
| 3 | `rts_list_items` + model + scoping | `App\Models\RoadToSold\RtsListItem` |
| 4 | The nine selections | `App\Services\RoadToSold\Selections\*` behind one `Selection` interface |
| 5 | `ListBuilderService::buildFor / addToDay`, nightly command, missed/rollover | `App\Services\RoadToSold\ListBuilderService`, `rts:build-lists` |
| 6 | Hot pockets leads + sales join | `App\Services\RoadToSold\HotPocketService` |

**Status 13 Oct (end of lane 2's first pass): all six pieces are built, tested and deployed to QA1.** Lane 2 is wired to lane 1's real `SettingsService` / `AreaService` / `WorkdayService` and lane 3's real `HoldService` / call log (`OutcomeService::followUpsDue`) through the four adapters in `Services/RoadToSold/Adapters` — **no stubs remain**: each adapter calls the real class directly (`WorkdayService`, `AreaService`, `HoldService`, `OutcomeService`); tests inject fakes through the contracts. Lane 3's page reads `rts_list_items` through `RtsListItem::visibleTo()` / `forDay()` and builds on demand with `app(ListBuilderService::class)->buildFor($user, $date)`; "Add to my day" is `->addToDay($user, $selectionKey, $count, $date)` (throws `InvalidArgumentException` with a plain-English message). Marking an item worked is done by lane 3's `OutcomeService` (it sets `status='worked'` through `list_item_id`); `ListBuilderService::markWorked()` exists for any other caller.

### 9.2 Piece 1 — the one address rule (BUILT)
A listing **has an address** when its own captured `address` text is real (not null, `''` or the legacy "Address not available") **or** its tracked property has a `street_name`. The second half is what makes an address corrected through `TrackedPropertyController::editAddress` count: that edit writes the tracked property (history row + cached `street_name` via `TrackedPropertyAddressObserver`) but never touched `prospecting_listings.address`. `tracked_properties.street_name` (the cache) is the test, not `tracked_property_addresses`, because the history table has a primary row for only ~105 of ~40,000 properties on QA1. Used by the Work tab "with address" tick and by the list builder. The Opportunities tab's own "With address" chip (`primaryAddress.street_name`) is **not** changed (outside this task) — it undercounts for the same reason; reported.

### 9.3 Piece 2 — the real listed date (BUILT)
* `prospecting_listings.listed_date` (date, nullable) + `listed_date_source` (`portal` | `agent`). `first_seen_at` is untouched (it is the capture moment; 31k rows share an August bulk-capture clock).
* **Johan's ruling, 13 Oct (supersedes §1's "needs a REAL listed date" for selection 5): the portals' search tiles carry NO listed date** — it exists only when the listing page itself is opened. So the search capture (`POST /api/v1/prospecting/import`) never reads or stores one, and **there is no extension release for dates** (an earlier 3.12.0 tile reader was removed; the extension is back at 3.11.0, the capture API is byte-identical to before).
* **Where a real date comes from:** (1) the listing-page captures that already send it — the **Other Agency Stock import** (`listing_ref` + `date_posted`) and the single-listing **Pull property** (`portal_ref` + `date_posted`); both now call `App\Services\Prospecting\ListedDateRecorder::record()`, which stores the date on the MIC listing for that advert (`portal` + ref match, same agency, not deleted). It only UPDATES a listing MIC already holds — it never creates one, never overwrites an agent's correction, ignores blank / "0" / words / impossible ("31 Feb") / future / pre-2000 dates, and can never fail the import that called it. (2) P24 alert emails — later, through the same recorder. (3) The agent's manual correction (below). (1b) The **presentation portal capture** (`PortalCaptureController::ingest`, a P24 listing page uploaded whole) now also hands the page's JSON-LD `datePosted` (single object, list or `@graph`; `ListedDateRecorder::datePostedFromJsonLd`) to the same recorder — updates only, agency taken from the capturing user, never throws; the presentation extractor itself is unchanged.
* `ProspectingListing::listedOn()` = listed date, else first-seen day. `daysOnMarket()` uses it. `scopeListedWithinDays` / `scopeListedBeforeDays`: by the real date when present (calendar-day compare), else by `first_seen_at` exactly as the old `new_today` preset compared it. The `new_today` preset and the Work row's date use these.
* **Selections 5 and 6 both use the real date when CoreX has one, otherwise the day CoreX first captured the advert, so neither is ever empty for want of a date — and the row says which it is:** "Listed 4 days ago" vs "First seen by us 4 days ago (listed date not known)"; "On the market for 166 days" vs "…at least 135 days (first seen by us; listed date not known)". (Caveat: a first-seen "new" can be wrong when a suburb was only recently added to capture; the label is the safeguard.)
* Correction: `PUT /corex/market-intelligence/listing/{listing}/listed-date`, permission `mic.listed_date.correct` (admin + branch manager by default; run `corex:sync-permissions` on QA1), date + required reason (min 5), looked up through the viewer's own MIC data scope (outside it = 404). History in `prospecting_listing_date_corrections` (old → new, who, reason; soft delete). Form on the opportunity detail page.

### 9.4 Piece 3 — `rts_list_items`
Columns: `id`; `agency_id` NOT NULL; `branch_id` nullable (copied from the agent); `user_id` (whose list); `list_date` date; `track` varchar(20) default `'sales'`; `tracked_property_id`, `prospecting_listing_id`, `property_id` (all nullable, no FK — project convention); `selection` varchar(40) (a selection key, or `follow_up`); `origin` `auto|follow_up|added|rollover`; `position` smallint; `status` `open|worked|missed|rolled`; plus lane-2 additions `reason` (plain-English "why suggested", shown on the row), `rolled_from_item_id`, `status_changed_at`; `timestamps`, `softDeletes`.
Unique `(agency_id, track, user_id, list_date, tracked_property_id)` — a property is on a day's list once (NULL tracked ids are de-duplicated in code by listing id). Because a unique index ignores soft-deletes (BUILD_STANDARD §5a) the builder inserts through `withTrashed()->firstOrNew` + `restore()`. The builder never soft-deletes; statuses carry the lifecycle.
Scoping: `RtsListItem::scopeVisibleTo($user, ?$scope)` — `all` agency, `branch` = `effectiveBranchId()`, `own` = `dataIdentityIds()`, otherwise nothing; scope read from `PermissionService::getDataScope($user,'road_to_sold')`. Agency isolation by `BelongsToAgency`. Lane 3's page reads **only** through this scope.

### 9.5 Piece 4 — selections
One interface `Selection`: `key()`, `label()`, `candidates(SelectionContext $ctx, int $limit): Collection<Candidate>`. A `Candidate` carries `trackedPropertyId`, `prospectingListingId`, `propertyId`, `reason`, `hasAddress`, `inArea`. Order inside every selection: **area tier first** (agent's own suburbs, then the rest — or only own when "Lock agents to their areas" is on), **then with-address before without**, then the selection's own rank. (Open question Q6: if an addressed property outside the agent's area should instead beat an address-less one inside it, it is one comparator to flip.)
Shared exclusions (every MIC-listing selection, 1–7): inactive/off-market; the agency's own on-market stock (`whereNotCompanyStock`); a property whose tracked/matched CoreX property belongs to **another** agent; an active MIC claim or pitch lock held by another user; plus the **suggestion guard** (cc3: held by another agent, do-not-contact) and the agent's own still-held properties. Twin adverts (P24 + PP of one property) collapse to one candidate on `tracked_property_id`.

| Key | Source | Rank |
|---|---|---|
| `my_buyers_sourcing` | `prospecting_buyer_matches` (not dismissed, tier perfect/strong) for buyers whose `contacts.agent_id` / `second_agent_id` is the agent and `buyer_state` new/warm | most matching buyers, best score |
| `agency_buyers_sourcing` | same, any agent's buyers | same |
| `hot_pockets` | `HotPocketService` (below) | pocket score |
| `price_drops` | `prospecting_price_history` (new < old) inside the lookback | biggest % drop, most recent |
| `new_on_market` | listed (else first-seen) within `newOnMarketDays`; row says which | newest first |
| `long_on_market` | listed (else first-seen) more than `longOnMarketDays` ago; row says which | longest first |
| `owner_known` | owner known by any of the four paths (tracked property `owner_contact_id`; a `tracked_property_owners` row with a contact; a `contact_property` seller on `matched_property_id`; an owner on the deed-linked tracked property) | shown again, capped at `ownerKnownQuantity` per day |
| `my_expired_stock` | the agent's own `properties` (`agent_id` in `dataIdentityIds()`), `status='expired'`, sale | latest `expiry_date` first; listing agent only |
| `my_stock_not_selling` | the agent's own on-market properties with `DaysOnMarket` ≥ `stuckStockMinDaysOnMarket`, portal views (last 30 days, `property_portal_metrics`) ≤ `stuckStockMaxViews`, leads ≤ `stuckStockMaxLeads` | longest on market first; listing agent only |

Selections 8 and 9 read **only** the user's own properties — there is no code path that reads another agent's stock (tested). Tracked-property id for own stock = `tracked_properties.promoted_to_property_id` lookup, else a read-only match; null if none, with `property_id` carried.
**Hot pockets (piece 6):** a pocket is a suburb. Score = distinct active buyers with a perfect/strong match on listings in that suburb **+** the agency's own portal leads (`portal_leads` → `properties.suburb`) **+** the agency's own completed sales (`deals_v2` → `properties.suburb`) inside the lookback, equal weights, explained on the row ("14 buyers, 6 leads, 3 sales in Uvongo"). The join is the one the investigation found: `portal_leads.listing_id` and `deals_v2.property_id` both resolve to `properties`.

### 9.6 Piece 5 — the builder
`ListBuilderService::buildFor(User $u, CarbonInterface|string $date, string $track='sales'): Collection` — idempotent (if a list already exists for user+date+track it is returned untouched). Order: (1) **follow-ups due on or before the date** (origin `follow_up`, from cc3's `rts_contact_outcomes.next_action_at`; overdue ones carry forward), (2) rolled-over items (origin `rollover`) when the agency rolls over, (3) new properties from the enabled selections **in the agency's order** until `WorkdayService::targetFor` is reached — follow-ups and rollovers **count toward** the target. Every property counts whether or not an owner is on file. Not a working day (`isWorkday` false) → no list. `addToDay(User, string $selection, int $count, $date=today)` adds extra items with origin `added` (not counted toward the target, reported separately); count 1–100; the selection must be enabled.
Nightly: `rts:build-lists [--date=] [--agency=] [--user=]`, scheduled 03:30. For each eligible agent (holds `access_road_to_sold`, active) it first **closes** open items of earlier days (`missed`, or `rolled` + a new `rollover` item on the next working day when the agency rolls over), then builds today's list if today is a working day. Run by hand on QA1 (no scheduler there).
Decoupling: lane 2 depends on four small contracts — `BuilderSettings`-style reads from `SettingsService`, `WorkCalendar` (`WorkdayService`), `AreaProvider` (`AreaService`), `SuggestionGuard` + `FollowUpSource` (cc3). Each has a default adapter that calls the lane's real class; tests inject fakes (`NullSuggestionGuard` lives in the tests). Nothing in lane 2 edits another lane's files.

### 9.7 Settings lane 2 asked lane 1 for — DELIVERED
`hotPocketLookbackDays` (180), `priceDropLookbackDays` (30), `stuckStockLookbackDays` (30) are now real columns on `rts_settings` and getters on `SettingsService` (lane 1's migration `…100050_add_lookback_windows_to_rts_settings`). `BuilderSettings::forUser()` reads them; nothing is hard-coded in lane 2.

### 9.8 Input space
| Input | Rule |
|---|---|
| Agent with no areas, lock off | Absorb: everything is "outside area"; list built from the agency pool |
| Agent with no areas, lock on | Prevent: no list; the page shows the lane-1 warning |
| Target 0 / not a working day | No list, no error |
| Fewer candidates than target | Partial list; never padded with ineligible properties |
| Property resolved to no tracked property | Keep on `prospecting_listing_id` / `property_id`; de-dupe by those |
| Same property from two selections | First selection (agency order) wins; counted once |
| Build run twice / nightly + on demand at once | Idempotent: unique index + `firstOrNew`; second run adds nothing |
| Add to day with 0, negative, huge or unknown selection | 422-style refusal, nothing written |
| Selection 8/9 for an agent with no own stock | Empty; the day fills from other selections |
| Tracked property deleted after listing | Row dropped from candidates |
| Second agency | Own settings, own pool, own lists; nothing from agency 1 |

### 9.9 Open questions (lane 2 → conductor; each has a default so building is not blocked)
* **Q6 (business):** Inside a selection, does the agent's **area** or the **address** come first? *Built:* area first, then with-address. Flip is one comparator.
* **Q7 (business):** Two agents who share a suburb, on a day when neither has worked a property yet, can be handed the same property (the hold only starts after an outcome is captured). *Built:* only rule (4) of the brief; no cross-agent de-duplication of the same day's lists. Do you want one?
* **Q8 (process):** Strict agency-order fill means selection 1 can use the whole day's target on its own. *Built as written.* A per-selection share would be a setting; not built.

---

## 10. Lane 3 (cc3) — outcomes, holds, the page, the summary bar, the report

Governing ticket AT-454. Section 1 is the rulebook. **Canonical property key = `tracked_properties.id`** (conductor ruling 13 Oct): holds, the call log, the "never suggest" marker and every read here key on `tracked_property_id`; `property_id` / `prospecting_listing_id` ride alongside for display and links. Owners with no contact record keep history on the property only.

### 10.1 Data model (all: `agency_id` NOT NULL + `BelongsToAgency`, `track` default `'sales'`, soft deletes)

| Table | Purpose | Notes |
|---|---|---|
| `rts_outcome_definitions` | The agency's outcome list | `key` (stable, stored; the seeded eight keep theirs when renamed), `label`, `kind` attempt\|conversation, `next_action` (`return_after_days`\|`return_on_picked_date`\|`return_on_expiry_else_picked`\|`move_to_list`\|`never_again`\|`calendar_appointment`), `delay_days`, `list_tag`, `counts_as_no_answer`, `records_do_not_contact`, `is_active`, `is_system`, `sort_order`. Unique `(agency_id, track, key)` ignores soft deletes → **create after archive restores** the trashed row. Seeded lazily per agency+track by `OutcomeDefinitionService::ensureDefaults()` (never for agency id 0). |
| `rts_contact_outcomes` | The call log | One row per captured outcome: `user_id` (the agent), `tracked_property_id`, `property_id`, `prospecting_listing_id`, `list_item_id`, `contact_id` (owner, when known), `outcome_key/label/kind` **copied** (history survives a rename/archive), `note`, `valuation_booked`, `calendar_event_id`, `next_action`, `next_action_at`, `list_tag`, `no_answer_streak`, `dropped`, `is_current`, `occurred_at`. `is_current` = newest outcome per (tracked property, track). |
| `rts_holds` | Who is working a property | One row per (agency, track, tracked property); `user_id`, `started_at`, `expires_at`, `released_at`. A new outcome re-uses the row (restores a trashed one) and restarts it. |
| `rts_property_flags` | `never_suggest` marker | Set by a "Do not contact" outcome so a property with no owner contact is still excluded for every agent. |

### 10.2 Capture rules (`RoadToSold\OutcomeService::record()` — one transaction)
1. **Hold:** another agent's active hold refuses the capture with "X is working this property until D" — except an outcome flagged `records_do_not_contact`, which is always recordable.
2. **Next action:** `return_after_days` → today + delay; `return_on_picked_date` → agent's date (required, today or later); `return_on_expiry_else_picked` → the mandate end date when CoreX knows it (**no stored expiry exists for other agencies' mandates today, so the agent always picks the date** — the single hook is `OutcomeService::knownExpiryDate()`), else the picked date; `move_to_list` → the outcome's list tag, no date; `never_again` → no date; `calendar_appointment` → creates the appointment.
3. **Valuation booked** is offered on every *conversation* outcome (never on an attempt, never on "never again"). It needs a date-and-time, creates a `property_evaluation` calendar event through `CalendarEventCreator` (owner contact linked when known), sets `valuation_booked`, and — because the appointment is the follow-up — the property leaves the call list (`next_action = calendar_appointment`) whatever outcome it was booked from. *Decision (mine, within the brief):* the brief seeds "Valuation booked" as its own outcome **and** says it is offered on every conversation outcome; both are built.
4. **No answer run:** `counts_as_no_answer` outcomes count consecutively for this agent on this property; any other outcome (or another agent's) resets the run. At `SettingsService::noAnswerDropAfter()` the row is `dropped` (never a follow-up again) and shows on the manager's report. The agent's hold on the property continues until it lapses.
5. **Owner contact side** (only when the tracked property has an owner contact): a `ContactNote` ("Road to Sold - <outcome>: <note>") is written — that is the history on the contact, visible in the contact's existing Notes tab; **conversations call `Contact::markContacted()`** (moves last-contacted, fires `ContactContactedByAgent` — intended, conductor ruling), attempts do not. A plain note never moves last-contacted (unchanged rule).
6. **Do not contact:** `Contact::setConsent('channel_call', declined, 'verbal', …)` → `recomputeChannelConsent()` derives `opt_out_call` (never written directly) **and** `HoldService::markNeverSuggest()` (so no-owner properties are excluded too). No hold is created.
7. **Hold** (not for do-not-contact): `holdDays()` from the occurrence.
8. After commit, `rts_list_items.status` is set `worked` for the `list_item_id` if given (lane 2 owns the table; this is the only write lane 3 makes to it).

### 10.3 Interfaces other lanes call
* `HoldService::isHeldByOther(int $trackedPropertyId, User $u, string $track='sales'): bool`, `heldByOtherIds(array, User, track): int[]`, `ownHeldIds(array, User, track): int[]`, `excludedIdsFor(User, array, track): int[]` (held by another ∪ never-suggest). Every query filters `agency_id` explicitly (the overnight build has no signed-in user, where `AgencyScope` is skipped).
* `OutcomeService::followUpsDue(User, date, track): Collection<RtsContactOutcome>`, `currentFor(agencyId, ids, track)`, `historyForProperty(agencyId, id, track)`.
* **For lane 2's contracts:** `App\Services\RoadToSold\Adapters\HoldSuggestionGuard implements SuggestionGuard` and `…\OutcomeFollowUpSource implements FollowUpSource` are the real answers (bind them when `HoldService` exists).

### 10.4 Outcome settings screen (`road-to-sold.settings.outcomes.index` etc., `road_to_sold.manage_settings`)
Agency-wide list. **Search:** outcome name. **Filter:** All / switched on / switched off / archived. **Sort:** order shown to agents (default), name, attempt-or-conversation. Pagination 50. Add, edit (name, counts as, what happens next, days, list name, counts toward no-answer run, stops calls), switch on/off, move up/down (only in default sort, no search), archive (confirm), restore. Real empty states for "no match" vs "none yet". Scoping: `BelongsToAgency` + route binding (another agency's id → 404); gated by `road_to_sold.manage_settings`.
Wizard (rule 10a): the outcome list is configured here, not in the wizard step — **Johan's call** whether the wizard should also walk through it; recorded as "Deliberately NOT in the wizard" until he says (the wizard step already carries hold days and the no-answer count, lane 1).

### 10.5 The page — `GET /road-to-sold` (`road-to-sold.index`), permission `access_road_to_sold`
Sidebar link "Road to Sold" (Real Estate group, `@feature('road-to-sold')`). Server-rendered Blade; Alpine only for the popup and filters.
* **Viewing whose list:** the viewer's own list by default; a viewer whose breadth is branch/all can pick an agent from those in scope (`?agent=`). A user outside scope → 404. All reads go through `RtsListItem::scopeVisibleTo()` / `RtsContactOutcome::scopeVisibleTo()` (own = `dataIdentityIds()`).
* **Show:** Today's list (default) · No answer · Wrong number · No owner. *Today's list* and *No owner* read `rts_list_items` for the day; *No answer* and *Wrong number* read the agent's **current** outcomes across all days (so a property can be found when the owner phones back).
* **Columns:** Address (street, suburb) · Price · Days on market · Listing agency · Why suggested · Owner + phone (or **Find the owner**) · Last outcome (label, date, who) · **History** (only when the property has outcomes the viewer may see) and **Contacted client**, side by side, same height.
* **Search (named):** address, suburb, owner name, owner phone. **Filters:** selection (the nine + follow-up), status (Open default · Worked · Missed · All), Show (above). **Sort:** list order (default), address, price, days on market, last outcome date. Pagination 50 (the day's list is short; the other views are not).
* **Empty states:** no list built yet / not a working day / no match for this filter — each says what to do.
* **Find the owner:** one link to the existing owner-capture screen for the tracked property (`seller-outreach.entry.from-tracked-property`), which carries the CMA Info (title-deed) link, the TVA ingest and the manual contact link — the three existing owner paths live on that one screen.
* **Contacted client popup:** outcome list (active only, agency order); date picker only for outcomes that need one; "Valuation booked" tick (conversations) → date-and-time; note; Save. Errors re-open the popup with the message.
* **History popup:** the property's last 10 outcomes the viewer may see (own = own calls, branch = branch, agency = all): outcome, attempt or conversation, valuation booked, date, who, back-on date, note. Read through `OutcomeService::historyForPage()` (one query for the whole page, `RtsContactOutcome::scopeVisibleTo`). The same outcome is also a note on the owner contact (Notes tab) - history on the property and on the contact.
* **Layout rule (Johan):** screen space is for data and controls only - no explanatory paragraphs under headings or empty states; every field, dropdown and button on the page, the report and the outcome settings is the same height (`road-to-sold/_controls.blade.php`, class `rts-page`).
* **Track:** every Road to Sold request carries a track (`RoadToSold\Track::fromRequest()` - route default or `track` field; anything unknown = sales). The page, outcome list, capture, add-to-day, summary bar, report, print/PDF/XLSX and drill-down all use it; links and forms keep it (`Track::params()` - nothing is added for sales). The three feed blocks are sales work and are not shown on another track. Open: the on-demand list build in `RoadToSoldController::buildIfMissing()` and `RtsListItem::forDay` do not take a track yet - that block is cc4's current fix and was left alone.
* **Dates in the past:** an agent-picked return date and a valuation date-and-time are refused with a plain message when they are before today / before now (`OutcomeService`).
* **Report vs drill:** the call-log drill left-joins `tracked_properties`, so a call on an archived property is in the drill exactly as it is in the report; the row reads "(property archived)".
* **Add to my day:** selection + count → `ListBuilderService::addToDay()` (lane 2).
* **Blocks under the list (not counted against the target), each own-scoped and correct (not the Today-page queries):** buyer follow-ups (`contacts.agent_id` in the agent's identity — **not** `created_by_user_id` — stale beyond the agency's buyer-warm/cold days, newest risk score only, no hard-coded 14 days); lead follow-ups (`LeadResponseService::results()` waiting/overdue for the agent); expiring mandates (`MandateExpiryPolicy` window for the agent's own listings).

### 10.6 Summary bar and report (BUILT)
**Summary bar** (`SummaryBarService`, on the page). Steps and order come from `SettingsService::summarySteps()` (each switchable by the agency); social shares is not built. Each step is the month so far, counted by `DrilldownService::count()` - the same query as the rows behind the figure, so the two cannot disagree.
* `calls_made` = every outcome captured; `conversations` / `valuations_booked` from the call log; `valuations_done` = the agent's `property_evaluation` calendar appointments with feedback captured; `mandates_signed` = mandate e-sign documents owned by the agent that reached COMPLETED (the same signal that credits `mandate.signed` points); `listings_live` = the agent's sale listings whose `listed_date` (first portal publish) falls in the month.
* **Should-be-by-today:** calls = `WorkdayService::monthTargetToDate()`; listings live = the agent's Agency Tracker `targets.listings_target` for the month x the share of the month's target days already passed (calendar days when the agent has no call target). **Conversations, valuations booked, valuations done and mandates signed have no monthly target (Q9): the bar shows the actual only, no colour.**
* Colour: `SettingsService::statusForPct()` - green on or above the green line (default 90), red below the red line (default 70), amber between.
* Click a tile -> `GET /road-to-sold/drilldown` (`road-to-sold.drilldown`), the shared drill-down envelope `{title,total,columns,rows,truncated}` rendered by `buyers-report/_drilldown-modal`.

**Report** (`GET /road-to-sold/report`, `road-to-sold.report`; `.print`, `.pdf`, `.xlsx`). Permission `access_road_to_sold`; breadth by `road_to_sold.view` (own = the viewer only, branch = the branch, all = the agency). One row per agent plus a Total row, one column per figure:
`Target` (WorkdayService over the period) - `Worked` (list items worked toward the target) - `Attempts` - `Conversations` - one column per outcome (the agency's current list in its own order, plus any outcome logged in the window even if archived since) - `Valuations booked` - `Extra over target` (items worked after "Add to my day") - `Missed / rolled over` - `Dropped after no-answers`.
* Reuses `PeriodResolver`, `Period`, `PeriodComparison`, `performance.agency-report._period-selector`, `<x-performance-delta>`, the drill-down modal and its envelope. **Added the `last_30_days` preset to `PeriodResolver::PRESETS`: it now also appears in the period selector of the Agency Performance, Buyers and Lead Response reports** (30 days ending today, today included; tested in `PeriodResolverTest`).
* **Compare:** any compare mode the selector offers (previous, same period last year, custom). Each cell shows the figure and, beneath it, the delta line; direction of good: more is better for worked / attempts / conversations / valuations / extra; fewer is better for missed and dropped; target and each outcome are neutral.
* **Every figure drills to its rows** - agent cells, the Total row, and the delta line (rows of the comparison period, via `source=compare`). The drill base carries `period`, `start`, `end`, `compare`, `compare_start`, `compare_end`, `branch_id` and the agent search, so the total row drills the same agents the page shows. `DrilldownService` metrics: `target` (one row per working day), `worked`, `added_worked`, `missed`, `attempts`, `conversations`, `valuations_booked`, `dropped`, `outcome:<key>`.
* **Search:** agent name. **Filter:** branch (only when the viewer can see more than one). **Sort:** agent name A-Z (default) or any column, ascending / descending. **Pagination:** 50 agents a page (totals cover everyone matching, not just the page). **Empty state:** "No agents match this search" vs "There are no agents to report on yet".
* **Print / PDF / XLSX** are the same cohort, period, comparison and sort as the page (PDF via DomPDF A4 landscape, XLSX via OpenSpout; text cells beginning `= + - @` are prefixed so a name cannot become a formula).
* Scoping per screen: page, print, PDF, XLSX and the drill all take their agents from `CallListService::viewableAgents()` (own / branch / agency) and add the agency wall in every query; an agent id outside the viewer's breadth is a 404.

### 10.7 Input space (prevent or absorb)
| Input | Rule |
|---|---|
| Outcome name blank / > 120 | Prevent, plain message |
| Days blank / 0 / non-number for "after days" | Prevent |
| List name blank for "move to list" | Prevent |
| Archive then add the same name | Absorb: the archived row is restored |
| Capture with a switched-off / archived / other agency's outcome | Refuse: "not available any more" |
| Capture on another agency's property | Refuse: "could not be found" |
| Picked date blank / unreadable / in the past | Prevent, plain message |
| Valuation booked without a date-and-time / on an attempt | Prevent / ignored |
| Note blank | Absorb; > 5000 refuse |
| Property held by another agent | Refuse with who and until when (do-not-contact excepted) |
| Owner has no contact record | Absorb: history on the property only |
| Do not contact, no owner contact | Absorb: marker only |
| Same outcome captured twice quickly | Two honest rows; streak and hold behave |
| Agency id 0 / no signed-in user | Defaults, nothing written, nothing crosses agencies |

### 10.8 Open questions (lane 3 → conductor; each has a default so building is not blocked)
* **Q9 (business):** The summary bar needs a *monthly target per step*; the brief gives weekday targets for calls only. *Built:* calls use the weekday targets, listings live uses the Agency Tracker listings target; **conversations, valuations booked, valuations done and mandates signed show the actual only (no "should be", no colour) until Johan says how they are targeted.**
* **Q10 (data):** Other agencies' mandate **end dates** are not stored anywhere, so "expiry date if known else agent-picked" is always agent-picked today.
* **Q11 (process):** lane 3 lands on `origin/QA1` itself (conductor, 13 Oct) although Standard −1l says only cc1 does; acted on the conductor's word.


## 10. Wizard step as built (lane 1, step 5)

Step key `road_to_sold` (`config/agency-onboarding-copy.php`; `AgencyOnboardingSetup::STEPS` position 8b; gate `road_to_sold => road-to-sold`). Controls (all with `explain` + "What this changes:"): `default_target_1..7` (Monday..Sunday, 0-200), `half_day_leave_halves_target`, `lock_agents_to_areas`, `area_refresh_warn_days`, `missed_items_mode`, `no_answer_drop_after`, `hold_days`. Saver: `RoadToSold\SettingsController@saveFromWizard` - requires `road_to_sold.manage_settings` (403 before any write, absorbed by the wizard), maps `default_target_N` to the settings page's `default_targets[]` only when all seven are posted, then calls the same `saveFor` (partial-update safe: anything the step did not post is left as it was). Values shown come from `SettingsService` through an explicit per-key arm in `AgencySetupWizardController::currentValues`. **Deliberately NOT in the wizard (Johan, 13 Oct):** the call-list sources (order, on/off) and their limits and look-backs, the colour lines, the on-track bar steps; and each agent's own suburbs and weekday numbers (per-person records). Also recorded in `agency-onboarding-setup.md` §5 / §5.1.

### 9.10 Proven on real QA1 data (13 Oct 2026, rolled-back transaction + read-only timing)
* Agent 29 (5 buyers matching): 5 items in 197 ms, all "My buyers". Agent 24: 5 items, all "My buyers", own expired stock 59 candidates, every one owned by agent 24. Johan (22): own expired stock 200 candidates, every one owned by 22.
* Selection capacity (200 asked): my buyers 200 / agency buyers 200 / hot pockets 200 / long on market 200 (all with an address) / price drops 4 (none with an address) / owner known 3 (the agency quantity) / new on market — measured before the first-seen fallback (13 Oct ruling) when it was 0 / stuck stock 0 (QA1's portal statistics stop at 7 Sep, outside the 30-day window — "no numbers, no claim").
* Timings after the fix: 0.01–0.6 s per selection, hot pockets 1.8 s (141 pockets). **Before the fix two selections ran past 60 s**: `px.id IN (pl.matched_property_id, tp.promoted_to_property_id)` in a correlated NOT EXISTS defeats the primary key on 34k live listings; replaced by two plain equality checks (0.26 s). Test data cannot show this class of fault — profile on real data.
* Strict agency-order fill (Q8) means an agent who has buyers gets a day that is 100 % "My buyers" until those run out. That is the rule as written; a per-selection share would be a setting.
