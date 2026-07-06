# CoreX OS — Claude Instructions
> **Root entry point. Read this first. Every session. No exceptions.**
> Last updated: 2026-05-28

---

## What is CoreX OS

CoreX OS is the all-in-one operating system for Home Finders Coastal — a real estate agency on the KZN South Coast, South Africa. It is not a feature-rich website. It is a **real estate operating system** built on four core pillars that every module connects to.

This is a production system used by real agents, managing real deals, real money, and real compliance obligations. There is no "good enough for now." Everything ships production-ready.

---

## CoreX Operating Principle

CoreX is the best real estate operating system that will ever exist. This is the standard. Not a marketing line — the decision filter.

### What this means in practice

**1. Best-in-class or rebuild.** If DocuSign, Property24, Lightstone, Monday.com, or any other product offers a feature better than what we have — we investigate it, learn from it, and build ours better. "Better" means: more functional, more integrated, more aligned to the actual estate-agent workflow, or all three. Not "matches them" — exceeds them. Done is when ours is the better product.

**2. No shortcuts. Ever.** Quick fixes that work today but require rebuilding later are forbidden. Later does not exist. Now is the only time that exists. If a fix is the wrong shape architecturally, we do it right the first time, even if it costs another hour or another prompt. Half-built features are technical debt that compounds; the only way to ship correctly is to ship correctly.

**3. Integration is the moat.** Every feature in CoreX must integrate seamlessly with every other feature. An e-signed document doesn't just collect signatures — it auto-files, triggers FICA verification, updates the deal pipeline, posts to the calendar, notifies the right parties. A contact is not a record — it's a node in a graph linking properties, deals, calendars, documents, and communications. Integration is not optional; it's the difference between CoreX and a feature list.

**4. Built for agents, not for screens.** Every flow is judged by one question: does this let an agent be an agent, or does it trap them behind a screen? CoreX automates the computer work so agents can do the property work. We simplify by absorbing complexity. Hours of admin become a single button press. The dream is the red button: agent clicks, makes coffee, and the system has done the work.

**5. AI enhances, never replaces.** AI in CoreX accelerates human work — it does not replace the human. Agents stay agents. Compliance officers stay compliance officers. AI handles the tedious parsing, drafting, suggesting, and cross-referencing so humans handle the judgement, the relationships, and the deals.

**6. Constraint is fuel, not excuse.** Where we lack live data feeds (Lightstone, CMA, etc.) we build smarter workflows around the data we have. Where we lack budget for premium APIs, we ship the best version possible with what we have today — and architect the upgrade path for when the budget is there. The constraint is never an excuse for a worse product.

### How this changes every prompt and every commit

Before any decision (architectural, scope, deferral, simplification), ask: **does this make CoreX the best real estate OS that will ever exist, or does it make CoreX merely working?**

- If the answer is "best", proceed.
- If the answer is "working", redesign until it's "best".
- "We'll fix it later" is never an acceptable answer.
- "Good enough for now" is never an acceptable answer.
- "It's how other software does it" is not a reason — we ask whether other software does it correctly, then build ours correctly.

Every line of code, every prompt, every commit message answers to this standard. This is the only standard.

---

## MANDATORY: Session Start Protocol

Before touching a single line of code, every session — Johan's or Andre's — must follow this sequence:

```
1. Read /.ai/SYSTEM.md          — pillars, architecture, data model, non-negotiables
2. Read /.ai/STANDARDS.md       — UX rules, execution rules, done criteria
3. Read /.ai/BUILD_STANDARD.md  — Robustness Charter — what "done" means; input-space rule; prevent-or-absorb; test reality. MANDATORY pre-read.
4. Read /.ai/CODEBASE_MAP.md    — file paths, patterns, common gotchas
5. Git sync (non-negotiable #11) — fetch + pull origin/<current branch> AND origin/Staging into the working branch. Resolve conflicts BEFORE touching any other file.
6. Find the relevant spec in /.ai/specs/[module].md
7. If no spec exists → STOP. Create the spec first. Get approval. Then build.
8. If the feature touches multiple modules → confirm pillar connections before starting.
```

**There is no step 0 that skips this.**

---

## The Four Pillars

Every module in CoreX OS reads from and writes back to at least one of these:

| Pillar | Model | What it represents |
|--------|-------|-------------------|
| **Property** | `Property` | The physical asset — address, type, valuation, history |
| **Contact** | `Contact` | Any person — owner, buyer, tenant, landlord, seller |
| **Deal** | `Deal` | Any transaction — sale, rental, mandate, offer |
| **Agent** | `User` (agent role) | The practitioner — FFC, commission, performance |

**If a module cannot read from its relevant pillars and write enriched data back — it is not done.**

---

## Spec-First Rule

**No spec = no code. This is non-negotiable.**

A spec must exist in `/.ai/specs/[module].md` before any development begins on that module or feature.

### Who writes specs
Either Johan or Andre can draft a spec. The other party reviews it. Both must be aligned before dev starts. Johan commits approved specs to `main`.

### What a spec must contain
- What this feature does and why (business requirement)
- Which pillars it connects to, reads from, writes back to
- Data model / migrations needed
- UI placement and navigation entry
- User flow (step by step)
- Permissions required
- Acceptance criteria — how we know it's done and working
- Files to create or modify

### Spec sync rule
The `/.ai/` folder is the single source of truth. Spec changes are committed to `main` only. Before starting any dev session, pull the latest specs:
```bash
git pull origin main -- .ai/
```
Both Johan (HFC2402) and Andre (andre) always develop against the same approved specs.

---

## Non-Negotiables

These rules are not open for discussion. They apply to every line of code written on this project:

### 1. No hard deletes. Ever.
All "delete" actions are soft deletes (`deleted_at` via Laravel SoftDeletes). The user sees a Delete button. The system archives. Admin can recover. No exceptions — not for documents, deals, contacts, templates, users, or any other model.

### 2. Every new page gets a navigation entry on the same day.
A page without a navigation link does not exist to the user. Sidebar, menu, or button — it must exist. This is built as part of the feature, not added later.

### 3. Spec before code.
Described above. No exceptions.

### 4. Pillars are the spine.
New features connect to the pillars. They do not become new islands. If a feature doesn't connect to at least one pillar, the spec is incomplete.

### 5. Permissions are mandatory.
Every new feature includes permission keys in `config/corex-permissions.php`, sidebar gating, route middleware, and controller checks. If permissions aren't in, the feature isn't done.

### 6. Production quality only.
No demo modes. No "we'll fix it later." No patches over root causes. If it works, it works correctly. If it doesn't, fix the root cause.

### 7. Every API endpoint is registered and discoverable.
All new HTTP API endpoints MUST live under `/api/v1/*` (or another versioned `/api/vN/*` namespace), MUST have a `->name()` on the route, and MUST be reachable from the **Admin → API** catalog page at `/admin/api`. The catalog is auto-generated from Laravel's route table — if the route is registered correctly with the `api/` URI prefix, it appears automatically. Do NOT build hidden JSON endpoints under arbitrary URIs (e.g. `/some-page/data`). One global frontend client (`window.CoreX.api`) consumes them; one catalog lists them. The session-authenticated "who am I" endpoint is `GET /api/v1/logged-user` — fired automatically on every authenticated page via `resources/js/corex-api.js`.

### 8. Branch rules.
- `main` = production server (91.99.130.85)
- `HFC2402` = Johan's dev branch
- `andre` = Andre's dev branch
- Hotfixes only go directly to main. Everything else: dev branch → reviewed → merged to main.
- Always check for the other person's commits before merging to main.
- Never push `database.sqlite` — this file must be in `.gitignore`.

### 9. Cross-pillar reactivity uses domain events.
For any feature that involves cross-pillar reactivity — where a state change in one part of CoreX should trigger updates, notifications, recomputations, or side effects in another part — the relevant build prompt MUST read `.ai/specs/corex-domain-events-spec.md` and use the event/listener pattern from the catalogue. Do NOT invent ad-hoc observer hooks, ad-hoc service calls, or ad-hoc query paths between pillars. Emit a named event when state changes; subscribe to existing events when reacting to state changes. The events catalogue is the API contract between pillars.

CoreX is built on the principle that every important domain action sends signals across an interconnected system. The events catalogue is the connective tissue between Property, Contact, Agent, Mandate, Deal, FICA, and Documents. Without this pattern, every feature invents its own reactivity — leading to inconsistent behaviour, hard-to-debug cascades, and architectural debt at branch-merge time. Both Johan's and Andre's branches build to the same catalogue so that features either of them ship plug seamlessly into the work the other is doing.

### 10. Universal Match-or-Create Rule.
Every data ingress into CoreX — CMA presentations, P24 alerts, PP feed events, Chrome capture imports, manual entries, mandate signings, scraping outputs, deeds-office lookups, any future source — MUST call `App\Services\Prospecting\TrackedPropertyMatchOrCreateService::matchOrCreate()` before storing property data. Match first, create only if no match. Every contribution appends to `source_chain` for audit. No property data ever sits orphaned.

There are two property tiers, clearly separated:

| Tier | Table | Purpose |
|------|-------|---------|
| Agency Stock | `properties` | Formal mandates HFC works (My Listings) |
| Tracked Properties | `tracked_properties` | Every property CoreX has intelligence on (Prospecting → Tracked Properties) |

Promotion from Tracked → Stock happens when a mandate is signed, via `promoteToStock()`. Promotion preserves the audit chain — the Tracked Property record stays as the long-lived audit trail, and its `promoted_to_property_id` points at the operational Property. Resolution uses a 5-strategy match: source-ref exact → GPS proximity (~5m) → erf+suburb → normalised address → token overlap. This is the architectural mechanism by which CoreX builds a comprehensive property intelligence dataset organically through normal agent work.

### 11. Git sync at session boundaries.
Every VS Code session begins with the mandatory pre-reads (CLAUDE.md, STANDARDS.md, CODEBASE_MAP.md, the relevant spec) and THEN — before any other work — runs `git fetch --all --prune`, `git pull --rebase origin <current branch>`, and merges/rebases `origin/Staging` into the working branch. Conflicts are resolved on the spot, before any other file is touched. Every session ends with `git add` of the changed files, a focused commit message, and `git push origin <current branch>`.

This replaces all separate "sync prompts" — there is no scenario where work begins without a pull or ends without a push. The reason: HFC2402 and andre branches diverge daily; without forced sync at session boundaries, the two developers' commits race each other into Staging with predictable merge pain. Pulling Staging at session start surfaces conflicts on the developer's local clock — not at merge time, not at deploy time. Pushing at session end keeps the remote branch the source of truth for the next session.

### 12a. Test bootstrap uses the schema snapshot.
`database/schema/mysql-schema.sql` is the committed schema snapshot loaded by `RefreshDatabase` instead of replaying all ~190 migrations per test invocation. The snapshot drops test-run bootstrap from ~190s to ~25s (~83% reduction).

**Prerequisite (one-time, per developer machine):** `mysqldump` and `mysql` must be on the system `PATH` so Laravel's `MySqlSchemaState` can dump/load. On laragon installs the binaries live at `C:\laragon\bin\mysql\mysql-<version>\bin`. Add that folder to the user `PATH` via System Properties → Environment Variables (or `setx PATH "%PATH%;C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin"`). If `mysql` isn't on PATH, `php artisan test` will fail with `'mysql' is not recognized as an internal or external command` — fix PATH; do not delete the snapshot.

**When to re-run `schema:dump`:** every time you add a new migration. New migrations DO run on top of the snapshot, so tests stay correct, but every replay costs seconds; keeping the snapshot current keeps the bootstrap fast. Run `php artisan schema:dump` whenever the `database/migrations/` folder gains a file, then `git add database/schema/mysql-schema.sql` and include in the same commit as the migration.

The snapshot is committed to the repo — it travels with the migrations it represents. Production migrations are unaffected: Laravel only uses the snapshot when no migrations have run yet (i.e. fresh test DB / `migrate:fresh`).

### 12. Demo is always a working copy.
The demo environment (`demo1.corexos.co.za`, `/mnt/HC_Volume_103099143/corex-demo` on the production host, tracking `HFC2402`) is not a snapshot — it is a living working copy. Every dev cycle that touches the database, schema, or any seeder MUST end with the demo migrated, seeded, and verified to match local. The demo's `nexus_os_demo` database is the proving ground: if it can be regenerated end-to-end from `php artisan migrate:fresh --database=demo --force && php artisan demo:seed`, the work is complete; if not, it isn't.

The reason: a stale demo is a dead demo. Walkthroughs that hit empty tables, missing columns, or pre-fix bugs cost trust with every customer interaction. The demo must always be exactly one fetch+migrate behind local — never more.

### 13. NEVER run the full test suite (or any broad suite) without Johan's explicit go-ahead.
**This is absolute.** The repo carries a large KNOWN-FAILING infra baseline (hundreds of `QueryException` failures from the test-DB schema gotcha) — running broad suites tells you nothing new and burns 20+ minutes you do not have.

- **Default during active work = the SINGLE most relevant test file only** (the one whose assertions cover the change). If that file passes, the change is clean — proceed.
- **A test command that takes >60s during active work is a rule violation. Kill it and target one file.** Do NOT run `tests/Feature/<Module>` directories, `php artisan test` with no path, or `scripts/dev-check.ps1` mid-task.
- **The full sweep runs ONLY when Johan explicitly says so, or at final merge.** Never infer permission from "I want to be thorough" — thoroughness here means the right single file, not the whole tree.
- When you need to know whether a failure is yours vs baseline: reason about it from the diff (does my change touch the asserted value?), do NOT run the suite to find out.

---

## How to Build Something New

```
1. Is there a spec?
   YES → read it, confirm pillars, build
   NO  → write the spec, get approval, commit to main, then build

2. Before writing any code:
   - Find existing code that does something similar
   - Read CODEBASE_MAP.md for exact file paths
   - Use the INVESTIGATE → COPY → ADAPT pattern
   - Never build from scratch when a working pattern exists

3. One concern per prompt.
   Tightly related changes in the same file = together.
   Everything else = separate prompts, tested one at a time.

4. Read the relevant spec from .ai/specs/ for whatever module you're
   working on. If working on documents/e-sign, read .ai/specs/docuperfect.md
   AND .ai/specs/esignature.md. If no spec exists for the feature, STOP
   and create one before writing any code.

5. Before declaring done — run ALL of these in order:
   a. php -l on every changed PHP file
   b. php artisan view:clear
   c. php artisan route:clear
   d. php artisan cache:clear
   e. scripts/dev-check.ps1 — must pass with 0 new failures
   f. Functional verification via Tinker:
      - If you created a route: verify it resolves
      - If you created a view: verify it renders without error
      - If you created a model: verify it instantiates
      - If you saved data: verify it persists and loads
      - If you built a form: verify the POST endpoint accepts data
      Do NOT mark done until all verification passes.
   g. git add + commit + push to origin/<current branch> (non-negotiable #11).
      No work is "done" until it is on the remote.
   h. Demo/live deployment (non-negotiable #12) — if the change touches DB, schema,
      seeder, or anything the persona will see: deploy to the target host in this
      order — git pull → `php artisan migrate --force` →
      **`php artisan deploy:sync-reference-data`** (idempotent, global-scope —
      carries seeder-owned GLOBAL reference rows that `migrate` does NOT; seeders
      never run on a `git pull` deploy — AT-162) → view:clear + route:clear +
      config:clear → reload php-fpm → restart the worker. Verify parity against
      local and report the verification result. If the feature added a
      must-travel GLOBAL reference row, either backfill it IN the migration or
      register its seeder in `deploy:sync-reference-data`.
      **Env-parity (AT-169):** also diff the live FPM pool's `php -m` (and PHP
      VERSION) against staging's — CoreX deploys are code-only `git pull`, so a
      PHP extension or version the promoted code needs but live lacks will 500 a
      code path (e.g. live php8.3 lacked `imagick` → Redact broke). Install the
      matching `phpX.Y-<ext>` for the live pool's version and reload ONLY that
      pool; never install unused extensions. Full rule in BUILD_STANDARD.md §8.
   i. Update `.ai/CHAT_STARTER.md` — move items between sections
      (LIVE / IN FLIGHT / SPECCED / PARKED) to reflect what landed, prepend a
      dated entry to the Recent decisions log if a decision was made, remove
      completed items from Outstanding small fixes, refresh the "Last updated
      by" header. Keep total length under 350 lines.
   j. Confirm the work shipped meets the CoreX Operating Principle. If the
      close requires noting a deferred limitation, a quick fix, or a "good
      enough for now" compromise — STOP. Either complete the work properly in
      this prompt, or escalate to Johan with a specific proposal for the
      proper fix. Do not ship compromised work and document the compromise as
      if it's acceptable.
```

### E-sign integration moat — pipeline gate

`scripts/dev-check.ps1` enforces a hard rule for changes to the recipient
signing pipeline:

  Pipeline files (any change here MUST be accompanied by a test diff in
  `tests/Feature/Docuperfect/SigningView/` or its supporting fixtures /
  trait):

  - `app/Models/Docuperfect/Template.php`
  - `app/Models/Docuperfect/CdsDraft.php`
  - `app/Services/Docuperfect/SignatureSurfaceNormalizer.php`
  - `app/Services/Docuperfect/LetterheadRefresher.php`
  - `app/Services/Docuperfect/InsertableBlockRenderer.php`
  - `app/Services/Docuperfect/RoleBlockDetectionService.php`
  - `app/Services/Docuperfect/RoleBlockExpansionService.php`
  - `app/Services/Docuperfect/RoleBlockNormalizer.php`
  - `app/Services/Docuperfect/MergedHtmlFreshnessGuard.php`
  - `app/Http/Controllers/Docuperfect/SigningController.php`

The gate exists because the audit at
`.ai/audits/esign-reset-investigation-2026-05-27.md` found that these
files had zero integration tests before the reset — 49 RecipientLoop
unit tests were green while five live bugs shipped to the browser.
Locking the discipline structurally is the answer.

Bypass: `scripts/dev-check.ps1 -SkipPipelineGate` — use ONLY when the
test diff landed in a previous commit and the current commit is a
follow-up cleanup (e.g. a CHAT_STARTER doc update). Never use this
flag to skirt writing a test when you're touching the runtime.

## Subagent file-write rule

When a prompt requires the agent to produce a report file (audit, investigation,
spec, design document, anything where the prompt names a target path), the agent
MUST use file-write tools to create the file at the requested path and verify
the file exists on disk before declaring done. Returning report content only in
the chat reply is not acceptable — the file must persist beyond the chat
session so future prompts, other agents, and humans can read it. This applies
to both the main agent and any spawned subagents.

When delegating an audit task to a subagent, the parent prompt must include:
"Write the report to `<path>` using file-write tools and verify the file exists
before returning. Returning content only in the reply is not sufficient."

---

## How to Fix a Bug

```
1. Get the exact error message and the URL it happens on
2. Read the stack trace — find the actual file and line number
3. Read DIAG_CHECKLIST_UI.md if it's a page showing 0 or blank
4. Fix the root cause, not the symptom
5. php -l on changed files
6. Test the fix
7. Run dev-check.ps1
```

---

## .ai Folder Reference

| File | Purpose | Read when |
|------|---------|-----------|
| `CLAUDE.md` | This file — session entry point | Every session |
| `SYSTEM.md` | Pillars, architecture, data model, tech stack | Every session |
| `STANDARDS.md` | UX rules, execution rules, done criteria | Every session |
| `BUILD_STANDARD.md` | **Robustness Charter** — what "done" means; input-space rule; prevent-or-absorb; "fix the class, not the instance"; the test-reality matrix | **Every session — mandatory pre-read** |
| `CODEBASE_MAP.md` | File paths, patterns, component reference, gotchas | Before touching any file |
| `ROADMAP.md` | What's built, in progress, specced, blocked | When planning or starting a new feature |
| `specs/listings.md` | Listings module spec | When working on listings |
| `specs/contacts.md` | Contacts module spec | When working on contacts |
| `specs/deals.md` | Deals module spec | When working on deals |
| `specs/docuperfect.md` | DocuPerfect module spec | When working on documents |
| `specs/esignature.md` | E-Signature wizard spec | When working on signing |
| `specs/agency-tracker.md` | Agency Tracker spec | When working on deals/commissions |
| `specs/presentations.md` | Presentation system spec | When working on presentations |
| `specs/compliance.md` | Compliance module spec | When working on FICA/POPIA/PPRA |
| `specs/ellie.md` | Ellie AI assistant spec | When working on Ellie |
| `specs/tvadisplay.md` | TV display spec | When working on TV |
| `specs/multi-tenancy.md` | Agency isolation — global scope, switcher rules | Any feature touching the DB |
| `specs/corex-domain-events-spec.md` | Domain events catalogue — system-wide event/listener pattern (architectural foundation) | Whenever a feature involves cross-pillar reactivity |

---

## Tech Stack Quick Reference

| Item | Value |
|------|-------|
| Framework | Laravel (PHP 8.x) + Blade + Alpine.js |
| Build | Vite — `npm run dev` (local), `npm run build` (production) |
| Database | MySQL via Laragon (local), MySQL on server (production) |
| Server | Ubuntu at 91.99.130.85, codebase at /corex |
| Domain | corex.hfcoastal.co.za |
| Repo | johan7610/hfc-dash |
| Python AI | /opt/hf-ai/app.py on port 3100 (hf-ai.service) |
| Tests | scripts/dev-check.ps1 — 894 tests, 2236 assertions |
| Layout | corex-app.blade.php + corex-sidebar.blade.php |

---

## South African Context

- Regulatory authority: **PPRA** (Property Practitioners Regulatory Authority) — never EAAB
- Legislation: Property Practitioners Act 22 of 2019, FICA, POPIA, CPA
- Currency: ZAR — format as `R 1,250,000`
- VAT: 15%
- Commission: typically 5–7.5% + VAT
- Mandate types: Sole, Open, Dual
- FFC: Fidelity Fund Certificate — required per agent, tracked in system

---

## Golden Rule

> We do complicated so the user can do simple.
> Over-engineer for correctness. Fix root causes, not symptoms.
> No patches. No quick fixes. No "later."
> **Later doesn't exist.**
