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

This applies to the conductor too.


> **MANDATORY. Read alongside CLAUDE.md, STANDARDS.md, CODEBASE_MAP.md.**
> This file defines what "done" means. Code that only passes its own
> happy-path test is NOT done. This is the senior-engineer baseline.
> Every prompt references this file. No feature is complete until it
> satisfies every section below that applies to it.

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

## 1. Full CRUD is the default, never a request

Every entity that can be created can be read, updated, and archived
(soft-deleted). If a prompt says "add the ability to create X", the
build INCLUDES list, view, edit, and archive for X unless the prompt
explicitly scopes it down. Never ship a create with no edit. Never ship
an edit with no archive. Asking for "full CRUD" should never be a
thought — it is the floor.

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

- [ ] Full CRUD present (or explicitly scoped out in the prompt)
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
