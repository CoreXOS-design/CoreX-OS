# Audit — AI overlap guard + band pricing on QA2 (2026-10-20)

Scope: everything built 2026-10-20 and pushed to QA2 —
`0f4b0b783` AI templates: refuse words on top of each other (AT-456 §B.11);
`578d7e6dd` New CoreX pricing: headcount bands (agreement + billing); `5f606bb45` band pricing test fixes.
Two independent read-only reviews (sections below, verbatim), then the conductor's own verification.

## Conductor verification (2026-10-20)

- **Pricing H1 (deploy migration can stop the deploy) — confirmed in code, NOT triggered on live today.** `publishBandPricing()` throws
  `DomainException` when a wording draft is open and `RuntimeException` when the wording no longer matches. Read-only check of live
  (`platform_esign_wording_versions`): one row, version 1.0, published, no draft. Dry run of the QA2 conversion on the LIVE 1.0 wording
  (nothing written): converts and passes every check; live 1.0 is byte-identical to the code's 1.0. Risk remains if anyone opens a
  wording draft before the deploy — check immediately before promoting, or harden the migration (Johan's call on behaviour).
- **Pricing H2 / M1 / M2 are business decisions** (who moves to bands; whether non-agent logins count toward a band; whether a new 31+
  agency should email CoreX) — put to Johan, not changed.
- **Overlap M2 (12-error cap on the retry) — confirmed** at `AiTemplateBuilderService.php:783` and `:839`.
- **Overlap L4 (company ads with no picture: rule 1c names custom_image while the prompt says do not use it) — confirmed**
  (`CompanyAdBuilderService.php:514`).
- Live AI run (3 generations, facebook / instagram / story, 35 designs incl. type versions): 0 overlaps reached the user; 4 of 39 AI
  answers were refused for overlap and fixed on retry, 1 type version dropped to the main design.


---

## Review 1 — AI overlap guard

## Audit: 0f4b0b783 "AI templates: refuse words on top of each other (AT-456 §B.11)"

Worktree: /mnt/HC_Volume_103099143/corex-worktrees/ai-ad-overlap-guard-2026-10-20
Report only. No code was changed and no tests were run. All line numbers below are for that worktree at 0f4b0b783.

### Verdict

The core geometry is correct. There are no HIGH findings. The check only runs on AI-generated designs: `validate()` and `validateAndRepair()` are called only from AiTemplateBuilderService.php:590 and CompanyAdBuilderService.php:394. Templates that users save or drag in the Ad Builder are not affected.

What is left is mostly about how well the retry can act on the errors (element numbering, the 12-line cap), one prompt contradiction in company mode, and some gaps in what the check covers.

### Findings

#### MEDIUM 1: The element number in overlap errors may not match the AI's answer
- In `checkOverlaps()` the tag is `'Element ' . ($idx + 1)`, and `$idx` is the position in the **built** list (AdLayoutValidator.php ~1351). That list leaves out:
  - elements `buildElement()` rejected (it returns null on any error, AdLayoutValidator.php:1146-1250, used at :420-425);
  - elements `repairRaw()` already removed: unknown types, custom_text/badge/ribbon with no text, a second custom_image, listing fields on a company ad (:496-525);
  - elements the callers strip before validating: unticked extras via `AdTemplateMustIncludes::stripUnticked` and `AdIdeaWishes::stripIcons` (AiTemplateBuilderService.php:583-590), and in company mode the custom_image / ME_FIELDS / agency-name text removals (CompanyAdBuilderService.php:362-385).
- The result: "Element 12 (erf_size)" may not be the 12th element the AI wrote. It can also disagree with the numbering in other errors for the same option, because `buildElement()` tags by raw index `$i`.
- The field name usually makes it clear. When a design has two elements of the same type (several `custom_text` lines or badges are common), the number is the only way to tell them apart, and it can point at the wrong one. That makes the targeted retry less effective. It matters most for property-type versions, where the retry is asked to fix the same design (AiTemplateBuilderService.php:783).
- Possible fix (for the conductor to decide): carry the original raw index on each element through repair and build, and tag with that.

#### MEDIUM 2: Overlap errors can push other errors out of the 12-line retry list
- The validator returns every pair, with no limit (:1360-1370). The log keeps 20 errors per option (AiTemplateBuilderService.php:526, :704; CompanyAdBuilderService.php:312), which is fine for diagnosis.
- The retry prompt keeps only the first 12 errors across **all** options combined: AiTemplateBuilderService.php:783 (property-type versions) and :839 (defaults), and CompanyAdBuilderService.php:520.
- One badly laid-out option can fill all 12 lines. Example: a full-width text box crossing five text rows gives 5+ errors, plus one error per further clash. The other options' errors, and "missing what the user ticked" lines (appended later in the loop), then never reach the AI.
- This cap existed before this commit, but overlap is the first rule that grows with pairs of elements (up to 40*39/2). The spec's promise that the retry "is told exactly which two elements to move" does not hold once the 12-line cap is hit.
- Suggestion: limit overlap errors per option (for example 3, plus "and N more"), or share the 12 lines fairly across options.

#### LOW 3: A repair can now cause the design to be refused, and the retry is not told why
- Two repairs move elements without the AI knowing:
  - `repairRaw()` moves out-of-canvas elements back inside (:557-569);
  - in company mode, it adds an `agency_name` pill at a fixed spot bottom-left (x40, y ch-104, 520x64) when no agency mark exists (:636-645).
- Either can now land on another element and trigger an overlap refusal. The retry is then told that "Element N (agency_name)" sits on something, although the AI never wrote that element, or that two elements overlap which did not overlap in its own answer. Nothing in the message says a repair moved them.
- The test-data changes in this commit show exactly this happening. See finding 9.
- The outcome is still safe: no clashing design reaches the user. But it will cost more options for designs that were nearly right. This matches the spec's design choice ("never repaired"), so it is acceptable, but expect more short results ("fewer than 3 options"). I could not measure how often, because no live call was made.

#### LOW 4: Company mode with no picture names custom_image in rule 1c
- In `promptLimits()` the exempt list keeps `custom_image` in company mode whenever it is not in `$forbidden` (:1001-1002).
- CompanyAdBuilderService.php:563 builds `$forbidden` as `FIELDS - allowed`. `custom_image` is not in `FIELDS` (it is in COMPANY_ONLY_FIELDS), so it is never forbidden, even when there is no picture. In that case the catalogue drops it (:565) and the user prompt says "Do not use custom_image" (:512, :514).
- So in no-picture and "copy the look" modes, rule 1c still lists custom_image as something other elements may sit on. That is a small contradiction and goes against the spirit of decision 20. The existing test `CompanyAdAiBuilderTest.php:195` only checks for `'- custom_image:'`, so it does not catch this.
- Fix: pass `custom_image` in `$forbidden` when `! $hasPicture`, or filter it in the caller.

#### LOW 5: The ribbon is exempt, so words on the ribbon's band are not caught
- `ribbon` is in OVERLAP_EXEMPT_FIELDS (:99-100) because its box is mostly empty. But the band itself carries text. A status_badge, logo or text placed in the same corner across the diagonal band is not refused.
- A corner check (only the triangle or band of `ribbonCorner`) would close this gap. As written it is a deliberate trade-off, and it is documented in the spec.

#### LOW 6: Rotation is ignored
- The check compares the unrotated boxes (:1351-1352). `rotation` may be anywhere from -180 to 180 (NUMBERS, :246). A rotated badge or text element can clash without being caught, or be refused when its drawn shape does not actually clash.
- AI designs rarely rotate text, so the real-world impact is likely small (not verified).

#### NOTE 7: Possible false refusals (boxes are compared, not the drawn content)
- Text boxes are often much wider than their text. For example, a left-aligned title box across the full width with a right-aligned price in the same row: the boxes overlap even though the words do not. These are refused.
- Facts that never show together (only one of them has a value on any real listing) cannot share a spot either: `rent_per_month`/`available_from` (rentals only) vs `price_per_m2` (sales only), and `auction_*` vs `show_day`. A designer might put such pairs in the same place on purpose. These are refused too.
- Both are the safe direction. Rule 1c and the retry should steer the AI away from them.
- Checked and fine:
  - agent_avatar **next to** agent_name passes, as long as the boxes do not overlap;
  - a logo on the watermark, a badge on a photo, a badge or status_badge on a color_block, and text on a shape or gradient all pass (those are exempt);
  - feature_pills, qr, logo, agency_logo, agent_avatar, badge and status_badge are correctly counted as clashing with text, since each draws its own words or picture;
  - zero or negative sizes cannot reach the check: `buildElement` rejects w/h < 4 (:1172-1174), and repairRaw clamps w/h to at least 4 (:563-564).

#### NOTE 8: Prompt rule 1c and decision 20
- The `$on` filter drops unticked extras from the exempt list (:1001-1002). Property mode also drops `custom_image`; company mode drops the listing photos (they are also in company `$forbidden`).
- No existing test compares the prompt text word for word or by line number:
  - I looked at AdBuildingBlocksKernelTest.php:146, AiTemplateBlocksAndStylesTest.php:79/:669 and AiTemplateExtrasOnlyWhenTickedTest.php:226-238;
  - their checks (`'- ribbon:'`, `'ribbon: "text" is required'`, `'or a collage'`, `'qr: w and h at least'`) do not appear in the new rule text, so the new rule should not break them. I reasoned this from the code and did not run them.
- Rule 1c's wording does not depend on the agency. The overlap test's decision-20 check only searches the next 400 characters after "1c."; the exempt list sits near the start of the rule, so that is enough.

#### NOTE 9: Test quality, and whether the edits to existing tests were fair
- The new tests in AiTemplateOverlapGuardTest prove the behaviour:
  - the live clash is refused, both for `validate` and `validateAndRepair`;
  - side by side and one under the other pass;
  - the edge is exact: 6px shared passes, 7px is refused;
  - exempt types do not count, and the avatar is counted;
  - prompt rule 1c, decision 20 and company mode are covered;
  - end to end, the retry carries "Option 2:" plus the element names.
- The two new MultiType tests prove a property-type version is retried and then kept, or dropped in favour of the main design.
- Missing tests:
  - a repair (moving an element back inside, or adding agency_name) that causes the overlap;
  - a company-mode overlap refusal end to end, through CompanyAdBuilderService;
  - rotation;
  - the error cap / crowd-out from finding 2.
- The edits to existing test data are justified. I worked through the geometry for each:
  - **response-with-repairable-faults.json, Hero Pill:**
    - Old data: price x40 w1200 was repaired to x0 w1200, y500-580. agent_name y590 h60 was moved to y568-628. That gives a real 12px vertical overlap at x760-1160.
    - Changing price to w700 removes the clash. It also removes the "too-wide price" fault from this fixture. The "outside the canvas" check (RepairTest:82) is still met by agent_name, and "wider than the canvas" is still covered by the data provider (RepairTest:189). So coverage is kept, not weakened.
  - **AiTemplateRepairTest::validOption():** agent_name moved from y540 to y440. With the old y, the cases 'just outside' (price moved to x700-1200, y500-580) and 'wider than the canvas' (price at x0-1200) overlapped agent_name at x760-1160, y540-580. That is a real clash. At y440, agent_name (x760-1160) sits next to the title (x40-740), so the change is legitimate.
  - **AiTemplateBlocksAndStylesTest:461:** instagram changed to facebook. This avoids squeezing a recording drawn for 1200x628 into a square, where its rows land on each other. The test is about style, so it is fair. But it no longer exercises the square size, and it confirms that a wrong-size answer squeezed into a square is now refused where before it was repaired (see finding 3).

#### NOTE 10: Multi-agency rule and CLAUDE.md
- The shipped code has no hardcoded agency IDs and no agency-specific wording.
- The spec text names "agency 20" and the live template name. That is only a record of the incident, not shipped behaviour.
- No new settings, so the Setup Wizard rule 10a does not apply. No migration and no new route.
- The spec was updated in the same commit, and CHAT_STARTER was updated.
- The commit message and spec say "217 green". I cannot check this, since no tests were run here.

#### NOTE 11: How accurate spec §B.11 is
- Accurate:
  - tolerance (`>` 6 in both directions), the exempt list, the counted types (agent photo, logos, QR);
  - "never repaired", the fallback for property-type versions, rule 1c's content, and the company-mode wording that names its own picture;
  - the explanation of each test-data edit.
- Minor overstatements:
  - **"The existing targeted retry (Defaults...) is told exactly which two elements to move."** The defaults retry asks for *new* replacement options (`$missing`), not a fix of the clashing design. The error list it sees is capped at 12 (finding 2), and element numbers can be shifted (finding 1).
  - **"Unticked extras are not named (decision 20)":** true for property mode. Company mode with no picture still names custom_image (finding 4).

---

## Review 2 — Band pricing

## Audit — band pricing commits 578d7e6dd + 5f606bb45 (QA2)

Worktree: /mnt/HC_Volume_103099143/corex-worktrees/ai-ad-overlap-guard-2026-10-20 (QA2 tip). Report only: no code changed, no tests run, no DB touched.
Line numbers refer to the files at the QA2 tip.

### Summary by severity

| # | Sev | Finding |
|---|-----|---------|
| H1 | HIGH (conditional, operational) | The agreement-version migration throws if a wording draft is open OR if the current live wording is not exactly the 1.0 text. Either makes `php artisan migrate --force` fail at deploy step 6, and `scripts/deploy.sh` then **holds maintenance ON** on purpose. Result: live outage until someone steps in. |
| H2 | HIGH (conditional, money/contract) | Which price list an agency is on depends on **when its agency row was created**, not on which agreement it signed. An existing agency that is sent an agreement from now on signs **band** wording and prices, but is billed on the **tier** list. There is no screen to change `price_list`. |
| M1 | MEDIUM | The agreement prices "number of agents" (the signer types it in). The bill counts **every active, non-assistant login**: principals, admins, secretaries. Under bands one extra admin can move the agency up a band (+R1 000 at 5→6). The contract figure and the bill can differ. |
| M2 | MEDIUM | A band-list agency whose **first** reconcile already sees 31+ users is settled to `quote` **silently**: no email. Until a custom amount is set it pays only for branches. Only the owner-page banner flags it. |
| M3 | MEDIUM | Two separate sources of band prices: `config/corex-billing.php` for billing, and the version's `rates_json` (editable on the Rates tab) for the agreement. Nothing keeps them in step. The quoted fee recorded on a 31+ agreement is never carried into billing either: someone must re-enter it by hand as a custom amount. |
| L1 | LOW | `AgencySubscription::usesBands()` treats a NULL `price_list` as the current list (bands). That is safe after the migration, but the fallback points the wrong way for existing data (during the pull→migrate window, or for any raw insert). |
| L2 | LOW | Billing with 0 seats on the band list: plan is `band1` and the label reads "1 agent" with no users. The agreement treats 0 agents as unpriced. Money is the same (R0 for seats); only the label differs. |
| L3 | LOW | Plan-change email for band8→quote shows "new monthly" as the branches-only amount (for example R750), next to "fee on quotation". This can read like a price drop. |
| L4 | LOW | The `@error('quoted_fee')` slot on the send form never fires for a bad amount. The DomainException is returned under the `send` key and shows at the top of the form instead. Cosmetic only. |
| L5 | LOW | `createDraft($fromId)` can still copy an **older tier version** while bands are current. Publishing that draft brings tier pricing back as current. The open-draft guard in the migration does not cover this later path. This is a deliberate owner action, but nothing warns about it. |
| N1 | NOTE | Pricing math: server and page JS agree at every boundary checked (details below). |
| N2 | NOTE | 31+ quoted fee: enforced on the server and cannot be tampered with by the signer. |
| N3 | NOTE | Test changes in 5f606bb45 are legitimate. The raw DB deletes are test-only, and the swapped assertion does not hide a runtime path. |
| N4 | NOTE | Multi-agency: no hardcoded agency IDs or HFC wording. The agreement is the platform (RR Technologies/CoreX) contract, so its fixed text is not per-agency. |

---

### 1. Pricing math (server vs page JS)

Files: `app/Services/PlatformEsign/Agreement/AgreementPricing.php` (`bands()` 62-74, `computeBands()` 134-161, `bandPlanFor()` 167-180, `derive()` 210-226), `resources/views/platform-esign/agreement/_js.blade.php` `calcBands()` (~83-116), `app/Services/Billing/SubscriptionPricingService.php` (`derivePlan()` 154-170, `bandPlanLines()` 270-287, `lines()` 230-262), `config/corex-billing.php` `bands`.

Agreement band rates: `AgreementPricing::BAND_RATES` (29-39) = 450/1, 900/2, 1495/5, 2495/10, 3295/15, 3995/20, 4595/25, 5095/30, branch 750. These match the owner's list exactly. The billing config bands match too.

| Agents | Server (agreement) | JS calcBands | Billing (bands list) |
|---|---|---|---|
| 0 | plan '' , total 0, branches not billed | same (`agents >= 1` guard) | plan `band1`, no seat line, **extra branches ARE billed** (L2) |
| 1 | band1 R450 | same | band1 R450 |
| 2 | band2 R900 | same | band2 R900 |
| 3 / 5 | band3 R1 495 | same | same |
| 6 / 10 | band4 R2 495 | same | same |
| 11 / 15 | band5 R3 295 | same | same |
| 16 / 20 | band6 R3 995 | same | same |
| 21 / 25 | band7 R4 595 | same | same |
| 26 / 30 | band8 R5 095 | same | same |
| 31, 10 000 | no quoted fee: plan '', total 0, validation error. With quoted fee: `quoted` line ×1 | same (`!plan && over && quoted>0`) | `quote`: no seat line; branches billed; "on quotation" until custom amount |

- Branches: 0 or 1 → 0 extra, 2 → 1 extra, n → n-1, on both sides, and only when a fee line is priced (server 152-153, JS `extra = plan ? max(branches-1,0) : 0`). Billing: `max(0, branches - included)`, with `included` = 1 from config. All agree.
- Discount: JS percent `round(sub*v/100)` matches `AgreementDiscount::amountFor` (40-48). The amount is capped at the subtotal on both sides.
- Type safety: JS does `from = to + 1` on `rates['bandN_max']`. That would join strings instead of adding if `rates_json` ever held strings. It does not: `createBandVersion` stores `BAND_RATES` ints, and `cleanRates` casts `int` fields (`AgreementVersions::cleanRates`). Safe today.
- Billable seats: `billableSeats()` is **unchanged** by the diff (active, not archived, not assistant). The migration's seat count for the initial plan uses the same predicate (migration 100700 lines 34-41).
- VAT: no VAT in billing (config header; spec D4 unchanged). The agreement's VAT wording is untouched (only the Plan row, what-it-costs, the Monthly-fee paragraph, the fee rows and B10.1 are replaced; `AgreementContent::bandPricingContent`).

### 2. Billing

- Migration `database/migrations/2026_11_16_100700_add_price_list_to_agency_subscriptions_table.php`:
  - Adds a nullable `price_list` column. Sets **every existing row with NULL** to `tiers_2026_07` (line 29). Inserts a row on `tiers_2026_07` for every agency with **no** subscription row, with plan team/agency from its live seat count (42-56). So no existing agency is re-priced, whether or not it has a row. Soft-deleted agencies also get a row; harmless.
  - Re-run safe: `hasColumn` guard, `whereNull` update, and `whereNotIn` insert.
- New agency after deploy: `AgencySubscription::forAgency()` firstOrCreate stamps `price_list = config('corex-billing.price_list')` = `bands_2026_10`, plan `team`. The first reconcile moves it to `bandN` silently (`SubscriptionReconciler` 63-64, 80).
- Reconciler at band moves: silent (spec D3 amended). Tier→tier behaviour is unchanged (email as before). band→`quote` sends an email with a "needs quote" paragraph (`AgencyPlanChangedMail` `needsQuote`, `plan-changed.blade.php`). **M2:** `stored='team'` (new row) → `quote` is also silent, because `isBandPlan('team')` is false. A new agency whose first reconcile happens with ≥31 users (for example after a bulk import, or settled by the nightly sweep) never triggers the email. The admin page banner `on_quotation` (admin/billing/index.blade.php ~72-80) is the only signal.
- `BillingReconcileCommand` dry-run now passes the subscription into `derivePlan` (lines 52-54), so it matches the reconciler.
- No caller of `derivePlan()` is left without the subscription (grep: only SubscriptionPricingService:40, SubscriptionReconciler:51, BillingReconcileCommand:53).
- Screens: admin KPIs "On price bands" (`usesBands`) and "On old prices" (`derivedPlan` team/agency); flag "On quotation — set a custom amount"; a pricing reference card per list. Agency page: "Your price band", "On quotation" tile and notice. For tier agencies the old text and numbers are untouched. Correct for both lists. Note: "Monthly total: On quotation" hides that branches ARE still billed (payable includes them); the receipt below still shows the branches line.
- **H2:** the agreement version used to send is always the current one (band after deploy). Billing `price_list` follows the agency row's creation time. So:
  - an existing tier agency that is (re)sent an agreement signs band prices but is billed on tiers;
  - an agency whose row was created before deploy but which has not yet signed (for example a new customer set up in advance) is pinned to tiers while signing bands.

  `AgencyBillingController` has no way to change `price_list` (grep: only custom_amount handling, lines 94-111). The only remedy is a DB edit. Spec D7 records the creation-date rule, so this is per spec. But the contract/bill mismatch is not addressed anywhere. **Business question for the conductor:** should signing a band agreement move the agency onto the band list, or should the owner be able to pick the agreement version/price list per agency?
- **M1:** spec D1 "a login is a seat" is unchanged. The agreement asks for "number of agents" (control label in `AgreementRenderer::bandControl`). B10.1 says "the price band the Agency's seats fall in". Under the tiers, one admin cost R450 more. Under bands, crossing 2→3, 5→6, 10→11 and so on costs R595–R1 000 more, and 30→31 moves the agency to quotation.

### 3. Agreement wording version migration

`database/migrations/2026_11_16_100800_publish_band_pricing_agreement_version.php` → `AgreementContent::publishBandPricing()` (~118-130) → `createBandVersion()` / `bandPricingContent()`.

- Idempotent: returns early when the current version is already band-shaped. Skips when the template has never been seeded; the first `ensureSeeded()` then seeds 1.0 plus the band version (`seedBandPricing`, guarded by "only one published version exists").
- Version number: `AgreementVersions::nextVersion()` gives top `x.y` → `x.(y+1)` (1.0 → 1.1). Parent = current. Audit row written. Layout calibrated lazily.
- Already sent/signed agreements: unaffected. Documents pin `wording_version_id`; pricing is chosen by that version's `rates_json` shape (`AgreementPricing::compute` → `isBands`); published versions are immutable (WordingVersion `updating`/`deleting` guards).
- **H1 — failure mode on deploy:** the migration throws in two cases:
  - **`DomainException`** when any wording draft is open;
  - **`RuntimeException`** from `once()` (`AgreementContent.php:335-343`) when the current live version's Part A/B text does not contain each old string exactly once. That happens if any wording edit since 1.0 touched the Plan row, the what-it-costs sentence, the Monthly-fee paragraph, the fee rows or B10.1.

  The docblock says this "stops the deploy, loudly", and it does not skip silently. But `scripts/deploy.sh` runs under `set -euo pipefail` (line 67). `php artisan migrate --force` is step 6 (line 630), and the policy for steps 4-9 is **maintenance HELD ON** (header lines 11-17, `on_error` 234-236). So on live this means a 503 for every user, plus the queue-worker restart churn noted in memory, until someone discards or publishes the draft and re-runs. Migration 100700 has already been applied and recorded at that point, so re-running only replays 100800.

  I could not check whether live currently has an open draft or edited wording (no DB access by brief). **Recommended pre-promotion check:** on the target DB, confirm there are no drafts and that `bandPricingContent(current.content_json)` succeeds. Alternatively, make the migration log and skip instead of throwing; that is the conductor's call.

### 4. The 31+ path

- Sender: `send.blade.php` 97-108 shows "Quoted monthly fee" only for band versions. Controller validation is `quoted_fee nullable|string|max:14` (AgreementController:69). `AgreementService::send` (~99-111) cleans it and refuses ≤0 or non-numeric (DomainException, shown under `send`). It is stored in `rr_data.quoted_fee` and audited `quoted_fee_set` (~143-145). The tier "Fix the plan" is ignored for band versions.
- Server enforcement: `AgreementFields::validateRecipient` (282-285) adds an error when the version is band-shaped, agents > top band, and the derived plan is ''. `submit()` (AgreementService ~603-609) runs this on `settle()`d data. The `plan` is set by `withDerived()` from the server calculation (346-352); `plan` is in `DERIVED_KEYS` and removed from incoming values via `lockedKeys()` (337-343). So the signer **cannot** post a plan to get past the check.
- Tamper: `quoted_fee` lives in `rr_data`, written only by the owner-gated `store()` (`$this->owner($request)`). Recipient input is filtered through `AgreementFields::clean($values, 'r')`. The page JS reads `C.quoted` for display only; the server recalculates `m_total_at_signing` itself. Not tamperable.
- UI: `bandControl()` (AgreementRenderer ~587-604) and JS `note:agents` show the "contact CoreX" note. Totals stay blank while there is no plan.
- As noted in M3, the quoted fee is not copied into `agency_subscriptions.custom_amount_zar`, so billing stays "on quotation" until the owner sets it again by hand.

### 5. Multi-agency, permissions

- No agency IDs or HFC-specific strings in the diff. Band prices live in config (billing) and `rates_json` (agreement, editable on the Rates tab), not code constants. `AgreementPricing::BAND_RATES` is only the seed default and the fill-in for missing keys.
- Fixed literals in the generated wording ("more than 30 agents", the `BAND_PRICING_NOTE` "R750", "October 2026") are platform contract text. `AgreementConsistency::checkBands` flags a "more than N agents" that no longer matches the top band if the Rates tab changes.
- Plan-change recipients (`andre@`/`johan@corexos.co.za`) are already in config; they are platform owners, not agency-specific.
- New input `quoted_fee` sits behind the existing owner gate. No new routes or endpoints. `price_list` is deliberately kept out of the Setup Wizard (spec agency-billing §14, recorded).

### 6. Test fixes in 5f606bb45

- The raw deletes via `DB::table(...)->delete()` are in `tests/Feature/Platform/Agreement/Concerns/PinsTierPricing.php`, `AgreementFidelityTest::pinTierPricing`, and `AgreementBandPricingTest::onlyTierPricing` only. A grep of `app/` and `database/migrations/` finds no product code deleting `platform_esign_wording_versions` rows (the only write is a 2026-10-06 migration that sets `change_note`). The model guard (`WordingVersion::deleting`) stays in force for product code. **Test-only. OK.**
- Assertion swap ("band version passes `problems()`" → "a draft made from it passes `problems()`"):
  - `problems()` is called at runtime only for **drafts**: `AgreementWordingController::show` line 201 (`$v->is_published ? [] : problems($v)`) and `AgreementVersions::publish` line 346.
  - `AgreementTokens::compare` is called only from `problems()` and `saveSection()` (line 172, draft only).
  - Version compare (`AgreementVersions::compare`) is a separate diff that does not call `AgreementTokens::compare`; it was updated to list the rate fields of both shapes.
  - So the published band version is never run through the token-count rule at runtime. On its own it would fail that rule, because it removes the `o:plan:*` options and changes the `q:`/`amt:` counts against its 1.0 parent.
  - `createBandVersion` checks its result with `AgreementTokens::validate` + `AgreementConsistency::check`, but not `compare`. That is consistent with the stated rationale (developer change).
  - A draft copied from the band version compares against the band version (`parent`), with band rates as the parent rates for the consistency check. That is the path an owner actually uses. **The new assertion tests the real path. It hides no runtime bug.**
- Unverified by me: the reported test results (48/48, 379/381). I did not run tests, per the brief.

### 7. Spec vs code

- agency-billing D7 and esign §11.5a describe the implementation correctly: migration names, `price_list` values, silent band moves, email only above the last band, "a new row settles its first band silently", quoted fee, `validateRecipient`, Rates tab, consistency rules.
- The spec states the "new row settles silently" rule but not its consequence for a new 31+ agency (M2).
- Everything the commit message promises is implemented: price shapes, `calcBands`, wording version via migration and fresh seed, the draft guard, the quoted fee, Rates tab / consistency / compare, `price_list` on existing rows, on-quotation handling, silent band moves. The message honestly says the schema snapshot was not re-dumped; the new migrations replay on top. That is consistent with CLAUDE.md 12a but leaves the snapshot further behind.
- The esign spec says the band version "Both validate the result with `AgreementTokens::validate` + `AgreementConsistency::check`". That is accurate. It does not mention that the token-count compare is deliberately skipped; 5f606bb45 added that explanation to §11.5a.
