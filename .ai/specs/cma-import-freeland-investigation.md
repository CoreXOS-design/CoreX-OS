# CMA Info Import — Freeland Park fails, Amanzimtoti succeeds

> **Status:** read-only investigation; no code changed.
> **Authored:** 2026-05-28 on branch `feature/map-workspace-overhaul`.
> **Decision sought:** Johan signs off on the root cause + scope before any fix is built.

---

## (a) Entry-point map

The import is a single fetch followed by a synchronous parse job. Each hop with file:line.

### Routes
[routes/web.php:2861-2876](routes/web.php#L2861-L2876) — under `Route::prefix('reports')->name('reports.')->middleware('permission:mic.upload_reports')`:

```php
Route::get('/',                       …'index')->name('index');
Route::get('/create',                 …'create')->name('create');
Route::post('/',                      …'store')->name('store');
Route::get('/bulk-import',            …'bulkImportShow')->name('bulk-import');
Route::post('/bulk-import',           …'bulkImportStore')->name('bulk-import.store');
Route::get('/parsers',                …'parserDashboard')->name('parser-dashboard');
Route::get('/{report}',               …'show')->name('show');
Route::delete('/{report}',            …'destroy')->name('destroy');
Route::post('/{report}/spot-check',   …'runSpotCheck')->name('spot-check');
Route::get('/{report}/discrepancies', …'discrepancies')->name('discrepancies');
Route::post('/{report}/reparse',      …'reparse')->name('reparse');
Route::post('/{report}/restore',      …'restore')->name('restore');
```

### Controller
[app/Http/Controllers/CoreX/MarketReportController.php:72](app/Http/Controllers/CoreX/MarketReportController.php#L72) — `store()` (single-file path).
[app/Http/Controllers/CoreX/MarketReportController.php:184](app/Http/Controllers/CoreX/MarketReportController.php#L184) — `bulkImportStore()` (multi-file path).

Both call the **parser registry's auto-detect** to choose `report_type_id` at upload time, then dispatch the parse job:

```php
// store() lines 115-122
if (!$detectedTypeId) {
    $detection = $this->registry->detect($absolutePath);
    $detectedConfidence = $detection['confidence'];
    $detectedKey = $detection['parser']->getReportTypeKey();
    $type = MarketReportType::query()->where('key', $detectedKey)->first();
    $detectedTypeId = $type?->id;
}
…
ParseMarketReportJob::dispatchSync($report->id);   // line 140
```

### Parser registry
[app/Services/MarketReports/MarketReportParserRegistry.php:30-40](app/Services/MarketReports/MarketReportParserRegistry.php#L30-L40) — `V1_PARSERS` ordered constant (6 CMA parsers + `GenericFallbackParser` last).
[app/Services/MarketReports/MarketReportParserRegistry.php:56-71](app/Services/MarketReports/MarketReportParserRegistry.php#L56-L71) — `detect()` walks every parser, calls `canParse()`, takes the highest score:

```php
public function detect(string $filePath): array
{
    $best = null;
    $bestScore = -1.0;
    foreach ($this->all() as $parser) {
        $conf = $parser->canParse($filePath);
        if ($conf->score > $bestScore) {
            $best = $parser;
            $bestConf = $conf;
            $bestScore = $conf->score;
        }
    }
    return ['parser' => $best, 'confidence' => $bestConf];
}
```

### Parser job
[app/Jobs/MarketReports/ParseMarketReportJob.php:72-87](app/Jobs/MarketReports/ParseMarketReportJob.php#L72-L87) — resolves the parser (by stamped `report_type_id` OR by re-running detect when the stamp is null), then calls `$parser->parse($absolutePath, $report)` at line 91.

### Candidate parser
[app/Services/MarketReports/Parsers/CmaInfoMedianSalesAnalysisParser.php:1-214](app/Services/MarketReports/Parsers/CmaInfoMedianSalesAnalysisParser.php) — the parser that *should* handle suburb-history reports with the Sales Analysis / Price Ranges / Annual Index tables.

---

## (b) Section-location verdict

### The gate ALL six CMA parsers share — `looksLikeCmaInfo()`

[app/Services/MarketReports/Parsers/AbstractCmaInfoParser.php:163-186](app/Services/MarketReports/Parsers/AbstractCmaInfoParser.php#L163-L186):

```php
protected function looksLikeCmaInfo(string $text): bool
{
    if ($text === '') return false;
    if (stripos($text, 'CMA Info') !== false) return true;
    if (stripos($text, 'CMAinfo') !== false) return true;
    if (preg_match('/\bCMA\s*-\s*/i', $text)) return true;
    if (stripos($text, 'Sectional Title sales') !== false) return true;
    if (stripos($text, 'Sectional Title Scheme Owners List') !== false) return true;
    if (stripos($text, 'ST Residential Sales Analysis') !== false) return true;
    if (stripos($text, 'Residential sales within') !== false) return true;
    if (stripos($text, 'Vacant land sales within') !== false) return true;
    if (stripos($text, 'PROPERTY INFORMATION') !== false
        && stripos($text, 'SALE INFORMATION') !== false) return true;
    return false;
}
```

Every CMA parser opens its `canParse()` with the same guard:

```php
if (!$this->looksLikeCmaInfo($text)) return ParserConfidence::none('no CMA Info signature');
```

(Quoted from `CmaInfoMedianSalesAnalysisParser.php:41`, identical lines exist in the other 5 CMA parsers — confirmed by `grep -l "looksLikeCmaInfo" app/Services/MarketReports/Parsers/*.php` listing all six.)

**Verdict on Freeland Park's headings:**

Per the context, Freeland Park's section headings are:

```
Residential Sales Analysis        (Amanzimtoti: "ST Residential Sales Analysis")
Residential Price Ranges          (Amanzimtoti: "ST Residential Price Ranges")
Annual Residential Index          (Amanzimtoti: "ST Annual Residential Index")
Price Ranges                      (Amanzimtoti: "ST Price Ranges")
```

Run those against the markers in `looksLikeCmaInfo()`:

| Marker | Matches Freeland Park? |
|---|---|
| `CMA Info` (wordmark) | UNRESOLVED — depends on whether the document carries the wordmark in extractable PDF text. The comment at line 147 says "real reports HFC uses don't carry that wordmark in extractable PDF text", so likely **no**. |
| `CMAinfo` | Same — likely no. |
| `\bCMA\s*-\s*/i` (regex for "CMA - ") | UNRESOLVED — depends on whether Freeland Park has a "CMA - …" section header somewhere. |
| `Sectional Title sales` | **No** — the missing "ST" prefix indicates this is a residential/freehold report, not sectional title. |
| `Sectional Title Scheme Owners List` | **No** — same. |
| `ST Residential Sales Analysis` | **No** — this is the one Amanzimtoti matches via the "ST " prefix; Freeland Park lacks it. |
| `Residential sales within` | **No** — that's the Vicinity Sales family (300m / 2000m). The Median Sales Analysis report has "Residential Sales Analysis", not "sales within". |
| `Vacant land sales within` | **No**. |
| `PROPERTY INFORMATION` + `SALE INFORMATION` | **No** — that's the Property Valuation block. |

**If Freeland Park has none of `CMA Info`, `CMAinfo`, or `CMA - ` in the extracted text, every check above is `no` and `looksLikeCmaInfo()` returns `false`.**

When `looksLikeCmaInfo()` returns false:

- `CmaInfoMarketAnalysisParser::canParse()` line 40 → returns `ParserConfidence::none`
- `CmaInfoMedianSalesAnalysisParser::canParse()` line 41 → `ParserConfidence::none`
- `CmaInfoPropertyValuationParser::canParse()` → `ParserConfidence::none`
- `CmaInfoSectionalTitleSalesParser::canParse()` → `ParserConfidence::none`
- `CmaInfoSchemeOwnersListParser::canParse()` → `ParserConfidence::none`
- `CmaInfoVicinitySaleParser::canParse()` lines 64-66 → `ParserConfidence::none`
- `GenericFallbackParser::canParse()` line 32 → `ParserConfidence(0.1, 'generic fallback — always available')`

`detect()` returns `GenericFallbackParser`. The report is filed with `report_type_id = 'other'` and parsed as Generic Fallback.

### The Median Sales Analysis parser's *secondary* section gate

[CmaInfoMedianSalesAnalysisParser.php:49](app/Services/MarketReports/Parsers/CmaInfoMedianSalesAnalysisParser.php#L49):

```php
if ($this->findHeader($text, 'Median Sales Analysis') || $this->findHeader($text, 'ST Residential Sales Analysis')) {
    $score += 0.5;
    $reasons[] = 'Median Sales Analysis header';
}
```

`findHeader()` is `stripos($haystack, $needle) !== false` ([AbstractCmaInfoParser.php:140-143](app/Services/MarketReports/Parsers/AbstractCmaInfoParser.php#L140-L143)) — case-insensitive substring search.

Freeland Park's heading "Residential Sales Analysis" does **not** contain `Median Sales Analysis` and does **not** contain `ST Residential Sales Analysis`. So even if `looksLikeCmaInfo()` somehow returned true (via `CMA - ` say), this scorer would NOT add 0.5. The parser's max possible score becomes 0.3 (page count) + 0.1 (annual change header) + 0.1 (year × sales row) = **0.5 ceiling**. Still beats GenericFallback's 0.1, but only if `looksLikeCmaInfo()` admits the document at all.

---

## (c) Row-parse + empty-cell behaviour

### Sales Analysis triplet (per-year sub-block)

[CmaInfoMedianSalesAnalysisParser.php:122-153](app/Services/MarketReports/Parsers/CmaInfoMedianSalesAnalysisParser.php#L122-L153):

```php
if (preg_match_all('/(?:^|\n)(?<year>20\d{2})(?<body>.*?)(?=(?:\n20\d{2})|\nPlease|\Z)/su', $text, $blocks, PREG_SET_ORDER)) {
    foreach ($blocks as $block) {
        $year = (int) $block['year'];
        if ($year < 2000 || $year > 2099) continue;
        $body = (string) $block['body'];

        // Bounded "thousands group" pattern so the median price
        // can't bleed into the change% column.
        if (!preg_match_all('/(?<c>\d{1,5})\s+R\s*(?<m>\d{1,3}(?:[\s,]\d{3}){0,3})\s+(?<chg>-?\d{1,3}\.\d{1,2})\s*%/u', $body, $triplets, PREG_SET_ORDER)) {
            continue;     // ← row skipped silently when no triplet matches
        }
        …
        $points[] = ['metric_key' => 'suburb_median_price_year', 'metric_value_numeric' => $this->parsePrice($t1['m']), …];
        $points[] = ['metric_key' => 'suburb_sales_count_year',  'metric_value_numeric' => (float) $t1['c'],          …];
        $points[] = ['metric_key' => 'suburb_annual_change_pct', 'metric_value_numeric' => (float) $t1['chg'],        …];
        …
    }
}
```

What the regex requires per triplet:
- `(?<c>\d{1,5})` — sales count (≥ 1 digit)
- `R\s*(?<m>\d{1,3}(?:[\s,]\d{3}){0,3})` — `R` literal then the median price (≥ 1 digit)
- `(?<chg>-?\d{1,3}\.\d{1,2})\s*%` — change % with a **mandatory decimal point** and **1-2 fractional digits**

**Freeland Park 2026 row (from context A):** count=0, median=EMPTY, change="0%".

- `(?<c>\d{1,5})` matches `0` ✓
- `R\s*(?<m>\d{1,3}…)` requires at least one digit after `R`. With median=EMPTY there is no `R 123 456` token — **fails**.
- `(?<chg>-?\d{1,3}\.\d{1,2})\s*%` requires `0.00%` shape; Freeland's `0%` has no decimal — **also fails**.

Either failure alone is enough: `preg_match_all` returns 0 triplets for the 2026 block, the `if (!preg_match_all(...))` falls into `continue` at line 131, and the 2026 row is **silently dropped**. Other years' rows are unaffected.

### Price Ranges (per-year low/median/high/max)

[CmaInfoMedianSalesAnalysisParser.php:161-163](app/Services/MarketReports/Parsers/CmaInfoMedianSalesAnalysisParser.php#L161-L163):

```php
$priceTok    = 'R\s*(\d{1,3}(?:[\s,]\d{3}){0,3})';
$rangePattern = '/\b(?<year>20\d{2})\s+(?<count>\d{1,4})\s+' . $priceTok
              . '\s+' . $priceTok . '\s+' . $priceTok . '\s+' . $priceTok . '/u';
if (preg_match_all($rangePattern, $text, $rangeMatches, PREG_SET_ORDER)) {
    foreach ($rangeMatches as $rm) { … }
}
```

What the regex requires: `<year> <count> R<low> R<median> R<high> R<max>` — five tokens after the year. Every `R` is mandatory.

**Freeland Park 2026 Price Ranges (from context A):** the row is just `2026` (all five value cells empty).

Five mandatory `R<digits>` tokens absent → the regex doesn't match the 2026 row. `preg_match_all` returns matches for the OTHER years' rows. The 2026 row is silently dropped at the regex layer — never reaches the foreach body.

### Cast / NULL safety in the row-write phase

When a row IS matched, the parser hands a `$points[]` dictionary to the job. The job does:

[ParseMarketReportJob.php:124-148](app/Jobs/MarketReports/ParseMarketReportJob.php#L124-L148):

```php
foreach ($result->dataPoints as $dp) {
    try {
        MarketDataPoint::create([
            'agency_id'            => $report->agency_id,
            'report_id'            => $report->id,
            …
            'metric_value_numeric' => $dp['metric_value_numeric'] ?? null,
            'metric_value_date'    => $dp['metric_value_date']    ?? null,
            'metric_value_string'  => $dp['metric_value_string']  ?? null,
            'metric_date'          => $dp['metric_date']          ?? $today,
            …
        ]);
    } catch (Throwable $e) {
        Log::warning('ParseMarketReportJob: data point write failed', […]);
    }
}
```

Each row write is in its own try/catch — a single row failure logs a warning and continues. No `(int)` or `(float)` cast on a possibly-empty raw cell happens at the JOB layer (the cast already happened in the parser body — `(float) $t1['chg']` at line 143 — but it only runs when `preg_match_all` matched, so `$t1['chg']` is guaranteed non-empty by the regex contract).

**Conclusion on empty cells:** the regex contract ensures the cast only runs on matched groups. The `0%` (no decimal) failure or the empty-cell failure simply skips the row — no PHP error, no DB error, no exception bubbling up.

---

## (d) Transaction / rollback behaviour

[ParseMarketReportJob.php:72-252](app/Jobs/MarketReports/ParseMarketReportJob.php#L72-L252) shows the structure:

```
try {                                                            // line 72  outer try
    $parser = …resolveByKey or detect…                          // 73-87
    $result = $parser->parse(…);                                 // line 91

    DB::transaction(function () use ($result, $report) {         // line 80 → 192
        // subject metadata write
        foreach ($result->dataPoints as $dp) {
            try { MarketDataPoint::create(…); }                  // 125-147
            catch (Throwable $e) { Log::warning(…); }            // ← per-row safety net
        }
        foreach ($result->compRows as $row) {
            try { MarketReportCompRow::create(…); }              // 152-163
            catch (Throwable $e) { Log::warning(…); }
        }
        foreach ($result->schemeOwners as $owner) {
            try { SchemeOwner::updateOrCreate(…); }              // 168-190
            catch (Throwable $e) { Log::warning(…); }
        }
    });                                                          // line 192 ← end transaction

    // address back-propagation, parse_status=PARSED, event fire …
} catch (Throwable $e) {                                         // line 242 outer catch
    $report->update([
        'parse_status'        => MarketReport::PARSE_FAILED,
        'parse_completed_at'  => now(),
        'raw_extracted_json'  => ['error' => $e->getMessage()],
    ]);
    Log::error('ParseMarketReportJob: parse failed', […]);
}
```

Behavioural takeaways:

- **Per-row failure** (a single `MarketDataPoint::create` throwing) is caught INSIDE the transaction. The exception does NOT escape the transaction closure → the transaction commits. Other rows continue. `parse_status` ends as `PARSED`.
- **Parser throw** (a `preg_match` throwing PREG_BAD_UTF8_ERROR, a `$parser->parse()` raising, anything OUTSIDE the inner try/catch but INSIDE the outer try) → outer catch fires → `parse_status = FAILED`. THIS is the only path that produces "total failure with a FAILED stamp".
- **Detection picks GenericFallback** → `parse()` returns `dataPoints: [], compRows: [], schemeOwners: [], rawJson: ['parser_note' => 'Generic fallback — no structured extraction performed.', …]` → DB transaction loops over empty arrays → `data_points_count = 0`, `parse_status = PARSED`. THIS is the "silent zero-rows" path.

The Freeland Park observed symptom — "appears to be total failure, zero rows" — matches the **GenericFallback silent-zero path** (parse_status=PARSED, count=0, parser_note='Generic fallback'). It does NOT match the FAILED-status path; a FAILED parse would carry `raw_extracted_json.error` text and the show view would render an error banner.

---

## (e) NULL-constraint check

[database/migrations/2026_05_21_120004_create_market_data_points_table.php:30-65](database/migrations/2026_05_21_120004_create_market_data_points_table.php#L30-L65):

```php
$table->foreignId('agency_id')->constrained('agencies');                                // NOT NULL
$table->foreignId('report_id')->nullable()->…->nullOnDelete();                          // nullable
$table->foreignId('tracked_property_id')->nullable()->…;                                // nullable
$table->string('suburb_normalised', 100)->nullable();                                   // nullable
$table->string('town', 100)->nullable();                                                // nullable
$table->string('metric_key', 100)…;                                                     // NOT NULL
$table->decimal('metric_value_numeric', 15, 2)->nullable();                             // nullable
$table->date('metric_value_date')->nullable();                                          // nullable
$table->text('metric_value_string')->nullable();                                        // nullable
$table->date('metric_date')…;                                                           // NOT NULL
…
$table->string('source_type', 50)…;                                                     // NOT NULL
$table->string('source_ref', 200)->nullable();                                          // nullable
```

The NOT NULL columns relevant to empty-cell rows are:

- `metric_key` — the job supplies `$dp['metric_key'] ?? 'unknown'` (line 132), so never NULL.
- `metric_date` — the job supplies `$dp['metric_date'] ?? $today` (line 136), so never NULL.
- `source_type` — hardcoded `'market_report'` (line 138).

**Verdict:** an empty cell does NOT produce a NULL-constraint violation because the row only reaches `MarketDataPoint::create` AFTER `preg_match_all` matched — and the match contract guarantees the value tokens exist. Empty cells fail the regex and the row is skipped at the parser layer, never reaching the DB.

---

## (f) Root-cause verdict — A, B, or both?

### Verdict: **B is the cause of total failure. A causes a silent partial-data loss that would compound after a B fix.**

**Proof chain for B (the missing "ST " prefix):**

1. Every CMA parser opens `canParse()` with `if (!$this->looksLikeCmaInfo($text)) return ParserConfidence::none('no CMA Info signature');`. Source: e.g. [CmaInfoMedianSalesAnalysisParser.php:41](app/Services/MarketReports/Parsers/CmaInfoMedianSalesAnalysisParser.php#L41), [CmaInfoMarketAnalysisParser.php:40](app/Services/MarketReports/Parsers/CmaInfoMarketAnalysisParser.php#L40), and four more.
2. `looksLikeCmaInfo()` ([AbstractCmaInfoParser.php:163-186](app/Services/MarketReports/Parsers/AbstractCmaInfoParser.php#L163-L186)) recognises the suburb-history report family ONLY via the `'ST Residential Sales Analysis'` marker (line 175). There is no marker for non-`ST` variants like "Residential Sales Analysis" (no ST), "Residential Price Ranges", or "Annual Residential Index".
3. Per the context, Freeland Park has none of those `ST`-prefixed strings, and the document is a residential-freehold suburb-history report (not sectional, not vicinity, not property-valuation, not scheme-owners). Unless the document independently carries `CMA Info` / `CMAinfo` / `CMA - ` wordmarks in extractable PDF text (UNRESOLVED — would need a `pdftotext -layout` dump of the actual PDF to verify), `looksLikeCmaInfo()` returns false.
4. All six CMA parsers return `ParserConfidence::none` (score 0). `GenericFallbackParser` returns `ParserConfidence(0.1, …)` ([GenericFallbackParser.php:32](app/Services/MarketReports/Parsers/GenericFallbackParser.php#L32)). The registry picks the highest score — `GenericFallbackParser` wins.
5. `GenericFallbackParser::parse()` returns `dataPoints: []` with `'parser_note' => 'Generic fallback — no structured extraction performed.'` ([GenericFallbackParser.php:36-48](app/Services/MarketReports/Parsers/GenericFallbackParser.php#L36-L48)).
6. The job writes 0 rows, stamps `parse_status = PARSED`, `data_points_count = 0`, `report_type_id = 11` ('other'). Exactly the observed symptom.

**Proof chain for A (empty cells in 2026 rows):**

1. Sales Analysis triplet regex at [CmaInfoMedianSalesAnalysisParser.php:130](app/Services/MarketReports/Parsers/CmaInfoMedianSalesAnalysisParser.php#L130) requires `R\s*<digits>` + `<int>.<int>%`. Freeland Park 2026 has median=EMPTY (no `R<digits>` token) AND change="0%" (no decimal point). Regex fails to match the 2026 row.
2. `if (!preg_match_all(…)) continue;` at line 131 → 2026 row is silently dropped at the parser layer.
3. Price Ranges regex at [CmaInfoMedianSalesAnalysisParser.php:161-163](app/Services/MarketReports/Parsers/CmaInfoMedianSalesAnalysisParser.php#L161-L163) requires five `R<digits>` tokens after the year. Freeland Park 2026 has all five empty — regex doesn't match.
4. Neither path throws or fails the job. They produce partial extraction: every year EXCEPT 2026 lands in the database.

**Order of fire:** B fires first. The detect() loop happens at upload time; if B blocks (looksLikeCmaInfo returns false), the parser never runs, and A's empty-cell behaviour is moot. If we fix B (add the non-`ST` variant to looksLikeCmaInfo + to the Median Sales Analysis parser's section scorer), A then becomes the next bug: Freeland Park would import, but its 2026 row would be silently dropped. That's a partial-data correctness bug masquerading as "complete import" — worse for trust than a full failure.

**Recommendation for the eventual fix (DO NOT IMPLEMENT THIS PASS):** address both. A fix to B alone leaves a silent 2026-row dropper in place.

---

## (g) Bug-class sibling list

Every spot in the parser family where a hardcoded `ST ` marker or a regex requiring decimal-point change% or full `R<price>` tokens would skip or block residential-freehold variants and empty-cell rows. Enumerated only — no fixes:

### Heading-variant sibling cases

| File | Line | Pattern | Class-of-bug |
|---|---|---|---|
| [AbstractCmaInfoParser.php](app/Services/MarketReports/Parsers/AbstractCmaInfoParser.php#L175) | 175 | `stripos($text, 'ST Residential Sales Analysis')` | Recognises only the ST-prefixed sectional variant. A residential-freehold suburb-history report fails this marker. **Primary root cause.** |
| [CmaInfoMedianSalesAnalysisParser.php](app/Services/MarketReports/Parsers/CmaInfoMedianSalesAnalysisParser.php#L49) | 49 | `findHeader(text, 'Median Sales Analysis') \|\| findHeader(text, 'ST Residential Sales Analysis')` | Section scorer awards +0.5 only for those two heading variants. A document headed "Residential Sales Analysis" (no `ST`) misses the boost. |
| [AbstractCmaInfoParser.php](app/Services/MarketReports/Parsers/AbstractCmaInfoParser.php#L173-L174) | 173-174 | `'Sectional Title sales'`, `'Sectional Title Scheme Owners List'` | Long-form sectional-title markers; same shape of "spelled out for one variant only" — if CMA Info ever ships a Sectional Title Sales Analysis that drops the "Sectional Title" wordmark and uses "ST " short-form alone (or vice versa), these would fail. |

### Row-parsing regex sibling cases (decimal-point required / full-row required)

| File | Line | Pattern | Class-of-bug |
|---|---|---|---|
| [CmaInfoMedianSalesAnalysisParser.php](app/Services/MarketReports/Parsers/CmaInfoMedianSalesAnalysisParser.php#L130) | 130 | `(?<chg>-?\d{1,3}\.\d{1,2})\s*%` | Mandates a decimal point in the change% — "0%" (Freeland) is dropped. Any year reporting an exact integer percent (`5%`, `-2%`) also fails. |
| [CmaInfoMedianSalesAnalysisParser.php](app/Services/MarketReports/Parsers/CmaInfoMedianSalesAnalysisParser.php#L130) | 130 | `R\s*(?<m>\d{1,3}(?:[\s,]\d{3}){0,3})` | Requires at least one digit after `R`. An empty median cell breaks the whole triplet. |
| [CmaInfoMedianSalesAnalysisParser.php](app/Services/MarketReports/Parsers/CmaInfoMedianSalesAnalysisParser.php#L161-L163) | 161-163 | Range pattern requires year + count + 4 mandatory `R<digits>` tokens | A year row with ANY one of the 5 value cells empty is dropped. Freeland's 2026 row (all 5 empty) is dropped silently — but a row with ONE empty cell (e.g. missing only `max`) would be ALSO dropped, silently losing the other 3 valid values too. |
| [CmaInfoMedianSalesAnalysisParser.php](app/Services/MarketReports/Parsers/CmaInfoMedianSalesAnalysisParser.php#L57) | 57 | `\b20\d{2}\s+\d+\s+R[\s\d,]+` | The scoring confirmer at the canParse layer — same decimal-point/R-required shape. |

### Other parsers' `canParse()` signatures (worth scanning by the same lens, not currently observed to fire on Freeland Park because B blocks first)

`CmaInfoMarketAnalysisParser.php:48-63` — heading markers `Market Analysis`, `Subject Property`, `Vicinity Sales`, `Comparable Sales`, `12 Month`. None are `ST`-prefixed, so this parser would survive the B style of variant. But it's gated by the same `looksLikeCmaInfo()` so B still blocks Freeland Park there too.

`CmaInfoPropertyValuationParser.php`, `CmaInfoSectionalTitleSalesParser.php`, `CmaInfoSchemeOwnersListParser.php`, `CmaInfoVicinitySaleParser.php` — same `looksLikeCmaInfo()` gate. Each has its own additional positive markers; none are likely matches for a residential suburb-history PDF.

---

## (h) Open questions for Johan to decide

### Q1 — What "ST " means in CMA Info exports

The code labels the Median Sales Analysis report's heading variant as `'ST Residential Sales Analysis'`, and `looksLikeCmaInfo()` matches that exact phrase. The codebase doesn't define what `ST` is in a comment. **My read:** `ST` = Sectional Title. Amanzimtoti's report is the Sectional Title variant of the suburb-history report; Freeland Park's is the Residential Freehold variant. Same parser logic should apply (the row layouts are identical per the context), just the heading prefix differs. **UNRESOLVED — Johan confirm.**

### Q2 — Should the fix add a generic `Residential Sales Analysis` marker, or distinguish ST vs non-ST variants?

Option (i): broaden `looksLikeCmaInfo()` to accept `Residential Sales Analysis` too. Simplest; treats both variants as one parser case. Downside — any unrelated PDF carrying the phrase "Residential Sales Analysis" would now be admitted into the CMA family detection (the parser's own canParse scoring still has to push score above 0.1 to win against GenericFallback, so this isn't a huge risk; but worth deciding).
Option (ii): add a separate `Residential Sales Analysis` marker AND a paired secondary positive marker that's specific to the suburb-history report family (e.g. `Annual Residential Index` or `Price Ranges` co-occurring). Tighter; less risk of false positives.
Option (iii): treat ST vs non-ST as TWO distinct report-type keys. Heavier; needs a new `market_report_types` row and the per-key parser class might fork. Probably overkill if the row layouts truly are identical.

### Q3 — How aggressive should the empty-cell recovery be?

If we fix B and Freeland Park imports, the 2026 row will still be silently dropped because of the decimal-point-required regex and the all-mandatory-R range regex. Options:

(i) **Strict** (current behaviour) — drop incomplete rows entirely. Clean DB, no half-rows.
(ii) **Lenient triplet** — relax the change% regex to accept `0%` / `5%` (integer percent). Recovers the count + median when those exist; still drops rows where the median itself is missing.
(iii) **Year-only rows for incomplete data** — write a row with `metric_value_numeric = NULL` and a sentinel `metric_key = 'suburb_sales_count_year'` + `metric_value_numeric = 0` for the count. Loses no year of data but introduces null-medians that downstream consumers must handle.

The right answer depends on what downstream code does with the data — if charts skip null medians cleanly, (iii) gives the most fidelity. If they crash on null, (i) is safer. **UNRESOLVED.**

### Q4 — Should a parse that lands on GenericFallback flag itself for re-detect?

The Re-parse endpoint shipped earlier this session clears `report_type_id` and re-detects. But there's no signal in the index/show UI that the original detection LANDED on GenericFallback — that's a "0 data points + parser_note='Generic fallback'" state that can only be discovered by opening the report. After Freeland Park lands on GenericFallback, the user has no breadcrumb that says "this might have been mis-detected". A status-banner on the show view "Detected as generic fallback — try Re-parse after the next parser ships" would close the loop. **UNRESOLVED, scope for a later prompt.**

### Q5 — Is `pdftotext` actually producing the strings as written in the PDF?

The investigation assumes the extracted text mirrors the headings the user sees in the PDF viewer. CMA Info renders some headings as embedded images (which `pdftotext` cannot read at all) — though we have no evidence of that in this report family. To be 100% certain B is the cause, the verification step (when fixing) should run `pdftotext -layout` on the Freeland Park PDF and confirm the headings appear in extracted text as `Residential Sales Analysis` (and confirm `looksLikeCmaInfo()` indeed returns false). **Worth doing as the first step of the fix prompt.**

---

## Closing — read-only verification

Exactly one new file produced: this report. `git status --short` output is in the chat reply.
