# PRES-CMA-REALFIX — CMA Info Data-Shape Verification (read-only)

> **Date:** 2026-06-16
> **Branch:** `PRES-CMA-REALFIX` · **DB:** `corex_dev` (local)
> **Author:** Claude + Johan
> **Status:** verified against live-imported CMA data. Read-only.

## ⚠️ GATING DATA-SHAPE FINDINGS
1. **"PRES 87" does not exist here.** Max presentation id = **84**. Grindewald = **PRES 41 — "29 GRINDEWALD DRIVE"**. The "PRES 87" numbers came from a different env (likely demo `nexus_os_demo`). Grindewald's imported CMA report = **`market_report` 55 (UVONGO)** (comp rows literally on Grindewald Drive).
2. **`mic_comp_row_id` FK is empty across the whole DB** — `linked=0` for all 39 presentations with sold comps. The FK→`market_report_comp_rows.condition` path is non-functional on real data here.
3. **Condition is 0% populated** — `market_report_comp_rows.condition` = **0 / 741** comp rows; `presentation_sold_comps.raw_row_json` has **no condition key**.
4. **PRES 41 itself has 0 sold comps** and asking **R1,600,000** — so its band compiles to NULL. The documented "R2.9M overpriced" Grindewald scenario is not reproducible on the live PRES 41 row; the figure-integrity test uses a controlled fixture (median R2,525,000) instead.

## 1. COMP COUNT (`market_report_comp_rows`, row_type='comp', 65 reports)
**min=1, max=18, median=12, mode=15.** Distribution: [0–4]:5 · [5–9]:18 · [10–14]:17 · [15–19]:25. **~20/report is NOT typical — real shape ~12–15, capped at 18.** Grindewald report 55 = **18 comps**.

## 2. STRUCTURE
| Layer | Source | Status |
|---|---|---|
| (a) suburb registrations (counts) | — | **MISSING.** Only `suburb_high/low/max_year` = annual **price** points, not counts. |
| (b) vicinity sales by type | `mrcr comp` (741) + `vicinity_radius_sale_price`/`comparable_sale_price` | **EXISTS**; `property_type` 736/741 (99%). |
| (c) subject | `mrcr row_type='subject'` (80) | **EXISTS**. |
| (d) active for-sale stock | `mrcr listing` (56) + `presentation_active_listings` (4,863) | **EXISTS but inconsistent** (§4). |

## 3. REGISTRATIONS BY TYPE
**Not available at any granularity.** Only count-type metric is `scheme_owner_count` (1 row). No registrations-per-month, suburb-wide or per type. **Months-of-stock is not computable.**

## 4. ACTIVE STOCK COUNT
`mrcr listing` = 56 (sparse). `presentation_active_listings` = 4,863, per-presentation area dumps (PRES 50=464), **not type-filtered**. `active_stock ÷ registrations_per_month` is **NOT computable by type** — both inputs missing/unreliable.

## 5. BAND EVIDENCE
Per-report sold-price spread (reports ≥8 comps): median per-report half-band ≈ ±16%, **bimodal** — clean single-suburb same-type pools cluster **±10–12%**, mixed pools (Margate) blow out to **±23%**. Grindewald **report 55: median R985,000, p25 R930,000, p75 R1,045,000 → IQR −5.6%/+6.1% (≈±6%)**, mid-60% within −7.8%/+9%. **Read:** clean same-type cluster ≈ ±6–7% (IQR) widening to ±10–12% (mid-60%); ±20%+ is type-contamination the per-comp grid corrects. → **band cap belongs AFTER adjustment (~±6–8%), not as a blanket on a raw pool.**

## 6. CONDITION SOURCE
`mrcr.condition` = **0/741** populated; report 55 = 0/18. `raw_row_json` has no condition. `mic_comp_row_id` = 0 linked. **Per-comp condition unavailable from imported data** — must be captured at curation or excluded from v1.

**Grid-sourceable per-comp (fill rates):** `extent_m2` 702/741 (95%) · `sale_date` 741/741 (100%) · `property_type` 736/741 (99%). condition 0% · position/view = no column.

## Bottom line
- Size + sale-date + type reliably present (95–100%) → real grid axes.
- Condition (0%) + position/view (no column) → capture at curation or exclude from v1.
- Months-of-stock / registrations-by-type → not buildable from current imports.
- Band cap evidence: clean same-type ±6–7% (IQR) → ±10–12% (mid-60%).
