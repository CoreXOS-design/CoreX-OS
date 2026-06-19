<?php

declare(strict_types=1);

namespace App\Services\MarketReports\Parsers;

use App\Domain\Presentation\TextExtractionService;
use App\Models\Prospecting\TrackedProperty;
use App\Services\MarketReports\Contracts\MarketReportParser;
use App\Services\MarketReports\DTOs\ParserConfidence;
use Illuminate\Support\Facades\Log;

/**
 * Shared scaffolding for the CMA Info family of parsers.
 *
 * Subclasses override canParse()/parse() and reuse:
 *   - extractText()          — pdftotext via the existing service
 *   - normaliseSuburb()      — same as TrackedProperty::normaliseSuburb
 *   - parsePrice()           — strips R + comma + spaces from "R 1,234,567"
 *   - parseDate()            — flexible Carbon parse with null-on-fail
 *   - findHeader()           — case-insensitive substring check
 *   - looksLikeCmaInfo()     — common gate ("CMA Info", "CMAInfo") + page count band
 *
 * On Windows local without pdftotext, extractText() returns ''. Each subclass'
 * canParse() must handle empty text and return ParserConfidence::none() so
 * the GenericFallbackParser ends up winning.
 *
 * Spec: .ai/specs/mic-complete-spec.md §8.
 */
abstract class AbstractCmaInfoParser implements MarketReportParser
{
    public function __construct(
        protected readonly TextExtractionService $textExtractor,
    ) {}

    /**
     * Returns raw pdftotext output (preferring -layout mode so column-based
     * CMA Info reports survive extraction) or '' when extraction is
     * unavailable / fails. NEVER throws.
     *
     * Falls back to the standard TextExtractionService (no -layout) when the
     * pdftotext binary is unavailable on PATH.
     */
    protected function extractText(string $filePath): string
    {
        if (!is_file($filePath)) return '';

        // -layout preserves table columns — essential for CMA Info reports
        // where labels and values are positioned side-by-side, not stacked.
        $layout = $this->tryPdftotextLayout($filePath);
        if ($layout !== '') {
            return $layout;
        }

        return $this->textExtractor->extractText($filePath, 'application/pdf');
    }

    private function tryPdftotextLayout(string $absolutePath): string
    {
        $whereCmd = PHP_OS_FAMILY === 'Windows' ? 'where pdftotext 2>NUL' : 'command -v pdftotext 2>/dev/null';
        $exists = @shell_exec($whereCmd);
        if (empty($exists)) return '';

        try {
            $escaped = escapeshellarg($absolutePath);
            $output  = @shell_exec("pdftotext -layout {$escaped} -");
            if (!is_string($output)) return '';

            // pdftotext emits U+FFFD (replacement char) for glyphs it cannot
            // decode (e.g. ° / ²). The bytes look like 0xEF 0xBF 0xBD which
            // IS valid UTF-8 — but pdftotext on Windows sometimes emits
            // lone 0xEF or 0xBD bytes that break /u-modifier regex matches.
            // Strip invalid UTF-8 silently so subsequent /u regex calls
            // don't throw preg_last_error 4 (PREG_BAD_UTF8_ERROR).
            $cleaned = @mb_convert_encoding($output, 'UTF-8', 'UTF-8');
            return trim(is_string($cleaned) ? $cleaned : $output);
        } catch (\Throwable) {
            return '';
        }
    }

    protected function normaliseSuburb(?string $s): ?string
    {
        return TrackedProperty::normaliseSuburb($s);
    }

    /**
     * Resolve the suburb to use for THIS parse pass.
     *
     * Pre-fix bug: every parser computed `$suburb = normaliseSuburb($report->source_suburb)`
     * once at parse-entry and bound that into every comp row. When the upload
     * path failed to capture source_suburb (most common case — agent uploads
     * without filling the field, filename auto-detect fails), every row went
     * to DB with `suburb_normalised = NULL`, killing every downstream comp
     * filter.
     *
     * Fix: parsers pass any in-PDF suburb candidates they detected (e.g.
     * vicinity's "Limited to. MARGATE BEACH", sectional's subject_address
     * trailing token, scheme-owners' header suburb) and we pick the best
     * available source. Priority:
     *
     *   1. report.source_suburb           — user-explicit, never clobber
     *   2. parser-detected candidates     — first non-empty in caller's order
     *   3. null                           — caller decides
     *
     * Returns a [raw, normalised] pair:
     *   - $raw is the source-detected ORIGINAL case ("Uvongo Beach"),
     *     promoted to subject_meta['source_suburb'] so the job's existing
     *     backfill writes it to the report row.
     *   - $normalised is the lowercase form ($this->normaliseSuburb on raw),
     *     used as the comp-row suburb_normalised value.
     *
     * SuburbMatcher (used at match time) bridges "Uvongo Beach" ↔ "uvongo"
     * downstream — so the PERSISTED form stays high-fidelity to what the
     * PDF said.
     *
     * @param  list<?string>  $candidates  parser-detected suburb candidates in priority order
     * @return array{raw: ?string, normalised: ?string}
     */
    protected function resolveReportSuburb(?string $reportSourceSuburb, array $candidates): array
    {
        $explicit = is_string($reportSourceSuburb) ? trim($reportSourceSuburb) : '';
        if ($explicit !== '') {
            return ['raw' => $explicit, 'normalised' => $this->normaliseSuburb($explicit)];
        }
        foreach ($candidates as $candidate) {
            $trimmed = is_string($candidate) ? trim($candidate) : '';
            if ($trimmed !== '') {
                return ['raw' => $trimmed, 'normalised' => $this->normaliseSuburb($trimmed)];
            }
        }
        return ['raw' => null, 'normalised' => null];
    }

    /** Strip "R", commas, whitespace from a price string. Returns float|null. */
    protected function parsePrice(?string $raw): ?float
    {
        if ($raw === null || $raw === '') return null;
        $digits = preg_replace('/[^\d.]/', '', (string) $raw);
        if ($digits === '' || $digits === '.') return null;
        return (float) $digits;
    }

    /**
     * Phase 3e A1 — parse a captured price and reject implausible outliers.
     *
     * Default sanity window 50,000 ≤ price ≤ 50,000,000 catches the most
     * common parser failure mode (column bleed concatenating digits across
     * adjacent columns, e.g. "R 810 000 18.25%" → R 81,000,018). Out-of-range
     * captures are logged with the raw matched segment so we can audit and
     * tighten the regex later. Returns null when raw is empty or out of band.
     */
    protected function parsePriceBounded(
        ?string $raw,
        string $field,
        ?string $matchedSegment = null,
        int $min = 50_000,
        int $max = 50_000_000,
    ): ?int {
        $val = $this->parsePrice($raw);
        if ($val === null) return null;
        $int = (int) $val;
        if ($int < $min || $int > $max) {
            Log::warning('CMA parser price out of range; dropping value.', [
                'field'   => $field,
                'parser'  => static::class,
                'raw'     => $raw,
                'value'   => $int,
                'min'     => $min,
                'max'     => $max,
                'segment' => $matchedSegment !== null ? mb_substr($matchedSegment, 0, 200) : null,
            ]);
            return null;
        }
        return $int;
    }

    protected function parseDate(?string $raw): ?string
    {
        if (!$raw) return null;
        try {
            return \Carbon\Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function findHeader(string $haystack, string $needle): bool
    {
        return stripos($haystack, $needle) !== false;
    }

    /**
     * Heuristic shared by all CMA Info variants. The original gate looked
     * only for "CMA Info" / "CMAinfo" branding, but the real reports HFC
     * uses don't carry that wordmark in extractable PDF text. We broaden
     * to recognise the actual structural signatures we see across the
     * five sample types:
     *
     *   - "CMA - <something>" section headers (Property Valuation, Market
     *     Analysis, Indexed Value, Comparative Market Analysis)
     *   - "Sectional Title sales" header (in-scheme + radius variants)
     *   - "Sectional Title Scheme Owners List" header
     *   - "ST Residential Sales Analysis" header (Median Sales Analysis)
     *   - "PROPERTY INFORMATION" + "SALE INFORMATION" block (Property Valuation)
     *
     * Returns true if any of these markers appear. Returns false for
     * unrelated documents so the GenericFallbackParser still wins on
     * non-CMA-Info content.
     */
    protected function looksLikeCmaInfo(string $text): bool
    {
        if ($text === '') return false;

        // Legacy wordmark — still match when the source has it.
        if (stripos($text, 'CMA Info') !== false) return true;
        if (stripos($text, 'CMAinfo') !== false) return true;

        // Structural signatures.
        if (preg_match('/\bCMA\s*-\s*/i', $text)) return true;
        if (stripos($text, 'Sectional Title sales') !== false) return true;
        if (stripos($text, 'Sectional Title Scheme Owners List') !== false) return true;
        if (stripos($text, 'ST Residential Sales Analysis') !== false) return true;
        // Vicinity Sales family — Residential freehold + Vacant land variants.
        // Added 2026-05-28 alongside CmaInfoVicinitySaleParser. Additive
        // only; existing parsers ignore these markers in their per-class
        // canParse() scoring.
        if (stripos($text, 'Residential sales within') !== false) return true;
        if (stripos($text, 'Vacant land sales within') !== false) return true;
        if (stripos($text, 'PROPERTY INFORMATION') !== false
            && stripos($text, 'SALE INFORMATION') !== false) return true;

        return false;
    }

    protected function pageCount(string $text): int
    {
        if ($text === '') return 0;
        // pdftotext separates pages with form-feed (\f).
        return substr_count($text, "\f") + 1;
    }

    /**
     * Phase 3b — coalesce row-wrapped tables back into logical single lines.
     *
     * pdftotext -layout sometimes wraps long table rows across 2-3 physical
     * lines when a scheme/address column is wider than its column width. The
     * data tokens (section + SS year + Residence + extent + date + R<price>)
     * may end up on a different line from the scheme name they belong to.
     *
     * Algorithm: walk the text top-to-bottom. When a line contains a row
     * "anchor" (a YYYY-MM-DD date + an R<digits> price + Residence in close
     * proximity), back-fill it with the previous N non-anchor lines until
     * either the previous anchor or a section break (blank line, page break,
     * "SUBJECT PROPERTY" / "COMPARATIVE PROPERTIES" header) is reached.
     *
     * Emits one coalesced row per anchor. Non-anchor lines that don't get
     * coalesced into an anchor are kept verbatim so other regex passes
     * (subject extraction, scheme headers, etc.) still work.
     *
     * Returns the rewritten text — same shape as the input, just with some
     * row blocks collapsed onto single lines.
     */
    protected function coalesceRowWraps(string $text): string
    {
        if ($text === '') return $text;

        $lines = preg_split('/\r?\n/', $text);
        if (!is_array($lines)) return $text;

        // Anchor detector: a line with EITHER a date+price pair OR a distance prefix.
        $anchorPattern = '/(?:\d{4}[\/\-]\d{2}[\/\-]\d{2}.*?R\s*[\d ,]+|R\s*[\d ,]+.*?\d{4}[\/\-]\d{2}[\/\-]\d{2}|^\s*\d{2,4}\s*m\s+[A-Z])/u';
        $boundaryHints = [
            'SUBJECT PROPERTY', 'COMPARATIVE PROPERTIES', 'PROPERTY INFORMATION',
            'SOLD PROPERTIES', 'FOR SALE', 'MUNICIPAL VALUATION', 'SALE INFORMATION',
            'ACCOMMODATION', 'CMA -', 'Page ', 'Powered by',
            'Comparative Market Analysis Value', 'Average:',
        ];

        $out = [];
        $i = 0;
        $count = count($lines);
        while ($i < $count) {
            $line = $lines[$i];
            $trim = trim($line);
            $isAnchor = $trim !== '' && preg_match($anchorPattern, $trim);

            if (!$isAnchor) {
                $out[] = $line;
                $i++;
                continue;
            }

            // Walk backwards from current to find the start of the row block.
            // Stop at: a previous anchor, a boundary header, a blank line, or
            // the start of the file.
            $start = $i;
            for ($k = $i - 1; $k >= 0; $k--) {
                $prevTrim = trim($lines[$k]);
                if ($prevTrim === '') break;
                if (preg_match($anchorPattern, $prevTrim)) break;
                if ($this->lineIsBoundary($prevTrim, $boundaryHints)) break;
                $start = $k;
                // Cap look-back to avoid runaway concatenation of headers.
                if ($i - $start >= 4) break;
            }

            if ($start === $i) {
                // No back-fill needed.
                $out[] = $line;
                $i++;
                continue;
            }

            // Replace the back-filled block with a single coalesced line.
            // Earlier lines we already pushed onto $out — pop them.
            $popCount = $i - $start;
            for ($p = 0; $p < $popCount; $p++) {
                array_pop($out);
            }

            $parts = [];
            for ($k = $start; $k <= $i; $k++) {
                $t = trim($lines[$k]);
                if ($t !== '') $parts[] = $t;
            }
            $out[] = implode(' ', $parts);
            $i++;
        }

        return implode("\n", $out);
    }

    private function lineIsBoundary(string $trim, array $hints): bool
    {
        foreach ($hints as $h) {
            if (stripos($trim, $h) !== false) return true;
        }
        // Page break separator from pdftotext.
        if ($trim === "\f" || str_contains($trim, "\f")) return true;
        return false;
    }

    /**
     * Reconstruct STACKED multi-section sectional-title sales (AT-59 follow-up
     * — pres 98 / PUMULA, reports 162 + 163).
     *
     * When one owner owns several sections of a scheme and sells the combined
     * unit, CMA Info prints the sale as a THREE-line block under
     * pdftotext -layout: the "Section [Flat] No" + "Extent" for each owned
     * section sit on the physical lines ABOVE and BELOW an "anchor" line that
     * carries the scheme name, SS no/year, sale date, price and (radius
     * report) R/m². The anchor's OWN Section and Extent columns are BLANK —
     * the data wrapped to the neighbouring lines:
     *
     *        2                    Residence     65 m²          <- section 2  / 65 m²
     *   PUMULA, DUKE ROAD ...   2  1976   ...  2026/01/11  R 500 000  R 5 747   <- anchor
     *        12                   Residence     22 m²          <- section 12 / 22 m²
     *
     * A single-line regex (one comp = one physical line) cannot see the
     * wrapped fragments, so the sale is either dropped entirely
     * (CmaInfoPropertyValuationParser comparative table) or its SS-year tail
     * is mis-read as a phantom section then nulled (CmaInfoSectionalTitleSales
     * radius table) — leaving section_number + extent_m2 NULL on a real comp.
     *
     * The CMA's stacked-vs-separate layout encodes the title-deed reality —
     * stacked = ONE title deed (sections sold together, inseparable); separate
     * rows = separate deeds. The parser mirrors that exactly: it neither merges
     * separate rows nor splits a stacked one. A stacked sale stays ONE comp,
     * preserving BOTH section numbers AND BOTH extents for display.
     *
     * The sale is ONE transaction: the price covers the combined unit and the
     * printed R/m² is price ÷ (sum of the section extents) — e.g.
     * R 500 000 ÷ (65 + 22 = 87 m²) = R 5 747. We therefore emit ONE logical
     * group per anchor, carrying:
     *   - section_label = the owned sections joined "8/14"
     *   - extent_label  = BOTH extents joined "65/22" — the agent-facing cell,
     *                     mirroring the source (NOT summed)
     *   - extent_sum    = the SUM of the section extents — the math basis for
     *                     size_m2 / R-per-m² ONLY (never shown raw)
     *   - the anchor's sale_date, SS no/year, scheme and every "R <amount>"
     *     figure in source order (the caller maps the R-figures to its own
     *     table's columns — radius: [price, r/m²]; valuation: [price, est]).
     *
     * Observed layout invariant (validated across reports 162 + 163): every
     * combined-unit sale prints exactly ONE section fragment directly above
     * and ONE directly below its anchor, so each anchor claims line i-1 and
     * line i+1. A fragment is consumed at most once, so interleaved sales of
     * adjacent schemes never steal each other's sections.
     *
     * @return list<array{
     *   sections: list<string>, section_label: string, extents: list<int>,
     *   extent_label: ?string, extent_sum: ?int, sale_date: ?string,
     *   ss_number: ?string, ss_year: ?int, r_amounts: list<int>,
     *   scheme_name: ?string
     * }>
     */
    protected function extractStackedSectionalGroups(string $text): array
    {
        if ($text === '') return [];

        $lines = preg_split('/\r?\n/', $text);
        if (!is_array($lines)) return [];
        $n = count($lines);

        $dateRe   = '#\d{4}[/\-]\d{2}[/\-]\d{2}#';
        // A section fragment: "<sec> [<ss> <yr>] Residence <ext> m²" with NO
        // sale date on the line (a dated line is a full single-line row, not a
        // wrapped fragment, and is handled by the per-parser single-line pass).
        $fragRe   = '/(?<sec>\d{1,3})\s+(?:\d{1,5}\s+\d{4}\s+)?Residence\s+(?<ext>\d{1,3}(?:[\s,]\d{3})?)\s*m/u';
        // Scheme identifier — "PUMULA, DUKE ROAD" / "FOREST WALK, FOREST ROAD".
        $schemeRe = "/([A-Z][A-Z0-9 ']{2,40}),/u";

        $isFragment = static function (string $t) use ($dateRe, $fragRe): ?array {
            if (preg_match($dateRe, $t)) return null;
            if (!preg_match($fragRe, $t, $m)) return null;
            $ext = (int) preg_replace('/\D/', '', (string) $m['ext']);
            return ['sec' => $m['sec'], 'ext' => $ext > 0 ? $ext : null];
        };
        // An anchor: a comp line carrying a sale date + price whose own
        // Section/Extent columns are blank (no inline "Residence" token —
        // that token only appears on full single-line rows and on fragments).
        $isAnchor = static function (string $t) use ($dateRe): bool {
            if (stripos($t, 'Residence') !== false) return false;
            if (!preg_match($dateRe, $t)) return false;
            return (bool) preg_match('/R\s*\d/u', $t);
        };

        $groups = [];
        $used   = [];

        for ($i = 0; $i < $n; $i++) {
            $t = trim($lines[$i]);
            if ($t === '' || !$isAnchor($t)) continue;

            $frags = [];
            if ($i - 1 >= 0 && !isset($used[$i - 1])) {
                $f = $isFragment(trim($lines[$i - 1]));
                if ($f !== null) { $frags['above'] = $f; $used[$i - 1] = true; }
            }
            if ($i + 1 < $n && !isset($used[$i + 1])) {
                $f = $isFragment(trim($lines[$i + 1]));
                if ($f !== null) { $frags['below'] = $f; $used[$i + 1] = true; }
            }
            // No wrapped fragments → not a stacked multi-section sale. Leave it
            // for the per-parser single-line pass (footers / explanatory text
            // that look anchor-like carry no adjacent fragments and exit here).
            if (empty($frags)) continue;

            $sections = [];
            $extents  = [];
            foreach (['above', 'below'] as $pos) {
                if (!isset($frags[$pos])) continue;
                $sections[] = (string) $frags[$pos]['sec'];
                if ($frags[$pos]['ext'] !== null) $extents[] = $frags[$pos]['ext'];
            }
            if ($sections === []) continue;

            // Scheme: anchor line first (radius report), else the fragment
            // lines (valuation report carries the scheme on the wrapped lines).
            $scheme = null;
            foreach ([$t, trim($lines[$i - 1] ?? ''), trim($lines[$i + 1] ?? '')] as $cand) {
                if ($cand !== '' && preg_match($schemeRe, $cand, $sm)) { $scheme = trim($sm[1]); break; }
            }

            // SS no/year — the "<ss> <yr>" pair immediately before the date.
            $ssNumber = null;
            $ssYear   = null;
            if (preg_match('#(\d{1,5})\s+(\d{4})\s+\d{4}[/\-]\d{2}[/\-]\d{2}#u', $t, $mm)) {
                $ssNumber = $mm[1];
                $ssYear   = (int) $mm[2];
            }

            $date = null;
            if (preg_match($dateRe, $t, $dm)) $date = $dm[0];

            $rAmounts = [];
            if (preg_match_all('/R\s*(\d{1,3}(?:[\s,]\d{3}){0,3})/u', $t, $rm)) {
                foreach ($rm[1] as $r) {
                    $v = (int) preg_replace('/\D/', '', (string) $r);
                    if ($v > 0) $rAmounts[] = $v;
                }
            }

            $extentSum = array_sum($extents);
            $groups[] = [
                'sections'      => $sections,
                'section_label' => implode('/', $sections),
                'extents'       => $extents,
                // Display string mirroring the source — both extents preserved
                // ("65/22"), NOT summed. The summed extent_sum is the math basis
                // for size_m2 / R-per-m² only; the agent-facing cell shows both.
                'extent_label'  => $extents !== [] ? implode('/', array_map('strval', $extents)) : null,
                'extent_sum'    => $extentSum > 0 ? $extentSum : null,
                'sale_date'     => $this->parseDate($date),
                'ss_number'     => $ssNumber,
                'ss_year'       => $ssYear,
                'r_amounts'     => $rAmounts,
                'scheme_name'   => $scheme,
            ];
        }

        return $groups;
    }

    /**
     * Used by every CMA parser to seed an extracted-address record from a
     * subject-property or comparable-sale line. The orchestrator then routes
     * these through TrackedPropertyMatchOrCreateService with
     * source_type='cmainfo'.
     */
    protected function makeAddress(array $bits): array
    {
        return array_filter([
            'street_number' => $bits['street_number'] ?? null,
            'street_name'   => $bits['street_name'] ?? null,
            'suburb'        => $bits['suburb'] ?? null,
            'town'          => $bits['town'] ?? null,
            'latitude'      => $bits['latitude'] ?? null,
            'longitude'     => $bits['longitude'] ?? null,
            'erf_number'    => $bits['erf_number'] ?? null,
            'sale_price'    => $bits['sale_price'] ?? null,
            'sale_date'     => $bits['sale_date'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    /** Subclasses override. */
    abstract public function canParse(string $filePath): ParserConfidence;
    abstract public function getReportTypeKey(): string;
}
