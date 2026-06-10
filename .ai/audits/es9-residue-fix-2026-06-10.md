# ES-9 residue — Insertable Conditions / Clause Library completion

**Date:** 2026-06-10
**Branch:** AT-12-E-Sig
**Author:** Claude (build), for Johan Reichel (morning review — NOT pushed)
**Scope:** The three documented ES-9 gaps from `esign-reconciliation-2026-06-10.md`
§2. Gaps 1 & 2 BUILT; gap 3 (pagination recalc / per-page initials) DEFERRED with
a concrete proper-fix proposal — see §3 for the precise, legally-grounded reason.

> Pre-reads honoured: CLAUDE.md, STANDARDS.md, BUILD_STANDARD.md,
> esign-v3-complete-spec.md §7.5/§24, esign-reconciliation-2026-06-10.md §2,
> esign-v3-phase-1b-build-notes.md §1. Branch confirmed `AT-12-E-Sig` first.

---

## Gap 1 — Clause schema extension: ✅ BUILT

**Root cause:** `docuperfect_clauses` (created `2026_02_24_400004`) shipped with only
`name, text, is_global, owner_id` — no `category` (grouping key) and no `is_system`
(CoreX-default marker). Verified against the migration + schema snapshot +
`Clause.php` fillable.

**Built:**
- Migration `database/migrations/2026_06_24_000000_add_category_and_is_system_to_docuperfect_clauses.php`:
  `category` (string(50), nullable, indexed), `is_system` (boolean, default false).
  Both nullable/defaulted so the 23 pre-existing rows keep working untouched.
  `down()` drops the index by name then the columns. Soft-delete untouched.
- `app/Models/Docuperfect/Clause.php`: `category` + `is_system` added to `$fillable`;
  `is_system` cast to boolean; a single-source-of-truth `Clause::CATEGORIES` map
  (bond/occupation/fittings/compliance/fees/notice/general) and
  `Clause::normaliseCategory()` (defaults to `general`).

**Verified (corex_dev):** migrate clean (245ms); 23 existing clauses → category
NULL, is_system false; rollback clean (columns gone, 23 rows intact); re-apply
clean. (php -l clean.)

> **Schema snapshot note:** `database/schema/mysql-schema.sql` was NOT regenerated
> in this commit. Running `php artisan schema:dump` produced a ~10,500-line diff —
> the committed snapshot has drifted far from `corex_dev` (a pre-existing branch
> condition unrelated to this change), so a wholesale regeneration would bundle
> unreviewable churn. Per CLAUDE.md §12a, new migrations run *on top of* the
> snapshot, so tests stay correct. The snapshot should be refreshed on a clean
> baseline on Johan's Windows env (separate from this work).

## Gap 2 — System-default clause seed + picker grouping: ✅ BUILT

**Root cause:** no clause seeder existed (library ran on 21–23 ad-hoc rows);
`ClauseController::listJson` returned only `id,name,text,is_global` (no category for
grouping); the builder clause picker (`cds-builder.blade.php`) rendered a flat list.

**Built:**
- `database/seeders/DocuperfectSystemClauseSeeder.php` — 22 SA-correct clauses
  (`is_system=true`, `is_global=true`, `owner_id=null`), categorised. Variable bits
  use bracket tokens (`[X] days`, `[DATE]`, `[AMOUNT]`) — no hardcoded numbers.
  Content is SA-correct: voetstoots (with CPA carve-out), bond approval, occupation +
  occupational rental, electrical/electric-fence/gas/plumbing/beetle compliance
  certificates, commission, 72-hour clause, s29A cooling-off, FICA, etc.
  **Idempotent + soft-delete-aware:** matches on `(name, is_system)` *including*
  trashed rows → re-run updates in place, never duplicates; an admin-archived
  system clause stays archived (deleted_at preserved).
- Registered in `DatabaseSeeder.php`'s idempotent REFERENCE SEEDERS block.
- `ClauseController::listJson` now returns `category`, `category_label`, `is_system`
  (legacy NULL category normalised to `general`). `store`/`update` accept + validate
  `category` (`in:` the category keys) so agency-authored clauses can be categorised.
- Builder picker (`cds-builder.blade.php`) groups by category via a new
  `groupedClauses` Alpine getter (fixed category order; unknown→General last), with a
  "CoreX" badge on system clauses.
- Clause management UI (`clauses/index.blade.php`): category `<select>` on create +
  edit forms; category badge + "CoreX" marker in the list.

**Note on the recipient "Add condition" picker:** per Phase 1B.6 FIX1 the
recipient-side Add Condition modal is intentionally free-text only (no library
access). Grouping is therefore wired on the builder/agent side, which is the only
surface that consumes the clause library — consistent with the shipped design.

**Verified (corex_dev):**
- Seeder run #1 → 23 system clauses; run #2 → still 23 (idempotent, no duplicates).
- Category distribution: bond 3, occupation 2, fittings 3, compliance 5, fees 3,
  notice 3, general 4 (= 23). Every bucket represented.
- `listJson` mapping returns `{category, category_label, is_system, is_global}`;
  a legacy NULL-category clause normalises to `general`.
- phpunit `tests/Feature/Docuperfect/ClauseLibrarySeederTest.php` ships (schema
  columns, ~20+ categorised system clauses, idempotency, archived-not-revived,
  normaliseCategory) — runs under the MySQL schema-snapshot bootstrap on Windows
  (no isolated test DB on this Linux host; see §4).

## Gap 3 — Pagination recalc + per-page initials: ⛔ DEFERRED (legally-grounded)

**This is the deliberate, documented deferral the task's own instruction calls for
when the safe bar (per-page-initial PDF-flatten verification needing a real browser
render) cannot be met. It is NOT a silent compromise — the reasoning and the
proper-fix plan are below.**

### What I investigated (firsthand + a dedicated read-only investigation)

- **Pagination is client-side-only.** `paginateDocument()` /` _paginateWrapper()` in
  `resources/views/docuperfect/signatures/partials/a4-page-styles.blade.php:94-308`
  decide page boundaries by **browser-measured pixel heights**
  (`getBoundingClientRect().height` vs `PAGE_CONTENT_HEIGHT=1500`, `:216,:241-243`).
  There is no PHP/server HTML-pagination path anywhere.
- **The only server-side page count is Puppeteer.** `flattened_page_count` is written
  in exactly one place — `WebTemplatePdfService.php:120`, produced by a `node`
  shell-out to `scripts/web-template-flatten.mjs` (headless Chrome render),
  `WebTemplatePdfService.php:261-287`. Heavy, process-spawning, not a sub-second
  synchronous API call.
- **The per-page-initial machinery already exists and is correct.**
  `_buildInitialsRow(parties, pageIdx)` (`a4-page-styles.blade.php:313-330`) stamps
  `data-marker-index="{docIdx}-{pageIdx}-{partyIdx}"` (`:323`); the
  `P < lastPageIndexOfThatDocument` rule (§19.3) is at `:296`; the §19.4 idempotent
  re-anchor (snapshot signed initials by `_markerKey`, de-paginate, rebuild, re-apply)
  is at `:104-159`. So a newly-added condition that overflows onto a new page
  **already gets a fresh initial slot the next time `paginateDocument()` runs**
  (i.e. next signing-surface render). The missing piece is purely a *trigger* —
  nothing re-paginates immediately after a condition add.
- **The focused initialing view has NO page model.**
  `resources/views/docuperfect/signatures/external/initialing.blade.php` is a flat
  changed-regions checkbox list (one checkbox per condition/strikethrough, scoped to
  the current party); it does NOT `@include` `a4-page-styles`, never calls
  `paginateDocument`, and has no `.corex-a4-page` / `data-marker-*` page initials
  (confirmed: 0 references).
- **No preview/page-count endpoint exists** (grep: no `previewRender`/`renderPreview`;
  condition-add returns only `rendered_row`, `SigningController::addCondition:3246-3351`;
  `ConditionsController::storeCondition:41-111` returns `{ok, condition}`). The agent
  review surface (`amendments/review.blade.php`) shows a structured diff with zero
  page-count display.

### Why each sub-item cannot be built **and verified** safely here

1. **Synchronous preview-render returning new page count** — the legally-true count
   only exists *after* a browser measures pixel heights (client `paginateDocument`) or
   a Puppeteer flatten. A synchronous server endpoint cannot produce it without
   spawning Puppeteer (heavy, not synchronous) or trusting a client measurement. Any
   PHP-side estimate would be an approximation, not the true count.
2. **"+N pages added" in the agent review modal** — depends on (1). Displaying an
   *approximate* page delta on a legal review surface (the screen where the agent
   approves an initialing cascade) is worse than showing nothing; it could misstate
   how many pages the parties must initial. I will not ship a fabricated count.
3. **Per-page initial slots on new pages in the focused initialing view** — the
   initialing view has no page concept; adding one means bringing the a4-page
   pagination engine into it AND knowing which page a condition lands on
   (browser-measured) AND verifying that signed-mandate per-page initials are
   captured correctly through the Puppeteer flatten. Per-page initials are legally
   significant (every page of a SA mandate must be initialled by every party). I
   cannot run a real browser/Puppeteer render in this environment to verify the
   flattened PDF carries the right initial slots on the right pages — exactly the
   verification bar the task says must be met or the sub-item deferred.

### What ALREADY protects correctness today (so the deferral is safe)

Because the existing client `paginateDocument()` runs on every signing-surface render
and the §19.4 re-anchor already adds fresh initial slots to genuinely new pages while
preserving captured initials, **a condition that pushes content onto a new page does
get a per-page initial slot at the next render** (the last signer's render, whose
`paginated_html` becomes the filed `signed_paginated_html` → the flattened PDF). The
gap is the *immediacy/UX* (no live "+N pages" during mid-session add, and the focused
initialing view's lack of a page model), not a silent loss of a legally-required
initial in the final document via the normal flow.

### Proper-fix proposal (for when a browser/Puppeteer harness is available)

1. **Trigger, don't reinvent.** After `addCondition` appends its `rendered_row`,
   re-invoke the existing three-call sequence already used at
   `external/sign.blade.php:1531-1533`/`:2178-2180`:
   `paginateDocument(...) → _syncTotalPagesFromPagination(...) → restoreStoredInitials(...)`.
   The §19.4 key (`{docIdx}-{pageIdx}-{partyIdx}`) reconciles new-page slots and
   preserves signed initials. NO new keying.
2. **"+N pages" from the authoritative source.** Read the post-re-pagination
   `data-doc-total` delta client-side and POST it for display in the agent review
   modal; OR (server option) add a Puppeteer "preview flatten" that returns
   `flattened_page_count` and diff against the pre-amendment count. Either way the
   number comes from a real render, never an estimate.
3. **Initialing view page model.** Bring `a4-page-styles` into
   `external/initialing.blade.php` so changed pages render with `_buildInitialsRow`
   under the last-page rule, scoped to the current party — reusing the existing
   mechanism, not a parallel one.
4. **Verification gate.** Before shipping, prove via a headless-browser/Puppeteer run
   (or a DOM-measurement harness) that a multi-condition document that grows by ≥1
   page has the correct per-page initial slots on every new page, the signature block
   on the true last page, and that the flattened internal + client PDFs carry them.

This is the specific escalation the CoreX Operating Principle requires for a deferral.

---

## Verification summary

| Item | Result |
|---|---|
| php -l (all changed PHP) | clean |
| php artisan view:clear | OK |
| Migration up / rollback / re-apply (corex_dev) | clean; 23 existing rows intact |
| Seeder double-run idempotency (corex_dev) | 23 system clauses both runs; 0 duplicates |
| Category distribution | all 7 buckets populated (bond3 occ2 fit3 comp5 fee3 not3 gen4) |
| listJson shape | returns category + category_label + is_system; legacy→general |
| phpunit `ClauseLibrarySeederTest` | ships (runs on Windows schema-snapshot bootstrap) |
| Gap 3 | deferred, documented above with proper-fix proposal |

**dev-check.ps1 skipped** — Windows/PowerShell, not runnable on this Linux host (per
task). **phpunit** for gaps 1–2 ships but cannot run here: the test DB
(`hfc_dash_test`/root) is inaccessible and `corexdev` is scoped to `corex_dev` only
(no isolated test DB; RefreshDatabase would wipe dev data; in-memory SQLite is
rejected by a raw MySQL `ALTER…MODIFY` migration). The seeder/schema behaviour is
fully proven by the Tinker runs above.
