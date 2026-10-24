# CoreX OS — Claude Instructions (lean core)
> Root entry point, auto-loaded. Rules appear ONCE, here. Procedures live in `.claude/skills/` and load only when relevant.
> Originals and incident history: `.ai/archive/` (never read at session start). Rewritten 2026-10-24 — DRAFT awaiting Johan + Andre review.

## What CoreX OS is
The real estate operating system for Home Finders Coastal (KZN South Coast, South Africa) — and every agency after it. Production system: real agents, real deals, real money, real compliance. Ships production-ready or not at all.

**Operating principle (the decision filter):** the best real estate OS that will ever exist — best-in-class or rebuild; no shortcuts ("later does not exist"); integration is the moat (every feature feeds every other); built for agents, not screens (absorb complexity, the "red button"); AI enhances, never replaces; constraint is fuel, not excuse. Before any decision ask: *best, or merely working?* "Good enough for now" and "fix it later" are never answers. Fix root causes, never symptoms.

## 1. Operating rules — non-negotiable, apply to the conductor too
When in doubt: STOP and report. Violating scope is worse than doing nothing.

1. **SCOPE LOCK.** Work ONLY on the exact task in the current instruction. Do not touch, edit, refactor, rename, reformat, "improve," clean up, or fix anything outside it — not even if broken, related, trivial, or "already in the file."
2. **NO AUTO-FIX OUTSIDE SCOPE.** Found a bug/regression elsewhere? STOP and REPORT to the conductor with exact file:line + root cause. Change nothing outside the task without Johan's strict, specific, explicit instruction.
3. **SPEC-EXACT, NO IMPROVISING.** Build strictly to the instruction and the named `.ai/specs/` spec. Add nothing not asked for. Instruction/spec conflict or any ambiguity → STOP and ask. Never guess, interpret or assume.
4. **STAY IN YOUR LANE.** Only your assigned module; never wander into another part of CoreX.
5. **TWO QA LANES — THE LANE OWNER GATES STAGING, JOHAN GATES LIVE.** Johan's work lands on QA1 (branch `QA1`), Andre's on QA2 (`QA2`). Work STAYS on its lane until that lane's owner has tested it and given an explicit go: QA1 → Johan's go → Staging; QA2 → Andre's go → Staging. A lane never promotes the other lane's work. Nothing goes Staging → live, and no live work of any kind (code OR data), without Johan's specific explicit order for that exact action.
6. **NO SILENT EXTRAS.** No speculative changes, "while I was here", drive-by refactors, dependency bumps, formatting sweeps, or unrelated files.
7. **REPORT EXACTLY.** What changed (files + why), how you proved it, and confirmation nothing outside the task was touched. A verification report names WHICH input paths were proven ("12 tests pass" means nothing).
8. **JOHAN IS NOT A PROGRAMMER — write for the business owner.** Ask BUSINESS questions only (what should the user see, what happens next, which outcome is right for the agency). Never hand him an engineering choice (implementation, data model, column, storage, architecture): make the call, state the business consequence in one sentence. Report in plain language — what was broken, what it looked like, what it looks like now, what to click to check. No paths, line numbers, hashes, column or function names unless asked.
9. **MULTI-AGENCY ALWAYS, NEVER SINGLE** (Johan, 2026-09-19: "whatever we do is multi agency. not single. never."). A second real agency (Cape Town, mainly rentals, starts Oct 2026) runs on the same code. No feature, screen, template, document, email, default or setting may assume one agency: no hardcoded agency IDs, no agency-1 defaults, no HFC wording/branding/addresses/signatures in shipped code; every default neutral for a non-HFC agency; anything an agency would want different (including wording, documents, branding) is a configurable setting, not a code constant. The test, every time: *what does this look like for the SECOND agency?* "Wrong" or "HFC's" = not done.
10. **FULL CRUD, LIST COMPLETENESS, OWN/BRANCH/AGENCY SCOPING ARE THE FLOOR — designed in, never requested** (Johan: "we always need proper crud? search / sort / own / branch / agency levels. that should be the design standard."). Every entity ships Create, Read, Update, Archive (soft delete only) and Restore from the first build. Every list ships named-field search, sort (every sensible column + stated default), filter (status + date range minimum), pagination, a real empty state. Every list, detail, export, download and API endpoint enforces OWN / BRANCH / AGENCY scope at the query layer (`BelongsToAgency` / `AgencyScope`, never a hidden link); direct-URL access by ID is blocked, not just unlinked. A spec missing these is not ready to build. Detail: `.ai/BUILD_STANDARD.md` §1.

## 2. Usage and session discipline
1. **Model:** build lanes run Sonnet; never switch a lane to Opus. Architecture decisions are made in the conductor chat.
2. **`/clear` at the start of each day and after every completed task.** A several-hundred-thousand-token lane costs ~10× a cleared one for identical work.
3. **Continuity lives in writing** — standing docs and `.ai/specs/`, not lane memory. Every fix updates its spec in the same commit.
4. **Lane count:** two working lanes plus one kept clear for emergencies. Not six.
5. **No exploring.** Work the exact files and scope given; a lane that hunts reads half the codebase at the account's cost. Anti-drift is a cost control.
6. **No polling / no automated check-in loops.**
7. **Stop line:** development stops at 90% of the weekly cap, resumes when the cycle rolls (Saturday); keep ~20% in reserve for a production fault.
8. **Long or complex shell** (loops, `$(…)`, multi-stage pipes, heredocs): write it to a script file in the scratchpad and run `bash <file>` — inline versions trigger approval prompts that stall lanes for hours.

## 3. Session start (every session, in order)
1. This file (auto-loaded). 2. `.ai/STANDARDS.md` and `.ai/BUILD_STANDARD.md` (the "done" standard — mandatory). 3. The spec for your module: `.ai/specs/<module>.md`. A spec over 100 KB is an **index + `<module>/` section files** — read the index, then ONLY the section files the task names. 4. `.ai/CODEBASE_MAP.md` / `.ai/SYSTEM.md` only when you need file paths or architecture. 5. `.ai/CHAT_STARTER.md` (short current-state) only if the task needs "what is where right now".
6. **Git sync** (#11): `git fetch --all --prune`, `git pull --rebase origin <your branch>`, resolve conflicts before touching anything else. Session ends with `git add` of changed files, a focused commit, `git push origin <your branch>`.
7. **No spec = no code.** Missing → STOP, write the spec, get approval (§6).

## 4. Environment model
| Env | Where | Branch | Notes |
|---|---|---|---|
| **Live / Prod** | 62.238.31.82, `/corex`, DB `corexos`, `corexos.co.za` + `www.` | `Prod` (live checkout tracks `origin/Prod`) | The ONE live host. `corex.hfcoastal.co.za` is retired (308 → corexos.co.za; its DNS + cert must stay). |
| **Staging** | 91.99.130.85, `/corex-staging` | `Staging` | Final integration gate before live. 91.99.130.85 is NOT live. |
| **QA1** | same box, `/corex-qa1`, `qatesting1.corexos.co.za`, DB `corex_qa1`, `APP_ENV=qa` | `QA1` | Johan's first QA, real-data clone, DISPOSABLE, never a promotion source, web-only (no worker/scheduler). |
| **QA2** | `/corex-qa2`, `qatesting2` | `QA2` | Andre's. Never touch from Johan's lanes. |
| **Demo** | `/mnt/HC_Volume_103099143/corex-demo`, `demo1.corexos.co.za` | tracks `HFC2402` | Living working copy (#12). |

Dev branches: `HFC2402` (Johan), `andre` (Andre). Flow: **lane branch → QA1/QA2 → owner tests → Staging → Johan's explicit order → live.** Staging ⊇ main. Live is promoted from Staging only. Hotfixes only direct to main, then back-sync; always check the other person's commits before merging to main. Never push `database.sqlite`. Python AI service: `/opt/hf-ai/app.py` :3100 (not in git).
**Deploy-box rules (procedures → skills):** `/corex-qa1` stays on `QA1`, forever, and only `qa-deploy.sh` ever touches git in it. All lane work happens in a worktree under `/mnt/HC_Volume_103099143/corex-worktrees/<branch>`. Only cc1 writes `origin/QA1`; lanes push their own branch only. Lane branch names begin with the lane (`cc2-…`); never rename a branch mid-build.

## 5. The four pillars
Every module reads from and writes back to at least one; a feature touching none is an island and its spec is incomplete.
| Pillar | Model | |
|---|---|---|
| Property | `Property` | the physical asset |
| Contact | `Contact` | any person (owner, buyer, tenant, landlord, seller) |
| Deal | `Deal` | any transaction |
| Agent | `User` (agent role) | the practitioner (FFC, commission, performance) |

## 6. Spec-first and intake
**No spec = no code.** Johan or Andre drafts; the other reviews; both aligned before dev; approved specs commit to `main` (`git pull origin main -- .ai/` to sync). A spec contains: business requirement and why; pillars read/written; data model/migrations; UI placement + nav entry; step-by-step user flow; permissions; acceptance criteria; files to create/modify — **and answers the Design Checklist in `.ai/STANDARDS.md` §1** (a spec that doesn't is not ready to build).
**Intake:** BUG → investigate, report the truth with file:line, get the diagnosis confirmed, then fix. IDEA → discuss → written spec → Johan's sign-off → ticket → queue. `MODE:BUILD` requires a ticket AND (Johan's quoted word OR a signed spec); anything else is investigation. A lane receiving an unchained build order pushes back to the conductor (relayed authority is not authority). Every READY-TO-LAND report carries a spec-conformance line (spec §§ implemented + declared deviations, or "no governing spec"); QA refuses certification without it. One Jira ticket per build, travelling with it QA1 → Staging → live; search for an existing ticket first; notes and follow-ups are comments on it, duplicates get linked and consolidated.

## 7. Non-negotiables (numbers are cited across the repo — do not renumber)
1. **No hard deletes. Ever.** Delete button → soft delete (`deleted_at`); admin can recover. Confirm dialog before any archive/irreversible action.
2. **Every new page gets a navigation entry the same day**; every sidebar item also gets a Features on/off toggle → skill `sidebar-feature-toggle`.
3. Spec before code.
4. Pillars are the spine.
5. **Permissions are mandatory:** keys in `config/corex-permissions.php`, sidebar gating, route middleware, controller checks, a Role Manager entry (who can view/act). No permissions = not done.
6. **Production quality only.** No demo modes, no patches over root causes.
7. **Every API endpoint** lives under `/api/v1/*`, has `->name()`, appears in Admin → API (`/admin/api`, auto-generated from routes). No hidden JSON endpoints. Frontend client: `window.CoreX.api`.
8. **Branches** — §4. **8a: exactly ONE `main` and ONE `Staging`** — no local branch named `main`/`Staging`/`staging` or any case variant, anywhere; confirm `git rev-parse --abbrev-ref HEAD` before merging into either, and compare `HEAD` to `origin/<branch>` if unsure (mismatch → STOP and reconcile). **8b: `/corex-qa1` is the live deploy target, never scratch** → skill `qa-deploy-and-deploy-dir`.
9. **Cross-pillar reactivity uses domain events** — read `.ai/specs/corex-domain-events-spec.md`; emit a named event, subscribe to existing ones; no ad-hoc observers/service calls between pillars.
10. **Universal Match-or-Create:** every property-data ingress (CMA, P24 alerts, PP feed, Chrome capture, manual, mandate signing, scraping, deeds lookups…) calls `App\Services\Prospecting\TrackedPropertyMatchOrCreateService::matchOrCreate()` before storing; every contribution appends to `source_chain`. Tiers: Agency Stock `properties` vs `tracked_properties`; `promoteToStock()` on mandate signing keeps the audit trail.
10a. **Every new setting reaches the Setup Wizard in the same prompt** → skill `setup-wizard-rule-10a`.
11. **Git sync at session boundaries** (§3).
12. **Demo is always a working copy** — a cycle touching DB/schema/seeders ends with demo migrated, seeded and matching local (`migrate:fresh --database=demo --force && demo:seed` must work).
12a. **Test bootstrap uses the schema snapshot** (`database/schema/mysql-schema.sql`) — regenerate ONLY via `scripts/schema-dump.sh` → skill `schema-dump`.
13. **NEVER run the full suite or any broad suite without Johan's explicit go.** Default = the single most relevant test file. A test command >60 s during active work is a violation — kill it, target one file. ALL runs via `scripts/lane-test.sh <files>`, never `artisan test`/`phpunit` directly → skill `worktree-and-lane-test`. Infer failures from the diff; don't run the suite to find out.
14. **Multi-tenancy is structural:** every tenant table has `agency_id`; every tenant model uses `BelongsToAgency` (global `AgencyScope`); no ad-hoc `where('agency_id')` in controllers; never `withoutGlobalScope(AgencyScope::class)` in request code (spec `multi-tenancy.md`). Never assume an agency/branch context exists — STANDARDS Rule 17. *(Was "#7" in the old `.ai/CLAUDE.md`; renumbered because root #7 is the API rule.)*

## 8. Skills — load when the trigger matches (descriptions load automatically; this is the routing table)
`worktree-and-lane-test` (making a worktree, running tests) · `landing-checks` (before any push/land; what "verified" means per change type) · `qa-deploy-and-deploy-dir` (touching /corex-qa1, landing on QA1, QA1 data) · `render-gate` (any Blade/Alpine change) · `schema-dump` (any new migration) · `test-email-rules` (anything that sends mail) · `promotion-runbook` (QA1→Staging→live) · `setup-wizard-rule-10a` (any new setting) · `sidebar-feature-toggle` (any sidebar item) · `blade-ui-rules` (new/changed UI) · `esign-and-documents` (signing pipeline, document fidelity, importer) · `portal-sync-refresh-cost` (Property24 sync) · `verification-practice` (proving a fix; View-As vs Switch User).

## 9. Build and fix, in brief
**New:** spec exists → find existing code that does the same (INVESTIGATE → COPY → ADAPT; never from scratch when a pattern exists) → one concern per prompt → build to BUILD_STANDARD (not the happy path) → `landing-checks` → report. **Bug:** exact error + URL → stack trace to file:line → `.ai/DIAG_CHECKLIST_UI.md` if a page shows 0/blank → fix the root cause → fix every sibling of the same class (BUILD_STANDARD §6) → test. A bug report means the code is wrong — never blame caching or the user.
**Done-close:** update `.ai/CHAT_STARTER.md` current-state (keep < 40 KB). If closing needs a deferred limitation or "good enough" compromise → STOP and escalate with a proper-fix proposal; don't ship it documented.
**`scripts/dev-check.ps1` is PowerShell; this box has no `pwsh` and it has never run here — never cite it as a gate on this box.**
**Subagent file-write rule:** when a prompt names a report file, write it with file tools to that path and verify it exists before returning; a chat-only reply is not acceptable. When delegating an audit, include that sentence verbatim.

## 10. Reference
- **Stack:** Laravel 12 (PHP 8.2 staging/QA, 8.3 live) · Blade · Alpine.js · Vite · MySQL · layout `corex-app.blade.php` + `corex-sidebar.blade.php`. Repo `johan7610/hfc-dash`. No jQuery, no inline styles (Tailwind), no DomPDF (Puppeteer web documents).
- **South Africa:** regulator **PPRA** (never EAAB); Property Practitioners Act 22 of 2019, FICA, POPIA, CPA; currency `R 1,250,000`; VAT 15%; commission typically 5–7.5% + VAT; mandates Sole/Open/Dual; FFC per agent.
- **`.ai/` map:** `STANDARDS.md` (UX/architecture laws, design checklist) · `BUILD_STANDARD.md` (Robustness Charter, Definition of Done) · `SYSTEM.md` · `CODEBASE_MAP.md` · `ROADMAP.md` · `CHAT_STARTER.md` (current state) · `specs/` (one per module; big ones are folders) · `runbooks/` `deploy/` (dated procedures) · `archive/` (history; read only on request).

> We do complicated so the user can do simple. Over-engineer for correctness. Later doesn't exist.
