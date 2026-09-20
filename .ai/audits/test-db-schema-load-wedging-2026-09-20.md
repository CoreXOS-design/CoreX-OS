# Test-DB schema-load wedging — an open finding, not yet root-caused

**Date:** 2026-09-20
**Author:** cc4
**Status:** UNRESOLVED. Cost several hours of a lane's night on 2026-09-19/20.
Written up on the conductor's explicit instruction so the next person who
hits this does not have to rediscover it from scratch.

---

## What this is NOT

This is not the DEFINER-clause / `SUPER or SET_ANY_DEFINER` failure already
documented in `CLAUDE.md` non-negotiable #12a. That failure is fast and
specific (`ERROR 1227`, an immediate rejection). What follows is a **silent,
total hang** — zero output, zero progress, for minutes at a time — which is
a different symptom with a different (and still unknown) cause.

## What was observed

Building `.ai/specs/rental-work-orders.md` §3a (Rental Fault Reports, Stage
1) in worktree `cc4-rental-fault-reports-stage1-2026-09-25`, every attempt
to run the new feature's test suite against a **freshly created, previously
untouched** `hfc_dash_test_<N>` database went through one of two outcomes:

1. **Runs cleanly** — bootstraps, migrates, executes all tests, exits. This
   happened exactly twice across the whole session, both times against a
   database that had **never been used by any process before**, and both
   times when `uptime`'s load average was low (under ~1.3) and
   `SHOW PROCESSLIST` showed no other active `hfc_dash_test_*` or
   `mysql-schema.sql`-loading connections.
2. **Hangs completely** — zero stdout, for the full length of whatever
   timeout was applied (observed hanging for 45+ minutes unbounded once,
   and repeatedly for the full 60–180s of every subsequent bounded
   `timeout` attempt), then either killed manually or reaped cleanly by
   `timeout` (SIGTERM handled gracefully; no orphan). This happened on:
   - a database that had already been used once (whether that one prior
     use succeeded or errored out partway through a migration)
   - a database that had never been used before, on at least one occasion
     (`hfc_dash_test_41`, brand new, hung on the very first attempt)

So the pattern is **not simply "fresh database always works, reused
database always hangs."** A fresh database worked twice and hung once. A
reused database hung every time it was tried (three separate reuse
attempts, three hangs).

## Exact stall point, isolated via `vendor/bin/phpunit --debug`

Every hang, without exception, stopped at or before the same PHPUnit
lifecycle event:

```
Test Suite Started (Tests\...\SomeTest, N tests)
Test Preparation Started (Tests\...\SomeTest::test_something)
```

...and never printed the next expected line, `Before Test Method Called
(...::setUp)`. This means the hang is somewhere between PHPUnit deciding to
run a test and PHPUnit actually invoking that test's `setUp()` — i.e.
**before `Tests\TestCase::setUp()` and before `RefreshDatabase` gets a
chance to run.** On the one occasion a run got PAST this point and into an
actual, informative failure (a real bug in this session's own migration —
see the Stage 1 commit), the debug log showed `Before Test Method Called`
immediately following `Test Preparation Started` with no perceptible delay.

This is the single most important unresolved fact: **the stall point
observed via `--debug` is BEFORE any of this session's code or migrations
ever execute**, which is why the hang cannot be blamed on the Stage 1
feature code itself, but it is also earlier than `RefreshDatabase`'s own
`mysql < database/schema/mysql-schema.sql` shell-out
(`tests/bootstrap.php` / `MySqlSchemaState`, per non-negotiable #12a) —
meaning the popular assumption ("it's the schema snapshot load that's
slow") is not fully consistent with the debug timeline either, and needs
re-examining by whoever picks this up.

## What was ruled out

- **Not a fresh-vs-reused-database question alone.** See above — both
  fresh and reused databases hung; both fresh and (once) reused databases
  worked.
- **Not plain Laravel bootstrap.** `php artisan tinker --execute="echo
  'ok';"` returned instantly, every time, including immediately before and
  after a hang. The Laravel container, service providers, and `.env`
  loading are not the bottleneck.
- **Not raw DB throughput.** A bare `CREATE TABLE x (...); DROP TABLE x;`
  against the SAME test schema, run directly via the `mysql` CLI, executed
  in 0.22s. MySQL itself was not slow to execute individual DDL, at least
  at the moment this was checked.
- **Not (solely) my own `GRANT`/`FLUSH PRIVILEGES`.** The very first hang
  followed directly after `GRANT ALL PRIVILEGES ON hfc_dash_test_4.* TO
  nexus; FLUSH PRIVILEGES;`, and that is a reasonable first suspect. But
  every subsequent hang that night occurred with NO fresh grant issued —
  including on databases created identically (`CREATE DATABASE`; `GRANT
  ALL PRIVILEGES ON <name>.* TO nexus`; no further `FLUSH PRIVILEGES`)
  well after the first incident.
- **Not `php artisan test`-specific.** Both the `php artisan test` wrapper
  and calling `vendor/bin/phpunit` directly exhibited the hang. (Direct
  `vendor/bin/phpunit` was more informative because `--debug` is only
  available that way, which is how the exact stall point above was found.)
- **Not this worktree's `vendor/`, `bootstrap/cache/`, or `storage/`
  being stale/cross-contaminated.** `bootstrap/cache/{packages,services}.php`
  were checked and confirmed freshly generated by this worktree's own
  `composer install`, with no absolute paths pointing outside the
  worktree — ruling out the specific vendor/autoloader cross-checkout
  gotcha CLAUDE.md already documents for a different incident.
- **Partially — general server load.** `uptime` load average was
  genuinely elevated (3.5–3.9 on a 16-core box) during the worst,
  longest hang, and genuinely low (0.47–1.36) during both successful
  runs, which is suggestive but not conclusive — one reused-database hang
  also occurred at the LOW load average (1.25), immediately following a
  successful run on the same database seconds earlier, which a pure
  system-load explanation does not obviously cover.
- **Contention from other lanes' concurrent schema loads — supported, not
  proven.** The conductor's hypothesis was that cc1/cc2/cc5 were all
  independently reloading the same ~547-table schema snapshot against the
  same shared MySQL instance overnight, and that the successful runs
  landed in gaps between theirs. This is consistent with the load-average
  correlation above and with the fact that a run finally succeeded
  cleanly once those lanes' overnight suites had finished. It does NOT by
  itself explain the reused-database hang that followed immediately after
  a success on a now-quiet system (see above) — so contention looks like
  A real contributing factor, quite possibly not the ONLY one.

## What was NOT ruled out — real open questions for whoever picks this up

- **What, exactly, happens between `Test Preparation Started` and `Before
  Test Method Called`?** This is PHPUnit's own internal machinery
  (resolving test doubles/attributes, possibly triggering autoload of the
  test class and its full dependency graph for the first time this
  process). Nothing was done to instrument or profile this specific
  window — e.g. `strace -f -tt` on the PHP process during a hang, or a
  PHP-level profiler (Xdebug trace, `pcntl` signal handler dumping a
  backtrace) was never attempted. This is the highest-value next step.
- **Whether the "reused database" hangs are actually about the DATABASE
  at all**, as opposed to some per-PROCESS state (a stale
  `bootstrap/cache/*.php` file regenerated mid-session, a PHP opcache
  entry, a leftover file lock under `storage/framework/`) that happens to
  correlate with "I just ran a test in this worktree a moment ago." This
  worktree's `storage/framework/{views,cache,sessions,testing}` and
  `bootstrap/cache` directories did not exist at all when the worktree was
  first created (a separate, already-understood gotcha — Laravel's
  `.gitignore`'d empty directories don't come through `git worktree add`)
  and had to be created by hand before ANYTHING would run. Whether
  something in that manual fix interacts badly with repeated runs was not
  checked.
- **Whether this reproduces in a worktree that was NOT freshly created
  the same night**, i.e. whether it is specific to a worktree still
  "settling" (first composer install, first cache-file generation, first
  few artisan commands) versus a long-lived worktree cc1/cc2/cc5 have
  been running against successfully all evening. This was the natural
  next diagnostic and was explicitly NOT run, on the conductor's
  instruction to stop rather than spend a fresh-worktree attempt at 2am.
- **Whether MySQL's own connection/thread state (not just table locks)
  was ever actually exhausted or throttled** — `max_connections`,
  `thread_cache_size`, or similar server-side limits were never checked
  against the actual concurrent connection count from 4+ lanes' queue
  workers plus however many test runs were live at once.
- **No `strace`/profiler evidence exists for any single hang.** Everything
  above was inferred from `SHOW PROCESSLIST`, `ps`, `uptime`, and
  PHPUnit's own `--debug` event stream — all external observation, no
  in-process trace of what the PHP process itself was blocked on
  (a syscall, a socket read, a file lock, a mutex).

## Practical guidance for the next lane that hits this

1. **Do not assume a hang is your code.** Check `ps aux` for the actual
   PHP/mysql process tree and its CPU-time-vs-wall-clock ratio first —
   near-zero CPU time after real wall-clock minutes is the signature of
   a genuine stall, not merely "slow."
2. **A fresh, differently-numbered `hfc_dash_test_<N>` is cheap and worth
   trying once or twice, but is NOT a guaranteed fix** — it worked twice,
   failed once, in this session's own experience. Don't treat "try a new
   database" as a reliable, repeatable escape hatch; treat it as one data
   point.
3. **`vendor/bin/phpunit --debug` is far more informative than `php
   artisan test`** for isolating exactly where a hang occurs — use it
   first, not as a last resort.
4. **If you get a hang, before killing anything: `strace -p <pid>`** (or
   attach with a profiler) to see what the process is actually blocked
   on. Nobody did this yet — it is very likely the fastest way to turn
   this from "unexplained" into "root-caused."
5. **Never `sudo mysql -e "DROP DATABASE ..."` or similar** as part of
   chasing this — the box's own sandbox already refuses that command as a
   destructive-action safety rail, for good reason; don't try to route
   around it.

## Why this belongs on the morning list

Four lanes (cc1, cc2, cc4, cc5) each independently reload a ~547-table
schema snapshot against one shared MySQL instance whenever their test
suite runs. Even with the contention hypothesis only partially confirmed,
that shape — many lanes, one DB server, each doing a heavy bulk-DDL
operation whenever they test — will keep colliding as more lanes run
concurrently or overnight. This is a finding about how the team's test
infrastructure is shared, not about any one feature, and it cost real
time twice already (the DEFINER-clause incident non-negotiable #12a
documents, and this one). It is worth a deliberate decision — e.g.
per-lane dedicated MySQL instances, a serialized/queued schema-load
step, or a genuinely faster schema-load mechanism — rather than being
rediscovered lane by lane.
