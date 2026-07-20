# AT-321 — Property Audit Trail: log EVERY change, always attributable, never bypassable

> **Status:** SPEC — awaiting Johan's sign-off. **NO code until approved.** QA1 only when built.
> **Author:** cc1. **Date:** 2026-07-20.
> **Mandate (Johan):** the property audit trail must log *any and every* change on a property,
> always with WHO (or a clear system/source label) and WHEN. No event allow-list, no observer
> bypass, no silent "System".
> **Origin:** investigation `.ai/audits/` — property #3492 ("14 Aride" / Aride Genoeg unit 14) was
> reassigned Shawn Du Bois (#26) → Elise McFarlane (#39) with **zero** audit trail; the actor is
> unrecoverable because no row was ever written.

---

## 1. Problem statement (what's broken today)

Two independent failure modes leave changes unlogged or unattributed:

**A. Event allow-list — most edits are never audited.**
`PropertyObserver::saved()` only logs 5 fields:
- `app/Observers/PropertyObserver.php:270` `price` → `logPriceChange`
- `:273` `status` → `logStatusChange`
- `:276` `agent_id` → `agent_assigned`
- `:284` `compliance_snapshot_at` → `logComplianceSnapshot`
- `:299`/`:314` `published_at` → website published/unpublished

Everything else — address, description, beds/baths, features, mandate_type, suburb, erf, price_on_application, etc. — changes **with no audit row**. (`$auditFields` at `PropertyObserver.php:72` is the same short list feeding `saving()`'s originals capture.)

**B. Observer bypass — whole write paths produce no row at all.**
Any write that does not go through an Eloquent `save()`/`update()` skips the observer entirely:
- **Raw `DB::table`** — `app/Services/Admin/AgentDeletionService.php:148,154,261,266,288` (`->update(['agent_id' => …])` agent-merge/reassign).
- **`updateQuietly()` / `saveQuietly()`** (by design suppress observer events):
  - `app/Console/Commands/ReconcileP24PortalPresence.php:116` (`p24_syndication_status`)
  - `app/Console/Commands/Properties/ReconcilePropertyAddresses.php:200,202`
  - `app/Services/Properties/SoldPropertyImporter.php:157` (`saveQuietly`), agent set at `:207`
  - `app/Services/Syndication/Property24/Property24SyndicationService.php:749` (P24 cache stamp)
- **Jobs using `save()` but with no authenticated user** → observer fires but actor is `null` →
  the row (if any) shows a blank **"System"** with no context:
  - `app/Jobs/ConfirmP24PropertyRowJob.php:78` sets `agent_id`, `:108`/`:118`/`:138` `->save()`.

**C. Actor resolution is a dead end when null.**
`PropertyAuditService::log()` resolves the actor as `auth()->user()` only
(`app/Services/Audit/PropertyAuditService.php:21`). For jobs/imports/console/bypass writes that is
`null`, and the UI prints a bare **"System"** with no source label
(`resources/views/corex/properties/show.blade.php:4611`; CSV `PropertyController.php:492`).

**D. The exact #3492 path.**
The reassignment left **no** `property_audit_log` row (only its `property_created` row, `user_id=NULL`),
and no row in `agent_activity_events`, `daily_activity_entries`, `domain_event_log`, `automation_log`,
`tool_history_entries`, nor any `*_by` column on `properties` (there is none). It was **not**
agent-deletion (Shawn #26 is still active and still holds 126 listings). The write therefore came
through a **quiet/raw** path (updateQuietly / DB::table / an import-apply job) — exactly the class in
(B). **Because the data was never written, the specific tool cannot be proven from logs** — which *is*
the defect. The fix must make the path irrelevant: capture at the lowest layer so nothing can escape,
regardless of which tool writes.

**E. UI cap.**
The History tab is real and visible to any user who can open the property
(route `corex.properties.` group gated `permission:access_properties` + `agency.required`,
`routes/web.php:2708`; tab `show.blade.php:992` / `:4583`), but it is **capped at 50 rows**
(`PropertyController.php:461-465` `->limit(50)`), so it is not the *full* trail. (CSV export
`:487-497` is already unlimited.)

---

## 2. Pillars & multi-tenancy

- **Property** (primary — the audited subject). **Agent/User** (the actor).
- Every audit row is filed under the **property's** `agency_id` (never a hardcoded `1`) — the existing
  Rule-17 guard at `PropertyAuditService.php:28-41` is retained and extended to all new writers.

---

## 3. Design — four coordinated changes

### 3.1 LOG EVERYTHING — generic dirty-field diff (Mandate #1)

Replace the 5-field allow-list in `PropertyObserver` with a **generic diff over `getChanges()`**:

- `saving()` captures originals for **all** columns (not just `$auditFields:72`).
- `saved()` (existing records only): keep the three **rich** dedicated events for nice summaries —
  `price_changed`, `status_changed`, `agent_assigned` — **plus** emit **one consolidated
  `property_updated` row** carrying every *other* changed, non-excluded column as
  `old_values`/`new_values` maps (one entry per edit = "Elise changed description, beds, levy").
- `compliance_snapshot_at` / `published_at` keep their dedicated semantic events.

**Explicit exclusion list (do NOT log — pure timestamps / derived cache / sync-stamps / signatures):**
```
updated_at, created_at, last_activity_at, last_cma_at, last_cma_presentation_id,
sg_last_searched_at, geo_resolved_at, first_marketed_at,
p24_last_submitted_at, p24_activated_at, p24_images_last_synced_at,
p24_listing_last_synced_at, p24_stats_synced_at, p24_image_signature, p24_last_error,
pp_last_submitted_at, pp_activated_at, pp_images_last_synced_at,
pp_listing_last_synced_at, pp_last_error, pp_listing_feed_ref,
p24_syndication_status, pp_syndication_status   (already covered by logSyndication; exclude from generic to avoid dupes)
```
> Everything **not** on this list is "meaningful" and logs. The list is the ONLY allow-list that
> remains, and it is an *exclusion* list of noise — the inverse of today. Johan approves the final list.

Rendering stays human-readable: dedicated events have summaries; the consolidated row lists field
labels (map DB column → friendly label, reuse the property form's label map if one exists).

### 3.2 CLOSE EVERY BYPASS — the core fix (Mandate #2)

**Recommended: Option 3 (hybrid), belt-and-suspenders, matches CoreX house style.**

1. **App layer (rich, primary):** the generic observer diff (3.1) captures every Eloquent
   `save()`/`update()` — the normal path. For the legitimate **quiet** sites, introduce a single
   helper `Property::auditedQuietUpdate(array $attrs, ?AuditActor $actor)` that performs the quiet
   write **and** writes the audit row explicitly. Convert the known raw/quiet sites (B) to it, or add
   an explicit `PropertyAuditService::log()` call beside them:
   - `AgentDeletionService.php:148,154,261,266,288` → explicit `agent_assigned` rows, source `agent-merge`.
   - `ReconcileP24PortalPresence.php:116`, `ReconcilePropertyAddresses.php:200,202`,
     `SoldPropertyImporter.php:157/207`, `Property24SyndicationService.php:749` → audited-quiet-write
     (or excluded per 3.1 if pure cache stamp).
   - `ConfirmP24PropertyRowJob.php:78/108` → runs through the observer already; the fix is actor/source
     (3.3), so the null-actor row becomes `source: P24 import`.

2. **Structural gate (prevent future bypass):** add a `scripts/dev-check.ps1` gate — same pattern as
   the existing e-sign pipeline gate — that **fails CI** if a diff introduces
   `DB::table('properties')->update(`, `->updateQuietly(` or `->saveQuietly(` on a `Property`
   outside the `auditedQuietUpdate()` helper without an accompanying audit test. This locks the
   discipline structurally, not by memory.

3. **Unbypassable runtime backstop:** a MySQL **`AFTER UPDATE` + `AFTER INSERT` trigger** on
   `properties` that writes a minimal `property_audit_log` row for any changed row **regardless of
   write path** (Eloquent, quiet, raw `DB::table`, or manual SQL). It stamps actor from a MySQL
   session variable `@corex_actor_id` / `@corex_actor_label` (set per-request/job/console — see 3.3),
   and writes `event_type='property_updated'`, `event_category='system'`, `source='db-trigger'`.
   To avoid **double-logging** with the app layer, the app layer sets a per-connection flag
   `@corex_app_logged=1` for the current statement; the trigger writes **only when the flag is unset**
   (i.e. only for writes the app layer didn't already record). Net: rich attributed rows on known
   paths, a guaranteed minimal row on every unknown/raw path.

**Trade-offs (for Johan's decision):**
- *Option 1 (app-layer only)* — simplest, richest, pure PHP/testable, **but cannot catch future
  `updateQuietly`/`DB::table` writes** → violates "cannot be bypassed". **Rejected as the sole fix.**
- *Option 2 (DB trigger only)* — truly unbypassable, but rows are DB-generated (thin summaries), actor
  depends on the session var, harder to unit-test. Acceptable if Johan wants minimum surface.
- *Option 3 (hybrid, recommended)* — rich when possible, guaranteed always; cost is the dedupe flag and
  keeping the trigger in the schema snapshot. **Recommended** — it is the only option that is both
  attributable *and* unbypassable.

> **Robustness caveat on the trigger (see §3.5):** the trigger runs inside the same transaction as the
> UPDATE, so a trigger error would roll back the property save. It MUST therefore be a bare `INSERT`
> into `property_audit_log` with no derived logic and only nullable/guaranteed columns — nothing that
> can fail. If Johan judges that risk unacceptable, fall back to Option 1 + the CI gate (accepting that
> a brand-new raw path could slip until the gate catches it in review).

### 3.3 ALWAYS CAPTURE ACTOR OR SOURCE (Mandate #3)

- New `App\Support\Audit\PropertyAuditContext` resolves the actor in priority order:
  1. `auth()->user()` → `user_id` + `actor_label = user->name`, `actor_type = 'user'`.
  2. An explicit actor/source pushed by the entry point (job/console/raw site).
  3. A framework-derived source (see below).
  4. **Never blank** — if all else fails, `actor_label='unattributed'`, `actor_type='unknown'`, and a
     WARNING is logged + alerted (§3.5) so the gap is visible, never silent.
- **Source labels** are set at the edges:
  - **HTTP** middleware stamps the authenticated user (web/mobile/api) into the context + `@corex_actor_*`.
  - **Jobs** — a `WithAuditSource` trait / `SetsAuditContext` job-middleware sets e.g. `source: 'P24 import'`,
    `'bulk reassign'`, `'address reconcile'`, `'sold import'`.
  - **Console** — command bootstrap sets `source: 'console:<signature>'`.
  - **Explicit raw sites** pass a literal: `'agent-merge'`, `'P24 sync'`, etc.
- **Schema change** — new migration adds to `property_audit_log`:
  - `actor_type` (string 20: `user|system|import|console|sync|portal|db-trigger|unknown`)
  - `actor_label` (string 120, nullable)
  - `source` (string 60, nullable)
  (Mirrors the existing `P24PortalEvent` precedent `actor_type`/`actor_label`, `app/Models/P24PortalEvent.php:20-21`.)
  Backfill existing `user_id IS NULL` rows to `actor_label='System (pre-AT-321)'`, `actor_type='system'`.
- **UI/CSV** print `actor_label` (+ `source` in the detail expander), never a bare "System".

### 3.4 REMOVE THE 50-ROW CAP (Mandate #4)

- `PropertyController.php:461-465` `->limit(50)` → `->paginate(50)` (or cursor-paginate for very long
  histories), exposing the full trail.
- `show.blade.php:4599` `@forelse($fullAuditLog …)` → render the paginator (page links / "Load more").
  Keep the unlimited CSV export (`PropertyController.php:487-497`).
- Confirm (no change needed): the History tab is already visible to **any user** with
  `permission:access_properties` who can open the property (`routes/web.php:2708`). Verify a long log
  renders cleanly (pagination prevents a multi-thousand-row DOM).

### 3.5 ROBUSTNESS — audit failure must never break a save, and never be silent (Mandate #5)

- Every audit write is wrapped so a throw **cannot** break or 500 the property save. The observer
  already wraps (`PropertyObserver.php:317-319`); extend the same guarantee to `auditedQuietUpdate()`,
  the explicit raw-site calls, and the context resolver.
- A swallowed failure is **recorded and alerted**, never lost: on catch →
  `Log::channel('property_audit')->error(...)` **and** dispatch a `PropertyAuditWriteFailed` domain
  event (per Non-Negotiable #9) so monitoring surfaces it. (Optional: a lightweight failure counter.)
- DB-trigger path: bulletproof bare `INSERT` only (§3.2 caveat) so it cannot roll back a save.
- Rule-17 skip (no agency to file under) stays a logged WARNING, not a silent drop
  (`PropertyAuditService.php:29`).

---

## 4. Acceptance criteria

1. Editing **any** non-excluded property field writes an audit row with `old→new` and a real actor.
2. **No write path escapes:** agent-merge (`AgentDeletionService`), quiet reconcilers, importer/sync
   quiet saves, and a raw `DB::table('properties')->update()` all produce an audit row.
3. **Every** row has `user_id` **or** a non-blank `actor_label`+`source` — never a contextless "System".
4. The History tab shows the **full** trail (paginated), visible to any `access_properties` user; CSV full.
5. A property save **still succeeds** if the audit write throws; the failure is logged + alerted.
6. The dev-check gate fails a diff that adds an unaudited raw/quiet property write.

## 5. Files to create / modify

**Create**
- `database/migrations/xxxx_add_actor_columns_to_property_audit_log.php` (actor_type, actor_label, source + backfill)
- `database/migrations/xxxx_add_property_audit_trigger.php` (if Option 2/3 approved — trigger + drop in `down()`)
- `app/Support/Audit/PropertyAuditContext.php` (actor/source resolver + context stack; sets `@corex_actor_*`)
- `app/Http/Middleware/SetPropertyAuditActor.php` (or fold into an existing global middleware)
- Job middleware / trait `SetsAuditContext`
- `tests/Feature/Properties/Audit/*` (see §6)

**Modify**
- `app/Observers/PropertyObserver.php` (`:72` originals → all columns; `:262-320` generic diff + keep 3 rich events)
- `app/Services/Audit/PropertyAuditService.php` (`:11-51` actor/source resolution, actor_label/source, robust wrap)
- `app/Models/Property.php` (add `auditedQuietUpdate()` helper)
- `app/Services/Admin/AgentDeletionService.php` (`:148,154,261,266,288` explicit audit)
- `app/Console/Commands/ReconcileP24PortalPresence.php:116`, `Properties/ReconcilePropertyAddresses.php:200,202`,
  `app/Services/Properties/SoldPropertyImporter.php:157,207`, `app/Services/Syndication/Property24/Property24SyndicationService.php:749`
  (audited-quiet-write or confirmed exclusion)
- `app/Http/Controllers/CoreX/PropertyController.php:461-465` (remove `limit(50)` → paginate)
- `resources/views/corex/properties/show.blade.php:4599+` (paginated render; show `source`)
- `scripts/dev-check.ps1` (new raw-property-write gate)
- `app/Console/Kernel.php` / bootstrap (console audit source)
- `database/schema/mysql-schema.sql` (`php artisan schema:dump` — Non-Negotiable 12a; trigger travels in snapshot)

## 6. Tests (single-file targeted per CLAUDE.md #13)

- `GenericFieldDiffTest` — edit description/beds/levy → one `property_updated` row with old→new; excluded columns produce none.
- `RichEventsStillFireTest` — price/status/agent still get dedicated rows.
- `BypassPathsLoggedTest` — `AgentDeletionService` merge, `updateQuietly` reconcile, and a raw
  `DB::table('properties')->update()` each yield an audit row (trigger and/or explicit call).
- `ActorAlwaysPresentTest` — web edit → `user_id` set; job/import/console → `actor_label`+`source` set, never blank.
- `SaveSurvivesAuditFailureTest` — mock audit throw → property save commits; `PropertyAuditWriteFailed` dispatched + error logged.
- `HistoryFullTrailTest` — 60 rows → History paginates and shows all; visible under `access_properties`.
- After adding migrations: `php artisan schema:dump` and commit the snapshot in the same commit.

## 7. Prevent-or-absorb (BUILD_STANDARD §3) & deploy

- **Absorb:** any audit-write error is absorbed (save always wins) and surfaced (logged+alerted).
- **Prevent:** the dev-check gate prevents new unaudited raw writes at review time.
- **Deploy (QA1 only until Johan promotes):** migrate → `deploy:sync-reference-data` →
  view/route/config clear → reload fpm → restart worker; re-run `schema:dump`; verify a fresh QA1
  edit + a QA1 raw write both log with actor/source. Update `.ai/CHAT_STARTER.md`.

## 8. Deliberately NOT in this ticket

- No new "settings" (nothing for the Setup Wizard / Non-Negotiable #10a).
- No retroactive attribution of past unlogged changes (e.g. #3492) — that data never existed; the
  guarantee is **from this fix forward**. State this to Johan explicitly.
- Audit trails for other pillars (Contact/Deal) are out of scope — Property only.
