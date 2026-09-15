# Commission internal-pool share-percent defect — QA1 → Staging promotion runbook

> Written 2026-09-10. **NOT EXECUTED.** Johan's fix is approved and applied on QA1 only.
> Waiting on his explicit word to promote to Staging. cc1 is the landing hand — this
> runbook is what cc1 runs, prepared by cc6. Re-run the pre-flight checks at execution
> time; QA1 and Staging both move daily.

---

## 0. What this is, and — more importantly — what it is NOT

This is a **single-commit cherry-pick**, not a branch promotion. QA1 is **456 commits**
ahead of Staging (`git rev-list --count origin/Staging..origin/QA1`). None of that other
work — rental applications, multi-property (AT-398), the wishlist drawer, the markings
rebuild, cc5's review-screen fixes, the e-sign worktrees — is approved for Staging and
**none of it should move**. The only thing that goes to Staging is:

```
acaf76b89  fix(commission): our_share_percent no longer applied to a non-external side
```

One commit. 7 files: `app/Services/Finance/CommissionPoolCalculator.php` (new),
`app/Models/Deal.php`, `app/Models/DealV2/DealV2.php`, `app/Services/Finance/CommissionCalculator.php`,
`app/Services/DealMoneyLineRebuilder.php`, `app/Console/Commands/CorrectCommissionInternalPoolShareDefect.php` (new),
`tests/Feature/Commission/CommissionInternalPoolShareDefectTest.php` (new).

**Verified separable, not assumed.** Checked which QA1-only commits (since it diverged
from Staging) touch any of these 7 files:

```
git log --oneline $(git merge-base origin/QA1 origin/Staging)..origin/QA1 -- <the 7 files>
```

Two hits: `acaf76b89` (this fix) and `18115ffa0` (AT-398 multi-property), which touches
`app/Models/Deal.php` only — 87 lines, a different method entirely (adds multi-property
plumbing; my fix is `calculateInternalPool()`/`commissionExVat()` etc.). No overlap.

**Already rehearsed — content proven, not just exit code.** Cherry-picked `acaf76b89`
into a scratch worktree off `origin/Staging` (HEAD `d5af0e292` at time of writing). Result:
`Auto-merging app/Models/Deal.php`, exit 0, 7 files changed — matches exactly. Then, per
the lesson from earlier today (a clean exit code proved nothing), verified **content**:
sha256 of all 6 non-`Deal.php` files identical to QA1's versions; `Deal.php`'s commission
methods (`commissionExVat`/`calculateInternalPool`/`listingPool`/`sellingPool`/`totalOurCommission`)
diffed byte-for-byte identical to QA1's copy despite the auto-merge. `php -l` clean on all
7 in the worktree. Worktree removed after (disk hygiene, no data changes were possible —
this was a git-only rehearsal, no app bootstrap, no DB).

---

## 1. Pre-flight (re-run at execution time — refs move)

```bash
git -C /corex-staging fetch --all --prune
git -C /corex-staging rev-parse HEAD
git -C /corex-staging rev-parse origin/Staging
# MUST match. If not: STOP, reconcile per non-negotiable #8a before touching anything.

git -C /corex-qa1 log --oneline -1 acaf76b89   # confirm the commit still exists as expected
```

No migrations in this change — no schema-dump currency check needed, no env-parity
(PHP extension) concern. This is pure application code plus one new Artisan command.

---

## 2. Checkpoint (before the cherry-pick touches Staging)

```bash
cd /corex-staging
git tag pre-commission-fix-2026-09-10 $(git rev-parse HEAD)
git push origin pre-commission-fix-2026-09-10
```

This tag is the rollback anchor and the baseline for the content-density sweep below.

---

## 3. Land it

```bash
cd /corex-staging
git checkout Staging
git pull --ff-only origin Staging
git cherry-pick acaf76b89
```

If this does **not** apply cleanly (conflict markers in `Deal.php`), STOP — do not
hand-resolve blind. The rehearsal above found a clean auto-merge against `d5af0e292`;
a conflict now means Staging moved in a way that changes this. Re-diagnose before
proceeding.

---

## 4. Content-density sweep — prove the content, not the exit code

This is the step that would have caught the earlier cherry-pick problem. Compare every
landed file against QA1's tested copy — not "did `cherry-pick` exit 0."

```bash
QA1=/corex-qa1
ST=/corex-staging

for f in app/Services/Finance/CommissionPoolCalculator.php \
         app/Models/DealV2/DealV2.php \
         app/Services/Finance/CommissionCalculator.php \
         app/Services/DealMoneyLineRebuilder.php \
         app/Console/Commands/CorrectCommissionInternalPoolShareDefect.php \
         tests/Feature/Commission/CommissionInternalPoolShareDefectTest.php; do
  a=$(sha256sum "$ST/$f" | cut -d' ' -f1)
  b=$(sha256sum "$QA1/$f" | cut -d' ' -f1)
  [ "$a" = "$b" ] && echo "OK   $f" || echo "DIFF $f — STOP, investigate before proceeding"
done

# Deal.php auto-merged with AT-398 on QA1 — compare just the commission methods, not the whole file:
diff <(sed -n '/public function commissionExVat/,/public function totalOurCommission/p' "$ST/app/Models/Deal.php") \
     <(sed -n '/public function commissionExVat/,/public function totalOurCommission/p' "$QA1/app/Models/Deal.php") \
  && echo "OK   Deal.php commission methods identical" || echo "DIFF — STOP, investigate"
```

Every line must say `OK`. Any `DIFF` — stop, do not push, report back.

---

## 5. Verify on Staging itself

```bash
cd /corex-staging
for f in app/Services/Finance/CommissionPoolCalculator.php app/Models/Deal.php \
         app/Models/DealV2/DealV2.php app/Services/Finance/CommissionCalculator.php \
         app/Services/DealMoneyLineRebuilder.php app/Console/Commands/CorrectCommissionInternalPoolShareDefect.php; do
  php -l "$f"
done

php artisan test tests/Feature/Commission/CommissionInternalPoolShareDefectTest.php
# Expect: 8 passed, 23 assertions. This is the SAME suite that was proven to fail on
# pre-fix code and pass on the fix, now running against Staging's own dependencies —
# not a rehearsal, not QA1's database, Staging's own.

php artisan view:clear && php artisan route:clear && php artisan cache:clear
```

---

## 6. Push

```bash
git push origin Staging
git rev-parse HEAD
git rev-parse origin/Staging
# MUST match — confirms the push landed and nothing raced it.
```

**Deploy note:** Staging is a git checkout that deploys via `scripts/deploy.sh staging`
per the standard flow (§ "Demo is always a working copy" / CLAUDE.md non-negotiable #12
does not apply here — no schema/seeder/DB change in this fix). Reload php-fpm for the
Staging pool after `git pull` per the normal Staging deploy step; no migration, no
`deploy:sync-reference-data`, no queue restart strictly required, but doing the normal
cache-clear + fpm-reload deploy sequence is still correct hygiene.

---

## 7. The data question — separate from the code question

Deal 1818 (id 169) is **already corrected on QA1** (`--apply` already run there,
verified). Landing the CODE above does **not** touch Staging's data — Staging's deal
1818/169 (same record, same broken value, confirmed via a read-only dry run today) stays
wrong until the historical-correction command is **also run with `--apply` on Staging**.
That is a **separate decision** from "promote the code" — Johan may want the code live on
Staging first, watch it, then approve the data correction separately, exactly as he split
those two approvals on QA1. Do not assume `--apply` on Staging is bundled into this
promotion unless he says so explicitly.

If/when approved, the Staging apply is identical to the QA1 procedure already proven:
`php artisan deals:correct-share-percent-defect` (dry run first, always) then `--apply`,
then the same verification battery (before/after query, audit trail query, cache-row
query respecting soft-deletes, idempotency re-run). The QA1 dry-run/apply session is the
template — same commands, same checks, different database.

---

## 8. Rollback

**Code:** `git revert acaf76b89` on Staging (not `reset --hard` — Staging is shared and
other work may land after this). This commit is purely additive/isolated (one new
service class, one new command, one new test file, and method-body-only edits inside
`calculateInternalPool()`/`companyIncomeExVatBreakdown()`/`computeDealPools()`/
`rebuildSingleDeal()`) — a revert cleanly undoes it without touching anything unrelated
landed on top. Then `view:clear`/`route:clear`/`cache:clear` + fpm reload.

**Data (only relevant if §7's `--apply` was also run on Staging):** the correction's own
audit trail (`DealLog`/`DealActivityLog`) has the exact before-value for every field it
touched. Reversing means writing that stored `from_value` back — do this deliberately,
by hand, per deal, referencing the log row; do not script a blind reversal. On QA1 today
that is one field on one deal (`selling_our_share_percent`: 100 → back to 50) — trivial
in scope, but still a deliberate, logged action, not an automated undo.

**Cheapest mitigation if something looks wrong post-promotion:** nothing to toggle off —
this fix has no feature flag (it's a bug fix, not a feature). The rollback IS the revert.

---

## 9. Who does what

cc6 (this document's author) prepared and rehearsed everything above. **cc1 is the
landing hand** — cc1 runs §§1–6 on Johan's word. cc6 does not push to Staging directly.
Coordinate through the conductor, not pane-to-pane.
