# AT-267 Assistants — promotion runbook (QA2 → Staging → main/live)

> Written 2026-07-26 after the post-ship audit + remediation. **NOT EXECUTED** — Johan's hold:
> "do not commit to staging yet". This is the sequence, computed from the actual refs, not a
> template. Re-compute the deltas at execution time; they move.

---

## 0. STOP — read this before planning the window

**You are not shipping "the assistant fixes". You are shipping the Assistants feature itself,
plus 83 other commits.**

| Fact | Value | How to re-check |
|---|---|---|
| `origin/Staging..QA2` | **84 commits** | `git rev-list --count origin/Staging..QA2` |
| `origin/main..QA2` | **94 commits** | `git rev-list --count origin/main..QA2` |
| New migrations in that delta | **23** | `git diff --name-only --diff-filter=A origin/Staging QA2 -- database/migrations/` |
| AT-267 commits already on Staging | **none** | `git log --oneline origin/Staging --grep="AT-267"` |

The whole Assistants feature — schema, resolver, middleware, UI — has never left QA2. Neither has
the agency feature registry, the branch_id backfill series, or the P24 completeness work. If the
intent was a small assistant-only patch, that is a **cherry-pick job, not a branch promotion**, and
it is a different (and more fiddly) runbook — the 23 migrations interlock.

### The 23 migrations, split by risk

**Structural (safe, reversible):** 11 assistant migrations (`2026_07_14_2000*`, `2026_07_19_00000[67]`,
`2026_07_21_000001`, `2026_07_22_1[234]0000`), `add_invited_at_to_users`, the two P24 completeness
migrations, `create_agency_features_table`, `add_agency_id_to_performance_settings`, and the three
`add_branch_id_to_*` migrations.

**⚠ DATA / BACKFILL — these do NOT cleanly reverse. A mysqldump is the only rollback:**
- `2026_07_14_200005_seed_assistant_role.php`
- `2026_07_18_000002_backfill_agency_features.php`
- `2026_07_18_000004_backfill_legacy_deal_branches.php`
- `2026_07_19_000004_backfill_branch_id_for_per_user_activity_models.php`
- `2026_07_19_000005_backfill_branch_id_for_commission_ledger.php`

`backfill_legacy_deal_branches` and `backfill_branch_id_for_commission_ledger` touch **deal and
commission rows**. Eyeball both `--pretend` output and a `SELECT COUNT(*) … WHERE branch_id IS NULL`
before and after.

---

## 1. The one piece of good news — it ships DARK

`agencies.assistants_enabled` **defaults to `false`**
(`2026_07_14_200004_add_assistants_settings_to_agencies_table.php:22`), and `User::activeAssistantAssignment()`
funnels every assistant code path through that switch. `assistants` is **not** in the feature
registry (`config/corex-features.php`) — the agency column is the only gate.

So: deploying does **not** turn Assistants on for anybody. No agency sees a change until someone
sets `assistants_enabled = 1` for them (Company Settings → Assistants, or the Setup Wizard step).
That makes the code deploy and the feature launch two separate, independently reversible events —
use that. Deploy on one day, enable one pilot agency on another.

---

## 2. Pre-flight (before any host is touched)

```bash
# a. The deltas, recomputed at execution time
git fetch --all --prune
git rev-list --count origin/Staging..QA2
git diff --name-only --diff-filter=A origin/Staging QA2 -- database/migrations/

# b. Schema snapshot currency (non-negotiable #12a).
#    The audit remediation added NO migrations, so the snapshot is as current as it was at
#    32e62630 (2026-07-22). Confirm nothing landed on QA2 since without a re-dump:
git log --oneline -1 -- database/schema/mysql-schema.sql
git log --oneline -1 -- database/migrations/
#    If a migration is NEWER than the snapshot: php artisan schema:dump && commit it.

# c. Env parity (AT-169) — live pool is php8.3, staging may not be.
#    Diff the module lists BEFORE promoting, not after a 500.
ssh <staging> 'php -m; php -v'
ssh <live>    'php8.3 -m; php8.3 -v'
```

**Decision needed from Johan before step 3 — the demo.** Non-negotiable #12 says the demo must end
every DB-touching cycle migrated and verified. But `demo1.corexos.co.za` (`/mnt/HC_Volume_103099143/corex-demo`,
db `nexus_os_demo`) **tracks `HFC2402`**, and this work is on **QA2**. Either HFC2402 takes the
merge too, or the demo is repointed, or the demo is explicitly skipped this cycle and that is
recorded. Pick one — do not leave it implied.

---

## 3. QA2 → Staging (git only, no host)

```bash
git checkout Staging
git pull --ff-only origin Staging
git merge --no-ff QA2 -m "merge(QA2): AT-267 Assistants + feature registry + branch_id series"
# Resolve conflicts HERE, on your clock — not at deploy time (non-negotiable #11).
git push origin Staging
```

---

## 4. Staging host deploy

```bash
# 0. SAFETY FIRST — tag + dump before anything
git -C /corex-staging tag staging-pre-at267 $(git -C /corex-staging rev-parse HEAD)
git -C /corex-staging push origin staging-pre-at267
mysqldump <staging-db> > /root/backups/staging-pre-at267-2026-07-26.sql

# 1. CODE
git -C /corex-staging fetch origin && git -C /corex-staging pull --ff-only

# 2. MIGRATE — pretend first, eyeball the 5 backfills
sudo -u www-data php /corex-staging/artisan migrate --pretend
sudo -u www-data php /corex-staging/artisan migrate --force

# 3. REFERENCE DATA — NOT optional. Seeders do not run on a git-pull deploy (AT-162).
sudo -u www-data php /corex-staging/artisan deploy:sync-reference-data
sudo -u www-data php /corex-staging/artisan corex:sync-permissions --merge-defaults

# 4. POPIA BACKFILL — one-shot, still outstanding from the 2026-07-21 audit
sudo -u www-data php /corex-staging/artisan corex:move-property-files-to-local --dry-run
sudo -u www-data php /corex-staging/artisan corex:move-property-files-to-local

# 5. CACHES + SERVE
sudo -u www-data php /corex-staging/artisan config:clear
sudo -u www-data php /corex-staging/artisan route:clear
sudo -u www-data php /corex-staging/artisan view:clear
systemctl reload php<VER>-fpm        # match the STAGING pool version, not live's
supervisorctl restart <staging workers>
sudo -u www-data php /corex-staging/artisan queue:restart
```

### Why step 3 is load-bearing here, specifically

`deploy:sync-reference-data` carries two rows this release cannot work without:

1. **`AssistantRoleSeeder`** — the zero-grant `assistant` role. `users.role` is `NOT NULL DEFAULT 'agent'`.
   On an environment without that row, creating an assistant saves them as a **full agent**. This is
   the single most dangerous omission in the whole deploy.
2. **`NotificationEventTypeSeeder`** — includes the new `assistant.acted_on_behalf` row (added by the
   audit remediation). Without it the "Notify me when my assistant changes something" toggle exists
   and silently never fires — the exact class of bug the audit was called in to fix.

`corex:sync-permissions --merge-defaults` is required because `config/corex-permissions.php` gained
**+48 lines** in this delta (the `assistants.*` keys and the admin-default-off sections). Skip it and
the matrix has nothing to grant.

`HfcConsentTemplatesSeeder` is a **one-shot and is NOT in sync-reference-data** — only run it
explicitly if this delta touches consent templates or targets a new agency.

---

## 5. Staging smoke — the assistant-specific list

Generic page-loads prove nothing here. Test the things the audit found broken:

1. **Create an assistant** (Company → Assistants → Add). Confirm the invite email sends and the
   user lands with `role='assistant'`, `is_admin=0`.
2. **Edit them** (the new F5 surface) — change the Title, confirm it persists.
3. As the assistant: **open a document the agent owns** → must NOT 403 (F2).
4. As the agent: switch **"can edit & delete my records" OFF**. As the assistant, try to edit a
   contact → 403 with the plain-English message; try to ADD one → still works (F1).
5. As the assistant, build an **e-sign document** and a **viewing pack**; log in as the AGENT and
   confirm both are visible on their book (F3).
6. Open **DR2 deal capture** — the assistant must not appear in the listing/selling agent pickers (F4).
7. As the assistant, open **My Portal → Compliance** — no FFC / PI / Tax rows, and the card is not
   permanently red (F9).
8. Confirm `assistants_enabled` is still **0** for every agency you did not deliberately enable.

---

## 6. Staging → main → live

Only after §5 passes and Johan gives the word. Follow the existing live runbook —
`.ai/deploy/2026-07-14-live-promotion-manifest.md` "Tonight's delta runbook" — which is current and
correct. The deltas that matter:

- **Live pool is php8.3**, not 8.2 — `systemctl reload php8.3-fpm`.
- Workers: `supervisorctl restart corex-worker-live: corex-worker-live-mail: corex-worker-live-matching:`
- Same §4 steps 3+4 apply on live (`sync-reference-data`, `sync-permissions`, the POPIA backfill).
- Tag `live-pre-at267` and mysqldump before touching anything.

---

## 7. Rollback

**Code:** `git -C <path> reset --hard <the pre tag>` + config/route/view clear + fpm reload + worker restart.

**Database: restore the mysqldump.** The five backfill migrations do not cleanly reverse — two of
them rewrite deal and commission branch attribution. Do not attempt `migrate:rollback` on live.

**Cheapest mitigation, and usually the right first move:** set `assistants_enabled = 0` on the
affected agency. That neutralises the entire Assistants feature without a code or schema change,
because every assistant path funnels through that switch. Reach for this before a rollback.

---

## 8. After the deploy — launching the feature

Deploy ≠ launch. To turn it on for a pilot agency:

1. Company Settings → Assistants → enable (or the Setup Wizard step).
2. Create one assistant for one willing agent.
3. Walk that agent through their control page — in particular the three behaviour toggles, which
   as of this release **actually do what they say** for the first time.
4. Leave it a week before widening.

---

## Still outstanding (not blocking this deploy)

`daily_activity_entries.on_behalf_of_user_id` — the last item of control-page spec Phase 6. It needs
its own migration + `schema:dump` + a demo/live migrate, so it belongs to a later cycle. Daily
activity already files under the correct agent; only the audit column naming which assistant keyed
each number is missing.
