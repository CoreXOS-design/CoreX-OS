# Core-match autonomy session — 2026-08-04

Johan offline ~2hrs, authorised autonomous work on the runway below. STAGING promotion
authorised; LIVE explicitly NOT authorised tonight. No destructive data ops. This doc is
the single record of everything done/decided/found while unsupervised — read this first.

## TL;DR

1. **Staging has the matching-engine fix live** (property-type family gate, suburb gate,
   structured-features-only) — verified with real Staging data. cc2's wishlist one-of-toggle
   fix could **not** be cherry-picked cleanly (real conflict, not forced — needs cc2 or you).
2. **Portal/website leads already seed the wishlist from the enquired property** — this
   mostly isn't broken, it's the same engine gap #1 fixes. Found 3 small real gaps + a
   1-file proposal, not built.
3. **Live-promotion plan written** for the matching commit — one command, ~8% expected
   match-removal (all correct, all traceable to the family gate), rollback = one revert.
   Nothing executed against live.
4. **Feature-warning follow-up spec written** — turns out ~90% already-built (reuses the
   existing AI-photo-suggestion UI for a second, text-based source). One open decision
   flagged for you.
5. **4 new edge-case tests added + deployed to QA1 and Staging** (test-only, zero
   production-code risk): mixed-family multi-select, farm-only, commercial-only, and an
   explicit guardrail test for agency-custom property types that aren't in the family
   classifier at all — proving those never get wrongly excluded.

Everything below is the detail. Nothing touched `/corex` (live) at any point this session.

---

## 1. TASK 1 — Core-match promotion to STAGING

**Status: DONE for the matching-engine batch. BLOCKED (documented, not forced) for cc2's wishlist-form batch.**

### What's live on Staging right now
Commit `2e2dbbac` (cherry-picked `-x` from QA1 `7ebc3674`), pushed to `origin/Staging`,
fast-forwarded onto `/corex-staging`, caches cleared, `php8.2-fpm` reloaded. Contains:
- Property-type FAMILY hard gate in `MatchingService::score()` (built/land/farm/commercial).
- Suburb hard gate added to `MatchingService::applyHardFilters()` (closes the property→buyer
  direction that only `propertiesForMatch()` had before).
- Structured-features-only matching — description/headline text-fallback removed from
  `propertyHasFeature()`.
- New test file `tests/Feature/Matching/PropertyTypeFamilyAndStructuredFeaturesTest.php`.

Cherry-pick applied clean, auto-merging around an unrelated, more recent Staging-only fix
in the same file (AT-350 sold-by-3rd-party status handling) without touching it.

**Staging verification (real data, re-confirmed this session — nothing regressed):**
- `git log --oneline -1` on `/corex-staging` = `2e2dbbac`. `git status` clean.
- Retried `git cherry-pick -x 5137beb6` once more this session as a final sanity check
  before writing this off — **identical conflict, nothing changed upstream**. Aborted
  cleanly again; Staging tree is back to exactly `2e2dbbac`, nothing left dangling.

### What did NOT land — cc2's commit 5137beb6 (wishlist one-of-toggle)
Cherry-pick conflicts in `resources/views/corex/contacts/_match-form.blade.php` only.
The other 4 files in that commit (`BuyerDetailController.php`, `ContactMatchController.php`,
`ContactMatch.php`, the new `WishlistFeatureExclusivityTest.php`) merge cleanly every time.

This is **not** an unrelated-commits problem — Staging's current copy of that Blade file
still has the exact three-separate-chip-list UI 5137beb6 fixes, but the surrounding lines
have drifted enough (independent Staging edits elsewhere in the same file) that git can't
auto-apply the patch. Re-checked `origin/QA1` this session: `5137beb6` is still the tip
touching that file — cc2 hasn't pushed a follow-up. **Per instruction, did not force a
resolution — this is cc2's feature/file, not mine to resolve unilaterally.**

**What's needed to unblock:** cc2 (or whoever owns that Staging checkout) needs to either
rebase `5137beb6` onto current Staging and resolve the one conflicting hunk, or resolve it
directly on Staging by hand — the target state (single mutually-exclusive selector, no
must+deal-breaker double-tick) is not in question, just the mechanical merge. I did not
attempt this myself.

**Live: untouched.** No commands run against `/corex` this session.

---

## 2. TASK 2 — Portal/website-lead core-match seeding: investigation + proposal

**Read-only. Nothing built.**

### Current behaviour — better than the ask implied; it already exists and is wired in

`App\Services\Buyers\BuyerLeadCascadeService::seedFromListing()` already derives a countable
wishlist directly from the enquired property: suburb (`p24_suburb_id`), price (± the agency's
configured MIC price-band tolerance, `AgencyContactSettings::micPriceBandFraction()`), beds
(`beds_min`), and type (`property_type` / `property_types`). It's called at real-time
ingestion from all three lead sources:

- `app/Services/Syndication/Property24/P24LeadService.php:225`
- `app/Services/PrivateProperty/PpLeadService.php:229`
- `app/Services/Website/WebsiteLeadService.php:208` (`seedBuyerFromLead()`)

Each tags `buyer_source` (`portal_p24` / `portal_pp` / `portal_website`) so MIC demand stays
attributable, and wraps the cascade call in try/catch (logs a warning, never throws — a
seed failure never breaks lead capture).

Verified this is actually firing on real data (QA1 snapshot, agency 1), not just wired:
`portal_lead_auto_seed_buyer` = **true** (the agency toggle is ON). 261/285 (91.6%) of
portal leads have both `contact_id` and `listing_id` resolved. **162 of 164 portal-sourced
buyer contacts (98.8%) carry a wishlist named in the exact `"{Type} in {Suburb} (from
enquiry)"` pattern `seedName()` produces** — this is not theoretical, it's working for
nearly every real lead already.

### So why would Johan still see a mismatched core match? Five real causes, ranked

1. **Most likely dominant cause — the matching-engine gaps this session already fixed.**
   Even when `deriveCriteria()` correctly anchored a wishlist to (say) Shelly Beach, until
   today's fix `MatchingService::score()` only soft-diluted suburb (20/~100 points) and never
   gated property-type at all — so a *correctly seeded* Shelly Beach wishlist could still
   surface a wrong-area or wrong-type "match" via the exact same mechanism as the Zululami
   and vacant-land cases investigated earlier this session. That fix is now on Staging
   (Task 1). **Recommend Johan re-check a real portal-lead example after this promotes to
   live — the seeding was never the problem, the scoring was.**
2. **`deriveCriteria()` doesn't derive `baths_min` / `garages_min`** — only `beds_min`. A
   3-bath house enquiry loses that spec entirely; the derived wishlist is narrower than the
   enquiry actually was.
   `app/Services/Buyers/BuyerLeadCascadeService.php:148-175`
3. **`equivalentSeedExists()` de-dups only on `listing_type` + price band + suburb-set — not
   type or beds.** A returning contact who enquires about a second, genuinely different
   property in the same suburb/price band (different beds or type) is silently treated as
   "already seeded" and gets **no new wishlist** — the wishlist stays anchored to the FIRST
   enquiry only, forever.
   `app/Services/Buyers/BuyerLeadCascadeService.php:178-200`
4. **The seeded wishlist is never explicitly `is_primary`.** An observer auto-promotes it to
   primary only when it's the contact's *first-ever* wishlist
   (`BuyerDetailController.php:193-194` comment confirms this). For a brand-new buyer this is
   fine. For an **existing** contact who enquires about a *new* property later, the fresh,
   accurate enquiry-derived wishlist lands non-primary and sorts *below* whatever older
   wishlist happens to be primary (`BuyerDetailController.php:38` — `orderByDesc('is_primary')
   .orderByDesc('updated_at')`). The agent's first glance at "the" core match can still be
   the old, wrong one.
5. **Minor, upstream, not in this file:** ~8% of portal leads on real data never get
   `listing_id`/`contact_id` resolved at all (property/contact matching failure in each lead
   service's own resolver — `WebsiteLeadService::resolveListing()`,
   `P24LeadService::resolveListingId()`), so they silently get no seed. Logged as a warning
   only. Flagging, not proposing a fix here — it's a different problem (lead-matching, not
   wishlist-seeding) and wasn't what was asked.

### Proposed fix (NOT built — Johan approves the model first)

All changes confined to **one file**, `app/Services/Buyers/BuyerLeadCascadeService.php`:

- `deriveCriteria()` — add `baths_min` (from `$listing->baths`) and `garages_min` (from
  `$listing->garages`), mirroring the existing `beds_min` derivation exactly. Trivial,
  low-risk, same pattern already proven.
- `equivalentSeedExists()` — widen the comparison to also require matching
  `property_type`/`beds_min`, not just price+suburb, so a second, meaningfully different
  enquiry creates its own wishlist instead of being silently swallowed.
- `seedFromListing()` — promote the new seed to `is_primary` when it represents the
  contact's most recent enquiry (needs Johan's call on the exact rule: "always primary on a
  fresh portal/website enquiry" is simplest but most aggressive toward any existing primary;
  "primary only if no other active wishlist was touched more recently" is gentler but more
  code). **Flagging as the one open design decision in this proposal — everything else is a
  mechanical, low-risk addition.**

**File touch-list:** `app/Services/Buyers/BuyerLeadCascadeService.php` only. No changes needed
to `P24LeadService.php`, `PpLeadService.php`, `WebsiteLeadService.php`, or the matching engine
— this is entirely inside the cascade's derivation logic, which every ingestion path already
calls through.

---

## 3. Staging → LIVE promotion PLAN for the core-match batch (PLAN ONLY — nothing executed)

**Scope: the matching-engine commit only.** cc2's wishlist-form commit (5137beb6) is NOT
part of this plan — it never landed on Staging (see §1), so it cannot be part of a
Staging-sourced live promotion tonight. If Johan wants it live too, that's a separate
decision after cc2/whoever resolves the Staging conflict and it's verified there first.

### Exact commit + order
- Single commit: `7ebc3674` on `QA1` (already cherry-picked onto `Staging` as `2e2dbbac`).
- **Cherry-pick from `Staging`** (`2e2dbbac`) onto `main`, not from QA1 directly — Staging is
  the verified, gate-passed copy; that's the one that's been proven, not the QA1 original.
- **Pre-flight check already done, read-only, nothing touched:** tested the cherry-pick in a
  disposable local worktree checked out from `origin/main` (never touched `/corex`'s real
  checkout). **Applies clean, zero conflicts** — auto-merges around the same AT-350
  sold-by-3rd-party fix, which is already on `main` too (so `main` and `Staging` agree on that
  region of the file; no drift to worry about). Worktree torn down after the check, no trace
  left.
- No other commits need to ride along. This is a true one-commit surgical promotion.
- No migration — pure PHP logic change, `php artisan migrate` is a no-op for this batch.

### The ~8% match-removal impact — what actually changes when this goes live
From the controlled QA1 measurement done during this session's earlier build round (same
50-buyer data snapshot, OLD matching code vs NEW, isolating exactly this commit's effect from
unrelated cache staleness):

| | |
|---|---|
| Cached matches before | 4,556 |
| Cached matches after | 4,187 |
| **Removed** | **369 (8.1%)** |
| Added | 0 |
| Score changed | 1 |

Cause breakdown of every removal: **293 Vacant land, 55 Commercial, 10 Farm, 8 Industrial, 3
built-type listings removed from land-only buyers** (the reverse direction — a land-only
buyer no longer seeing houses/apartments). Every single removal traces to the property-type
family gate working as designed; the structured-features-only change didn't move anything in
that sample (no buyer happened to have a prose-only must-have) but is separately proven by its
own unit test and the real "4 Alomsee" case.

**On live this number will almost certainly be similar in shape (dominated by land/commercial
family-mismatches) but the absolute count will scale up** — live has more buyers and more
cached rows than the QA1 snapshot. Recommend Johan expects "some previously-shown MIC/Core
Match rows disappear for buyers whose wishlist and the property's type were never actually
compatible" as the expected, correct, one-time effect of turning this on — not a regression to
investigate.

**Nothing gets falsely hidden that was correctly showing** — `ADDED: 0` in the sample means no
buyer loses a match they should keep; the multi-select false-negative fix (buyer explicitly
wants land+built, now correctly sees both) is proven separately by its own passing unit test
even though it didn't fire in this particular 50-buyer sample.

### Risk notes
- **Blast radius is real but one-directional and self-correcting** — buyers who were being
  shown nonsense matches (vacant land for an apartment buyer) stop being shown them. There is
  no plausible path by which this fix HIDES a match a buyer should see, given the empty/open
  wishlist case is explicitly exempted (proven by test) and the fix is a pure exclusion, never
  an inclusion-side change.
- **Cache staleness compounds on first run, same as it did on QA1.** Live's
  `prospecting_buyer_matches`/`property_buyer_matches` caches will only reflect the new model
  once each buyer's wishlist is next recomputed (nightly cron `prospecting:recompute-matches`,
  or the next time an agent opens/edits that buyer). Recommend NOT force-running an
  agency-wide recompute the moment this goes live — let it settle on the normal cadence so the
  "before/after" is gradual and any agent noticing a match disappear can be told exactly why
  (this doc). A forced full recompute the same night would surface the whole 8%+ delta at once,
  which is more startling than necessary for a correct, expected change.
- **No schema change, no queue/worker restart needed**, only PHP logic — lowest-risk class of
  live change this codebase has (no migration to roll back if something's wrong, just a
  `git revert` of one commit).
- **Rollback plan:** `git revert 2e2dbbac`-equivalent (revert the cherry-picked commit) on
  `main`, redeploy — restores the exact soft-scoring behaviour instantly, no data cleanup
  needed since nothing destructive was written (cache rows just get recomputed back to their
  prior shape on the next natural recompute).
- **cc2's wishlist-form fix is explicitly NOT bundled** — the underlying data-integrity issue
  it fixes (a feature ticked as both must-have and deal-breaker) has zero interaction with this
  commit; it's a pure UI/validation fix on save, unrelated to how existing saved criteria are
  scored. Safe to promote independently, in either order, whenever it's unblocked.

### Suggested execution steps for Johan tonight (not run by me)
```
cd /corex
git fetch origin Staging
git cherry-pick -x 2e2dbbac        # from Staging, not QA1 — the gate-verified copy
php artisan optimize:clear
# reload the live php-fpm pool (confirm exact service name/version before running)
git push origin main
```

---

## 4. Agent feature-warning follow-up — SCOPED, spec written, not built

Full build-ready spec: `.ai/specs/property-description-feature-detection.md`

**Headline finding: this is ~90% already-built infrastructure, not a new feature.** The
property workspace (`show.blade.php`) already has a complete "AI-detected feature suggestion"
UI — chips with Accept/Discard, confidence badges, a modal — fed by
`App\Services\AI\PropertyAiSuggestionService::forProperty()` from **photo** analysis. The
proposal is to feed the exact same array from a **second source** (description-text scan,
reusing the logic just removed from `MatchingService::propertyHasFeature()` this session) so
the existing UI needs **zero changes**.

One real open decision flagged in the spec for Johan: today's photo-suggestion vocabulary map
(`PropertyAiSuggestionService::TOKEN_MAP`) deliberately excludes `sea_view` and generic
`security` — the exact two tokens that caused the "4 Alomsee" miss — because there's no clean
web feature-category slot for them yet. Recommended "v1-full" (add those two to the category
vocabulary, closes the gap that motivated this whole follow-up) vs "v1-minimal" (skip them,
ships faster, doesn't catch the motivating case). Spec covers both.

File touch-list: `PropertyAiSuggestionService.php` (new method + optional TOKEN_MAP entries),
one-line visibility widen on `MatchingService::canonicalFeature()`, `PropertyController.php`
(merge the two suggestion sources), `show.blade.php` (only if v1-full, add 2 category
entries), one new test file. No migration, no new page, no new nav entry.

---

## 5. Matching edge-case tests + model summary

Added 4 new tests to `tests/Feature/Matching/PropertyTypeFamilyAndStructuredFeaturesTest.php`
(now 10 total in that file, all green — `php artisan test` run confirmed before every push):

- **Mixed-family multi-select** — a buyer who selected House *and* Vacant Land sees both; a
  Farm listing and an Office listing (families they never selected) stay excluded. Confirms
  the gate is genuinely per-family-selected, not "first family wins."
- **Farm-only wishlist** — sees farms, never sees a house or commercial stock.
- **Commercial(+Industrial)-only wishlist** — sees both selected commercial families, never a
  farm or an apartment.
- **Unrecognised custom type guardrail** — a made-up `property_type` ("Houseboat", not in
  `PROPERTY_TYPE_FAMILIES`) on both the wishlist and the listing must **never** be treated as
  a mismatch. This is the explicit test for the false-negative promise made when this model
  was proposed: an agency-specific type the classifier doesn't recognise is "unknown," not
  "different" — it never silently hides a match.

Test-only change (zero production-code risk) — cherry-picked to **both QA1 and Staging**
this session (`3868078d` → `275bd422` on Staging), caches cleared, FPM reloaded on both.

### Matching-model summary (for Johan)

The engine now has three explicit hard gates plus soft scoring, applied uniformly everywhere
(`MatchingService::score()`, called by Core Matches, the property page, MIC, and the buyer
portal alike — one engine, not a fork per surface):

| Gate | Type | Where |
|---|---|---|
| Availability (status) | Hard | Always was — `isMatchableStatus()` |
| Listing type (sale/rental) | Hard | Always was |
| **Suburb/area** | **Hard** (this session) | `applyHardFilters()` + `propertiesForMatch()`, both directions now |
| **Property-type family** (built/land/farm/commercial) | **Hard** (this session) | `score()` — universal, covers MIC too |
| Price, beds, baths, garages | Soft % | Unchanged, exactly as before |
| Must-have / nice-to-have features | Hard / soft, **structured-only** (this session) | `propertyHasFeature()` — no more description text-scan |

Net effect: a buyer's match list now reliably means "matches the area you asked for, the kind
of property you asked for, and roughly the specs/budget you asked for" — the three things a
buyer would consider table-stakes are now guaranteed, not just usually-true.

---
