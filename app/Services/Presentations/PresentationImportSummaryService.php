<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Models\Presentation;
use App\Support\Presentations\SubjectReportResolver;
use Illuminate\Support\Facades\DB;

/**
 * Builds the post-Generate import-confirmation summary the review screen
 * shows the agent:
 *
 *   "2 reports imported · 21 comps parsed · 15 sold + 6 active hydrated · 12 mapped"
 *
 * This is the honest answer to "did my upload work?" — it reports the real
 * HYDRATED counts (what the engine actually used), not a badge that can
 * structurally lie. Every number is derived from persisted source-of-truth
 * tables so it cannot drift from what the report renders.
 *
 * Spec: .ai/audits/cma-comp-gps-axis-investigation-2026-06-17.md §7;
 *       data-lineage §2.3 / §2.11.
 */
class PresentationImportSummaryService
{
    /**
     * @return array{
     *   reports_imported:int, comps_parsed:int, sold_hydrated:int,
     *   active_hydrated:int, mapped:int, unmapped:int
     * }
     */
    public function build(Presentation $presentation): array
    {
        $agencyId = (int) $presentation->agency_id;
        $isDemo   = (bool) ($presentation->property?->is_demo ?? false);

        $subjectReportIds = SubjectReportResolver::resolveReportIds(
            $agencyId,
            (string) ($presentation->property_address ?? $presentation->property?->address ?? ''),
            (string) ($presentation->suburb ?? ''),
        );

        $compsParsed = 0;
        if (!empty($subjectReportIds)) {
            $compsParsed = (int) DB::table('market_report_comp_rows')
                ->whereNull('deleted_at')
                ->where('row_type', 'comp')
                ->where('is_demo', $isDemo)
                ->whereIn('market_report_id', $subjectReportIds)
                ->count();
        }

        $soldHydrated = (int) DB::table('presentation_sold_comps')
            ->whereNull('deleted_at')
            ->where('presentation_id', $presentation->id)
            ->count();

        $activeHydrated = (int) DB::table('presentation_active_listings')
            ->whereNull('deleted_at')
            ->where('presentation_id', $presentation->id)
            ->count();

        // "Mapped" = hydrated rows that carry real coordinates (raw_row_json
        // latitude+longitude). The spatial map plots only these; the caption
        // surfaces the residual instead of showing a silent empty map.
        $mapped = $this->countMapped('presentation_sold_comps', $presentation->id)
            + $this->countMapped('presentation_active_listings', $presentation->id);

        $totalHydrated = $soldHydrated + $activeHydrated;

        return [
            'reports_imported' => count($subjectReportIds),
            'comps_parsed'     => $compsParsed,
            'sold_hydrated'    => $soldHydrated,
            'active_hydrated'  => $activeHydrated,
            'mapped'           => $mapped,
            'unmapped'         => max(0, $totalHydrated - $mapped),
        ];
    }

    /**
     * Count rows for a presentation whose raw_row_json carries a usable
     * lat/lng pair. Done in PHP because raw_row_json is a JSON text column
     * and the test/prod DBs differ on JSON-function support.
     */
    private function countMapped(string $table, int $presentationId): int
    {
        $rows = DB::table($table)
            ->whereNull('deleted_at')
            ->where('presentation_id', $presentationId)
            ->pluck('raw_row_json');

        $count = 0;
        foreach ($rows as $raw) {
            if (empty($raw)) {
                continue;
            }
            $decoded = is_string($raw) ? json_decode($raw, true) : (array) $raw;
            if (is_array($decoded)
                && ($decoded['latitude'] ?? null) !== null
                && ($decoded['longitude'] ?? null) !== null) {
                $count++;
            }
        }

        return $count;
    }
}
