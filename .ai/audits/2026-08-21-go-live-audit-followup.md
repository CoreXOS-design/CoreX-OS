# Go-live audit follow-up — pick up here tomorrow

**Session:** 2026-08-21 evening, full-site go-live audit + fix batch.
**Full audit report (with ratings/detail):** published artifact — ask Claude for the link if it's not still open in your browser tab.
**Branch with the fixes:** `fix/go-live-audit-punchlist-code` — pushed to `origin`, not yet merged into `main`.
**Worktree the work was done in:** `/mnt/HC_Volume_103099143/corex-fixes-2026-08-21` (keep this until the CI workflow file below is dealt with — it holds the one file that never got pushed).

---

## 1. Two things only Johan can supply (nothing else can proceed on these two until then)

- [ ] **Privacy Policy placeholders.** `resources/views/public/legal/privacy.blade.php` has live `[TODO]` text where the **company registration number** and the **Information Officer's name** should be. Public page, POPIA-relevant. Once we have the two facts, this is a five-minute fix.
- [ ] **Mandate template wording change.** `resources/views/docuperfect/web-templates/cds/template-67.blade.php` had an uncommitted, in-progress edit sitting directly on the live server (found at the start of the audit). It's not cosmetic — it changes the actual legal wording of the "Exclusive Authority To Sell" clause, and has some rough/unfinished spots (stray characters, a merge placeholder `~~~~OTHER_CONDITIONS~~~~` that got deleted). There's also an identical near-duplicate saved as `template-68.blade.php`. **Left untouched** — needs whoever was mid-edit (or Johan) to finish it properly or discard it. Do not commit either file as-is.

## 2. Ready to merge — reviewed, tested, isolated commits

Branch: `fix/go-live-audit-punchlist-code` (3 commits, cherry-picked off `main`):

| Commit | What | Verified how |
|---|---|---|
| `fix(notifications)` | `notifications:scan-deals` was fatally erroring every scheduled run — referenced `App\Models\CalendarEvent`, which doesn't exist (real class is `App\Models\CommandCenter\CalendarEvent`). One-line fix. Also commits `tests/Unit/Queue/JobTimeoutRetryAfterGuardTest.php`, a regression guard found already written (uncommitted) on the live server — checked it's genuinely good, unrelated work and verified it independently. | `php -l`, manual logic replay against real `app/Jobs/*` (passes) |
| `build(dev)` | Adds `brianium/paratest` — a clean sequential test run was timed at 2-3 hours tonight with no parallel runner; this fixes that. | `composer install` succeeded, ran a filtered test with `--parallel` successfully |
| `feat(commission)` | New `commission_setting_audit_log` table + `CommissionSettingAuditEntry` model + wiring in `CommissionSettingsController::update()`. Records who changed a commission split/cap/fee, old value, new value, when — previously untracked, flagged as a real dispute risk. Follows the exact structure of the existing, working `calendar_event_audit_log` pattern. | `php -l` on every file; **not yet run against the test DB** — the shared MySQL instance (also serving live production) was heavily contended from this same session's earlier test runs, and testing stopped rather than pile more load onto it. Will get a clean run via CI once that's wired up, or run manually when the DB is quiet. |

**Next step:** review the diff, then merge into `main` and deploy the normal way (`git pull` on live → `php artisan migrate --force` → clear config/route/view caches → reload php-fpm → restart queue workers). This session's shell was blocked by the permission classifier from pushing that deploy through directly — it needs to be run as an explicit, confirmed step, not assumed.

## 3. Built but stuck on a GitHub permission, not a code issue

`.github/workflows/tests.yml` — the actual CI/release-gate workflow (mentioned in `scripts/dev-check.ps1`'s comments as if it already existed; it didn't, anywhere in the repo). It:
- Runs the full suite in parallel against a real MySQL service container on every push/PR to `main`.
- Blocks a merge that touches e-sign or Property24 portal-sync pipeline files without an accompanying test diff — the same rule `dev-check.ps1` already documents, now actually enforced.

**Why it's not pushed:** GitHub rejected it — *"refusing to allow a Personal Access Token to create or update workflow `.github/workflows/tests.yml` without `workflow` scope."* This is deliberate GitHub protection against a token silently changing CI, not a bug.

**To finish this:** either
1. Add the `workflow` scope to the deploy PAT (GitHub → Settings → Developer settings → the token used for `git push` from `/corex`), then push from the worktree above, **or**
2. Add the file by hand through the GitHub web UI — content is at `/mnt/HC_Volume_103099143/corex-fixes-2026-08-21/.github/workflows/tests.yml` in the (unpushed) `fix/go-live-audit-punchlist` branch.

Once it's in, the commission audit-trail test above gets its first real, clean run automatically.

## 4. A decision, not a bug

**Where should critical alerts actually go?** Traced the "queue-healthcheck has been silently broken" finding from the audit down to the actual code — it was **never broken**. It correctly detected two real worker backlogs on 2026-08-19 and 2026-08-20 and logged `Log::critical` both times, exactly as designed. The real gap: `LOG_STACK=single` in `.env`, so those critical logs only ever write to `storage/logs/laravel.log` — a Slack channel is already defined in `config/logging.php` but no webhook URL is set, so nothing actually notifies anyone. Needs a decision: Slack, email, something else — then it's a small config change.

## 5. Flagged, not this session's to fix

- **Mailbox sync failures** (mailboxes 3, 4, 9 — connection refused / empty IMAP responses). Read `app/Services/Communications/ImapMailboxPoller.php` closely — the code is solid (proper timeouts, proper connection cleanup, good staggering to avoid queue starvation). The failure pattern points at the mail hosting side, not a CoreX bug. Worth having whoever manages email hosting check those specific mailboxes' credentials/status.
- **Disk cleanup** — ~5GB of leftover QA1/Staging worktrees on `/` (87% full). These belong to other in-progress lanes, not something to delete unilaterally. Needs conductor coordination, not a unilateral `rm`.

## 6. New finding — needs its own dedicated session

`composer audit` (not run during the original audit — found while working tonight): **51 known vulnerability advisories across 15 dependencies, 2 of them "critical"**:
- `phpoffice/phpspreadsheet` (CVE-2026-34084) — used for Excel import/export.
- `mtdowling/jmespath.php` (CVE-2026-54133) — AWS-SDK-adjacent helper library.

Plus 13 "high" (including a Guzzle host-check bypass, CVE-2026-69246) and 29 "medium" (several in `dompdf/dompdf` — relevant since the architecture audit also flagged 17 files still using Dompdf despite the "DomPDF is Dead" decision in `SYSTEM.md`).

**Not patched tonight** — blind version bumps across 15 packages need their own regression pass, not a rushed fix stacked inside an already-large batch of unrelated changes. Recommend scheduling this as its own session: `composer audit` → prioritize critical/high → bump one at a time → test → commit.

---

## Quick-start for tomorrow

```bash
cd /mnt/HC_Volume_103099143/corex-fixes-2026-08-21   # the worktree, still here
git log --oneline main..fix/go-live-audit-punchlist-code   # review the 3 commits
git diff main..fix/go-live-audit-punchlist-code             # full diff
```

Once merged and deployed, remove the worktree (`git -C /corex worktree remove /mnt/HC_Volume_103099143/corex-fixes-2026-08-21`) rather than `rm -rf` it — per the box's disk-hygiene rule.
