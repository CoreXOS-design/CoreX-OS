# CMA property-valuation parser — freehold comp rows parsed to zero, live hotfix by patch-id

**Date:** 2026-08-20

## What happened

Johan, on live: presentation version 290's review screen
(`https://corexos.co.za/presentations/version/290/review`) showed "Imported
& hydrated: 2 reports imported · 0 comps parsed · 0 sold + 0 active
hydrated", "No comparable sales hydrated yet", "0 OF 10 COMPS USED". His
own diagnosis was sharp and correct on both counts: (1) the earlier
property-type filter fix (see the market-analytics audit earlier tonight)
could not be the cause here — there were no rows to filter — and (2) his
note that Shawn's presentation from **yesterday worked** was the decisive
clue, since it meant the parser is not broadly broken, only this shape.

Subject: 138 Torquay Avenue, Leisure Bay, Port Edward (Johan's "183 Torquay
Road" traced earlier to a digit transposition — real erf is 138, "Avenue"
not "Road", both confirmed against the deeds PDF).

## Root cause

Two real CMA Info PDFs Johan uploaded for this subject (market_reports
`239`, `241`, parser `cma_info_property_valuation_v2`) each carry a genuine
page-5 "CMA - Comparative Market Analysis" table with real comparable
sales — confirmed by reading the actual source PDF and cross-checking with
`pdftotext -layout` (the exact extraction method the real parser pipeline
uses, per `AbstractCmaInfoParser::extractText()`). `239` has 3 comps
(Walton Ave ×2, Swansea Ave), `241` has 11 (Penzance, Bristol, Haven,
Seaford, Blackpool, Portobello, Saltcoats, New Haven Ave — a separate,
wider CMA run for the same subject, confirmed genuinely different files by
`file_hash`, not a duplicate upload).

`CmaInfoPropertyValuationParser::extractCmaCompRows()`'s row-matching regex
was built for **sectional title** tables only — it requires
`Section number + SS number + SS year + literal "Residence"` immediately
before the row data. This subject is a **freehold erf property**; its
comp table has no section/SS number at all. The row shape is instead
`<idx> <dist>m <erf> <STREET>, <SUBURB> <usage> <extent>m² <date> R<price>
R<est> R<ppm>`, and "usage" here reads **"Residential"**, not "Residence"
(the same CMA Info vocabulary split already fixed tonight in
`MarketCompRowsSoldAdapter`, but here it broke row *matching* entirely
rather than a downstream filter — zero rows can ever match, not some).
This is not a regression — this table shape was never supported by this
parser's comp-row extraction, for any freehold subject. Confirmed via
`git log` — zero commits touched this parser or its abstract base class
since 2026-08-18, ruling out a recent deploy as the cause. Shawn's working
presentation from yesterday (id 134, 26 Romsdal Road) used the general
market-analytics "run" pipeline (a different feature entirely, fixed
separately tonight) — not this review-screen hydration path — so it isn't
actually a counter-example; the two screens read different data.

The benchmark values (Lower/Middle/Upper range) extracted correctly because
they come from an entirely separate, simple text-scan regex
(`Lower Range:\s*R...`) that doesn't depend on the row-table pattern at
all — confirming Johan's own read that "parsing is partially working."

## Fix

Added a second extraction pattern to the same method, for the freehold/erf
row shape, run against the same already-scoped comparative-properties
text block. Also widened `parsePriceBounded()`'s floor for this new path
specifically (its 50k default would have silently dropped 2 of the 3 real
comps on report 239 — genuine 1997/1998 sale prices of R13,000 and
R14,000). The existing sectional-title path and its bound are untouched.

## Patch-id — literal cherry-pick, single environment

| Environment | Commit | Patch-id |
|---|---|---|
| `origin/main` | `1805213c7` | `8e7ced26b...` |
| `/corex` (live) | `2871d557b` | `8e7ced26b...` |

Identical patch-id both sides.

## Data repair on live (not just code)

Fixing the parser alone does not repair already-parsed reports — `239` and
`241` had already been parsed (to zero comp rows) before this fix landed.
Re-ran `CmaInfoPropertyValuationParser::parse()` directly against both
reports' real stored PDFs with the fix in place, then inserted only the
newly-found `comp`-type rows (their existing `subject` row was left alone
— `ParseMarketReportJob::handle()` is not idempotent for comp-row inserts,
so re-dispatching the job would have duplicated the subject row instead of
just adding the missing comps). `239` → 3 rows, `241` → 11 rows, both
matching their source PDFs exactly (addresses, distances, dates, prices).
Then re-ran `MicSnapshotHydrator::hydrateForPresentation()` for
presentation 137 (idempotent — wipes and rebuilds this presentation's own
`presentation_sold_comps`/`presentation_active_listings` rows before
reinserting) — 12 sold comps hydrated, `source_reports: [239, 241]`,
matching the page's own "2 reports imported" exactly.

## Verification on live — the real page, not an adapter count

Learned the hard way earlier tonight (a different fix) that an
adapter-level pass is not proof the page renders correctly. This time:
real authenticated HTTP GET to
`https://corexos.co.za/presentations/version/290/review`, using Shawn's own
genuinely active live session (cookie correctly re-derived via the
Encrypter's actual decoded key — `app('encrypter')->getKey()`, not the raw
`config('app.key')` `base64:`-prefixed string, which was the mistake that
made every earlier cookie-crafting attempt tonight silently fail auth).

- **HTTP 200.**
- Banner: **"2 reports imported"** (unchanged, correct) and **"12 of 24
  comps used in this CMA"** — was "0 of 10".
- "No comparable sales were found for this subject" — gone (0 occurrences
  in the response, was present before).
- Real comp rows rendering in the table: Walton Avenue, Bristol Avenue,
  Penzance Avenue confirmed present in the actual HTML response.

## Scope note

Per Johan's explicit scope cut mid-investigation: dropped the originally-
requested agency-wide zero-parse-rate survey and the "no category set"
check entirely. This fix and this verification cover presentation 137 /
version 290 only, as instructed — "one presentation, its reports, make it
work."
