<?php

declare(strict_types=1);

namespace App\Jobs\MarketReports;

use App\Models\MarketReports\MarketReport;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3f C2 — after a market report parses with a fresh subject GPS,
 * propagate the geo enrichment back to any matching Property + TrackedProperty
 * row. This makes "import a CMA" the canonical path for filling CoreX's
 * spatial layer organically — no extra agent step required.
 *
 * Matching: case-insensitive contains-match on suburb + address_needle (same
 * fragment logic the resolver uses). Only updates rows that don't already
 * have GPS.
 *
 * cc2, 2026-08-25 — two fixes, both narrow, both the same class of bug found
 * on presentation 331 (LLE Tonmawr): the property's own location was stored
 * as the literal (0.0, 0.0) sentinel — never geocoded, defaulted to zero
 * instead of staying null — and this job's own "does it already have GPS"
 * guard only ever checked for NULL, so a row already sitting at (0,0) was
 * silently excluded from the query and never reached, even though the
 * report parsed moments earlier carried the real coordinate the whole time.
 *
 *   1. The candidate query below now also matches the (0,0) sentinel, not
 *      only NULL — the same narrow "is this a real coordinate" test used in
 *      CompPoolBuilder's own fix for the same root cause. No other matching
 *      rule changed.
 *   2. A matched row now takes the coordinate straight from the report's
 *      own subject_latitude/subject_longitude — the number the parser
 *      already read off the document's own "PROPERTY INFORMATION" page —
 *      instead of triggering a second, independent address re-geocode
 *      through PropertyGeoBackfillService/AddressResolverService. Johan's
 *      own instruction: "the CMA valuation report has the geocodes as well,
 *      so that can be used to locate the actual property" — use the number
 *      that's already been read and confirmed reliable (122/122 spot-checked
 *      on staging), not send the address out to a third party again to ask
 *      the same question. PropertyGeoBackfillService is untouched; a NULL
 *      row still resolves through it exactly as before — this only changes
 *      how the market-report path fills a gap, when it already knows the
 *      answer.
 *
 * NEVER overwrites a real, already-set location — the query only ever
 * selects rows that are missing one or sitting at the (0,0) sentinel.
 */
final class BackfillPropertyGpsFromReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $reportId) {}

    public function handle(): void
    {
        $report = MarketReport::query()->withoutGlobalScopes()->find($this->reportId);
        if (!$report || $report->subject_latitude === null || $report->subject_longitude === null) {
            return;
        }

        $subject = (string) $report->subject_address;
        $suburb  = (string) $report->source_suburb;
        if ($subject === '') return;

        $needles = $this->needles($subject);
        if (empty($needles)) return;

        $matched = 0;

        // Properties — agency-scoped to the report.
        $propQuery = Property::query()->withoutGlobalScopes();
        if ($report->agency_id) {
            $propQuery->where('agency_id', $report->agency_id);
        }
        $propQuery->where(function ($q) use ($needles) {
            foreach ($needles as $n) {
                $q->orWhereRaw('LOWER(address) LIKE ?', ['%' . $n . '%']);
            }
        });
        if ($suburb !== '') {
            $propQuery->whereRaw('LOWER(suburb) LIKE ?', ['%' . mb_strtolower($suburb) . '%']);
        }
        $propQuery->where(function ($q) {
            $q->whereNull('latitude')->orWhereNull('longitude')
              ->orWhere(function ($qq) {
                  $qq->where('latitude', 0)->where('longitude', 0);
              });
        });
        foreach ($propQuery->limit(200)->get() as $property) {
            try {
                $this->fillFromReport($property, $report);
                $matched++;
            } catch (\Throwable $e) {
                Log::warning('Property GPS backfill failed', ['id' => $property->id, 'err' => $e->getMessage()]);
            }
        }

        // Tracked properties — agency-scoped too.
        $tpQuery = TrackedProperty::query()->withoutGlobalScopes();
        if ($report->agency_id) {
            $tpQuery->where('agency_id', $report->agency_id);
        }
        $tpQuery->where(function ($q) use ($needles) {
            foreach ($needles as $n) {
                $q->orWhereRaw('LOWER(CONCAT_WS(\' \', street_number, street_name)) LIKE ?', ['%' . $n . '%']);
            }
        });
        if ($suburb !== '') {
            $tpQuery->whereRaw('LOWER(suburb) LIKE ?', ['%' . mb_strtolower($suburb) . '%']);
        }
        $tpQuery->where(function ($q) {
            $q->whereNull('latitude')->orWhereNull('longitude')
              ->orWhere(function ($qq) {
                  $qq->where('latitude', 0)->where('longitude', 0);
              });
        });
        foreach ($tpQuery->limit(200)->get() as $tp) {
            try {
                $this->fillFromReport($tp, $report);
                $matched++;
            } catch (\Throwable $e) {
                Log::warning('TP GPS backfill failed', ['id' => $tp->id, 'err' => $e->getMessage()]);
            }
        }

        Log::info('BackfillPropertyGpsFromReportJob: complete', [
            'report_id' => $report->id,
            'matched'   => $matched,
        ]);
    }

    /**
     * Write the report's own subject GPS directly onto a matched row.
     * Re-checks the (0,0)/null gate on the actual model instance (not just
     * the earlier SQL WHERE) so a row that changed between the query and
     * here — however unlikely inside one job run — is never overwritten.
     */
    private function fillFromReport(Property|TrackedProperty $entity, MarketReport $report): void
    {
        $hasReal = $entity->latitude !== null && $entity->longitude !== null
            && !((float) $entity->latitude === 0.0 && (float) $entity->longitude === 0.0);
        if ($hasReal) {
            return;
        }

        $entity->latitude        = $report->subject_latitude;
        $entity->longitude       = $report->subject_longitude;
        $entity->geo_source      = 'market_report_subject';
        $entity->geo_confidence  = 'high';
        $entity->geo_resolved_at = now();
        $entity->saveQuietly();
    }

    private function needles(string $address): array
    {
        $needles = [];
        foreach (explode(',', $address) as $piece) {
            $piece = mb_strtolower(trim($piece));
            if (mb_strlen($piece) >= 8) $needles[] = $piece;
            $stripped = preg_replace('/^\d+\s+/', '', $piece);
            if ($stripped !== null && $stripped !== $piece && mb_strlen($stripped) >= 8) {
                $needles[] = $stripped;
            }
        }
        return array_values(array_unique($needles));
    }
}
