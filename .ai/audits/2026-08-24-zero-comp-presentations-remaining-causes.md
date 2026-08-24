# Zero-hydrated-comp presentations — the two causes that aren't defects

Two of the four independently-sufficient causes found while investigating the
Marina Glen "0 of 61" incident are not bugs. They're product decisions Johan
needs to make, not code to fix. Written up separately per his instruction, not
folded into the code-gate fix (`.ai/audits/` doc pending for that one).

The other two causes (the title-type classifier gap, and the two duplicated
gates it lived in) are fixed — see the `TitleTypeClassifier::resolveCategory()`
unification and the `1a3939047` cherry-pick, same date.

---

## Cause 3 — no CMA report was ever uploaded for the property

`MicSnapshotHydrator::collectMatchedRows()` sources comp candidates from
`market_report_comp_rows`, scoped to `agency_id` + `row_type='comp'` +
matching `is_demo` + non-null `sale_date`/`sale_price`. If no CMA report
covering this property or its suburb was ever uploaded, this query returns
zero rows before any title-type logic runs at all — there is nothing to
classify, gate, or hydrate.

**This is not a bug.** CoreX has no comp data of its own until an agent
uploads a CMA-Info (or equivalent) report, or a registered HFC deal happens
to cover the same suburb. A property nobody has ever run a CMA for
legitimately has zero comps.

**What this means for the product**: the empty-state message fixed today
already handles this case correctly — "No CMA report has been imported for
this property yet... Upload a CMA report" is the true, actionable state.
Nothing further to build unless Johan wants to change the underlying
behaviour (e.g. auto-suggest a report from a neighbouring property, or block
presentation generation entirely until a report exists) — that's a product
call, not a defect to patch.

**A secondary, narrower version of the same cause**: a report WAS uploaded,
but its comp rows are missing `sale_date` or `sale_price` — a PDF-parsing
gap on that specific report, not the presentation. Same empty end state,
different remedy (re-upload or re-parse the report, not build a new list).
Not separately measured — would need a query across `market_report_comp_rows`
for rows with `row_type='comp'` but a null `sale_date` or `sale_price` to
size this specifically.

## Cause 4 — the subject address is too short to match its own report

`SubjectReportResolver::resolveReportIds()` (the mechanism that says "this
market report was written FOR this exact property, so its comps enter
regardless of date window or radius") requires at least one address fragment
of 8 or more characters extracted from the presentation's `property_address`.
A blank address, or one that produces no fragment that long, returns an empty
report list — the same-subject-report exemption silently doesn't apply, and
comps then depend entirely on suburb-name matching (`SuburbMatcher`) or a GPS
radius search succeeding instead.

**This is a deliberate correctness gate, not an oversight.** The resolver's
own comment explains why: an earlier version matched on suburb alone and
"borrowed" a different property's report (report 81 NAUTILUS / 75 Marine
Drive got stamped onto 55 Garden Avenue, AT-78) — a wrong report is worse
than no report, because it silently mixes in someone else's analyst-vetted
comps. The 8-character floor exists to stop a too-short, too-generic
fragment (a bare unit number, a one-word suburb) from producing a false
match. Loosening it reintroduces the exact bug AT-78 fixed.

**What this means for the product**: a presentation whose subject address is
blank, or unusually short/generic, won't get its own report's comps
auto-included even when a perfectly matching report exists — it falls back
to suburb/radius matching, which may or may not find the same comps by a
different, weaker path. Not measured how often this actually happens on real
data (would need a query for presentations where `property_address` produces
zero ≥8-char fragments) — flagging the mechanism, not its live frequency.

**The decision for Johan, if any**: is the current floor (8 characters) too
strict for any real HFC address shape? Worth checking real addresses that
have hit this before deciding — not something to loosen speculatively given
why it exists.

---

## What's already fixed (for context, not part of this write-up's scope)

- Header no longer conflates the presentation's own comp count with the
  suburb-wide `CmaCoverageService` estimate (`e2ccda75c`).
- Empty-state message now distinguishes "no report imported" from "a report
  was imported but produced zero comps" (today, this session).
- `CompPoolBuilder::category()`'s title-type trust logic cherry-picked from
  `origin/main` onto Staging (`1a3939047`) — sectional CMA-Info comp rows
  stamped `property_type='Residence'` now classify correctly via
  scheme/section signal instead of the generic text alone.
- The classification decision itself is now one canonical method
  (`TitleTypeClassifier::resolveCategory()`) both `CompPoolBuilder` call
  sites delegate to, instead of a private copy that could drift — proven
  with an end-to-end test reproducing the exact Marina Glen input shape,
  confirmed to fail without the fix and pass with it.
- The three silent `catch (\Throwable) { // Skip }` blocks around comp/
  listing inserts in `MicSnapshotHydrator` now log what failed, why, and
  which row — behaviour unchanged, only visibility added.
