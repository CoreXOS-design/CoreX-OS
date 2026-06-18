<?php

declare(strict_types=1);

namespace App\Support\Presentations;

/**
 * Comparable-sale display-label builder.
 *
 * Pre-fix: review screen + PDF + map tooltips read $raw['address']
 * directly. When sectional CMA imports landed with scheme+section but
 * no street address (a common pattern — the PDF table lists scheme as
 * a column header rather than per-row), every consumer rendered "—",
 * leaving agents and sellers unable to identify which property was
 * being compared.
 *
 * Single source of truth for the identifier label. Falls through a
 * chain so the label is NEVER blank:
 *
 *   1. SECTIONAL identity — scheme_name + section both present →
 *      "Unit <section>, <scheme>" ("Unit 7, Pumula"). This takes
 *      PRIORITY over the street address: the street on a sectional row
 *      is the scheme's shared street (non-unique across units — every
 *      unit of "Suntide Cabanas" sits at "18 Duke Road"), so the
 *      Unit+complex pair is the identifying label and the street is not.
 *      Unit-first to read identically to the subject's own identity,
 *      which Property::buildDisplayAddress() renders Unit-first
 *      ("Unit 17, Brock Manor, Margate"). Gated on the same
 *      data-presence test used at AnalysisDataService.php:163 (both
 *      structured columns filled) rather than the heuristic title_type.
 *   2. raw.address (non-empty)                — street address (freehold,
 *      and sectional rows that carry a unique street but lack a usable
 *      scheme+section pair so nothing regresses)
 *   3. raw.scheme_name (alone)                — sectional scheme, no section
 *   4. raw.section_number + suburb            — bare section ("Section 8, Uvongo")
 *   5. suburb                                 — fallback to locality
 *   6. "Comp #<id>"                           — absolute floor
 *
 * Section number may appear under either of two keys in raw_row_json
 * depending on the parser:
 *   - section_number  (MicSnapshotHydrator / CmaInfoVicinitySaleParser)
 *   - section_no      (doc_extract_v1 family)
 * Both are checked.
 *
 * Call sites (single source of truth — all three must produce the
 * same label for the same comp):
 *   - AnalysisDataService::compileComparableSales (analysis tab +
 *     the PDF's Comparable Sales table)
 *   - PresentationPdfService spatial-view marker tooltips (per-row
 *     call at the SVG marker title site)
 *   - PresentationReviewController::show — the review-screen comp
 *     table (added after the parity gap that left sectional comps
 *     with no street address rendering blank on the review screen
 *     while populating correctly on the PDF + analysis tab).
 */
final class CompLabel
{
    /**
     * Build the display label for a comparable sale.
     *
     * @param  array<string, mixed>|null  $raw     decoded raw_row_json
     * @param  ?string                    $suburb  presentation_sold_comps.suburb (last-resort locality)
     * @param  int|string|null            $id      comp row id for the floor case
     */
    public static function build(?array $raw, ?string $suburb = null, int|string|null $id = null): string
    {
        $raw = $raw ?? [];

        $section = self::sectionToken($raw);
        $scheme  = isset($raw['scheme_name']) ? trim((string) $raw['scheme_name']) : '';
        $address = isset($raw['address']) ? trim((string) $raw['address']) : '';

        // Sectional identity takes priority over the street address (see
        // class docblock). Both structured columns must be filled — this
        // mirrors the subject's own gate at AnalysisDataService.php:163.
        if ($scheme !== '' && $section !== '') {
            return "Unit {$section}, {$scheme}";
        }

        if ($address !== '') {
            return $address;
        }

        if ($scheme !== '') {
            return $scheme;
        }

        $suburbStr = is_string($suburb) ? trim($suburb) : '';
        if ($section !== '') {
            return $suburbStr !== '' ? "Section {$section}, {$suburbStr}" : "Section {$section}";
        }

        if ($suburbStr !== '') {
            return $suburbStr;
        }

        return $id !== null ? "Comp #{$id}" : 'Unidentified comp';
    }

    /**
     * Extract section identifier from either of the known raw_row_json
     * key spellings. Empty string when neither carries a value.
     */
    private static function sectionToken(array $raw): string
    {
        foreach (['section_number', 'section_no'] as $k) {
            if (isset($raw[$k]) && trim((string) $raw[$k]) !== '') {
                return trim((string) $raw[$k]);
            }
        }
        return '';
    }
}
