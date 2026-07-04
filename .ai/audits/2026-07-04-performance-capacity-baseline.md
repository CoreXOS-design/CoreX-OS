# CoreX Live Performance Audit & Capacity Baseline — 2026-07-04

> The measured half of the AT-172 server audit. Read-only measurement on the
> LIVE host; one zero-risk reversible MySQL correction applied (documented).
> Feeds Johan's AX102 upgrade-timing decision with real numbers.

Host: Hetzner **CAX41 — 16 vCPU (Ampere ARM) / 30 GiB RAM** (hostname
`ubuntu-4gb-nbg1-2` is stale, not the spec). Live app `/corex` →
`corex.hfcoastal.co.za` / `corexos.co.za`, php8.3 FPM, MySQL 8.0, DB `nexus_os`.

---

## The one-line answer for the AX102 decision

**The CAX41 is nowhere near capacity for HFC scale.** It runs ~7% CPU with 24 GB
RAM free. The only thing limiting it is a **single config line** (5 PHP workers).
The hardware upgrade is **not needed for raw throughput in the near term** — one
FPM change unlocks a 6–8× headroom increase on the box you already have.

---

## What we measured (plain terms)

| Finding | Detail | Verdict |
|---|---|---|
| **Idle hardware** | Load ~1.0 on 16 cores (~7% CPU), 24 GB of 30 GB RAM free | Vastly under-used |
| **The real ceiling** | PHP is capped at **5 concurrent requests** (`pm.max_children = 5`, the stock default) regardless of 16 idle cores | ⚠ Artificial bottleneck #1 |
| **Everything on the database** | Logins/sessions, cache, and the job queue all run on **MySQL** (`SESSION_DRIVER`/`CACHE_STORE`/`QUEUE_CONNECTION = database`). Redis is configured in `.env` but **not running and not loaded** — nothing uses it | ⚠ Concentration point #2 |
| **Code cache healthy** | php8.3 opcache: 96.3% hit rate, not full, 0 restarts | Good |
| **Page speed fine** | A representative full-stack page (login: boot + DB session write + render) ≈ **105 ms** | Good |
| **Front-end light** | 464 KB total built assets (~130 KB gzipped), compression on | Not a bottleneck |
| **DB per-query fine** | Buffer-pool hit ratio 99.84% (helped by OS cache); temp-tables barely spill (5 in 208k); 20 peak DB connections of 151 | Good after the fix below |
| **Batch cost** | The heaviest DB time is the **nightly backup** full-table scans (~1/night, off-peak), not user pages | Note only |

**Diagnosis:** individual requests are *fast*; the constraint is **concurrency**
(5 workers) and **architecture** (all state on MySQL), not slow code or slow SQL.

---

## Applied to LIVE (zero-risk, reversible — standing approval)

- **InnoDB buffer pool: 128 MB → 4.00 GB.** It was the stock default on an 8 GB
  database; 4 GB now holds half the DB directly in RAM and protects the working
  set from the nightly backup's eviction. Online resize, no downtime, `SET
  PERSIST` (revert: `SET PERSIST innodb_buffer_pool_size=134217728`).
- **thread_cache_size: 9 → 32.** `SET PERSIST`.
- Box healthy after: 23 GB free, load ~1.0.

## Proposed (NOT applied — Johan's call)

1. **Raise `pm.max_children` 5 → ~30** (php8.3 www pool). The single biggest win,
   ~zero memory risk (30 × ~60 MB ≈ 1.8 GB of 24 GB free). Reversible. Held only
   because it changes live concurrency and Johan was away.
2. **Move sessions + cache (+ queue) to Redis — STAGING first**, with before/after
   numbers, then a live proposal. Removes the per-request DB write+fsync that
   becomes the next bottleneck once workers are raised.
3. **MySQL (safe):** `innodb_buffer_pool_instances=8` (needs restart),
   `innodb_redo_log_capacity` 100 M → 512 M; consider `innodb_flush_log_at_trx_commit=2`
   for the session/cache/queue write path (durability trade).
4. opcache `validate_timestamps=Off` (deploys already reload FPM). Purge the 3,229
   stale `failed_jobs`. Add brotli.
5. ⚠ **Security (found in passing, not perf):** `/corex/.env` is world-readable
   (`-rwxr-xr-x`) and holds live DB / OpenAI / Anthropic / Meta / Google / mail /
   P24 secrets. On this shared multi-tenant box any local user can read them.
   Recommend `chmod 640 /corex/.env` (www-data still reads).

---

## Capacity baseline (the numbers for AX102 timing)

Derived analytically from measured single-request service time × worker count. A
live load test was deliberately **not** run against production.

| Scenario | Sustained throughput | Bound by |
|---|---|---|
| **Today (5 workers)** | ~**15–50 req/s** (page-mix dependent) | The 5-worker cap; 16 cores idle |
| **After `max_children`→30** | ~**120–300 req/s** (6–8×) | Then the MySQL commit/fsync path (all state on DB) |
| **After Redis for sessions/cache** | Higher still | CPU / DB read, i.e. genuine hardware limits |

**In HFC-size-agency equivalents** (assumption stated: a heavily-active agent ≈
0.2 req/s peak; an HFC-size agency of ~10–20 concurrent active agents ≈ 2–4 req/s
peak):

- **Today, even at the 5-worker cap:** ~**5–15 HFC-sized agencies**.
- **After the one FPM change:** ~**50–100+ HFC-sized agencies**.

**Conclusion for the AX102 decision:** buy time, not hardware. The CAX41 has large
untapped headroom; the upgrade is justified by *future multi-agency growth or a
deliberate architecture change*, not by current load. Revisit AX102 when sustained
peak approaches ~100 req/s or the agency count climbs past a few dozen active
tenants — both far beyond today.

---

## Method note

Measured read-only via: `/proc/loadavg`, `free`, FPM pool config, `cgi-fcgi`
opcache probe against the live socket, `performance_schema` digest + buffer-pool
counters, MySQL `SHOW GLOBAL VARIABLES/STATUS`, `curl -w` timing on the live login
page, and built-asset sizing. Full running log + proposals: AT-172 comment (perf
phase). The slow-query log was **not** enabled (weekend-morning traffic was near
zero; `performance_schema`'s 216 h aggregate was the richer, zero-touch source).
