## 1. Identity & Stack

**Who:** Johan Reichel (CEO, HFC). Andre Roets (full-stack dev). Two-person team building CoreX OS — real estate operating system. HFC (Home Finders Coastal) is the first live agency. Long-term vision: SA's leading real estate OS, replacing PropCon / Sage / Property24 layers across the industry.

**Stack:** Laravel 12 / Blade / Alpine.js. MySQL prod (`hfc_corex`, `hfc_staging`). SQLite local. Vite. Ubuntu on Hetzner `91.99.130.85`. Code at `/corex` (live) and `/corex-staging`. Python AI service `/opt/hf-ai/app.py` port 3100 (not in git).

**Domain:** `corex.hfcoastal.co.za` live. Staging on port 8084.

**Branches:** `main` (live), `Staging`, `HFC2402` (Johan dev), `andre` (Andre dev), feature branches off Staging. Discipline: HFC2402 + andre → Staging → staging server → main → live. Hotfix exception: direct to main, then back-sync to Staging.

**VS Code Claude** is the coding agent. Johan pastes prompts, it executes. Specs in `.ai/specs/`, read at start of every prompt.

## 1.5 Operating Principle (read this first, every chat)

CoreX is the best real estate operating system that will ever exist. This is the decision filter for every prompt, every architectural choice, every "should we ship this now" call.

- Best-in-class or rebuild — if competitors do something better, we build ours better
- No shortcuts ever — later does not exist; build it right the first time
- Integration is the moat — every feature seamlessly connects to every other
- Built for agents, not screens — we absorb complexity so they don't have to
- AI enhances, never replaces human judgement
- Constraint is fuel — build best with what we have, never use lack of resources as an excuse

Decision test before any commit: **does this make CoreX the best, or does it make CoreX merely working?** If the answer is "working", redesign.

"Good enough for now" is never the answer. "We'll fix it later" is never the answer.

See CLAUDE.md "CoreX Operating Principle" section for the full version.

## 2. Working Rules

- **Investigate before fixing.** Every issue: (1) audit, (2) Johan approves fix approach, (3) only then fix prompt. Never guess root causes.
- **One concern per prompt.** Two only if tightly related and same file.
- **Surgical changes.** No refactoring beyond scope.
- **Every prompt opens:** read CLAUDE.md, .ai/STANDARDS.md, relevant spec, and this file.
- **Every prompt closes:** php -l, php artisan view:clear, scripts/dev-check.ps1 if structural change, Tinker verification, **update this CHAT_STARTER.md**.
- **No hard deletes.** SoftDeletes only.
- **PPRA not EAAB. POPIA consent rules apply.**
- **Johan is not a programmer.** Ask business questions only (what should the user see / what should happen). Never ask him to make a technical or architectural choice — make it yourself and state the business consequence in one sentence. Report in plain language, no code or file paths unless asked. See CLAUDE.md operating rule 8.
- **Every new sidebar item ships with a Settings → Features on/off toggle (Andre + Johan, 2026-10-07).** Same commit: a row in `config/corex-features.php` (default ON, `route_names`, `view_dirs`), the link wrapped in `@feature('<key>')`, links to it from other screens wrapped too, a group whose children are all off hides itself. `FeatureNavGuardCoverageTest` fails otherwise; only core pillar pages and System-Owner-only links are exempt (`SidebarFeatureCoverage`). Full rule: STANDARDS.md "Every Sidebar Item Ships With A Features On/Off Toggle"; spec `corex-feature-registry.md` §8.2.
- **Disk hygiene for lanes (13 Oct 2026, after `/` hit 92%).** (1) Any server-side Chromium render goes through `scripts/html-to-pdf.mjs` (per-run browser profile removed on every exit path, no `--single-process`; a render must leave `/tmp` and `/var/crash` unchanged - `PdfRenderLeaksNothingTest`). Do not launch Puppeteer/Chromium any other way without the same cleanup. (2) Before `git worktree remove`, run `scripts/lane-test.sh --drop-schema` from the worktree (drops its ~115 MB test schema); orphans are also swept automatically (`scripts/lane-test-sweep.sh`: at most daily from `lane-test.sh`, weekly Sunday 04:10 cron; `--dry-run` shows what it would drop). The sweep finds worktrees by scanning for `artisan` files, never by `git worktree list` (that hides other repos' worktrees), and skips any schema touched in the last 2 h. (3) Box backstops (`/etc/cron.d/corex-housekeeping`, `/mnt/HC_Volume_103099143/bin/`): daily 03:50 removal of `/tmp/puppeteer_dev_chrome_profile-*` and `/tmp/corex-pdf-profile-*` older than 1 h (unless in use) and `/var/crash` files older than 2 days; disk alarm now broadcasts "DISK CRITICAL" at `/` 80% and the data volume 85% (once per 30 min per mount). Test schemas live on the data volume, NOT on `/`; `/` is a separate 38 GB disk.
- **Bug report = code is wrong.** Never blame caching or user error.

<!-- ============================================================ -->
<!-- DYNAMIC SECTION — VS Code updates this                       -->
<!-- ============================================================ -->

