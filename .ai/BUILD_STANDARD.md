# CoreX — Build Standard (Robustness Charter, lean)
> MANDATORY pre-read. Defines what "done" means: code that only passes its own happy-path test is NOT done. Operating rules → `CLAUDE.md` (not repeated). Section numbers are cited across the codebase — do not renumber. Pre-lean text with incident stories: `.ai/archive/pre-lean-BUILD_STANDARD.md`.

## §0a. Render gate + browser smoke — required for any Blade/Alpine change
`php -l` and PHPUnit passing are necessary and PROVEN NOT SUFFICIENT: three incidents shipped a dead screen to QA1 with both green and HTTP 200. A feature touching a Blade file with Alpine is not done until the render gate (and, for rental screens, the smoke/click-through) is green; **a 200 status is never a pass signal.** Procedure, who runs it and when → skill `render-gate`. Lane tests → skill `worktree-and-lane-test` (`scripts/lane-test.sh`, never `artisan test` directly). `scripts/dev-check.ps1` has never run on this box — do not cite it.

## 0. Governing principle
We do complicated so the user does simple — and the user is never perfect. Real users submit half-filled forms, paste messy data, click in the wrong order, skip optional fields, take the lazy-but-valid shortcut. Code that assumes clean input is broken. The system takes anything thrown at it and either handles it gracefully or refuses it clearly — never a 500, never silent data loss, never a meaningless error.

## §1. CRUD, list completeness, scoping are the floor (design-time, never a retrofit)
Johan: *"we always need proper crud? search / sort / own / branch / agency levels… not me asking for it once we get to that stage."*
- **1a Full CRUD.** Anything creatable is readable, updatable, archivable (soft delete) AND restorable. Never create without edit, edit without archive, or archive without a restore path (an unrestorable archive is a hard delete in disguise). Hard deletes are forbidden (CLAUDE.md N1).
- **1b Every list screen:** search across named fields (spec names them); sort on every sensible column with a **stated default** (no default order = non-deterministic); filter by status + date range minimum plus domain filters (branch, agent, type); pagination (never an unbounded DOM); a real empty state (no-results-for-filter ≠ nothing-yet; says what to do next).
- **1c Three visibility levels, always: OWN / BRANCH / AGENCY** on every list, detail, export, download, API endpoint — permission-driven, decided per screen at spec time, layered on top of multi-tenancy (CLAUDE.md N14; agency is the outer boundary, never crossed). Enforced at the query layer (`BelongsToAgency`/`AgencyScope`), never by hiding a link. Every `show`/`edit`/`destroy`/download/export re-checks scope against the authenticated user; a controller that trusts "they wouldn't find the URL" is a security bug.
- **1d Design-time.** The spec states, before code: search fields (named), sort columns + default, filters, and own/branch/agency scoping per screen (list, detail, export, download, API). A spec missing these is not ready to build.

## §2. The input-space rule (the one that keeps biting)
For EVERY field a user can touch, handle the whole input space, not the spec's example:
- **Required-but-empty** → reject at validation with a message a non-technical user understands; never reach the DB and 500.
- **Optional-and-empty** → accept gracefully, never an error. The `array_filter` class of bug (an optional-field filter stripping NOT-NULL columns) is BANNED: NOT-NULL columns always get a value ('' or a sensible default).
- **Optional-and-malformed** → validate format, clear message, no crash.
- **Lazy-but-valid shortcut** (e.g. first name + phone, hit send) → if legal, MUST work end to end; first-class path.
- **Whitespace** → trim before validation (email/phone/name never reject or duplicate on spaces).
- **Wrong order** → prevent it in the UI or handle it server-side; never assume sequence.
- **Absent checkbox** → an omitted checkbox never silently coerces to `false` and wipes a setting the form never rendered (guard boolean writes with `$request->has()`).
- **Schema is the contract:** read the migration first; every NOT-NULL column without a DB default is supplied a value for every input combination. Prove it.

## §3. Guard rails: prevent OR absorb, never break
Every input that could break the system is either **prevented** (disabled submit, required field, mask, confirm) or **absorbed** (sensible default, graceful skip, null-safe path). No third option — "it errors if the user does X" is a defect. Decide per breaking input at SPEC time.

## §4. Errors are for users, not stack traces
No raw 500/SQLSTATE/exception page reaches a user: catch, log detail, show plain language saying what to do next. A failed action leaves a clean state (transactions roll back, no half-created or orphaned rows). Not-found is a friendly 404. A link to a deleted/archived related record (contact/property/deal/supplier/lease) renders gracefully with denormalised data or a clear note — never a crash (a repeat offender; standing requirement).

## §5. Tests mirror reality, not the spec example
Every build's tests include: happy path (all fields); **each optional field omitted individually**; the lazy-but-valid shortcut; one malformed input per validated field; the deleted-related-record path; idempotency where repeatable. Test data looks like real CoreX data (real SA addresses, phone formats, messy agent typing) — not "Test/0000000000"; seed data mirrors live-world messiness. Reports state WHICH input paths were tested.
- **5a Two independent verification axes — vary both:** *transport* (real HTTP vs in-process) and *data state* (clean fixture vs already-touched record). Upgrading one proves nothing about the other. Any table with a UNIQUE constraint + SoftDeletes needs an explicit **create → soft-delete → recreate** pass: MySQL's unique index ignores soft-delete while Eloquent's default scope hides trashed rows, so a naive `firstOrNew()`+`save()` throws a raw duplicate-key error unless code uses `withTrashed()` and `restore()`. Adversarial tests deliberately reuse already-touched records.
- **5b Never perform, inside a test, the automatic behaviour the test checks.** A test of an automatic behaviour (autosave, auto-focus…) checks state IMMEDIATELY after the real user trigger with zero extra steps of your own; if the test needs an extra action to pass, that action IS the missing feature.

## §6. Fix the class, not the instance
On any bug, grep for every sibling of the same pattern and fix all in one pass (one `array_filter` NOT-NULL bug ⇒ every `Model::create(array_filter(...))` is suspect).

## §7. Navigation and access are part of the feature
Nav entry (sidebar/menu/button) AND permission gate ship in the same build. Unreachable, or reachable without permission = not done.

## §8. Definition of Done — ALL must apply
- [ ] Full CRUD (create, read, update, archive, restore) — or explicitly scoped out in the prompt
- [ ] List screen: search (named fields), sort (columns + stated default), filter (status + date range min), pagination, real empty state
- [ ] OWN/BRANCH/AGENCY scoping at the query layer on every list, detail, export, download, API endpoint — verified by direct-URL-by-ID, not menu absence
- [ ] Every NOT-NULL column supplied a value for every input combination; optional-empty paths accepted (no 500); required-empty rejected with a user-clear message; the lazy-but-valid shortcut works end to end
- [ ] Prevent-or-absorb decided and implemented per breaking input; no raw error reaches the user; transactions roll back cleanly; deleted-related-record paths render gracefully
- [ ] Tests cover happy + each-empty + shortcut + malformed + deleted-rel; test/seed data mirrors real messiness; sibling occurrences of any fixed bug class also fixed
- [ ] Navigation entry + permission gate (+ Role Manager entry, Features toggle for a sidebar item)
- [ ] **Every new SETTING is surfaced in the Setup Wizard in the same prompt** (control + `explain` + `affects` + canonical saver; omission is Johan's call, recorded in the spec's "Deliberately NOT in the wizard" list) → skill `setup-wizard-rule-10a`
- [ ] Verification report states WHICH input paths were proven
- [ ] **Reference data travels with the deploy:** GLOBAL reference rows (settings/types/classes/permissions) are provisioned by a MIGRATION BACKFILL or the owning seeder is registered in `deploy:sync-reference-data` — seeders do NOT run on `git pull` deploys (AT-162). Verify the row exists on the target after promotion.
- [ ] The spec is updated in the same commit; `.ai/CHAT_STARTER.md` current-state refreshed; the Design Checklist (STANDARDS §1) was answered
If any box is unchecked the feature is not done, however many tests pass.

**Promotion flow, QA gate, deploy sequence, scheduler check, env-parity (PHP extensions AND version), outbound-neutralised-on-QA, Jira-per-build** → skill `promotion-runbook` (the lane's §8h deploy step has two targets in sequence: QA1 for first QA, then Staging for final integration).

## §9. How this changes the prompt lifecycle
1. **Spec** — robustness specced UP FRONT: input space, prevent-or-absorb per breaking input, test matrix. 2. **Investigate** — read the migration (NOT-NULL contract), sibling code paths (bug-class scan), existing tests (happy-path theatre?). 3. **Build** to this standard. 4. **Verify** against §8 with real data; report which paths were proven. 5. **Review** — "tests pass" is rejected; "these input paths proven" is required.
