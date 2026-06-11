# AT-22 — Presentation / CMA Report-Quality Overhaul

**Status:** BUILD AUTHORIZED — STEP 2 investigation reviewed and **approved verbally by Johan 2026-06-11**; build of items 1, 3, 4, 5, 6, 7 authorized and landed on branch `AT-22-presentation-quality`. (STEP 1 gate passed earlier; four thresholds + modal-anchor correction locked.) Item 6 (comp type misclassification) was built + tested in this batch ahead of its own ticket — **AT-26 to be marked "done early"** by Johan. Remaining to verify: agency-settings UI for the §0.1 thresholds (built this pass), then regenerate PRES 87 on Staging for eyeball sign-off.
**Date:** 2026-06-11
**Author:** Claude + Johan
**Jira:** [AT-22](https://corexos.atlassian.net/browse/AT-22) (Task, project AT) — status To Do
**Branch:** `AT-22-presentation-quality`
**Reference subject:** PRES 87 — 36 Grindewald, R2.9M asking, 1,375m² erf. All seven items reproduced here after AT-18 landed.
**Process:** branch → Staging → test → main → live (full flow, NOT hotfix — these are quality improvements). Investigate-first on items 1–6 (report root cause + proposed approach, Johan approves before any fix). Item 7 (images) may be root-caused and fixed in-pass.
**Relates to:** AT-18 (comp whitelist — verified working), AT-21 (comp-table CRUD), AT-19 (in-presentation upload), AT-20 (geocode precision).

---

## 0. Pillars & Configurability

- **Pillars touched:** Property (subject + comps), Contact (seller recipient of the report), Deal (mandate/listing context). Reads from Property + Tracked Properties (`tracked_properties`, `imported_listings`); writes enriched comp-selection + range data back into the presentation snapshot.
- **Configurability rule (CoreX):** every business threshold below is **agency-configurable** with the stated sensible default. Defaults live in a presentations settings config/table keyed by agency; the engine reads agency value → falls back to default. No hard-coded rand figures or radii in the selection/range path.
- **Non-negotiables honoured:** soft deletes only; permissions on any new settings UI; every threshold surfaced in an agency settings screen with a navigation entry the same day it ships.

### 0.1 Locked parameters (Johan, 11 Jun 2026 — AT-22 comment)

These are **decisions, not proposals**. Investigation may refine *how* they're implemented, not *what* they are.

| Parameter | Locked value (default) | Configurable | Notes |
|---|---|---|---|
| **Type match** | Hard gate (see §1) | — | NEVER cross-breed freehold ↔ sectional |
| **Price band** | subject **±25%** (range 25–30 allowed) | Yes (%) | Anchored on **CMA / market estimate**, NOT asking price |
| **Radius** | **300m** default, widen-if-thin to 3000m | Yes (m) | Matches CMA Info default; 1000m current is too wide |
| **Erf size** | within **±30%** as ranking factor | Yes (%) | Applied inside type + price + radius gates |
| **Recommended range** | comp distribution **P25–P75 around CMA mid** | Yes | Asking price NEVER widens the band |
| **Valuation anchor** | CMA mid of the **cleaned (post-gate) pool** | Yes | Robust, not naive — see §1.5; raw all-comps median is the R1.1M trap |

---

## 1. Comp selection too loose — relevance ranking

**Current behaviour.** For the R2.9M / 1,375m² subject the comp pool pulls sub-R1M and tiny-erf sales. **[STEP 2 correction]** The sold-comp pool is built by **`MicSnapshotHydrator::collectMatchedRows()`** (`app/Services/Presentations/MicSnapshotHydrator.php:521-637`) — NOT `CompetitorStockMatchService` (that builds the *Active Competition* card list, a separate surface) and NOT `CmaComputeService` (that only does math on the pool handed to it). A comp is admitted if it passes the title-type gate AND matches any of: same-subject report (`:613-615`), **suburb-only** match (`:622-625`, the leak — no price/erf gate), or Haversine radius (`:628-632`). Radius default is **1000m** (`agencies.presentations_default_radius_m`, migration `2026_05_23_140001`). The ONLY gate today is title-type; there is **no `property_type` like-for-like check, no price-band filter, and no erf-size filter**.

**Target behaviour.** A tight, short, profile-clustered comp list produced by a **gate-then-rank** pipeline:

**Stage A — hard gates (a comp is excluded outright if it fails any):**
1. **Type gate.** First pass: like-for-like `property_type` (house→house, apartment→apartment). If the resulting pool is too thin, fall back to **category** (freehold→freehold, sectional-scheme→sectional-scheme). **Never** cross freehold ↔ sectional under any circumstance, regardless of other proximity.
2. **Price-band gate.** Comp price within **subject ±25–30%** where "subject" = the **CMA / market estimate**, not the asking price. (PRES 87: R2.9M band example given by Johan ≈ R2.2M–R3.0M; R1.0M subject ≈ R0.8M–R1.2M.)
3. **Radius gate.** Within **300m** of the subject by default. **Widen-if-thin fallback:** if 300m yields fewer than the minimum-comp threshold, expand the radius progressively (e.g. 300 → 600 → 1000 → 1500m, steps configurable) until the minimum is met or a hard ceiling is reached. **Report the radius actually used** in the output/snapshot.

**Stage B — ranking (orders the gated pool, tightest first):**
- **Erf size** proximity — within **±30%** of subject erf as a ranking factor (closer = better).
- **Distance** — nearer = better.
- **Price proximity** to CMA mid — closer = better.
- Composite weighted score; shortlist the top N (N agency-configurable, sensible default).

**Exact thresholds (defaults):**

| Threshold | Default | Config key (proposed) |
|---|---|---|
| Price band | ±25% | `comp_price_band_pct` |
| Radius (initial) | 300 m | `comp_radius_m` |
| Radius widen steps | 300, 600, 1000, 1500, 3000 m | `comp_radius_widen_steps` |
| Radius hard ceiling | 3000 m (rural mandates must resolve) | `comp_radius_max_m` |
| Erf-size proximity | ±30% | `comp_erf_band_pct` |
| Min comps before widening | 10 | `comp_min_count` (round-1: raised 5→10 so the ladder auto-widens 300→600→1000m to catch on-profile comps just outside 300m — Johan, 11 Jun) |
| Max comps shortlisted | 15 (don't force-drop; PRES 87 curated 13) | `comp_max_count` |

> Open question for STEP 2: confirm whether band/price anchor field is the CMA mid produced by `CmaComputeService` and that erf/type/coords are reliably present on the comp source rows.

---

## 1.5 Valuation anchor robustness — the overpriced-subject edge case (CRITICAL)

**The trap.** The price band (item 1) and the recommended range (item 5) both anchor on the **CMA / market estimate**, not asking. But on PRES 87 the *raw* CMA came back **≈R1.1M** — absurdly low for a genuine ~R2.4M property — because the comp pool was polluted with sub-R1M tiny-erf sales. Anchoring a band on a naive median of a polluted pool would show the seller R1.1M on a R2.4M property and get HFC thrown out of the listing. **A naive anchor is as unshippable as a competitor logo.** The anchor must be *robust*, not naive.

**The principle.** A clean, type-and-size-matched, distance-bounded comp pool produces a *defensible* market anchor. The R1.1M was a **symptom of the loose pool** — so fixing item 1 largely fixes the anchor. The band is ±25–30% around **that** robust anchor, never around the raw all-comps median and never around asking.

**Ordered derivation (the anchor pipeline):**

1. **Gate first.** Apply the item-1 **type gate + erf-size gate + radius** to produce a *cleaned* comp pool. This happens **before** any CMA estimate is computed.
2. **Estimate from the cleaned pool.** Compute the CMA / market estimate from the **cleaned pool**, not the raw all-comps median. The cleaned pool is what yields a realistic anchor.
3. **Divergence guard (widen-if-thin, robustly).** If the cleaned-pool estimate diverges wildly from supporting signals — **vicinity average** and the **agent's own comp curation** — treat the pool as still-thin/low and **widen the radius** (the item-1 widen-if-thin fallback) to pull a more representative set, rather than anchoring on too few low sales. Report the radius actually used. Threshold for "diverges wildly" is agency-configurable: default **cleaned-pool estimate more than ±25% off the vicinity average** triggers a widen.
4. **Agent curation is final authority.** The agent's manual comp curation (`included_comp_ids_json`) **ALWAYS wins**. The engine's job is to produce a sane default pool so the agent rarely *has* to override — but when the agent has curated comps, the anchor and range derive from the agent's set, full stop. The robust default exists to make the override rare, not to override the agent.

**Exact thresholds (defaults):**

| Threshold | Default | Config key (proposed) |
|---|---|---|
| Estimate source | cleaned pool (post-gate) | (fixed — never raw all-comps) |
| Divergence-vs-vicinity trigger | ±25% off vicinity average | `anchor_divergence_pct` |
| On divergence | widen radius per item-1 steps | (reuses `comp_radius_widen_steps`) |
| Agent override | `included_comp_ids_json` wins absolutely | (fixed) |

**Generate-modal binding (the anchor must surface, not asking).** On the Generate Presentation modal:
- The **"Suggestion based on suburb data"** field must display the **system market anchor** — the clean-pool CMA estimate (§1.5 steps 1–3) — NOT the asking price. Current behaviour is wrong: it echoes the asking price back, which makes the "suggestion" meaningless and reinforces the asking-leak. Bind it to the robust anchor.
- The **"Asking price"** field stays exactly as it is: the **agent's input** — what the seller wants / the figure we test against the market. It is not derived from the anchor and does not feed the band (§5).
- **No slider / no manual value drag.** The agent's value-adjustment mechanism is **comp curation** — tick/untick comps (`included_comp_ids_json`) and the anchor recomputes from the new cleaned set. There is no manual value-drag control. This keeps every displayed value evidence-backed: the agent moves the anchor by changing the *comps*, never by dragging a number.

> This section governs the anchor used by **both** item 1 (price band) and item 5 (P25–P75 range), and the Generate-modal "Suggestion" field. STEP 2 confirms where the raw R1.1M is computed (`CmaComputeService`), where the Generate modal sources the "Suggestion" value (the asking-echo bug), where `included_comp_ids_json` is read + recompute is triggered, and how the vicinity average is sourced for the divergence guard.

---

## 2. Competitor agency branding on HFC report — CRITICAL

**Current behaviour.** Active-competition cards render a **third-party agency name and logo** (e.g. "RE/MAX Coast and Country" with the RE/MAX logo as the card image), sourced from portal-capture data. A competitor brand on a Home Finders seller report is unshippable.

**Target behaviour.** Portal-sourced competition cards in the **seller-facing output** carry **no third-party agency name, branding, or logo**. Specifically:
- Suppress/blank the source-agency name label on competition cards.
- Never use a portal/agency-branded logo as the card image. If the only available image is agency-branded (or is in fact a logo, not a property photo), fall back to the neutral "property image unavailable" placeholder rather than display the competitor mark.
- Internal/admin views may still retain provenance (we don't lose the data) — the strip applies to the **seller-facing presentation + PDF** surface only.

> STEP 2 will pinpoint where the card image + agency label are sourced (portal capture fields) and the exact render sites in `PresentationPdfService` / competition card partials. Interacts with item 7 (image path) — a logo masquerading as a photo is both a branding leak and a "wrong image" bug.

---

## 3. Spatial view unreadable

**Current behaviour.** "Spatial View — Subject + Comps + Competition" overprints text labels on top of each other — illegible at PDF scale. Rendered by `PresentationStaticMapService` / `SpatialViewSvgRenderer`.

**Target behaviour.** A decluttered, legible map:
- **Numbered pins + legend** instead of inline text labels. Subject visually distinct (e.g. distinct colour/marker); comps and competition numbered, keyed to a side/below legend listing address + key facts.
- No overlapping text directly on the map. Where pins collide, apply spacing/clustering or leader offsets.
- Must render readably at the actual PDF dimensions (test against PRES 87).

> STEP 2 proposes the concrete layout (pin + legend vs cluster) after reading the renderer. Pure presentation-layer change; no data-model impact expected.

---

## 4. Suburb Price Summary shows 0 / blank

**Current behaviour.** "Total Residential Sales = 0", all ranges "—", despite the agent having uploaded the suburb report. The uploaded suburb stats are not binding to the table. PDF reads `$data['suburb_overview']` (`PresentationPdfService.php:586`); compile sets `'suburb_overview' => $suburbOverview` (`AnalysisDataService.php:103`); `PricingSimulatorService.php:64` already defaults `sales_count` to 12 when missing — i.e. the field is arriving empty.

**Target behaviour.** Uploaded suburb-report stats bind to the Suburb Price Summary table: Total Residential Sales and all price ranges populate from the parsed upload. Fix the **parse → field mapping → `suburb_overview` compile** path so the data the agent uploaded actually lands in `suburb_overview`.

> STEP 2 traces upload → parse → mapping → `suburb_overview` to find where the values are dropped (parser key mismatch, mapping gap, or compile not reading the upload). Data exists (agent uploaded it), so this is a binding/mapping defect, not missing data.

---

## 5. Recommended range too wide + unsupported upper bound

**Current behaviour. [STEP 2 correction — the spec's original `PriceBandService:54-55` attribution was WRONG.]** `PriceBandService` is a separate, feature-flagged (`features.price_band_v1`) simulator API (`PresentationController::priceBand` :1345-1386) and does **NOT** feed the rendered Recommended Range. The rendered range is `PresentationPdfService.php:2881` = **`$cmaMiddle — $cmaUpper`**, i.e. **CMA-median → pool P75** (`cma_upper` = `pool_stats['p75']`, `AnalysisDataService.php:417`). So R2.388M–R3.0M is median→P75 of the **polluted pool**; the R3.0M upper is the pool's 75th percentile (coincidentally near asking), **not asking leaking in via a multiplier**. The band reads "too wide" because the *lower* bound is the median, not P25 — it is an asymmetric upper-half band. Additional finding: `pool_stats` P25/P75 are currently computed on the **pre-clean** prices (`CmaComputeService.php:114-115`).

**Target behaviour.** Range = **comp distribution P25–P75 around the robust CMA mid** (the cleaned-pool anchor from §1.5, NOT the raw all-comps median):
- **Lower bound = P25**, **upper bound = P75** of the gated/ranked comp set's price distribution (the same cleaned comp set produced by item 1 / §1.5), centred on the robust CMA mid.
- If the agent has curated comps (`included_comp_ids_json`), P25/P75 derive from the agent's set — agent curation is final authority (§1.5).
- The **subject's own asking price must NEVER widen the band.** Asking may be *shown* as a reference marker but is excluded from the bound computation.
- Both bounds must be **evidence-backed** — traceable to comps in the set. The displayed evidence list reflects the actual P25/P75 comps.

**Exact thresholds (defaults):**

| Threshold | Default | Config key (proposed) |
|---|---|---|
| Lower percentile | P25 | `range_lower_pct` |
| Upper percentile | P75 | `range_upper_pct` |
| Centre anchor | robust cleaned-pool CMA mid (§1.5) | (fixed — not asking, not raw median) |
| Asking influence on bounds | none | (fixed — display-only marker) |

> STEP 2 confirms callers of `PriceBandService::findOptimalBand()` and how `currentPrice` flows in, then proposes the minimal change to swap asking-anchored bounds for comp-percentile bounds without breaking the aggressive/balanced/defensive band consumers downstream.

---

## 6. Comp type misclassification

**Current behaviour.** "Topanga, 74 Colin Street" shows as Residence/freehold but is in fact **sectional title**. Portal-sourced comps/competition carry a wrong `title_type`. This also undermines item 1's type hard-gate — a mislabelled sectional comp would pass a freehold gate.

**Target behaviour.** `title_type` (freehold vs sectional-scheme) is classified correctly on portal-sourced comps/competition, so:
- The card displays the correct type.
- The item-1 hard gate sees the true type (no sectional leaking into a freehold pool).

> STEP 2 investigates the `title_type` classification on the portal-capture / match-or-create path — whether the source provides it, whether a heuristic mislabels, and where to correct. May need a classification fix at ingress + a re-derive for existing rows feeding PRES 87.

---

## 7. Competition images not displaying — recurring

**Current behaviour.** Most competition cards show "No photo" in PDF and on screen. Recurring across sessions. (Item 2 is the inverse failure mode — when an image *does* show it's a competitor logo.)

**Target behaviour.** Competition cards display the captured property photo in both on-screen presentation and PDF. Root-cause the **portal-capture image storage → presentation render → PDF** path (storage location/URL, disk visibility, PDF image embedding/base64, broken-path fallback) and fix.

**Per AT-22 this item may be root-caused AND fixed in the same pass** (not gated on approach approval). Must coordinate with item 2: the placeholder fallback (when no genuine property photo exists) is shared between the two — a logo is never shown; a real photo always is; otherwise the neutral placeholder.

---

## 8. Acceptance criteria (verified against PRES 87)

Regenerate the PRES 87 PDF after the build and confirm:

1. **Comps** cluster to the subject profile — no sub-R1M / tiny-erf sales; all within type gate, ±25–30% of CMA mid, and the reported radius; list is short and tight. Radius actually used is reported.
2. **No third-party agency name or logo** anywhere on the seller-facing report/PDF.
3. **Spatial view** legible at PDF scale — numbered pins + legend, no overprinting.
4. **Suburb Price Summary** populated from the uploaded report — Total Residential Sales > 0, ranges show real figures.
5. **Recommended range** = comp P25–P75 around the **robust cleaned-pool CMA mid** (§1.5); upper bound evidence-backed; R3.0M asking-leak gone; asking shown only as a reference marker. **Anchor is defensible** — the cleaned pool produces a realistic estimate (no R1.1M-on-a-R2.4M-property), divergence guard widened the radius if the pool was thin, and agent `included_comp_ids_json` curation was honoured as final authority.
5b. **Generate modal** — "Suggestion based on suburb data" shows the system market anchor (clean-pool CMA estimate), not an echo of asking; "Asking price" remains the agent's input; no slider — tick/untick comps recomputes the anchor.
6. **Topanga / 74 Colin Street** (and any sectional comps) correctly classified sectional; type gate respects it.
7. **Competition images** display real property photos; "No photo" only where genuinely no photo exists (neutral placeholder, never a logo).
8. All thresholds **agency-configurable** with the locked defaults; settings UI has a navigation entry; permissions enforced.
9. `php -l` clean on changed files; `dev-check.ps1` 0 new failures; Tinker/route/view verification per CLAUDE.md done-criteria; full branch→Staging flow.

---

## 9. Files (to confirm/expand in STEP 2)

**Likely read/modify:**
- `app/Services/Presentations/CompetitorStockMatchService.php` (item 1, 2, 6 — comp/competition pull + portal fields)
- `app/Services/Presentations/CmaComputeService.php` (item 1, 5 — comp sourcing + CMA mid)
- `app/Services/Presentations/PriceBandService.php` (item 5 — band derivation; the asking-anchor bug at :54-55)
- `app/Services/Presentations/RecommendationService.php` (item 5 — range consumer)
- `app/Services/Presentations/AnalysisDataService.php` (item 4 — `suburb_overview` compile)
- `app/Services/Presentations/PricingSimulatorService.php` (item 4 — `sales_count` default)
- `app/Services/Presentations/Pdf/PresentationStaticMapService.php` + `Pdf/SpatialViewSvgRenderer.php` (item 3)
- `app/Services/Presentations/PresentationPdfService.php` (items 2, 3, 4, 7 — render sites)
- Portal-capture ingest / `TrackedPropertyMatchOrCreateService` (items 2, 6, 7 — source fields)
- Suburb-report parser/upload path (item 4)
- New: agency presentations-settings config/table + settings UI + permission (item 0 configurability)

**New tests:** comp-gate (type hard-gate, price band, radius widen), range P25–P75 (asking excluded), suburb_overview binding, competition-branding strip, image fallback.
