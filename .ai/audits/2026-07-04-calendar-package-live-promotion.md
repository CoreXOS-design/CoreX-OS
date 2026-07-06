# Calendar Package — Live Promotion Report

**Date:** 2026-07-04
**Lane:** promotion lane (Johan-authorized live promotion)
**Live HEAD after:** `e84eaee4`  ·  **Pin:** `8cc70fa8`  ·  **Merge-base:** `53463bb9`

---

## 1. Pin

Polled `origin/Staging` until the guided-tour commit landed and settled:

- Tour commit: `a1b9f7b8` — `feat(AT-164): guided tour of the new calendar cockpit`
- 3 trailing **docs-only** commits (tour headless proof `cf1df3ff`, BUILD_STANDARD QA-gate `4d94f0ba`, CHAT_STARTER qatesting1 `8cc70fa8`)
- **PIN = `8cc70fa8`** (current stable tip; verified stable across a 100s re-poll — no cc2/cc3 merge mid-flight)

The QA-gate doc (`4d94f0ba`) describes a *forward-flow* process (build → QA1 → Staging → live) for future work; it does **not** require this already-Johan-QA'd calendar package to re-run QA1. Inert.

## 2. Payload audit (`origin/main..8cc70fa8` = 60 commits + tour family)

| Group | Commits | Disposition |
|-------|---------|-------------|
| AT-164 calendar cockpit / gates / rounds / week-stream / **tour** | ~37 | LIVE (announced) |
| AT-178 event reminders | 2 | LIVE (announced) |
| AT-164 day-diff humaniser fix | 1 | LIVE (announced) |
| AT-158 DR2 WS6/7/8 + WS-R1/R2/R3 + complete-with-reason + iCal | ~10 | LIVE but **dormant** (BM/admin-gated) |
| AT-167 misfiled docs | 1 | already on live (cherry-pick `f81b3657`) |
| AT-172 perf docs, AT-173/AT-177 specs | 3 | docs/specs, inert |
| matches daily-digest | 1 | **already live** (git-parity only) |
| 180e875a "fix" (Andre — leave/contact-dup/contact-show) | 1 | trivial, rode along |
| **AT-165 offline-draft-persistence** | 3 | **EXCLUDED per Johan** (see §3) |

**No unexplained commits.** Only genuine surprise = AT-165 (new-to-live global-layout JS + API route). Escalated to Johan → ruled **Exclude AT-165 only**.

## 3. AT-165 exclusion (Johan ruling)

- Full-Staging merged into main (`d4275111`), then **reverted AT-165's 2 code commits** (`f38783c7`, `2f50ef05`) → `7094b003`.
- Verified: no `draft-persistence`/`resilient-submit`/`session-keepalive`/`CoreXDraft`/`data-draft`/`session-ping` remnants anywhere in `app/ resources/ routes/ config/`.
- **AT-178 preserved intact:** `reminder-toast` include (layout:148) + `/reminders/*` routes (api.php:426–430) present.
- `resources/views/corex/properties/create-edit.blade.php` kept absent (removed from the codebase independently of AT-165; absent on both main and Staging).

## 4. Migrations run on live (8, timestamp-ordered, all DONE)

```
2026_07_03_400000 create_deal_step_escalations_table
2026_07_03_500000 seed_deals_v2_view_overview_permission        (perm: admin/BM/super_admin only)
2026_07_04_100000 add_deal_v2_bm_approval_enabled_to_agencies
2026_07_04_120000 add_reminder_config_and_occurrence_key         (reminder_channels/offsets, occurrence_key+snoozed_until+UNIQUE, class defaults, agency lead options)
2026_07_06_000001 add_calendar_deck_settings_to_agency_contact_settings
2026_07_06_000002 add_deck_layout_to_calendar_user_preferences  (calendar_deck_layout, calendar_layers)
2026_07_06_000003 seed_calendar_my_deals_tile_permission        (perm: NO grants — FLAGGED HIDDEN)
2026_07_07_000001 add_cockpit_layout_to_calendar_user_preferences (calendar_cockpit)
```
All target columns verified present post-migrate.

## 5. Permission diff (no agent broadening)

| Key | Live grants after | Note |
|-----|-------------------|------|
| `deals_v2.view_overview` | super_admin, admin, branch_manager (7 rows) | role-filtered clone of `manage_pipeline`; staging's stray agent/office_admin grants deliberately excluded |
| `deals_v2.manage_pipeline` | super_admin, admin, branch_manager (7 rows) | unchanged |
| `calendar.tile.my_deals` | **none** | seeded, granted to no role; owner-bypass renders empty tile |

`sync-permissions --merge-defaults` (via `deploy:sync-reference-data`) reported **0 created / 0 updated / 0 new grants** — migrations already carried the exact distribution.

## 6. Deploy sequence (`/corex`, php8.3 pool)

git ff-pull → `migrate --force` → `deploy:sync-reference-data` (47 calendar class settings) → view/route/config clear → reload php8.3-fpm → `queue:restart` + `supervisorctl restart corex-worker-live:*`. Live **`schedule:run` cron confirmed present** (per-minute — required by `command-center:reminders`).

**Env-parity:** live=php8.3.31, staging=php8.2.31 (known drift). Staging has `igbinary`+`redis` that live lacks — live uses **all-database drivers** (cache/queue/session) and no promoted code references them → left uninstalled, noted (BUILD_STANDARD §8).

## 7. In-flight regression — FOUND + FIXED (`e84eaee4`)

**Symptom:** `/corex/command-center/calendar` → 500 for owner accounts.
**Cause:** `CalendarController::sharedViewData()` (AT-178) → `AgencyContactSettings::forAgency((int)($user->agency_id ?: 0))`. A global super_admin has null agency_id → 0; `forAgency()` `firstOrCreate`'d an `agency_id=0` row → SQLSTATE 23000 (1452 FK) → 500. Impact = 2 live super_admins; agency-scoped users unaffected (why QA missed it).
**Fix (root-cause class):** `forAgency()` never persists for `agency_id <= 0` — returns an unsaved defaults instance (every accessor is null-safe). Fixes the whole class (any owner-facing `forAgency` caller). Regression test `AgencySettingsNullAgencyTest` 3/3 green. Cherry-picked to Staging (`6678592a`).

## 8. Live verification

| Check | Result |
|-------|--------|
| Calendar cockpit (agency admin) | **200** (746 KB) |
| Calendar cockpit (null-agency owner) | **200** (1.2 MB) — post-fix |
| `forAgency(0)` on live | unsaved, no `agency_id=0` row, leadOptions `[0,5,10,15,30,60,120,1440]` |
| Reminder poll `GET /api/v1/command-center/reminders/due` | **200** `{"reminders":[]}` |
| Tour anchors (`data-tour`) + trigger in rendered calendar | present |
| Save/Reset + New Event + reminder field in cockpit | present |
| Humanised to-do titles | 1839 repaired; **0** negative/fractional-day titles remain |
| DR1 `deals` | **131** (unchanged) · `deals_v2` = 0 (dormant) |
| dashboard / contacts / properties / document-types / backups / misfiled-docs / deals-v2 / overview / create (agency admin) | all **200**, no 500s |
| owner 302s (contacts/properties/document-types) | → `/agency/select` (intentional multi-agency gate, not an error) |

## 9. Superset & Jira

- **Staging ⊇ main** in content, sole intentional divergence = main's AT-165 exclusion (Staging retains AT-165 for the QA1 flow). Hotfix present on both branches.
- AT-164 → **Production** (+comment) · AT-178 → **Production** (+comment) · AT-158 → **In Progress retained**, DR2-live-dormant comment posted.

## 10. Open items / follow-ups

- DR2 remediated capture remains subject to Johan's side-by-side QA before any DR1→DR2 cutover or announcement.
- AT-165 to re-enter via the QA1 → Staging → live flow.
- Staging host (`/corex-staging`) carries the `forAgency` fix on the branch (`6678592a`) — pull on next staging deploy so staging QA doesn't hit the same owner-calendar 500.
