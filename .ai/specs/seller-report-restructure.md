# Seller Report Restructure — Executive Summary + Five Proof Beats

**Spec target:** `.ai/specs/seller-report-restructure.md`
**Status:** DRAFT for Johan sign-off (B1). Build = B2.
**Depends on:** B0a (holding total single-sourced) + B0b (competition denominator single-sourced) — both committed.

---

## 1. The model

Two layers, one document.

**Layer 1 — the Executive Summary is the primary document.** One page, five plain-language bullets, kitchen-table voice, seventh-grader readable. Most sellers read only this page. Every figure in it is injected live from canonical `$data` and points to the proof page where it's derived.

**Layer 2 — five proof beats**, in fixed order, each one bold interpretive line ("the market speaks") plus its evidence:

1. **Your Property** — what we're valuing
2. **What's Happened Around You** — sold comps (+ suburb context strip)
3. **What's On The Market Now** — live competition + how long listed
4. **Where You Should Be** — recommendation (CMA band, price position)
5. **What Waiting Costs** — holding cost as the close

Non-negotiable voice rules (from the design session):
- The market delivers the message, never the agent judging.
- No jargon on seller-facing surfaces: no *percentile, absorption, median, mean, R/m²*.
- "Informed, never inferior."
- Holding cost is the finale, not a mid-document aside.

---

## 2. The AI-prose contract (kills the frozen-vs-recomputed class)

**The AI executive summary text carries ZERO hard rand figures, counts, ranks, or dates.** It supplies qualitative tone/framing only (one or two warm sentences). Every number a seller sees in the summary comes from the **token-templated bullets**, rendered live from `$data` at build time.

Consequence: the frozen `versions.ai_summary_text` can no longer contradict the recomputed beats, because it contains no numbers to drift. No regenerate-gate, no staleness annotation needed. This is the permanent fix for contradiction-class (a)/(d) at the prose layer.

`AiSummaryService` prompt is updated so generated prose is figure-free (instruct: describe the situation in plain language, do not state any prices, counts, percentages, or dates — those are inserted separately).

---

## 3. The five bullets — locked copy + token bindings

Tokens in `{ }` resolve from canonical `$data` at render. Copy is deterministic; only the tokens vary. `→ p.{ref}` is the cross-reference (mechanism in §5).

### Bullet 1 — This is your home → p.{ref.your_property}
> Unit {n}, {complex} — a **{beds}-bed, {baths}-bath, {size} m²** {type} with {garaging} in {suburb}.

| token | source key |
|---|---|
| beds / baths | `subject_property.bedrooms` / `.bathrooms` |
| size | `subject_property.extent_m2` |
| type | `subject_property.property_type` |
| suburb | `subject_property.suburb` |
| address parts | `subject_property.address` |

### Bullet 2 — Here's what actually sold near you → p.{ref.sold}
> Homes like yours sold for between **{sold_low}** and **{sold_high}**. The closest match of all — {match_descriptor} — sold for **{match_price}**.

| token | source key | note |
|---|---|---|
| sold_low / sold_high | size-matched cleaned pool range (see §4a) | NOT the raw vicinity min/max — outliers excluded |
| match_descriptor | closest comp by \|Δsize\| then recency | e.g. "a 75 m² unit, the same size as yours" |
| match_price | that comp's `sale_price` | |

### Bullet 3 — Here's what you'd compete against now → p.{ref.competition}
> There are **{competing_count} similar homes** for sale near you right now, from **{comp_low} to {comp_high}** — and one nearby has sat unsold for **{longest_dom} days**.

| token | source key |
|---|---|
| competing_count | `competitor_stock.competing_count` (B0b canonical) |
| comp_low / comp_high | min/max price of `competitor_stock.visible` |
| longest_dom | max `days_on_market` in `competitor_stock.visible` (the cautionary listing) |

### Bullet 4 — Here's where to price it → p.{ref.recommendation}
> To **sell** — not just to list — your home fits best at **{recommended_price}**. At today's **{asking_price}** you're priced above {above_clause}.

| token | source key | note |
|---|---|---|
| recommended_price | see §4b — bind to one canonical recommendation value (DECISION) | |
| asking_price | `subject_property.asking_price` | |
| above_clause | conditional copy (see §4c) | only asserts "above" when true |

### Bullet 5 — And here's what waiting costs → p.{ref.waiting}
> Every month unsold costs about **{holding_monthly}**. Pricing it right today usually means **the same money — or more — in your pocket, sooner.**

| token | source key |
|---|---|
| holding_monthly | `holding_cost.monthly_total` (B0a canonical) |

---

## 4. Derivations that need a decision

**(4a) "What sold" band — LOCKED: min/max of the cleaned, size-matched pool.**
Bind `sold_low`/`sold_high` to the min/max of `cma_computed.pool_stats` *after* the recency + outlier + size cuts the CMA already applies. Not p25/p75 — sellers read "between X and Y" as the actual spread of real sales. The 111 m² R960k and 168 m² R1.88m Seeskulp outliers fall out via the existing cuts. `match_descriptor`/`match_price` = the surviving comp with smallest \|Δsize\| then most recent.

**(4b) Recommended price — LOCKED: condition-adjusted CMA middle.**
Bind `recommended_price` to `cma_valuation.cma_middle` (condition-adjusted, monotonic post-fix), single value. If the B0c report shows `RecommendationService` is already wired into this presentation's data, the build may swap the binding to its output **without changing the bullet copy or any other token** — still one canonical value, never a band in the bullet. The band lives only on the Beat-4 proof page.

**(4c) "Above" clause must be conditional — never assert a falsehood.**
`above_clause` renders only the true comparison:
- asking > every visible competitor price → "above all {competing_count} homes you're competing with"
- asking > `sold_high` → "above everything similar that's sold"
- both true → combine
- asking ≤ band → **different copy entirely** ("priced right in the band buyers are paying" — no waiting-cost pressure in Bullet 5 either; the close softens). The summary must tell the truth for a well-priced seller too.

This conditional logic is the one place the bullets carry real branching. Spec it explicitly so an under-priced or well-priced subject doesn't get a "you're too high" pitch.

---

## 5. Cross-reference mechanism (`→ p.N`)

Chromium's PDF engine does dynamic page-number counters poorly. Use a **pre-computed `$sectionIndex` map** instead of live page counting.

- The five beats are fixed-order and (in the seller report) always rendered, so their page positions are deterministic given the cover + summary offset.
- Before render, build `$sectionIndex = ['your_property' => N, 'sold' => N, 'competition' => N, 'recommendation' => N, 'waiting' => N]` from the known beat order.
- Bullets print `→ p.{$sectionIndex['sold']}` etc.
- Optional appendix sections (inflow, pricing scenarios, articles) render **after** the five beats so they never shift the core beat numbers.
- Each beat page carries a matching anchor + visible section number so a seller can match bullet → page by number alone.

**Edge case:** if a beat is genuinely empty (e.g. no comps at all → Beat 2 has no data), the bullet for it is suppressed *and* the `$sectionIndex` recomputes so downstream references stay correct. Define beat-suppression rules in B2.

---

## 6. Where existing sections fold (informs B2/B3, not built in B1)

| Current section | New home |
|---|---|
| Subject card | Beat 1 |
| §3 Recent Sales + sale-price trend | Beat 2 |
| §2 Market Overview | Beat 2 context strip (re-framed, jargon stripped; fix the "0 sales" window — separate item) |
| §5 Active Competition + Scored Stock + price brackets | Beat 3 |
| Spatial view (static map) | **Beat 2** — shows where the sold homes sit around the subject; needs the Static Maps key live |
| §1 CMA tiles + price-position + recommended band | Beat 4 |
| §N+1 Pricing Strategy | Beat 4 |
| §N Holding Cost | Beat 5 |
| §6 Inflow/Absorption, §N+2 Pricing Scenarios, Articles | optional appendix (B3 decides keep/drop) |

The §1 Exec Summary block stops owning the CMA tiles — it becomes pure prose + 5 bullets. Those tiles move to Beat 4. This is the structural edit in B2.

---

## 7. Degraded-state matrix (must all render cleanly — B4 proof)

| Condition | Behaviour |
|---|---|
| 0% vs non-zero condition | recommended_price + band scale together (band fix already landed) |
| No comps | Beat 2 suppressed, Bullet 2 suppressed, `$sectionIndex` recomputes |
| Empty `competitor_stock.visible` | Bullet 3 + Beat 3 verdict suppressed (B0b guard: `has_data=false`) |
| No AI summary text (legacy version) | bullets still render (they don't depend on AI prose); tone sentence omitted |
| No Static Maps key | map placeholder with honest caption (existing radial fallback or "map renders once key live") |
| Sectional vs freehold | holding_monthly uses correct component set (B0a canonical) |
| Asking ≤ band (well-priced) | conditional copy per §4c; no false "too high" pitch |

---

## 8. Locked decisions

1. **"What sold" band** = min/max of cleaned, size-matched pool (§4a).
2. **`recommended_price`** = condition-adjusted CMA middle, single value; B0c may swap source to RecommendationService without changing copy (§4b).
3. **Spatial map** lives in Beat 2.
4. **Bullet copy** in §3 is frozen.
5. **Appendix** (inflow/absorption + pricing scenarios) kept after the five beats, agent-toggleable; B3 may trim.
6. **Conditional "above" clause** (§4c) is mandatory — the summary tells the truth for well-priced and under-priced subjects, never a false "too high" pitch.

This spec is frozen. B2 builds straight off it.
