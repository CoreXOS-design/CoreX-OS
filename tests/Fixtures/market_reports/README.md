# Market Report Test Fixtures

PDF samples that exercise the `App\Services\MarketReports\Parsers\*` family in
`tests/Unit/MarketReports/Parsers/`. Each fixture is a real CMA Info /
Lightstone / etc. report; tests run them through the parser end-to-end
(via `pdftotext -layout`) rather than mocking `extractText()`.

## Why real fixtures, not stubs

Parser regex patterns are tuned to the exact byte-sequence pdftotext emits
for each report template. Stubs that fake `extractText()` make the tests
pass while leaving real-world layout quirks (column-wrap, line breaks
inside cells, replacement-char glyphs `0xEF 0xBF 0xBD`) uncovered. Every
parser test in this folder MUST read from a real PDF.

## Naming convention

```
cma_info_<variant>.pdf
lightstone_<report>.pdf
deeds_office_<variant>.pdf
ooba_<variant>.pdf
betterbond_<variant>.pdf
agent_built_cma_<variant>.pdf
```

The `<variant>` slug matches the `market_report_types.key` minus the vendor
prefix. So a fixture for `market_report_types.key = 'cma_info_vicinity_sale'`
lives at `cma_info_vicinity_sale_residential.pdf` (and a sibling
`_vacant_land.pdf` for the second variant if the parser handles multiple).

## Currently present

| File | Used by | Variant |
|---|---|---|
| `cma_info_sectional_title_sales.pdf` | `CmaInfoSectionalTitleSalesParserTest` (future) | Regression guard — proves CmaInfoVicinitySaleParser yields to the sectional title parser |
| `cma_info_vicinity_sale_residential.pdf` | `CmaInfoVicinitySaleParserTest` | Residential freehold variant |
| `cma_info_vicinity_sale_vacant_land.pdf` | `CmaInfoVicinitySaleParserTest` | Vacant land variant |

## Adding a new fixture

1. Drop the PDF here using the naming convention above. **Redact PII** if
   the source contains personal info (owner names, phone numbers) — copy
   the source PDF, open in a PDF editor, replace identifying strings with
   `[REDACTED]`. The pdftotext output is what the parser sees, so byte-
   level redaction in the PDF is sufficient.
2. Reference the file from your `*ParserTest.php` via
   `base_path('tests/Fixtures/market_reports/<your_fixture>.pdf')`.
3. Tests that read the fixture should `$this->markTestSkipped()` when the
   file is missing — so the suite still runs cleanly on a fresh checkout
   where someone forgot to copy the PDF in.

## Why pdftotext is the contract

CMA Info, Lightstone, and the other vendors all publish their reports as
fixed-layout PDFs. We parse via `pdftotext -layout` (or
`TextExtractionService` fallback when the binary isn't on PATH — see
`AbstractCmaInfoParser::extractText()`). The regex patterns in each
parser are tuned to the exact column-preserving output pdftotext produces.

If a parser test fails on a real fixture but passes against a hand-typed
text stub, the bug is in the parser — not the fixture. Don't "fix" the
stub. Fix the parser.

## Re-deriving the expected output

When debugging a failing fixture-backed test:

```bash
pdftotext -layout tests/Fixtures/market_reports/<your_fixture>.pdf -
```

Pipe the output to a temp file, review what pdftotext actually saw, and
adjust the parser's regex to match. Update the test's expected-value
assertions only after confirming the parser handles the real bytes.
