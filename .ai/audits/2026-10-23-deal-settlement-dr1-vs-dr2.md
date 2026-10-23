# Deal settlement — old register (DR1) against the Deal Register (DR2): the comparison

Date: 23 Oct 2026. Lane: Road to Sold (AT-454), Agency Tracker re-home step 6. QA1 only.
Why this exists: `agency-tracker-rehome.md` §8.3 and §8.7 check 6, and Johan's answer 10 of 20 Oct 2026 — "compare first": nothing in `admin.deals.settle*` is redirected until this is delivered and signed off, because settlement is money.
Method: a line-by-line diff of the two controllers' settlement code and of the two sets of screens (read on `origin/QA1` at `1f03acac7`). Nothing was run against money data.

## The answer in one paragraph

The two settlement screens do the same sums and write the same rows. Every line of arithmetic, every validation rule, the lock on a Paid deal, the "mark as paid" path, the money-line rebuild and the finance roll-up are identical. The Deal Register copy (`Dr2\DealSettlementController`, 536 lines) is a faithful copy of the old one (`Admin\DealController` settlement methods, lines 654-1147). The only differences are the names of the routes and views it uses, how the screen looks, and one rule about who may open the printouts. **No behaviour the Deal Register copy lacks was found.** Redirecting the four old settlement addresses to the Deal Register copy would change no number anywhere — but that redirect has NOT been made. It waits for Johan's word.

## What was compared

| Piece | Old register | Deal Register | Same? |
|---|---|---|---|
| Open the settlement screen (`settle`) | `Admin\DealController::settle` | `Dr2\DealSettlementController::settle` | Same pools, same summary, same rows. Only the screen it renders differs (below) |
| Save the settlement (`saveSettlement`) | `Admin\DealController::saveSettlement` | `Dr2\DealSettlementController::saveSettlement` | Identical: validation, the external-side rule, the share / cut / PAYE / bonus fields, the paid lock, `mark_paid`, the transaction, the money-line rebuild, the roll-up, the log entry. Only the redirect target after saving differs (it returns to its own screen) |
| Print settlement | `printSettlement` | `printSettlement` | Identical sums; same figures printed (see the permission rule below) |
| Print agent payslip | `printAgentPayslip` | `printAgentPayslip` | Identical sums; same figures printed |
| Summary and per-agent rows | `buildSettlementSummary`, `buildSettleRows` | same names | Identical, no difference in any line |
| The screen (`settle.blade.php`) | `admin/deals/settle` | `dr2/settle` | Same form fields (every `name=` identical), same script (the live recalculation script is identical word for word), same buttons. Styling only: the Deal Register copy uses the newer colour variables; the old one carried an old-look wrapper class |
| The two printouts | `admin/deals/print/*` | `dr2/print/*` | Byte for byte identical |

## The differences, all of them

1. **Names.** Routes `admin.deals.settle*` against `deals-dr2.settle*`; views `admin.deals.settle`, `admin.deals.print.*` against `dr2.settle`, `dr2.print.*`. The page's "Back" and "Print" buttons point at its own family.
2. **Who may open the two printouts.** Both need the `settle_deals` permission. The old register then ran the full "edit" deal check, so an assistant could print only the assigned agent's own deals. The Deal Register copy runs the "read" check for printouts (`forEdit: false`): an assistant can print a deal they can see at the agent's breadth, but can still never SAVE a settlement on a colleague's deal (that stays the edit check in both). In plain words: a printout of a colleague's deal is slightly easier to reach in the Deal Register copy for an assistant; changing figures is not. Money is never changed by it.
3. **Look.** Colours and a few layout classes. No field, label or number differs.

## What this means for the redirect

- Behaviour the Deal Register copy lacks: **none**.
- Behaviour only the Deal Register copy has: the read-breadth rule for printouts (item 2).
- The four old addresses (`/admin/deals/{id}/settle`, its save, and the two print pages) are still served by the old screens today. They are inside the Deals menu highlight already. They will 301 (page) / 308 (save) to the Deal Register copy in one small change as soon as Johan signs this off — that change is ready to be made and is the only thing left of the settlement part of step 6.

## Decision needed from Johan (a business one)

"Is it fine that, from the day the old settlement addresses are switched off, an agent's assistant can print the settlement of a colleague's deal they can already see?" — everything else is identical. If not, the Deal Register copy gets the stricter rule first, then the redirect.
