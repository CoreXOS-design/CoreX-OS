# agency_id NOT-NULL Landmine Register — live sweep 2026-07-07

**Trigger:** AT-202 (seller + buyer public links 500'd on `SQLSTATE 1364 Field 'agency_id' doesn't have a default value`). Johan asked for the full pending-migration / landmine sweep across live.

**Method:** `migrate:status` on `/corex`; 6-day live `laravel.log` (2026-07-01 → 07-07) grepped for `1364 ... agency_id`; per erroring table the writer site + code-state resolved; proactive raw-`DB::table()->insert` sweep across the whole `add_agency_id_*` wave; `information_schema` nullability confirmation. **Read-only — no migrations run, no fixes applied.**

---

## Headline findings

1. **Zero migrations are pending on live.** The six-week backlog is fully migrated (all `Ran`). The "pending migration landmine" as literally framed does not exist — but the wave already **detonated in an earlier deploy** and has been bleeding latent 500s ever since.

2. **The AT-202 timeline was wrong.** The `agency_id` columns did **not** land today. The whole multi-tenancy wave is **batch 118–119** (the `wave3b` lineage, ~2026-05-23), which ran weeks ago — far below today's 155/163–182 batches. `property_health_scores` has been throwing this error since the log's start (07-02) and earlier. Seller/buyer links only *appeared* today because that's when a client first clicked them; the column was already NOT NULL. **Correction: AT-202's fix is correct; its "landed today" framing is not.**

3. **This is a broader class than AT-202, and it is already being remediated writer-by-writer.** Over the log window, several writers were fixed mid-week (their errors stop dead): `property_health_scores` (07-06), `contact_match_notifications` (07-03), `calendar_event_audit_log` (07-06). AT-202 fixed two more today. **Five writers remain open.**

4. **The class has two sub-shapes:**
   - **(a) Raw `DB::table()->insert()`** — bypasses the `BelongsToAgency` `creating` hook entirely (public pages, some console cmds). AT-202's two sites; still-open: buyer respond, buyer risk scores, presentation snapshot.
   - **(b) `BelongsToAgency` Eloquent model written from console/queue/observer** — the trait stamps from `Auth::user()->agency_id`, which is **null with no request**; the single-agency safety-net is **disabled on live because there are 2 agencies**. Still-open: agent scorecards.

5. **Reverse check (code expecting a not-yet-run migration) = CLEAN.** With 0 pending migrations, no live code path can read a column/table that doesn't exist yet. No latent 500s in that direction.

---

## Register — confirmed from live errors (Jul 1–7)

| Table | Errors | Last seen | Writer | agency_id source | Verdict |
|---|---|---|---|---|---|
| `property_health_scores` | 6652 | 07-06 02:00 | `PropertyHealthCalculator` (nightly svc) | `$property->agency_id` | ✅ **FIXED** — stamps explicitly; **0 errors at today's 02:00 run** |
| `agent_scorecards` | 138 | **07-07 02:30 (today)** | `AgentScorecardCalculator:113` (console) | `$user->agency_id` | 🔴 **OPEN — ACTIVE** (erroring every night) |
| `contact_match_notifications` | 21 | 07-03 12:12 | `MatchPropertyJob:72` (queue) | `$property->agency_id` | ✅ **FIXED** — stamps explicitly |
| `calendar_event_audit_log` | 5 | 07-06 03:01 | `ReconcileCalendarEvents:305` (console) | `$evt->agency_id` | ✅ **FIXED** — stamps explicitly |
| `contact_match_feedback` | 3 | 07-02 16:56 | `SharedMatchController:94` (PUBLIC route) | `$match->agency_id` | 🟠 **OPEN — LATENT** (public shared-match link) |
| `property_seller_link_accesses` | 4 | 07-07 11:17 | `SellerLinkController` | `$link->agency_id` | ✅ **FIXED** — AT-202 today |
| `buyer_portal_links` | 1 | 07-07 11:36 | `routes/web.php:1395` | `$contact->agency_id` | ✅ **FIXED** — AT-202 today |

## Register — proactive (untriggered in window; NOT NULL + unstamped writer)

| Table | Writer | Context | Verdict |
|---|---|---|---|
| `buyer_property_responses` | `BuyerPortalController:74` | **PUBLIC** buyer-portal *respond* (raw insert; next line stamps `BuyerActivityLog` but this insert was missed) | 🟠 **OPEN — LATENT** (same buyer-portal feature as AT-202) |
| `buyer_lost_risk_scores` | `RecomputeBuyerRiskScores:29` | console cmd, appears **unscheduled** (not in 6-day log) | 🟠 **OPEN — LATENT** |
| `property_presentation_snapshots` | `PresentationSnapshotController:57` | authed, **wrapped in try/catch** → fails **silently** (snapshot not saved), no user 500 | 🟡 **OPEN — SILENT** (data loss, not a crash) |
| `calendar_event_links` | `CalendarEventCreator:235` / `CalendarEventService:587` | raw `insert($links)` — each `$links[]` row **includes** `'agency_id' => $agencyId` | ✅ **SAFE** (false positive) |

---

## Open items → fix set (5 writers, all same one-line pattern: stamp agency_id from the subject)

1. **`agent_scorecards`** — `AgentScorecardCalculator:113` `updateOrCreate` → add `'agency_id' => $user->agency_id`. **Priority: highest (live-active, fails every nightly run).**
2. **`buyer_property_responses`** — `BuyerPortalController:74` → add `'agency_id' => $link->agency_id` (buyer_portal_links now carries agency_id post-AT-202). Public buyer-portal path — fold into the AT-202 buyer-portal fix.
3. **`contact_match_feedback`** — `SharedMatchController:94` `updateOrCreate` → add `'agency_id' => $match->agency_id`. Public shared-match link.
4. **`buyer_lost_risk_scores`** — `RecomputeBuyerRiskScores:29` → add `'agency_id' => $buyer->agency_id`. Console; confirm whether it's meant to be scheduled.
5. **`property_presentation_snapshots`** — `PresentationSnapshotController:57` → add `'agency_id' => $agencyId` (already computed one line above; currently only used for the demand call). Stops silent snapshot loss.

**Verdict roll-up:** no migration is unsafe-to-run (all already run); the work is **needs-code-fix** on 5 writers. All are continuations of Andre's `wave3b` multi-tenancy remediation — **coordinate with Andre** so we don't collide, but the fixes are mechanical and unambiguous.

**Recommended guard (class kill, not instance):** a test that asserts every `BelongsToAgency` model with a NOT-NULL `agency_id` column can be created from a no-auth (console/queue) context — or a `migrate`-time check listing NOT-NULL `agency_id` tables whose writers are raw/console. Prevents the next writer from shipping unstamped.
