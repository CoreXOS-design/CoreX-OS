# Budgets and targets move to Road to Sold settings: counts and sums before and after (re-home step 4)

Prepared 21 Oct 2026 for Johan. Ticket AT-475. Spec: `.ai/specs/agency-tracker-rehome.md` 4.3, 4.5, 5.7.

**Status: proven on a copy of the QA1 data, NOT yet run on QA1.** Johan put a hold on QA1 while HFC staff are in rental training, so nothing has been pushed, deployed or migrated there. "Before" is read from QA1 (read-only, 21 Oct 2026 about 08:00). "After" is what the move produced when run on a scratch copy of exactly those rows. When QA1 is released, the same migration runs there and these figures are re-checked against the live result.

## In plain words

- **Nothing was lost.** Every agent budget the Worksheet held is now in Road to Sold settings, month by month, to the cent. The old Worksheet rows are all still there (75 of 75), untouched.
- **Branch budgets:** 30 branch budgets before, 29 after. The one that did not move is the one you ordered archived: the R200,000 budget with no branch for September 2026 (the old Monthly Goals row number 31). It is switched off, not deleted, and can be brought back.
- **Targets: no rows and no numbers changed** (173 rows; 2,106,200 points; 133 deals; 1,307 listings; R163,048,000 value). Moving the Targets screen did not touch the targets themselves.
- **Listing Targets** was empty on QA1, so there was nothing to fold in. If another agency has some, they are added to that agent's "new listings" target, and a number already there is never replaced.
- Running the move a second time changes nothing.

## Agents' budgets (Worksheet -> Road to Sold > Budgets)

Only budgets above zero are carried (a row of zeros says nothing). Where the Worksheet held the same agent and month twice, the newest row wins. The "want" figure is not carried (you dropped it).

| Month | Budgets before | after | Personal before | after | Business before | after |
|---|---:|---:|---:|---:|---:|---:|
| 2026-02 | 17 | 17 | 717,858.61 | 717,858.61 | 105,513.74 | 105,513.74 |
| 2026-03 | 16 | 16 | 691,790.52 | 691,790.52 | 90,861.80 | 90,861.80 |
| 2026-04 | 13 | 13 | 591,938.77 | 591,938.77 | 62,159.85 | 62,159.85 |
| 2026-05 | 9 | 9 | 454,194.66 | 454,194.66 | 36,518.67 | 36,518.67 |
| 2026-06 | 3 | 3 | 202,703.70 | 202,703.70 | 12,511.11 | 12,511.11 |
| 2026-07 | 1 | 1 | 60,000.00 | 60,000.00 | 2,000.00 | 2,000.00 |
| 2026-08 | 2 | 2 | 116,603.78 | 116,603.78 | 5,773.58 | 5,773.58 |
| **All** | **61** | **61** | **2,835,090.04** | **2,835,090.04** | **315,338.75** | **315,338.75** |

Worksheet rows in the old table: 75 before, 75 after. (September and October have Worksheet rows but no budget in them, so nothing to carry.)

## What each branch needs (Monthly Goals "branch budget" -> Road to Sold > Budgets)

| | Rows before | after | Total before | after |
|---|---:|---:|---:|---:|
| Branch budgets above zero | 30 | **29** | R 7,812,500.00 | **R 7,612,500.00** |

The difference is exactly one row, R200,000: **Monthly Goals row id 31** (agency 1, no branch, September 2026, R200,000). It was archived by its id, after re-checking agency, branch, month and amount; it was not copied. Rows in the old table: 36 before, 36 after (nothing deleted).

**For Johan - five more of the same.** The same R200,000 "no branch" budget also sits in April, May, June, July and August 2026 (rows 10, 14, 18, 22, 26). You named only September, so those five were carried across unchanged and still count toward the company total. Do you want them archived too?

Monthly Goals' own goal columns (deals, listings, value) were zero in every row, so nothing there needed a home; the Targets tab now shows each branch's targets added up instead.

## Monthly targets (the Targets screen)

| | Rows before | after | Points | Deals | Listings | Value |
|---|---:|---:|---:|---:|---:|---:|
| All months | 173 | 173 | 2,106,200 | 133 | 1,307 | R 163,048,000.00 |
| 2026-09 | 23 | 23 | 281,000 | 10 | 100 | R 13,495,000.00 |
| 2026-10 | 1 | 1 | 0 | 0 | 0 | R 0.00 |

Identical before and after in every column.

## The targets-zeroing bug

Saving the Targets screen used to write zero into deals, value and listings for every agent on the page, because the form posted only the points box while the handler wrote all four. Now a save writes only the boxes that were posted; a blank box is left alone and a typed 0 sets zero; the person who first set a target stays recorded as its creator. The old Targets address redirects to the new tab, and a Targets page that was open before the move can still post safely. Proven in `tests/Feature/AgencyTracker/BudgetsAndTargetsMoveTest.php`.

## How this was proven

The method: read the counts and sums from QA1 (SELECT only) -> copy the seven source tables into a scratch schema on the tests-only MySQL -> run the two new-table migrations there -> read the same counts and sums -> run the move a second time and confirm nothing changed.
