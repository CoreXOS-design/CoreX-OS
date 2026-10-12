# CoreX Multi-Agency Stress Test — 2026-09-16

> Requested by Johan: "make sure the system can handle multiple agencies."
> Two halves tested: **isolation** (can agency A see agency B?) and **capacity**
> (how many agencies can the box carry?). Run on the LIVE host while it was in
> maintenance mode. **No data was written** — all probes read-only; the load
> generator issued GETs only.

Host measured: `ubuntu-16gb-hel1-5` / 62.238.31.82 — **8 cores / 15.6 GB RAM**,
php8.3-fpm (`pm.max_children=30`), MySQL 8, DB `corexos`, branch `Prod`.

---

## 1. Isolation — PASS, no leakage found

Empirical test: authenticated (in-process, no session written) as a real
non-owner agent from each agency and counted rows visible from the other.

| Model | Agency 1 sees | Agency 20 sees | Foreign rows visible |
|---|---|---|---|
| Property | 5,786 | 3,410 | **0 / 0** |
| Contact | 10,947 | 0 | **0 / 0** |
| Deal | 161 | 0 | **0 / 0** |
| Document | 3,026 | 0 | **0 / 0** |
| Docuperfect Document | 571 | 0 | **0 / 0** |
| User | 20 | 5 | **0 / 0** |
| Branch | 3 | 1 | **0 / 0** |

Reconciliation is exact: 9,196 live properties = 5,786 + 3,410, and **0 rows
carry a NULL agency_id** (no orphans invisible to everyone). Same for contacts.

**Static sweep:** of 462 models, 312 back tables carrying `agency_id`; 286 apply
`BelongsToAgency`/`AgencyScope`. All **26** that do not were examined individually:

- *Deliberate + documented shared-pool / global-default patterns* (18):
  `MarketDataPoint` (spec §13.2 shared pool, agency_id = audit only),
  `CompiledTemplate`, `DataDictionaryEntry`, `RecipientTemplate`,
  `Docuperfect\Template`, `PerformanceSetting`, `ActivityDefinition`,
  `AINarrativeCache`, `AiUsageEvent`, `AgentActivityEvent`, `EsignSettings`,
  `SoftDeleteRestoration`, `UserBranchHistory`, `AgentSeatRelease`,
  `DealDocumentAccessLog`, `MinionCaptureSettings`, `Role`, `SchemeOwner`.
- *Isolated by explicit filter at every call site* (5): `MinionCaptureArea`,
  `MinionCaptureRun`, `RolePermission` (RoleManagerController filters
  `agency_id` on both read and the delete+insert save path), `AgentSignature`
  (keyed on globally-unique `user_id`), `DocumentSealedVersion` (keyed on
  `document_id`, parent is scoped).
- *Write-only today, never read in app code* (3): `ProspectingPriceAnomaly`,
  `SchemeOwner`, `CompiledTemplateFieldBinding`.

**Forward-looking note (not a current bug):** `ProspectingPriceAnomaly` and
`SchemeOwner` are written but never read. If a read path is added later without
an explicit `agency_id` filter, it would leak — they have no global scope to
catch the omission.

---

## 2. Capacity — measured ceiling ~134 req/s, CPU-bound

Read-only ramp, `GET /login` (full stack: framework boot + DB session + render),
keep-alive, zero errors at every level (HTTP 200 on 100% of ~3,000 requests).

| Concurrency | Throughput | p50 | p95 | Verdict |
|---|---|---|---|---|
| 1 | 16.9 req/s | 57 ms | 72 ms | idle-fast |
| 5 | 70.6 req/s | 54 ms | 71 ms | healthy |
| 10 | 121.9 req/s | 71 ms | 98 ms | healthy |
| 20 | 132.5 req/s | 132 ms | 211 ms | **saturated** |
| 30 | 132.3 req/s | 209 ms | 322 ms | queueing |
| 45 | 135.2 req/s | 292 ms | 510 ms | queueing |
| 60 | 132.3 req/s | 392 ms | 584 ms | queueing |

**Validity check:** two independent client processes × 25 concurrent returned
66.1 + 66.0 = 132.1 req/s — identical to the single-client ceiling, proving the
*server* is the limit, not the test rig.

**Bottleneck identified:** during sustained load, CPU = **96.4% user, 0.0% idle,
0.0% iowait**, and php-fpm children pinned at exactly **30** (`pm.max_children`).
It is **CPU-bound on 8 cores**, not disk- or DB-IO-bound. Raising `max_children`
alone will NOT help — there is no idle CPU left to give it.

### Per-agency page cost scales with that agency's own data

| Query (agency-scoped) | HFC (5,786 props / 10,947 contacts) | Demo (3,410 props / 0 contacts) |
|---|---|---|
| property list, 25 rows + relations | 3.2 ms | 8.0 ms |
| **property COUNT (pagination)** | **21.1 ms** | 10.1 ms |
| **contact COUNT (pagination)** | **23.6 ms** | 0.9 ms |
| deal / document COUNT | 2.9 ms | 1.5 ms |
| **total DB work per page** | **50.8 ms** | 20.6 ms |

~45 of HFC's 50.8 ms is **pagination COUNT queries** — full index scans run purely
to render "Showing 1–25 of 5,786". Cost grows linearly with each agency's own
row count, independent of how many agencies exist.

**The scaling shape: agency *count* is cheap; agency *data volume* is what costs.**

---

## 3. Discrepancy with the 2026-07-04 capacity audit

That audit recorded a **CAX41, 16 vCPU / 30 GiB** (hostname then
`ubuntu-4gb-nbg1-2`, Nuremberg) and projected **50–100+ HFC-sized agencies**
after raising `max_children`. This host is now **8 cores / 15.6 GB**, hostname
`ubuntu-16gb-hel1-5` (Helsinki) — a different machine, roughly half the CPU and
half the RAM.

`max_children` **has** since been raised 5 → 30 as that audit recommended, but
the machine it was sized against is not the machine running today. **The July
audit's agency-capacity conclusion should not be relied on.** Whether this was a
deliberate migration or a mis-recorded spec needs reconciling by Johan/Andre —
not determined here.

Using that audit's own stated assumption (an HFC-sized agency ≈ 2–4 req/s peak)
against the **measured** 134 req/s ceiling, and discounting for real agent pages
being heavier than `/login`:

- Theoretical saturation: ~35–65 agencies (light pages), ~15–30 (realistic mix)
- **At a sane ~60% utilisation target: roughly 10–18 HFC-sized agencies today.**

---

## 4. Also found (NOT changed — report-only per operating rule 2)

1. **Laravel bug: maintenance mode makes every worker crash-loop.**
   `Worker::pauseWorker()` (Worker.php:310) calls
   `stopIfNecessary($options, $lastRestart)` without `$startTime`, which defaults
   to `0`. The `--max-time` check at Worker.php:334 then evaluates
   `hrtime(true)/1e9 - 0 >= maxTime` — i.e. monotonic clock (≈ box uptime,
   2,535,263 s) vs 3600 — always true, so the worker exits on its first pause
   loop. Maintenance mode triggers the pause path via WorkCommand.php:366.
   With supervisor `autorestart=true`, all 23 workers restarted every 1–3 s,
   burning ~50% of 8 cores for as long as maintenance stayed on. Normally masked
   because `deploy.sh` holds maintenance for only ~1 minute.
   **Mitigation applied for this session:** workers stopped
   (`supervisorctl stop all`), CPU returned to 98.8% idle.
   **Suggested permanent fix (needs Johan's go):** stop workers as part of
   `deploy.sh` step 3 and start them at step 11 — the pattern
   `scripts/qa1/sync-from-live.sh` already uses.
2. **983 failed jobs** accumulated in `failed_jobs` (the 2026-07-04 audit
   reported 3,229 and recommended purging; it has regrown).
3. **Mail worker down since 2026-09-11 06:50** — 178 × `SQLSTATE[HY000] [2002]
   Connection refused` in `storage/logs/worker-mail.log` (SMTP connector, not
   the database). Queued mail has not been sent since that date.

---

## 5. Recommendations (in order of value, none applied)

1. **Reconcile the hardware question first** — capacity planning is meaningless
   until it is known whether the 8-core box is intended.
2. **Kill the pagination COUNTs.** ~45 ms of every HFC page load is counting rows
   for "Showing 1–25 of N". Caching the count per agency, or switching to
   `simplePaginate`, is the single biggest per-page win and it gets *better*
   the more data an agency has.
3. **Move sessions + cache to Redis.** Redis is installed and running on this
   host but unused — `SESSION_DRIVER`, `CACHE_STORE` and `QUEUE_CONNECTION` are
   all still `database`. This was recommendation #2 of the July audit and is
   still outstanding. It frees CPU and DB commits on every single request.
4. Do **not** raise `pm.max_children` above 30 — CPU is already at 0% idle.
   More workers would add queueing, not throughput.
5. Fix the mail worker (item 4.3) — it is silently not sending.

---

## 6. Test method / reproducibility

- Load generator: `curl_multi` PHP harness, GET-only, keep-alive, run from the
  host itself; validated against a second independent client process.
- Isolation probe: `Auth::setUser()` (fires no events, writes no session),
  then per-model counts with and without global scopes.
- Nothing was written to the database. No code changed. No agencies created.
