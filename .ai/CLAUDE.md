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
4. Find the relevant spec in /.ai/specs/[module].md
5. If no spec exists → STOP. Create the spec first. Get approval. Then build.
6. If the feature touches multiple modules → confirm pillar connections before starting.
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
Every new feature includes permission keys in `CoreXPermissionSeeder.php`, sidebar gating, route middleware, and controller checks. If permissions aren't in, the feature isn't done.

### 6. Production quality only.
No demo modes. No "we'll fix it later." No patches over root causes. If it works, it works correctly. If it doesn't, fix the root cause.

### 7. Multi-tenancy is non-negotiable.
Every tenant-owned table has an `agency_id` column and every tenant-owned model
uses `App\Models\Concerns\BelongsToAgency`. A user of Agency A must never read
or write Agency B data — enforced structurally by the global `AgencyScope`,
not by ad-hoc `where('agency_id', …)` in controllers. New pillar tables ship
with `agency_id` from day one. Full spec: `.ai/specs/multi-tenancy.md`. Do
not use `withoutGlobalScope(AgencyScope::class)` in request code.

### 8. Branch rules.
- `main` = production server (91.99.130.85)
- `HFC2402` = Johan's dev branch
- `andre` = Andre's dev branch
- Hotfixes only go directly to main. Everything else: dev branch → reviewed → merged to main.
- Always check for the other person's commits before merging to main.
- Never push `database.sqlite` — this file must be in `.gitignore`.

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

4. Before declaring done:
   - php artisan view:clear
   - php artisan route:clear
   - php artisan cache:clear
   - php -l on all changed PHP files
   - scripts/dev-check.ps1 (894 tests, 2236 assertions)
   - Verify the feature works end-to-end, not just "no errors"
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
