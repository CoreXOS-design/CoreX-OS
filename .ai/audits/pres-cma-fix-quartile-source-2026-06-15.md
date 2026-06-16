# PRES-CMA-FIX — §5/§6 recommended range: quartile-source verification + subject self-exclusion

> **Date:** 2026-06-15
> **Branch:** `PRES-CMA-FIX-comp-quartiles` (off `AT-43-…`, Staging merged in)
> **Author:** Claude + Johan
> **Status:** verified on real local data; relevant suite green (CompPoolBuilderTest 15/15)

---

## TL;DR

The prompt's stated root cause — "§5/§6 renders the **imported CMA valuation
band** mislabelled as P25–P75" — is **already fixed in code on this branch and on
`origin/main`**. §5/§6 read the **comparable-sales distribution** (pool_stats
p25/median/p75), not the imported band. The live Grindewald R2,994,000–R3,000,000
is therefore a **stale generated PDF artifact** (the version HTML is rendered once
at publish and stored), not a live code defect — **regenerate** the presentation to
refresh it.

Two genuine improvements shipped here:
- **A — subject self-exclusion** so the subject can never be its own comparable
  (a real 0 m case exists in local data: PRES 81 / comp 954).
- **B — honest labelling** of the "Why This Range?" middle row (it showed the comp
  median but was labelled "CMA Valuation (Middle)").

---

## The 4 critical checks

### CHECK 1 — §5 uses pool quartiles, NOT the imported CMA band

Fresh `AnalysisDataService::compile()` for 5 local presentations. §5 render values
(`cma_lower/middle/upper`, PresentationPdfService:654-656) vs pool_stats vs the
imported CMA benchmark (`cma_info_benchmark`):

| PRES | §5 lower / middle / upper | pool_stats p25 / median / p75 | imported CMA lower / middle / upper |
|---|---|---|---|
| 1  | 173,750 / 722,500 / 950,000     | **173,750 / 722,500 / 950,000**     | 1,150,000 / 1,459,000 / 1,873,000 |
| 3  | 1,250,000 / 1,575,000 / 1,700,000 | **1,250,000 / 1,575,000 / 1,700,000** | 1,763,000 / 1,950,000 / 2,292,000 |
| 5  | 1,175,000 / 1,297,500 / 1,500,000 | **1,175,000 / 1,297,500 / 1,500,000** | 1,132,000 / 1,283,000 / 1,426,000 |
| 10 | 937,500 / 1,197,500 / 1,418,750  | **937,500 / 1,197,500 / 1,418,750**  | 1,258,000 / 1,615,000 / 1,768,000 |
| 12 | 1,495,000 / 1,740,000 / 2,500,000 | **1,495,000 / 1,740,000 / 2,500,000** | 1,883,000 / 2,247,000 / 2,532,000 |

§5 == pool quartiles in **every** case; §5 never equals the imported CMA band.

### CHECK 2 — subject excluded from its own pool

Across **39** local presentations with comps: **0** subject-by-address hits; **1**
subject-by-GPS(≤8 m) hit → **PRES 81, comp 954, 0 m**. PRES 81's subject is
**sectional** with only "Margate" as its address; comp 954 ("NT SERRAT, 3 QUEENS")
sits at the same GPS. The guard **deliberately does not** GPS-exclude sectional
subjects (scheme-mates legitimately share one point and are the best comps), and
with no street address it can't disambiguate — so this row is **correctly retained**.

The guard (CompPoolBuilder) is proven by 4 unit tests: exclude-by-address,
exclude-by-GPS (freehold), **sectional scheme-mate kept** (no-regression),
no-op-without-signals. It runs at hydration, which `regenerate` re-executes
(MicSnapshotHydrator wipes + re-selects MIC/deal comps).

### CHECK 3 — is there ANY path where the imported CMA band still feeds the range / a quartile row?

**No.** `PresentationPdfService` never reads `cma_info_benchmark` / `cma.middle_range`
(only an explanatory comment mentions it). `$cmaLower/$cmaMiddle/$cmaUpper` come
solely from `$cma['cma_lower/middle/upper']` = pool_stats p25/p75 + method_median.
The imported band lives only in `cma_info_benchmark`, explicitly "NEVER rendered on
the seller PDF" (AnalysisDataService:503-513).

### CHECK 4 — which AT-22 commit carries the quartile-source fix, and where is it?

- `20920ba1` (2 Jun) — first wired `cma_lower = pool_stats.p25`, `cma_upper = p75`.
- `b9400f77` — AT-22 overhaul (compute engine + pool gate).
- `d91a199d` — tick-wire live compute.

All three are on **`origin/Staging` AND `origin/main`**. So the fix is on production
code already; the live stale numbers are a **regen/deploy-of-artifact** matter, not a
code change. Action for live Grindewald (PRES 87): **regenerate** the presentation.

---

## Changes in this branch

- `app/Services/Presentations/CompPoolBuilder.php` — `SUBJECT_SELF_RADIUS_M = 8`;
  `isSubjectSelf()` (address-equality always; GPS≤8 m only for non-sectional);
  `normaliseAddr()`; `n_subject_self_excluded` diagnostic.
- `app/Services/Presentations/MicSnapshotHydrator.php` — pass candidate/subject
  `address`; skip the subject's own deal by `property_id` in the deals-comp path.
- `app/Services/Presentations/PresentationPdfService.php` — relabel the middle row
  "CMA Valuation (Middle)" → "Comparable sales — median"; §5 subtitle "around the
  CMA mid" → "around the comparable-sales median".
- `tests/Unit/Presentations/CompPoolBuilderTest.php` — 4 subject-exclusion tests.
