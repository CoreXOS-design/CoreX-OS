# AT-68 — expired mandate → pull the property off the portals (INVESTIGATE-ONLY)

**Date:** 2026-07-20 · **Lane:** cc3 · **Ticket:** AT-68 (In Progress, assigned Johan)
**Status:** investigation complete — cause + approach for Johan's sign-off. **NO code written.**
**Rule to enforce:** the agency must NEVER advertise a listing whose mandate has expired; on expiry
CoreX auto-withdraws from Property24 + Private Property (+ website); on renewal it re-lists. Legal
exposure (PPRA / Property Practitioners Act 22 of 2019). Launch-critical — treat with care.

---

## Headline

**The expiry → auto-withdraw spine is ALREADY BUILT and wired (June 2026), and it already reuses the
exact same proven withdraw path a manual withdraw uses.** The genuinely NEW AT-68 work is two gaps:
1. the **agency-configurable grace window** (today expiry is hardcoded, zero grace); and
2. **auto re-list on renewal** (the reactivate capability exists but nothing fires it when a mandate is
   renewed).

Plus one robustness flag to raise (below): the withdraw path trusts the portal's "success" ack, which
AT-68/AT-221 proved is not truth.

---

## 1. How mandate expiry is detected/stored today

- **Field:** `properties.expiry_date` (the mandate end-date on agency stock). Referenced by the live-
  mandate scope in `app/Models/Property.php:1028` ("expiry_date IS NULL OR expiry_date >= today").
  Mandate e-sign writes it via `WebTemplateDataService` (`:737`, `:1240` — `mandate_expiry` → `expiry_date`).
- **Detector:** `app/Console/Commands/ExpireMandates.php` (`mandates:expire`). Daily scan:
  `whereNotNull('expiry_date')->whereDate('expiry_date', '<', $today)` and status not already
  `expired/sold/withdrawn` (`ExpireMandates.php:30-35`). For each match: sets `status='expired'` in a
  transaction, then fires `MandateExpired` (`:48-56`).
- **Scheduled:** YES — `Schedule::command('mandates:expire')->dailyAt('01:00')->onOneServer()->withoutOverlapping()`
  (`routes/console.php:381`). Has a `--dry-run` flag.

## 2. Where syndication push AND withdraw happen (the proven manual path to reuse)

- **Withdraw (depublish) service methods:**
  - P24: `Property24SyndicationService::deactivateListing()` (`app/Services/Syndication/Property24/Property24SyndicationService.php:463`).
  - PP: `PrivatePropertySyndicationService::deactivateListing()` (`app/Services/PrivateProperty/PrivatePropertySyndicationService.php:235`).
  - Website: `WebsiteSyndicationService::setEnabled($property,$key,false)`.
- **The single job that does the withdraw across all channels:**
  `app/Jobs/Syndication/DesyndicatePropertyFromPortalsJob.php` — delists P24 (`:98`), PP (`:120`),
  websites (`:143`). Failure-isolated per portal, `$tries=3`, backoff `[60,300,900]`, idempotent guards
  (`mayBeLiveOnP24()`, `pp_syndication_enabled && mayBeLiveOnPp()`) so retries never double-delist.
- **A MANUAL off-market change uses this SAME job:** `PropertyObserver` (`app/Observers/PropertyObserver.php:394`)
  dispatches `DesyndicatePropertyFromPortalsJob` on any status change to an off-market status
  (withdrawn/expired/sold/cancelled), plus a per-status P24 `setListingStatus` push in the auto-sync
  block (`PropertyObserver.php` ~455). **So auto-withdraw already reuses the proven manual path — the
  job IS the shared path.** ✅ (This is exactly what the ticket asks for.)
- **Push / re-list capability (exists):** `submitListing()` and **`reactivateListing()`** on BOTH
  services (P24 `:88`/`:496`; PP `:41`/`:369`). Manual reactivate endpoint:
  `P24SyndicationController::reactivate` (`app/Http/Controllers/Property24/P24SyndicationController.php:121`).

## 3. Current actual behaviour on expiry

- **The listing does NOT stay live after expiry.** Chain (all present + wired):
  `mandates:expire` (01:00 daily) → `status='expired'` + `MandateExpired` event
  (`app/Events/Mandate/MandateExpired.php`) → listener `DesyndicateExpiredMandate`
  (`app/Listeners/Mandate/DesyndicateExpiredMandate.php:46`, auto-discovered, `removeFromWebsite: true`)
  → `DesyndicatePropertyFromPortalsJob` → P24 + PP + website delist.
- Documented in `.ai/audits/mandate-expiry-desyndication-2026-06-20.md`.
- **Caveat (env):** the delist is a QUEUED job. QA1 is web-only (no queue worker, per BUILD_STANDARD §8),
  so on QA1 the job queues but does not process — expiry-withdraw can only be proven on **Staging**
  (Johan-gated) or by running the job synchronously in a test. Cron + queue run on Staging/live.

## 4. The gaps vs the ticket (the actual AT-68 work)

**GAP A — grace window is NOT a setting (hardcoded, zero grace).** `ExpireMandates` expires the day
AFTER `expiry_date` (`whereDate('expiry_date','<',$today)`, `ExpireMandates.php:32`). The ticket
requires an **agency-configurable grace window/threshold with a sensible default, never hardcoded.**
Nothing of the sort exists (grep for a mandate grace setting: none).

**GAP B — no auto re-list on renewal.** There is no `MandateRenewed` event and no listener that calls
`reactivateListing()`/`submitListing()` when a mandate is renewed. The PropertyObserver status auto-sync
only sends a lightweight `setListingStatus` ping — which will NOT re-add a listing that was DEACTIVATED
(removed) from the portal; that needs `reactivateListing()`/`submitListing()`. It is also unconfirmed
whether renewing a mandate today automatically flips `status` off `expired` and pushes `expiry_date`
forward (renewal appears to run through the mandate e-sign / `WebTemplateDataService`, not a single
"renew" action). **Open question for Johan: what is the "renewal" action in CoreX, and does it reset
`properties.expiry_date` + `status`?** — the re-list trigger hangs off that answer.

---

## Proposed approach (for sign-off — spec-first after approval)

**Reuse everything above; add only the two missing pieces. No new withdraw path.**

**A. Agency-configurable grace window (the core new build).**
- New agency setting, e.g. `agencies.mandate_expiry_grace_days` (unsigned int, **default TBD by Johan** —
  0 = strict day-after, or e.g. 3–7 days grace). Read it PER AGENCY in `ExpireMandates` so the cutoff
  becomes `expiry_date < today - grace_days(agency)`. Because the scan is global, either group the scan
  by agency or join the setting (design in the spec).
- **Must be surfaced in the Agency Onboarding Setup Wizard** (`config/agency-onboarding-copy.php`) in the
  same build — Non-negotiable #10a / BUILD_STANDARD §8 — with `explain` + `affects` + its saver
  (guard the write with `$request->has()`), OR it's Johan's explicit call to keep it out (recorded in
  the spec's "Deliberately NOT in the wizard" list).
- Nothing else changes — the existing event → listener → job chain fires exactly as today, just on the
  grace-adjusted date.

**B. Auto re-list on renewal.**
- Confirm the renewal action first (open question above). Then, when a renewal sets `expiry_date` forward
  and status back to on-market, emit a `MandateRenewed` domain event (mirroring `MandateExpired`, per
  `.ai/specs/corex-domain-events-spec.md`), with a listener that dispatches a re-list job calling the
  EXISTING `reactivateListing()` (P24 + PP) — the mirror of the desync job. Reuse, don't reinvent.

**C. Robustness flag to raise now (report-only — bears directly on "never advertise an expired listing"):**
the withdraw path reads `result['success']` from the portal, but AT-68/AT-221 proved **the portal's
success ack is NOT truth** (PP returns `"Successful"` while doing nothing; P24 returns HTTP 200 while
rejecting). For a LEGAL withdrawal we should **read back and assert the listing is actually off**
(`GetActiveListings`/`GetListingStatus` for PP; `isOnPortal` for P24) rather than trust the ack. Flagging
for Johan — whether to fold this hardening into AT-68 or keep it separate is his call. See
[[at68-pp-status-parity]].

**Open questions for Johan (blocking spec):**
1. Grace-window default value + unit (calendar days?) and scope (per-agency setting confirmed?).
2. What is the concrete "mandate renewal" action, and does it reset `properties.expiry_date` + `status`
   today? (Determines the re-list trigger.)
3. Fold the portal-ack read-back hardening (C) into AT-68, or track separately?
4. QA: expiry-withdraw is queue-dependent → first real QA on Staging (QA1 is web-only). OK?

**Files that would change (build phase, on approval):** `ExpireMandates.php` (grace read),
`config/agency-onboarding-copy.php` + the agency-settings page + saver (new setting + wizard), a
migration (grace column), a new `MandateRenewed` event + listener + re-list job (renewal). No changes to
the proven `DesyndicatePropertyFromPortalsJob` withdraw path.
