# Dedicated test database per lane — investigation and design (2026-10-24)

Status: DESIGN ONLY. Nothing shared was changed. Probes ran on throwaway schemas `zz_probe_{a,b,c,f}` on the
tests-only MySQL (docker `corex-lanetest-mysql`), all dropped and verified gone (0 schemas, 0 data dirs left).
Probe method: one sample per number, idle box (load 1.5, container 2.56/3.0 GiB), root inside the container
(the `lanetest` user is granted `hfc_dash_test_%` only, so it cannot create `zz_probe_*`). I held the setup lock
for the ~14 min the probes ran; no lane was queued at the time. Raw script: scratchpad `probe.sh`.

## 0. The premise is half out of date — what is already true

`scripts/lane-test.sh` does NOT build a throwaway schema per run any more (changed 2026-10-05/06). It already keeps a
PERSISTENT schema per name, fingerprints it, wraps every test in a transaction, and a *warm* run never touches the
global lock. The pain Johan sees is not the warm path. It is three other things, all measured/verified below:

1. Every new worktree is a COLD schema (130 s load) because the schema name follows the worktree, not the lane.
2. Cold loads and `schema-dump.sh` queue on ONE global setup lock; a dump holds it 15–20 min.
3. Names are not stable or per-lane (see 1.3), so "which lane owns which database" cannot be answered today.

## 1. Current flow, exact lines

### 1.1 `scripts/lane-test.sh` (529 lines)
- L98 `MAX_SLOTS=2` concurrent runs. L100 setup lock `/tmp/corex-lane-test.lock` (fd 200). L103 per-schema lock prefix (fd 201). L102 slot locks (fd 202).
- L251–271 schema name: shell `TEST_DB_DATABASE` > worktree `.env` > derived `hfc_dash_test_<cksum of worktree path>` (L265). Whitelist `^hfc_dash_test(_[0-9]+)?$` (L104/268).
- L327–333 `compute_fingerprint`: sha256 of `mysql-schema.sql` + every migration file's content.
- L358–405 `ensure_schema`: missing -> create + load snapshot (L366) + `artisan migrate` (L368); `--fresh` -> drop/rebuild (L379–389); fingerprint changed -> plain `migrate` (L401–404).
- L456–482: if `schema_is_current` -> NO setup lock; else take fd 200, run `ensure_schema`, release (L480) BEFORE taking a run slot (L486+).
- L524 runs `php8.2 artisan test`; `tests/bootstrap.php` (last block) sets `RefreshDatabaseState::$migrated = true` when `LANE_TEST_SCHEMA_READY=1`, so RefreshDatabase skips `migrate:fresh` and just wraps each test in a transaction.

### 1.2 `scripts/schema-dump.sh` (193 lines)
- L56–58 same lock file. L110–114: `exec 200>$LOCK_FILE; flock 200` — held for the WHOLE run (L118 migrate from scratch of all 1,830 migrations, "~15-20 min", header L38).
- L91 own scratch schema `hfc_dash_test_7<epoch><pid>`; L128 `migrate --schema-path=/nonexistent`; L131 `schema:dump`; L143–163 carries GLOBAL reference rows; L192 atomic `mv -f "$TMP" "$TARGET"` (already atomic, same filesystem).

### 1.3 What the schema names really look like (25 worktrees checked)
- 11 of 25 worktrees have NO `TEST_DB_DATABASE` -> path-derived name -> cold load.
- The rest invent names: `_455, _100707, _9505, _9506, _709, _120, _390905 ...` (one cold load each).
- Names are SHARED across unrelated worktrees: `hfc_dash_test_1` = `/corex-qa1` AND cc1 worktrees; `hfc_dash_test_6` = four esign worktrees. Same name = same schema = queued behind each other AND tested against a database built by a different branch.
- Live consequence, verified read-only: `hfc_dash_test_1` has 1,813 migrations applied. Against `cc1-inspections-i4` (1,618 files) it is **195 migrations AHEAD**; against `/corex-qa1` (1,842 files) it is 32 behind and has 3 that QA1 does not have. Today's code (L401–404) runs `migrate`, which does nothing for "ahead", then STORES the fingerprint and runs the tests anyway. A fingerprint match does not mean "schema == this branch". Also: a migration EDITED in place (same name) is never re-run. Both are silent today.

### 1.4 Why the global lock exists — what race it protects
Not a data race. Data safety is the per-schema lock (fd 201) and the transaction wrapper. The setup lock is a THROUGHPUT guard:
comments at lane-test.sh L62–65 and schema-dump.sh L108–109 say concurrent snapshot loads "thrash the tests instance's small redo
log" and that heavy DDL should be serialised. It is a heuristic someone saw in practice, not a correctness requirement.
Measured today (idle box): ONE load = 130.4 s; TWO concurrent loads = 158.8 s wall (serial would be 261 s) -> concurrency was
1.64x FASTER, no thrash at N=2. Not measured: N>2 or under load; the container has a 3 GiB cap and sits at 2.56 GiB, so
unbounded parallel loads are an OOM risk. Conclusion: keep a limit (2), but the lock should not be held for 15–20 min by a dump.

## 2. Measurements (one throwaway schema each; snapshot = 732 tables, 1,812 FKs, 2,700 seed rows)

| What | Seconds | Note |
|---|---|---|
| Cold load of committed snapshot | **130.4** | same client path as lane-test.sh |
| …of which table DDL / data inserts | 125.5 / 0.6 | cost is 732 CREATE TABLEs (~0.17 s each) — DDL-bound |
| Two cold loads concurrent | 158.8 wall | vs 261 serial |
| Fingerprint compute (warm-run overhead) | 0.06 | 0.28 first, cold cache |
| (a) Transaction rollback of 808 rows churn | **0.1** | rows verified intact after |
| (b) Scan all 732 tables for non-empty | 1.6 | finds 18 (17 seed + migrations) |
| (c) TRUNCATE the 18 non-empty tables | 2.7 | |
| (c) Reseed 2,700 rows from the snapshot | 0.6 | |
| **(c) total "dirty reset" = scan + truncate + reseed** | **~4.9** | proposed recovery valve |
| (d) TRUNCATE all 732 tables | 133.5 | as slow as a reload — rejected |
| (e) DELETE FROM the 18 + reseed | ~0.7 | no scan; AUTO_INCREMENT not reset |
| Disk, one migrated schema | **124 MB** | 732 files; matches the 125 MB sweep log |

Compared options:
- **Rollback (RefreshDatabase transaction)** — 0.1 s, already in place. Right default. Cannot undo DDL or committed/multi-connection residue.
- **Truncate-all** — 133 s. DDL-bound, no better than a reload. Reject.
- **Restore per-lane snapshot** — 130 s (same as a load). Only for "schema ahead" / DDL residue.
- **Clone from a golden schema with `CREATE TABLE … LIKE`** — NOT measured and NOT valid: it drops all 1,812 FKs. Physical clone (`FLUSH TABLES … FOR EXPORT` + `IMPORT TABLESPACE`) not measured; it is 732 ALTERs, expect DDL-bound. MySQL CLONE plugin is whole-instance only.
- **Targeted reset (c)** — ~5 s, catches row residue in any table. Recommended middle tier.

Not measured: a real PHPUnit run end to end — `tests/bootstrap.php` refuses any name that is not `hfc_dash_test_*`, so a `zz_probe_*` schema cannot run the suite (correct guard; I left it alone). Only the setup/reset portion of a run is measured.

## 3. Design

### 3.1 Schemas — keyed by LANE, not by worktree
- `hfc_dash_test_9101 … 9106` = cc1 … cc6 (digits only). Passes the whitelist in `bootstrap.php`, `TestCase::setUp`, `lane-test.sh` L104, the `lanetest` grant and the sweep regex with ZERO code change. Literal `lane_cc1` names would need all five touched plus a grant — not recommended.
- Lane id comes from an env var `LANE_TEST_LANE=cc3` exported by the pane's launch command (NOT `.env.testing`: `bootstrap.php`/`lane-test.sh` never read it, Laravel only loads it under APP_ENV=testing, and 11 of 25 worktrees have no `.env` at all). Unset = today's behaviour exactly.
- Conductor's view: add `lane`, `last_worktree`, `last_branch`, `last_run_at` to the existing `_corex_lane_test_fingerprint` marker row; `scripts/lane-test.sh --lanes` prints lane -> schema -> last worktree/branch/time. A one-page registry `/root/lane-db-registry.md` mirrors it.
- Sweep (`lane-test-sweep.sh`) gets a protected list `^hfc_dash_test_910[1-6]$`. Without it, a lane schema is dropped after 2 h idle once no worktree `.env` names it.
- Each lane keeps the per-schema lock, so two panes of one lane never collide; different lanes never share a schema.

### 3.2 Reset between runs (tiered)
1. Default: transaction rollback (0.1 s) — unchanged.
2. `--reset` (new, replaces most `--fresh` use): scan non-empty tables -> TRUNCATE them -> reseed from the snapshot's INSERT block. ~5 s. Also a cheap structural guard: table count must equal golden's (733 incl. marker) else escalate to 3.
3. `--fresh` / auto when "schema ahead" or table-count mismatch: drop + reload (130 s, no global lock).

### 3.3 Migrations per lane, not per global fingerprint
- Keep the content fingerprint as the 0.06 s fast path.
- On mismatch compare the schema's own `migrations` table with the worktree's files: `files − applied` -> `artisan migrate` (only new ones; zero on a current snapshot — Staging's snapshot has 0 pending). `applied − files` ("schema ahead", found live in 1.3) -> rebuild (3.2 step 3), never "migrate and carry on". An edited-in-place migration is caught by the content hash -> rebuild.
- Lane branches off QA1 tip so "ahead" should be rare; Staging-only hotfix work vs QA1 work in the same lane will ping-pong (130 s each switch) — acceptable, still less than today.

### 3.4 Nightly golden snapshot, atomic, nobody waits
- Cron 03:30 runs the schema-dump logic in a dedicated worktree `/mnt/HC_Volume_103099143/corex-worktrees/lane-golden` (own `composer install`, tracks origin/QA1; never `/corex-qa1`) on its own scratch schema.
- It does NOT take the setup lock (own scratch schema; it only needs the 2-slot memory budget, and at 03:30 nobody is queued). 15–20 min is fine overnight.
- Output `/mnt/HC_Volume_103099143/lane-golden/mysql-schema.<qa1sha>.sql.tmp` -> `check_schema_snapshot` -> `mv` -> `ln -sfn` + rename to `current.sql` (both atomic). A lane's rebuild loads `current.sql` only if every migration in it exists in that worktree's files; otherwise falls back to the worktree's committed snapshot (today's behaviour).
- `scripts/schema-dump.sh` stays for refreshing the COMMITTED snapshot (CLAUDE.md 12a), minus the lock hold. `database/schema/mysql-schema.sql` in git is unchanged by this design.

### 3.5 Disk
One migrated schema = 124 MB. 6 lanes = **~0.75 GB**, + one transient scratch schema at 03:30 (124 MB) + golden file (1.9 MB) -> **~0.9 GB**. Today: 26 schemas = 5.4 GB on the data volume (85% used, 29 GB free), so the change frees ~4.4 GB once the ad-hoc names are retired. Nothing lands on `/` (81%).

## 4. Seed data
- Tests need NO copy of real data and none exists: the snapshot is structure + `migrations` ledger (1,830 rows) + 870 GLOBAL reference rows in 17 tables, written by migrations. Tenant rows (agencies, roles, role_permissions, HFC mail host) are excluded on purpose (`schema-dump.sh` L72).
- Largest: `docuperfect_named_fields` 677, `notification_event_types` 89, `document_types` 34, `nexus_permissions` 11, `agency_timeline_default_items` 11, then 12 tables of ≤ 8 rows.
- Already "one small fixture set". Reload is 0.6 s, so shrinking it buys nothing and could break tests. Recommendation: do not touch. Tests that need tenants build their own (existing practice).

## 5. Migration plan (each step independent, each reversible)
0. Sweep protection for `hfc_dash_test_9101–9106` (script change, no behaviour change). Rollback: revert.
1. `lane-test.sh`: `LANE_TEST_LANE` -> schema mapping + marker columns + `--lanes`. Unset var = old behaviour. Rollback: unset var.
2. `lane-test.sh`: migrations-table comparison + "ahead -> rebuild". Rollback: revert (restores today's silent behaviour).
3. `lane-test.sh --reset`. Additive. Rollback: don't call it.
4. Prime the six schemas once, sequentially, off-hours: 6 × ~130 s ≈ 13 min, under the existing lock. Rollback: drop them (no data worth keeping).
5. Golden cron + dump-without-lock (needs Johan's go for the cron entry — box change). Rollback: delete the cron file; lanes fall back to the committed snapshot automatically.
6. Roll lanes over ONE AT A TIME (emergency lane first): add `LANE_TEST_LANE` to its launch. Retire that lane's old ad-hoc names via `--drop-schema`.
What changes in lane prompts: nothing to type — still `scripts/lane-test.sh <one file>`. New: (a) the pane launch exports `LANE_TEST_LANE`; (b) delete any `TEST_DB_DATABASE` line from new worktree `.env`s (shell env wins, but stop inventing names); (c) residue -> `--reset`, not `--fresh`; (d) CLAUDE.md / Standard −1x updated; (e) conductor reads `--lanes` instead of asking.

## 6. Saving
Measured: cold load 130 s; dirty-reset 5 s (was 130 s via `--fresh`); warm run overhead ~0.2 s today and after — **a warm run saves nothing**; the gain is on every non-warm path.
- New worktree / unknown name: 130 s + queue wait -> 0 s (lane schema already exists).
- `--fresh` for residue: 130 s -> ~5 s.
- Waiting behind `schema-dump.sh`: 15–20 min per lane that needs setup -> 0.
- Per week (ESTIMATE — I could not count cold runs directly): the sweep header records 96 orphan schemas in 8 days (≈ 12 cold loads/day); this week 5 worktrees were touched and sweeps dropped ~9 recently, so plausible range **5–80 cold loads/week = 11 min – 2.9 h of loading**, before queueing. Queue time is the bigger cost (the 10–27 min Johan reports) and the design removes it; with 6 permanent schemas the steady state is ≈ 0–2 loads/week. Each wait also keeps a lane's pane (and tokens) idle.

## 7. Open points / risks
- A lane id must exist per pane (env var at launch). Needs the launch command, which I have not seen.
- N>2 concurrent loads and loads under real lane load are unmeasured; keep the 2-at-a-time bound and the 3 GiB cap in mind.
- Residue from tests that run DDL is only caught by the table-count guard; a test that alters a column in place would not be. `--fresh` remains the escape.
- Ledger discovery: the existing "ahead" behaviour is a live correctness gap today, independent of this proposal (1.3).
