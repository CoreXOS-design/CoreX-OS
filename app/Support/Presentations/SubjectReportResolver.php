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
 * Resolution REQUIRES an address-fragment (street) match against
 * market_reports.subject_address; source_suburb may only CONFIRM/disambiguate
 * that match, never select a report on its own (AT-78 — suburb-alone borrowed a
 * different property's report; same GPS-borrow bug-class as
 * AddressResolverService). It is intentionally NOT date-bounded: an analyst's
 * CMA full of 2019–2024 sectional sales is still evidence FOR this subject today.
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

        $suburb  = mb_strtolower(trim((string) $suburb));
        $needles = self::extractAddressNeedles((string) $subjectAddress);

        // GPS-BORROW-FIX PARITY (AT-78) — this resolver had the SAME bug-class
        // AddressResolverService::resolveFromMarketReports was already fixed for:
        // it OR-ed an address-needle match with a bare suburb match, so ANY
        // report in the same suburb matched and the caller borrowed a DIFFERENT
        // property's report (report 81 NAUTILUS / 75 Marine Drive was stamped
        // onto 55 Garden Avenue). The rule is now identical in both: an
        // address-needle (street) match is REQUIRED; the suburb may only
        // CONFIRM/disambiguate it, never select alone. No street needles → no
        // match (suburb alone is never enough to claim a report is the subject's).
        if (empty($needles)) {
            return [];
        }

        return MarketReport::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            // REQUIRED: the report's subject_address must contain a street needle.
            ->where(function ($q) use ($needles) {
                foreach ($needles as $n) {
                    $q->orWhereRaw('LOWER(subject_address) LIKE ?', ['%' . $n . '%']);
                }
            })
            // OPTIONAL CONFIRM: when the suburb is known, require the report's
            // suburb to match it (or be blank) so a same-street-name report from
            // another suburb can't be borrowed. This narrows, never widens.
            ->when($suburb !== '', function ($q) use ($suburb) {
                $q->where(function ($w) use ($suburb) {
                    $w->whereRaw('LOWER(source_suburb) = ?', [$suburb])
                      ->orWhereNull('source_suburb')
                      ->orWhere('source_suburb', '');
                });
            })
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }
}
