<?php

declare(strict_types=1);

namespace App\Support\Presentations;

use App\Models\MarketReports\MarketReport;

/**
 * Resolves the set of market_reports whose SUBJECT is the presentation's
 * subject property — i.e. the reports an analyst built FOR this property.
 *
 * This is the single source of truth for the "same-subject" exemption that
 * two consumers must agree on:
 *
 *   • MicSnapshotHydrator — comps from these reports enter the engine pool
 *     regardless of the period window or radius/suburb scope (Branch 1 of
 *     collectMatchedRows): the analyst already vetted them for this subject.
 *
 *   • CmaCoverageService::countComps — the Generate-modal coverage badge must
 *     count those same comps, or it tells the agent "0 strong comps" while the
 *     hydrator silently includes 15. The badge and the hydrator MUST use one
 *     selection rule; the hydrator is the source of truth and this helper is
 *     how the badge mirrors it.
 *
 * Resolution is by address-fragment OR suburb match against
 * market_reports.subject_address / source_suburb. It is intentionally NOT
 * date-bounded: an analyst's CMA full of 2019–2024 sectional sales is still
 * evidence FOR this subject today.
 *
 * Spec: .ai/specs/presentation-data-lineage.md §2.3 / §2.6;
 *       .ai/audits/cma-comp-gps-axis-investigation-2026-06-17.md §6.
 */
final class SubjectReportResolver
{
    /**
     * Extract street-shaped fragments from an address so a verbose
     * presentation address ("4 Ss Madeira Gardens, 4 Tucker Avenue") matches
     * the street-only subject_address a CMA Info report stores ("4 TUCKER
     * AVENUE"). Fragments shorter than 8 chars are dropped as noise.
     *
     * @return list<string> lowercased fragments
     */
    public static function extractAddressNeedles(string $address): array
    {
        $address = trim($address);
        if ($address === '') {
            return [];
        }

        $needles = [];
        foreach (explode(',', $address) as $piece) {
            $piece = mb_strtolower(trim($piece));
            if (mb_strlen($piece) >= 8) {
                $needles[] = $piece;
            }
            // Strip a leading street number ("4 Tucker Avenue" → "tucker avenue").
            $stripped = preg_replace('/^\d+\s+/', '', $piece);
            if ($stripped && $stripped !== $piece && mb_strlen($stripped) >= 8) {
                $needles[] = $stripped;
            }
        }

        return array_values(array_unique($needles));
    }

    /**
     * Resolve the ids of market_reports whose subject is this property.
     *
     * @return list<int>
     */
    public static function resolveReportIds(
        int $agencyId,
        ?string $subjectAddress,
        ?string $suburb,
    ): array {
        if ($agencyId <= 0) {
            return [];
        }

        $suburb  = trim((string) $suburb);
        $needles = self::extractAddressNeedles((string) $subjectAddress);

        if (empty($needles) && $suburb === '') {
            return [];
        }

        return MarketReport::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where(function ($q) use ($needles, $suburb) {
                foreach ($needles as $n) {
                    $q->orWhereRaw('LOWER(subject_address) LIKE ?', ['%' . $n . '%']);
                }
                if ($suburb !== '') {
                    $q->orWhereRaw('LOWER(source_suburb) = ?', [mb_strtolower($suburb)]);
                    $q->orWhereRaw('LOWER(subject_address) LIKE ?', ['%' . mb_strtolower($suburb) . '%']);
                }
            })
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }
}
