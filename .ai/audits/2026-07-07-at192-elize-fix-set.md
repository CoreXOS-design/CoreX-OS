# AT-192 — The Elize Fix Set (2026-07-07)

Authorised by Johan ("do the elize fix"). Investigation dossier lives on AT-192;
this file is the remediation record + evidence. Read-only facts gathered against
live agency 1 (`nexus_os`); code fixes proven with targeted tests.

Branches: 1 = Shelly Beach (SH), 2 = Ballito (BA), 3 = Southbroom (SO).
Elize accounts: #23 Elize Reichel (admin, home branch NULL, ACTIVE) · #41 Elize
Southbroom (BM, branch 3, ARCHIVED 2026-06-25) · #42 Elize Ballito (BM, branch 2,
ARCHIVED 2026-06-25).

---

## Step 0 — 21:05 transcription flip (AT-194) — VERIFIED PASS

Log `/opt/corex-transcribe/logs/live-flip-20260706.log`, ran 21:05 SAST.

- Model live: `COREX_TRANSCRIBE_MODEL=large-v3` in `/corex/.env` (mtime 21:05, php8.3-fpm reloaded, worker restarted). Agency 1 language = `af`.
- Re-transcribe: 20 notes regenerated, 0 failed.
- **Forest Walk voice note (comm#285) before → after:**
  - BEFORE (medium/af): *"Andre, ek sê nie bij Sean, nege nine forest walk uit om gerealist… we is op property 24, maar ek kom hier nie op jouw Finders Coastal website nie. Kijk of jy iets kan sê, nege forest walk."*
  - AFTER (large-v3/af): *"Andrei, ek sal gaan hier by Sean, 9-9 Forrest Walk… ek wees op property 24, maar ek kom nie dier op jou om finders coastal website nie, check of jy iets daar kan sien, 9 Forrest Walk."*
  - Recovers "9 Forrest Walk", "property 24", "finders coastal website". VERIFY = PASS.

---

## (a) Wire #23 into Andre's Multi-Branch Manager — DONE (live data)

Mechanism used: `User::syncManagedBranches([2,3], 3, 1)` — the SAME shared method
Andre's self-service panel and admin user-edit screen call (not a raw insert).
Logged as `AT-192.elize_managed_branches_wired`.

Result on live:
- `user_managed_branches`: branch 2 (Ballito, default=0), branch 3 (Southbroom, **default=1**), agency 1.
- `defaultManagedBranchId() = 3`; `isManagerOfBranch(2)=true`, `(3)=true`, `(1)=false`.
- On login the existing `Login` listener seeds `view_as_branch_id = 3`, so Elize opens CoreX already acting as **Southbroom** manager; the branch switcher offers Ballito ↔ Southbroom; DR1 capture defaults to her current branch and stamps `managed_by_user_id`.
- Home branch left NULL by design (she holds `branches.view_all`, so BranchScope is bypassed — NULL hides nothing; the managed-branch default supplies the sane capture default). **Default = Southbroom is adjustable** (matches her last 6 captures); she can switch any time.

## (b) Kill the silent-branch-default CLASS on DR1 capture — DONE (live hotfix)

`DealController::persistDeal()` — after the branch-scope auto-stamp, a server-side
gate now REJECTS any non-branch-scope capture with an empty branch:

> "Please choose the branch this deal belongs to. Your account has no home
> branch, so the branch cannot be filled in automatically."

Also hardened `store()` to re-throw `ValidationException` (it was being swallowed
into a generic "Failed to save deal" message). Normal branch agents are
auto-stamped from their home branch and never reach the gate.
Tests: `DealCaptureBranchRequiredTest` (3 passed) — null-home admin blocked;
null-home admin with a chosen branch saves; branch agent unaffected.

## (c) The R248k report fix — DONE (live hotfix)

Root: `CompanyPerformanceService::getPeriodRollup()` built the branch agent grid
with `where('is_active', 1)`. Archiving #41 (Southbroom, `counts_for_branch_split=1`)
dropped her from the grid and from every total keyed off it — silently removing
**R248,160.53** of real Southbroom take-home.

Fix: the grid now keeps every active split-counting agent PLUS any now-inactive
split-counting agent with a **non-declined deal dated in the period** (period-scoped,
so an idle archived agent never clutters the grid). Rows carry `is_archived`, and
the Admin/BM performance grids badge them "Archived".

Live evidence — Σ `deal_money_lines.agent_net_ex_vat` over the branch-3 grid agents:
- BEFORE fix: grid = [34,35,36,39,44] → **R1,531,880.40** (#41 absent).
- AFTER fix: grid = [34,35,36,39,41,44] → **R1,780,040.93** — #41's R248,160.53 restored.
- #41's 15 lines span 2025-10 → 2026-05; she re-appears (badged Archived) in each of those periods; the finance engine already holds her figures (e.g. 2025-10 `agent_income_ex_vat = 24,939.13`), so both the engine and inline paths pick her up.

**Blast check** (every consumer of the grid / `is_active` agent-set):
- The grid feeds Admin `PerformanceController`, `BranchPerformanceController`, BM `PerformanceController`/`AgentPerformanceController`, both TV controllers, `AgentPerformanceService`, and `CompanyPerformanceLegacyReader` — all read through `getPeriodRollup`/`getBranchRollup`, so all now correctly count archived-but-historical production. Row SHAPE is unchanged (only `is_active`/`is_archived` added), so no consumer breaks.
- `counts_for_branch_split=1` and `whereNotNull('branch_id')` remain hard gates for everyone → #42 (cfbs=0) and #23 (cfbs=0) are still correctly excluded from the split grid; no other agent shifts.
- The only other file pairing `deal_money_lines` + `is_active` is `WorksheetController` (pipeline-stage view, per-agent, unrelated to the branch team grid) — not touched, not affected.
Test: `CompanyPerformanceArchivedAgentTest` (3 passed).

## (d) DR2 twin branch defect — DONE (Staging + QA1; DR2 lineage)

`DealV2Controller::store()` hardcoded `branch_id = auth()->user()->branch_id ?? Branch::first()?->id`
— a NULL-home capturer landed on **Shelly Beach**. Replaced with DR1-parity:
prefer `effectiveBranchId()` (home OR managed-branch context), else REQUIRE an
explicit branch (same message as (b)); `Branch::first()` fallback removed. Added a
`branch_id` field to the validate block and a Branch selector to the create form
(shown only when the capturer has no effective branch). DR2 is dormant on live;
this rides the DR2 lineage to Staging + QA1.
Test: `DealV2CaptureBranchTest` (3 passed) — null-home rejected; explicit branch
honoured; home-branch capturer auto-stamped.

---

## (e) Deals 146 & 147 — human-readable (NO data changed — eyeball first)

Register shows the **Deal number** (`deal_no`), not the internal id.

### Deal 146 → register number **1795**
| Field | Value |
|---|---|
| Property | 5 La Perla *(no property record linked)* |
| Seller | K. Ellis |
| Buyer | A. de Bruyn |
| Attorney | VDS Attorneys |
| Captured | 2026-07-02 |
| Sale value | R 1,000,000 |
| Commission | R 75,000 |
| Status | Pending |
| **Stamped branch** | **Southbroom (3)** |
| Agents | Elize Reichel — selling 100% (home branch: none) · Shawn Du Bois — listing 100% (home branch: **Shelly Beach**) |

### Deal 147 → register number **1796**
| Field | Value |
|---|---|
| Property | 7 La Mouette *(no property record linked)* |
| Seller | N.T. Lenyai |
| Buyer | A. de Bruyn |
| Attorney | Kruger Durheim |
| Captured | 2026-07-01 |
| Sale value | R 1,000,000 |
| Commission | R 75,000 |
| Status | **Declined / Cancelled** *(already excluded from reports)* |
| **Stamped branch** | **Southbroom (3)** |
| Agents | Elize Reichel — selling 100% (home branch: none) · Shalan Du Bois — listing 100% (home branch: **Shelly Beach**) |

**VERIFIED CORRECT by Johan (2026-07-07) — no reassignment.** Business rule,
verbatim: *"Deals sit with the SELLING agent and office."* On both deals Elize
is the **selling** agent acting as **Southbroom**; Shawn/Shalan are the
**listing** side (Shelly Beach). A deal keyed to the selling side's acting
office → both are correctly-captured **Southbroom** deals. The earlier
"possible mis-stamp" flag used a wrong heuristic (it looked for *any* agent
whose HOME branch matched the deal branch; the true rule keys the deal to the
selling side's acting office). Doctrine now recorded in `STANDARDS.md`
(Architectural Laws → Deal Branch Attribution) and `deal-register-v2-spec.md` §1.

---

## Topology — RATIFIED by Johan (2026-07-07)

Live stays as-is, **no rollback**. The `Merge branch 'Staging'` (`45bc135a`,
Andre, 2026-07-06 19:16) folded the held Staging pile onto main; a full main→live
promotion that evening carried it to live (`6c36b017`) — all before this session.
Johan's ruling:

- **Calendar (AT-164) live = RATIFIED** (he'd approved it on QA1; agents are using it — 7 saved cockpit prefs).
- **E-sign = fine** — agents only use the PDF/legacy side; `compiled_serving=1` on **0/64** templates (legacy serving); Compile Studio nav is `esign.compiler.view` = admin-only. Untouched.
- **DR2 visible-but-empty = fine** — only Johan + the machine work DR2; Elize & Falan work DR1. Nav shows for HFC roles but `deals_v2`=0 and `deal_pipeline_templates`=0, so it's inert. **Do NOT backfill live twins.**

**Held-from-live CLEARED** for calendar / DR2-code / e-sign-code. The remaining
**true gates are DATA-level, still Johan's word only:** (1) e-sign
`compiled_serving` flip, (2) DR2 live twins backfill + cutover.

### Andre-session agenda (release-protocol discipline)
- Root cause of the leak: a full `git merge Staging` into main collapses the whole
  superset branch (which carries conversation-level holds) into a live-bound line.
- **Agree ONE of:** (a) a single ordered **gate to live** — promote to live *only*
  by scoped cherry-pick of the intended commits, never `merge Staging`; or (b) a
  visible **HOLDS register** (a checked-in file listing "held from live" SHAs/features)
  that must be reconciled *before* any Staging→main merge, so conversation-level holds
  are visible to Andre. Recommend (b) backing (a).

---

## Deploy

- (a): live data via `syncManagedBranches`, logged.
- (b)+(c): live hotfix (`/corex` main) — pull → clears → php8.3-fpm reload → worker restart; back-synced Staging + QA1.
- (d): Staging + QA1 (DR2 lineage) — not actively deployed to live (DR2 dormant there).
