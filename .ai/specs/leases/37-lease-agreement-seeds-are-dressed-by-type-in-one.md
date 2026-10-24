## Lease agreement seeds are dressed by type, in one place (23 Oct 2026, QA1 - Mega lease, e-sign doc 51 / template 82)

"Create lease & prepare for signing" filled the agreement with raw values: "2026-11-01" / "2027-10-31", "5500.00", "10". **Rule: `LeaseAgreementDocumentValues::forLease()` works every key out RAW and dresses it once, at the end, by its registry type (`present()`); the stored lease and its terms stay raw.** Formats are the ones CoreX documents already use:

| Registry type | Printed as | Same as |
|---|---|---|
| `money` (rent, deposit, other deduction, service fee, net to owner) | `R 8,500.00` | `App\Support\Money\Zar::format` (AT-177, also `DataType::ZarMoney`) |
| `date` (start, end, earliest termination, earliest notice) | `1 November 2026` | the `j F Y` of `WebTemplateDataService` (lease and mandate dates); there is no per-agency date-format setting |
| `percent` (escalation) | `10%` / `7.5%` | - |
| `month` | `March` | unchanged |
| `integer`, `text`, `longtext` | as typed | unchanged |

Calculations (escalation in words, the service fee, net to owner) read the RAW numbers, never a dressed string.

**An agreement that already prints the unit must not get it twice.** `LeaseSeedPresentation` reads the agreement's own compiled template and tells the formatter per key: `bare` - the template draws the "R" (a `field-currency` box, or a literal "R" before the box) or writes the "%" after the box (HFC's lease: "an escalation of [box]% per annum"), so only the number is seeded (`5,500.00`, `10`); `iso` - the target field is a native date box (field type `date`), which shows nothing for any value but `Y-m-d`. Anything it cannot read (a PDF-overlay agreement) is dressed in full. No new setting: it follows the template, so a new agency's lease needs a map, not code.

What is printed still reads back to the lease value: `LeaseAgreementValuesReader` parses `R 8,500.00`, `1 November 2026` and `10%` (pinned in the tests), so the change check and the renewal reader are unaffected.

Tests: `LeaseSeedDisplayFormatsTest` (new - every type, raw stored record, bare/iso, round trip, raw deduction in the schedule, words kept as typed, the template detector incl. the stray-"R" cases); `LeaseSigningLauncherTest` (seeded values now the dressed ones; plain text-box agreement and an own-"R"/"%" agreement end to end). Not changed: `WebTemplateDataService` still hands a raw rental/date to templates that resolve from document details (a different path - the lease launcher does not use it for these fields).
