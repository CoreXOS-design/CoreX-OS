# Road to Sold v2 (and Road to Rented) — the recipe for a successful agent

**Jira: AT-454** (one ticket for the whole build; commits prefixed `AT-454:`).
Status: **spec v2, 20 Oct 2026. Spec only — no code, no data, no menu changes were made writing this.**
Governing rulings: Johan, 20 Oct 2026 (section 1, verbatim — lanes do not edit it).
Verified against `QA1` at `8ee4fb502` (read-only), 20 Oct 2026. Every `path:line` was read from that tree. Anything not read is marked **UNVERIFIED**.
Supersedes: v1 of this file (13 Oct 2026). **v1 is kept whole, unedited, in Appendix A** (including Johan's original business rules of 13 Oct 2026 07:19). Section 2 says which v1 rulings still stand.
Companion spec: `agency-tracker-rehome.md` (taking Agency Tracker apart). Where the two touch (budgets, targets, daily activity, Worksheet), section 3.5 and section 9 say who owns what.

Words: always **evaluation**, never "valuation" (v1 §12, Johan's standing rule). The legacy column `rts_contact_outcomes.valuation_booked` keeps its name; nothing user-visible says it.
Abbreviations: `RW` = `routes/web.php`; `SB` = `resources/views/layouts/corex-sidebar.blade.php`; `ASP` = `app/Providers/AppServiceProvider.php`; `CIAL` = `app/Listeners/Activity/CreditInstantActionListener.php`.
Roles: Ag agent, BM branch manager, Ad admin, Ow owner.

---

## 0. Read this first — what the code says that shapes the build

1. **Road to Sold v1 is built and live on QA1, and v2 reuses it — it does not start a parallel engine.** Page `GET /road-to-sold` (`RW:6402-6430`), list builder, nine selections, outcomes, holds, summary bar, report, settings hub, areas, weekday targets, wizard step. Detailed inventory in section 3.6. QA1 rows are test data only (166 open list items, 1 outcome).
2. **The six-step summary bar is real, not event-driven.** Every actual is a query run on page load (`DrilldownService.php:71-87`, `SummaryBarService.php:26-33`). v2's recipe needs the 13 steps measured the same way *and* auto-completed from events. Both mechanisms are specced (section 5): the query is the **truth for the funnel**, the event is the **trigger for the task tick and the celebration**.
3. **Seven of the 13 steps have no usable auto-detect event today** (section 6 is the gap register). The biggest: nothing fires when a **Road to Sold outcome is recorded**, when an **evaluation is booked or done** (only a calendar observer and a calendar-class mapping), when an **owner is linked on the deeds path**, when a **listing goes live on P24/Private Property**, when a **presentation is presented**, when a **seller live link or viewing pack is sent**, when a **paper OTP/mandate is signed**, and when a **payslip is generated**. Aftercare/referrals does not exist at all.
4. **Two dead events are registered but never raised:** `MandateSigned` and `DocumentSigned` (zero raise sites; `ASP:802, :822` register listeners to nothing). Mandates are credited by a direct call (`SignatureService.php:4003-4004`). The real e-sign completion hook is `SignatureEnvelopeFinalized` (`SignatureService.php:4141`), whose only listener today is lease-only (`ASP:338-345`).
5. **The points engine v2 replaces is double-weighted for calendar credit — verified.** `value_per_event` was seeded *from the weight* (`database/migrations/2026_06_20_120000_seed_hfc_activity_calendar_mappings.php:76`) and every reader multiplies `value × weight` (e.g. QA1: "Presentation" 5 confirmed rows score 200,000 instead of 1,000). v2 stores a **snapshotted `points_awarded`** per completion and never joins a live weight (section 7.4). Existing confirmed points are not rewritten without Johan's go.
6. **There is no level, rank or sign-off concept for agents anywhere** (`users.agent_tier` is an unused column; "tier" in commission code means revenue-share depth). Levels are new. The best reusable BM sign-off shape is the syndication approval service (section 8.3).
7. **No funnel ratio is computed anywhere today.** All numerators/denominators exist as counts through `DrilldownService::count()` (`:71`). Caution: mandates and evaluations are keyed by *document owner / calendar user*, outcomes by `rts_contact_outcomes.user_id` — a ratio across them is not on the same population (section 9.2 states the fix).
8. **Celebration infrastructure is thin.** No confetti/badge/achievement concept; `window.showToast` (`resources/views/components/toast-notifications.blade.php:120`) is the only success-styled toast; persistent alert toasts style only `warning`/`critical` (`resources/views/components/alert-popups.blade.php:177`). A 360-minute per-type-per-subject alert cooldown drops repeats (memory note `alert-cooldown-swallows-same-type-alerts.md`; **figure not re-verified in code**). The only existing "congratulations" precedent is `WonMandateNotification` (`app/Notifications/Presentations/WonMandateNotification.php:14-17`, dispatched `PresentationOutcomeService.php:251`).
9. **Road to Rented does not exist** — no route, feature key, permission, sidebar link or catalogue. Only the `track` column everywhere, `Support/Routing.php:17-20`, and a remountable settings file. Section 12.
10. **Listing Stock is frozen data** (`listing_stocks`, 213 Propcon rows, last updated 2026-08-14) — v2 must read CoreX `properties` for stock, never `listing_stocks` (see `agency-tracker-rehome.md` finding 7).
11. **Multi-agency:** the activity catalogue (41 manual definitions, calendar mappings) is HFC's — seeded for slug `hfc-coastal` only (`ActivityCalendarMappingSeeder.php:49`; the migration likewise). A second agency gets none. v2 ships a **neutral default recipe** (section 5.5) and migrates HFC's list as HFC's *own custom tasks*.

---

## 1. Johan's rulings — 20 Oct 2026, verbatim as relayed

Pasted to this lane by Johan's side. Copied exactly; do not edit.

```
JOHAN'S RULINGS (20 Oct 2026):
1. Purpose: Road to Sold is the recipe for an agent to become successful (get stock, sell stock). CoreX shows "do this now / do this next". Agent, BM and agency measure progress AND where the agent needs training.
2. Steps (map each to its CoreX screen and its auto-detect event):
   GET STOCK: 1 find (MIC lists: new on market, long on market, price drops, expired, my buyers / agency buyers sourcing; drive your area) → 2 find owner (deeds/CMA Info capture, link contact) → 3 contact + outcome → 4 evaluation booked (calendar; never "valuation") → 5 presentation created / presented / feedback → 6 mandate signed (e-sign) → 7 listing loaded, compliant, live on P24/PP.
   SELL STOCK: 8 marketing (social shares, seller live link, viewing pack) → 9 buyers (leads, response time, Core Matches, buyer pipeline) → 10 viewings + feedback → 11 OTP signed → 12 DR2 deal pending → granted → registered → payslip → 13 aftercare / referrals.
3. Two task types: (a) CoreX tasks, auto-completed from the event in CoreX (today's auto points); (b) agency custom tasks, recurring per hour / day / week / month (e.g. "drive your area once a week"). Custom tasks are NOT a tickbox: they capture what was found (stock and contacts made, as real CoreX records, e.g. properties and contacts created from the drive), and those leads then flow into the next steps. Each task has a target per period, points, and feedback/evidence. This REPLACES today's daily activity setup and capture (migrate existing activity types and points; keep the history).
4. Levels: a new agent starts at level 1 (small tasks). The BM checks, coaches and signs off, which unlocks the next level. The agency builds its recipe per level. Targets and budgets per user are Road to Sold settings (from the agency-tracker spec: company requirement, per-user budgets, targets).
5. Intelligence: from the data, CoreX predicts, advises and auto-fills (e.g. suggested next targets), and shows training flags from the funnel ratios (e.g. many calls, no evaluations → "needs training: evaluation pitch"). The Road to Sold stats are what the BM and admin use to set the next targets. The more it's used, the better the data.
6. Motivation: congratulate on each target hit, celebrate a DR2 deal, show progress so the agent is pulled to the next task. Johan wants to play with colours. Spec the states (behind / on track / ahead / achieved / celebration) with the CoreX components, and leave the colours to a later mockup round with Johan.
7. Views: agent (today's list, next action first, progress vs target); BM (team funnel, training flags, sign-offs); agency (recipe/level builder, targets, reports). Full CRUD, search, sort, filter and own/branch/agency scoping on every list.
8. Road to Rented: same engine and process, with the sales steps swapped for rental steps (to-let stock, applications, leases, inspections, renewals). Spec it as a section that reuses everything.
9. Settings live under Road to Sold settings, and every threshold is an agency setting with a default.
```

---

## 2. v1 rulings that still stand (nothing here is re-litigated)

v1 §1 (Johan, 13 Oct 2026 07:19) and the conductor decisions of 13–14 Oct stay in force unless the right-hand column says otherwise. The text is in Appendix A; do not duplicate it.

| v1 ruling (Appendix A ref) | v2 status |
|---|---|
| One new page "Road to Sold" with its own sidebar link; does not replace the Today page (§1) | **Stands.** The page grows the recipe/next-action layer (section 10.1); same route `road-to-sold.index` |
| Everything built with a `track` dimension (sales now, rentals later), no sales-only hardcoding (§1) | **Stands, and is now enforced** — section 12 lists the sales-only spots that must be made track-aware |
| Every threshold/window/rule is an agency setting with a default; multi-agency safe; `agency_id` on every table; soft delete only; own/branch/agency at the query layer (`PermissionService::getDataScope` + `scopeVisibleTo` + `EnforcesRecordVisibility`; "own" uses `User::dataIdentityIds()`) (§1) | **Stands** and is Johan's ruling 9 above |
| Areas: derived from the agency's own stock + MIC data, not Prospecting Setup (§1 replaced by §13, Johan 13 Oct 16:03) | **Stands** (v1 §13, built) |
| Areas come first, not a fence; "Lock agents to their areas" default OFF (§1) | **Stands** |
| Targets per agent per weekday, agency default Mon–Fri 5; public holidays and approved leave carry no target; "Add to my day" over-and-above; missed items show-as-missed or roll over (§1, §3.3, §5.3) | **Stands** — these are the *daily call targets* for step 1/3. The new per-task period targets (section 5.2) sit beside them, not in place of them |
| The daily list: built overnight, fill order (follow-ups, selections, address-first, never suggest held/do-not-contact/another agent's stock), nine selections switchable and ordered, "an agent NEVER gets another agent's stock" (§1, §9) | **Stands** — this *is* step 1 (FIND) |
| Other feeds shown as separate blocks (buyer follow-ups, lead follow-ups, expiring mandates), not counted against the call target (§1) | **Stands**; these blocks become the "next action" feeds for steps 9 and 6 (section 10.1) |
| Working a property: "Contacted client" popup, agency-defined outcome list with attempt/conversation, next action, hold 30 days, N no-answers drop (§1, §10) | **Stands** — this *is* step 3 (CONTACT + OUTCOME) |
| Summary bar: per step should-be vs actual, green ≥ 90 / amber < 90 / red < 70 (agency setting), click a step to see rows; "Social shares OFF — do not build"; "Financial line later" (§1) | **Stands, widened:** the bar now spans the recipe's steps (section 10.1). **"Social shares OFF" is overturned** by Johan's step 8 (marketing includes social shares); it becomes a recipe step, auto-detected from `MarketingPostPublished` (section 6) |
| One interactive report reusing PeriodResolver/Period/PeriodComparison/`x-performance-delta`, drill-down on every figure, print/PDF/XLSX (§1, §10.6) | **Stands** and is extended with the funnel (section 9) |
| Settings: one Road to Sold settings page + wizard step; role-manager keys + corex-features row + sidebar link + sync-permissions (§1, §4) | **Stands**; v2 adds tabs and keys (section 13) |
| Assistants: work the list owner's list; outcomes/holds/targets/report figures belong to the agent; an assistant is never a report row (§11.1) | **Stands.** Levels, sign-off and training flags attach to the **agent (list owner)** only; assistant activity already counts under the agent, so funnel ratios include assistant calls — Johan to confirm that is intended (section 15) |
| Properties the agent parked with their own outcome are not offered back as new (§11.2) | **Stands** |
| The word is "evaluation", never "valuation" (§12) | **Stands** |
| Screen-text rule: data and controls only, no intro paragraphs, every button same height (§5.0, 13 Oct) | **Stands** for every v2 screen |
| Wizard: main settings in the wizard step; advanced thresholds on the settings page only, recorded in the "Deliberately NOT in the wizard" list (§8) | **Stands as the method**; v2's new settings are classified in section 13.3 |
| Only cc1 writes to `origin/QA1` (STANDARDS −1l) vs conductor's 13 Oct "each lane lands its own work" (§8) | v1's note stands as recorded; **this spec makes no change to it** — the conductor decides which applies to the v2 build |
| v1 §2.1 summary-step catalogue (six steps), selection keys, `SettingsService` getters, `WorkdayService`, `AreaService` | **Stand as built.** v2 adds a task/step layer on top (section 5); nothing renamed (the keys are stored) |

What v2 **replaces from the old system** (not from v1 Road to Sold): the daily-activity setup and capture screens and the points engine behind them (Johan's ruling 3). Section 7.

---

## 3. The model in one page

### 3.1 The recipe

The recipe is an **ordered list of 13 steps** (Get Stock 1–7, Sell Stock 8–13). A *step* is a milestone in an agent's work with a stock lead or a buyer. A *task* is something an agent does inside a step. The agency builds its recipe **per level** (section 8): level 1 uses only small tasks; higher levels add tasks, raise targets, and add whole steps.

| Concept | Meaning | Where it lives (new) |
|---|---|---|
| Step | One of the 13 recipe steps (fixed catalogue, labels agency-editable). Per track (sales/rentals) | `rts_steps` (seeded neutral; agency may switch off, rename, reorder within its group) |
| Task | A thing to do in a step. **Type A — CoreX task:** completed automatically from a CoreX event. **Type B — agency custom task:** recurring, captures real records, needs evidence | `rts_tasks` |
| Task in a level | Which tasks an agent sees at level N, the target per period, the points | `rts_level_tasks` |
| Completion | One occurrence of a task (auto or captured), points snapshotted | `rts_task_completions` |
| Level | An agency-named stage; a new agent starts at level 1 | `rts_levels`, `rts_agent_levels`, `rts_level_signoffs` |

### 3.2 How the current Road to Sold maps onto this

- Step 1 (FIND) = today's daily call list with its nine selections (v1 §9).
- Step 3 (CONTACT + OUTCOME) = today's outcome capture (v1 §10).
- The six summary-bar steps (`calls_made`, `conversations`, `evaluations_booked`, `evaluations_done`, `mandates_signed`, `listings_live`, `RtsSetting.php:173-182`) are the **funnel measures for steps 3–7**. They stay as the always-on funnel. The recipe adds steps 2 and 5, and the whole Sell Stock half (8–13).
- The existing per-weekday call target (v1 `rts_weekday_targets`) remains the daily target for step 1/3 work. Per-task period targets (section 5.2) cover everything else.

### 3.3 The two truths — never confuse them

1. **Funnel truth (the numbers):** counts computed by query from the source records (outcomes, calendar feedback, signed documents, properties, deals). These drive ratios, training flags, reports and the summary bar. No tickbox can move them.
2. **Task truth (the tick):** a `rts_task_completions` row — written by an event listener (Type A) or by a captured form (Type B). It drives the agent's "done today", points, celebration and level progress.
The two should agree. A reconciliation report (section 14.2) flags drift (e.g. a task ticked "evaluation booked" with no calendar event).

### 3.4 Points

`points_awarded` is a **snapshot** written at completion (agency task points × quantity). Readers sum `points_awarded`; they never join a live weight. Editing a task's points changes the future only. This removes the weight² defect (section 7.4) and the retroactive re-scoring the old engine does (`daily_activity_entries` readers join live `activity_definitions.weight` — verified, e.g. `CompanyPerformanceService.php:134-148`).

### 3.5 Budgets and targets (the interface owned by the agency-tracker spec)

`agency-tracker-rehome.md` §4.3 defines the interface My Portal reads: `RoadToSold\BudgetService::forUser / requirementFor` and `RoadToSold\TargetService::monthlyFor` (monthly deals/value/listings/points), plus two settings tabs "Budgets & requirements" and "Targets". **v2 adopts that interface unchanged and does not redefine it.** v2 adds one thing to it: the *per-task period targets* and *level* belong to the same Targets area (section 5.2). Who builds what:

| Item | Spec | Built by |
|---|---|---|
| Company requirement, per-user personal/business budget, monthly points/deals/value/listings targets, the Targets-zeroing fix | `agency-tracker-rehome.md` §4.3, §5.7 | the rehome lane (step 4 there) |
| Task catalogue, per-task period targets, levels, sign-off, funnel, flags, celebration | this spec | the v2 lanes |
| Daily activity screens/menu merge | `agency-tracker-rehome.md` §5 (interim) | rehome step 3, then **absorbed** by v2 phase 3 (section 7.6) |

### 3.6 What exists today (reuse, do not rebuild)

**Routes** — page group `RW:6402-6430` (`permission:access_road_to_sold`, `agency.required`, `feature:road-to-sold`): `GET /road-to-sold` `index` (`RoadToSoldController@index` :36), `POST /outcomes` (`@recordOutcome` :131), `POST /add-to-day` (`@addToDay` :176), `GET /drilldown`, `GET /report` + `print/pdf/xlsx` (`ReportController` :41, :73, :79, :89, :243), outcome settings `settings/outcomes*` (`OutcomeSettingsController`, `manage_settings`; mounted inside the sales group, **not** in the remountable file). Settings group `RW:8153-8158` loads `routes/road-to-sold-settings.php` (closure, remountable): hub `/`, `general`, `areas.*`, `targets.*`. Nightly build `rts:build-lists` 04:30 (`routes/console.php:470`, `Console/Commands/RoadToSold/BuildLists.php`). Sidebar `SB:1069-1072`, settings link `SB:1101-1106`; group opener `SB:292`.
**Services** (`app/Services/RoadToSold/`): `SettingsService` (typed getters; `forAgency` :36, `forUser` :58, `statusForPct` :329), `WorkdayService` (:51-99), `TargetService` (`weekFor` :29, `saveWeek` :99, `clear` :149, `restore` :160), `AreaService` (:49-477), `ListBuilderService` (`buildFor` :109, `addToDay` :349, `markWorked` :467), `CallListService` (`viewableAgents` :41), `OutcomeService` (`record` :44), `OutcomeDefinitionService`, `HoldService`, `SummaryBarService` (`forAgent` :44), `ReportService` (`build` :49), `DrilldownService` (`supports` :34, `count` :71), `FeedService`, `HotPocketService`, `Scope`, `VisibleAgents`, `Track`. Selections in `Selections/` (registry `SelectionRegistry.php:24-34`).
**Models** (`app/Models/RoadToSold/`): `RtsSetting`, `RtsSettingAuditEntry`, `RtsAgentArea`, `RtsHiddenArea`, `RtsWeekdayTarget`, `RtsListItem`, `RtsOutcomeDefinition`, `RtsContactOutcome`, `RtsHold`, `RtsPropertyFlag`. Views `resources/views/road-to-sold/*` (index 399 lines, report, settings tabs).
**Permissions** `config/corex-permissions.php:617-623` (`access_road_to_sold`, `road_to_sold.view`, `.manage_settings`, `.manage_areas`, `.manage_targets`). **Feature row** `config/corex-features.php:214-224` (`road-to-sold`, `depends_on ['prospecting']`). **Wizard step** `road_to_sold` (`config/agency-onboarding-copy.php:1656-~1740`; saver `SettingsController@saveFromWizard` :110).

---

## 4. The 13 steps — screen, model, and auto-detect event (verified)

Legend for "Auto-detect": **EXISTS** = a domain event/observer fires today; **PARTIAL** = something fires but not at the right moment or not as an event; **GAP** = nothing, with the nearest hook. "Points slug" = an existing instant-action key (`database/seeders/ActivityInstantActionsSeeder.php`, credited via `CIAL` and the map at `ASP:646-679`). The listing of every raised event is in section 6.3.

### GET STOCK

**Step 1 — FIND**
- Screens: Road to Sold index (`road-to-sold.index`, `RoadToSoldController@index`, view `road-to-sold/index.blade.php`); MIC Work tab (`market-intelligence.work`, `GET /corex/market-intelligence`, `CoreX\MarketIntelligenceController@work` :60, `RW:7830/7834`, `access_prospecting` + `feature:prospecting`). Selections (registry `SelectionRegistry.php:24-34`): 1 `my_buyers_sourcing` / 2 `agency_buyers_sourcing` (`BuyerSourcingSelection.php:16`, reads `prospecting_buyer_matches`⨝`contacts`), 3 `hot_pockets` (`HotPocketSelection.php:13`, `HotPocketService::rank`), 4 `price_drops` (`PriceDropSelection.php:12`, `prospecting_price_history`), 5 `new_on_market` (`NewOnMarketSelection.php:16`, `listed_date` else `first_seen_at`), 6 `long_on_market` (`LongOnMarketSelection.php:14`), 7 `owner_known` (`OwnerKnownSelection.php:17`), 8 `my_expired_stock` (`MyExpiredStockSelection.php:13`), 9 `my_stock_not_selling` (`MyStockNotSellingSelection.php:24`). Shared MIC query `MicPoolSelection.php:150-203`; own-stock base `OwnStockSelection.php:21`.
- **Note vs the ruling:** Johan's list says "expired". The only expired selection is the agent's **own** expired mandates (#8). There is **no "expired competitor listing" selection**. MIC Work presets are `pitch_now_high, pitch_now, log_outcomes, my_claims, expiring (claim expiry), new_today` (`ProspectingActionPresetService.php:56-135`) — the new/long/price-drop lists exist only as Road to Sold selections. Decision needed (section 15): add "expired competitor listings" as selection #10, or confirm #8 is what he means.
- Models: `tracked_properties` (canonical key, v1 ruling), `prospecting_listings`, `prospecting_claims`, `rts_list_items`.
- **Drive your area: GAP (no concept in code).** Closest capture points: Map module (`corex.map.*`, `RW:6221-6237`; `MapActivityController.php:362-370` `matchOrCreate` source `map_prospect_launch`), Chrome extension import (`POST /api/v1/prospecting/import`, `routes/api.php:646`, `ProspectingApiController@import`; `ProspectingListing::create` :241; `linkToTrackedProperty` :544-560), manual add (`EntryPointController@storeFromProspecting` / `storeFromTrackedProperty`, `RW:5102, :5169`; source `manual_prospect_entry` `:499-516`). **A "drive your area" custom task (type B) is the answer:** it creates real tracked properties (via `TrackedPropertyMatchOrCreateService::matchOrCreate`, `:145`, with a new source type `area_drive`) and contacts (section 5.3).
- Auto-detect (existing):
  - List item worked → `ListBuilderService::markWorked` :467 sets `rts_list_items.status='worked'` (no domain event).
  - MIC claim taken → `ClaimCreated` (`ProspectingClaimObserver.php:58`) → `CIAL::handleClaimCreated` :261 → slug `mic.claim_taken`.
  - Tracked property created → `TrackedPropertyCreated` (`TrackedPropertyMatchOrCreateService.php:1659-1664`); only listener `LogAgentActivity` (`ASP:541`); **no points slug**.
  - Matched existing → `TrackedPropertyEnriched` (`:1777`).
- Verdict: **EXISTS for claim; GAP for "drive an area" and for "tracked property captured" (event fires, no task/points binding).**

**Step 2 — FIND OWNER**
- Screens: Deeds Capture (`corex.deeds-capture.*`, `GET /corex/deeds-capture`, `CoreX\DeedsCaptureController@index` :28, `RW:7765-7799`, `deeds_capture.access`); browser-extension ingress `POST /api/v1/deeds-capture` (`routes/api.php:653`, `Api\DeedsCaptureController@store`; `matchOrCreate` :668; `capture_kind='deeds_capture'` :691); `promote` (`DeedsCaptureController.php:694`, links current owners via `ContactPropertyLinker::link($contactId,$property->id,'owner')` :1133). Other owner paths: TVA capture (`TvaCompanyDirectorsController.php:154`), Map scheme owner (`MapController.php:682-697`), seller outreach link (`EntryPointController@linkSellerToProspecting` :1477, `RW:5121`, sets `owner_contact_id` :809), CMA Info capture (`PropagateCmaToProperty.php:129`, `ParseMarketReportJob.php:266`, source `cmainfo`). Owner persistence: `OwnerContactResolver::persist` (`Services/Prospecting/OwnerContactResolver.php:67`) → `tracked_property_owners` + `Contact`; `tracked_properties.owner_contact_id` written at `Api/DeedsCaptureController.php:750`.
- Auto-detect: `TrackedPropertyEnriched` (sourceType `deeds_capture`/`cmainfo`; log only); `ContactCreated` → slug `contact.captured` (cap 50/day, `CIAL::handleContactCreated` :99); `TrackedPropertyPromotedToStock` → `tracked_property.promoted_to_stock` (`CIAL` :200).
- **GAP — the moment the owner is linked on the deeds path.** `ContactPropertyLinker::link` fires no event; `ContactLinkedToProperty` is raised from 8 other sites only (`PropertyContactController.php:237,307,376`, `ContactPropertyController.php:91`, `PropertyWizardController.php:348`, `PropertyController.php:1626`, `DealRegisterController.php:1523`, `LeaseCaptureService.php:976`), not from `DeedsCaptureController.php:1133` nor `linkSellerToProspecting`. Nearest hooks: the single writer of `tracked_property_owners` (`OwnerContactResolver.php:83-91`, model has no observer) or `tracked_properties.owner_contact_id` null→set (3 write sites: `Api/DeedsCaptureController.php:750`, `EntryPointController.php:809`, `MapController.php:697`). `OwnerContactResolver::linkCurrentOwners` (:106) has no callers (dead).

**Step 3 — CONTACT + OUTCOME**
- Screen: `road-to-sold.outcomes.record` (`POST …/road-to-sold/outcomes`, `RW:6407`, `RoadToSoldController@recordOutcome` :131) → `OutcomeService::record` (`OutcomeService.php:44`), one DB transaction: holds check, next-action, no-answer streak/drop, resolves `tp.owner_contact_id`, optional calendar event for "evaluation booked" (`CalendarEventCreator::create` :121-129, category `property_evaluation`), flips earlier outcomes `is_current=false`, inserts `rts_contact_outcomes` (`:138-162`), hold (`:165-169`), `writeContactSide` (`:252-271`: `ContactNote`, `markContacted` for conversations, consent for do-not-contact), then `markListItemWorked` (:274). Seeded outcomes `OutcomeDefinitionService.php:27-41` (no_answer, spoke_evaluation_booked, spoke_call_back, spoke_not_interested, listed_other_agency_open, mandated_other_agency_sole, wrong_number, do_not_contact). Tables `rts_outcome_definitions`, `rts_contact_outcomes`, `rts_holds`, `rts_property_flags`.
- Auto-detect: **GAP.** `record()` dispatches **no event** and `RtsContactOutcome` has no observer. Hook: after the transaction at `OutcomeService.php:171`, or the create at :138. Indirect only: for conversation outcomes with an owner contact, `Contact::markContacted` (`Contact.php:2002`) fires `ContactContactedByAgent` (channel `contacted_action`, `Contact.php:2015`); its listener `RecordLeadFirstResponse` handles lead-response time and awards no points. It does not fire for attempts or when the property has no owner contact.
- v2 adds `RoadToSoldOutcomeRecorded` (a real domain event, `AbstractDomainEvent`), carrying outcome key, kind, track, tracked property id, actor.

**Step 4 — EVALUATION BOOKED (calendar; never "valuation")**
- Screens: Command Center calendar (`command-center.calendar`, `RW:2318`, `CalendarController@index`); create `command-center.calendar.store` (`RW:2417`, `CalendarController@store` :1853, `CalendarEventService::createManual` :34); complete `…calendar.complete` (`RW:2420`, `@complete` :2250); feedback `…feedback.show/store` (`RW:2429-2430`, `@storeFeedback` :1499). Model `CommandCenter\CalendarEvent`; related `calendar_event_feedback`. Class `property_evaluation` is manual-creatable (`CalendarEventService.php:26-28`; seed `CalendarEventClassSeeder.php:118`, `completion_behaviour require_feedback`). Links to the property/contact via `calendar_events.property_id`/`contact_id`.
- Auto-detect (PARTIAL, observer-based, **no domain event**):
  - Booked: `CalendarEventObserver::created` (`ASP:267`) → `ProvisionalPointService::credit` (`Services/Activity/ProvisionalPointService.php:47`) resolving `activity_definition_calendar_classes` by `event_class`. QA1 agency 1: `property_evaluation` → "Appointments" (weight 200), `listing_presentation` → "Presentation" (weight 200); provisional with back-date window, daily cap, duplicate-in-week gate; cancel/dismiss revokes it.
  - Done: `CalendarEventFeedbackObserver::saved` (`ASP:266`, `app/Observers/CalendarEventFeedbackObserver.php:17`) confirms the provisional points (`PointStateService::confirm` :32) and fires `ContactContactedByAgent('appointment_feedback')`; feedback capture sets `calendar_events.status='completed'` (`CalendarController.php:1395, :1745`).
  - A skipped feedback leaves a missed-feedback task (`source_type='calendar:missed_feedback'`, `CalendarController.php:1735`).
- **GAP:** no calendar domain event class exists (`ls app/Events | grep -i calend` empty). v2 adds `EvaluationBooked` and `EvaluationCompleted`, raised from the two observers above (filtered to `category='property_evaluation'`, completed only when feedback `captured_at != null`).
- **Double-credit risk (inferred, UNVERIFIED by execution):** `OutcomeService` creates the evaluation calendar event; the calendar observer then credits the "Appointments" mapping. If a v2 task also awards for the same booking, one side must be off. Rule in section 7.5: the calendar mapping is retired in favour of the task; a single completion per (agent, evaluation event).

**Step 5 — PRESENTATION created / presented / feedback**
- Screens: `presentations.index` (`/presentations`, `RW:6639`, `Presentation\PresentationController@index` :78); create/store (:140/:162, status `draft` :212); `show` :295, `analysis` :382, `confirmAndGenerate` :496; delivery `presentations.deliveries.send` (`RW:6746`, `PresentationDeliveryController@send`, `PresentationDeliveryService.php:257`, statuses `queued, sent, failed, delivered, opened, bounced` `PresentationDelivery.php:32-37`); public view `GET /p/{token}` (`PublicPresentationController@show`, writes `PresentationSnapshotView` :116 and `snapshot_link.first_viewed_at`); outcome `presentations.outcome.record` (`RW:6732`, `PresentationOutcomeController@record`, outcomes `won_mandate, won_sale, lost_to_competitor, lost_to_no_decision, lost_to_price_dispute, lost_to_no_response, still_pending, other`). Gate `access_presentations` + `feature:presentations`. Tables `presentations` (has `property_id`, `tracked_property_id`, `seller_contact_id`, `deal_id`), `presentation_versions`, `presentation_deliveries`, `presentation_snapshot_links`, `presentation_snapshot_views`, `presentation_outcomes`.
- Auto-detect:
  - **Created: EXISTS.** `PresentationGenerated` (`PresentationGeneratorService.php:366`) → `CIAL::handlePresentationGenerated` :161 → `presentation.generated`.
  - **Feedback / outcome: EXISTS.** `PresentationOutcomeRecorded` (`PresentationOutcomeService.php:233`) → `CIAL` :171 credits `presentation.won` for won outcomes only. `presentation.lost` is seeded and active but never credited (`CIAL.php:66-70, :172-176`). Also `PresentationOutcomePrompted` (`Jobs/PromptOutcomeCaptureJob.php:101`), `PresentationOutcomeLocked` (`Jobs/LockOldOutcomesJob.php:46`, after 90 days).
  - **Presented: GAP.** `presentations.status` documents `draft|presented|locked` (`Presentation.php:14`) but only `draft` is ever written. Hooks: `PresentationDelivery` created/sent (`PresentationDeliveryService.php:257`), `PresentationSnapshotLink.first_viewed_at` null→set (`PublicPresentationController.php:~130`, "the seller opened it"), or a booked `listing_presentation` calendar event (mapped, weight 200).

**Step 6 — MANDATE SIGNED (e-sign)**
- Screens: DocuPerfect e-sign wizard (`docuperfect.esign.create`, `GET /docuperfect/esign/create`, `Docuperfect\ESignWizardController@create`; steps `docuperfect.esign.step`; `myDocuments`; group `RW:6895`, `access_docuperfect` + `feature:docuperfect`; view `docuperfect.esign.wizard`). Engine `Services/Docuperfect/SignatureService.php`; models `Docuperfect\SignatureTemplate` (`STATUS_COMPLETED` :92), `SignatureRequest`, `Document`. **There is no `Mandate` model** — a mandate is the property (`expiry_date`, status) plus a document whose template/document-type slug is `mandate`.
- Auto-detect:
  - **E-signed: PARTIAL.** `SignatureService::completeDocument` (:3866, from `approveAndAdvance` :1680 → :1658, :1825, and :2120) credits `mandate.signed` **directly** (`:3994-4005`, only when doc type is `mandate`, `DB::afterCommit`), not through a listener. The right hook for tasks is `SignatureEnvelopeFinalized` (`recordFinalizationSucceeded` :4130-4141 via `announceEnvelope` :5015; payload `signatureTemplateId, documentId, agencyId`, **no document type**; only listener is lease-only `Rentals\UpdateLeaseSigningState`, `ASP:338-345`).
  - `MandateSigned` / `DocumentSigned` (`app/Events/Mandate/MandateSigned.php`): registered (`ASP:802, :822`), **never dispatched**.
  - **Paper / wet-ink mandate: GAP** (no signed-on-paper recording path found for a mandate; UNVERIFIED). `DocumentUploaded` is raised only from `ContactDocumentController.php:63` and `LeaseCaptureService.php:923`, not from property documents.
  - Manual "mandate won" button `corex.properties.convert-from-prospecting` (`RW:5957`, `PropertyController@convertFromProspecting` :2405) sets prospecting→draft with no event; also `MandateConverted` (`TrackedPropertyMatchOrCreateService.php:2354`) on promotion.
- The "open pending cc6 hook: all parties signed" in memory is **rental inspections**, not mandates (`RentalPortalDocumentService::inspectionIsShareable()`); the mandate equivalent is `SignatureTemplate` status `completed`.

**Step 7 — LISTING LOADED, COMPLIANT, LIVE on P24/PP**
- Screens: Properties (`corex.properties.*`, group `RW:5761`, `access_properties`, `agency.required`, `deny_assistant_property_write`): create `RW:5900` → `PropertyController@create` :1091 / store :1326; wizard `PropertyWizardController` (`createDraft` :167, `finalize` :489); show `PropertyController@show` :841; go-live `corex.properties.go-live` (`POST …/{property}/go-live`, `RW:5763`, `PropertyController@goLive` :3708). P24: `corex.properties.p24-syndication.{toggle,submit,deactivate,reactivate,status,readiness}` (`RW:6085-6091`, `P24SyndicationController` submit :75); PP: `PrivateProperty\SyndicationController` submit :83. Table `properties`.
- Auto-detect:
  - **Loaded: EXISTS.** `PropertyCaptured` (`PropertyObserver.php:394/:416`) → `CIAL` :296 → `property.captured`. **Caveat:** the wizard `createDraft` also creates a property row, so the credit fires at *draft creation*, not at finalize.
  - **Compliant: EXISTS.** `go-live` → `MarketingReadinessService::snapshotCompliance` (:160) sets `compliance_snapshot_at`; `PropertyObserver` sees null→set → `PropertyCompliancePassed` (:605) → `CIAL` :306 → `property.compliance_passed`.
  - **Live on the website: EXISTS but is not the portal.** `PropertyPublished` (:620, `published_at` null→set) → `property.published`. `published_at` is the website publish flag (`Property.php:2441-2450` says syndication does not touch it).
  - **Live on P24/PP: GAP.** "Live" is derived (`isLiveOnAnyPortal` `Property.php:2441`; `scopeLiveOnAnyPortal` :2476): P24 = `p24_syndication_enabled` + `p24_ref` + `p24_syndication_status='active'`; PP = `pp_syndication_enabled` + `pp_ref` + status. First-go-live writers raise no event: `Property24SyndicationService.php:441-449` (`p24_activated_at`, plus `listed_date` via `listedDateStampOnGoLive` `Property.php:586`), `syncActivationStatus` :839/:869, `P24ListingNumberReconciler.php:170`, `PrivatePropertySyndicationService.php:253-258` (`pp_activated_at`). Hook: observer rule on `p24_activated_at`/`pp_activated_at` null→set, or a new `PropertyWentLiveOnPortal` at those lines. `listed_date` is already the "real listed date" (v1 §9.3) and is what `listings_live` counts today.
  - Syndication approval events do exist (`SyndicationApprovalRequested/Approved/Rejected/Revoked`, `SyndicationApprovalService.php:330/:419/:446/:487`).

### SELL STOCK

**Step 8 — MARKETING**
- 8a **Social shares (Ad Manager).** Screens: bulk `tools.ad-manager` (`GET /tools/ad-manager`, `AdManagerController@index`, `RW:1633`); scheduled list `tools.ad-manager.scheduled` (`RW:1665`, `.update/.cancel/.reschedule` `RW:1666-1668`, `ScheduledPostsController`); create schedule JSON `ad-manager.scheduled.store` (`RW:557`, `@store` :171; modes `at|daily|now`); single-listing publish `marketing.publish` (`RW:6064`, `PropertyMarketingController@publish`). Model `PropertyMarketingPost` / `property_marketing_posts` (statuses draft, scheduled, publishing, published, failed, cancelled, `:15-21`) — there is **no** `ScheduledPost` model. Publisher `ScheduledPostPublisher` (`claim()` :106-111, `recordPublished()` :175-198). **Listing "Post now" (AT-456 §D.10, commit e34caa9bb) is on Staging, not on QA1**; on QA1 `ScheduledPostService::schedule` refuses `mode='now'` unless every item is a company ad (:235-240).
  - Auto-detect: **EXISTS.** `MarketingPostPublished` (`PropertyMarketingPostObserver.php:39`, registered `ASP:710`) → `CIAL::handleMarketingPostPublished` :320-324 → slug `marketing.published` (`ActivityInstantActionsSeeder.php:178`). Failure: `MarketingPostScheduleFailed` (`ScheduledPostPublisher.php:295`). Caveats: it is per platform post (an ad on two platforms = two events); the raw `DB::table()->update` fallbacks (`:192, :282`) bypass the observer.
- 8b **Seller live link.** Screens: generate `corex.properties.seller-links.generate` (`POST /corex/properties/seller-links/generate`, `SellerLinkController@generate` :372-403, `RW:5797`), revoke (`RW:5798`), public `seller-link.show` (`GET /property/live/{token}`, `RW:44`, view `seller-link/live.blade.php`, seller-safe buyer feedback `buildSellerSafeFeedback` :202-205). Model `PropertySellerLink` (`generated_at, last_accessed_at, access_count, revoked_at`).
  - Auto-detect: **GAP.** No event; "sent" is never recorded. Hooks: created `SellerLinkController.php:385`; seller opened `:83-84` (the only proof the seller saw it).
- 8c **Viewing pack.** Screens: `corex.viewing-packs.index/store/show` (`RW:2580-2586`, `ViewingPackController`), buyer PDF `.buyer-pack` (`RW:2597`, `@downloadBuyerPack` :355), agent sheet (`RW:2599`), launch from a calendar viewing `command-center.calendar.viewing-pack.launch` (`RW:2423`, `launchFromEvent` :540). Model `ViewingPack` (status only `draft|ready`, `ViewingPack.php:46-48`, `calendar_event_id`).
  - Auto-detect: **GAP.** No "sent" state, no event, no observer. Hooks: created `launchFromEvent` :540 / `store` :414; buyer pack downloaded `downloadBuyerPack` :355 (a download is not proof of sending).

**Step 9 — BUYERS (leads, response time, Core Matches, buyer pipeline)**
- Leads: inbox `corex.portal-leads.index` (`RW:6359-6363`, `PortalLeadController@index` :14); model `PortalLead` (`received_at, contact_id, first_response_at, first_response_by_user_id, first_response_channel, response_tracked`). **EXISTS (arrival):** `NewPortalLeadReceived` raised at `P24LeadService.php:212`, `PpLeadService.php:222`, `WebsiteLeadService.php:117`, `SharedLinkReengagementService.php:72`, `CommandTaskPortalLeadObserver.php:120`; listeners `LogPortalLeadReceived`, `EmailPortalLeadToAgent` (`ASP:302, :318`); **no points slug**. Each arrival also seeds the buyer pipeline (`BuyerLeadCascadeService::seedFromListing`, `app/Services/Buyers/BuyerLeadCascadeService.php:47`).
- Response time: report `lead-response-report.index` (`GET /corex/lead-response-report`, `RW:1848`, `LeadResponseReportController@index` :27, `view_buyers_report`, feature `lead-response-report`). **EXISTS:** `ContactContactedByAgent` (`app/Events/Contact/ContactContactedByAgent.php`; channels `contacted_action, message, shared_link, appointment_feedback`) → `RecordLeadFirstResponse` (`ASP:441`) → `LeadResponseRecorder::record` (`Services/LeadResponse/LeadResponseRecorder.php:22-37`). Counted minutes = business-hours minutes between `received_at` and `first_response_at` (`LeadResponseService::results` `:87`, `:117-122`; target and hours are agency settings, `command-center.settings.lead-response.update` `RW:2564`). Raised via `Contact::recordAgentContact` (`Contact.php:2013-2016`) from `markContacted`, `ContactMatchShare::confirmSent` (:123), `CalendarEventFeedbackObserver`, `EmailArchiveIngestor.php:168`, `WaArchiveIngestor.php:312`, `ProvisionalReconciler.php:130`, `PendingAttachmentService.php:65`. Caveat: the `message` channel fires when an outbound email/WhatsApp is *ingested*, not at the click of send; timing may lag (**UNVERIFIED how much**).
- Core Matches: `corex.core-matches.index` (`RW:6256`, `ContactMatchController@index` :73, feature `core-matches`), `.all` (`RW:6261`, `core_matches.all_view`), share confirm `corex.core-matches.shares.confirm` (`RW:6319`, `ContactMatchShareController@confirm`), convert to deal `matches.convertToDeal` (`RW:6526`, `@convertToDeal` :789). Models `ContactMatch` (table `contact_matches`; there is **no** `CoreMatch` model), `ContactMatchShare`, `ContactMatchShareProperty`. **"Sent a property": EXISTS** — `ContactMatchShare::confirmSent` (`ContactMatchShare.php:~95-125`) writes `ContactMatchShareProperty` rows and fires `ContactContactedByAgent('shared_link')`. **"Match created": GAP** — `ContactMatchObserver` (`ASP:275`, `landOnPipeline` `ContactMatchObserver.php:101`) only; no domain event.
- Buyer pipeline: `command-center.buyers.pipeline` (`RW:2516`, `BuyerPipelineController@index` :18; deliberately no permission key, comment `RW:6209-6215`); `buyer_pipeline.view` gates only the rentals pipeline (`RW:6216-6218`). Fields on `contacts`: `buyer_state` (`new|warm|cold|lost|won`, `BuyerStateService.php:19`), `buyer_pipeline_entered_at`, `last_activity_at`; rows `BuyerStateTransition`, `BuyerActivityLog`. **EXISTS:** `ContactMarkedLostInBuyerPipeline` / `…RestoredFromLost…` (`BuyerStateService.php:94, :96`); won via `MarkBuyerWonOnPropertyLink` on `ContactLinkedToProperty` (buyer/purchaser roles) → `BuyerStateService::markWon` (:286).

**Step 10 — VIEWINGS + FEEDBACK**
- Scheduling: `command-center.calendar.store` (class `viewing`; `PropertyViewings.php:49` categories `viewing, viewings, buyer_viewing`). **PARTIAL:** `CalendarEventObserver::created` → `ProvisionalPointService::credit`; mapping `viewing`→"Take out Buyers" (`ActivityCalendarMappingSeeder.php:67`); gates: back-date limit, daily cap, no duplicate buyer+property in a week (`ProvisionalPointService.php:63-150`).
- Feedback: read `command-center.calendar.feedback.show` (`RW:2429`, `CalendarController@showFeedback` :1102); write `…feedback.store` (`RW:2430`, `@storeFeedback` :1499 → `storeViewingFeedback` :1331); `ViewingFeedbackService` (`app/Services/Properties/ViewingFeedbackService.php`; visibility `public_to_seller` :109, :148 → shown on the seller live link via `PropertyViewings::sellerNotes`). Model `CalendarEventFeedback` (`viewing_status viewed|did_not_happen|declined_on_arrival`, `captured_at`, `seller_visible_notes`, `internal_notes`, `next_action_notes`).
- Auto-detect: **PARTIAL.** `CalendarEventFeedbackObserver::saved` (`ASP:266`) confirms the provisional points, fires `ContactContactedByAgent('appointment_feedback')`, and for buyer-facing classes calls `BuyerStateService::markActivity('viewing_completed')`. **No domain event class** for viewing held / feedback captured. A step tick must count only `viewing_status='viewed'` with `captured_at != null` (blank rows exist). v2 adds `ViewingHeld`. "Feedback shared with seller" has no event either (same observer, `visibility='public_to_seller'`).

**Step 11 — OTP SIGNED**
- There is **no OTP/offer model.** The "offer" is a `Deal` (`deals.accepted_status='P'`) created by `deals-dr2.store` (`DealRegisterController@store` :374, `RW:1075`) or from a Core Match (`convertToDeal`). Document type `otp` (id 23, `esign_warning_required=1`); old `offer_to_purchase` (id 10) inactive. QA1 OTP templates (ids 11–14, 25, 26, 43) all `is_esign=0`. Classifier `DocumentTypeClassifier.php:41-45, :82`. `Document` carries `deal_id` (`Document.php:24`), `document_properties`, `document_contacts`. The e-sign floor is an acknowledgement gate (`Template::booted()` `Docuperfect/Template.php:~543-575`); `Template::isEsignBlocked()` no longer exists (the memory note is stale).
- Auto-detect:
  - **E-signed OTP: PARTIAL (signal exists, nobody listens).** `SignatureEnvelopeFinalized` fires for every completed envelope (`SignatureService.php:4141`); for an OTP nothing reacts. `completeDocument` explicitly excludes OTP from the mandate credit (`:3991-4010`, `$docType === 'mandate'`). Hook: a listener on `SignatureEnvelopeFinalized` that resolves `document_type='otp'`, then the document's property and deal.
  - **Paper OTP: GAP.** The DR2 pipeline's first step is `otp` "Deal Signed" with `completion='date_input'` (`Dr2ConditionCatalog.php:489`) — typed in by hand; it is the date anchor for every other step.
  - `SignatureEnvelopeSent` (`SignatureService.php:1137`) exists.

**Step 12 — DR2 DEAL: pending → granted → registered → payslip**
- Screens (`/deals-dr2`, `RW:1072-1220`): `deals-dr2.index` :1073, `create/store` :1074-1075, `quickUpdate` (`DealRegisterController@quickUpdate` :202, `RW:1124`), `pipeline` (`Dr2\PipelineController@show`, `RW:~1146`), `settle`/`settle.save` (`DealSettlementController` :43/:76, `RW:1138-1139`, `settle_deals`), payslip `deals-dr2.settle.print.agent` (`@printAgentPayslip` :401, `RW:1141`, view `dr2.print.payslip`). Model `Deal`/`deals`: `accepted_status` P/G/D/R (`Services/Deal/DealPipelineLockService.php:20-26`), `commission_status` Not Paid/Paid/Loss, `granted_at`, `registration_date`. (Reality check: the DR2 screens run on the same `deals` table as the old register — `agency-tracker-rehome.md` finding 1.)
- Auto-detect: **EXISTS for pending/granted/registered**, all from `DealObserver` (`app/Observers/DealObserver.php`): `DealCreated` :19, `DealStatusChanged` :32, `DealClosed` :41 (R=won, D=lost), `DealStageAdvanced` :50 (forward-only P→G→R), `DealCommissionFinalised` :64. Pipeline steps drive status through `Dr1PipelineService::completeStep` :293 → `applyStatusTrigger` :417 (forward-only), which saves the deal and so raises the observer events; step completion itself raises no event (only `logActivity`). Points listeners: `CIAL` credits `deal.created`, `deal.stage_advanced`, `deal.registered` (won only), and per-side slugs (`ActivityInstantActionsSeeder.php:201-213`); reversal when R→not-R (`CIAL` :145-158). Property-status listeners `ASP:504-516`.
- **Payslip: GAP (confirmed).** `printAgentPayslip` (:401-461) renders a view and writes nothing — no event, no stamp. The only proof of "paid" is `mark_paid` in `saveSettlement` (`DealSettlementController.php:240-268`, sets `commission_status='Paid'`, `deal_settlements.paid_at`, `deal_user.paid_at`, raising `DealCommissionFinalised`). Use `DealCommissionFinalised` as the "payslip" signal until a real generated-payslip event exists (this is what Johan's "once a payslip is generated from DR2, that deal leaves the pipeline" will need). The slug `deal.commission_finalised` is seeded (`ActivityInstantActionsSeeder.php:147`) but **not wired** in `$spineCredits` (`ASP:646-679`), so it credits nothing today.
- Caution: `DealCreated` and `DealStageAdvanced` are raised both by `DealObserver` and again by `DealRegisterController.php:621-624, :1777-1780` (the second `DealStageAdvanced` passes the same status as from/to). Points are idempotent per deal/day/user but anything that counts *events* sees duplicates (**not confirmed whether `handleDealStageAdvanced` filters same-status — UNVERIFIED**). The task listener must be idempotent on (agent, deal, stage).
- Money rule (rehome spec §4.2): deal money for My Portal and any "commission earned" celebration comes from `DealMoney`/`deal_money_lines` — never `commission_ledger` (flagged wrong; separate money spec, needs Johan).

**Step 13 — AFTERCARE / REFERRALS**
- **Nothing step-specific exists.** What exists: contact birthdays (`contacts.birthday` + `birthday_reminder`; computed calendar class `contact_birthday`, `PeopleCalendarSource.php:67-86`, off by default `CalendarLayers.php:34`); referral source data (`contact_sources` per agency; `contacts.contact_source_id` `Contact.php:42`, `source()` :164; QA1 agency 1 has "Referral" id 13, "Old Client" id 11) — not a referral-tracking model; manual counters `DailyActivity.referrals_asked`/`referral_leads` (`DailyActivity.php:30, :39`) on the dead v1 table; `ContactTestimonialSubmitted` (`ClientPortalController.php:448`, listener `NotifyAgentOfClientTestimonial`); `TitleDeedCollected` (`TitleDeedHandoverService.php:105`, deed handover).
- **GAP, whole step:** no post-sale tasks (`AutoEventService` has only `onPropertyCreated/Updated`, `onDealCreated`, `app/Services/CommandCenter/AutoEventService.php:17, :93, :101`), no referral model/routes, no client-for-life or anniversary concept (grep of `aftercare|client.for.life|anniversar|post.sale` finds employment anniversaries only). Hook to start from: `DealClosed(won)` (`DealObserver.php:41`) or `DealStageAdvanced→R` (`:50`). v2 builds step 13 as a type-B custom-task family plus a small referral record (section 5.6).

---

## 5. The task engine

### 5.1 Task types

**Type A — CoreX task (auto).** Completed by a domain event. The task row stores `event_key` (the event class) and an optional filter (e.g. `document_type = otp`, `category = property_evaluation`, `outcome = won`). A **single generic listener on the `DomainEvent` interface** (the mechanism already used by the audit logger: `Event::listen(DomainEvent::class, RecordDomainEvent::class)`, `ASP:483`) looks up which active tasks of the actor's agency that event completes, then calls one `TaskCompletionService::complete($task, $user, $subject, $context)`. This keeps task definitions per-agency **data**, not code (the per-agency pattern of `activity_definition_calendar_classes`). Registration is mandatory because listener auto-discovery is **off** (`ASP:62-86`; `EventServiceProvider::disableEventDiscovery()`; a deploy that adds a listener must run `composer dump-autoload` and `php artisan event:clear`). Copy the safety rules of `CIAL`: never throw to the caller; skip when `environment('testing')`; skip a null actor; swallow-and-log (`CIAL.php:354-382`); reverse only on genuine reversals (`PointStateService::revoke`).
Idempotency key: (user, period window, task, subject_type, subject_id) — the same shape as `InstantPointService.php:119-126`. One real record never credits the same task twice in a period.

**Type B — agency custom task (recurring, captures records).**
- Defined by the agency: label, step, recurrence unit (**hour / day / week / month**), target per period, points per unit, evidence rule, and a **capture kind**.
- Not a tickbox. Completing it opens a short capture form that creates real CoreX records:
  - `capture_kind = property` → one or more tracked properties through `matchOrCreate` (source type from the task: e.g. `area_drive`; the call appends to `source_chain`, universal Match-or-Create Rule, `CLAUDE.md` non-negotiable 10) — never a bare insert;
  - `capture_kind = contact` → contact(s) through the normal contact creation path (fires `ContactCreated` → `contact.captured` as today);
  - `capture_kind = both` → property + owner/contact linked to it (linking via the same path that fires `ContactLinkedToProperty`, so downstream step 2 detection works);
  - `capture_kind = none` → feedback/notes only (for tasks that are genuinely an activity, e.g. "attend sales meeting"); still requires a text note or a photo if the agency sets evidence required.
- Every record created carries a back-reference: `rts_task_completions.subject_type/subject_id` points at the first record created and `rts_task_completion_records` lists all (section 5.4), so "what did the drive produce?" is a list of real properties and contacts. Those records **flow into the next steps**: new tracked properties enter the agent's step-1/2/3 funnel (they appear as "found by you" in the daily list source and in the owner-known/owner-needed pools); new contacts land with the correct owner link for step 3.
- Evidence: free-text feedback (what was found), optional photos, optional GPS/area label, and the linked records. Evidence-required is a per-task setting.
- Review: BM can open a completion, accept or send it back with a comment (reuse of the provisional→confirmed/revoked lifecycle, section 8.3).

### 5.2 Targets, periods, points

Every task has, **per level**: `target` (a whole number ≥ 0) per `period_unit` (`hour|day|week|month`), and `points` (decimal ≥ 0). Periods are calendar-aligned in the agency's timezone and respect `WorkdayService` (public holidays and approved leave carry no target; a weekly target is not pro-rated for leave unless the existing half-day setting says so — reuse, do not reinvent). A per-user override of a task target is allowed (BM/Admin, within scope), the way `rts_weekday_targets` overrides the agency default. Progress for a period = confirmed completions in the period vs target. "Hourly" tasks (e.g. "10 calls in the next hour") are timeboxed counters that the page shows as a live challenge; their completions are timestamped.
Existing weekday call targets (`rts_weekday_targets`, v1 §3.3) remain; they are the daily target for step 1/3 and feed the existing summary bar.

### 5.3 What "captures what was found" means in practice

- "Drive your area once a week" → opens a map/area capture form: choose area, add found properties (address/erf, optional photo, optional owner name/phone). Each submitted property becomes a tracked property via `matchOrCreate` (matched ones enrich instead — `TrackedPropertyEnriched`), each owner/contact a contact. The completion records counts: `properties_found`, `contacts_made`. Points can be per task or per record (setting).
- The same pattern serves "knock doors", "network event", "FSBO sweep".

### 5.4 Data model (proposed, all `agency_id` NOT NULL + `BelongsToAgency`; `track` varchar(20) default `'sales'`; soft deletes; no hard deletes; prefix `rts_`)

| Table | Key columns |
|---|---|
| `rts_steps` | agency_id, track, key (stable: `find, find_owner, contact_outcome, evaluation_booked, presentation, mandate, listing_live, marketing, buyers, viewings, otp, deal, aftercare` for sales; rental keys in §12), group (`get_stock`\|`sell_stock`), position, label, is_enabled |
| `rts_tasks` | agency_id, track, step_id, key, label, type (`core`\|`custom`), `event_key` (nullable; core only), `event_filter` json (nullable), recurrence_unit (`hour\|day\|week\|month`), capture_kind (`none\|property\|contact\|both`), evidence_required (`none\|note\|note_or_photo`), is_system (seeded neutral default), is_active, sort_order, legacy_activity_definition_id (nullable; section 7) |
| `rts_levels` | agency_id, track, number, name, description, is_active |
| `rts_level_tasks` | level_id, task_id, target (int), points (decimal), required_for_signoff (bool) |
| `rts_user_task_overrides` | user_id, task_id, level_id nullable, target nullable, points nullable, set_by_user_id |
| `rts_task_completions` | agency_id, branch_id, user_id (the list owner), recorded_by_user_id (assistant), track, task_id, step_id, level_id, occurred_at, period_unit, period_start, quantity, `points_awarded` (snapshot), `points_legacy` (migration only), state (`provisional\|confirmed\|revoked\|overridden`), source (`auto_event\|captured\|manual\|migrated`), subject_type, subject_id, evidence_note, evidence json (photo paths, area label, gps), confirmed_by_user_id, confirmed_at, revoked_at, revoke_reason, override audit json (reuse `PointStateService` shape) |
| `rts_task_completion_records` | completion_id, record_type (`TrackedProperty\|Contact\|Property\|Deal…`), record_id — the real CoreX records the completion produced |
| `rts_agent_levels` | agency_id, track, user_id, level_id, status (`active\|awaiting_signoff\|signed_off`), started_at, requested_signoff_at |
| `rts_level_signoffs` | **append-only** (same rule as `rts_settings_audit`): agency_id, user_id, from_level_id, to_level_id, decided_by_user_id, decision (`requested\|approved\|returned`), comment, decided_at |
| `rts_funnel_settings` | (or columns on `rts_settings`) ratio floors, sample sizes, windows, training links — section 9.3 |
| `rts_referrals` | agency_id, user_id, source_contact_id, referred_contact_id nullable, property_id nullable, deal_id nullable, status, created_at — step 13 |

All unique indexes follow BUILD_STANDARD §5a (`withTrashed()->firstOrNew` + `restore()`; never a bare create). `points_awarded` and all money are decimal(12,2). `rts_settings` keeps existing columns; new settings go in section 13.

### 5.5 The neutral default recipe (multi-agency rule — non-negotiable 9)

A new agency gets a seeded **neutral** recipe: the 13 steps with generic labels, the Type-A tasks bound to the events that exist (section 4), **level 1** containing a small set (e.g. "Work N properties from your list" (step 1/3), "Record a result for each call" (step 3), "Complete your profile" (neutral)), levels 2–3 empty shells the agency fills. **No HFC wording, weights, or areas.** The seeding is per-agency at first use (read-time defaults, never writing on read — STANDARDS Rule 17 pattern from `SettingsService::forAgency`), not a global catalogue. Today's calendar mappings and the 41 manual definitions are HFC data and migrate as HFC's own custom tasks (section 7).

### 5.6 Step 13 aftercare — a small build, honestly scoped

Because nothing exists, step 13 ships as: (1) a Type-A task bound to `DealClosed(won)` that creates a **follow-up task for the agent N days after registration** (`N` an agency setting, default a conservative neutral value) using the existing Command Center task system (`command_tasks`); (2) a **referral record** (`rts_referrals`) the agent can log from the contact page ("asked for referral" / "received referral"); referrals created become contacts that enter step 9. No client-for-life programme, anniversaries or testimonial automation unless Johan asks (section 15).

---

## 6. Gap register — steps with no matching CoreX event

### 6.1 Steps (or sub-steps) with **no usable event today**

| Step | Moment | Status | Nearest hook (file:line) |
|---|---|---|---|
| 1 | Drive your area | **GAP — no concept** | Type-B custom task + `matchOrCreate` source `area_drive`; no existing hook |
| 1 | Tracked property captured | event fires, **no points/task binding** | `TrackedPropertyCreated` `TrackedPropertyMatchOrCreateService.php:1659-1664` |
| 2 | Owner linked (deeds/CMA/seller-link paths) | **GAP** | `OwnerContactResolver.php:83-91`; `owner_contact_id` writes `Api/DeedsCaptureController.php:750`, `EntryPointController.php:809`, `MapController.php:697`; `DeedsCaptureController.php:1133` |
| 3 | Outcome recorded | **GAP** | `OutcomeService.php:138` / after-transaction :171 |
| 4 | Evaluation booked / done | **PARTIAL — observers + calendar mapping, no domain event** | `CalendarEventObserver::created` (`ASP:267`); `CalendarEventFeedbackObserver::saved` (`ASP:266`) |
| 5 | Presentation **presented** | **GAP** (status never written) | `PresentationDeliveryService.php:257`; `PublicPresentationController.php:~130` |
| 6 | Mandate signed **on paper** | **GAP** | no hook found; `DocumentUploaded` not raised for property docs |
| 6 | Mandate signed (e-sign) | **PARTIAL** — direct credit, dead `MandateSigned` | `SignatureService.php:4003-4004`; `SignatureEnvelopeFinalized` :4141 |
| 7 | Listing **live on P24/PP** | **GAP** | `Property24SyndicationService.php:441-449` (`p24_activated_at`), `PrivatePropertySyndicationService.php:253-258` (`pp_activated_at`) |
| 8 | Seller live link created / opened | **GAP** | `SellerLinkController.php:385`; `:83-84` |
| 8 | Viewing pack created / sent | **GAP** | `ViewingPackController.php:540`, `:414`; `:355` |
| 9 | Core Match **created** | **GAP** | `ContactMatchObserver` (`ASP:275`) |
| 9 | Lead arrived | event exists, **no points/task binding** | `NewPortalLeadReceived` raise sites listed in step 9 |
| 10 | Viewing **held** | **PARTIAL** — observer, no domain event | `CalendarEventFeedbackObserver::saved` (`viewing_status='viewed'`) |
| 11 | OTP signed — e-sign | **PARTIAL** — signal exists, no OTP listener | `SignatureEnvelopeFinalized` `SignatureService.php:4141` |
| 11 | OTP signed — paper | **GAP** | Dr2 step `date_input` only (`Dr2ConditionCatalog.php:489`) |
| 12 | **Payslip generated** | **GAP (confirmed)** | proxy: `DealCommissionFinalised` (`DealObserver.php:64`, from `mark_paid` `DealSettlementController.php:257-268`) |
| 12 | `deal.commission_finalised` points | seeded, **not wired** | `ASP:646-679` |
| 13 | **Whole step** (aftercare, referrals) | **GAP** | `DealClosed(won)` `DealObserver.php:41` |

### 6.2 New events v2 introduces (all `AbstractDomainEvent`, raised after commit, never throwing to the caller)

`RoadToSoldOutcomeRecorded`, `EvaluationBooked`, `EvaluationCompleted`, `OwnerLinkedToTrackedProperty`, `PresentationPresented`, `MandateSignedOnPaper` (only if a paper-recording path is built — section 15), `PropertyWentLiveOnPortal`, `SellerLinkCreated`, `SellerLinkOpened`, `ViewingPackCreated`, `ContactMatchCreated`, `ViewingHeld`, `OtpSigned` (fan-out from `SignatureEnvelopeFinalized` filtered on document type `otp`), `DealPayslipGenerated` (blocked on the money spec; until then use `DealCommissionFinalised`), `AreaDriveCompleted` (Type B completion). Each is added to `.ai/specs/corex-domain-events-spec.md` §5 in the same change (catalogue = contract; `CLAUDE.md` non-negotiable 9). Fix on the way: wire `MandateSigned` to its real raise site (inside the `completeDocument` afterCommit, `SignatureService.php:3994`) or delete the dead registrations; wire `deal.commission_finalised`; add `presentation.lost` handling or retire the slug.

### 6.3 Domain events actually raised today (so lanes know what exists)

Property: `PropertyCaptured` (`PropertyObserver.php:416`), `PropertyCompliancePassed` (:605), `PropertyPublished` (:620), `PropertySuburbLinked` (6 sites), `SyndicationApprovalRequested/Approved/Rejected/Revoked` (`SyndicationApprovalService.php:330/:419/:446/:487`), `ListingSyndicationChanged` (`WebsiteSyndicationService.php:69/:104/:189`, `PropertyObserver.php:695`), `PropertySoldByThirdParty`. Prospecting: `TrackedPropertyCreated` (:1659), `TrackedPropertyEnriched` (:1777), `TrackedPropertyPromotedToStock` (:2345), `TrackedPropertyAddress*`, `ClaimCreated`/`ClaimReleased` (`ProspectingClaimObserver.php:58/:83`), `ClaimFeedbackRecorded` (`MarketIntelligenceController.php:3846`). Contact: `ContactCreated` (`ContactObserver.php:48`), `ContactContactedByAgent` (`Contact.php:2015`), `ContactLinkedToProperty` (8 sites), `ContactMarkedLostInBuyerPipeline`, `ContactTestimonialSubmitted`. Lead: `NewPortalLeadReceived` (5 sites), seller outreach `PitchSent`/`PitchClicked`/`OptOutRecorded`/`OutreachOutcomeUpdated`. Mandate: `MandateExpired` (`ExpireMandates.php:67`, `PropertyObserver.php:556`), `MandateConverted`. E-sign: `SignatureEnvelopeSent/Finalized/Declined/Cancelled/Expired` (via `announceEnvelope` `SignatureService.php:5022`). Deal: `DealCreated`, `DealStatusChanged`, `DealClosed`, `DealStageAdvanced`, `DealCommissionFinalised`, `DealMoneyLineChanged`, `DealStepCompleted` (DealV2 only, `DealPipelineService.php:329/:372`). Marketing: `MarketingPostPublished` (`PropertyMarketingPostObserver.php:39`), `MarketingPostScheduleFailed`. FICA: `FicaSubmitted/Approved/Rejected`. Presentations: `PresentationGenerated`, `PresentationOutcomeRecorded/Prompted/Locked`. **Defined, never raised:** `MandateSigned`, `DocumentSigned`, `FicaExpired`, `ContactMergedInto`, `ClaimAutoReleased`, `ClaimConvertedFromLock`, `TrackedPropertyMerged`, `CallLogged`, `EmailMessageSent`, `WhatsAppMessageSent`, `EmailDraftOpened`, `WhatsAppDraftOpened` (static `new X(`/`::dispatch` search only; dynamic dispatch not ruled out). **No calendar/viewing domain events exist** — calendar runs through observers `CalendarEventObserver`, `CalendarEventFeedbackObserver`, `CalendarEventInvitationObserver`. Catalogue-specified events with **no class**: `PropertyCreated`, `PropertyUpdated`, `PropertyStatusChanged`, `PropertyArchived`, `ContactUpdated`, `ContactMerged`, `MandateWithdrawn`, `DealRegistered`, `ProspectingListing*`, Wishlist events (`.ai/specs/corex-domain-events-spec.md` §5, :297-372).

---

## 7. Replacing today's daily activity (setup + capture) — and migrating the history

### 7.1 What is being replaced

Screens: capture `agent.daily` (`RW:1926`, `Agent/DailyActivityController` `index` :61, `store` :340-479, view `agent/daily-v2.blade.php`), summaries `agent.daily.summary` / `bm.daily.summary` / `admin.daily.summary` (`RW:1927-1938`), setup `admin.daily-activities.setup` (`RW:1970-1978`, `Admin/DailyActivitySetupController`, tabs manual/auto), the legacy mapping screen `admin.activity-mappings.index` (redirects to setup, `RW:5736-5740`). Menu consequences are in `agency-tracker-rehome.md` §5 and §13.
Engine: `activity_definitions`, `daily_activity_entries`, `activity_definition_calendar_classes`; writers `InstantPointService`, `ProvisionalPointService`, `PointStateService`, `AutoRevokeStaleProvisionalPoints` (hourly, `routes/console.php:166`), `CIAL`, `SignatureService.php:4003`, `DealObserver.php:106-107`; readers (nine sites that each compute `SUM(value × weight)`): `Agent/DailyActivityController.php:120-129`, `AgentPerformanceService.php:165-207`, `CompanyPerformanceService.php:134-148, :633-649, :678-695, :817-833, :911-922`, `Admin/PerformanceController.php:99-107`, the three summary controllers, `CoreX/DashboardController.php:31-41`, `CommandCenter/DashboardController.php:104-114, :287-297`, `Api/CommandCenterApiController.php:112-117`, `AgentScorecardCalculator.php:75-86`, `CommandCenterService.php:737-738`.

### 7.2 QA1 baseline to migrate (read-only counts, 20 Oct 2026; **production counts UNVERIFIED — read Prod before any promotion**)

| Item | Count |
|---|---|
| `activity_definitions` | 204 rows = 3 copies of the same 68 (system NULL agency 68; agency 20 68; agency 205 68). 41 manual + 27 "[Auto]" each. 60 definitions have history; 144 rows have none |
| `daily_activity_entries` | 19,205 — all agency 1, 42 users, 60 distinct definitions, 2026-02-01 → 2026-10-19; **every entry points at a `scope='system'` definition** |
| …by source/state | manual/confirmed 8,340 (Σvalue 14,662); auto_instant/confirmed 10,613; auto_instant/revoked 19; auto_calendar/confirmed 21 (Σvalue 1,800); auto_calendar/provisional 2; auto_calendar/revoked 210 (Σvalue 22,050) |
| Entries with a subject | 10,632 over 9 types: Property 5,829; SellerOutreachSend 1,478; Contact 2,025; TrackedProperty 537; FicaSubmission 311; Deal 194; ProspectingClaim 152; Presentation 104; PropertyMarketingPost 2. 8,573 have none (the manual 8,340 + calendar 233) |
| `activity_definition_calendar_classes` | 31, all agency 1 (4 calendar, 27 instant) |
| Counted points today (confirmed+overridden; sources manual/auto_calendar/auto_instant; Σ value × live weight) | **1,961,094** = manual 954,814 + auto_instant 766,280 + auto_calendar 240,000 |
| Legacy v1 tables | `daily_activities`, `activity_targets`, `activity_point_goals`, `branch_activity_columns`, `activity_columns` — **0 rows** |
| `targets` | 173 rows (134 with `points_target > 0`), all agency 1, 2026-02 → 2026-10 |

### 7.3 Mapping

| Old | New |
|---|---|
| each of the 41 manual definitions (HFC's wording) | an agency **custom task** (`rts_tasks.type='custom'`, `legacy_activity_definition_id` set); points = the definition's weight at migration; recurrence `day`; target from the agency's existing monthly points goal where one exists |
| each of the 27 "[Auto]" definitions / instant slugs | a **core task** bound to the event that already credits it (section 4 and 6.3); the slug → `event_key` map is data; slugs with no firing path stay inactive and are listed in the report |
| calendar mappings (viewing, property_evaluation, listing_presentation, meeting) | core tasks bound to `EvaluationBooked`/`EvaluationCompleted`/`ViewingHeld`; the calendar mapping is retired (no double credit, section 7.5) |
| each `daily_activity_entries` row | one `rts_task_completions` row: `source='migrated'`, same timestamps/user/branch/subject; `points_legacy = value × weight` (what the old engine counted); `points_awarded` as in 7.4 |
| `point_state` | copied 1:1 (provisional/confirmed/revoked/overridden) |
| `targets.points_target` | the user's monthly points target in the Targets area (`agency-tracker-rehome.md` §5.7) — not a completion |
| the three copies of the 68 definitions | only HFC's in-use set migrates as HFC's tasks; agency 20 and 205's unused copies are **not** migrated (they had zero entries); they get the neutral default recipe (5.5) |

### 7.4 The points rule at migration (and the defect)

Verified defect (do not fix by editing history silently): confirmed calendar rows count weight² — QA1 "Take out Buyers" 16 rows × 50 × 50 = 40,000 and "Presentation" 5 rows × 200 × 200 = 200,000 (total 240,000, versus 16×50 + 5×200 = **1,800** if each were one event). Migration:
- `points_legacy` always stores what the old engine counted.
- `points_awarded` for manual and instant rows = `value × weight` at migration (unchanged: manual 954,814, instant 766,280).
- `points_awarded` for **confirmed calendar rows** = one event × weight (QA1: 1,800), i.e. the corrected figure.
- **Johan's go is required before the corrected figure replaces the legacy one on any screen.** Until that go, the migration writes both columns and screens show `points_legacy`-compatible totals; the lane delivers a before/after per-agent table (QA1: before 1,961,094; after 1,722,894). No confirmed points are deleted either way.
- Going forward `points_awarded` is a snapshot at completion: edits to a task's points change the future only.

### 7.5 One completion per real-world event

The old engine can credit one real event through several paths (e.g. an evaluation booked from Road to Sold creates a calendar event, which the calendar observer credits as "Appointments", while an event-bound task also fires). Rule: **exactly one completion per (agent, task, subject)** — enforced by the idempotency key (5.1) and by retiring the calendar-class mapping for any class a core task now covers. The reconciliation report (14.2) lists any duplicates.

### 7.6 Sequencing with the agency-tracker spec

`agency-tracker-rehome.md` step 3 relocates the daily-activity screens and fixes the points rule on the **old** tables. v2 phase 3 replaces the engine. To avoid building the Setup screen into Road to Sold settings twice, the recommendation is: rehome step 3 does only (a) the menu merge #13→#2 and (b) the points-service/defect fix; the Setup screen is **not** re-homed separately — it is replaced by v2's recipe builder. **Johan/conductor to confirm** (section 15). Until v2 phase 3 lands the old screens keep working under their current URLs, which then 301 to the new homes (rule J of the rehome spec).

### 7.7 Keeping the history

Old tables are **never dropped or altered destructively**: they become read-only, marked deprecated, and remain readable for audit and for reports that span the migration date. Historic reports read `rts_task_completions` (migrated rows) and, for cross-checks, the old table. The old routes `agent.daily*`, `bm.daily.summary*`, `admin.daily.summary*`, `admin.daily-activities.setup*` keep their names as redirects (the `prospecting.index` pattern, `RW:8069-8078`), so no internal link breaks.

### 7.8 Permissions/features carried over

`access_daily_activity`, `view_daily_activity`, `manage_targets`, `manage_activity_mappings` (`config/corex-permissions.php:45, :97, :90, :397`) map to the new keys in section 13.2; Role Manager grants are reconciled by `corex:sync-permissions` and listed in the lane's report. Pitfall to avoid: the old manual catalogue is global and edited by `isOwnerRole` (`DailyActivitySetupController.php:161-183, :213-221`) — v2 definitions are agency-owned.

---

## 8. Levels and sign-off

### 8.1 Model

- The agency defines **levels** (default names: Level 1, Level 2, … — agency renames). A new agent starts at Level 1 automatically when first given Road to Sold access (`rts_agent_levels` row created lazily, never on read).
- A level = a set of tasks with targets and points (`rts_level_tasks`) plus the steps enabled at that level. Level 1 uses small tasks (the neutral default: work your list, record every result, load your first stock lead). Higher levels add steps/tasks and raise targets.
- An agent **progresses** by meeting the level's required tasks (`required_for_signoff`) for the agency's required number of periods (settings: e.g. consecutive weeks on target; defaults conservative and editable). When met, the agent's status becomes `awaiting_signoff` and the BM is notified. **Completion of tasks never auto-promotes.** Only a BM sign-off unlocks the next level.
- BM sign-off: the BM reviews the agent's evidence and funnel, may **coach** (add a comment, return to the agent with focus areas) or **approve**. Approval writes a `rts_level_signoffs` row (append-only), moves the agent to the next level, and fires the celebration (section 11).
- An agency can set a level to "no sign-off required" (auto-advance) as an agency setting, default **off**.

### 8.2 Scoping (query layer)

Sign-off screens and APIs use `CallListService::viewableAgents($viewer)` (`CallListService.php:41-60`) for the cohort and `VisibleAgents::canSee` (`VisibleAgents.php:46`) for direct-URL blocking (404, not 403, outside scope). **Known inconsistency to fix in the lane:** `VisibleAgents::for()` returns empty when the scope is null (:24-26) whereas `Scope::level()` falls back to `own` (`Scope.php:17-20`) — pick one rule for the new screens and add a test. A BM can only sign off agents in their branch; admin can sign off any agent in the agency; **nobody can sign off themselves** (hard rule, tested).

### 8.3 Reusable pattern (copy, do not reinvent)

`PropertySyndicationApproval` + `SyndicationApprovalService` (`app/Models/PropertySyndicationApproval.php`; `app/Services/Syndication/SyndicationApprovalService.php`): `request` :303, `cancel` :351, `approve` :374, `reject` :424 (reason mandatory), `revoke` :458; each in a `DB::transaction` with a `lockForUpdate` re-read (:376-382); append-only rows, a cancel/revoke writes its own `withdrawn` row; approver roster from `PerformanceSetting::get(SETTING_APPROVERS, [], $agencyId)` (:39, :155) with owner/admin fallback (:177-181); `canApprove` (:199-232) is the single rule shared by list filters, buttons and write endpoints, branch-narrowed (direct-URL by id blocked). Events `SyndicationApprovalRequested/Revoked/Approved/Rejected` (`app/Events/Property/`), listener `NotifySyndicationApprovalDecision`. The audit-trail model for task points is `PointStateService::override` (`:97-132`: `overridden_by_user_id`, `override_reason`, append-only `override_audit_json`) — **it has no caller anywhere in `app/` today** (no manager-override UI exists), and `PointStateService::confirm` records no approver. v2 adds the approver and a "returned with comment" state.

### 8.4 Training flags and sign-off

The BM sign-off screen shows the agent's funnel and training flags (section 9.3) side by side, so the sign-off decision and the coaching are one screen.

---

## 9. Intelligence — predict, advise, auto-fill, train

### 9.1 Principle

AI/analytics **suggest; a person confirms** (CoreX operating principle 5). Everything below is deterministic first. Optional narrative text may use the AI gateway under the existing spend guard; deterministic output is always the fallback.

### 9.2 The funnel (ratios)

Counts come from `DrilldownService::count($metric, $agentIds, $period, $agencyId, $track)` (`:71`), supported metrics: `calls_made, attempts, conversations, evaluations_booked, dropped, outcome:<key>, worked, added_worked, missed, target, evaluations_done, mandates_signed, listings_live` (`supports()` :34-42, `LOG_METRICS` :32). Sources: calls/conversations/evaluations booked → `rts_contact_outcomes` (`ReportService.php:155-170`); evaluations done → `calendar_events.category='property_evaluation'` with captured `calendar_event_feedback` (`DrilldownService.php:248-258`); mandates signed → `signature_templates.status='completed'` for a mandate template/type by `docuperfect_documents.owner_id` (:279-290); listings live → `properties.listed_date` in window by `agent_id` and `listing_type` by track (:311-319). Sell-side counts: viewings held (feedback `viewing_status='viewed'`), leads (`portal_leads`), OTP signed (`SignatureEnvelopeFinalized` for type `otp`), deals by `accepted_status` (`deals`/`deal_user`), registered (`DealsRegisteredProvider.php:13`).

Ratios v2 defines (all computed by one `FunnelService`, none exist today):
`calls→conversations`, `conversations→evaluations booked`, `evaluations booked→done`, `evaluations done→presentation`, `presentation→mandate`, `mandate→listing live`, `listing live→viewings`, `viewings→OTP`, `OTP→registered`, plus buyer-side `leads→first response within target` (reuse `LeadResponseService::results` `:87`, in-target share), `matches sent→viewings`.
**Population fix (required, not optional):** mandates and evaluations are keyed by document owner / calendar user, outcomes by `rts_contact_outcomes.user_id`. The funnel service attributes every count to **one agent identity (the list owner, `User::ownershipUserId()`, `User.php:1630`)** with a documented rule, and each ratio states its denominator population. A ratio whose numerator and denominator come from different populations is not shown.
Assistant calls count under the agent (v1 §11.1); flag for Johan (section 15).

### 9.3 Training flags

- A flag = "this agent's ratio at step X is well below the benchmark, with enough data". Example from Johan's ruling: many calls, no evaluations → `needs training: evaluation pitch`.
- **Rule (agency setting, neutral default):** flag when (a) the numerator+denominator sample ≥ `flag_min_sample` (default 20 denominator events in the window), and (b) the agent's ratio < `flag_below_pct` of the **agency median ratio** (default 60%) for the same window and track, or below an absolute floor if the agency sets one. Window `flag_window_days` default 30. Using the agency's own median as the default avoids shipping HFC's absolute conversion expectations to another agency (non-negotiable 9). Flags never show for an agent with no data.
- Each flag maps to a **training pointer** the agency chooses per step/ratio: a `TrainingCourse` (`route('training.show', $course)`, `routes/web.php:2808-2823`, `feature:training`, category `sales`), a `TrainingDoc` (`route('training-help.show', $slug)`, `RW:2827-2836`), a document-library item (`documents.library.*`, `RW:7626-7633`) or a guided tour. **There is no skill/ratio→course mapping anywhere today** — it is a new agency setting; per non-negotiable 10a it reaches the Setup Wizard or Johan records the omission.
- The BM sees: agent × ratio grid with flags, drill to the underlying rows (existing drill-down JSON envelope), and one-click "assign training" (creates a task for the agent and a notification) and "add coaching note".
- No competency or skills-matrix model exists (grep empty); v2 does not build one — flags are derived views, not stored scores (a stored "flag history" is an append-only audit only if the BM acts on it).
- Agency admin sees the same grid rolled up by branch.

### 9.4 Prediction, advice, auto-fill

- **Suggested next targets:** deterministic. For each task/step, from the trailing `suggest_window_weeks` (default 8) of completions and funnel: `suggested = max(current target, round(trailing average × (1 + growth)))` with `growth` an agency setting (default 10%), capped by working-day capacity (`WorkdayService`) and by the next level's required target when one exists. The BM/Admin sees the suggestion with the data behind it and **accepts, edits or ignores**; accepting writes through `TargetService::saveWeek` (audited, `$setByUserId`, `TargetService.php:99`) or the new task-target writer. Nothing auto-writes a target.
- **Advice** ("do this next"): the agent's next-action ranking = (1) things due today (follow-ups, booked evaluations needing feedback, expiring mandates — existing `FeedService`: `buyerFollowUps` :44, `leadFollowUps` :96, `expiringMandates` :125), (2) the unfinished required task nearest to its period deadline, (3) the step with the largest gap to target. Pure rules (like `SuggestedActionResolver`, `app/Services/Prospecting/SuggestedActionResolver.php`, rules R1–R10).
- **Income/forecast context:** the existing projection (`BM/PerformanceController.php:40-48`) is a target roll-up, not a forecast, and uses HFC constants (`config('performance.commission_rate', 0.075)`, `company_share 0.50`, neither key defined in `config/performance.php`). v2 does **not** reuse it; any projection reads agency settings and `DealMoney`.
- **Optional narrative:** a short sentence per agent/BM may be generated with `AnthropicGateway::generate(NarrativeRequest)` (`app/Services/AI/AnthropicGateway.php:62`) following the `ThisWeekTileBuilder`/`StrategicBriefService` pattern (deterministic facts, model writes the sentence, templated fallback `buildTemplatedFallback` `StrategicBriefService.php:229`, 12h cache). Every call is guarded by `AiSpendGuard::assertAllowed($agencyId)` (`app/Services/AI/AiSpendGuard.php`) and recorded by `AiUsageRecorder::record` (`AiUsageRecorder.php:50`), marked with `resources/views/components/ai-badge.blade.php`. Spec `.ai/specs/at456-ai-tools-scheduler-and-spend-safety.md` §A, `.ai/specs/ai-cost-ledger.md`. Ellie's `my_performance` tool (`EllieToolkit.php:192`, impl :559) does not read Road to Sold data; extending it is optional and out of scope here.
- **The more it's used, the better the data:** every number above is computed on the agency's own history; with little data the screens say "not enough data yet" rather than guess.

### 9.5 BM/admin target-setting loop

Road to Sold stats → suggested targets → BM/Admin edits → targets saved (Targets area, `agency-tracker-rehome.md` §5.7) → agent sees new targets next period. The Targets screen's "save only the columns submitted" fix (rehome §5.7) is a prerequisite for this loop.

---

## 10. Views, screens and states

### 10.1 Agent view — "do this now / do this next"

Home: `GET /road-to-sold` (same route `road-to-sold.index`). Layout (top to bottom): (1) **Next action** (one card: the single most important thing now, with the button that does it); (2) **Today's list** (existing daily call list, `RoadToSoldController@index`, unchanged behaviour); (3) **Recipe progress**: the agent's current level, each step with its tasks, progress vs target for the current period, and the state colour (section 11); (4) the existing **summary bar** widened to the funnel steps; (5) feeds (buyer follow-ups, lead follow-ups, expiring mandates — existing `FeedService`); (6) level status ("2 of 3 weeks on target — your BM signs off at level end"). Capture entry points: type-B task buttons open the capture form (section 5.3). The personal activity/points history lives on My Portal (`agency-tracker-rehome.md` §5.2) and links here.

### 10.2 BM view

Team funnel (agent × step, with counts and ratios), training flags, sign-off queue, "tasks needing review" (returned/with evidence), target suggestions (9.4). Scope: branch (own agents) via `viewableAgents`; admin sees the agency. Drill-down on every figure (existing envelope).

### 10.3 Agency view

Recipe/level builder (steps, tasks, levels, targets, points, training pointers), targets and budgets (rehome Targets area), the interactive report (existing, extended with the funnel), agency funnel and flags by branch. Settings under Road to Sold settings (section 13).

### 10.4 Standard for every list (CLAUDE.md non-negotiable 8; BUILD_STANDARD §1a)

Each list ships with search (named fields), sort (stated default), filters (status + date range minimum), pagination, a real empty state, Create/Read/Update/**Archive/Restore** (soft only), and own/branch/agency scoping **enforced in the query** (direct URL by id blocked). v1's screen-text rule applies (data and controls only, one short line per setting). State per list:

| List | Search | Sort (default) | Filters | Scope |
|---|---|---|---|---|
| Recipe steps (settings) | label | position asc | track, enabled | agency |
| Tasks (settings) | label, step | step, sort order | type core/custom, step, active/archived, recurrence | agency |
| Levels & level tasks (settings) | name, task | number asc | active/archived | agency |
| Task targets per user (settings) | agent, task | agent A→Z | branch, level, overridden-only | branch/agency by `manage_targets` breadth |
| My task completions (agent) | task, note | occurred desc | task, step, state, date range | own |
| Team completions / review queue (BM) | agent, task, note | occurred desc | agent, step, state (provisional/returned), date range | branch (BM) / agency (admin) |
| Sign-off queue (BM) | agent | requested oldest first | status, level, branch | branch / agency |
| Funnel grid (BM/agency) | agent | worst flag first | branch, step, window | branch / agency |
| Training flags | agent, step | severity | step, acknowledged/open | branch / agency |
| Referrals (step 13) | contact, deal | created desc | status, date range | own/branch/agency |

Archive and restore are available on every settings entity (tasks, levels, steps, level-tasks, overrides); completions are not editable by the agent after confirmation except through the BM override path.

### 10.5 Input space (prevent or absorb — BUILD_STANDARD §2/§3)

| Input | Rule |
|---|---|
| Task target blank | Absorb: use the level default; `0` is valid ("nothing required this period") |
| Target/points negative, non-numeric, absurdly large | Prevent with a plain message (range stated per field) |
| Recurrence unit missing/unknown | Prevent: pick from `hour/day/week/month` |
| Type-A task with unknown `event_key` | Prevent: pick from the catalogue of firing events (section 6.3 + 6.2); an event with no raise site is not selectable |
| Capture form: zero records submitted but quantity > 0 | Prevent when `capture_kind ≠ none` |
| Duplicate property/contact in a drive | Absorb: `matchOrCreate` matches and enriches; the completion lists it once |
| Same real event completes a task twice | Absorb: idempotent (5.1) |
| Backdated completion | Prevent beyond the agency's back-date limit (setting; reuse the calendar `back_date_limit_hours` concept) |
| Assistant records on the agent's behalf | Absorb: completion belongs to the list owner; `recorded_by_user_id` = assistant (v1 §11.1) |
| Level with no tasks / agent with no level | Absorb: show "no tasks set for this level yet" / create Level 1 lazily on first open (never on read) |
| BM signs off self / out-of-scope agent | Prevent / 404 |
| Task points edited after completions exist | Absorb: past `points_awarded` untouched |
| Agent has no working days | Valid; `WorkdayService::nextWorkday` returns null — screens handle it (v1 §6) |
| Second-agency test | A second agency sees none of the first agency's tasks, levels, recipes, definitions or wording |

Test matrix per piece: happy path with all fields; each optional field omitted; the lazy shortcut (open, tick one thing, save); one malformed value per validated field; create → archive → recreate (§5a); deleted-related-record (agent or property soft-deleted); idempotent re-save; cross-agency 404 and out-of-branch 404 by direct URL; the second-agency test.

---

## 11. Motivation — states and components (colours deferred)

### 11.1 The five states

| State | Meaning (per task / step / level, vs period target) | Trigger |
|---|---|---|
| **Behind** | below the agency's red line for time elapsed | `statusForPct` red (< `summary_red_below_pct`, default 70) |
| **Amber / needs a push** | between red and green lines | `statusForPct` amber |
| **On track** | at or above the green line (default 90%) but below target | `statusForPct` green (`SettingsService.php:329`) |
| **Ahead** | at or above 105% of the expected-by-now figure | new; mirrors the existing company-pace rule (Achieved/Ahead ≥ 105%/On pace ≥ 95%/Behind, `CompanyPerformanceService.php:327-340`; repeated :673, :805, :906) |
| **Achieved** | period target met | completions ≥ target |
| **Celebration** | a moment worth marking | below |

(The spec lists the ruling's "behind / on track / ahead / achieved / celebration"; amber is the existing in-between of v1's three-band bar. The lane may fold amber into "behind" or "on track" visually — a colour-round decision with Johan.)

### 11.2 Celebration moments (events that trigger them)

1. A **period target hit** (task, step or the whole recipe for the day/week/month).
2. A **level signed off** (`rts_level_signoffs` approved).
3. A **mandate won** (precedent: `WonMandateNotification`, `PresentationOutcomeService.php:251`; or `mandate.signed`).
4. A **DR2 deal registered** — `DealClosed` with outcome won (`DealObserver.php:41`; "registered" detector `DealRegisteredForOutcomeObserver.php:24`). The money shown, if any, comes from `DealMoney`/`deal_money_lines`, never `commission_ledger`.
5. A **streak** milestone (days/weeks on target). A 30-day consecutive-points-day streak is computed inline in Blade today (`resources/views/agent/dashboard.blade.php:407-413`) — v2 moves it to a service.
Celebration is for the agent (in-page and notification) and also reaches the BM for items 2–4 (precedent: `WonMandateNotification` recipients are the BM and principal).

### 11.3 Components to use (existing) and gaps

| Need | Existing | Gap / note |
|---|---|---|
| Progress bar | `.ds-progress-track` + `.ds-bar-navy\|amber\|crimson\|green` (`resources/css/nexus.css:730-733`; UI_DESIGN_SYSTEM §3.13) | no progress **ring**; add one component (SVG/conic) |
| Status badge | `.ds-badge` + `-success/-warning/-danger/-info/-default` (`nexus.css:785-789`, §3.4); `x-pill` (`resources/views/components/pill.blade.php`) | |
| Summary bar tile | `resources/views/road-to-sold/_summary-bar.blade.php:6-13` (left-border colour from `--ds-green/--ds-amber/--ds-red`; wording On track / Slightly behind / Behind) | uses `--ds-red`, defined at `resources/css/corex.css:1375`, while the design spec lists `--ds-crimson` as the danger token — **reconcile in the colour round** |
| Delta text | `x-performance-delta` (`resources/views/components/performance-delta.blade.php`; classes `report-delta-good\|bad\|neutral`, defined for RTS at `road-to-sold/report/index.blade.php:147`) | colour follows the metric's own direction of good, never the raw sign |
| KPI tile | `x-corex-kpi-card` | |
| Short success toast | `window.showToast(message, type, duration, url)` (`toast-notifications.blade.php:120`) — the only success style that exists | use for in-page "target hit" |
| Persistent alert toast + bell | `components/alert-popups.blade.php`, `components/alert-bell.blade.php`, feed `Api/V1/AlertFeedController.php`, inbox `AlertInboxController` | **`success` severity is not styled** (only `warning`/`critical`, `alert-popups.blade.php:177`) — add it or use the short toast |
| Notification pipe | `NotificationDispatcher::send/fire` (`app/Services/CommandCenter/NotificationDispatcher.php:61, :76`); `eventKey` must exist in `notification_event_types` (registration migration precedent: `2026_10_23_000600_register_owner_query_notification.php`); preferences `NotificationPreferenceService` | **register one event key per celebration kind**, and use a distinct subject per achievement so the 360-minute per-type-per-subject cooldown does not swallow repeats (memory note; figure unverified in code) |
| Confetti/badge/achievement | none | new, small; honour a user preference to switch celebration animation off |
| Streak | inline Blade only | move to a service |

### 11.4 Colours

Colours are **not** specced here. The lane builds the five states against the design-system **semantic tokens** (`UI_DESIGN_SYSTEM.md` §1.5: `--ds-green` #059669, `--ds-amber` #f59e0b, `--ds-orange` #ea580c, `--ds-crimson` #c41e3a, `--ds-navy` #0b2a4a; "never use red for neutral scores"), with every colour behind a CSS variable so the later mockup round with Johan changes values, not markup. Acceptance for that round is a clickable mockup with 2–3 palette options, not code.

---

## 12. Road to Rented — same engine, rental steps

### 12.1 What exists

- The `track` dimension is on every table (`rts_settings`, `rts_outcome_definitions`, `rts_list_items`, `rts_contact_outcomes`, `rts_agent_areas`, `rts_weekday_targets`; migrations `2026_10_29_*`) and every service takes `$track`. `Track::fromRequest` (`Track.php:18-22`), `Support/Routing.php:17-20` (route-name root `road-to-rented` → rentals), remountable `routes/road-to-sold-settings.php`.
- Property side: `Property::isRental()` (`Property.php:2642`, accepts `rental/to_let/to-let/lease`, case-insensitive), write-normalised by `normaliseListingType` (`:2606-2615`, `:2624`); `listing_type` canon `LISTING_TYPES = ['sale','rental']` (:2587). Existing selection/listing queries filter `listing_type = 'rental'` by **exact match** (`DrilldownService.php:316`, `OwnStockSelection.php:26`, `MicPoolSelection.php:26`) — legacy rows with other spellings are **UNVERIFIED**; the lane must use the normalised rule.

### 12.2 What is missing (sales-only today — each is a line item)

No `road-to-rented` route group, feature key, permission key, sidebar entry or wizard copy exists. `RtsSetting::selectionCatalogue($track)` (:152) and `summaryStepCatalogue($track)` (:173) ignore `$track` (sales labels only; docblock :145-146 defers rentals). `HotPocketService::rank` returns `[]` for non-sales (`:39`). The three feed blocks are sales-only (`RoadToSoldController.php:63-64`). `evaluationsDoneQuery` (`DrilldownService.php:248`) and `mandatesQuery` (:279) carry no track filter, so a rentals bar would show sales evaluations/mandates. `listings_live` should-be reads `targets.listings_target` (a sales target; `SummaryBarService.php:79-91`). Seeded outcomes are sales wording ("Mandated with another agency - sole mandate", `OutcomeDefinitionService.php:24-45`). The outcome-settings routes are mounted inside the sales group, not in the remountable file (`RW:6415-6428`). The page group has no `{track}` parameter (rentals today would be `?track=rentals` on the sales URL, `RW:6402-6428`).

### 12.3 The rental recipe (rental steps swapped in)

| # | Rental step | Screen | Auto-detect event | Status |
|---|---|---|---|---|
| R1 | Find landlords / to-let stock leads | Road to Rented list (same builder, track `rentals`); selections need rental catalogues (to-let on market, long on market, price drops for rentals, my expired to-let mandates, agency tenants/applicants sourcing) | list item worked (no event); claim taken (`ClaimCreated`) | needs rental selection catalogue (catalogue `RtsSetting::selectionCatalogue` must honour track) |
| R2 | Find owner / landlord | same as step 2 | same GAP as step 2 | GAP (shared) |
| R3 | Contact + outcome | `OutcomeService::record` with track rentals and rental outcome set | same GAP as step 3 | GAP (shared); seed rental outcomes |
| R4 | Rental appraisal booked (calendar; never "valuation") | calendar | same partial as step 4 (needs a rental appraisal event class; `property_evaluation` is the sales class) | PARTIAL — verify whether a rental class exists (**UNVERIFIED**) |
| R5 | Rental mandate signed (e-sign) | DocuPerfect, document type for a rental mandate (**UNVERIFIED which slug**) | `SignatureEnvelopeFinalized` filtered by type | PARTIAL (shared with step 6) |
| R6 | **To-let listing live** | Properties, rental syndication | **GAP** — `listed_date` stamp only (`Property.php:~592-595`); `PropertyPublished` is website-only (`PropertyObserver.php:620`) | GAP (same hook as step 7: `p24_activated_at`/`pp_activated_at`) |
| R7 | Marketing the to-let | Ad Manager / seller (landlord) link / viewing pack | `MarketingPostPublished`; link/pack GAPs as steps 8b/8c | shared |
| R8 | Applicants / leads | portal leads, tenant applicants | `NewPortalLeadReceived`; response time | shared (buyer pipeline is the *sales* pipeline; rentals pipeline `corex.rentals.pipeline.index`, `buyer_pipeline.view`, `RW:6216-6218`) |
| R9 | Viewings + feedback | calendar viewing | same PARTIAL as step 10 | shared |
| R10 | **Application received** | Rental applications (`RentalApplication`, statuses `draft, sent, in_progress, returned, reopened, under_assessment, approved, declined, withdrawn`, `RentalApplication.php:27`) | **EXISTS:** `RentalApplicationSubmitted` (`RentalApplicationSigningController.php:527`) | EXISTS |
| R11 | **Application approved** | `RentalApplicationAuthorisationController` | **EXISTS:** `RentalApplicationApproved` (`:560`); also Declined/Withdrawn/Reopened/ReopenCancelled in `app/Events/RentalApplication/`; status trail `RentalApplicationStatusHistory` | EXISTS |
| R12 | **Lease signed** | Leases (`Lease`, `signing_status` not_sent…signed_on_paper, `Lease.php:63-72`; `rental_application_id` link :103) | **EXISTS:** `LeaseAgreementSigned` (`LeaseSigningStateService.php:451`, agent's final approval); `LeaseAgreementFailed` | EXISTS |
| R13 | **Inspections** (in / routine / out) | `RentalInspection` (types in, out, ad_hoc Routine, interim; statuses draft, in_progress, awaiting_signature, completed, cancelled, `RentalInspection.php:32-38, :91-95`) | **GAP** — `RentalInspectionCompletionObserver` fires for Out inspections only; no `InspectionCompleted` domain event; a completed In inspection has no hook (`ASP:285`) | GAP |
| R14 | **Renewals** | `LeaseRenewalService` (`createRenewalTerm` :40, `activateRenewalTerm` :105, `recordMonthToMonth` :179, `recordNotice` :259; chain via `previous_lease_id` `Lease.php:105`; specs `rental-renewals.md`, `rental-notices.md`) | **GAP** — audit rows only (`LeaseEvent` `TYPE_RENEWAL_DRAFT_CREATED`/`…ACTIVATED`, `LeaseTermAdvanceService.php:66-67, :168-169`); no domain event for "renewal agreed" | GAP |
| R15 | Aftercare / referrals | as step 13 | GAP | GAP |

Rental deals/fees (a rental equivalent of step 12): **UNVERIFIED** — no separate rental-deal or fee model was located on this path.

### 12.4 How it reuses the engine

Same tables (`track='rentals'`), same task engine, levels, sign-off, funnel service (ratio set swapped: calls→conversations→appraisals→mandates→to-let live→applications→approved→leases→renewals), same celebration, same views (the page is mounted as `road-to-rented.*`). New: a `road-to-rented` feature key (`depends_on` the rentals feature and `road-to-sold`'s engine — a standalone feature row), permission keys (`access_road_to_rented`, mirror of sales), sidebar link inside the Rentals group, wizard step, seeded rental catalogues (selections, outcomes, steps). The lane makes every sales-only spot in 12.2 track-aware **before** the rentals page is switched on, with a test that a rentals bar never counts sales evaluations/mandates.

---

## 13. Settings, permissions, features, wizard

### 13.1 Settings (all agency settings with a default, under Road to Sold settings; v1 settings unchanged)

New tabs in the settings hub (`road-to-sold.settings.index?tab=…`, closure file `routes/road-to-sold-settings.php`, so Road to Rented mounts the same screens): **Recipe** (steps, tasks, levels), **Targets & Budgets** (adopted from the rehome spec §4.3, §5.7, plus per-task targets), **Training & Flags**, **Celebration**. Existing tabs (General, Areas, Targets/weekday, Outcomes) stay.

| Setting | Default | Notes |
|---|---|---|
| Step on/off, label, order (13 + 15 rental) | all on, neutral labels | per track |
| Level names and count | 3 empty-shell levels, Level 1 seeded | agency edits |
| Task catalogue (type A seeded neutral, type B empty) | neutral | per agency |
| Task target / points / period per level | seeded small for level 1 | |
| `levels_signoff_required` | on | off = auto-advance |
| `level_periods_required` | agency-neutral conservative value | consecutive periods on target to request sign-off |
| `flag_min_sample` / `flag_below_pct` / `flag_window_days` | 20 / 60% of agency median / 30 | 9.3 |
| Training pointer per step/ratio | none | course / doc / library / tour |
| `suggest_window_weeks` / `suggest_growth_pct` | 8 / 10% | 9.4 |
| `celebrate_*` toggles and which moments notify the BM | all on | 11.2 |
| `drive_default_area_label` and capture form options | neutral | |
| `back_date_limit_hours` for task capture | neutral | reuse concept |
| `aftercare_followup_days` | neutral conservative | 5.6 |
| `portal_show_agency_commission_totals` | off | rehome spec §4.2 |
| Red/green lines | 90 / 70 | existing |

Every threshold lives in the settings row (`rts_settings` or a sibling `rts_recipe_settings`), is read through `SettingsService`-style typed getters with a 15-second memo (v1 §2.1), is audited in `rts_settings_audit`-style append-only history, and returns defaults without writing when `agency_id <= 0` (STANDARDS Rule 17).

### 13.2 Permissions (`config/corex-permissions.php`, section `road-to-sold`, module `road_to_sold`)

Existing: `access_road_to_sold`, `road_to_sold.view` (data breadth: agent=own, BM=branch, admin=all), `.manage_settings`, `.manage_areas`, `.manage_targets`. New (proposed): `road_to_sold.manage_recipe` (tasks, steps, levels), `road_to_sold.review_completions` (BM reviews/returns captured tasks), `road_to_sold.signoff_levels` (BM sign-off; branch/agency by breadth), `road_to_sold.view_funnel` (team funnel and flags; breadth by `road_to_sold.view`), `access_road_to_rented` (+ mirror keys). Retired (replaced): `access_daily_activity`, `view_daily_activity`, `manage_activity_mappings` map to `access_road_to_sold`/`manage_recipe`; `manage_targets` stays (it already gates the Targets area). Defaults: BM gets review/signoff/view_funnel, admin gets all, agent gets view own. Reconciled by `corex:sync-permissions` (the lane reports which roles got which keys). Role-default seeding only affects fresh installs (config header).

### 13.3 Features

Existing `road-to-sold` (`config/corex-features.php:214-224`). New: `road-to-rented` (own key), plus an optional `rts-recipe` sub-feature if Johan wants recipe/levels switchable independently of the call list. Sidebar links are wrapped in `@feature(...)` per the rule at `config/corex-features.php:44-48`; guards `SidebarNavAuditor`, `FeatureNavGuardCoverageTest`, `CrossLinkFeatureWrapTest`.

### 13.4 Setup Wizard (non-negotiable 10a)

Every new setting above reaches `config/agency-onboarding-copy.php` (step `road_to_sold`, :1656) with `explain` + `affects` and its canonical saver in the step's `savers` (guard every boolean write with `$request->has()` — `agency-onboarding-setup.md` §6.1). Johan's call per setting on what stays out of the wizard; the default proposal, following v1 §8: **in the wizard** — level count/names, sign-off on/off, flag on/off and the minimum sample; **out of the wizard (settings page only; recorded in `agency-onboarding-setup.md` §5.1)** — ratio percentages, suggestion window/growth, training pointers, celebration toggles, per-task points. Per-person data (levels per agent, per-user overrides) are records, not settings.

---

## 14. Build order, acceptance, reconciliation

Rule for every phase: QA1 only, nothing to Staging (CLAUDE.md #5); one lane at a time; route names kept and old URLs 301'd; sidebar mapping tests and the nav fixture updated in the same change; tests via `scripts/lane-test.sh`, one file at a time; migrations applied by the deploy (STANDARDS −1g); schema snapshot regenerated and DEFINER-stripped per CLAUDE.md 12a. Branch/landing rules per STANDARDS −1l/−1m (the conductor decides which landing rule applies — see Appendix A §8 note).

| Phase | Content | Acceptance |
|---|---|---|
| 0 | **Missing events & defect fixes** (section 6.2): raise the new events at the hooks listed; wire or retire dead registrations; wire `deal.commission_finalised`; fix the wizard-draft `property.captured` credit placement (or document it); fix the `VisibleAgents`/`Scope::level` disagreement; add events to the catalogue | each event fires exactly once per real occurrence (tinker proof per event in a rolled-back transaction); `domain_event_log` shows it; catalogue updated |
| 1 | **Engine:** tables (5.4), `TaskCompletionService`, generic `DomainEvent` listener, idempotency, neutral default recipe, permissions/features, settings tabs Recipe | Type-A task completes from a real event once; duplicate event does not double-complete; second agency sees only its own recipe; lazily created, never on read |
| 2 | **Agent view** (10.1): next action, recipe progress, capture form (Type B) with real `matchOrCreate`/contact creation; states (11.1) with placeholder colours | a drive capture creates tracked properties + contacts that appear in the agent's step 1–3 funnel next build; assistant records under the list owner |
| 3 | **Daily-activity replacement + migration** (7): migrate with `points_legacy`/`points_awarded`, retire old screens (redirects), keep old tables read-only. **Johan's go before corrected points show** | counts reconcile exactly: 19,205 entries → 19,205 completions (QA1); legacy total 1,961,094 reproduced from `points_legacy`; corrected total 1,722,894 shown only after the go; per-agent before/after table delivered; 0 rows lost; old URLs 301 |
| 4 | **Levels & sign-off** (8): rts_levels etc., BM sign-off screen, notifications | nobody signs off themselves; BM only in branch; append-only history; level 2 unlocks only after sign-off |
| 5 | **Funnel, flags, suggestions** (9) | ratios match `DrilldownService::count` for the same window; populations documented; sample-size gate respected; suggestion accept writes via audited saver; nothing auto-writes |
| 6 | **BM and agency views + reports** (10.2, 10.3); report extended with funnel | scoping proven for agent/BM/admin/second-agency admin; drill-down on every figure; print/PDF/XLSX |
| 7 | **Motivation:** celebration events + notifications + in-page; colour mockup round with Johan | cooldown trap tested (two achievements in one day both notify); animation can be switched off |
| 8 | **Road to Rented** (12): track-aware fixes first, rental catalogues, mount, feature/permission/sidebar/wizard | a rentals bar never counts sales rows (test); R10–R12 tick from their existing events |
| 9 | **Step 13 aftercare + referrals** (5.6) | follow-up task created N days after a won deal; referral record creates a contact entering step 9 |

### 14.1 Dependencies on the agency-tracker spec

Phase 3 follows `agency-tracker-rehome.md` step 3 (menu merge + points service) and absorbs its Setup screen (7.6). Phase 4–5 targets writes follow rehome step 4 (Targets save fix). The My Portal Targets & Budget block (rehome §4) and the Overview "where am I vs where I should be" (rehome §6) read the targets/levels defined here.

### 14.2 Reconciliation report (ships with phase 1)

A scheduled and on-demand report listing: completions with no matching source record, source events with no completion (Type-A mapping broken), duplicate completions per (agent, task, subject), and tasks whose `event_key` has no raise site. This is how "the more it's used, the better the data" stays honest.

---

## 15. Open points for Johan (business questions only — nothing here is a technical choice)

1. **Expired competitor listings:** step 1 lists "expired". Today only the agent's *own* expired mandates exist as a list. Do you want a new list of *other agencies'* expired listings to pitch?
2. **Paper documents:** a signed paper mandate or OTP cannot be detected by CoreX today. Should the agent have to upload the signed paper copy (which then ticks the step), or is "the e-sign copy only" enough?
3. **Payslip step:** CoreX does not record "payslip generated"; only "commission paid". Is "commission paid" the moment this step is ticked and the deal leaves the pipeline, until the commission/payslip money work is done? (Commission engine stays out of this spec.)
4. **Assistants:** an assistant's calls count under the agent in the funnel and in training flags. Is that what you want?
5. **Sequence with the Agency Tracker work:** do the interim daily-activity move first (menu merge + points fix), then replace — or skip the interim and go straight to the new engine?
6. **Old points:** when the calendar double-counting is corrected, an agent's history drops (QA1 example: 1,961,094 → 1,722,894 across all agents). Do you approve changing displayed history, or keep the old totals for past months and apply the corrected rule from the cut-over date?
7. **Aftercare:** how much for step 13 now — just a follow-up reminder N days after registration plus a referral log, or a fuller client-for-life programme later?
8. **Level sign-off for everyone:** should owners/principals also be signed off, or only agents and candidates?

---

## 16. Not verified in code (be honest about it)

- **Production** row counts, grants and settings — all counts here are QA1 (a test copy). Re-read Prod before any promotion.
- Which `document_types` slug a **rental mandate** uses; whether a **rental appraisal** calendar class exists; whether a rental-deal/fee model exists (section 12.3).
- Whether `handleDealStageAdvanced` filters the same-status double-raise (step 12); whether the 360-minute alert cooldown figure still holds.
- Whether the `message` channel of `ContactContactedByAgent` fires at send time or only on archive ingestion (step 9).
- Legacy `properties.listing_type` spellings beyond the normalised set (12.1).
- That a paper-mandate recording path does not exist (step 6).
- Why all 27 "[Auto]" definitions are `is_enabled=1` on QA1 though the seeder creates them disabled (7.2) — not rendered.
- Whether `ListBuilderService`'s overnight build and the summary bar behave identically for assistants after v2's changes (v1 §11.1 is the rule; not re-proved).
- Domain events dispatched dynamically (static `new X(`/`::dispatch` search only).
- `BuyersWonProvider` registration in `MetricProviderRegistry` (`MetricProviderRegistry.php:28-43` lists 13 providers without it).
- Whether `resources/views/layouts/navigation.blade.php` and `dashboard.blade.php` (legacy) are still rendered (from the rehome spec).

---

## 17. Parked (record only)

An AI scout that watches South African property and law changes and notifies agencies (Johan's idea K in the Agency Tracker rulings of 20 Oct 2026). Not part of Road to Sold; own spec when picked up.

---

---

# APPENDIX A — Superseded v1 (AT-454), kept unedited

> Everything below is the previous `road-to-sold.md` (spec v1, 13–14 Oct 2026), copied verbatim from `origin/QA1` at `8ee4fb502`. Where it conflicts with the sections above, the sections above win (see section 2 for exactly what still stands). Its Section 1 is Johan's original business rules (approved 13 Oct 2026 07:19) and its section numbering (§1–§13) is the numbering cited throughout this v2 as "v1 §n". Do not renumber or edit it.

---

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
- **Agency pool - REPLACED by Johan's ruling of 13 Oct 2026 16:03 (verbatim below, built as section 13):** "suburbs and towns can be auto created from properties that the agency have - so all stock suburbs and towns, and MIC suburb and towns. That way we are displaying what they have in stock and is updating from P24 and PP." The agency's working areas are DERIVED from its own stock and its Market Intelligence data, never hand-set in Prospecting Setup. (The earlier rule, "agency pool = existing Prospecting Setup suburbs (towns / p24_suburbs)", is void.) Suburbs are still assigned per agent (admin/BM screen: agents x suburbs); several agents may share a suburb.
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
- Seeded outcomes: No answer (attempt, +7 days); Spoke - evaluation booked (conversation, calendar appointment, leaves call list); Spoke - call back (conversation, agent-picked date); Spoke - not interested (conversation, agent-picked date); Listed with another agency, open mandate (conversation, stays callable, agent-picked date); Mandated with another agency, sole mandate (conversation, expiry date if known else agent-picked); Wrong number (attempt, tagged, moves to "find the right contact" list); Do not contact (conversation, never again for any agent, also sets the contact's call opt-out).
- "Evaluation booked" offered on every conversation outcome. Attempts and conversations counted separately.
- N no-answers in a row (default 3): drops off the agent's list, shows on the manager's report. "No answer" filter for when the owner phones back. Wrong-number and no-owner properties grouped as their own workable lists.
- Every outcome is history on the property and the owner contact. Conversation outcomes move the contact's last_contacted; attempts do not.
- HOLD: capturing an outcome gives that agent the property for 30 days (agency setting); not suggested to other agents; each new outcome restarts it; lapses -> free.

SUMMARY BAR
- Per step: should-be-by-today (this month) vs actual. Steps (each switchable): Calls made, Conversations, Evaluations booked, Evaluations done, Mandates signed, Listings live. Social shares OFF - do not build. Financial line later.
- Green on/ahead, amber under 90%, red under 70% (agency setting). Click a step -> rows behind it.

REPORT
- One interactive report reusing PeriodResolver, Period, PeriodComparison, _period-selector, x-performance-delta, the drill-down modal and its JSON envelope. Add "last 30 days". Periods: today, last 7 days, this month, last 30 days, custom, compare. Per agent: target, worked, attempts, conversations, each outcome, evaluations booked, extra over target, missed/rolled, dropped after no-answers. Drill-down on every figure. Print, PDF, XLSX.

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

**Memo (audit D):** the instance is reused for `SettingsService::MEMO_SECONDS` (15 s) only — never for the life of a process. The nightly build is one long process and must see a setting saved while it runs. The memo is also dropped at the start of every queued job and artisan command (`AppServiceProvider`), by every save (`forget()`), and `forAgency($id, $track, fresh: true)` re-reads on demand. A long loop that wants certainty calls `SettingsService::forget()` itself at the start of each agency.

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
| `holidayCountry()` | 2-letter code present in `public_holidays` | `'ZA'` | `WorkdayService` (audit C: was hard-coded) |
| `refreshPortals()` | subset of `['p24','pp']`, at least one | `['p24','pp']` | lane 1 Areas screens (the "portals we capture from" setting; column `refresh_portals`, migration `2026_10_29_100400`) |
| `buyerMatchTiers()` / `activeBuyerStates()` / `takenStatuses()` / `onMarketStatuses()` / `addToDayMax()` / `scanPageSize()` / `scanMaxPages()` / `hotPocketWeightBuyers()` / `…Leads()` / `…Sales()` | lists / ints | see §9.11 | lane 2 (audit G) |

Stable keys (do not rename — they are stored):
- **Selection keys, default order:** `my_buyers_sourcing`, `agency_buyers_sourcing`, `hot_pockets`, `price_drops`, `new_on_market`, `long_on_market`, `owner_known`, `my_expired_stock`, `my_stock_not_selling`.
- **Summary step keys:** `calls_made`, `conversations`, `evaluations_booked`, `evaluations_done`, `mandates_signed`, `listings_live`. (Social shares: not built. Financial line: later.)

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
| `poolFor(int $agencyId): Collection` | **Superseded - see section 13.** The pool is `poolQuery()` (derived from stock + MIC), not Prospecting Setup. |
| `suburbIdsFor(User $u, string $track='sales'): int[]` | The user's assigned `p24_suburb_id`s (non-archived), `[]` if none. |
| `suburbNamesNormalisedFor(User $u, string $track='sales'): string[]` | Same, as `suburb_normalised` strings, for matching `prospecting_listings.suburb` (free text) and `tracked_properties.suburb_normalised`. Lane 2 matches `tracked_properties` on `p24_suburb_id` where present, else on the normalised name. |
| `hasAreas(User $u, string $track='sales'): bool` | |
| `agentsWithoutAreas(int $agencyId, string $track='sales'): Collection` | Active selling users in the agency with zero areas (drives the settings warning when lock is ON). |
| `lastCaptured(int $agencyId, ?array $suburbIds=null, string $track='sales'): array<int,array{p24:?Carbon,pp:?Carbon}>` | **Rebuilt.** Reads `derived()` (no second scan); null = every pool suburb. Labelled "last captured" in the UI. |
| `suburbNamesLowerTrimFor(User, track): string[]` | **Built.** Suburb names as `lower(trim())` — the form MIC joins against `prospecting_listings.suburb`. |
| `portalsNeedingRefresh(array $captured, int $warnDays, ?array $portals = null): string[]` | **Built (audit A).** `['p24']`, `['pp']`, both or none: each portal prompts on its own — a fresh P24 capture does not hide a missing Private Property one. Only the portals the agency captures from (`SettingsService::refreshPortals()`, default both) are checked; an agency without Private Property is never asked to refresh it, and the Areas screens drop that portal's column and wording. |
| `needsRefresh(array $captured, int $warnDays): bool` | **Built.** True when at least one portal needs a refresh (never = true). |
| `suburbMatchesFor(User, track): array` / `AreaService::matchSql(array $matches, 'tp', 'pl'): [sql, bindings]` | **Built (audit B).** The agent's areas with city/town and `unique_in_pool`, and the ONE area-membership test (§5.7). Lane 2's `AreaMatchProvider` hands them to the selections. `suburbNamesNormalisedFor` / `suburbNamesLowerTrimFor` remain for stub providers but production matching no longer uses bare names. |
| `poolQuery(int $agencyId, string $track='sales')` / `idsInPool(int $agencyId, int[] $ids, string $track)` / `towns(int $agencyId, string $track)` / `derived(int $agencyId)` / `candidateIds()` / `hiddenSets()` / `hide()` | **Rebuilt for section 13.** `poolQuery` = the derived pool as a query builder (`s.id, s.name, s.p24_city_id, town_name` - town = the P24 town); `idsInPool` = membership; `towns` = the P24 towns that have a suburb in the pool (id = the P24 town id); `derived` = raw per-suburb `{own, mic, p24, pp}` (cached 5 min per agency). |
| `lastRefreshed(int $agencyId, int $p24SuburbId): array{p24:?Carbon,pp:?Carbon}` | Per portal, read from the same derived facts: P24 = newer of `minion_capture_areas.last_captured_at` and the newest P24 advert placed in the suburb; PP = newest PP advert. null = never. |
| `isStale(?Carbon $at, int $warnDays): bool` | `null` counts as stale. |

Lock behaviour is **applied by lane 2** (`ListBuilderService`) using `lockAgentsToAreas()` + `suburbIdsFor()`; lane 1 only supplies data and the settings warning.

---

## 3. Lane 1 data model

All tables: `agency_id` NOT NULL FK + `BelongsToAgency`; `branch_id` nullable + `BelongsToBranch` where the row belongs to a person; `track` varchar(20) NOT NULL default `'sales'`; `softDeletes`; `timestamps`. No hard deletes anywhere. No agency-1 defaults, no HFC wording.

### 3.1 `rts_settings` — one row per (agency, track)
`id`, `agency_id`, `track`, then:
`lock_agents_to_areas` tinyint(1) default 0; `half_day_leave_halves_target` tinyint(1) default 0; `area_refresh_warn_days` smallint default 7; `missed_items_mode` varchar(20) default `show_missed`; `selections` json (ordered `[{key,enabled}]`); `new_on_market_days` smallint default 14; `long_on_market_days` smallint default 90; `owner_known_quantity` smallint default 3; `stuck_stock_min_days` smallint default 60; `stuck_stock_max_views` smallint default 30; `stuck_stock_max_leads` int default 2; `hot_pocket_lookback_days` smallint default 180; `price_drop_lookback_days` smallint default 30; `stuck_stock_lookback_days` smallint default 30; `no_answer_drop_after` smallint default 3; `hold_days` smallint default 30; `summary_steps` json (ordered `[{key,enabled}]`); `summary_green_from_pct` smallint default 90; `summary_red_below_pct` smallint default 70; `default_weekday_targets` json (`{"1":5,…,"7":0}`); `updated_by_user_id` nullable.
Unique `(agency_id, track)`. **Soft delete (audit F, `deleted_at`):** "Put everything back to the defaults" archives the row (a missing or archived row means the defaults); the next save finds it `withTrashed()`, restores it and starts from the defaults plus that save (BUILD_STANDARD §5a — never a second row). **`rts_settings_audit` is append-only and has no soft delete, by design:** it is the permanent record, never edited or deleted. Columns added by the audit: `public_holiday_country` char(2) default `ZA`; the §9.11 lane 2 values; `deleted_at`. Missing row ⇒ `SettingsService` returns defaults and creates the row on first save, never on read. Every change is appended to `rts_settings_audit` (`agency_id`, `track`, `changed_by_user_id`, `old_values` json, `new_values` json, `changed_at`) — append-only, never edited or deleted, same shape as `core_match_buyer_state_audit`. A save that changes nothing writes nothing.

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

### 5.6 Assistants (audit H) — §11.1 is the rule; lane 1's part
Areas, targets and leave belong to the **list owner**, `User::ownershipUserId()` (the agent an assistant acts for, honouring a multi-agent assistant's "Acting for" choice); a plain user is their own owner. `AreaService::suburbIdsFor/hasAreas/suburbMatchesFor/suburbNames…` and `WorkdayService::targetFor/isWorkday/monthTarget…` resolve an assistant to that owner themselves, so a **direct call with an assistant** gives the same answer as the builder (which already hands over the owner). Rows assigned to an assistant directly are not used; the Areas and Targets screens list and edit real agents only (assistants are excluded from the grids, `agentsWithoutAreas` skips them). Tests: `AuditGapsTest` + `ParkedAndAssistantTest`.

### 5.7 Matching a property to an area (audit B)
A property (a tracked property, joined to its listing) is in an area:
1. **by suburb id** — `tracked_properties.p24_suburb_id` equals the area's `p24_suburb_id`; or, only when the row has **no** suburb id,
2. **by name AND town** — the suburb name (normalised form, or the listing's lower-trimmed free text) equals the area's AND the row's town agrees: its `p24_city_id` equals the area's city; else (no city on the row) its town name equals the area's town; else (no town information at all) the name alone decides **only when that name is unique among the agency's pool suburbs**. Two pool suburbs sharing a name (Uvongo in two towns) therefore never merge.
A row that carries a suburb id of a *different* suburb is never rescued by its name. "Last captured" dates (`lastCaptured`) apply the same test to each listing (through its tracked property); a listing with no tracked property and an ambiguous name belongs to no area.

### 5.8 Track (audit E)
The screens take their track from where they are mounted (`App\Services\RoadToSold\Support\Routing`): route-name root `road-to-sold` = sales, `road-to-rented` = rentals; anything else = sales. The routes live in `routes/road-to-sold-settings.php` as a closure; `web.php` mounts them as `road-to-sold.settings.*` and Road to Rented mounts the same file under `road-to-rented/settings` with name `road-to-rented.settings.` and gets the same screens, the same savers and its own rows (proved by `AuditGapsTest`). Nothing a user posts can switch track. Links and redirects use the mount's own route names (`$rb` in views, `Routing::base()` in controllers).

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
| Suburb later falls out of the derived pool (no stock / MIC, below the minimum, or hidden) | Absorb: the assignment row is KEPT and shown "Not in your stock or MIC yet"; `suburbIdsFor` keeps it (nothing silently drops an agent's area) except when an administrator has HIDDEN the suburb or its town - then it stops feeding that agent's lists, the row stays. |
| Agent deactivated / role loses access | Absorb: hidden from the grids, rows kept; `suburbIdsFor` unaffected. |
| User outside viewer's scope | 404 (not 403) so existence is not revealed. |
| Save with no change | Absorb: success message, no audit noise. |
| Two admins save the same grid | Last write wins per cell; every changed cell is audited. |
| Agent has every weekday at 0 | Valid; `nextWorkday` returns null; consumers must handle it (lane 2: no list, "no working days set" message). |
| Settings read when no row exists / agency id 0 | Absorb: defaults, nothing persisted. |
| "Back to the defaults" when already on the defaults | Absorb: nothing to undo, no audit row. |
| Save after "back to the defaults" | Restore the archived row, defaults + this save; never a second row. |
| Holiday country not in `public_holidays` | Prevent: "pick one of the choices shown". |
| A tick-list (buyer tiers / buyer states / the two status lists) with nothing ticked or an unknown value | Prevent: "tick at least one" / "pick from the choices shown"; a stored list with unusable values reads back as the clean subset, else the default. |
| Add-to-day / scan page / scan cap / hot-pocket weights out of range | Prevent: whole numbers 1–500 / 10–2000 / 1–50 / 0–100. |

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
Every row carries a tracked property (the builder creates one through match-or-create when a listing or own property has none; if that fails the property is not placed). Unique `(agency_id, track, user_id, list_date, tracked_property_id)` — a property is on a day's list once — plus, at the database level for any legacy or future row without a tracked id, a second unique index on the stored generated column `property_key` (tracked → listing → property, NULL once archived), migration `…120200_close_rts_list_item_duplicates`. `RtsListItem::scopeForDay` is a range on `list_date` so the day index is used. Because a unique index ignores soft-deletes (BUILD_STANDARD §5a) the builder inserts through `withTrashed()->firstOrNew` + `restore()`. The builder never soft-deletes; statuses carry the lifecycle.
Scoping: `RtsListItem::scopeVisibleTo($user, ?$scope)` — `all` agency, `branch` = `effectiveBranchId()`, `own` = `dataIdentityIds()`, otherwise nothing; scope read from `PermissionService::getDataScope($user,'road_to_sold')`. Agency isolation by `BelongsToAgency`. Lane 3's page reads **only** through this scope.

### 9.5 Piece 4 — selections
One interface `Selection`: `key()`, `label()`, `candidates(SelectionContext $ctx, int $limit): Collection<Candidate>`. A `Candidate` carries `trackedPropertyId`, `prospectingListingId`, `propertyId`, `reason`, `hasAddress`, `inArea`. Order inside every selection: **area tier first** (agent's own suburbs, then the rest — or only own when "Lock agents to their areas" is on), **then with-address before without**, then the selection's own rank. (Open question Q6: if an addressed property outside the agent's area should instead beat an address-less one inside it, it is one comparator to flip.)
Shared exclusions (every MIC-listing selection, 1–7): inactive/off-market; the agency's own on-market stock (`whereNotCompanyStock`); a property that is **another agent's CoreX property, whatever its status** (sold, withdrawn, expired, prospect, draft…) — found through the matched / promoted link AND, for an unlinked one, through the portal reference or normalised address (`Support\OtherAgentStock`; only a property that is another agency's imported listing, status `other_agency_stock`, belongs to nobody); the market-pool selections also filter on `prospecting_listings.listing_type` for the track; an active MIC claim or pitch lock held by another user; plus the **suggestion guard** (cc3: held by another agent, do-not-contact) and the agent's own still-held properties. Twin adverts (P24 + PP of one property) collapse to one candidate on `tracked_property_id`.

| Key | Source | Rank |
|---|---|---|
| `my_buyers_sourcing` | `prospecting_buyer_matches` (not dismissed, tier in the agency's match levels — standard perfect/strong) for buyers whose `contacts.agent_id` / `second_agent_id` is the agent and `buyer_state` in the agency's buyer states (standard new/warm) | most matching buyers, best score |
| `agency_buyers_sourcing` | same, any agent's buyers | same |
| `hot_pockets` | `HotPocketService` (below) | pocket score |
| `price_drops` | `prospecting_price_history` (new < old) inside the lookback | biggest % drop, most recent |
| `new_on_market` | listed (else first-seen) within `newOnMarketDays`; row says which | newest first |
| `long_on_market` | listed (else first-seen) more than `longOnMarketDays` ago; row says which | longest first |
| `owner_known` | owner known by any of the four paths (tracked property `owner_contact_id`; a `tracked_property_owners` row with a contact; a `contact_property` seller on `matched_property_id`; an owner on the deed-linked tracked property) | shown again, capped at `ownerKnownQuantity` per agent per day — counted across the overnight build, top-ups and every add-to-day |
| `my_expired_stock` | the agent's own `properties` (`agent_id` in `dataIdentityIds()`), sale: `status='expired'`, OR a live status (agency's "on the market" list) whose `expiry_date` is before the list date | latest `expiry_date` first; listing agent only |
| `my_stock_not_selling` | the agent's own on-market properties (the agency's "on the market" statuses) with `DaysOnMarket` ≥ `stuckStockMinDaysOnMarket`, portal views (look-back window, `property_portal_metrics`) ≤ `stuckStockMaxViews`, leads ≤ `stuckStockMaxLeads`; **no statistics in the window = zero views and zero leads, so it qualifies** | longest on market first; listing agent only |

Selections 8 and 9 read **only** the user's own properties — there is no code path that reads another agent's stock (tested). Tracked-property id for own stock = `tracked_properties.promoted_to_property_id` lookup, else match-or-create (`Support\TrackedPropertyResolver`); `property_id` rides along.
**Hot pockets (piece 6):** a pocket is a suburb - identified by **suburb id**, never by bare name (`Support\PocketResolver`, the same rule lane 1 uses for areas, §5.7): a row with a suburb id is that suburb; a row without one is matched by name only when the name belongs to one pool suburb, or when its city/town agrees; a name two towns share with no town to tell them apart is its own pocket ("uvongo|") and never guessed into either town. Two pockets of one name show their town on the row ("2 leads in Uvongo (Margate)"). The pool filter and order are by `tp.p24_suburb_id` first, name second. Score = distinct active buyers with a perfect/strong match on listings in that suburb **+** the agency's own portal leads (`portal_leads` → `properties.suburb`) **+** the agency's own completed sales (`deals_v2` → `properties.suburb`) inside the lookback, each part weighted by an agency setting (standard 1 each = equal), explained on the row ("14 buyers, 6 leads, 3 sales in Uvongo"). The join is the one the investigation found: `portal_leads.listing_id` and `deals_v2.property_id` both resolve to `properties`.

### 9.6 Piece 5 — the builder
`ListBuilderService::buildFor(User $u, CarbonInterface|string $date, string $track='sales'): Collection` — idempotent (if a list already exists for user+date+track it is returned untouched). Order: (1) **follow-ups due on or before the date** (origin `follow_up`, from cc3's `rts_contact_outcomes.next_action_at`; overdue ones carry forward), (2) rolled-over items (origin `rollover`) when the agency rolls over, (3) new properties from the enabled selections **in the agency's order** until `WorkdayService::targetFor` is reached — follow-ups and rollovers **count toward** the target. Every property counts whether or not an owner is on file. Not a working day (`isWorkday` false) → no list. `addToDay(User, string $selection, int $count, $date=today)` adds extra items with origin `added` (not counted toward the target, reported separately); count 1 to the agency's add-to-day maximum (standard 100); the selection must be enabled.
Nightly: `rts:build-lists [--date=] [--agency=] [--user=]`, scheduled 04:30. For each eligible agent (holds `access_road_to_sold`, active) it first **closes** open items of earlier days (`missed`, or `rolled` + a new `rollover` item on the next working day when the agency rolls over), then builds today's list if today is a working day. Run by hand on QA1 (no scheduler there).
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
| Listing / own property with no tracked property | Given one on the way in (match-or-create); if that fails it is logged and not suggested; the database also refuses a duplicate by `property_key` |
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
3. **Evaluation booked** is offered on every *conversation* outcome (never on an attempt, never on "never again"). It needs a date-and-time, creates a `property_evaluation` calendar event through `CalendarEventCreator` (owner contact linked when known), sets `valuation_booked`, and — because the appointment is the follow-up — the property leaves the call list (`next_action = calendar_appointment`) whatever outcome it was booked from. *Decision (mine, within the brief):* the brief seeds "Evaluation booked" as its own outcome **and** says it is offered on every conversation outcome; both are built.
4. **No answer run:** `counts_as_no_answer` outcomes count consecutively for this agent on this property; any other outcome (or another agent's) resets the run. At `SettingsService::noAnswerDropAfter()` the row is `dropped` (never a follow-up again) and shows on the manager's report. The agent's hold on the property continues until it lapses.
5. **Owner contact side** (only when the tracked property has an owner contact): a `ContactNote` ("Road to Sold - <outcome>: <note>") is written — that is the history on the contact, visible in the contact's existing Notes tab; **conversations call `Contact::markContacted()`** (moves last-contacted, fires `ContactContactedByAgent` — intended, conductor ruling), attempts do not. A plain note never moves last-contacted (unchanged rule). **The owner is looked up without `ContactScope`** (agency wall kept): that scope decides which contacts a person *sees* (an assistant's granted breadth, an agent's "own"), but the owner of a property is the property's fact - looked up through it, an assistant calling an owner a colleague captured lost the owner from the outcome, the note and last-contacted (found by the agent-week scenario test).
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
* **Contacted client popup:** outcome list (active only, agency order); date picker only for outcomes that need one; "Evaluation booked" tick (conversations) → date-and-time; note; Save. Errors re-open the popup with the message.
* **History popup:** the property's last 10 outcomes the viewer may see (own = own calls, branch = branch, agency = all): outcome, attempt or conversation, evaluation booked, date, who, back-on date, note. Read through `OutcomeService::historyForPage()` (one query for the whole page, `RtsContactOutcome::scopeVisibleTo`). The same outcome is also a note on the owner contact (Notes tab) - history on the property and on the contact.
* **Layout rule (Johan):** screen space is for data and controls only - no explanatory paragraphs under headings or empty states; every field, dropdown and button on the page, the report and the outcome settings is the same height, and every button the same weight, border and corner - the standard primary button has no border and a heavier weight than the outline one, so `_controls` evens them out; the two buttons in a popup (Cancel / Save) also share a width (`road-to-sold/_controls.blade.php`, classes `rts-page`, `rts-actions`).
* **Track:** every Road to Sold request carries a track (`RoadToSold\Track::fromRequest()` - route default or `track` field; anything unknown = sales). The page, outcome list, capture, add-to-day, summary bar, report, print/PDF/XLSX and drill-down all use it; links and forms keep it (`Track::params()` - nothing is added for sales). The three feed blocks are sales work and are not shown on another track. Open: the on-demand list build in `RoadToSoldController::buildIfMissing()` and `RtsListItem::forDay` do not take a track yet - that block is cc4's current fix and was left alone.
* **Dates in the past:** an agent-picked return date and an evaluation date-and-time are refused with a plain message when they are before today / before now (`OutcomeService`).
* **Report vs drill:** the call-log drill left-joins `tracked_properties`, so a call on an archived property is in the drill exactly as it is in the report; the row reads "(property archived)".
* **Add to my day:** selection + count → `ListBuilderService::addToDay()` (lane 2).
* **Blocks under the list (not counted against the target), each own-scoped and correct (not the Today-page queries):** buyer follow-ups (`contacts.agent_id` in the agent's identity — **not** `created_by_user_id` — stale beyond the agency's buyer-warm/cold days, newest risk score only, no hard-coded 14 days); lead follow-ups (`LeadResponseService::results()` waiting/overdue for the agent); expiring mandates (`MandateExpiryPolicy` window for the agent's own listings).

### 10.6 Summary bar and report (BUILT)
**Summary bar** (`SummaryBarService`, on the page). Steps and order come from `SettingsService::summarySteps()` (each switchable by the agency); social shares is not built. Each step is the month so far, counted by `DrilldownService::count()` - the same query as the rows behind the figure, so the two cannot disagree.
* `calls_made` = every outcome captured; `conversations` / `evaluations_booked` from the call log; `evaluations_done` = the agent's `property_evaluation` calendar appointments with feedback captured; `mandates_signed` = mandate e-sign documents owned by the agent that reached COMPLETED (the same signal that credits `mandate.signed` points); `listings_live` = the agent's sale listings whose `listed_date` (first portal publish) falls in the month.
* **Should-be-by-today:** calls = `WorkdayService::monthTargetToDate()`; listings live = the agent's Agency Tracker `targets.listings_target` for the month x the share of the month's target days already passed (calendar days when the agent has no call target). **Conversations, evaluations booked, evaluations done and mandates signed have no monthly target (Q9): the bar shows the actual only, no colour.**
* Colour: `SettingsService::statusForPct()` - green on or above the green line (default 90), red below the red line (default 70), amber between.
* Click a tile -> `GET /road-to-sold/drilldown` (`road-to-sold.drilldown`), the shared drill-down envelope `{title,total,columns,rows,truncated}` rendered by `buyers-report/_drilldown-modal`.

**Report** (`GET /road-to-sold/report`, `road-to-sold.report`; `.print`, `.pdf`, `.xlsx`). Permission `access_road_to_sold`; breadth by `road_to_sold.view` (own = the viewer only, branch = the branch, all = the agency). One row per agent plus a Total row, one column per figure:
`Target` (WorkdayService over the period) - `Worked` (list items worked toward the target) - `Attempts` - `Conversations` - one column per outcome (the agency's current list in its own order, plus any outcome logged in the window even if archived since) - `Evaluations booked` - `Extra over target` (items worked after "Add to my day") - `Missed / rolled over` - `Dropped after no-answers`.
* Reuses `PeriodResolver`, `Period`, `PeriodComparison`, `performance.agency-report._period-selector`, `<x-performance-delta>`, the drill-down modal and its envelope. **Added the `last_30_days` preset to `PeriodResolver::PRESETS`: it now also appears in the period selector of the Agency Performance, Buyers and Lead Response reports** (30 days ending today, today included; tested in `PeriodResolverTest`).
* **Compare:** any compare mode the selector offers (previous, same period last year, custom). Each cell shows the figure and, beneath it, the delta line; direction of good: more is better for worked / attempts / conversations / evaluations / extra; fewer is better for missed and dropped; target and each outcome are neutral.
* **Every figure drills to its rows** - agent cells, the Total row, and the delta line (rows of the comparison period, via `source=compare`). The drill base carries `period`, `start`, `end`, `compare`, `compare_start`, `compare_end`, `branch_id` and the agent search, so the total row drills the same agents the page shows. `DrilldownService` metrics: `target` (one row per working day), `worked`, `added_worked`, `missed`, `attempts`, `conversations`, `evaluations_booked`, `dropped`, `outcome:<key>`.
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
| Evaluation booked without a date-and-time / on an attempt | Prevent / ignored |
| Note blank | Absorb; > 5000 refuse |
| Property held by another agent | Refuse with who and until when (do-not-contact excepted) |
| Owner has no contact record | Absorb: history on the property only |
| Do not contact, no owner contact | Absorb: marker only |
| Same outcome captured twice quickly | Two honest rows; streak and hold behave |
| Agency id 0 / no signed-in user | Defaults, nothing written, nothing crosses agencies |

### 10.8 Open questions (lane 3 → conductor; each has a default so building is not blocked)
* **Q9 (business):** The summary bar needs a *monthly target per step*; the brief gives weekday targets for calls only. *Built:* calls use the weekday targets, listings live uses the Agency Tracker listings target; **conversations, evaluations booked, evaluations done and mandates signed show the actual only (no "should be", no colour) until Johan says how they are targeted.**
* **Q10 (data):** Other agencies' mandate **end dates** are not stored anywhere, so "expiry date if known else agent-picked" is always agent-picked today.
* **Q11 (process):** lane 3 lands on `origin/QA1` itself (conductor, 13 Oct) although Standard −1l says only cc1 does; acted on the conductor's word.


### 10.9 Call history on the property and the tracked-property page (BUILT, 14 Oct)
- Rule (section 1): every outcome is history on the property and on the owner contact. The contact side is the contact note written by `OutcomeService`; this is the property side.
- `<x-road-to-sold.history-panel :property-id>` on the property page (Overview tab, under the owner/showdays grid) and `:tracked-property-id` on the tracked-property (MIC) detail page. Heading "Road to Sold calls". Columns: When, Who, Outcome, Note, Next action. Newest first, 10 a page (`?rts_page=`, Previous/Next). Empty state is one line: "No Road to Sold calls on this property yet." No helper text.
- Reads `PropertyHistoryService` (viewer-scoped and paged; `OutcomeService::historyForProperty` stays the unscoped read behind the call page). Scoping is in the query through `RtsContactOutcome::visibleTo()`: agent = own calls, branch manager = the branch, admin = the agency; agency is always pinned.
- The property page shows calls logged on the property (`property_id`) and on the tracked property it was promoted from (`promoted_to_property_id`). Works with no owner contact.
- Renders nothing without the `road-to-sold` feature or `access_road_to_sold`. Tests: `tests/Feature/RoadToSold/PropertyHistoryTest.php`.

## 10. Wizard step as built (lane 1, step 5)

Step key `road_to_sold` (`config/agency-onboarding-copy.php`; `AgencyOnboardingSetup::STEPS` position 8b; gate `road_to_sold => road-to-sold`). Controls (all with `explain` + "What this changes:"): `default_target_1..7` (Monday..Sunday, 0-200), `half_day_leave_halves_target`, `lock_agents_to_areas`, `area_refresh_warn_days`, `missed_items_mode`, `no_answer_drop_after`, `hold_days`. Saver: `RoadToSold\SettingsController@saveFromWizard` - requires `road_to_sold.manage_settings` (403 before any write, absorbed by the wizard), maps `default_target_N` to the settings page's `default_targets[]` only when all seven are posted, then calls the same `saveFor` (partial-update safe: anything the step did not post is left as it was). Values shown come from `SettingsService` through an explicit per-key arm in `AgencySetupWizardController::currentValues`. **Deliberately NOT in the wizard (Johan, 13 Oct):** the call-list sources (order, on/off) and their limits and look-backs, the colour lines, the on-track bar steps; and each agent's own suburbs and weekday numbers (per-person records). Also recorded in `agency-onboarding-setup.md` §5 / §5.1.

### 9.10 Proven on real QA1 data (13 Oct 2026, rolled-back transaction + read-only timing)
* Agent 29 (5 buyers matching): 5 items in 197 ms, all "My buyers". Agent 24: 5 items, all "My buyers", own expired stock 59 candidates, every one owned by agent 24. Johan (22): own expired stock 200 candidates, every one owned by 22.
* Selection capacity (200 asked): my buyers 200 / agency buyers 200 / hot pockets 200 / long on market 200 (all with an address) / price drops 4 (none with an address) / owner known 3 (the agency quantity) / new on market — measured before the first-seen fallback (13 Oct ruling) when it was 0 / stuck stock 0 at that time (QA1's portal statistics stop at 7 Sep, outside the 30-day window) — since the 13 Oct audit, a property with no statistics counts as zero views and zero leads and qualifies.
* Timings after the fix: 0.01–0.6 s per selection, hot pockets 1.8 s (141 pockets). **Before the fix two selections ran past 60 s**: `px.id IN (pl.matched_property_id, tp.promoted_to_property_id)` in a correlated NOT EXISTS defeats the primary key on 34k live listings; replaced by two plain equality checks (0.26 s). Test data cannot show this class of fault — profile on real data.
* Strict agency-order fill (Q8) means an agent who has buyers gets a day that is 100 % "My buyers" until those run out. That is the rule as written; a per-selection share would be a setting.


### 9.11 Settings lane 2 reads — agreed keys (audit G, lane 1, 13 Oct 2026)
These were hard-coded in lane 2; they are now agency settings (columns on `rts_settings`, getters on `SettingsService`, controls under "Matching and list size" on the General screen). **Lane 2: replace the constant with the getter; do not add columns.** Defaults equal the old constants, so an untouched agency behaves exactly as before.

| Getter | Replaces | Default | Choices / range |
|---|---|---|---|
| `buyerMatchTiers()` | `tier IN ('perfect','strong')` (BuyerSourcingSelection, HotPocketService) | `perfect, strong` | perfect / strong / approximate; at least one |
| `activeBuyerStates()` | `ACTIVE_BUYER_STATES`, `buyer_state IN ('new','warm')` | `new, warm` | new / warm / cold; at least one |
| `onMarketStatuses()` | `MyStockNotSellingSelection::ON_MARKET` (also selection 8's live-status test) | `active, for_sale, on_show` | active / for sale / to let / under offer / on show / prospecting / expired; at least one |
| `addToDayMax()` | `ListBuilderService::MAX_ADD` | 100 | 1–500 |
| `scanPageSize()` | `MicPoolSelection::PAGE` (the first window; the scan then doubles and never stops short) | 200 | 10–2000 |
| `hotPocketWeightBuyers()` / `…Leads()` / `…Sales()` | the equal weights in `HotPocketService::rank` | 1 / 1 / 1 | 0–100 (0 ignores that source) |

Not settings on purpose: the lead-type `'Test'` and the deal statuses counted as a sale (`active/granted/completed`) are data-integrity rules, not agency preferences. Hot pockets are still keyed by suburb *name* in `HotPocketService` (portal_leads → properties.suburb); two same-named suburbs in two towns are merged there — lane 2's file, reported to the conductor (§5.7 is the rule to follow).

---

## 11. Assistants, and properties the agent has parked (13 Oct 2026, conductor rulings after the lane audit)

### 11.1 Assistants - ONE rule

**An assistant works the list of the agent they act for.** The *list owner* is `User::ownershipUserId()` - the assigned agent, or the Sub-Agent a multi-agent assistant has chosen with "Acting for". A normal user is their own list owner. Every Road to Sold service that takes a `User` first turns an assistant into the list owner, so one user id is used everywhere:

- **page build and page read** - `ListBuilderService::buildFor()` / `listFor()` / `RtsListItem::forDay($user)` resolve the list owner; the page reads by that same id, so what the assistant sees is the agent's list;
- **the nightly build** (`rts:build-lists`) builds for the AGENT (assistants are skipped as users in their own right: they have no list of their own) - the assistant opens that list;
- **add to my day, roll-over, "worked"** (`addToDay`, `closePastDays`, `markWorked`) all act on the agent's rows (`rts_list_items.user_id` is always the agent);
- **areas and targets** are the agent's: because the builder hands the list owner to `AreaService` / `WorkdayService`, an assistant never has "no areas" of their own (lane 1 also fixes `AreaService::suburbIdsFor` for a direct assistant call - both must match this rule);
- **outcomes**: the outcome, the hold, the follow-up, the target and the report figure belong to the AGENT (`rts_contact_outcomes.user_id`). WHO ACTUALLY MADE THE CALL is kept in `rts_contact_outcomes.recorded_by_user_id` (the assistant; `NULL` = the agent recorded it). The note written on the owner's contact says "(call made by <assistant>)", and `OutcomeService::historyForPage()` returns `made_by` beside `by`.

**Report, drill and summary bar (lane 3, verified 13 Oct):** an assistant is never a row. `CallListService::viewableAgents()` (the page's agent list and the report cohort) excludes `is_assistant` users, and `ReportService::build()` drops any it is handed, so the Total row and its target are the agents' alone. The assistant's calls are already the agent's (`rts_contact_outcomes.user_id`), so they count under the agent; the call-log drill shows a **Call made by** column (the assistant's name, `-` when the agent made it). A direct drill on an assistant's id is a 404. The summary bar is built for the list owner (the page passes the agent), and for an assistant it reads the agent's identity ids and the agent's target - it is a view of the agent's month, never added to it. Test: `ReportTest::test_an_assistant_is_never_a_row...`.

Migration `2026_10_29_500000_add_recorded_by_to_rts_contact_outcomes.php` adds the column (nullable, indexed, no foreign key - project convention).
Tests: `tests/Feature/RoadToSold/ParkedAndAssistantTest.php`.

### 11.2 Properties the agent parked with their own outcome are not offered back as new

Audit gap 1. A hold lasts a fixed 30 days (agency setting) and then the property is free again (section 1: "lapses -> free"). But an agent's OWN latest outcome can say more than that, and it must not be contradicted by the property reappearing as a new suggestion. `HoldService::ownParkedIds()` (part of `excludedIdsFor()`, so it applies to every selection and to follow-ups through `SuggestionGuard::blockedFor()`) excludes, for THIS agent only, a property whose current outcome is:

- a return date still in the future (return after N days, a picked date, a mandate expiry date) - until that date, when it comes back as a **follow-up** through `OutcomeService::followUpsDue()`;
- "move to the list" (wrong number) - it lives on the find-the-right-contact list, not the new-property list;
- a booked evaluation (the appointment is the follow-up);
- dropped after N no-answers in a row (on the manager's report, off this agent's list).

`blockedFor()` takes the day the list is built for (`$on`, default today), so a follow-up that is due on that day is never mistaken for a parked property (building tomorrow's list tomorrow's follow-ups are due). Another agent is NOT affected: when the hold lapses the property is free for them, as section 1 says.
Open question for Johan (not built): after a hold lapses should a property that the first agent parked with a date far in the future also stay off OTHER agents' new lists until that date?

*Superseded by the lane 2 audit (§9.12):* `takenStatuses()` and `scanMaxPages()` were removed again - another agent's property is never offered whatever its status (so there is no "taken" list), and the pool scan has no page cap.

### 9.12 Audit fixes (13 Oct 2026, cc4's findings A-H on lane 2's files)
* **A — never another agent's stock, on every path.** `Support\OtherAgentStock` (built once per build / add-to-day / close; ~0.8 s on QA1's 4,700 properties): another agent's property is excluded **whatever its status** and whether or not the listing was linked to it (matched / promoted link, same portal reference, same normalised address; a tracked property any of whose adverts is theirs). It is applied inside the pool scan (so a day is not left short), to follow-ups, to rolled-over items (closed as missed, not carried) and to add-to-my-day — and again in `ListBuilderService::insert()`, the one gate every origin passes. The agent's own stock is never blocked by it. On real QA1 data for the agent with most properties, 885 of 34,097 active adverts are now excluded (372 of them only found through reference/address, not through a link). The earlier fixed status list (active, for_sale, … expired) is gone — it let sold / let / withdrawn through.
* **B — nothing fails quietly.** `BuilderSettings::forUser()` reads `SettingsService` directly (the "class missing → defaults" fallback and the 5-a-day calendar fallback are gone; the adapters call lane 1/3 directly). An enabled selection with no implementation is logged as an error. A failed on-demand build on the page logs and shows "We could not build your call list just now…" instead of an empty page (small change in lane 3's `RoadToSoldController`).
* **C — owner known is per agent per day.** The quantity left = agency quantity − owner-known rows already on that day (any origin), read from the day's rows on every build and add-to-day.
* **D — selections 8 and 9.** 9: no portal statistics = zero views / leads (qualifies). 8: also a live-status listing whose `expiry_date` has passed.
* **E — settings, with today's value as the default** (lane 1 delivered the columns and controls - §9.11; Settings → Road to Sold; **not in the wizard**: they are limits for the call-list sources, which Johan ruled settings-page only on 13 Oct): the builder now READS them (add-to-day maximum on the page and in the builder, hot-pocket weights, buyer match levels and states, on-market statuses, first scan window). The "statuses counted as taken" list does not exist (A replaces it) and the page cap is gone: the scan reads one `LIMIT scanPageSize` batch (nearly every day is filled from it) and, only if the day is still short, streams the REST of the ranked pool from one more query in batches of at least 2,000 - until the day is filled or the pool is exhausted. (An earlier doubling-OFFSET version re-sorted the pool per page: ~0.7 s each on 34,000 adverts.)
* **F — track.** `prospecting_listings.listing_type` (`sale` default, migration `…120100`) and every market-pool selection filters on it by track (`rentals` → `rental`). Nothing writes `rental` yet; the rentals capture will.
* **G — keyed and de-duplicated rows** (see §9.4).
* **H — day lookup** is a date range, not `whereDate()`.
Test file: `tests/Feature/RoadToSold/AuditFixesTest.php` (24 tests; the guard tests fail if the guard is disabled).
* **Follow-up round (13 Oct):** (1) hot pockets by suburb id / town + name, above. (2) Every business value the builder uses is a `SettingsService` getter read through `BuilderSettings::forUser()`; the constructor defaults of `BuilderSettings` are read from `RtsSetting::SCALAR_DEFAULTS` / `LIST_DEFAULTS` (one table, no copy). What remains in code is not an agency preference: the deal statuses counted as a sale and the lead type "Test" (data-integrity rules, §9.11), the cache lock/marker timings, the streamed batch floor (2,000), and the fixed domain status `expired`. (3) **Track end to end:** `buildFor`, `listFor`, `addToDay`, `closePastDays`, `markWorked`, `RtsListItem::forDay`, the page's build-if-missing and the nightly command (`--track`, default `sales`) all carry the track; an unknown track is an error in the builder (`InvalidArgumentException`) and a failure in the command, never a quiet sales list. A rentals list draws on `listing_type = 'rental'` adverts, the agent's rental properties and rentals follow-ups only. (4) **Timing, one agent, 34,000 adverts, 8,000 other-agent properties, 25,000 buyer matches (tests-only database, `BuildTimingTest`, run with `RTS_TIMING=1`):** normal day (target 5) 1.9 s / 54 queries; big day (target 60) 1.3 s / 433 queries; add 50 to the day 1.3 s / 360 queries; the artificial worst case (every property blocked, so all seven pool sources exhaust the whole pool) 8.4 s / 1,128 queries. On a busy shared test database the same runs read up to 2-3x slower. Fixes made for it: the other-agent stock load uses joins (not 24 long IN lists), own stock already given a tracked property is found again by its external reference (not re-matched every build), and the pool is sorted at most twice per source.
* **Roll-over passes the same gate (13 Oct, cc6's scenario).** One private gate, `ListBuilderService::mayPlace(user, day, track, candidate)`, now answers "may this property be on this agent's list for this DAY" for every path that writes a row: not another agent's CoreX stock (`OtherAgentStock`) AND not blocked by the suggestion guard (`blockedFor($user, [tracked id], $track, $day)`: held by another agent, do-not-contact / never-suggest, parked by the agent's own outcome past that day). `insert()` calls it (so auto, follow-up, add-to-day and roll-over all do), and `closePastDays()` calls it with the roll-over TARGET day before carrying an item: a missed property that fails it is closed as `missed`, not `rolled`; one that is simply already on the target list (a follow-up due that day) is `rolled`. The day is passed so a property whose return date is the target day is a follow-up, not a parked one. Tests: `RolloverGuardTest` (real hold, never-suggest flag, parked outcome, another agent's stock; the guard fails the test when removed).


## 13. Derived working areas - Johan's ruling of 13 Oct 2026 16:03 (cc1, built on QA1)

> Johan, verbatim: "suburbs and towns can be auto created from properties that the agency have - so all stock suburbs and towns, and MIC suburb and towns. That way we are displaying what they have in stock and is updating from P24 and PP."

**Why the old rule failed (found on QA1, same day).** The first pool was "the suburbs of the towns hand-set in Prospecting Setup (`towns.p24_city_id`) + ticked P24 auto-import suburbs". The Prospecting Setup towns screen never sets `towns.p24_city_id` (only the `prospecting:assign-municipalities` command mirrors it from stock), so a town an agency added by hand reached Road to Sold with no suburbs, and HFC's QA1 towns carried seed towns from other provinces (East London, Gqeberha, Johannesburg, Sandton, Cape Town, Knysna, Somerset West). Nobody had a way to see or fix that from Road to Sold.

**The rule.** An agency's working areas are DERIVED, agency-scoped at the query layer (every query carries `agency_id`):
- **(a) Own stock:** `properties` of the agency, not deleted, **Other Agency Stock excluded** (it is another agency's advert - `Property::STATUS_OTHER_AGENCY_STOCK`), grouped by `p24_suburb_id`. A property with no suburb id but a P24 city is placed by (city, name) only when that name is the ONE suburb of that city.
- **(b) Market Intelligence:** every `prospecting_listings` advert of the agency (P24 and PP), placed by its tracked property's `p24_suburb_id`; a row with a city but no suburb id by (city, name) as above; a row with neither only attaches to a suburb already in the pool whose name is unique there. **Never by a bare name** (spec 5.7).
- A suburb is a **working area** when own stock + MIC adverts >= the agency setting `min_area_properties` (default 1) and it is not hidden. Towns = the P24 town of those suburbs. **No fallback to every suburb, ever:** no stock and no MIC data = an empty pool.

**Fast, and why.** Computed on read from two index-backed GROUP BYs (`properties(agency_id,p24_suburb_id)`, `tracked_properties(agency_id,p24_suburb_id)` joined to `prospecting_listings(agency_id)`), cached per agency for 5 minutes (`AreaService::derived()`, key `rts:derived-areas:{agency}`; tests bypass it). Measured on QA1 (HFC: 5,801 properties, 40,624 tracked properties, 41,365 adverts): **0.47 s uncached**, 296 suburbs in 62 towns. A maintained table was rejected: it needs a hook in every stock, capture and import path (several lanes' code, two portals) and can drift; a 5-minute read of what is actually there cannot.

**What each suburb row shows** (suburbs-and-who-works-them): our stock count, MIC adverts count, who works it, last captured P24 / PP, Needs a refresh / Refresh now, and Hide. Filter: Where from (stock and MIC / we hold stock / MIC only), Show (working / hidden), search, town, nobody works it, needs a refresh. Sort: name, needs a refresh, most agents, most of our stock, most MIC adverts. The agents-and-their-suburbs picker lists the same pool with the counts.

**Minimum (agency setting, in the wizard).** `rts_settings.min_area_properties` (default 1, 1-1000): "a suburb counts as a working area from N properties" (stock + MIC adverts). General screen + the `road_to_sold` wizard step. Keeps a single stray advert out of the list.

**Hide (administrator, soft).** `rts_hidden_areas` (agency_id, track, kind suburb|town, p24_suburb_id | p24_city_id, hidden_by, soft deletes). Permission `road_to_sold.manage_settings`. Hide on a suburb row, "Hide <town>" when a town is filtered; the Hidden view lists them with **Bring back** (a soft delete of the hide row; hiding again restores the same row). A hidden suburb or town leaves the pool, the picker and the agents' lists; nothing is deleted. Typical use: a one-off import from another province.

**Existing assignments** (`rts_agent_areas`) outside the derived pool are KEPT and flagged "Not in your stock or MIC yet" (section 6 table). Adding a suburb outside the pool is refused with a plain message.

**Same pool everywhere.** The areas screens, the add-suburbs picker, `AreaService::suburbMatchesFor()` (cc2's `AreaMatchProvider` - contract unchanged; "unique in pool" is judged against the pool plus the agent's own suburbs), `HotPocketService` (cc2: `poolQuery()->get()` - same shape `id, name, p24_city_id, town_name`; town_name is now the P24 town) and the refresh prompts all read `poolQuery()` / `derived()`.

**Empty agency.** One line - "No stock and no Market Intelligence data yet - your suburbs appear here by themselves once you hold stock or capture adverts." - with **Market Intelligence capture** (`/corex/market-intelligence`) and **P24 Auto-Import** (`admin.minion.setup`, only for `access_settings`). If everything is hidden it says so and offers "See what is hidden".

**Where the old set-up lives** (for the record, not used by Road to Sold any more): Settings -> Operations -> Prospecting Setup -> Towns, `GET /settings/prospecting?tab=towns` (`settings.prospecting.index`, `prospecting_setup.manage`), and the wizard step `market_intelligence`. It has no search, no select-all-in-town and does not link a town to a Property24 town - proposal in the conductor report, not built.

**Wizard (rule 10a).** Areas no longer need choosing; the `road_to_sold` step now says so and carries `min_area_properties` beside Lock agents to their areas. Tests: `AreasTest` (derivation, Other Agency Stock excluded, MIC placement by city, minimum, hide / bring back, permission, second agency never sees the first's suburbs, empty state, picker + town filter, counts / sort / source filter), `WizardStepTest`.

## 12. The word is "evaluation", never "valuation" (Johan's standing rule, 14 Oct 2026)

Everything a user can see in Road to Sold says **evaluation**: the seeded outcome "Spoke - evaluation booked", the tick "Evaluation booked" and "Evaluation date and time" on the Contacted client popup, the summary-bar and report steps "Evaluations booked" / "Evaluations done", the drill-down titles and rows "(evaluation booked)", the plain messages ("The evaluation cannot be booked in the past…", "The evaluation is in your calendar."), the calendar appointment title "Evaluation: <address>", and the history note on the owner contact.

* **Renamed (they are visible in the address bar or stored per agency):** the outcome key `spoke_valuation_booked` -> `spoke_evaluation_booked`; the summary-step / report / drill-down metric keys `valuations_booked` -> `evaluations_booked` and `valuations_done` -> `evaluations_done`.
* **Kept, never shown to a user:** the call-log column `rts_contact_outcomes.valuation_booked` and the form field of the same name (a rename would touch every lane's queries for no visible change); the page's Alpine state names (`valuation`, `offers_valuation`, `oldValuation`); the PHP variable `$wantsValuation`; the ledger key `valuation` in tests.
* **Migration `2026_10_29_600000_rename_valuation_to_evaluation_in_road_to_sold`** (idempotent) re-words what is already stored: each agency's seeded outcome (label only where the agency has not renamed it itself; the key always moves unless the agency already owns the new key), the call log (key, and the label snapshot where it is still the seeded wording), saved summary-bar steps and the settings audit trail, the "Road to Sold - Spoke - valuation booked…" note on the owner contact (the agent's own words are never edited), and the title of the calendar appointments Road to Sold created.
* Guarded by `EvaluationWordingMigrationTest` and by `AgentWeekScenarioTest`, which fails if the list page, any report view or any drill-down says the old word.
