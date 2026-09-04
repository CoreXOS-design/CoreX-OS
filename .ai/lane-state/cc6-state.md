# cc6 lane state — written before context auto-compact, 2026-09-03 ~03:20 SAST

**If you are cc6 reading this after compaction: read this file FIRST, before
doing anything else.** Then go straight to the task at the bottom ("resume
here").

## Role tonight

Started as: deals/DR2/calendar/dashboard/MI feature owner (one of 6 lanes).
Escalated by Johan/coordinator over the night into: **coordinator role**
across all 6 lanes — own the master feature checklist, the git/seeder
reconciliation when DemoDataSeeder.php was getting clobbered, the infra
watch list (every ~20min check-in), and now **the final owner of
`.ai/DEMO-TESTING-INDEX.md`** as Johan's literal run sheet for the 10:00
webinar.

Current HEAD on origin/main: **9423dbb9b** (confirmed local==origin, in sync).

## Completed tonight (chronological, with shas)

1. **Property Intelligence chart investigation + fix** — chart pipeline was
   never broken, root cause was only 15/512 properties had portal-metric
   data and nobody found one by luck. Extended CMA presentations to all 15
   heroes. Properties 1/2/3 confirmed working examples.
2. **Master config sweep checklist** (`CONFIG-SWEEP-CHECKLIST.md`) — 46
   settings screens enumerated from the settings-hub nav array + routes.
3. **DR2 showcase deal** (deal 111, deal_no 920005) — full parties
   (seller/buyer/attorney/bond-originator from cc3's supplier directory),
   documents (OTP/Mandate/Rates Clearance generated + Johan's real FICA
   upload reused), comments on 3 completed steps, Supplier Work Orders
   panel configured (Electrical/Beetle/Gas/Electric Fence). **Exercised
   live**: completed "Bond Approved", watched all 4 real emails land in
   Mailpit (370→374), then rolled back to `pending`/`active` so Johan gets
   the live click tomorrow. Seeder: `DemoDr2ShowcaseDealSeeder`, stage15b.
4. **Expanded feature checklist** (`DEMO-FEATURE-CHECKLIST.md` then
   superseded by `DEMO-FEATURE-COVERAGE.md`) — every significant screen,
   not just settings, Screen/Route/Lane/HasData/Risk columns.
5. **Calendar Invitations seeded** — was 0 rows despite 1,455 events.
   `DemoCalendarInvitationsSeeder`, stage15d.
6. **DemoDataSeeder.php reconciliation** — investigated a reported
   "clobbering" scare (cc4's stash). Conclusion: stash was stale, nothing
   was actually lost, but stage19/20 were sitting uncommitted at real risk
   — committed those. Full 25-stage list verified in order.
7. **Fixed the actual duplicate-key bug** cc2/cc3 both hit:
   `stage1_agencyBranchesUsers()` and `createUser()` were plain
   `insertGetId` with NO existence check — non-idempotent, duplicated
   branches (agency 1 had 6, should be 3) and fatal-collided on
   `users.email` on any standalone re-run. Fixed both to key on
   `(agency_id,name)` / `email`; cleaned up the existing duplicate data
   (reassigned properties/contacts, soft-deleted the 3 dupe branches).
   Verified idempotent by direct re-invocation.
8. **Broadcast git protocol to all 5 lanes**: commit DemoDataSeeder.php
   immediately, `git pull --rebase` before touching it, stash-with-message
   over force-push on conflict.
9. **Fixed DEMO-TESTING-INDEX.md's dead-end buyer example** (cc3 caught
   viewing pack 17/contacts 1-3 = zero matches). Rewrote §6 with a
   verified 8-contact spread: contact 30 (Pieter Dlamini) as the
   no-caveats pick, 31/35/36/39/46/47/29 covering
   new/warm/cold/lost states. Cross-checked every OTHER example record in
   the document against live DB — found 2 more drifts (property 16
   re-seeded, stale filed-doc filename; viewing packs grew 3→7) and fixed
   both.
10. **Infra emergency fixes** (highest priority when they landed):
    - Reloaded `php8.2-fpm` on demo — stale opcache was serving last
      night's bytecode despite code changes (cc4's root cause).
    - Storage permissions sweep: 2,122 root-owned entries under
      `storage/` (root-run lanes creating files as root, unreadable by
      www-data). `chown -R www-data:www-data storage/` across the WHOLE
      tree, not just the one flagged directory. Verified 0 remaining
      non-www-data entries.
    - Both fixes verified via REAL external curl requests (actual login
      session through nginx→php-fpm, not internal kernel dispatch):
      deal 111 doc download 200 w/ valid PDF; viewing-pack-16's redacted
      file (via viewing_pack_property_id=61) 200 w/ real 1.1MB PDF.
    - cc4 independently re-verified both with fresh curls after — CLOSED.
11. Infra watch list run twice tonight, all PASS both times: sites 200/302
    as expected, all supervisor workers RUNNING (demo/live/staging/qa1/qa2),
    apt-daily-upgrade.timer masked, demo_reset_frozen=1, disk 84-86%
    (cleaned 6 stale puppeteer profiles once), git HEAD==origin/main both
    times, demo:reset schedule still commented out in routes/console.php.
    **06:00-06:30 SAST window (when workers died once already this week)
    has NOT been checked yet as of this state file — outstanding, do this
    explicitly when the clock gets there, do not assume the mask held.**

## Known recurring pattern to watch for

**Root-run lanes are the recurring cause of the storage-permission class of
bug.** Every lane is running as root tonight (not www-data), so any file a
lane creates directly (not through the real upload/generation code path,
which correctly runs as www-data under php-fpm) risks landing root-owned
and unreadable by the web server. This has now hit twice (CDS generate 500,
viewing-pack redacted 404). If a THIRD 404/500-that-shouldn't-happen shows
up tonight, check `find storage -not -user www-data` before anything else.

## Outstanding / in progress

**THE TASK IN PROGRESS RIGHT NOW — resume here:**

Rewriting `.ai/DEMO-TESTING-INDEX.md` as Johan's literal 07:00 run sheet
(he reads it in a hurry, agencies dial in at 10:00). This supersedes
everything in the doc as it stands. Mandatory corrections from tonight's
lane reports, ALL THREE must be in the doc prominently, not as footnotes:

- **A.** Login recipe in §1 is incomplete — cc4 found `/agency/select` →
  pick "CoreX Demo Realty" is a REQUIRED step after login, or payroll/
  deeds-capture/compliance return 302/403. Must be a numbered step.
- **B.** Contacts looks empty when opened cold — cc5 found the list
  defaults to a filtered agent view showing nothing; `?agent_id=all` or
  clicking "All" in the agent filter shows all 312. Put near the top of
  the Contacts section.
- **C.** Property Intelligence chart: use property 15, or 1-15/17 — NOT
  the top of the default newest-first properties list, where syndication
  is genuinely off and the chart is correctly empty. See commit
  `9423dbb9b` / `02884d7ef` / `0ec0a5c57` for cc5's Known gap #9 reasoning
  (competitor stock density fix, verified links).

Also fold in (from tonight's commits, verify each before writing):
- Presentation 57's live link
- Property 2 with redacted pack 16 (viewing_pack_property_id=61)
- Deal 111 (already documented, re-confirm still accurate)
- E-sign packs 16-18 verified on BOTH property 16 AND contact 16
- Document Distribution Matrix — 47 rules across 8 party roles (check
  commit history around `426cb21e6`/`914e0d687` era or later for the
  seeder that built this — NOT yet verified by me, check before writing)
- Buyer 30 + flagship buyer spread (already in the doc from my earlier
  work, re-confirm still accurate given more reseeding may have happened)
- Commercial Evaluations (fixed tonight, commit `9382c9aae`)
- Agent Daily (fixed tonight, commit `dc9b60a6d`)
- Knowledge Base (fixed tonight, commit `426cb21e6`)
- Guided Tours — 84 real cards (fixed tonight, commit `426cb21e6` era)

**Structure requirement: a walk he can follow top to bottom, not a
reference list.** This is a rewrite, not a patch — read the current file
fresh, verify every claim above against the live DB/a real render before
writing it (same discipline as all night: never transcribe another lane's
claim unverified), then restructure the whole thing as a sequential script
for Johan's 07:00-09:00 window.

After writing: commit and push. This file is explicitly "the single most
important thing he touches tomorrow" per the coordinator's own words.

## Other still-open items (lower priority than the index rewrite above)

- Coverage table (`DEMO-FEATURE-COVERAGE.md`) still has several `unknown`
  rows — lower priority than the index rewrite now, per the coordinator's
  explicit reprioritization.
- Orphan list was reported 5 times: Ellie (AI assistant, still likely
  unowned — check if anyone claimed it since), Payroll Leave module
  (still unconfirmed whose slice). Commercial Evaluations/Agent
  Daily/Knowledge Base/Guided Tours/Whats New are now FIXED (see commits
  above) — update the orphan list to remove them once confirmed.
