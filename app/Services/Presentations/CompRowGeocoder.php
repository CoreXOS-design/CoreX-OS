<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Services\Geocoding\AddressResolverService;
use App\Support\Presentations\SuburbMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Synchronously resolves GPS for the comp rows a presentation is about to
 * hydrate — BEFORE the radius filter runs.
 *
 * Why this exists (root cause from the 2026-06-17 GPS investigation): CMA
 * comp tables carry NO per-comp GPS, so comp coords only exist once the
 * address geocodes. The hydrator's lazy encodeRaw geocode fired AFTER
 * collectMatchedRows had already dropped NULL-geo rows from the Haversine
 * gate — so a freshly-imported report mapped 0 comps on its first generate,
 * even though the rows geocoded fine on the (never-reached) next run.
 *
 * Running this at generate time, against the rows the hydrator will actually
 * consider (subject's own reports + suburb matches), means the radius filter
 * sees real coordinates on the FIRST generate. Best-effort per row — a row
 * that won't resolve is left NULL and surfaces honestly in the
 * "N of M plotted" caption rather than failing the generate.
 *
 * Spec: .ai/audits/cma-comp-gps-axis-investigation-2026-06-17.md §7;
 *       data-lineage §2.11.
 */
class CompRowGeocoder
{
    public function __construct(
        private AddressResolverService $resolver = new AddressResolverService(),
    ) {}

    /**
     * Resolve + persist GPS for NULL-geo comp/listing rows in the pool the
     * hydrator will consider: the subject's own reports (any) OR rows whose
     * suburb_normalised matches the subject suburb.
     *
     * @param list<int> $subjectReportIds
     * @return array{attempted:int, resolved:int, failed:int}
     */
    public function backfillForSubject(
        int $agencyId,
        array $subjectReportIds,
        ?string $suburb,
        bool $isDemo,
        int $limit = 200,
    ): array {
        $tally = ['attempted' => 0, 'resolved' => 0, 'failed' => 0];

        $subjectReportIds = array_values(array_filter(array_map('intval', $subjectReportIds)));
        $suburb           = trim((string) $suburb);
        $suburbCore       = $suburb !== '' ? SuburbMatcher::normaliseSuburbToken($suburb) : '';

        if (empty($subjectReportIds) && $suburbCore === '') {
            return $tally;
        }

        $query = DB::table('market_report_comp_rows')
            ->whereNull('deleted_at')
            ->where('is_demo', $isDemo)
            ->whereNotNull('address')->where('address', '<>', '')
            ->where(function ($q) {
                $q->whereNull('latitude')->orWhereNull('longitude');
            })
            ->where(function ($q) use ($subjectReportIds, $suburbCore) {
                if (!empty($subjectReportIds)) {
                    $q->orWhereIn('market_report_id', $subjectReportIds);
                }
                if ($suburbCore !== '') {
                    $q->orWhereRaw('LOWER(suburb_normalised) LIKE ?', ['%' . $suburbCore . '%']);
                }
            });

        $rows = $query->limit($limit)
            ->select(['id', 'address', 'suburb_normalised', 'market_report_id'])
            ->get();

        foreach ($rows as $row) {
            // Suburb-matched rows: narrow the SQL core-token LIKE down to a
            // true locality match (same discipline as the hydrator). Rows
            // from the subject's own reports are exempt from this narrowing.
            $isSubjectReport = !empty($subjectReportIds)
                && in_array((int) $row->market_report_id, $subjectReportIds, true);
            if (!$isSubjectReport
                && $suburb !== ''
                && !empty($row->suburb_normalised)
                && !SuburbMatcher::matches($row->suburb_normalised, $suburb)) {
                continue;
            }

            $tally['attempted']++;
            try {
                $result = $this->resolver->resolve(
                    (string) $row->address,
                    $row->suburb_normalised ?: ($suburb ?: null),
                    null,
                    context: 'mic_comp_row:' . (int) $row->id,
                );
                if ($result->hasGps()) {
                    DB::table('market_report_comp_rows')
                        ->where('id', (int) $row->id)
                        ->update([
                            'latitude'   => $result->latitude,
                            'longitude'  => $result->longitude,
                            'updated_at' => now(),
                        ]);
                    $tally['resolved']++;
                } else {
                    $tally['failed']++;
                }
            } catch (\Throwable $e) {
                $tally['failed']++;
                Log::debug('CompRowGeocoder: resolve failed', [
                    'comp_row_id' => (int) $row->id,
                    'err'         => $e->getMessage(),
                ]);
            }
        }

        return $tally;
    }
}
