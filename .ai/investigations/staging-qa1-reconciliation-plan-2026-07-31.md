# Staging ↔ QA1 reconciliation — deferred plan & conflict map

> Written 2026-07-31. **DEFERRED — do not execute yet.** Johan's call: sequence it
> safely and later. This doc is the map for whoever runs it. Nothing has been pushed;
> reference artifacts (local branches/worktrees) are listed at the bottom.

## Why this exists
`origin/Staging` and `origin/QA1` have diverged hugely (merge-base `22015dd5`):
Staging ~257 commits ahead the base QA1 lacks, QA1 ~614 ahead Staging lacks. Staging
carries a whole second lane's work — **André's / QA2 body**: AT-267 Assistants, AT-11
Billing, AT-350 Sold-by-3rd-party, AT-336 restyle, Ellie v2, AT-338 System Updates,
importer/feature-registry, mobile-gallery, etc. QA1 carries the DR2 / e-sign /
lifecycle body.

**This is BIDIRECTIONAL — André's Staging work MUST come into QA; do not lose it.**
It is not "QA-only". QA wins on genuine *conflicts* (same lines changed both sides),
but every *non-conflicting* Staging change (all of André's non-overlapping work) is
preserved by the merge.

## The agreed 3-step sequence (Johan, 2026-07-31)
1. **Finish DR2 + e-sign on QA1.**
2. **Promote DR2 + e-sign QA1 → Staging** (normal forward, per-feature, Johan-gated —
   same as the MIC promotion). ⚠️ **This step may itself conflict with André's Staging
   work — review conflicts at that time**, they are not mapped here.
3. **THEN pull the WHOLE Staging → QA1.** At that point QA picks up André's work AND
   the just-promoted DR2/e-sign, and both branches converge cleanly (the overlap has
   been drained by step 2, so step 3 is far smaller).

Doing DR2/e-sign → Staging FIRST (step 2) is what makes step 3 clean: it removes the
QA-vs-Staging overlap on those features before the big Staging→QA catch-up.

## Conflict intel already gathered (from a throwaway -X ours prototype, 2026-07-30/31)
A full `git merge origin/Staging` into a QA1 worktree was prototyped twice (hand-resolve,
then `-X ours`). Findings for the eventual step-3 merge:

- **Real conflicts: ~30 files** (of 231 files touched by both lanes) — the other ~200
  auto-merge. Categories: ~13 code (`Property.php`, `ContactController`, `routes/web.php`,
  `bootstrap/app.php`, `PropertyObserver`, `ViewingPack(Controller)`, `SyncReferenceData`,
  `EncryptMediaBackfill`, `config/logging.php`, `PrivatePropertySyndicationService`,
  `PropertyAuditService`), ~11 views (dr2 pipeline/_deal-documents/distribute-compose,
  esign wizard, contacts/show, fica compliance-review, company-settings, capture-consent,
  triage, deals-v2 pipeline-setup), 2 tests, 1 migration, 1 schema snapshot, 2 docs.
  Most are single-hunk. (Exact set will drift once step 1/2 land more QA1 commits.)

- **Resolution policy = `git merge origin/Staging -X ours`** — QA1 (ours) wins every
  conflicting hunk automatically, while all non-conflicting Staging-only work (André's)
  merges in. Confirmed on the prototype: 243 Staging-only NEW files + 619 shared files
  getting Staging's non-conflicting hunks, 0 deletions, incl. 36 Staging migrations
  (Assistants, Billing, feature-registry, Ellie, System-Updates, third-party-sale).
  Nothing Staging-only orphaned.

- **Migration/schema union regenerates CLEAN (verified on a disposable DB):**
  `php artisan migrate --force` on an empty DB ran all ~290 migrations, **exit 0, zero
  failures** — both lanes' same-timestamp-different-name migrations coexist (e.g.
  `2026_08_20_000001_add_display_priority_to_pipeline_steps` [QA1] AND
  `..._add_sold_by_3rd_party_status_item` [Staging] both applied). The two migration sets
  touch **disjoint tables**. `php artisan schema:dump` then produced a valid snapshot
  (473 tables, both lanes present).

- **REQUIRED post-merge steps (both bit during the prototype):**
  1. **`composer dump-autoload`** — Staging's new classes are NOT in QA1's authoritative
     classmap (QA1 vendor is `--optimize-autoloader`), so routes/controllers 500 with
     "Invalid route action" until the classmap is regenerated. (Test runs also need a
     `--dev` install; QA1/Staging deploy vendors are `--no-dev` = no phpunit.)
  2. **DEFINER-strip the regenerated snapshot** — `schema:dump` bakes
     `DEFINER=`user`@`host`` onto the 4 audit triggers (contact/property audit), which
     breaks non-SUPER test bootstrap (the exact issue the `add_contact_audit_trigger`
     migration docblock warns about). Strip with:
     `sed -i 's#/\*!50017 DEFINER=[^*]*\*/ ##g' database/schema/mysql-schema.sql`
     (0 DEFINER left, 4 triggers intact). Also set `log_bin_trust_function_creators=1`
     on the test/CI MySQL so the non-SUPER app user can create the triggers.

- **Same-name migration collision:** exactly ONE same-filename migration differs between
  the lanes — `2026_08_10_000002_add_contact_audit_trigger.php` — and it's a **comment-only
  superset** (Staging adds the DEFINER-portability note). Take Staging's / union. All other
  same-name migrations were byte-identical.

- **Test gate (deferred to step-3 execution):** run the full suite as a baseline-diff
  (origin/QA1 vs the merge) to confirm no NEW failures. Needs a `--dev` composer install
  (phpunit lives in a dev vendor — `/mnt/HC_Volume_103099143/corex-dev/vendor/bin/phpunit`
  exists; QA1/Staging deploy vendors don't). NB box rule #13 (no broad suites without
  Johan's go) + PHPUnit is flagged as hanging on lane cc1 — run it in the proper dev/CI env.

## Reference artifacts (kept, NOT pushed)
- Local branch **`reconcile-optA`** (worktree `/tmp/reconcile-optA`) — the hand-resolved
  prototype off an older QA1 base, incl. a regenerated+DEFINER-stripped snapshot.
- Local branch **`optA-land`** (worktree `/tmp/optA-land`) — the `-X ours` merge off
  `origin/QA1@638d6497` + a `chore(schema)` snapshot-regen commit. Structurally verified
  (route:cache / view:cache / config:cache all pass). Full phpunit baseline-diff NOT run.
- Disposable DBs used for verification were throwaway (`corex_reconcile_tmp`,
  `hfc_dash_test_99`) — droppable, no real data.

These are stale the moment QA1/Staging advance, but they capture the conflict *shape* and
the exact mechanical steps. Re-run the `-X ours` merge off current tips at step-3 time.
Related: [[sync-permissions-duplicate-key-deploy-blocker-2026-07-30]] (already fixed on QA1,
travels with the next promotion).
