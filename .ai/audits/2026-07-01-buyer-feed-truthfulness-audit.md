# Buyer-feed truthfulness audit — buyer-count / active-buyer claims in consent templates

**Jira:** AT-144 (Relates to AT-142) · **Date:** 2026-07-01 · **Type:** INVESTIGATION ONLY (no code written)
**Verified on:** `hfc_staging` (staging server), rolled-back Tinker transactions, real agency-1 data.

---

## TL;DR — verdict up front

| Surface | Template | State | Claim is data-backed? | Verdict |
|---|---|---|---|---|
| **2 (PRIORITY)** | id 3 "Buyer Demand Marketing" | **ACTIVE / LIVE** | **NO** — hardcoded prose, no token | **NOT SAFE — live CPA exposure** |
| **1** | id 9 "Buyer-Led — Active Buyer Match" | disabled | **NO** — hardcoded prose, no token | **NOT SAFE — keep disabled** |
| **3** | (infra `{buyer_count}` / `{matching_buyer_count}`) | exists, unwired | live-computed but **non-canonical & can diverge** | **Partially safe — needs canonical rewire before any template uses it** |

**Headline:** Neither of the two active-buyer templates uses a data token at all. The active-buyer claim is **hardcoded English prose** that renders **unconditionally** — it ships whether or not a single matching buyer exists. Proven on staging: property #6062 (Glen Hills) has **0 matching buyers** (composer AND canonical both say 0), yet template id 3 still renders *"I have a buyer active in Glen Hills … your property may suit them."*

**Surface 2 is LIVE right now and can assert a specific active buyer suited to a property when there are none. That is a Consumer Protection Act (s41) misrepresentation on every send where no buyer actually matches. Recommend Johan disable id 3 immediately pending the fix.**

---

## The two claims, verbatim (from the seeder)

`database/seeders/HfcConsentTemplatesSeeder.php`

- **id 3 "Buyer Demand Marketing"** (`is_active => true`), body line **176**:
  > "I have **a buyer active** in `{property_suburb}` and **your property may suit them**. With your permission, I'd like to send you the details…"
  - Reply-line 172 (`description`): "specific active buyer for the seller's property."
  - email subject line 173: "A buyer for your `{property_suburb}` property"
- **id 9 "Buyer-Led — Active Buyer Match (DISABLED)"** (`is_active => false`), body line **256**:
  > "…we're currently **working with buyers looking in** `{property_suburb}` — and **your home is the kind some of them have in mind**."

**Token scan of every template body** (`grep '{buyer_count}\|{matching_buyer_count}'` on the seeder): **NONE.** No template consumes either count token. The claims are literal strings; the only tokens in those two bodies are identity/address/opt-out (`{seller_name}`, `{property_suburb}`, `{agent_name}`, `{agency_name}`, `{opt_out_link}` …).

---

## Per-surface findings

### SURFACE 2 — id 3 "Buyer Demand Marketing" (LIVE, PRIORITY)

- **1. Source of truth:** *None.* The active-buyer assertion is a hardcoded sentence (seeder line 176). It is not bound to `{buyer_count}`, `{matching_buyer_count}`, the canonical `MatchingService`, or any query. `SellerOutreachComposerService::composeContext()` renders the body via `renderBody()` (`app/Services/SellerOutreach/SellerOutreachComposerService.php:332`) — string token substitution only; the buyer claim has no token to substitute, so it passes through untouched.
- **2. Correctness:** Not applicable / cannot be correct — there is no number to be correct. The sentence claims exactly one active buyer ("a buyer … may suit them") regardless of the real figure. **Worked example (staging, prop #6062 Glen Hills, House, 3-bed):** real per-property matching buyers = **0** (composer `matching_buyer_count`=0 **and** canonical `MatchingService::matchesForProperty`=0), yet the rendered id 3 body still contains "I have a buyer active in Glen Hills". Claim shipped: **false**.
- **3. cm.suburb:** N/A — id 3 touches no matching engine at all (see Surface 3 for the column's actual state).
- **4. Live vs stale:** The claim is neither live nor cached — it is **static copy**. Worst of the three (a stale cache is at least occasionally right; static copy is right only by coincidence).
- **5. Zero-state:** **Renders the claim anyway.** No `{?buyer_count}…{/}` conditional wrapper, no send-gate keyed on buyer demand. `composeContext()` has gates for `no_address` / `no_recipient_name` / `no_designation` (lines 111–151) but **nothing that checks buyer demand**. A send to a seller in a suburb with zero buyers still says "I have a buyer active."
- **6. Active vs historic:** N/A (no data path) — but the prose asserts a *currently active* buyer, so the honesty bar is the highest and it is unmet.
- **7. Countable definition:** N/A (no gate consulted).
- **VERDICT: NOT SAFE.** Live misrepresentation risk on every send where no buyer matches. **Recommend immediate disable of id 3** (set `is_active=false`, same posture as id 9) pending the fix.

### SURFACE 1 — id 9 "Buyer-Led — Active Buyer Match" (DISABLED)

- Identical mechanism to Surface 2 — hardcoded prose ("buyers looking in {suburb}", plural), no token, no source, no zero-state guard (seeder line 256).
- Already `is_active=false` and correctly flagged in-code ("DISABLED pending a separate buyer-feed audit"). **VERDICT: NOT SAFE to enable as written.** Keep disabled until rewired to a canonical, zero-state-gated count.

### SURFACE 3 — the `{buyer_count}` / `{matching_buyer_count}` infrastructure (exists, unwired)

The tokens the spec intends for honest buyer claims **do exist and are live-computed**, but they are **not on the canonical path** and **do not currently power any template**.

- **1. Source:** `SellerOutreachComposerService::buildMergeFields()`:
  - `buyer_count` — `app/Services/SellerOutreach/SellerOutreachComposerService.php:238` → `ProspectingIntelligenceService::buyersForSegment($ag,'town',…)`; fallback line **245–246** → `snapshot()->activeBuyers`.
  - `matching_buyer_count` — lines **264–276** intersect `buyersForSegment` across town∩property_type∩bedrooms∩price_band; assigned line **310**.
  - **This is a PARALLEL path, NOT the canonical engine.** The canonical buyer engine is `App\Services\Matching\MatchingService::matchesForProperty()` (`app/Services/Matching/MatchingService.php:106`) / `PropertyMatchScoringService::getBuyerDemandForProperty()` (`app/Services/PropertyMatchScoringService.php:199`). The composer never calls either.
- **2. Correctness — PROVEN DIVERGENCE:** on staging, property **#6018** (Umbango, Vacant Land): composer `matching_buyer_count` = **0**, canonical `MatchingService::matchesForProperty` = **1**. The token and the canonical engine **disagree on real data**. So even if a template used `{matching_buyer_count}`, it would not equal the Core Matches / buyer-demand surfaces (violates Buyer Pillar doctrine "one canonical engine, one truth" — cf. AT-73 / AT-108).
- **3. cm.suburb (AT-71 dropped column):** **FIXED and intact — but only on the canonical path, which the composer doesn't use.** `PropertyMatchScoringService.php:267` documents "AT-71 fix: the legacy `cm.suburb` column was DROPPED"; the query now uses `JSON_SEARCH(cm.suburbs, 'one', ?)` on the JSON `cm.suburbs` column (line **284**; also `BuyerMatchTierService.php:129`). No live reference to the singular dropped `cm.suburb` remains → no silent empty/error from that path. Composer's `ProspectingIntelligenceService` path does its town bucketing in PHP from each match's suburbs (`buyersByTown` line 200+, `matchSuburbs`), so the dropped column never touched it either.
- **4. Live vs cached:** `buyer_count`/`matching_buyer_count` are computed **live per render** (fresh `Contact` queries via `loadActiveBuyers`), no cache, no staleness. Recorded into `facts_snapshot` (`composeContext` line ~163) for compliance — **but note the snapshot records numbers the body never actually claims**, and the body's prose claim is not represented in the snapshot, so the audit trail and the message are disconnected.
- **5. Zero-state:** The token infra itself is honest at zero — `matching_buyer_count` returns `''` in address-only mode so the `{?matching_buyer_count}…{/}` optional segment collapses (lines 205, 308–310). `buyer_count` renders `'0'` as a literal. So a *token-driven* template could be made zero-safe; the *current prose* templates are not.
- **6. Active vs historic — HONEST:** `ProspectingIntelligenceService::loadActiveBuyers` (`:155`) filters `is_buyer=1` + `whereHas('matches', status='active' & not deleted)` (line 170) + `buyer_state IN ['new','warm']` (const `ACTIVE_BUYER_STATES` line **43**, applied line 179). Cold/lost are excluded (matches spec P1, `prospecting-intelligence-spec.md:62-71`). The "active" label is honest on this path. Historic buyers live in a separate `buyer_property_views` path (`PropertyMatchScoringService.php:230-242`) and are not folded in.
- **7. Countable — DIVERGES FROM CANONICAL:** `loadActiveBuyers` requires an *active* match but **does NOT apply the AT-71 countable gate** (`->countable()`), which the canonical engine DOES (`MatchingService.php:95,115`; `ContactMatch::scopeCountable():398`, `isCountable():377`, default bar `min_countable_criteria = ['any']` = ≥1 non-empty criteria, `AgencyContactSettings.php:72`). So the composer can count a buyer whose only active wishlist is empty/below-bar — a buyer the canonical surfaces exclude. **On staging today the overstatement is 0** (all 35 active buyers happen to have countable wishlists), so this is a **latent/structural** divergence, not currently firing — but it is not gated and will fire the moment an empty-criteria active wishlist exists.
- **VERDICT: PARTIALLY SAFE.** The infra is live, honest on active-vs-historic, and zero-safe by construction — but it is **non-canonical and provably divergent** from the buyer engine. Do not wire any template to it until it is repointed at `MatchingService` (or reconciled so it cannot disagree).

---

## Cross-cutting root cause

The seller-outreach **spec mandates** data-bound claims — `seller-outreach-spec.md:25` ("every claim … sourced live"), `:29` ("The honest pitch principle … every claim … holds up in a dispute"), `:137-138` (`{buyer_count}`/`{matching_buyer_count}` = live counts). The **shipped templates violate the spec**: they assert active buyers in prose with no token, so the honest-pitch machinery is bypassed entirely. This is the exact class of defect the Buyer Pillar doctrine (one canonical engine, honest active-vs-historic, AT-71 countable gate) was built to prevent — and a second, quieter defect is that the one place counts *are* computed (`buildMergeFields`) uses a parallel non-canonical engine.

---

## Recommended fixes (NO code written — for Johan's approval)

1. **IMMEDIATE (Surface 2, live):** Disable template id 3 (`is_active=false`) — or strip the active-buyer sentence to a demand-neutral consent request — until it is rewired. This stops a live CPA exposure today. *(One-line seeder/data change; Johan's call on disable-vs-reword.)*
2. **Rewire the buyer claim to a canonical, gated token.** Introduce a canonical count on the composer (repoint `buyer_count`/`matching_buyer_count` at `MatchingService`/`getBuyerDemandForProperty`, applying `->countable()`), and rewrite id 3 / id 9 to state the *real* number via a token, wrapped so it collapses at zero — e.g. `{?matching_buyer_count}I have {matching_buyer_count} buyer(s) active and looking for a property like yours in {property_suburb}.{/matching_buyer_count}` with a hard send-gate when the number is 0 (mirror the existing `no_address`/`no_designation` gate pattern in `composeContext`).
3. **Reconcile the two engines** (or document why a parallel path may exist and guarantee it cannot disagree) so the outreach number equals Core Matches — closes the same doctrine gap as AT-73 / AT-108. Prove with a matched worked example before any such template goes active.
4. **Only then** re-enable id 9 (Surface 1) as a genuine buyer-led template, and consider building the spec's intended Surface-3 message ("We have {buyer_count} buyers actively looking in {town}", spec `:199/:207`) off the same canonical token.

**No template making an active-buyer claim should be active until steps 2–3 land.**

---

## Evidence (staging, rolled-back tx, agency 1, agent #24)

- Agency-1 active buyers (snapshot, sale) = **18**; active buyers (any listing type) = **35**; all 35 countable (overstatement = 0 today).
- Prop #6062 (Glen Hills, House 3-bed): `buyer_count`=18 (town-level = whole pool), `matching_buyer_count`=**0**, canonical=**0**; id 3 body still asserts "I have a buyer active in Glen Hills". → the town-level 18 is not a per-property claim; the honest per-property number is 0; the prose claim is false.
- Prop #6018 (Umbango, Vacant Land): composer `matching_buyer_count`=**0** vs canonical `matchesForProperty`=**1** → composer/canonical divergence proven.
