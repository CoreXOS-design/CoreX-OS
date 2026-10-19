# Audit D: money and spend (release 989f6ffd0..0c7bfebed)

Worktree: /mnt/HC_Volume_103099143/corex-worktrees/audit-release-2026-10-18 (HEAD 0c7bfebed). Read-only review. No tests were run.

Scope: (1) AI spend paths: guard, gateway, ledger, scheduled AI and loops. (2) The P24/PP refresh cost contract. (3) Money maths added in this release: commission/cap, job-card VAT, pricing/markup, supplier invoices, proforma, billing.

Totals: High 1 · Medium 3 · Low 5

---

## HIGH

### H1. Archiving a VAT type quietly drops VAT to 0% on every open job-card line that uses it. CONFIRMED
- `app/Services/Rentals/RentalJobCardVatService.php:63-65` (also :80, :118, :147, :367, :400)
- `app/Models/RentalJobCardLine.php:128-131`: `vatType()` is a plain `belongsTo` with no `withTrashed()`
- `app/Models/RentalVatType.php:136-139`: `archive()` is a soft delete
- `app/Http/Controllers/Admin/RentalVatTypeController.php:58-66`: archive has no "in use" or "is default" check

**Path:** `lineVat()` resolves `$line->vatType ?? RentalVatType::find($id)`. Both hide soft-deleted rows, so the result is `$type = null`. Then `rateFor(null)` returns 0.0 and the label becomes "No VAT". Every line on a card that is not yet frozen (no quote sent, not completed) is affected the moment the type is archived. When the quote is sent, `snapshot()` (line 284) freezes the 0%, so the owner gets a VAT-free quote.

**Wrong amount:**
- An agency that captures prices excluding VAT archives or replaces "Standard VAT". A R10 000 line now quotes the owner R10 000 instead of R11 500, so R1 500 per R10 000 is never charged.
- An agency that captures prices including VAT: R11 500 typed incl. becomes excl R11 500 with VAT R0. The VAT on the document is understated by R1 500, which is wrong on a SARS document.
- The owner's no-approval threshold (`inclusiveTotal()`, used by RentalApprovalGateService:261 and RentalJobCardStageService:42) is compared against the reduced figure.
- The same null-type path sets catalogue default prices (`catalogueDefaultPriceForLine`) to the excl figure.

**Fix:** resolve the line's type `withTrashed()` (relation and `find`). Block archiving the default type, or a type referenced by open lines, until it is replaced.

---

## MEDIUM

### M1. AI calls that time out are retried up to 3 times, and none of those attempts is recorded, so the spend guard cannot see them. CONFIRMED (code path) / PLAUSIBLE (that Anthropic bills each attempt)
- `app/Services/AI/AnthropicGateway.php:386-419`: `retry(max_retries=3)`. The callback returns true for a `ConnectionException` unless the request carries documents. A timeout is a `ConnectionException`.
- `AnthropicGateway.php:508-533`: `handleApiFailure()` writes no ledger row. `emitFallback` records cost 0, and only when a fallback exists, which none of the new callers have.

**New in this release:** these paths go through it with long timeouts and big outputs:
- AI Template Builder stage 1 when there is no reference image: `AiTemplateBuilderService.php:826-843`, 100 s timeout, max_tokens 10 500 on the quality model.
- Company Ad Builder stage 1: `CompanyAdBuilderService.php:284`.
- Article Library filter and briefs: `ArticleLibraryService.php:340-358` (60 s timeout) and `:405-430` (90 s timeout, 1 200 tokens).

**Cost:** a slow Anthropic day makes the gateway resend the same prompt three times. Anthropic usually finishes and bills a non-streamed call even after the client has timed out.
- One template-builder click can cost about 3 × R3 ≈ R9 with nothing in `ai_usage_events`.
- The R30 alert and R50 cut-out never trip on this spend.
- The template is reported "not counted" (see M2).
- The pre-release code already retried on timeout. The new guard depends on the ledger, so this is now a hole in the safety net, not just extra cost.

**Fix:** make the timeout no-retry rule apply to every request, not only document calls: retry only connect failures and 5xx.

### M2. Failed AI template / company-ad generations do not count against the monthly allowance, yet every attempt is paid for. CONFIRMED
- `app/Services/AdTemplates/AiTemplateBuilderService.php:1202-1215`: `usedQuery()` counts only `success` and fresh `pending` rows. `markFailed()` (1175) sets `failed`.
- `:422-436`: the failure path, with the message "This attempt was not counted".
- Company ads share the same pool: `CompanyAdBuilderService.php:144, 169-178`.

**Cost:**
- A failed stage 1 is still two paid quality-model calls, about R3 each (10 500 output tokens at $15/M × R16.50), or three paid calls with M1.
- A ticked must-include the model cannot satisfy, or an idea that keeps failing validation, can be retried at the route throttle of 8 per minute per user (`routes/web.php:525-527, 557-559`), with no allowance consumed.
- The only stop is the agency's R50 per 24 h cut-out. After the owner resumes it, the next R50 can go the same way.
- The allowance (default 20 a month) therefore does not bound AI template spend.

**Fix:** count failed generations with cost_zar > 0 against the allowance. Alternatively, add a separate monthly cap on failed attempts, or a per-agency rand cap per month.

### M3. A single generation can fire many concurrent calls, so the R50 cut-out overshoots by more than "one in-flight call". CONFIRMED (mechanism) / PLAUSIBLE (size)
- `app/Services/AI/AnthropicGateway.php:92-149`: `generateMany()` runs `prepareCall()`, including the pause check, for every request before any HTTP call. That is up to 5 property types per round, 2 rounds (`AiTemplateBuilderService.php:619-646`), each up to 10 500 output tokens.
- `app/Services/AI/AiSpendGuard.php:43-44` documents the overshoot as one call. With the pool plus parallel users it is many.

**Cost:** one generation with 5 types can cost about R3 × (2 + 10) ≈ R36 in total (worst case). Several users clicking at once (8 per minute each) all pass `isPaused()` together. An agency can land at roughly R100–R250 before the pause takes hold, against a R50 cut-out.

**Fix:** add a reservation step. Before spending, check `windowSpend + estimated max cost of this call` against the cut-out inside the guard lock, or check the pause between pool rounds and cap concurrency per agency.

---

## LOW

### L1. Article Library: the 900 s run lock can expire before a scheduled run finishes, so a "Run now" can run alongside it and pay for the same briefs twice. PLAUSIBLE
- `app/Services/Articles/ArticleLibraryService.php:75, 146-160`: lock of 900 s. A scheduled run has no time budget (`$timeBudgetSeconds` is null). It can write up to 50 briefs at up to 90 s each, ×3 on timeout (M1).
- `withoutOverlapping` on the schedule (`routes/console.php:620`) only stops scheduled overlap.
- **Cost:** items in flight in both runs are each paid for (about R0.40–0.60 per brief on the quality model). The unique constraint then throws one away (`:473`).
- **Fix:** give the scheduled run the same time budget as the web run, or extend the lock while the run is still going.

### L2. Article Library: an item whose brief comes back as unreadable JSON is paid for again every night. PLAUSIBLE
- `ArticleLibraryService.php:433-436` throws a `RuntimeException` ("unreadable answer") after the call was paid. `:260-266` treats it as transient and does not `remember()` the item.
- The same happens to the filter call (`:364-366`): the whole candidate list is re-filtered next run.
- **Cost:** one quality-model call per stuck item per run, indefinitely (for example 10 stuck items × R0.5 × 30 nights ≈ R150 a month on the system bucket).
- **Fix:** count failed attempts per hash and remember the item after 2.

### L3. The spend-alert email is sent while the guard lock is held. Slow SMTP lets the 10 s lock expire, which delays the cut-out and can send the alert twice. PLAUSIBLE
- `app/Services/AI/AiSpendGuard.php:155-156` (lock 10 s, wait 5 s) and `:377` (`sendMail` inside `evaluateLocked`).
- While the mail is blocking, other evaluations wait 5 s, give up silently (`:157`), and do not pause even if spend is now over R50. The pause waits for the next costed call. If the mail takes longer than 10 s, a second evaluation enters before `ai_alert_last_sent_at` is written.
- **Fix:** write `alertSentAt` before mailing, and dispatch the mail after releasing the lock.

### L4. Rental job-card report: the "Selling" total sits beside "Cost". Selling is in the agency's capture mode (incl. VAT for incl. agencies); cost is always excl. VAT. CONFIRMED
- `app/Services/Rentals/RentalReportService.php:596, 634-635`: `total_selling` = raw `total_amount`, which is the sum of `line_total` in capture mode. `total_cost` (:601) is `marginFor()['costExcl']`.
- **Wrong amount:** for an incl.-VAT agency, a R1 000 + VAT job shows Selling R1 150 next to an excl. cost. The column total also mixes cards frozen in different modes. Margin itself is computed correctly.
- **Fix:** show selling excl. VAT, as margin does, or label the column with its basis.

### L5. Job-card margin reads the agency's live VAT registration, while cost reads the frozen snapshot. If an agency deregisters after a quote was sent, margin is overstated by the VAT. PLAUSIBLE (edge case)
- `app/Services/Rentals/RentalPricingService.php:218-220`: `lineVat($line, $agency, $mode)` returns nulls when `$agency->vat_registered` is now false. The `?? $line->line_total` fallback then uses the incl. figure as excl. `costBreakdown()` uses `vat_registered_snapshot` (`RentalJobCardVatService.php:391, 419`).
- **Fix:** in `marginFor()`, use the line's frozen `vat_excl_snapshot` when the card is frozen.

---

## Informational (by design, not defects)
- The per-agency monthly rand budget (`canMakeAiCall`, `ai_monthly_budget_zar`) is retired and replaced by a rolling 24 h cut-out of R50 that the owner can resume (`AiSpendGuard.php:24-25`). With no owner action, the most an agency can spend is about R50 × 30 ≈ R1 500 a month plus overshoot, and there is no longer any monthly ceiling. This is per the AT-456 spec. Flagged so it is a conscious decision.
- The alert recipient default `andre@corexos.co.za` is hard-coded (`AiSpendGuard.php:62`). It is a platform setting, not per agency, so it is fine for the system bucket.

---

## Checked and found clean
- **Guard coverage.** Every file that reaches Anthropic directly now calls `AiSpendGuard::assertAllowed()` and records cost through `AiUsageRecorder`: EllieAgentService (checked per loop round), IntentExtractionService, VisionRecognitionService, AiFieldMapperService, ClaudeVisionParserService (checked per page), ImporterAiService, MarketingCopyService, Presentations AIExtractionService, and AiChatProxyController (hf-ai /chat with estimated tokens). The guard and recorder resolve to the same bucket in each case.
- **Unguarded local services.** EmbeddingService, EmbedAll and SpeechToTextService hit the local hf-ai /embed and /transcribe, which are local models with no spend.
- **nginx.** `/ai/chat` on performance.hfcoastal.co.za is routed to the guarded Laravel proxy (`location = /ai/chat`). The generic `/ai/` passthrough to :3100 reaches only free endpoints.
- **No new direct SDK or Http:: calls.** The release adds no new `Http::` or SDK call to api.anthropic.com or api.openai.com that bypasses the guard. The three new AI services (AiTemplateBuilder, CompanyAdBuilder, ArticleLibrary) all go through AnthropicGateway, which checks the pause in `prepareCall()` for both `generate` and `generateMany` and records every successful call.
- **Allowance race.** The AI template allowance check-and-reserve is under a per-agency cache lock with a pending row (`AiTemplateBuilderService.php:257-277`), so two parallel requests at "1 left" cannot both pass.
- **Article Library limits.** Capped at max_per_run ≤ 50 and 60 filter candidates. It is checked against the system bucket (`system: true`) both on the schedule and on Run now. A pause refuses the run before any fetch.
- **WarmThisWeekTilesJob.** Now skips paused agencies.
- **Cost pricing.** `AiCostCalculator` now prices unknown model ids at the most expensive configured rate instead of R0, which errs on the safe side.
- **Refresh cost contract.** No change to `Property24SyndicationService`. Mapper edits do not add portal calls:
  - P24 `occupationDate`/`availabilityDate` is now gated by `show_available_from_on_portals`.
  - PP `AvailableFrom` and the off-market status are fields in the existing payload.
  - The new back-on-market path in PropertyObserver (`saved`, re-list via `reactivateListing`) fires only on an off-market → on-market status change. It makes one `setListingStatus` call, then returns without also re-submitting. It is not on the unchanged-refresh path.
  - `DesyndicatePropertyFromPortalsJob` now stands down when the property is back on market, so there is no wasted withdraw.
- **Commission.** `CommissionCalculationService` is still bcmath at scale 2. A cap of 0 now means "no cap" consistently:
  - `hasCap` / `checkCap` / `getRemainingToCap` / `getCapProgressPercent`, and the just-capped check.
  - `adoptAgencyCap` moves only open periods that had cap 0.
  - `annual_cap` nullable becomes 0. Both forms (settings page and wizard step) render the field, so it cannot be wiped by omission.
  - The new neutral defaults (100/0 split, no fees) apply only to newly created rows. `firstOrCreate` does not rewrite existing ones.
- **Job-card VAT maths** (apart from H1, L4, L5):
  - Per-line rounding to 2 dp. Incl. → excl uses `round(incl / (1 + r))` with VAT = incl − excl, so there is no double VAT.
  - Totals are a sum of rounded lines.
  - The snapshot freezes rate, mode and registration.
  - The owner quote, approval threshold and completion cost all use `inclusiveTotal()`.
  - An agency that is not VAT-registered short-circuits to `total_amount`.
- **Pricing and markup.** Rule order is consistent. Rounding is per unit, then per line. Markup is bounded to 0–1000 %.
- **Other money code.**
  - Supplier invoices are evidence only and never write `cost_amount`. Amounts are rounded to 2 dp.
  - `SupplierCalloutFeeService` validates gt:0 and rounds to 2 dp.
  - Proforma/billing/deposit-calculator views have wording and styling changes only, no maths changes.
  - The payroll payslip prefix change has no money impact.
