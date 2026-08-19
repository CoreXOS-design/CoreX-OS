# QA1 / main divergence audit — 2026-08-19

Author: conductor's data-access lane, read-only investigation. No branch changes, no merges,
no resets, no database or code writes were made to produce this audit.

Context: today alone surfaced four separate incidents caused by treating `qa1-deal-external-guard`
and `origin/main` (and, downstream, live) as comparable when they are not:

1. Approved MIC commit `7686305d4` silently depended on unapproved ancestor `300a247ba`. Found mid-fold.
2. Approved deeds migration `2026_08_26_130100` referenced `deeds_captured_at`, a column created by
   unapproved ancestor `9f3463347`. Found only when the migration failed on staging.
3. QA1's database is missing `users.is_assistant` (main migration `2026_07_14_200001`, dated 2026-07-14),
   which blocked an authenticated page render for a lane today.
4. The AT-267 assistant test — its own header calls it "the test the whole feature stands or falls on" —
   exists on `origin/main` but was never merged into qa1.

Johan intends to reset qa1 to mirror live once tonight's work lands, on the reasoning that "all work
that I cannot lose will be on live." This audit exists to make that true before it happens, or to name
precisely what would be lost if it happens today.

---

## 1. The shape of the gap

Merge-base of `qa1-deal-external-guard` and `origin/main`: `c754bba78` (2026-08-14).

| Direction | Count |
|---|---|
| Commits on `origin/main`, not on qa1 (`qa1..main`) | **547** (non-merge) |
| Commits on qa1, not on `origin/main` (`main..qa1`) | **71** (non-merge) |

The 547 main-side commits, grouped into named bodies of work (2026-07-14 → 2026-08-18):

- **AT-267 — Assistants feature (~49 commits, Jul 14 – Aug 12).** The full build of the "assistant"
  user role: schema, permissions model, the security resolver (`AssistantPermissionResolver`,
  `dataIdentityIds()`), a later multi-agent addendum. **This is the exact feature tonight's calendar
  work (AT-267 hybrid scoping) sits on top of, and qa1 has none of the branch history that built it.**
  Its own most-critical test — the one referred to in this audit's title — is part of this body and is
  not on qa1 (see §1a below).
- **ad-manager AI background removal (~26 commits, Aug 2–3).** Auto-removes backgrounds from agent
  photos for marketing materials; several correction rounds including two reverts.
- **AT-336 — E-sign wizard restyle (~19 commits, Jul 26 – Aug 16).** Visual/layout rework of the
  e-signature wizard.
- **Multi-tenancy / tenant-isolation hardening (12+ commits across several waves, Aug 14–15).**
  Security fixes closing cross-agency data leaks — branding leak, Market Pulse data leak,
  documents/web-docs IDOR, an onboarding link stamped to the wrong agency. Flagged as the
  highest-consequence class in this group — these are correctness/security fixes, not cosmetics.
- **Schema-snapshot housekeeping (~8 commits).** Routine `schema:dump` refreshes after migrations
  landed elsewhere; not itself risky, just volume.
- **Core Matches fixes (~11 commits, Aug 12–17).** Buyer-property matching results page: a dead-button
  fix, a 500 error, badge styling.
- **Property importer / async CSV (~11 commits, Jul 17 & Aug 14).** Bulk listings/images import,
  plus an onboarding agent-invite step.
- **Properties module upkeep (~12 commits).** Photo pipeline, thumbnails, OG/preview image fixes.
- **MIC (~7 commits, Aug 10–17).** Feedback-tick UI bugs, Copy ID/reg buttons — smaller items than
  what landed on qa1 independently.
- **Compliance/FICA (~5 commits, Aug 10–15).** TFS staleness-check trust fix, online-send email/
  mojibake hotfixes.
- **Everything else** (AT-323 WhatsApp confirm, AT-144 wording, AT-338 What's New relocation,
  agency-admin invite/onboarding, p24 sync, deals, marketing/Facebook, Ellie, mobile gallery,
  pdf-splitter) is routine single-feature bug-fix/polish work — no single item large enough to
  name as its own body.

**§1a — the AT-267 test gap, specifically.** `tests/Feature/CommandCenter/AssistantCalendarVisibilityTest.php`
(exact path per the AT-267 body on main) is the test whose own header names it as the one the feature
stands or falls on. It is part of the AT-267 body above and is not present on qa1 in any form —
confirmed by its absence from qa1's tree and by the 71-commit reverse-diff (§4) containing no such file.
Tonight's calendar work (`ad9399923` → cc6's hybrid resolution, `d05111a95`) is a direct extension of
AT-267 scoping logic. It shipped tonight verified by hand-tracing and by the two purpose-written
`test(calendar): prove ...` commits already on staging — not by this pre-existing suite, because that
suite was never available on the branch the work came from.

## 2. The schema gap

**56 migration files exist on `origin/main`'s tree and do not exist anywhere in qa1's working tree**
(confirmed by exact tree diff, `database/migrations/` only). Cross-checked against qa1's live
`migrations` table (1,100 rows recorded) — a six-file sample, including the `is_assistant` one, returned
zero matches, and `SHOW COLUMNS FROM users LIKE 'is_assistant'` on qa1's actual database returned
nothing. These migrations have never run on qa1, full stop — not "ran once and got reverted."

Full list is in the migration-diff output referenced by this audit; the ones worth calling out because
they change *behaviour or data*, not just add a column:

- `2026_07_14_200005_seed_assistant_role` — inserts a new zero-grant `assistant` role row directly
  (not via a seeder, deliberately, per its own comment — seeders don't run on a `git pull` deploy).
- `2026_08_14_162800_scope_properties_external_id_unique_to_agency` — narrows a unique index from
  global to per-agency; fixes a real production collision (P24 import failure on agency 17, confirmed
  0-of-4,753 rows) but changes what a duplicate-key error means going forward.
- `2026_08_20_000008_backfill_cutout_matte_color_for_removebg_avatars` **and its same-day revert**
  `2026_08_20_000009_revert_cutout_matte_color_backfill` — a change that shipped and was reverted the
  same day in production; qa1 has neither half.
- `2026_08_22_000004_scope_client_users_email_unique_to_active_rows` — replaces a blanket unique
  email constraint with one that ignores soft-deleted rows, fixing a real re-signup collision bug.
- Several `backfill_*` migrations (`agency_features`, `legacy_deal_branches`, `branch_id_for_*`,
  `default_property_settings_per_agency`) that rewrite existing data, not just add columns.

None of these are catastrophic in isolation, but a schema this far behind is exactly the mechanism
behind incident #3 today, and will keep producing incidents like it for any qa1 work that touches a
table main has since altered.

## 3. The dependency trap, generalised

Problems 1 and 2 today share one shape: **an approved commit's migration or code references a
column/row/symbol that a DIFFERENT, unapproved ancestor commit created — invisible until the approved
commit is applied somewhere that ancestor never reached.**

**Mechanical check applied tonight, and recommended as a standing pre-deploy check:** for every
migration in the commit set about to ship, read every `->after('column_name')` (and, more generally,
every column/table reference the migration or its accompanying model/service code depends on) and
confirm that column exists via one of exactly two routes: (a) created earlier in the SAME migration, or
(b) present in the target branch's baseline *before* the approved commit set is applied. If neither
holds, the reference came from an ancestor outside the approved set — stop and check whether that
ancestor is itself approved.

Applied to the 4 migrations in tonight's batch:

| Migration | `after()` target | Verdict |
|---|---|---|
| `2026_08_26_130100_add_ownership_parse_status...` (deeds) | `deeds_captured_at` | **FAILED** — created by unapproved ancestor `9f3463347`. Fixed tonight (`c3c08dea8`) by dropping the clause. |
| `2026_08_26_130000_add_ownership_history_fields...` (deeds) | `id_type` | Clean — pre-existing column, migration ran successfully. |
| `2026_08_19_100000_add_section_extent_m2...` (deeds) | `cadastral_extent` | Clean — pre-existing column (confirmed in `TrackedProperty::$fillable`), migration ran successfully. |
| `2026_08_18_120000_create_tracked_property_comments_table` (MIC) | n/a — pure `Schema::create`, foreign keys only | Clean — references `agencies`, `tracked_properties`, `users`, all pre-existing. |
| `2026_08_19_090000_add_dismissal_reason_to_calendar_events` (calendar) | `status` | Clean — long-standing core column, migration ran successfully. |

No second instance of the migration-level trap was found in tonight's batch beyond the one already
caught and fixed. The MIC code-level instance (`7686305d4` → `300a247ba`) was a non-migration
dependency (application code referencing logic from an unapproved ancestor commit), which this
mechanical check does not cover — that class of dependency (a PHP method, a route, a computed field)
has no equally cheap mechanical test; it was caught by a human noticing behaviour during the fold, not
by inspection. **Recommend this as future tooling work**: a script that, for each commit selected for a
partial promotion, diffs referenced symbols (class methods, config keys, column names appearing in
`WHERE`/`SELECT`) against what the target branch's baseline actually defines — the migration check
above is the easy 80% of this; the code-symbol check is the harder remaining 20% and does not exist yet.

## 4. The reset risk — what would actually be lost

This is the load-bearing section. "Once tonight's work reaches live" is treated here as: staging's
current tip (`973f5907a`, which already contains all 21 of tonight's approved commits — 7 deeds + 1
fix, 11 MIC, and the calendar body + 2 verification tests — folded and verified). Comparing
`qa1-deal-external-guard` against that tip is comparing qa1 against what live will be tonight.

**Raw count: 71 commits on qa1 are not reachable from that tip.** Verified by content (patch-id), not
just by hash, that 16 of those 71 are the literal source commits for tonight's approved work (now on
staging under new hashes from the cherry-pick/rebase) — genuinely safe, already delivered.

A second pass — checking the remaining 55 by *exact commit-message match* against staging's full
history, not just tonight's additions, then verifying every message match by patch-id — found 9 more
that are already safe for reasons specific to each:

- 4 are byte-identical in content to commits already on live's pre-tonight baseline (the calendar
  action-bar/feedback-vocabulary/invited-events work, and the `wa-link` device-relink fix) — same
  work, landed earlier via a different path.
- 5 have the same subject line as something on staging but genuinely different code — in every case
  traced, this means the same bug was independently re-fixed on the branch that reached staging
  (`ad9399923`'s invitation logic was deliberately re-resolved by cc6 as the AT-267 hybrid; the mic
  claim-guard and multi-select matching fixes were independently re-implemented). One of the 5,
  `13fdc4560`, is qa1's own copy of one of my 7 approved deeds commits — it fails the patch-id check
  only because it went through an intermediate conflict-resolution rebase (`resolve/body-a`) that
  reshaped its diff context; it is not separate work.

**That leaves 46 commits on `qa1-deal-external-guard` with no equivalent anywhere on live, staging, or
tonight's approved batch — checked twice, by content and by message, and still standing.** Grouped:

- **Seller-outreach compose screen — 10 commits, several marked P0.** The largest single body of
  at-risk work and the most urgent to triage. Includes `3babe7597` ("root-cause fix — inline
  `@php(...)` broke the entire template's compilation") and `ac190998e` ("P0 fix — compose screen
  rendered raw JS as body text") — both read as genuine production-grade bug fixes to a screen agents
  use, not exploratory work. Also: agent-ejected-on-refresh, TVA-numbers gate, WhatsApp-cooldown and
  keepalive fixes.
- **Deeds-capture chrome-extension version chain — 8 commits (v3.4.1 through v3.4.5, plus the
  deeds-link-modal scope fix).** CAVEAT, not a clean finding: tonight's approved endpoint commit
  `13fdc4560` is documented (in the deeds-body task that shipped it) as compressing "several unshipped
  chrome-extension increments (v3.4.5 → v3.6.4) into one diff." Some or all of this chain may already
  be subsumed into what's on staging now. This audit did **not** individually verify file-by-file
  overlap for these 8 — that check should happen before anyone abandons them, not be assumed from this
  list.
- **`9f3463347` — "deeds capture of an existing property no longer vanishes."** The already-diagnosed
  unapproved ancestor from incident #2. Its actual fix (a real bug: a deeds capture that lands on an
  existing prospecting lead never surfaces on the Deeds Capture screen) is not on staging or live —
  only its accidental column side-effect was ever seen elsewhere, and that side-effect has now been
  removed from the approved migration.
- **`5aa4ce85a` — Deals V2 both-sides-external guard.** "block both-sides-external deals — require ≥1
  internal agent." Reads as a compliance/business-rule feature, not a cosmetic fix.
- **Performance/ROI reporting — 2 commits + spec.** `6ba5c2642` (ROI showpiece redesign) and
  `9b5f081f0` (period-comparison on the Agency Performance & ROI report), with spec
  `.ai/specs/at366-period-comparison.md`.
- **Contacts — ID/Passport identity feature port — 1 commit.** `f9715b787`, "port ID vs Passport
  identity feature (#17) to qa1."
- **MIC fixes not yet folded — 5 commits.** Claim-notes-with-line-breaks, price self-heal, claim
  server-side guards, stock-status display. Some of these have similarly-worded (not identically-worded)
  counterparts already on staging — e.g. `f47b79a77` ("company stock shows 'In stock' not 'Unclaimed'")
  reads close to staging's `1223be96f` ("company stock shows In stock, not Unclaimed+Claim") but the
  message text differs enough that this audit's exact-match check did not resolve it either way.
  **Flagging explicitly: this audit's duplicate-detection is exact-match only (content or message). It
  will not catch a same-fix-reworded-message pair. A human pass on this specific 5-item list, and
  spot-checks on near-miss pairs like this one, is needed before anyone abandons them.**
- **QA1-self-port commits — 4 commits.** `f0a9a511f`/`aa0ec8e88`/`8a79718e6`/`c60d5bd7f` — qa1 pulling
  live's already-working seller-outreach/contact-show code onto itself. Likely lower risk (qa1 catching
  up to live, not new work), included for completeness.

**Specs and audits committed today that exist ONLY on qa1-deal-external-guard and would be destroyed
by a reset — checked across the full 55-commit candidate set, not just the "docs" commits:**

1. `.ai/specs/mic-claim-working-hours-window.md` (`057a93485`)
2. `.ai/specs/2026-08-19-claim-timer.md` (`19ba0e832`)
3. `.ai/specs/mic-stale-stock-resolution.md` (`a28a48e37`)
4. `.ai/specs/2026-08-19-stale-stock-and-mic-resolution.md` (`f82b40e63`)
5. `.ai/specs/mic-promoted-property-exclusion-proposal.md` (`838bdeb9c`)
6. `.ai/specs/at366-period-comparison.md` (`9b5f081f0`)
7. `.ai/CHAT_STARTER.md` — modified on qa1 (`bf3acd880`) with content not on staging/live.

These are pure documentation, zero execution risk to move, and the highest value-per-effort item in
this whole audit — several lanes spent real time writing considered specs and proposals onto this
branch tonight, and a reset takes them with it unless someone moves them first.

## 5. Recommended reset procedure

Not for tonight. Written down so it's ready when Johan is.

1. **Tag first, before anything else, regardless of what else happens.** `git tag
   pre-reset-archive-<date> qa1-deal-external-guard` and push the tag to origin. This costs nothing,
   takes one command, and means even a same-day reset cannot destroy anything — the 71 commits stay
   reachable via the tag indefinitely. This step alone removes the actual irreversibility risk; every
   step after it is about tidiness and re-integration, not data loss.
2. **Move the 7 spec/audit files out first**, independent of any code decision — cherry-pick just their
   commits (or copy the files directly) onto `origin/main` via the repo's normal spec-approval path.
   Zero code risk, all upside.
3. **Triage the 46-commit list against the named groups above**, before reset, not after — specifically
   the seller-outreach P0 arc (10 commits, reads as real production bugs) and the Deals V2 external-guard
   commit (reads as a compliance rule) deserve someone's explicit "promote" or "deliberately abandon"
   decision from Johan or Andre, not a default of "gone because qa1 got wiped." The deeds v3.4.x chain
   and the near-duplicate MIC pair need the file-level/message-similarity check this audit flagged but
   didn't finish, before either goes on the abandon list.
4. **Only after 1–3, reset qa1-deal-external-guard** (or however Johan wants the reset performed) to
   mirror live.
5. **Confirm the tag survived** — pushed to origin, not just local to this machine — so a disk-level
   accident on the qa1 host can't take out both the working branch and its only backup at once.

Steps 2–3 can run in parallel with other work; step 1 should happen essentially immediately once this
audit is read, since it is the one step that actually prevents loss and has no downside.
