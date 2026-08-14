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

This applies to the conductor too.


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

### Rule 13: Full CRUD is Non-Negotiable
Every created entity must have create, read, update, and delete paths. No orphan records.

### Rule 14: Every Action Must Be Reversible
Undo, soft-delete, or archive. Never hard delete.

### Rule 15: Read Specs Before Coding
Before any code changes, read CLAUDE.md, STANDARDS.md, and the relevant spec from .ai/specs/. Design decisions in the spec override assumptions.

### Rule 16: Functional Verification Required
php -l and dev-check are necessary but not sufficient. Every feature must be verified via Tinker or equivalent to confirm it actually works end-to-end, not just compiles.

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
