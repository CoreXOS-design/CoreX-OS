# CoreX — Build Standard (Robustness Charter)

# ⛔ NON-NEGOTIABLE OPERATING RULES — READ FIRST, EVERY COMMAND, NO EXCEPTIONS

These override everything else. Violating scope is worse than doing nothing. When in doubt: STOP and report.

1. SCOPE LOCK. Work ONLY on the exact task in the current instruction. Do not touch, edit, refactor, rename, reformat, "improve," clean up, or fix ANY file, feature, module, or behaviour outside that exact task — not even if it looks broken, related, or trivial, and not even if you are "already in the file."

2. NO AUTO-FIX / REPORT-ONLY OUTSIDE SCOPE. If you find a bug, regression, or issue anywhere outside your exact task, STOP and REPORT it to the conductor with exact file:line + root cause. Do NOT change it. Nothing outside the assigned task is changed without Johan's strict, specific, explicit instruction.

3. SPEC-EXACT, NO IMPROVISING. Build strictly to the instruction and the named .ai/specs/ spec. Add NOTHING that was not explicitly asked for — no extra features, fields, pages, UI, or behaviour. If the instruction and the spec conflict, or anything is ambiguous, STOP and ask the conductor. Never guess. Never interpret. Never assume.

4. STAY IN YOUR LANE. Work only in your assigned module. Never wander into another part of CoreX for any reason.

5. QA1 ONLY — JOHAN GATES EVERYTHING. All work lands on QA1 and STAYS there. NEVER promote to Staging or live. Flow: QA1 -> Johan tests on QA1 -> Johan's explicit go -> Staging -> live. No live work of any kind (code OR data) without Johan's specific explicit order for that exact action.

6. NO SILENT EXTRAS. No speculative changes, no "while I was here," no drive-by refactors, no dependency bumps, no formatting sweeps, no touching unrelated files.

7. REPORT EXACTLY. When done, report exactly what changed (files + why) and how you proved it, and confirm nothing outside the task was touched.

8. FULL CRUD, LIST-SCREEN COMPLETENESS, AND OWN/BRANCH/AGENCY SCOPING ARE THE FLOOR — DESIGNED IN, NOT REQUESTED. Johan's words: "we always need proper crud? search / sort / own / branch / agency levels. that should be the design standard. not me asking for it once we get to that stage." Every entity ships with Create, Read, Update, Archive (soft delete only — never hard delete) and Restore from the first build, not as a later ask. Every list screen ships with search (named fields), sort (every sensible column + a stated default), filter (status + date range minimum), pagination, and a real empty state. Every list, detail view, export, download, and API endpoint enforces OWN / BRANCH / AGENCY visibility scoping at the query layer (BelongsToAgency / AgencyScope, never a hidden UI link) — direct-URL access by ID is blocked, not just unlinked. The spec for any new feature states search fields, sort/default, filters, and per-screen scoping BEFORE code is written; a spec missing these is not ready to build. Full detail: §1a below.

This applies to the conductor too.


> **MANDATORY. Read alongside CLAUDE.md, STANDARDS.md, CODEBASE_MAP.md.**
> This file defines what "done" means. Code that only passes its own
> happy-path test is NOT done. This is the senior-engineer baseline.
> Every prompt references this file. No feature is complete until it
> satisfies every section below that applies to it.

---

## §0a. The render gate + browser smoke — required, not optional, for any Blade/Alpine change

See STANDARDS.md "Standard −1" for the full incident history and the
per-file contract (`scripts/fetch-authenticated-page.php` +
`scripts/verify-alpine-render.mjs`). The rule in this file is the
Definition-of-Done consequence: **a feature touching a Blade file with
Alpine in it is not done until both the render gate and the browser smoke
below are green.** `php -l` passing and PHPUnit passing are necessary and
proven NOT sufficient — three separate incidents shipped a completely dead
screen to QA1 with both green.

**`scripts/rental-smoke.mjs`** is the browser-level companion: a REAL
headless browser (Puppeteer) drives the actual rental-applications journey
— applications list (control-centre tiles), review screen, the mark-up
view with documents open, authorisation screen, applicant link, Rentals →
Contacts, contact edit, PDF splitter review — and for EVERY screen reports
a console-error count plus a real-data assertion (a total with a figure
beside it, a list with rows in it — never just that labels rendered). Zero
console errors is the pass mark. **A 200 HTTP status is never treated as a
pass signal anywhere in this script or the render gate** — that is the
entire lesson of this section's existence: 200 is exactly what all three
incidents returned.

```bash
node scripts/rental-smoke.mjs
```

Run this after every pull into `/corex-qa1` that touches rental-application
views, and before every push that touches one. Report per-screen console
error counts, not a single pass/fail line — "0 tests failed" told nobody
which screen was actually dead.

**`scripts/dev-check.ps1` is PowerShell. This box has no `pwsh`. It has
NEVER run here, for any build in this repo's history on this environment —
do not cite it as a verification gate, do not tell Johan or the conductor
it ran, and do not wait for it. The two scripts above are its replacement
in this environment.**

---

## 0. The governing principle

**We do complicated so the user does simple — and the user is never
perfect.** Real users submit half-filled forms, paste messy data, click
the wrong order, skip optional fields, and do the lazy-but-valid
shortcut. Code that assumes clean input is broken code, no matter how
many happy-path tests pass. The job is a system that takes anything
thrown at it and either handles it gracefully or refuses it clearly —
never a 500, never a silent data-loss, never an error message that means
nothing to a user.

---

## 1. Full CRUD, list-screen completeness, and own/branch/agency scoping are the floor

Johan, verbatim: *"we always need proper crud? search / sort / own /
branch / agency levels. that should be the design standard. not me
asking for it once we get to that stage. so get that going as well
that we design and build correctly from the word go."*

He should never have to ask for these after a feature is built. They
are designed in at spec time, every time, without him mentioning it. A
feature that ships with only create-and-list is incomplete — it is not
"phase 1," it is not done.

### 1a. Full CRUD is the default, never a request

Every entity that can be created can be read, updated, and archived
(soft-deleted). If a prompt says "add the ability to create X", the
build INCLUDES list, view, edit, archive, AND **restore from archive**
for X unless the prompt explicitly scopes it down. Never ship a create
with no edit. Never ship an edit with no archive. Never ship an archive
with no restore path — an archived record an admin cannot bring back is
a hard delete wearing a soft-delete costume. **Hard deletes are
forbidden anywhere in CoreX, no exceptions** (CLAUDE.md Non-negotiable
#1). Asking for "full CRUD" should never be a thought — it is the
floor.

### 1b. Every list screen ships with search, sort, filter, pagination, and a real empty state

No exceptions, no "add it later":

- **Search** — across the fields a user would actually search by. The
  spec names those fields explicitly; "search" with no named fields is
  not a spec, it's a placeholder.
- **Sort** — on every sensible column, with a **stated default sort**.
  A list with no default order is non-deterministic to the user — same
  query, different-looking results, every reload.
- **Filter** — by status and by date range, at minimum. Add
  domain-specific filters (branch, agent, type) where the entity has
  them.
- **Pagination** — a sensible page size. Never dump an unbounded result
  set into the DOM.
- **A real empty state** — copy that tells the user why the list is
  empty and what to do next (no results for this filter vs. genuinely
  nothing yet are different messages). A blank table with no rows and
  no explanation is a bug, not an edge case.

### 1c. Three visibility levels, always: OWN / BRANCH / AGENCY

Every list, every detail view, every export, every document download,
and every API endpoint respects three scoping levels:

- **OWN** — records belonging to the logged-in user.
- **BRANCH** — records belonging to their branch.
- **AGENCY** — records belonging to their agency.

Which level a given user sees is **permission-driven**, decided at spec
time per screen. This is layered on top of, not a replacement for,
CLAUDE.md Non-negotiable #7 (multi-tenancy / `AgencyScope`) — agency is
the outer boundary that can never be crossed; own/branch is the
narrower scoping WITHIN an agency that a role's permissions resolve.

**An agency must never see another agency's data. This is a hard
security boundary, enforced at the query layer** (`BelongsToAgency` /
the global `AgencyScope`), **never by hiding a link in the UI.**
Removing a menu item is not access control. **Direct-URL access by ID
must be blocked, not just absent from the menu** — every `show`/`edit`/
`destroy`/download/export action re-checks scope against the
authenticated user's own/branch/agency, independent of how the request
arrived. A controller that trusts "they wouldn't have found the URL" is
a security bug, not a low-risk gap.

### 1d. This is design-time, not retrofit

The spec for any new feature states, **before code is written**:

- the search fields, named explicitly,
- the sort columns and the stated default,
- the filters,
- and how own/branch/agency scoping is enforced, **per screen** (list,
  detail, export, download, API).

**A spec missing these is not ready to build.** This is not a
checklist item to satisfy after the feature works — the input-space
rule in §2 below and the CRUD/scoping standard here are decided
together, at the same spec stage, for the same reason: discovering
either after code is written means a rebuild, not a review comment.

---

## 2. The input-space rule (this is the one that keeps biting us)

For EVERY field a user can touch, the build must handle the entire
input space, not the example in the spec:

- **Required-but-empty** → reject at validation with a message a
  non-technical user understands. Never let it reach the DB and 500.
- **Optional-and-empty** → accept gracefully. Empty optional field must
  NEVER cause an error. (The `array_filter` class of bug: an optional
  filter that strips NOT-NULL columns. BANNED. NOT-NULL columns always
  get a value — '' or a sensible default — they are never filtered out.)
- **Optional-and-filled-but-malformed** → validate format, reject with a
  clear message, do not crash.
- **The lazy-but-valid shortcut** → e.g. "first name + phone, hit send."
  If it's legal per the rules, it MUST work end to end. This is how
  users actually behave. It is a first-class path, not an edge case.
- **Whitespace** → trim before validation. Leading/trailing spaces on
  email/phone/name never cause a reject or a duplicate.
- **Wrong order** → if a user can reach step 3 before step 2, either
  prevent it in the UI or handle it server-side. Never assume sequence.

**Schema is the contract.** Before writing any create/update, read the
migration. Every NOT-NULL column without a DB default MUST be supplied a
value by the code, every time, for every input combination. Prove it.

---

## 3. Guard rails: prevent OR absorb, never break

For any input that could break the system, exactly one of two things
must be true, by design:

1. **Prevent** — the UI/validation does not allow the breaking entry
   (disabled submit, required field, format mask, confirm dialog), OR
2. **Absorb** — the system accepts the non-entry/odd-entry and continues
   without breaking (sensible default, graceful skip, null-safe path).

There is no third option. "It errors if the user does X" is a defect,
not a known limitation. Decide prevent-or-absorb for every breaking
input AT SPEC TIME, before code is written.

---

## 4. Errors are for users, not stack traces

- No raw 500 / SQLSTATE / exception page ever reaches a user. Catch,
  log the technical detail, show the user a plain-language message that
  tells them what to do next.
- A failed action must leave the system in a clean state — transactions
  roll back fully, no half-created records, no orphaned rows.
- "Not found" is a 404 with a friendly page, never a 500.
- Deleted-related-record (link to a deleted contact/property/deal)
  renders gracefully with denormalised data or a clear note — never a
  crash. (We have hit this repeatedly. It is now a standing requirement.)

---

## 5. Tests must mirror reality, not the spec example

A test that only passes `last_name => 'Tester'` is theatre. Every
build's tests MUST include:

- The happy path (all fields).
- **Each optional field omitted, individually** (the empty paths).
- The lazy-but-valid shortcut (minimum legal input).
- One malformed-but-submitted input per validated field.
- The deleted-related-record path where relationships exist.
- Idempotency where the action can be repeated.

Test DATA must look like real CoreX data — real SA addresses, real
phone formats, the messy stuff agents type — NOT "Test / Test /
0000000000". If the demo/seed data is clean-world, the tests built on it
are lying. Seed data mirrors live-world messiness on purpose.

When VS Code reports "tests pass," the report must state WHICH input
paths were tested. "12 tests pass" means nothing. "Tests pass for:
all-fields, no-last-name, email-only, malformed-phone-rejected,
deleted-contact-renders" means something.

### 5a. Verification has two independent axes — vary both

A "verified working" report can still miss a real bug if it only varies
one axis of how a test is run. AT-392's RA-04/RA-06 pair (2026-09-08) is
the concrete case this rule is written from: a highlight-save endpoint was
re-verified over real HTTP — real login, real CSRF, real curl, a real
database read — specifically because in-process dispatch was suspected of
producing false positives. That re-verification passed cleanly and was
correct as far as it went. Minutes later, adversarial testing against a
document whose highlight row had already been through a soft-delete/
recreate cycle threw a raw SQLSTATE error straight to the browser. The
real-HTTP re-verification could not have caught it, for a specific
mechanical reason, not a rigor gap.

The two axes:

- **Transport** — real HTTP request vs. in-process dispatch.
- **Data state** — a clean/fresh fixture vs. an already-touched record.

These are ORTHOGONAL. Upgrading transport rigor says nothing about
data-state rigor, and vice versa. A test that varies only the axis that
was under suspicion and then reports the feature "proven end to end" is
a false positive waiting to happen. **Verify both axes, not just the one
someone doubted.**

- A clean-fixture, single-pass test only proves a table's CREATE path.
  It proves nothing about what happens the second time a record with
  that same key comes back into existence.
- Any table combining a UNIQUE constraint with SoftDeletes requires an
  explicit **create → soft-delete → recreate** pass before it is called
  verified. MySQL's unique index has no soft-delete awareness — it
  enforces uniqueness across ALL rows, trashed or not — while Eloquent's
  default query scope (used by `firstOrNew`, `find`, `where`, etc.) hides
  trashed rows from the very query that would otherwise find and restore
  them instead of colliding with them. A naive `firstOrNew()`-then-
  `save()` on a key that was ever soft-deleted throws a raw duplicate-key
  exception, not a graceful restore, unless the code explicitly queries
  `withTrashed()` and calls `restore()`.
- Adversarial testing deliberately reuses already-touched records as
  standard practice, not just fresh fixtures. Production data is never
  clean — a real agency database has records that were created, edited,
  cleared, and re-entered many times over. A test suite that only ever
  exercises brand-new rows is testing a database that does not exist in
  production.

### 5b. Never perform, inside a test, the exact automatic behaviour the test exists to check

A distinct failure mode from §5a, found the same night on the same
module: a test that verifies an AUTOMATIC/PROACTIVE behaviour (autosave,
auto-focus, auto-anything the code is supposed to do without being
asked) must never manually trigger that same behaviour itself before
checking whether it happened. Doing so silently changes the claim being
tested — from "does the code do this on its own" to the much weaker "is
this element/state capable of this at all" — and the test will pass
either way, so the difference is invisible until someone else runs the
real thing without the same manual step.

Concretely: a note-placement feature was supposed to auto-focus a text
box the instant it appeared (`$nextTick(() => el.focus())`). The
verification script placed the note, then called `.click()` on the
textarea itself, THEN checked focus and typed — and reported success.
That `.click()` was never part of the feature; it was the tester's own
workaround, inserted one line before the assertion, and it made a
genuinely broken auto-focus pass every time. The real bug — the
underlying `x-ref` was shared across every loop iteration of a list, so
Alpine's ref resolution was unreliable and `.focus()` was silently
landing on a hidden element for a different item, which real browsers
don't even error on — was found only when a second person drove the
real screen with no such extra click and watched
`document.activeElement` stay on the wrong element.

**The rule:** when a test's own actions include a step that duplicates
what the code under test is supposed to do automatically, stop and
delete that step before trusting the result. Check the state
IMMEDIATELY after the triggering action (the one a real user actually
performs — clicking to place the note, not clicking on its result), with
zero intervening steps of your own. If the test needs an extra action to
make the assertion pass, that extra action IS the missing feature.

---

## 6. Fix the class, not the instance

When a bug is found, grep the codebase for every sibling occurrence of
the same pattern and fix them all in one pass. One `array_filter`
NOT-NULL bug means every `Model::create(array_filter(...))` in the
codebase is suspect. Find them all. A senior engineer kills the class of
defect, not the one instance the user happened to hit.

---

## 7. Navigation & access are part of the feature

Every new page/feature includes its navigation entry (sidebar, menu, or
button) AND its permission gate in the same build. A page a user cannot
reach, or can reach without permission, is not done.

---

## 8. Definition of Done (the checklist every build is held to)

A feature is DONE only when ALL apply:

- [ ] Full CRUD present — create, read, update, archive, AND restore (or explicitly scoped out in the prompt)
- [ ] List screen has search (named fields), sort (every sensible column + stated default), filter (status + date range minimum), pagination, and a real empty state
- [ ] OWN / BRANCH / AGENCY scoping enforced at the query layer on every list, detail view, export, download, and API endpoint for this feature — verified by direct-URL-by-ID test, not just absence from the menu
- [ ] Every NOT-NULL column supplied a value for every input combination
- [ ] Every optional-empty path accepted gracefully (no 500)
- [ ] Every required-empty path rejected with a user-clear message
- [ ] The lazy-but-valid shortcut works end to end
- [ ] Prevent-or-absorb decided and implemented for every breaking input
- [ ] No raw error reaches the user; transactions roll back cleanly
- [ ] Deleted-related-record paths render gracefully
- [ ] Tests cover happy + each-empty + shortcut + malformed + deleted-rel
- [ ] Test/seed data mirrors real-world messiness
- [ ] Sibling occurrences of any fixed bug-class also fixed
- [ ] Navigation entry + permission gate present
- [ ] **Every new SETTING is surfaced in the Agency Onboarding Setup Wizard** in the
      same prompt (`config/agency-onboarding-copy.php` — control + `explain` +
      `affects` + its canonical saver). A setting that exists only on the settings
      page is not done — the wizard is the only place an agency is ever told the
      feature exists, so a missing control means the feature ships inert. Leaving it
      out is Johan's call, not the lane's: ask, then record it in the spec's
      "Deliberately NOT in the wizard" list. CLAUDE.md Non-negotiable #10a.
      **Before wiring a saver, read `.ai/specs/agency-onboarding-setup.md` §6.1** — a
      wizard step posts a SUBSET of the saver's fields, and a saver that coerces an
      absent checkbox to `false` silently wipes settings it never rendered.
- [ ] Verification report states WHICH input paths were proven
- [ ] **Reference data travels with the deploy.** If the feature relies on
      GLOBAL reference rows (settings/types/classes/permissions), those rows are
      provisioned by a MIGRATION BACKFILL, or the owning seeder is registered in
      `deploy:sync-reference-data`. Seeders do NOT run on `git pull` deploys — a
      seeded-only row that isn't registered will silently fail to reach live
      (AT-162: the "Private" calendar type missing on live). Verify the row
      exists on the target after promotion.

If any box is unchecked, the feature is not done — regardless of how
many tests pass.

### Promotion flow — the QA gate (2026-07-04)

Work now flows through a **first-QA site** before Staging:

**build + prove (lane) → deploy QA1 (Johan's first QA) → on pass, rebase + merge to
Staging → deploy the Staging host (final integration QA) → live on Johan's explicit
authorisation.**

- **QA1 = `qatesting1.corexos.co.za`** (`/corex-qa1`, DB `corex_qa1`, branch `QA1`) — a
  real-data live-snapshot clone, `APP_ENV=qa`. Johan's first look at a lane's work on
  real data. **Andre's `qatesting2` (`/corex-qa2`, `QA2`) is his own — never touch it.**
- **QA sites are DISPOSABLE and are NEVER a promotion source.** Live is promoted from
  Staging only. **Staging ⊇ main** is unchanged; **Staging is the final integration gate
  before live.**
- **QA is web-only** (no queue worker / scheduler). Queue- and scheduler-dependent
  features (WA capture/media, transcription, DR2 notifications/escalations/digests, media
  backup) get their **first QA on Staging**, not on QA1. Revisit only if it chafes.
- **Deploy to QA uses `scripts/qa-deploy.sh`** (minimal: fetch → ff `QA1` → migrate →
  clears → chown). **`scripts/deploy.sh` is BANNED on qa1/qa2** until their base includes
  the de-landmined seeder — it carries the agency-blind `DealPipelineTemplateSeeder`
  forceDelete.
- **Outbound is neutralised on QA** (mail → log/localhost, WAHA blanked, PP/Firebase
  blanked) so a QA click can never reach a real person. Any new integration a lane adds
  must be inert on QA before Johan QAs a send path. See `/corex-qa1/NOTES-FOR-ANDRE.md`.

The lane "Definition of Done" deploy step (§8h) therefore has **two** deploy targets in
sequence — QA1 for first QA, then the Staging host for final integration — not one.

### Deploy sequence (every promotion to Staging / live)

`git pull` → `php artisan migrate --force` → **`php artisan deploy:sync-reference-data`**
(idempotent, global-scope) → `view:clear` + `route:clear` + `config:clear` →
reload php-fpm → restart the queue worker. The reference-data step is
non-optional: it is the only thing that carries seeder-owned GLOBAL reference
rows across environments.

### Env-parity check (every promotion) — extensions AND PHP version

CoreX deploys are `git pull` (code only), never provisioning. So a PHP
**extension** or **PHP version** that staging has but live lacks does not exist
on live until a code path 500s or a guard trips (real incident: live php8.3
lacked `imagick`, so the PDF Redact page broke — AT-169). Before/after every
promotion, run the parity check:

1. **Extension parity** — diff the live FPM pool's `php -m` against staging's:
   `comm -13 <(php<live-ver> -m|sort -u) <(php<staging-ver> -m|sort -u)`. For
   every extension staging has that live lacks, decide: does any **promoted or
   live** code path use it? If yes → install the matching `phpX.Y-<ext>` package
   and reload **only** the correct pool. If nothing references it → leave it
   uninstalled and note it (do NOT install unused extensions).
2. **PHP VERSION parity** — the staging FPM pool and the live FPM pool may run
   **different PHP versions** (currently staging = php8.2, live = php8.3). The
   check MUST flag version drift, not just extensions: a version mismatch means
   an extension present on one is package-named for the other (`php8.2-imagick`
   ≠ `php8.3-imagick`) and that behaviour can differ across versions. Record the
   live pool's PHP version and install extensions for THAT version.

Reload only the pool that serves the target environment (`systemctl reload
php<live-ver>-fpm`), never all pools.

---

## 9. How this changes the prompt lifecycle

1. **Spec** — robustness is specced UP FRONT. The spec lists the input
   space, the prevent-or-absorb decision per breaking input, and the
   test matrix. Edge cases are decided BEFORE code, never discovered
   after.
2. **Investigate** — read the migration (NOT-NULL contract), read
   sibling code paths (bug-class scan), read the existing tests (are
   they happy-path theatre?).
3. **Build** — to this standard, not to the happy path.
4. **Verify** — against the input matrix in section 8, with real data.
   Report which paths were proven.
5. **Review (Claude/Johan)** — the report is checked against section 8.
   "Tests pass" is rejected; "these input paths proven" is required.
