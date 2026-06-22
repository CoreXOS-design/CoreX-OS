# Atlas — Buyer Pipeline (the Buyer Pillar)

> **Status: DONE** · Last verified: 2026-06-22
> Pillars: **Contact** (the buyer) × the matching engines. Companion: `.ai/specs/unified-buyer-wishlist-spec.md`.
> Cited: AT-71 (countable gate), AT-72 (auto-land + agency_id fix), AT-74 (staleness + manual-placement
> protection), AT-76 (nav into Real Estate menu).

---

## 1. WHAT IT DOES — the doctrine

A **buyer = a `Contact` (`is_buyer=true`) + at least one *countable* `ContactMatch` (wishlist)**.
`contacts.buyer_state` **IS the pipeline** (new / warm / cold / lost) — there is no separate pipeline
table. A wishlist becomes "countable" (AT-71) when it presents at least `min_countable_criteria` distinct
criteria groups; only countable buyers are counted in figures and cached for matching. The pipeline is
**three-audiences-one-truth**: agent, branch manager, and principal all see the same `buyer_state` rows,
filtered only by a workspace scope.

---

## 2. ENTRY POINTS

### Routes (`routes/web.php`)
| Route | Name | Handler |
|-------|------|---------|
| `GET /buyers/pipeline` `:1253` | `command-center.buyers.pipeline` | `BuyerPipelineController@index` (the board) |
| `PATCH /buyers/{contact}/state` `:1278` | `command-center.buyers.update-state` | `@updateState` (manual move — writes `manual_override`) |
| `GET /buyers/{contact}` `:1254` | `command-center.buyers.show` | `BuyerDetailController@show` |
| `mark-lost` `:1265`, `reengage` `:1266`, `playbook-action` `:1264`, wishlist add/update/primary/archive `:1259-1262` | — | `BuyerDetailController` |

Controller `app/Http/Controllers/CommandCenter/BuyerPipelineController.php`: `index()` `:16` (base query
`Contact::buyers()->with(['createdBy','matches'])` `:28`; kanban grouped by `buyer_state` `:87-92`),
`updateState()` `:112` (validates `state in:new,warm,cold,lost` `:115`, calls
`transitionTo(...,'manual_override', auth()->id())` `:120`). View
`resources/views/command-center/buyers/pipeline.blade.php` (returned `:75,101`; "No core match · not in
figures" tag `:119-123,172-175`).

### Nav (AT-76)
`resources/views/layouts/corex-sidebar.blade.php` — **Real Estate** expandable group `:410-424`; the link
at `:491` (`command-center.buyers.pipeline`, active on `command-center.buyers*`). Comment `:490`:
"AT-76 — Buyer Pipeline lives in Real Estate (was under Dashboard/Command Center). Route unchanged."

---

## 3. THE STATE MODEL — `app/Services/BuyerStateService.php`

States are string literals `new`/`warm`/`cold`/`lost` (`contacts.buyer_state` varchar(20) nullable).

| Method | Line | What |
|--------|------|------|
| `resolveState()` | `:16` | `null` if not buyer `:18`; `new` if no `last_activity_at` `:22-24`; else `≤buyer_warm_days`→warm `:31`, `≤buyer_cold_days`→cold `:34`, else `lost` `:37`. **`buyer_lost_days` is NOT used here** — anything past cold is lost. |
| `transitionTo()` | `:43` | no-op if unchanged `:47`; `updateQuietly(['buyer_state'])` `:51`; writes `BuyerStateTransition` **with explicit `agency_id` `:59`** (AT-72 fix); on auto→lost inserts `buyer_lost_records` `:69-87` |
| `landOnPipeline()` (AT-72) | `:159` | sets `is_buyer=true` + stamps `buyer_pipeline_entered_at` once `:163-173`; **only lands `new` if `buyer_state` is currently null `:177-179`** (never resets Warm/Cold/Lost); idempotent; reason `wishlist_created` |
| `recomputeState()` (AT-74) | `:194` | early-returns if `isManualPlacementProtected()` `:196-198`; else transitions to `resolveState()` (reason `auto_recompute`) |
| `markActivity()` | `:93` | ensures `is_buyer` `:103-109`, writes `BuyerActivityLog` `:112-122`, sets `last_activity_at=now()` `:125`, recomputes (reason `first_activity` if prior null) |
| `isManualPlacementProtected()` (AT-74) | `:220` | finds latest `manual_override` transition (`withoutGlobalScopes()` `:222-226`); window = `buyer_cold_days`; protected if last manual move within that window `:235` |

Thresholds from `AgencyContactSettings::forAgency()` `:27,232`.

---

## 4. AUTO-LAND — `app/Observers/ContactMatchObserver.php`

`created()` `:71` (insert only — wishlist edits never re-land/reset). Guards re-entry `:73`, missing
`contact_id` `:76`. **Countable gate:** `if (!$m->isCountable()) return;` `:81` — empty/uncountable
wishlists do NOT land a buyer. Loads the contact dropping Agency/Branch scopes but keeping SoftDeletes
`:89-94`; stamps the triggering user only if a staff `User` `:99`; dispatches
`landOnPipeline($contact, 'wishlist_created', $userId)` `:101`; whole block try/catch → `Log::warning`
`:102-108` so a failure never breaks the wishlist save. (`saved()`/`deleted()` `:118/151` dispatch
`RegenerateBuyerMatchesJob` for cache freshness — see `market-intelligence.md`.)

**Backfill command** `app/Console/Commands/BuyersAutolandPipelineCommand.php` (`buyers:autoland-pipeline
{--agency=} {--dry-run}` `:45-47`): per-agency `:68-75`; selects contacts with ≥1 countable wishlist
(`ContactMatch::countable($agencyId)` `:88-95`) that have `whereNull('buyer_state')` `:103-106`; lands via
`landOnPipeline($contact, 'auto_landed')` `:121`. Idempotent, **manual-only — not in deploy.sh** `:35`.

---

## 5. DATA IT READS / WRITES

| Target | Where | Notes |
|--------|-------|-------|
| `contacts.buyer_state`, `is_buyer`, `last_activity_at`, `buyer_pipeline_entered_at`, `buyer_pipeline_notes` | migration `2026_05_05_000020_buyer_crm_foundation.php:15-23` (index `(agency_id,is_buyer,buyer_state)` `:22`); `Contact.php:49-50,64-66` | the pipeline state |
| `buyer_state_transitions` | original `2026_05_05_000020...:47-55` (`reason` ENUM `:52`); **`agency_id` added** `2026_05_23_030800_...` (backfill `:16-18`, NOT NULL `:33`); ENUM extended (AT-72) `2026_06_21_041100_...` adds `wishlist_created`/`auto_landed` `:21,27` | append-only audit; model `BuyerStateTransition.php` (`BelongsToAgency`, `$timestamps=false` `:13`) |
| `buyer_lost_records` | `BuyerStateService.php:70-87` | on auto→lost |
| `contact_matches` (countable wishlist) | `ContactMatch.php:354-462` | the buyer-qualifying input |
| `BuyerActivityLog` | `BuyerStateService.php:112-122` | activity audit |

---

## 6. CRON / STALENESS

`routes/console.php:156`: `buyers:recompute-states` scheduled `dailyAt('04:00')` (`onOneServer`,
`withoutOverlapping`). Command `app/Console/Commands/RecomputeBuyerStates.php`: loads all live buyers
(`withoutGlobalScopes()` `:19-23`); **respects recent agent action** — skips
`isManualPlacementProtected()` `:32-35` (within `buyer_cold_days` of a manual move); else transitions
stale buyers via `resolveState → transitionTo(...,'auto_recompute')` `:40-47`. Recent *activity* is
respected implicitly because `resolveState` keys off `last_activity_at` (`BuyerStateService.php:29`).

---

## 7. AGENCY SETTINGS / CONFIG — `app/Models/AgencyContactSettings.php`

| Setting | Default | Reader |
|---------|---------|--------|
| `buyer_warm_days` | 14 | `BuyerStateService.php:31` |
| `buyer_cold_days` | 30 | `BuyerStateService.php:34`, protection window `:232-233` |
| `buyer_lost_days` | 60 | (defined `:81`; **not used in resolveState** — see §9) |
| `min_countable_criteria` (AT-71) | `['any']` | `ContactMatch.php:380,414` (`minCountableFor()` cache `:124`) |
| `buyer_pipeline_default_scope` | `own` | `BuyerPipelineController::defaultPipelineScope()` `:135-138` |

Defaults in `forAgency()` `:69-90`.

---

## 8. THREE-AUDIENCES-ONE-TRUTH

Same `contacts.buyer_state` is the single source; only the **workspace filter** differs
(`BuyerPipelineController`): `defaultPipelineScope()` `:128-139` — admin/owner default `agency` (see all);
others get `buyer_pipeline_default_scope` (default `own`). `applyPipelineScope()` `:144-159` — `own` →
`created_by_user_id = user`; `branch` → users in `effectiveBranchId()`; `agency` → no extra filter.
`stateCounts()` `:161-173` applies the identical scope so header totals match columns. Agent/BM/principal
see the same rows, filtered by `?scope=`.

---

## 9. KNOWN FRAGILITIES

1. **agency_id-null transition bug (AT-72 — FIXED).** `BelongsToAgency` auto-stamps only under an
   authenticated user (`Concerns/BelongsToAgency.php:32-46`); the cron + observer run with no auth → the
   NOT-NULL `buyer_state_transitions.agency_id` insert would 1364-fail in multi-agency DBs. Fixed by
   explicit `'agency_id' => $contact->agency_id ?? 1` (`BuyerStateService.php:59`) covering transitionTo,
   markActivity, recompute, landOnPipeline. **The `?? 1` fallback is itself a latent fragility** for a
   contact with null `agency_id`. Schema: `2026_05_23_030800` + ENUM `2026_06_21_041100`.
2. **Staleness vs agent action.** The nightly recompute would clobber a manual placement; mitigated by
   `isManualPlacementProtected()` (`:220-236`) consulted at `RecomputeBuyerStates.php:32` and
   `BuyerStateService.php:196`. Window = `buyer_cold_days`.
3. **Protection depends on the `manual_override` reason being written.** Only
   `BuyerPipelineController::updateState` (`:120`) writes it. Detail-side `mark-lost`/`reengage`
   (`BuyerDetailController`) must also use `manual_override` or those agent actions are NOT protected —
   **(TODO: verify BuyerDetailController writes `manual_override`).**
4. **Protection lapses + non-re-derivable `new`.** After `buyer_cold_days` with no further manual move or
   activity, an intentionally-parked buyer resumes auto-decay. And `resolveState` never returns `new` once
   `last_activity_at` is set, so a manual reengage-to-`new` cannot be re-derived by the cron — protection
   is the only thing holding it.
5. **Countable gate edge cases.** PHP `isCountable()` (`ContactMatch.php:377`) and SQL
   `countableGroupSql()` (`:442`) are parallel implementations that must stay in lock-step. `landOnPipeline`
   fires only from `created()` (`ContactMatchObserver.php:71,81`) — a wishlist created empty then later
   edited to become countable will NOT auto-land; the backfill command is the only recovery. `hasCountableWishlist()`
   (`Contact.php:241`) drives the "No core match · not in figures" tag — a landed buyer can sit on the
   board yet be excluded from match figures (intentional but easily misread).

---

## Key file:line index
- `app/Services/BuyerStateService.php` — `:16` resolveState, `:43` transitionTo (`:59` agency_id fix),
  `:93` markActivity, `:159` landOnPipeline, `:194` recomputeState, `:220` isManualPlacementProtected.
- `app/Observers/ContactMatchObserver.php` — `:71-108` auto-land.
- `app/Console/Commands/RecomputeBuyerStates.php` `:32-47`; `BuyersAutolandPipelineCommand.php:45-121`; `routes/console.php:156`.
- `app/Http/Controllers/CommandCenter/BuyerPipelineController.php` — `:16,112,128-173`.
- Migrations: `2026_05_05_000020_buyer_crm_foundation.php`, `2026_05_23_030800_add_agency_id_to_buyer_state_transitions_table.php`, `2026_06_21_041100_add_autoland_reasons_to_buyer_state_transitions.php`.
