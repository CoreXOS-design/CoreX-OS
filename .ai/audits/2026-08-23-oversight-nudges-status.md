# Oversight nudge emails — status, for Johan (2026-08-23)

Read this file, not the terminal. Full investigation and proof-of-work trail is in
`.ai/audits/2026-08-23-queue-failed-jobs-triage.md` if you want the detail — this file exists
so you don't have to.

---

## 1. Kill switch — the one-liner

**The bug is fixed. Sending is OFF. Staying off until you turn it on.**

To turn nudge emails on:
```
OVERSIGHT_NUDGES_ENABLED=true
```
in `.env`, then `php artisan config:clear` and restart the PHP-FPM pool. That's the whole
mechanism — one env var, no code change, no redeploy.

Right now, on staging: `OVERSIGHT_NUDGES_ENABLED` is unset, which defaults to `false`. Confirmed
live on staging as of this write-up (`config('oversight.nudges_enabled')` returns `false`).

**Read §2 before you flip it.** The bug being fixed is not the same question as whether it's
safe to enable — that's what the volume analysis below is for.

<details>
<summary>Reasoning (click if you need it — you probably don't)</summary>

The actual bug: `App\Mail\OversightNudgeMail` used `Content(view: ...)` for a template written
in markdown-component syntax (`@component('mail::message')`). That view namespace only gets
registered by Laravel's Markdown renderer, which `Content(view:)` never routes through — every
send since the feature shipped threw `No hint path defined for [mail]`. Fixed by switching to
`Content(markdown: ...)`. This fix is permanent and unconditional — it is not behind the flag,
because it's a correctness fix, not a feature.

The flag is a SEPARATE, second layer on top of that fix, added same-day after you flagged that
CoreX's original live cutover flooded staff with email and you didn't want a repeat. It gates
only the outbound `Mail::queue()` call inside `OversightDigestJob` — not the whole job. The
`OversightNudge` idempotency row and the in-app notification (bell icon) keep recording
normally while the flag is off. That's deliberate: if idempotency tracking also stopped while
off, every nudge that "should" have fired during the off period would look brand new the
instant you flip the switch, and all fire in one burst — the exact flood the switch exists to
prevent. Turning it on later starts clean, not with a backlog.

Config: `config/oversight.php`. Gate: `app/Jobs/OversightDigestJob.php`, inside `run()`.
</details>

---

## 2. Volume analysis — status: PARTIALLY MEASURED, clearly marked below

What's below is real, measured on staging using the actual production code path against real
current data — not a simulation. One piece is a projection, not a measurement, and it's the one
you'll care about most, so it's flagged explicitly rather than blended in.

**MEASURED — one real run, flag off, on staging:**
- 2,684 nudge-worthy items evaluated.
- 334 of those in email-channel categories (`deals_near_expiry`, `expiring_mandates`,
  `expiring_ffcs` — the other four categories are in-app only, never email regardless of this
  flag).
- 5 distinct managers would have received email this run.
- **Worst case, one manager, one run: 82 emails** (angelique@hfcoastal.co.za — 64
  `deals_near_expiry` + 18 `expiring_mandates`). Next worst: 76, 76, 75 for three other
  managers.

**MEASURED (code-level, not a projection) — does idempotency prevent repeats across runs?**
Yes, within its own window — the same (manager, category, item) will not fire twice inside that
window. But the window itself is wrong: `OversightDigestJob`'s threshold lookup falls back to a
**flat 24 hours** when no per-user preference is saved, instead of the intended per-category
value (168h / 336h / 720h for the three email categories). **Zero preference rows exist on
staging** — confirmed by direct count — so every manager, every category, is running on the
flat 24h fallback today, not the intended weeks-long cadence. The job runs hourly, so this does
NOT mean hourly repeats — but it does mean a still-outstanding item re-fires the next day, and
the day after, until it's actually resolved or ages out.

**PROJECTED, NOT MEASURED — daily total per manager.** I did not run the job across a real
24-hour window and count actual repeats — I observed one run and reasoned from the confirmed
24h-fallback finding above. My estimate: manager #44's 82 emails in one run is **plausibly
close to their sustained daily total**, recurring for as long as those specific deals/mandates
stay in their near-expiry window (which for the 30-day-intended `expiring_ffcs` category could
be weeks). Treat "82/day, for weeks" as an informed projection, not a fact — **if you want a
real number here, the fix is to either (a) run the digest hourly for a real 24h window on
staging with the flag off (persist=true, so it's proving real behavior, same as the one run
already done) and count actual repeat sends per manager, or (b) fix the 24h-fallback bug first
so the projection becomes moot.** Neither has been done. That's the honest gap.

**Bottom line for the enable decision:** as the code stands today, turning this on produces at
least one manager receiving on the order of 80 emails on day one, and — per the flat-24h
finding — plausibly again the next day, and the day after, not a one-time burst. I'd resolve
the 24h-fallback bug (or make a deliberate call that 24h is acceptable) before enabling, not
after.

Repeatable, side-effect-free tool for re-checking this later without touching any data:
```
php artisan corex:oversight-nudge-volume-report
```
Writes nothing, sends nothing, safe to run anytime.

---

## 3. Two warnings, written to outlive this conversation

### 3a. Never bulk-retry DesyndicatePropertyFromPortalsJob

**710 rows in `failed_jobs` (staging), recurring Property24 HTTP 401 since April 2026 — left
completely alone, on purpose.** This job removes a property from live syndication portals
(Property24, Private Property, agency websites). A failure recorded months ago tells you
nothing about whether de-syndication is still correct today — the property may since have been
re-listed, sold, or had its status legitimately changed by an agent. Retrying blind risks
**de-listing a property that should currently be live** — real, external, business-facing harm
to a real agency, caused by an automated process nobody was watching at the moment it ran. Any
retry of this backlog requires per-property confirmation of current status first, always. This
warning also lives in the job's own docblock
(`app/Jobs/Syndication/DesyndicatePropertyFromPortalsJob.php`), so it's visible to whoever is
about to act on the backlog, not just to whoever reads this file.

### 3b. The queue healthcheck blind spot — the shape of the mistake, not just the fix

`QueueHealthcheck` detected a stalled worker by watching how old the oldest **pending** job in
`jobs` was. Reasonable on its face — a healthy worker keeps that number near zero, a stuck one
lets it grow. The blind spot: **when a job fails, Laravel deletes it from `jobs` and inserts it
into `failed_jobs` — a different table.** So a worker that is actively running, but failing
every job it touches almost instantly, makes the pending-depth number look BETTER, not worse —
failure removes the row from the exact thing being watched. 10,356 failures of a single job
class happened while this monitor reported healthy the entire time, because none of them ever
sat in `jobs` long enough to be "old."

The trap isn't the missing check — it's that the intuition runs backwards. You'd expect "things
are failing" to show up as a worse number somewhere. It doesn't, if the thing you're watching is
defined by presence in a table that failure empties you out of. **This shape will recur
anywhere a health signal is inferred from "is it still waiting" rather than "did it complete
successfully" — any queue, any pending-state table, any workflow where failure and completion
both remove a row from the same place.** The fix here (`QueueHealthcheck::checkFailedJobsGrowth()`
— tracks `failed_jobs` COUNT growth between runs, independent of `jobs` depth) closes this one
instance. The thing worth remembering is the shape, not the patch.

---

## Archive/discard — staging state, live untouched

**Already run on staging** (Johan's go, 2026-08-23): 9,961 `App\Mail\OversightNudgeMail` rows
archived to
`/mnt/HC_Volume_103099143/corex-backups/queue-job-archives/failed-oversight-nudges-staging-20260823-063515.json`
(151MB, row count verified against the query before deletion), then deleted by those exact
archived ids. The other four failure classes were confirmed unmoved, before/after, per class —
see the full audit for the table. **Live's equivalent backlog (10,356 rows) has not been
touched, and there is no urgency to touch it** — with the feature staying off, that backlog
isn't blocking anything. The same command (`php artisan corex:archive-discard-oversight-nudge-failures`)
is proven and ready whenever you want it run against live — dry-run first, shows the exact SQL
and row count before anything happens, same as it did here.

**Nothing has been run against live. Nothing will be, without you saying so specifically for
that action.**

---

## Everything else — deployed and verified on staging, nothing to live

- Mail-namespace root-cause fix (`OversightNudgeMail`, `FeedbackReportMail`).
- Real `Queue::failing()` alerting (`App\Support\Queue\QueueFailureAlerter`) — a job failure now
  logs unconditionally and sends a rate-limited digest email, replacing a hook that used to do
  nothing.
- `QueueHealthcheck`'s blind-spot fix (§3b above).
- The archive-then-discard tool, proven and executed on staging (above).
- The nudges kill switch and volume-report tool (§1, §2 above).

All committed, pushed to `origin/Staging`, deployed to the served staging checkout, verified
live on staging. No open/half-finished code — this file is the stopping point.

## Not started — open for whoever picks this up next

The two highest-priority user-facing email waits from cc4's ranked list
(`handleAmendment()`'s notification, `requeueAllPartiesForInitialing()`) were assigned to me
today but not started — cc4 offered to take both themselves instead, citing same-day context in
`SignatureService.php` and the file's legal sensitivity. That question was never resolved before
this session had to stop. Whoever picks this up next should get an explicit decision on that
before anyone edits `SignatureService.php`, not assume either way.
