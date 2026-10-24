---
name: landing-checks
description: What a lane must verify before pushing or reporting a change ready to land, the READY-TO-LAND report format, and who is allowed to push where. Use when finishing a task, before any git push, or when writing the final report.
---
# Landing checks

## A lane's own pre-push verification (Johan's standing policy)
Exactly: `php -l` on every changed PHP file → `php artisan view:clear` (also `route:clear`, `cache:clear`) → the relevant EXISTING single test file via `lane-test.sh` (skill `worktree-and-lane-test`) → Tinker/equivalent functional check (route resolves, view renders, model instantiates, data persists, POST accepts) → push. **Writing a new PHPUnit feature test is normal and encouraged; building browser-verification scaffolding is not:** no `php artisan serve` on a throwaway DB, no minted-session-cookie chains, no ad hoc Puppeteer/Chromium screenshot scripts, no `corex:sync-permissions --seed-defaults` on a scratch DB. Johan verifies visible changes himself in a real browser on the deployed site; a local render is a different environment and proves nothing to him.
**One carve-out (render-gate skill):** if the change touches an Alpine attribute (`x-*`, `:*`, `@*`), the lane itself MUST run `verify-alpine-render.mjs` on a really fetched page before pushing.
The full render gate / `rental-smoke.mjs` / `rental-click-through.mjs` run against the DEPLOYED site after landing (cc1 or conductor), not by the lane pre-push.

## Pipeline gates (by change type — the dev-check.ps1 gate cannot run here, the RULE stands)
- Touching the e-sign signing pipeline files → must come with a test diff under `tests/Feature/Docuperfect/SigningView/` (list in skill `esign-and-documents`).
- Touching Property24/portal sync files → must come with a test diff under `tests/Feature/Syndication/` (skill `portal-sync-refresh-cost`).
- New/changed migration → snapshot regeneration (skill `schema-dump`); new setting → Setup Wizard (skill `setup-wizard-rule-10a`); new sidebar item → toggle (skill `sidebar-feature-toggle`); new Blade UI → skill `blade-ui-rules`; anything that mails → skill `test-email-rules`.

## Who pushes where
- Lanes push their OWN branch only. **Only cc1 writes `origin/QA1`** — not a migration file, not a one-line doc fix; if cc1 needs something, push to your branch and tell cc1 the branch + SHA. (A 7 Oct memory note says a lane may `git push origin HEAD:QA1` when its brief pre-authorises it — flagged for Johan; follow this rule unless the brief explicitly orders a QA1 landing.)
- Lane branch names begin with the lane; never rename a branch mid-build.
- **QA freeze:** a freeze holds *behaviour*, not documentation — docs/standards/audit/spec notes may land; code, views, migrations, config and anything altering behaviour park on their own branch until the freeze is lifted.
- A finished, tested change lands on QA1 as soon as it's ready — the browser walk gate belongs at Staging, not QA1 (what still legitimately blocks a QA1 landing: an unread migration, a real cross-lane conflict, red tests, anything touching a live/shared system).
- Never `git commit/cherry-pick/merge/rebase/checkout` inside `/corex`; live changes only via `git -C /corex merge --ff-only origin/main` (not a fast-forward ⇒ STOP, report). Tag before any deploy. Anything on live must be pushed to origin (an unpushed commit on live is erased by the next deploy).

## READY-TO-LAND report (plain language to Johan; technical detail to the conductor)
1. What changed (files + why) and confirmation nothing outside the task was touched. 2. How proven — name the INPUT PATHS (all-fields, each-optional-omitted, lazy shortcut, malformed, deleted-related, create→soft-delete→recreate where UNIQUE+SoftDeletes). 3. **Spec-conformance line:** spec §§ implemented + declared deviations, or "no governing spec". 4. Anything found outside scope (file:line + root cause) — reported, not fixed. 5. Honest status: failing checks and skipped steps stated plainly, never hedged. 6. Spec updated in the same commit; `.ai/CHAT_STARTER.md` current-state updated. 7. If closing needs a "good enough" compromise — stop and escalate with a proper-fix proposal.
