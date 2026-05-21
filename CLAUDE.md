# CoreX OS — Claude Instructions
> **Root entry point. Read this first. Every session. No exceptions.**
> Last updated: 2026-04-14

---

## What is CoreX OS

CoreX OS is the all-in-one operating system for Home Finders Coastal — a real estate agency on the KZN South Coast, South Africa. It is not a feature-rich website. It is a **real estate operating system** built on four core pillars that every module connects to.

This is a production system used by real agents, managing real deals, real money, and real compliance obligations. There is no "good enough for now." Everything ships production-ready.

---

## MANDATORY: Session Start Protocol

Before touching a single line of code, every session — Johan's or Andre's — must follow this sequence:

```
1. Read /.ai/SYSTEM.md          — pillars, architecture, data model, non-negotiables
2. Read /.ai/STANDARDS.md       — UX rules, execution rules, done criteria
3. Read /.ai/CODEBASE_MAP.md    — file paths, patterns, common gotchas
4. Git sync (non-negotiable #11) — fetch + pull origin/<current branch> AND origin/Staging into the working branch. Resolve conflicts BEFORE touching any other file.
5. Find the relevant spec in /.ai/specs/[module].md
6. If no spec exists → STOP. Create the spec first. Get approval. Then build.
7. If the feature touches multiple modules → confirm pillar connections before starting.
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

### 12. Demo is always a working copy.
The demo environment (`demo1.corexos.co.za`, `/mnt/HC_Volume_103099143/hfc-demo` on the production host, tracking `HFC2402`) is not a snapshot — it is a living working copy. Every dev cycle that touches the database, schema, or any seeder MUST end with the demo migrated, seeded, and verified to match local. The demo's `nexus_os_demo` database is the proving ground: if it can be regenerated end-to-end from `php artisan migrate:fresh --database=demo --force && php artisan demo:seed`, the work is complete; if not, it isn't.

The reason: a stale demo is a dead demo. Walkthroughs that hit empty tables, missing columns, or pre-fix bugs cost trust with every customer interaction. The demo must always be exactly one fetch+migrate behind local — never more.

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
   h. Demo deployment (non-negotiable #12) — if the change touches DB, schema,
      seeder, or anything the demo persona will see: deploy to the demo host
      (git pull + php artisan migrate + view:clear + config:clear; reseed if
      data-shape changed) and verify parity against local. Report the demo
      verification result.
```

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
| Server | Ubuntu at 91.99.130.85, codebase at /hfc |
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
