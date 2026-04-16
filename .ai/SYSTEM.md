# CoreX OS — System Architecture

## What CoreX Is

CoreX OS is a real estate operating system — not a CRM, not a listing portal, not a document tool. It is the complete operational backbone of a real estate agency. Everything an agent, principal, or administrator needs to run the business lives here. No switching between tools. No re-entering data. One system, one source of truth.

The long-term vision is to replace every third-party tool the agency currently runs: P24 (portal), Sage (accounting), DocuSign (signatures), and all standalone compliance tools.

---

## The Five Foundational Concepts

Every architectural decision, every feature, every module must be evaluated against these five concepts. If something doesn't serve them, it doesn't get built.

---

### 1. The Four Pillars

Every piece of data in CoreX connects to one or more of the four pillars. These are the core entities of the system.

| Pillar | Model | Represents |
|--------|-------|------------|
| Property | `Property` | The physical asset — residential, commercial, land |
| Contact | `Contact` | Any person — buyer, seller, landlord, tenant, attorney |
| Deal | `Deal` | Any transaction — sale, rental, renewal, referral |
| Agent | `User` (agent role) | The practitioner — salaried agent, commission agent, principal |

**Rule:** No module is an island. Every module must link its data back to the relevant pillar(s). A listing links to Property + Agent. A lease links to Property + Contact (landlord + tenant) + Deal + Agent. A commission record links to Deal + Agent.

---

### 2. Flows

Flows are the operating model of CoreX. Real estate is not a series of disconnected tasks — it is a continuous lifecycle, and every stage flows into the next at the click of a button.

**The full lifecycle:**

```
PROPERTY IDENTIFIED
      ↓
LISTING APPOINTMENT → Presentation Flow
      ↓
MANDATE SIGNED → Mandate Flow (sole/open/rental)
      ↓
PROPERTY MARKETED → Listing live (P24, portal)
      ↓
OFFER RECEIVED → OTP Flow
      ↓
OFFER ACCEPTED → Sale Flow (Deed of Sale, FICA, compliance)
      ↓
REGISTERED → Deal closed → Commission calculated → Agent paid
      ↓
════════════════════════════ RENTAL TRACK ════════════════════════════
RENTAL MANDATE SIGNED → Rental Pack Flow
      ↓
TENANT FOUND → Lease Flow (Lease Agreement, deposit receipt, FICA)
      ↓
LEASE ACTIVE → Property under management
      ↓
RENEWAL DUE → Renewal Flow (new terms, updated lease)
      ↓
TENANT VACATES → Exit Inspection Flow → Re-listing Flow
      ↓
BACK TO TOP → Property re-enters the lifecycle
```

**Flow principles:**
- Every arrow is a button click — the system offers the next step automatically
- Flows carry data forward — property, contacts, deal details pre-fill into the next stage
- Flows save state — an agent can start Monday and resume Wednesday
- Completed flows enrich the pillars — every flow makes the next one faster
- The Flow Dashboard is the home screen of CoreX — agents see active flows the moment they log in

**Flow states:** `draft` → `in_progress` → `awaiting_signature` → `awaiting_counterparty` → `complete` → `archived`

---

### 3. No Hardcoding — Ever

Every dropdown, status, type, and category in CoreX must come from a settings table. Never from a PHP array. Never from a hardcoded Blade collection.

This is non-negotiable because:
- CoreX will be licensed to multiple agencies — each configures their own terminology
- Agencies change their processes — the system must adapt without code changes
- Hardcoded values create rebuild debt that compounds over time

**Examples of what must NOT be hardcoded:**
- Property types (House, Apartment, Vacant Land...)
- Listing statuses (Active, Under Offer, Sold...)
- Mandate types (Sole Mandate, Open Mandate...)
- Contact types (Buyer, Seller, Landlord, Tenant...)
- Deal types (Sale, Rental, Commercial...)
- Pipeline stages
- Document categories
- Compliance checklist items

---

### 4. Web Documents + Puppeteer (DomPDF is Dead)

All documents in CoreX are HTML/Blade web documents rendered to PDF via Puppeteer. The old PDF overlay system (image + stamped text) is retired.

**Why Puppeteer:**
- DomPDF interprets CSS — the gap between interpretation and reality breaks legal document layouts
- Puppeteer renders real Chrome — what you see in the browser is exactly what prints
- Document fidelity is non-negotiable: a character that changes is a legally compromised document
- The server already runs Puppeteer for presentations — extend it to all document generation

**Rule:** DomPDF must not be introduced anywhere new. Existing DomPDF templates are migrated to web documents during the consolidation sprint.

---

### 5. Settings Architecture

One settings menu, grouped by module. Agents and admins configure the system — the system does not configure itself.

```
Settings
  ├── Company & Branches
  ├── Users & Roles
  ├── Properties        ← property types, listing statuses, bedroom configs
  ├── Contacts          ← contact types, relationship types
  ├── Deals             ← deal types, pipeline statuses, mandate types
  ├── Documents         ← document types, clause libraries, template categories
  ├── Compliance        ← FICA checklist items, POPIA categories, PPRA requirements
  └── System            ← VAT rate, currency format, email config, API keys
```

---

## Multi-Tenancy (Agency Isolation)

CoreX OS is multi-tenant. Each agency is a hard boundary — a user of Agency A
must never read or write Agency B data.

**How it's enforced (structural, not by convention):**

- Every tenant-owned table carries an `agency_id` column — `users`, `branches`,
  `properties`, `contacts`, `deals`, `presentations`, `documents` and all
  downstream operational tables.
- Every corresponding Eloquent model uses `App\Models\Concerns\BelongsToAgency`,
  which registers `App\Models\Scopes\AgencyScope` as a global scope and
  auto-fills `agency_id` on creation from `Auth::user()->effectiveAgencyId()`.
- `AgencyScope` constrains every query to the effective agency, with
  `agency_id IS NULL` treated as shared/global. It skips when no user is
  authenticated (console, login) and for owner-role accounts that have not
  entered the agency switcher.
- `AgencySwitcherController` authorises the target agency before writing
  `active_agency_id` into session — owners may switch anywhere, everyone
  else may only switch to agencies they already belong to.

**Rules for every feature:**

1. New tenant table → `agency_id` from migration one, model uses the trait.
2. No manual `where('agency_id', …)` in new code; the scope does it.
3. No `withoutGlobalScope(AgencyScope::class)` in request code — console /
   queues only, and only with a documented reason.

Full spec and verification checklist: `.ai/specs/multi-tenancy.md`.

---

## Tech Stack

| Item | Value |
|------|-------|
| Framework | Laravel PHP 8.x + Blade + Alpine.js |
| PDF generation | Puppeteer (DomPDF deprecated) |
| Frontend build | Vite — `npm run dev` (local), `npm run build` (prod) |
| Database | MySQL (Laragon local, MySQL server) |
| Layout system | `corex-app.blade.php` + `corex-sidebar.blade.php` |
| AI — Ellie | OpenAI GPT-4o |
| AI — extraction fallback | claude-haiku-4-5-20251001 |
| Python AI service | `/opt/hf-ai/app.py` on port 3100 |
| Test suite | `scripts/dev-check.ps1` — 894 tests, 2236 assertions |

---

## Server & Repository

| Item | Value |
|------|-------|
| Server | Ubuntu at `91.99.130.85` |
| Codebase | `/hfc` |
| Domain | `corex.hfcoastal.co.za` |
| GitHub repo | `johan7610/hfc-dash` |
| Branch: production | `main` |
| Branch: Johan dev | `HFC2402` |
| Branch: Andre dev | `andre` |

**Deploy sequence:**
```bash
git stash
git pull
git stash pop
npm run build
php -r "opcache_reset();"
php artisan view:clear
php artisan cache:clear
```

**Rule:** Always check for Andre's commits before pushing to main.

---

## South African Context

| Item | Value |
|------|-------|
| Regulator | PPRA (Property Practitioners Regulatory Authority) — NOT EAAB |
| Legislation | Property Practitioners Act 22 of 2019, FICA, POPIA, CPA |
| Compliance doc | FFC (Fidelity Fund Certificate) — required per agent |
| Currency | ZAR — formatted as `R 1,250,000` |
| VAT | 15% |
| Commission | 5–7.5% + VAT (typical range) |

**Critical:** Always reference PPRA. The EAAB was dissolved in 2021 and replaced by the PPRA under the Property Practitioners Act 22 of 2019.

---

## Ellie — Domain AI

Ellie is CoreX's embedded domain AI. She is NOT a general-purpose chatbot. She is a real estate operations specialist with:
- Knowledge of SA property legislation (PPA, FICA, POPIA, CPA)
- Access to the CoreX knowledge base (vector-embedded documents)
- Awareness of listings, agents, deals, and compliance status
- The ability to surface information and flag issues

**Rule:** Ellie advises. Humans decide. Ellie never makes automated changes to documents, records, or data. She surfaces information — the user acts on it.
