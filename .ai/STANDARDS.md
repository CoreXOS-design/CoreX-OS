# CoreX OS — Prime Directive

# ⛔ NON-NEGOTIABLE OPERATING RULES — READ FIRST, EVERY COMMAND, NO EXCEPTIONS

These override everything else. Violating scope is worse than doing nothing. When in doubt: STOP and report.

1. SCOPE LOCK. Work ONLY on the exact task in the current instruction. Do not touch, edit, refactor, rename, reformat, "improve," clean up, or fix ANY file, feature, module, or behaviour outside that exact task — not even if it looks broken, related, or trivial, and not even if you are "already in the file."

2. NO AUTO-FIX / REPORT-ONLY OUTSIDE SCOPE. If you find a bug, regression, or issue anywhere outside your exact task, STOP and REPORT it to the conductor with exact file:line + root cause. Do NOT change it. Nothing outside the assigned task is changed without Johan's strict, specific, explicit instruction.

3. SPEC-EXACT, NO IMPROVISING. Build strictly to the instruction and the named .ai/specs/ spec. Add NOTHING that was not explicitly asked for — no extra features, fields, pages, UI, or behaviour. If the instruction and the spec conflict, or anything is ambiguous, STOP and ask the conductor. Never guess. Never interpret. Never assume.

4. STAY IN YOUR LANE. Work only in your assigned module. Never wander into another part of CoreX for any reason.

5. QA1 ONLY — JOHAN GATES EVERYTHING. All work lands on QA1 and STAYS there. NEVER promote to Staging or live. Flow: QA1 -> Johan tests on QA1 -> Johan's explicit go -> Staging -> live. No live work of any kind (code OR data) without Johan's specific explicit order for that exact action.

6. NO SILENT EXTRAS. No speculative changes, no "while I was here," no drive-by refactors, no dependency bumps, no formatting sweeps, no touching unrelated files.

7. REPORT EXACTLY. When done, report exactly what changed (files + why) and how you proved it, and confirm nothing outside the task was touched.

8. FULL CRUD, LIST-SCREEN COMPLETENESS, AND OWN/BRANCH/AGENCY SCOPING ARE THE FLOOR — DESIGNED IN, NOT REQUESTED. Johan's words: "we always need proper crud? search / sort / own / branch / agency levels. that should be the design standard. not me asking for it once we get to that stage." Every entity ships with Create, Read, Update, Archive (soft delete only — never hard delete) and Restore from the first build, not as a later ask. Every list screen ships with search (named fields), sort (every sensible column + a stated default), filter (status + date range minimum), pagination, and a real empty state. Every list, detail view, export, download, and API endpoint enforces OWN / BRANCH / AGENCY visibility scoping at the query layer (BelongsToAgency / AgencyScope, never a hidden UI link) — direct-URL access by ID is blocked, not just unlinked. The spec for any new feature states search fields, sort/default, filters, and per-screen scoping BEFORE code is written; a spec missing these is not ready to build. Full detail: BUILD_STANDARD.md §1a, and Rule 13 below.

This applies to the conductor too.


## Standard −1 — The render gate (REQUIRED, before any push touching a Blade file)

Three times in one week a rental-applications screen reached QA1 completely
non-functional — `initialResult`, then `sidebarOpen`/`markupModeActive`/
`markupSidebarPinned` — and every time `php -l` passed, PHPUnit passed, and
the HTTP status was 200. None of those three checks test what the user
actually gets: the server rendered fine: the JavaScript threw on
construction and nothing on the screen worked. A green build and a dead
screen are not mutually exclusive — that is the entire reason this exists.

**Before pushing any change that touches a Blade file with Alpine in it**
(not just rental-applications — anywhere the same class of bug is a risk),
run both of these, in order:

```bash
php8.2 scripts/fetch-authenticated-page.php \
    --app-root=/corex-qa1 --user-id=<a real test fixture id> \
    --url=https://qatesting1.corexos.co.za/<the changed route> \
    --out=/tmp/rendered.html
node scripts/verify-alpine-render.mjs /tmp/rendered.html
```

`fetch-authenticated-page.php` fetches the page as a REAL authenticated
user through the REAL nginx + PHP-FPM path (never an in-process
`Kernel::handle()` call — that runs under a different PHP process than what
actually serves the page, which is exactly the gap that let a "verified
fixed" report stand while the live page was still broken). `--php-bin`
matters: it must match the PHP version of the pool that actually serves the
URL (`php8.2` for qatesting1.corexos.co.za — check
`/etc/nginx/sites-enabled/` if unsure, never assume the box's default
`php` CLI matches).

`verify-alpine-render.mjs` then asserts, on the REAL rendered HTML:

1. **No leaked attribute/script text in the rendered body** — a quote-aware
   tokenizer catches an Alpine attribute's quote closing early (incident
   #2's exact shape: a JS comment inside `x-data="{...}"` contained a
   literal `"`, and everything after it — the rest of x-data, x-init, the
   event handler — escaped the tag and rendered as literal visible text
   above the header). **Part of the pass/fail signal.**
2. **Inline `x-data="{ ... }"` scope check** — declared keys vs. identifiers
   referenced in that element's own subtree. **Warning only** — Alpine's
   real scope resolution walks the full ancestor chain, which this
   heuristic cannot always trace through nested components; read the
   warnings and verify by hand.
3. **Real execution** — every named factory function AND every inline
   object, constructed with its REAL call-site arguments parsed straight
   out of the fetched page (never guessed), every zero-argument method
   called the way Alpine calls them on load. Proven to catch incident #1
   (a `ReferenceError` thrown during construction, which silently kills
   the WHOLE component — every binding on the page reads as undefined, not
   just the one bad reference). **Part of the pass/fail signal.**
4. **Alpine expression compile** — every Alpine attribute value run
   through the EXACT wrap Alpine's own `generateFunctionFromString()`
   applies (read straight out of `node_modules/alpinejs/dist/module.cjs.js`,
   not assumed), then compiled with `new Function`. Added 2026-09-12 after
   incident #3: a bare `try { ... } catch (_) {}` written directly as an
   `x-init` value threw `Unexpected token 'try'` in a real browser — this
   gate passed it clean beforehand. Alpine only auto-wraps a leading
   `if (...)` or a leading `let`/`const`; nothing else (not `try`, `for`,
   `switch`, `function`, `class`) ever gets statement treatment, so any
   other multi-statement attribute body is a guaranteed `SyntaxError`. If
   you need more than one statement in an x-init/x-effect/event-handler
   attribute, put it in a method on the component and call that method —
   `x-init="doTheThing()"` — rather than writing the statement body inline.
   **Part of the pass/fail signal.**

For the fuller browser-level check across an entire user journey (console
error counts, not just one page, plus a real-data assertion — a total with
a figure beside it, a list with rows in it, never just "the labels
rendered"), run `node scripts/rental-smoke.mjs` — see BUILD_STANDARD.md for
the full contract. **A 200 HTTP status is not a pass signal in either
script and must never be treated as one.**

**`scripts/dev-check.ps1` is PowerShell. There is no `pwsh` on this box. It
has never run here, for any build, ever.** Stop citing it as a verification
gate for any change made in this environment — the two scripts above are
its replacement here.

---

## Standard −1a — Your lane's own `TEST_DB_DATABASE`, always set, never shared

Six lanes running `php artisan test` (RefreshDatabase) against the SAME MySQL
schema at once — the default when nothing is configured — corrupts results
under concurrent access and blocks every lane behind whichever one is
running the slowest suite. This happened for real on 2026-09-12: cc6
corrupted its own results running two suites concurrently, cc2 re-ran tests
it had already passed, cc3 and cc4 both sat idle behind slow runs, cc4 was
reduced to polling `SHOW PROCESSLIST`.

The isolation mechanism already exists — `tests/bootstrap.php` resolves the
test schema from a dedicated `TEST_DB_DATABASE` key (shell env, then the
worktree's own gitignored `.env`), whitelisted to `hfc_dash_test` or
`hfc_dash_test_<N>`, and hard-refuses anything else before a single query
runs. The 2026-09-12 incident wasn't a tooling gap — it was assignment: half
the active worktrees had nothing set (silently sharing the default
`hfc_dash_test`), and several DIFFERENT worktrees had independently picked
the SAME suffix, colliding with each other anyway.

**The convention going forward: `TEST_DB_DATABASE` suffix matches your lane
number, permanently, for the life of your worktree.**

| Lane | `TEST_DB_DATABASE` |
|------|---------------------|
| cc1  | `hfc_dash_test_1`   |
| cc2  | `hfc_dash_test_2`   |
| cc3  | `hfc_dash_test_3`   |
| cc4  | `hfc_dash_test_4`   |
| cc5  | `hfc_dash_test_5`   |
| cc6  | `hfc_dash_test_6`   |

Set it once in your worktree's own `.env` (`TEST_DB_DATABASE=hfc_dash_test_N`)
and never touch another lane's value. If you spin up a SECOND worktree
alongside your main one, give it a suffix nobody else is using — check
`SHOW DATABASES LIKE 'hfc_dash_test_%'` first, since ad-hoc one-off suffixes
from past sessions already litter that namespace.

This is orthogonal to the schema-snapshot bootstrap (non-negotiable #12a) —
that makes ONE lane's bootstrap fast; this stops lanes from corrupting or
blocking EACH OTHER. Both matter; neither substitutes for the other.

---

## Standard −1b — Refresh `database/schema/mysql-schema.sql` when you add a migration

Real incident, 2026-09-12: `mysql-schema.sql` was dated 2026-09-10 while three
schema-changing migrations had already landed (`draft_saved_at` on
`rental_applications`, `agency_id` on `rental_application_signatures`,
`autosave_debounce` on `rental_application_qualifying_settings`). Every
lane's `RefreshDatabase` test run was silently building on a schema that
didn't match the code — test evidence from all six lanes was suspect until
this was caught.

**When:** the moment `database/migrations/` gains a file, per non-negotiable
#12a — not at the end of the day, not "next time someone notices tests are
slow." A stale snapshot doesn't fail loudly; it just means a table/column a
new migration added silently doesn't exist yet in every OTHER lane's test
runs, which reads as an unrelated, confusing test failure somewhere else
entirely.

**How, exactly** (do this in a worktree — never against `/corex-qa1`
directly, and never point your default `DB_DATABASE` at a test schema
permanently):

```bash
DB_DATABASE=hfc_dash_test_<your lane number> php8.2 artisan migrate:fresh --force
DB_DATABASE=hfc_dash_test_<your lane number> php8.2 artisan schema:dump
```

Then **strip the `DEFINER` clauses** — `schema:dump` bakes in whichever DB
user happened to run it, which breaks the load for every other user (see
non-negotiable #12a's own writeup of this exact gotcha):

```bash
sed -i 's/\/\*!50017 DEFINER=`[^`]*`@`[^`]*`\*\/ //g' database/schema/mysql-schema.sql
grep -c "DEFINER=" database/schema/mysql-schema.sql   # must print 0
```

Verify the migrations you added actually landed in the dump before
committing — `grep` for a column/table name only that migration introduces;
don't just trust that the command ran:

```bash
grep -c "<your new column name>" database/schema/mysql-schema.sql
```

Commit `database/schema/mysql-schema.sql` in the SAME commit as the
migration, exactly as non-negotiable #12a already says.

**A slow load is not the same problem as a stale snapshot — don't confuse
them.** Loading the snapshot via `mysql-schema.sql .......... DONE` can
legitimately take minutes (observed 2m27s–3m44s on 2026-09-12, and climbing
with more lanes concurrently hammering the same MySQL instance) — that is
real cost from six lanes sharing one box, not a bug. If a test run produces
genuinely ZERO output for a long time, first check with `stdbuf -oL -eL`
(output-buffering can hide the PHPUnit banner itself) and `SHOW FULL
PROCESSLIST` (to see if it's actively loading/migrating vs. actually stuck)
before assuming it's hung. Only treat it as STALE — meaning: fix the
snapshot — if `database/schema/mysql-schema.sql`'s own git history predates
a migration that's already merged.

---

## Standard −1c — Never run `npm run build` to make a feature test pass

`Tests\TestCase::setUp()` calls `$this->withoutVite()` for every feature
test, unconditionally. **A feature test never needs compiled frontend
assets to run** — if a test renders a Blade view containing `@vite(...)`
and you see `ViteManifestNotFoundException`, that is a real bug in that
test's own setup (extending the wrong base class, or something bypassing
`Tests\TestCase`), not a missing build. Do NOT "fix" it by running
`npm install && npm run build` in your worktree — that treats the symptom,
costs real time on every fresh worktree, and masks the actual gap if one
exists.

This used to be ~90 individual test files each calling `withoutVite()`
themselves — real, but scattered, evidence that this is exactly the kind
of thing every new feature test needs and nobody should have to remember.
Fixed at the class (2026-09-12) instead of the instance: it is on by
default now, for every test that extends `Tests\TestCase`, whether or not
that test's author knew it would ever render a view.

---

## Standard −1d — Why "PHPUnit is broken" and "PHPUnit just worked for me" can both be true

Real incident, 2026-09-12: cc5 reported the whole suite fatally blocked;
in the same round cc2, cc3 and cc6 all ran tests successfully. Both were
telling the truth. **The suite has exactly two invocation shapes, and they
do not fail the same way:**

1. **Targeting one specific file** — `php artisan test tests/Feature/X.php`
   or `vendor/bin/phpunit tests/Feature/X.php` (the default per Rule 13 —
   this is what you should almost always be running). PHP/PHPUnit only
   `require`s the classes that ONE file needs. A broken declaration in some
   UNRELATED test file is never loaded, so it can't fatal your run.
2. **Whole-suite discovery** — a bare `php artisan test` / `vendor/bin/
   phpunit` with no path, `--list-tests`, `--testdox`, coverage generation,
   or anything else that has to enumerate the full `tests/` tree. This
   `require`s and reflects on EVERY test class up front, before running
   anything — so ONE test file with an invalid method declaration (a
   private method overriding an inherited public one, or a method
   overriding a `final` PHPUnit method — both are plain fatal PHP errors,
   not warnings) blocks discovery for the ENTIRE suite, for every lane, no
   matter which file they actually wanted to run.

Confirmed by directly requiring the broken class outside PHPUnit and by
running `vendor/bin/phpunit --list-tests` (enumerates without executing —
the fastest way to prove/disprove a discovery-level fatal without paying
for a full run): found and fixed two independent instances this round —
`MobilePhotoEventTest::post()` (private, shadowing the inherited public
`MakesHttpRequests::post()`) and `QueueHealthcheckLaneAwarenessTest::run()`
(overriding PHPUnit's own `final` `TestCase::run()`). Both are the exact
same disease: a test's own private helper method happened to collide with
a name the base test class already owns. **Before naming a private test
helper `post`, `get`, `put`, `delete`, `run`, `assert*`, or anything else
that sounds generic, check it isn't already inherited** —
`php -r "require 'vendor/autoload.php'; print_r(get_class_methods(Tests\TestCase::class));"`
lists everything already spoken for.

**What this means for reading a result on this box:** a RED single-file
result means something in your code (or that file) is genuinely wrong. A
FATAL from a bare/discovery invocation means some OTHER, unrelated test
file has a declaration error — it means nothing about your own change,
but it DOES need fixing (report it, or fix it directly if it's this class
of plain PHP error) before anyone can trust a whole-suite run again. Never
conclude "PHPUnit is broken" from a discovery fatal without first checking
which of the two shapes above produced it.

---

## Standard −1e — A render-gate mock gap is fixed permissively, not by hand-enumerating one method

`verify-alpine-render.mjs`'s check 3 runs real page JS in a Node `vm`
sandbox against fake `window`/`document`/element objects — real incident,
2026-09-12 (cc6): a fake element had no `dataset`, so a pre-existing,
correct `canvas.dataset.someFlag` read threw and failed a file cc6 never
touched. Fixing that one property exposed three more gaps in the exact
same code path in sequence — `getContext('2d')` returning `null` (a real
browser never does, for a supported context type), the fake element
having no `addEventListener`, and a bare `FormData` global missing
entirely.

**The fix for `getContext()` is the pattern to repeat, not the property
list.** Canvas contexts (and anything else with a large, open-ended real
API) get `fakePermissiveObject()` — a Proxy where every property read
returns a no-op function and every write is silently accepted — instead of
hand-listing the handful of methods the ONE component you're looking at
happens to call. Enumerating exactly what today's file needs just moves
the next false failure to the next component that calls a method you
didn't list. `dataset`, `addEventListener`, `dispatchEvent`, `FormData`
stay as concrete stand-ins because their real shape is small, well-known,
and worth being explicit about — permissive stubs are for anything whose
real surface is too large to enumerate honestly.

**If you hit a `[SCRIPT EVAL ERROR]` or a `.method() ERROR` on a file you
didn't touch:** that is very likely this same class of gap, not a real
regression — confirm by checking whether the failing call is a standard,
universally-present browser API (any DOM element method, any Web API
constructor) the sandbox simply never modeled, and if so, fix the sandbox
(this file), never the Blade/JS you didn't touch. Verify a sandbox fix
against several DIFFERENT previously-passing pages afterward, not just the
one that surfaced it — a shared sandbox change can affect every page this
gate has ever checked.

---

## Standard 0 — Operating Principle

Every standard in this file is subordinate to the CoreX Operating Principle (see CLAUDE.md). If a standard conflicts with the principle, the principle wins. If a standard would let a shortcut ship, the standard is wrong and gets revised.

The principle: CoreX is the best real estate OS that will ever exist. Every prompt, every commit, every deferral decision is measured against this. "Good enough for now" never ships.

---

CoreX OS will become the best and biggest real estate operating system in South Africa.

**Technology Choices:** When multiple options exist, always choose the best one. If there is a superior library, API, approach or architecture — use it. Never choose mediocre when world class is available. Cost is a consideration but never a reason to choose inferior technology when better options exist at the same or similar cost.

**Quality Standard:** Every feature built must work seamlessly. A feature that half-works is not acceptable. Debug it until it works properly or do not ship it.

**Vision:** Johan Reichel brings deep real estate industry knowledge spanning operations, compliance, accounting, and agency management. Claude's role is to convert that knowledge into a flawless operating system — one that sets the industry standard.

---

# CoreX OS — Standards

These are the non-negotiable rules for building CoreX. Every developer, every prompt, every feature must comply.

---

## UX Rules

### Navigation — No Orphaned Pages
Every new page or feature must include a navigation path to reach it. A sidebar link, a button, a contextual action — something. If a user cannot navigate to a page without knowing the URL, the feature is incomplete.

### Soft Deletes — No Hard Deletes
CoreX has a no-hard-deletes policy across the entire platform.
- Show a "Delete" button to users
- The underlying action is always archive/soft-delete (`deleted_at` timestamp)
- Admin can recover any archived record
- Andre is implementing `SoftDeletes` across all models — check before adding new ones

### Confirmations Before Destructive Actions
Any action that archives, removes, or irreversibly changes data must show a confirmation dialog. No silent destructive actions.

### Status Always Visible
Every record that has a status (listing, deal, document, compliance item) must display that status clearly on its card/row. Users should never have to open a record to find out where it stands.

### No Silent Locks — Read-Only States Must Explain & Offer A Way Forward
Any read-only / locked / disabled state anywhere in CoreX must (1) SAY why it is locked, and (2) offer the action that unlocks it. Never render a surface silently uneditable — and never link to a screen promising an edit that the destination then refuses. Example: a confirmed (frozen) presentation locks editing; the Analysis screen shows a "Locked — confirmed snapshot. Re-open to edit, then Confirm & Generate" banner with a Re-open button, both page-level and on each locked section. A blocked/hidden action is hidden (no dead buttons); a locked-but-recoverable state is shown WITH its unlock path.

### No Invisible Edits — Editable State Must Be Visually Self-Evident
The sibling of No Silent Locks. When a value IS editable, it must look editable at a glance — without the user reading any hint text. Plain text + an italic "tap to edit" line is NOT an affordance. Render editable values as real form controls: a bordered input box, right-aligned value, a pencil (or equivalent) icon. A user must recognise instantly that these are fields they can change. This is the standard for every edit-in-place section (e.g. the holding-cost components on Analysis, and all Phase C edit-in-place sections). Keep the save/recompute behaviour whatever it is; only the affordance must be self-evident.

### Loading States
Every async operation must show a loading indicator. No blank screens, no silent waits.

### Mobile Awareness
CoreX is used in the field. Agents use phones. Every new page must be usable on a mobile screen — not necessarily pixel-perfect, but functional.

---

## Execution Rules

### Listen To The User — Non-Negotiable
- When the user describes a specific behaviour they want, build exactly that. Do not build an approximation or a "better" alternative.
- When the user says something is not working, believe them. Do not suggest it might be a different problem.
- When the user asks for shift-all-down, build shift-all-down. Not insert. Not swap. Not popover.
- Read the user's request twice before writing any code. If unclear, ask ONE question. Then build.
- Do not tell the user to test something that has not been verified to address their exact request.

### Document Importer — Lessons Learned
- Blank positions in the HTML are FIXED. They cannot be inserted or removed. Only assignments shift.
- When AI misses one blank, all subsequent fields shift wrong. The fix is shift-assignments, not insert-blank.
- Always send BOTH context_before AND context_after to AI — SA lease documents have blanks BEFORE their labels.
- Claude API errors: always run php artisan config:clear before assuming the key is wrong.
- Right tool for right job: Mammoth for HTML, Claude/OpenAI for field detection only.

### Investigation Before Prompt
Before writing any implementation prompt for Andre, always investigate:
- Exact file paths involved
- Exact method names and line numbers
- Exact model relationships
- Exact migration state

Never guess at structure. Check first.

### Fix Root Causes, Not Symptoms
If something is broken, find why it's broken — not the shortest path to making the error disappear. A symptom fix today becomes a compound rebuild in three months.

### No Quick Patches
Over-engineer for correctness. A solution that solves the problem cleanly once is always better than a workaround that needs revisiting.

### Every Spec Approved Before Build Begins
No module gets built without an approved spec in `/.ai/specs/`. Both Johan and Andre must be aligned on the spec before any code is written. The spec is the contract.

### Settings First
Before building any new module, identify every dropdown, status, type, or category it will use. Ensure those values live in settings tables before the feature is built. Never retrofit settings later.

---

## Architectural Laws

### One Source of Truth Per Data Point
If a piece of data exists in the system, it exists in one place. It is never duplicated across tables unless explicitly denormalized for performance with a documented sync strategy.

### Pillar Linkage is Mandatory
Every record created in any module must link to at least one pillar (Property, Contact, Deal, Agent). A document with no linked property and no linked contact is an orphan. Orphans are forbidden.

### Deal Branch Attribution — the selling side owns the deal (AT-192, Johan doctrine)
**A deal belongs to the SELLING agent's acting office.** The selling agent's branch (the office they are acting as when they capture) is the deal's branch. Listing-side agents from a *different* branch are entirely normal (a Shelly Beach listing sold by a Southbroom agent is a **Southbroom** deal) and are **never** a mis-stamp signal. Any future auto-derivation of a deal's branch MUST derive from the **selling side**, never from "any agent whose home branch matches the deal branch" (that heuristic is wrong and is banned from audits). The DR1 capture gate (AT-192 b) takes the branch by **explicit selection** with **no auto-derivation**, so it is already compatible with this doctrine; if derivation is ever added, it derives from the selling agent's acting office.

### Document Fidelity is Non-Negotiable
A web document rendered to PDF must be character-for-character identical to the intended legal document. No autocorrection. No smart quotes. No rewording. No reformatting. If a word changes, the document is legally compromised.

### E-Sign — Signing-view state preservation

During an active signing session, the recipient's signing-view state (captured signatures, captured initials, filled fields, party signing status) is the authoritative record. This state lives in two layers:

1. Persisted server-side: `party.signed_at`, `party.signature_locked_at`, captured signature data, field values stored on the document model
2. Hydrated client-side from #1 on page load via server-rendered Blade

**Forbidden operations during signing:**

- `location.reload()` after any AJAX action — wipes Alpine state including any captured-but-not-yet-submitted signatures
- Full re-fetch of the signing view from JS after an inline action
- Re-rendering signature widgets, initial widgets, or field widgets from JS based on document HTML metadata
- Resetting Alpine `x-data` on partial updates

**Required pattern for inline mutations** (e.g. add condition, flag clause, capture initial):

1. Client POSTs to endpoint
2. Controller returns JSON containing a `rendered_row` (or `rendered_html`) field — server-rendered HTML for ONLY the new/changed element
3. Client appends/replaces ONLY the affected node in the DOM
4. No other widgets touched. No re-render of anything else.

**Canonical implementation:** Phase 1B.9 commit `bb6cc9f` — `SigningController::addCondition()` + `InsertableBlockRenderer::renderConditionRowPublic()` + `add-condition-modal.blade.php` `_appendConditionRow()` handler.

**Why this matters:** Recipients spend 5–15 minutes signing a document. A single inadvertent re-render wipes all that work and destroys trust in the system. This is a P0 invariant.

### Flows Carry Data Forward
When a flow moves from one stage to the next, all relevant data from previous stages is carried forward and pre-filled. Agents never re-enter data the system already knows.

### API Keys and Credentials Live in .env Only
Never in code. Never in the database unless encrypted. Never in comments. `.env` only.

### Database — No SQLite in Repo
`database.sqlite` must be in `.gitignore`. It causes constant merge conflicts and has no place in a MySQL-driven production system.

### Design System Compliance (UI_DESIGN_SYSTEM.md is binding)

Every Blade view rendering new UI MUST start by reading `.ai/specs/UI_DESIGN_SYSTEM.md`. The view's header comment MUST declare the design system version it complies with (e.g. `DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20`).

**FORBIDDEN in any Blade view:**
- Hardcoded colours (`color: #0b2a4a`, `background: white`, `border-color: red`, etc.) for anything a design token covers.
- Hardcoded font families inline (`font-family: 'Plus Jakarta Sans'`) — use Figtree via the cascade.
- Hardcoded radii, shadows, or sizes that diverge from the token scale.

**Required pattern when referencing colours:**
- `var(--token-name, #fallback-hex)` — the var() pattern with the documented token value as a fallback per UI_DESIGN_SYSTEM.md §5.10. This makes views robust if a token fails to resolve at runtime AND auto-upgrades if the token is later refined.

**If a new token is needed:** define it in UI_DESIGN_SYSTEM.md FIRST (with Johan's approval, committed to `main`) BEFORE any view uses it. Do not silently invent tokens.

**Regression guard:** `scripts/check-design-tokens.ps1` greps the `resources/views/corex/` tree for naked hardcoded colours and fails the build if any are found. New CoreX views MUST pass this check.

When in doubt: tokens over hex, components over duplication, patterns over creativity.

### Plain-English Visible Labels (F.8 binding rule)

Every chip, badge, button, or short-form label visible to users MUST be either:

(a) **Plain English** a first-day agent would understand without training — full words preferred over abbreviations, common estate-agent vocabulary, no codenames; OR
(b) **Accompanied by a `title=` tooltip** (or the equivalent CoreX tooltip pattern from UI_DESIGN_SYSTEM.md) explaining what the label means and what clicking it does.

**FORBIDDEN as visible labels:**
- Developer jargon and internal abbreviations: `TP` (use "Property intel"), `KPI`, `R1`/`R2`/`R3` rule names, status enum values (`pitched_recently`, `meeting_set` shown raw — humanise with `str_replace('_', ' ', …)`).
- Acronyms not common to South African estate agents.
- Cryptic icons without a tooltip explaining the action.

**Rationale:** new agents joining HFC should be able to use Market Intelligence on their first morning without a Loom video. Every visible label is a UX commitment that costs nothing to write clearly.

### Universal Match-or-Create for Property Data
Every ingestion path produces or enriches a `tracked_properties` record via `App\Services\Prospecting\TrackedPropertyMatchOrCreateService::matchOrCreate()`. The service uses a 5-strategy match in priority order:

1. **Source-ref exact** — `tracked_property_external_refs(agency_id, source_type, source_ref)`
2. **GPS proximity** — `~5m tolerance` on `cma_gps_lat/lng`, fallback to `lat/lng`
3. **Erf number + suburb** — exact match on both
4. **Normalised address** — street_number + normalised street_name + normalised suburb
5. **Token overlap** — same suburb + ≥2 significant tokens in the street (last resort)

Source attribution is permanent. Every contribution appends a `source_chain` entry (type, ref, date, fields_contributed) AND creates/updates a `tracked_property_external_refs` row. The append-only chain is the audit record of every external system that has said "I think this is the same property".

Two property tiers, clearly separated:

| Tier | Table | Purpose |
|------|-------|---------|
| Agency Stock | `properties` | Formal mandates HFC works |
| Tracked Properties | `tracked_properties` | Every property CoreX has intelligence on |

Promotion to `properties` (Agency Stock) happens when a mandate is signed via `TrackedPropertyMatchOrCreateService::promoteToStock()`. The TrackedProperty record persists post-promotion as the audit trail; its `promoted_to_property_id` points at the operational Property.

This is the architectural mechanism by which CoreX builds a comprehensive property intelligence dataset organically through normal agent work — no manual data entry; no orphaned CMA fields; no duplicate records across portal sources.

---

## Code Style Expectations

### Laravel Conventions
- Models in `app/Models/`
- Services in `app/Services/`
- Controllers thin — business logic in services
- Use Eloquent relationships — never raw joins in controllers
- Migrations for every schema change — no manual DB edits on server

### Blade + Alpine.js
- Use Alpine.js for interactivity — no jQuery
- Use corex layout files: `corex-app.blade.php` + `corex-sidebar.blade.php`
- No inline styles — use Tailwind classes
- Component-level CSS in the component, not in global stylesheets unless truly global

### Naming
- Models: PascalCase singular (`Property`, `Contact`, `Deal`)
- Tables: snake_case plural (`properties`, `contacts`, `deals`)
- Routes: kebab-case (`/deals/create`, `/listings/edit`)
- Blade files: kebab-case (`listing-card.blade.php`)

---

## Prompt Execution Rules

### Rule 13: Full CRUD, list-screen completeness, and own/branch/agency scoping are non-negotiable

Johan, verbatim: *"we always need proper crud? search / sort / own /
branch / agency levels. that should be the design standard. not me
asking for it once we get to that stage."* This is the design standard
from the first line of the spec, not a follow-up ask. Full detail and
rationale: `BUILD_STANDARD.md` §1.

- Every created entity has create, read, update, archive (soft-delete
  only — never hard delete), and restore. No orphan records.
- Every list screen ships with named-field search, sort with a stated
  default, filter (status + date range minimum), pagination, and a real
  empty state.
- Every list, detail view, export, download, and API endpoint enforces
  OWN / BRANCH / AGENCY visibility scoping at the query layer
  (`BelongsToAgency` / `AgencyScope`), never by hiding a UI link.
  Direct-URL access by ID is blocked, not just unlinked.
- The spec states search fields, sort/default, filters, and per-screen
  scoping before code is written. A spec missing these is not ready to
  build.

### Rule 14: Every Action Must Be Reversible
Undo, soft-delete, or archive. Never hard delete.

### Rule 15: Read Specs Before Coding
Before any code changes, read CLAUDE.md, STANDARDS.md, and the relevant spec from .ai/specs/. Design decisions in the spec override assumptions.

### Rule 16: Functional Verification Required
php -l and dev-check are necessary but not sufficient. Every feature must be verified via Tinker or equivalent to confirm it actually works end-to-end, not just compiles.

Verification has two independent axes — transport (real HTTP vs. in-process dispatch) and data state (clean fixture vs. already-touched record) — and varying one proves nothing about the other. See BUILD_STANDARD.md §5a for the full rule and why a real-HTTP re-verification against a fresh fixture still missed a soft-delete/unique-index collision (AT-392 RA-06, 2026-09-08).

---

## Known Limitations

### View-As vs Switch User (Impersonation)

CoreX has TWO user-perspective features. They are NOT the same:

| Feature | Trigger | What it does | Visibility scopes work? |
|---------|---------|--------------|------------------------|
| **View As** (role dropdown) | Owner header dropdown → "View As [role]" | Swaps `role` + `branch_id` in session ONLY. Auth::user() unchanged. | **NO** — scopes still see original user |
| **Switch User** (impersonation) | Sidebar user menu → "Switch User" → pick user | Full `Auth::login($target)`. Auth::user() fully swapped. | **YES** — all scopes behave correctly |

**Rule: To test visibility-scoped features (ContactScope, CalendarVisibilityResolver, future scopes), use "Switch User" — NOT "View As".**

The "View As" role dropdown is useful ONLY for testing permission/UI gating (what menu items appear, what buttons show). It does NOT affect data visibility scopes because `Auth::user()` remains the original super_admin.

**Impersonation system details:**
- Controller: `App\Http\Controllers\Admin\ImpersonateController`
- Routes: `POST /admin/impersonate/{user}` (start), `POST /admin/impersonate/stop` (exit)
- Permission required: `impersonate_users` or owner role
- Audit log: `impersonation_logs` table (admin_user_id, target_user_id, action, ip, user_agent)
- Banner shown during impersonation (amber "Viewing as [name]" with exit button)
- Session marker: `impersonator_id` stores original admin's id for restoration

**Diagnostic pattern:** If a visibility-scoped feature shows wrong results, check which feature was used. If "View As" → switch to "Switch User" instead. If "Switch User" → the scope has a genuine bug.

---

## Rule 17: Never Assume an Agency/Branch Context Exists

The headline defect class of 2026-07-13 (AT-241 super-user calendar 500; MIC
`Call to effectiveAgencyId() on null` 500). Owner/super-admin users, console
commands, queued jobs, webhooks and public endpoints run with **no agency
context** — the acting user's `agency_id` is NULL and `effectiveAgencyId()`
returns NULL. Code that assumes a tenant exists either 500s (FK 1452, or "Call
to a member function on null") or silently writes to the WRONG tenant.

### The two failure shapes
1. **Accessor on a possibly-null receiver** → `Call to a member function
   effectiveAgencyId() on null`. E.g. `$deal->agent->effectiveAgencyId()` when
   `agent` is null; `Auth::user()->effectiveAgencyId()` in an unauthenticated
   (console/webhook/job) path where `user()` is null.
2. **Hardcoded-agency fallback** → `effectiveAgencyId() ?? 1`. `??` only catches
   null and falls back to a HARDCODED agency id that (a) may not exist (FK 1452
   on a firstOrCreate, or on any install where the one agency isn't id 1) and
   (b) is the WRONG tenant for a null-agency user.

### The canonical safe pattern

**Reading agency-scoped settings/config for the acting user** — resolve to the
sentinel `0` with `?:` (NOT `?? 1`), and route through a consumer that GUARDS
`<= 0`, returning unsaved in-memory defaults (never persisting):

```php
// GOOD — AgencyContactSettings::forAgency() has the <=0 guard (returns defaults, no write):
AgencyContactSettings::forAgency((int) ($user->effectiveAgencyId() ?: 0))->calendarPollSeconds();
//   public static function forAgency(int $agencyId): self {
//       if ($agencyId <= 0) { return (new self())->forceFill([...defaults]); } // no FK, no 500
//       return self::firstOrCreate(['agency_id' => $agencyId], $defaults);
//   }

// BAD — assumes agency 1 exists, mis-tenants a null-agency user, FK-1452s where agency 1 is absent:
AgencyContactSettings::forAgency($user->effectiveAgencyId() ?? 1)->calendarPollSeconds();
```

**Calling an accessor on a relation that can be null** — use `?->` and handle null:

```php
$agencyId = $deal->agent?->effectiveAgencyId();   // GOOD
$agencyId = Auth::user()?->effectiveAgencyId();   // GOOD (unauth/console-safe)
$agencyId = $deal->agent->effectiveAgencyId();    // BAD — 500 when agent is null
```

**Writing (stamping agency_id on a new row)** — never invent an agency. Derive
it from the domain object being acted on (the deal's / property's / branch's
agency), OR persist NULL for a legitimately global row (only if the column is
nullable), OR reject with a clear message ("no agency selected — switch into an
agency first"). NEVER stamp a hardcoded `1` or a sentinel `0` into a NOT-NULL /
FK agency column (that is the FK-1452 on write).

### The rule
- No `effectiveAgencyId()` / `effectiveBranchId()` / `->agency_id` on a receiver
  that can be null without `?->` or a prior guard.
- No `?? <hardcoded agency id>`. Reads use `?: 0` + a `<= 0` guard.
- A resolved-null agency on a WRITE is derive-from-context or reject — never a
  hardcoded or sentinel stamp into a NOT-NULL column.
- Sentinel `0` is safe ONLY if the consumer guards `<= 0`. A `?: 0` that flows
  unguarded into a NOT-NULL / FK insert is a latent 1452 — treat it as a bug.

---

## Conductor & Lane Intake Protocol

**This applies to the CONDUCTOR FIRST.** The conductor is the most common source of unchained build orders — an aside in conversation becomes a lane spending hours on code nobody specced. The protocol binds the conductor before it binds any lane.

### 1. Classify before any code moves

Every incoming instruction is classified BEFORE a lane touches code:

- **BUG** → **INVESTIGATE first.** Report the truth with `file:line` references. Get the diagnosis **confirmed**. *Then* fix. Never fix on a guess; never fix before the reporter agrees the diagnosis is right.
- **IDEA / DESIGN** → **DISCUSS to settled** → **written spec** → **Johan's explicit sign-off** → **ticket** → **queue**. No code before that chain is complete.

### 2. MODE:BUILD is only legal with the chain in the prompt

`MODE:BUILD` requires **BOTH**:

1. a **ticket reference**, AND
2. **either** Johan's **quoted word** **or** a **signed spec**, present in the prompt.

**`MODE:INVESTIGATION` is the default for everything else.** Absence of the chain does not mean "use judgement" — it means investigate and report.

### 3. No lane accepts an unchained build order

A lane receiving a build order without that chain **pushes back to the conductor**. "The conductor told me to" is **not** authorization. Relayed authority is not authority.

### 4. QA refuses certification of work built outside the chain

**No chain, no certification.** QA does not certify code that skipped classification, spec, or sign-off — regardless of whether it happens to work.

### 5. Spec-conformance line (mandatory)

Every **READY-TO-LAND** report must carry a **spec-conformance line**:

- which spec **§§** the landing implements, **and**
- any **deviation DECLARED** explicitly,
- **or** the words **"no governing spec"** stated outright.

QA enforces this at certification: **no conformance line, no certification.**

### Why this rule exists — today's cost cases

- **Region seed / town remodel** — built from a conversational aside. No ticket, no spec, no sign-off. It consumed a lane and landed on qa1 before anyone asked whether it was wanted.
- **AT-220 connection light** — the spec said a **persistent header indicator on every long-lived screen**. What shipped was an indicator at the **bottom of two DocuPerfect pages**. Nobody compared the artifact to the spec. Conformance is now **audited, not assumed**.
- **Green-for-mechanics vs proven-in-data** — a gate passed on *import mechanics* (a marked document parses to 29 fields) was read as proof the *contract existed in data*. It did not: no template row was ever saved. A check that measures whether a pipeline **can** work is not evidence that it **has** worked on real data. State which noun you measured.

The common failure in all three: **the check measured the wrong noun, and drift survived because nobody compared the artifact to the spec.**
