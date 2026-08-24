# P24 Importer — Async Listings+Images Parse

> Module: **Importer** — extension to `.ai/specs/importer.md` (§7 Stage 2) and
> `.ai/specs/importer-onboarding-portal.md`
> Status: Approved — Johan, 2026-08-14 (drafted and approved same session as
> a live-found bug).
> Pillars touched: **Property** (writes `p24_import_rows`, unchanged
> destination), **Agency** (portal creation timing changes).

---

## 1. What this is and why

`ImporterController::uploadListings()` parses both the listings and images
CSVs, and creates one `P24ImportRow` per listing, **fully synchronously
inside the HTTP request** — for a large agency (thousands of listings) this
is a genuinely long-running request with no queue dispatch at all. This was
the original v1 design (`importer.md` §7 step 8-10) and was never previously
a known problem.

Found live 2026-08-14: importing 4,753 listings for Demo Agency Test under
concurrent production load produced a `419 "Your session expired"` on the
resulting review page. Best-evidence root cause: the long synchronous
request interacted badly with session/CSRF handling under that load. Not
fully reproduced in isolation, but the underlying design — thousands of
individual DB inserts inside one HTTP request with no queue dispatch — is a
real risk regardless of what specifically triggered it that night, and is
the one step in the whole importer pipeline that was never made async. The
confirm step already went through exactly this fix in 2026-07-17 (`importer.md`
§14, "Parallel, lossless Import All") after an analogous problem; this spec
applies the same proven shape to the parse step.

---

## 2. What does NOT change

- CSV format, field mapping, agent resolution, fallback-admin logic — all
  identical, just relocated from the controller into a job.
- The confirm step, `ConfirmP24PropertyRowJob`, the `p24import`/`p24images`
  queue split — untouched.
- File upload validation (`$request->validate([...])` for the two CSV files)
  stays synchronous in the controller — it's fast (file presence/type/size
  only, no CSV parsing) and existing client-side error handling depends on
  getting validation errors back on the same request.
- The Stage-1/Stage-2 ordering guardrail ("import agents first") — unchanged,
  still checked synchronously before anything is queued.

---

## 3. The change

### 3.1 New job: `ParseP24ListingsImportJob`

Moves everything from `uploadListings()`'s `try` block (CSV parsing, agent
resolution, the `foreach` creating `P24ImportRow` rows, and the
`pending_confirm`/`failed` status transition) into `handle()`. Dispatched
on the `p24import` queue — it's DB writes, no CDN call, same reasoning as
`ConfirmP24PropertyRowJob` sharing that lane.

- `$tries = 1` — a parse failure is a data/format problem (bad CSV), not a
  transient one; retrying automatically would just fail the same way and
  delay the admin finding out. A crashed **worker process** (not a caught
  exception) can still cause Laravel to redeliver the job once — see the
  idempotency guard below for why that's safe.
- `$timeout = 900` — large CSVs, thousands of individual row inserts.
- **Idempotency guard:** at the start of `handle()`, soft-deletes any
  existing rows for this run before parsing (`P24ImportRow::where('run_id',
  ...)->delete()`). Cheap, and closes the one realistic failure mode this
  design has: a worker process dying mid-parse (OOM, deploy restart) leaves
  partial rows and a redelivered job would otherwise duplicate them.
- **Incremental progress:** every 250 rows, updates
  `run.counts_json['listings_parsed_so_far']` so the portal's existing
  polling can show live progress instead of a blank spinner on a large run.
- Reads `$run->user_id` instead of `auth()->id()` for the fallback-admin
  tie-break (a queued job has no request-scoped authenticated user) — the
  run already records who started the import.

### 3.2 `uploadListings()` — what stays synchronous

1. Validate the upload + Stage-1 guardrail (unchanged).
2. Store both files, create the `P24ImportRun` (`status = 'parsing'`,
   unchanged).
3. **Moved earlier:** create the `P24OnboardingPortal` immediately, right
   after the run — the admin gets a shareable link in the very same
   response, before parsing has even started. `portal.created` is logged
   here with `rows: null` in `meta_json` (the count genuinely isn't known
   yet); this is consistent with other event-log entries that don't always
   have every field at creation time.
4. Dispatch `ParseP24ListingsImportJob::dispatch($run->id)`.
5. Return immediately — same response shape as today
   (`redirect` + `portal_url` for JSON callers, a flash-message redirect
   otherwise), just returned before parsing is done rather than after.

### 3.3 Portal / review page — the "still parsing" state

`OnboardingPortalController::status()` (the existing polling endpoint,
already used for confirm/gallery progress) gains a `parse` key:

```json
"parse": {
  "status": "parsing" | "pending_confirm" | "failed" | null,
  "parsed_so_far": 1750,
  "error": null
}
```

`null` when the portal's run has already left `parsing` in the normal way
(i.e. for every portal created before this change, or once parsing
completes) — existing behaviour for those is untouched.

`review.blade.php` gains a progress panel matching the existing
property-write/gallery bars visually and behaviourally:
- Shown when `parse.status === 'parsing'`: "Parsing your listings — N so
  far…", disables the Import/Exclude bulk actions and per-row actions
  (mirrors how the existing property-write bar already disables buttons via
  `:disabled="progress.active"`) — the table still renders whatever rows
  have landed so far (progressive reveal), just not actionable mid-parse.
- Polls the existing `/status` endpoint every 2.5s (same cadence as
  `pollBatch()`); once `parse.status` leaves `'parsing'`, reloads the page
  once (`reloadFresh()`, already used elsewhere for exactly this purpose)
  so the full, now-actionable table renders normally.
- Shown when `parse.status === 'failed'`: a plain error banner with
  `run.error_message` and no auto-reload — the admin needs to see this on
  its own, not have it politely refresh away.

`welcome.blade.php` (the very first screen a portal visitor sees) is
unaffected — visiting it early into a still-parsing run works fine today
(it only renders portal/agency chrome + counts, and `counts()` on zero rows
is already a valid, if boring, empty state) and needs no special-casing.

---

## 4. Data model

No migration. `p24_import_runs.status` (`parsing` is already a valid enum
value) and `counts_json` (existing free-form JSON column) carry everything
needed.

---

## 5. Acceptance criteria

- Uploading a large listings+images CSV pair returns within the same
  request/response cycle as a small one (no more scaling with row count) —
  the admin gets the portal link immediately.
- The returned portal link resolves and shows a "still parsing" state with
  a live count while `ParseP24ListingsImportJob` runs.
- Once parsing completes, the page transitions to the normal, fully
  actionable review table with no manual refresh needed.
- A malformed CSV (parse throws) surfaces `run.status = 'failed'` and
  `error_message` on the portal, not a controller-level error response (the
  controller has already returned by the time parsing can fail).
- Re-delivering the job after a simulated mid-parse crash does not create
  duplicate `P24ImportRow` rows for the same run.
- Existing confirm-step behaviour (§14 of `importer.md`) is completely
  unaffected — this only touches the parse step.
- `scripts/dev-check.ps1`-equivalent: `php -l` clean; targeted test file
  passes.

---

## 6. Files to change

**New**
- `app/Jobs/ParseP24ListingsImportJob.php`
- `tests/Feature/Importer/AsyncListingsParseTest.php`

**Modify**
- `app/Http/Controllers/Admin/ImporterController.php` — `uploadListings()`
  slimmed to store+dispatch; portal creation moved earlier.
- `app/Http/Controllers/Public/OnboardingPortalController.php` —
  `status()` gains the `parse` key.
- `resources/views/onboarding/portal/review.blade.php` — parsing progress
  panel + polling + reload-on-complete.

---

*End of spec.*
