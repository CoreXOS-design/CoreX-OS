# CoreX — Multi-Worktree Git Rules

> **Read before your first git command in any session.** Short on purpose.
> Every rule here is scar tissue: each one cost someone real time, and every one
> of them **failed silently** — the command reported success and did nothing, or
> did the wrong thing.
> Last updated: 2026-07-11 (m6, e-sign crew).

---

## Why this file exists

CoreX runs **many worktrees over one repository** — `corex-dev`, `corex-dev-2`,
`corex-dev-3`, `corex-dev-5`, `corex-dev-6`, plus the deployed checkouts
(`/corex`, `/corex-staging`, `/corex-qa1`, `/corex-qa2`).

That means: **a branch can only be checked out in ONE worktree at a time**, and
several git commands behave differently — and worse, *quietly* — under that
constraint. The failures do not look like failures. They look like success.

**The meta-rule: after any git operation that matters, VERIFY THE REMOTE, not the
exit code.** `git push` exiting 0 does not mean your commit is on the remote.

---

## Rule 1 — `main` is checked out in someone else's worktree. Push with `HEAD:main`.

`git checkout main` in your worktree **fails** if another worktree holds it
(`fatal: 'main' is already checked out at ...`). If you were detached and didn't
notice, you are now committing on a **detached HEAD**.

Then `git push origin main` pushes your **stale local `main` ref** — not your
work — and cheerfully reports:

```
Everything up-to-date
```

Your commit is nowhere. This cost a full round trip on 2026-07-11.

**Do this:**

```bash
git push origin HEAD:main                       # push what you actually built
git fetch origin -q
git cat-file -e origin/main:path/to/your.file   # VERIFY it landed
```

Find who holds a branch: `git worktree list`.

---

## Rule 2 — never `git checkout <ref> -- <path>` over your own uncommitted work.

`git checkout <commit> -- .ai/` **overwrites the working tree from that commit,
with no warning and no stash**. If you had uncommitted edits under that path, they
are gone. There is no "are you sure".

This wiped three finished spec documents on 2026-07-11. They were only recoverable
because the content still existed in a scratch file.

**Do this instead:** commit or stash first, and only ever path-checkout onto a
clean tree. If you just want to *see* the old version:

```bash
git show <commit>:path/to/file          # read it, don't restore it
```

---

## Rule 3 — untracked files in a shared worktree are one branch-switch from gone.

Untracked files survive a branch switch — until someone runs `git clean`, or the
worktree is re-pointed, or the lane is recycled.

Two finished specs (`esign-ceremony-v3.md`, `esign-field-intelligence.md`) were
authored untracked, never committed, and **vanished from disk entirely** when their
worktree moved to another branch. They were reconstructed from the session
transcript — which worked, but only by luck.

**Do this:** if a deliverable took more than ten minutes to produce, **commit it**.
Docs go to `main` (docs-to-main is authorised as a class); code goes on its branch.
"I'll commit it at the end" is how work dies.

> Recovery, if it happens anyway: the session transcript at
> `/root/.claude/projects/<worktree-slug>/<session-id>.jsonl` records every Write
> and Edit tool call. Replaying the original `Write` plus each `Edit`
> (`old_string` → `new_string`, in order) reconstructs the file exactly.

---

## Rule 4 — `cd` into another worktree, or use `git -C`. Never assume.

`git` acts on the worktree of your **current directory**. A `cd` that silently
failed, or a tool that reset your cwd between calls, means your command ran against
the **wrong lane** — often another agent's live checkout.

**Do this:** be explicit, always.

```bash
git -C /mnt/HC_Volume_103099143/corex-dev-6 status
```

And **never touch another lane's checkout.** If you need work another agent has,
wait for their push and reconcile from the remote. Editing, stashing, or checking
out inside a pane that isn't yours will corrupt their session.

---

## Rule 5 — a fresh worktree cannot run anything until you set it up.

New lanes ship with **no `vendor/`, no `.env`, and no `storage/framework`
directories** (all gitignored). `php artisan` does not merely misbehave — it fails
to boot (`Please provide a valid cache path.`), and every test errors before the
first assertion.

**Do this, once, in a new worktree:**

```bash
composer install --no-interaction --prefer-dist
cp ../corex-dev-3/.env .env                        # a lane that works
mkdir -p storage/framework/{views,cache/data,sessions,testing} \
         storage/logs storage/app/public bootstrap/cache
```

Then give the lane **its own databases** — concurrent test runs across lanes will
otherwise drop each other's tables mid-run:

```bash
# .env
DB_DATABASE=corex_dev6
TEST_DB_DATABASE=hfc_dash_test_6      # hfc_dash_test_<N>, N = your lane
```

```sql
CREATE DATABASE corex_dev6;  CREATE DATABASE hfc_dash_test_6;
GRANT ALL PRIVILEGES ON corex_dev6.*     TO 'corexdev'@'localhost';
GRANT ALL PRIVILEGES ON hfc_dash_test_6.* TO 'corexdev'@'localhost';
FLUSH PRIVILEGES;
```

The test harness enforces this — `tests/TestCase.php` **refuses to run** unless
`DB_DATABASE` matches `hfc_dash_test(_N)?`, so a misrouted connection cannot drop a
real schema. Good. Don't fight it; configure the lane.

**Expect the first test in a run to take ~3 minutes** (schema-snapshot bootstrap).
Every subsequent test in the file takes ~0.2s. That is not a hang. Do not kill it.

---

## Rule 6 — commands that write to BOTH the DB and a tracked file need a plan.

`php artisan docuperfect:normalize-templates` (the `data-role-block` backfill) is
the canonical example. It writes:

1. `editor_state.tagged_html` in the database — **per-environment; does NOT travel
   on a `git pull`**, and
2. **the blade view file on disk — which IS tracked in git.**

Run it on a server and you have mutated tracked files outside version control; the
next `git pull` conflicts or clobbers them. Run it only locally and the DB half
never reaches the other environments.

**Do this:** run it **locally**, **commit the rewritten files**, deploy them by
`git pull`, and **re-run the command on each environment** for the database half.
Same class as AT-162 (seeders don't run on a `git pull` deploy — reference data
must be carried by a migration backfill or registered in
`deploy:sync-reference-data`).

---

## The one-line version

**Verify the remote, never the exit code. Commit anything you'd hate to lose.
Stay in your own lane.**
