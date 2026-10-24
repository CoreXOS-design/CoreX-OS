# CoreX OS — Standards (lean)
> Operating rules and usage discipline live in root `CLAUDE.md` — NOT repeated here. This file: the design checklist, UX/architecture laws, and a map from every old "Standard −1x" to where it now lives (labels are cited in code comments, so they are kept). Full pre-lean text with incident history: `.ai/archive/pre-lean-STANDARDS.md`.
> If a standard conflicts with the Operating Principle (CLAUDE.md), the principle wins; a standard that would let a shortcut ship is wrong and gets revised.

## 1. Design checklist — every spec answers ALL of these before build (a spec missing any is not ready)
1. **Layout** — follows Andre's design spec: **[PLACEHOLDER — Andre's design spec, being written the weekend of 25–26 Oct 2026; insert path + version here and delete this line]**. Until then: `.ai/specs/UI_DESIGN_SYSTEM.md` tokens + the `blade-ui-rules` skill.
2. **Agency settings + Setup Wizard** — every agency-specific value/wording/threshold/template is a setting (Rule 9: second agency). Name each setting, its default (neutral, non-HFC), its settings-page home, and its Setup Wizard step (`config/agency-onboarding-copy.php`: control + `explain` + `affects` + saver) — or record it under the wizard spec's "Deliberately NOT in the wizard" list with Johan's say-so. Skill `setup-wizard-rule-10a`.
3. **Role Manager permission** — the permission key(s) in `config/corex-permissions.php`; who can VIEW and who can ACT (per role); sidebar gating, route middleware, controller check.
4. **OWN / BRANCH / AGENCY scoping** — per screen (list, detail, export, download, API): which level each role sees, enforced at the query layer; direct-URL-by-ID blocked.
5. **Full CRUD + list floor** — create / read / update / archive / **restore**; list screen states: search fields (named), sort columns + stated default, filters (status + date range minimum + domain filters), page size, empty-state copy (no-results-for-filter vs genuinely-nothing-yet).
6. **Menu link** — where it sits in the sidebar/hub, its Features on/off toggle (`sidebar-feature-toggle`), and every other screen that links to it.
7. **Jira ticket** — the single ticket for this build (search first; one ticket per build).
8. **Plus (BUILD_STANDARD §9):** pillars read/written, input space + prevent-or-absorb per breaking input, test matrix, domain events emitted/consumed, files to create/modify, acceptance criteria.

## 2. UX rules
- **No orphaned pages** — every page is reachable by a sidebar link, button or contextual action. A sidebar item without a Features on/off toggle is incomplete (skill `sidebar-feature-toggle`).
- **Soft deletes only** — user sees "Delete", system archives; admin can recover. **Confirm** before any archive/removal/irreversible change. Every action reversible (Rule 14).
- **Status always visible** on every card/row that has one.
- **No silent locks:** any read-only/locked/disabled state SAYS why and offers the action that unlocks it (e.g. "Locked — confirmed snapshot. Re-open to edit"). A blocked action is hidden (no dead buttons); a recoverable lock is shown WITH its unlock path.
- **No invisible edits:** editable values look editable at a glance — real form controls (bordered input, pencil icon), never plain text with a "tap to edit" hint.
- **Loading states** on every async operation. **Mobile:** every page usable on a phone.
- **Plain-English visible labels**; design tokens not hex; no raw enum values → skill `blade-ui-rules`.

## 3. Execution rules
- **Listen to the user.** Build exactly the behaviour described — not an approximation or a "better" alternative. When they say it isn't working, believe them. Read the request twice; if unclear ask ONE question, then build. Don't tell them to test something unverified against their exact request.
- **Investigate before prompting/building:** exact file paths, method names + lines, model relationships, migration state. Never guess structure.
- **Fix root causes. No quick patches.** A symptom fix today is a compound rebuild in three months.
- **Settings first / no hardcoding:** every dropdown, status, type, category comes from a settings table, never a PHP array or Blade collection (property types, listing statuses, mandate types, contact types, deal types, pipeline stages, document categories, compliance items). Identify them before building; never retrofit.
- **Every spec approved before build** (CLAUDE.md §6).
- **Technology choices:** when options exist choose the best one (library, API, architecture); cost is a consideration, never a reason for inferior technology when better exists at similar cost. **Quality:** a feature that half-works is not acceptable — debug it until it works or don't ship it.

## 4. Architectural laws
- **One source of truth per data point**; no duplication unless explicitly denormalised with a documented sync.
- **Pillar linkage is mandatory** — a record linking to no Property/Contact/Deal/Agent is an orphan; orphans are forbidden.
- **Deal branch attribution — the selling side owns the deal:** a deal belongs to the SELLING agent's acting office; listing-side agents from another branch are normal, never a mis-stamp signal. Any auto-derivation derives from the selling side, never "any agent whose home branch matches".
- **Document fidelity:** a web document rendered to PDF is character-for-character the intended legal document (no autocorrect, smart quotes, rewording). **E-sign signing-view state is authoritative and never re-rendered or reloaded mid-session** (P0 invariant) → skill `esign-and-documents`.
- **Flows carry data forward** — agents never re-enter what the system knows.
- **Credentials live in `.env` only** — never code, comments, or unencrypted DB.
- **No SQLite in repo** (`database.sqlite` in `.gitignore`).
- **Web documents via Puppeteer** (`scripts/html-to-pdf.mjs`); DomPDF must not be introduced anywhere new.
- **Match-or-Create** (CLAUDE.md #10) uses the 5-strategy order: source-ref exact → GPS ~5 m → erf + suburb → normalised address → token overlap (≥2 street tokens, last resort); `source_chain` is append-only audit.
- **Code style:** models `app/Models` PascalCase singular; tables snake_case plural; services `app/Services`; controllers thin; Eloquent relationships not raw joins in controllers; migrations for every schema change (no manual DB edits); routes kebab-case; Blade files kebab-case; Alpine not jQuery; Tailwind not inline styles; component CSS in the component. **Never pass an inline multi-key array literal (or a `->map(fn => [...])`) straight into `@json()`** — build it in an `@php` variable first (details in `blade-ui-rules`).

## 5. Prompt execution rules
- **Rule 13 — CRUD / list / scoping floor:** CLAUDE.md operating rule 10, detail BUILD_STANDARD §1.
- **Rule 14 — every action reversible** (undo, soft-delete, archive; never hard delete).
- **Rule 15 — read specs before coding:** CLAUDE.md, this file, the relevant spec; spec design decisions override assumptions.
- **Rule 16 — functional verification required:** `php -l`/tests are necessary, not sufficient; verify end-to-end (Tinker or equivalent). Two independent axes — transport (real HTTP vs in-process) and data state (clean vs already-touched record) — vary BOTH (BUILD_STANDARD §5a).

## 6. Rule 17 — never assume an agency/branch context exists
Owner/super-admin users, console commands, jobs, webhooks and public endpoints run with **no agency context** (`effectiveAgencyId()` returns NULL). Two failure shapes: an accessor on a possibly-null receiver (`$deal->agent->effectiveAgencyId()`), and a hardcoded fallback (`?? 1` — wrong tenant, or FK 1452 where agency 1 doesn't exist).
- Reads: resolve with `?: 0` and route through a consumer that guards `<= 0` (returns unsaved defaults, never persists) — e.g. `AgencyContactSettings::forAgency((int) ($user->effectiveAgencyId() ?: 0))`. Sentinel `0` is safe ONLY if the consumer guards `<= 0`; unguarded into a NOT-NULL/FK insert it is a latent 1452.
- Null-able receivers: `?->` (`$deal->agent?->effectiveAgencyId()`, `Auth::user()?->effectiveAgencyId()`), then handle null.
- Writes: never invent an agency — derive from the domain object (deal/property/branch), persist NULL only for a legitimately global nullable column, or reject ("no agency selected — switch into an agency first"). Never stamp a hardcoded `1` or sentinel `0` into a NOT-NULL/FK agency column.

## 7. Rule 18 — a local worktree server never points at a shared database
A worktree's `php artisan serve` (or any local instance) must NEVER have `.env` pointed at a shared DB (`corex_qa1`, `corex_qa2`, Staging, live, another lane's). A DB row written there pairs with a file on the worktree's own disk that vanishes with the worktree (ghost record). To exercise a real write: (1) test in place on the shared environment over its real URL, or (2) use a fully isolated throwaway `hfc_dash_test_*` schema with the worktree's own `.env`. Never a mix.

## 8. Map — old "Standard −1x" labels → where the content lives now
| Old label | Now |
|---|---|
| −1, −1e, −1f, −1u (render gate, mock gaps, click-through, attribute-scoped sweeps) | skill `render-gate` |
| −1s (lanes don't build browser harnesses), −1p (freeze), −1l (only cc1 writes origin/QA1), −1m (branch naming) | skill `landing-checks` |
| −1a, −1c, −1d, −1h, −1i, −1k, −1x, −1y, −1z (test DBs, vite, discovery fatals, contention, disk, lane-test) | skill `worktree-and-lane-test` |
| −1b (schema snapshot) | skill `schema-dump` |
| −1g (migrate guard), −1v (deploy dir), −1w (cds templates) | skill `qa-deploy-and-deploy-dir` |
| −1j, −1n, −1o, −1q, −1r, −1t (proof practice, demo records, walk gate) | skill `verification-practice` |
| UX: sidebar toggle | skill `sidebar-feature-toggle` |
| Design system, plain labels, `@json` | skill `blade-ui-rules` |
| E-sign state, importer lessons, fidelity | skill `esign-and-documents` |
| Intake protocol (BUG/IDEA/MODE:BUILD) | CLAUDE.md §6 |
| View-As vs Switch User | skill `verification-practice` |
