<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use App\Support\MarketAnalytics\HaversineDistance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 3h Step 4 — synthetic market_reports + comp rows + listing rows.
 *
 * Per suburb:
 *   - 10 fake reports across three types (Property Valuation, ST Sales,
 *     Median Sales Analysis), report_date spread over 18 months
 *   - 8-15 comp rows per Property Valuation report (jittered ±300m, dated
 *     over last 5y, prices within suburb band ±20%)
 *   - 10-15 sectional comp rows per ST Sales report, clustered at scheme GPS
 *   - 1-3 active listing rows per ST report
 *
 * Architectural call-outs:
 *   - report_type_id values are looked up by key (looked up in STEP 0 the
 *     real IDs are 3 Property Valuation, 4 ST Sales, 2 Median). Each demo
 *     report gets a real type so existing display logic shows the right name.
 *   - file_path / file_name / file_hash are required NOT-NULL — we synthesise
 *     plausible filenames + a sha256 of the row index so each report has
 *     a unique hash without needing actual PDFs on disk.
 *   - uploaded_by_user_id is required — uses the first agent of the agency.
 *   - parser_version='demo_v1' so it can be told apart from real parser output.
 */
final class DemoMarketDataSeeder
{
    /** @return array{reports:int, comp_rows:int, listing_rows:int} */
    public function run(int $agencyId): array
    {
        $gazetteer = require database_path('seeders/data/kzn_south_coast_suburbs.php');

        $uploader = DB::table('users')->where('agency_id', $agencyId)
            ->whereIn('role', ['agent', 'admin', 'branch_manager'])
            ->orderBy('id')
            ->value('id');
        if (!$uploader) {
            return ['reports' => 0, 'comp_rows' => 0, 'listing_rows' => 0,
                    'note' => "Skipped — agency {$agencyId} has no agents."];
        }

        // Resolve report type IDs we need.
        $typeIds = DB::table('market_report_types')->pluck('id', 'key')->all();
        $tPropertyVal = $typeIds['cma_info_property_valuation']      ?? null;
        $tStSales     = $typeIds['cma_info_sectional_title_sales']   ?? null;
        $tMedian      = $typeIds['cma_info_median_sales_analysis']   ?? null;
        if (!$tPropertyVal || !$tStSales || !$tMedian) {
            return ['reports' => 0, 'comp_rows' => 0, 'listing_rows' => 0,
                    'note' => 'Skipped — required report type IDs missing.'];
        }

        $reportsInserted = 0;
        $compRowsInserted = 0;
        $listingRowsInserted = 0;

        foreach ($gazetteer as $suburbKey => $suburb) {
            // Pull a few demo properties from this suburb to use as subjects.
            $subjects = DB::table('properties')
                ->where('agency_id', $agencyId)
                ->where('is_demo', true)
                ->where('suburb', $suburb['name'])
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->orderBy('id')
                ->limit(10)
                ->get(['id', 'address', 'latitude', 'longitude', 'complex_name', 'property_type']);

            if ($subjects->isEmpty()) continue;

            // 4 Property Valuation + 4 ST Sales + 2 Median = 10 per suburb.
            $plan = array_merge(
                array_fill(0, 4, ['type_id' => $tPropertyVal, 'key' => 'pv']),
                array_fill(0, 4, ['type_id' => $tStSales,     'key' => 'st']),
                array_fill(0, 2, ['type_id' => $tMedian,      'key' => 'msa']),
            );

            foreach ($plan as $i => $spec) {
                $subject = $subjects[$i % $subjects->count()];
                $reportDate = Carbon::now()->subDays(random_int(7, 540));

                // 2026-09-02 — createReport() used to be a blind ::create(), so a
                // second run (e.g. re-seeding after market_report_types came back
                // populated) duplicated every report AND its comp/listing rows.
                // Deterministic slug (suburb+type+slot index — NOT subject alone,
                // since $i % count() can legitimately reuse the same subject for
                // multiple slots of the same type within one run) is the natural
                // key; comp/listing rows are only ever generated alongside a
                // genuinely NEW report.
                [$reportId, $isNew] = $this->createReport(
                    $agencyId, $uploader, $spec['type_id'], $spec['key'],
                    $subject, $reportDate, $suburb, $suburbKey, $i,
                );
                if ($isNew) {
                    $reportsInserted++;
                }

                if (!$isNew) {
                    continue;
                }

                if ($spec['key'] === 'pv') {
                    [$rows] = $this->seedPropertyValuationRows(
                        $agencyId, $reportId, $subject, $suburb,
                    );
                    $compRowsInserted += $rows;
                } elseif ($spec['key'] === 'st') {
                    [$rows, $listings] = $this->seedStSalesRows(
                        $agencyId, $reportId, $subject, $suburb,
                    );
                    $compRowsInserted    += $rows;
                    $listingRowsInserted += $listings;
                }
                // Median: no comp rows (suburb-aggregate only).
            }
        }

        return [
            'reports'       => $reportsInserted,
            'comp_rows'     => $compRowsInserted,
            'listing_rows'  => $listingRowsInserted,
        ];
    }

    /** @return array{0:int, 1:bool} [report id, wasRecentlyCreated] */
    private function createReport(
        int $agencyId,
        int $uploaderId,
        int $reportTypeId,
        string $key,
        \stdClass $subject,
        Carbon $reportDate,
        array $suburb,
        string $suburbKey,
        int $slotIndex,
    ): array {
        // Natural key: (agency_id, report_type_id, subject_address, source_suburb)
        // — NOT file_name (that's a random uuid every run, old or new code, so it
        // can never match a prior run's row) and NOT slot index alone. subject
        // pool per suburb is 10, slots per type are <=4, so a slot never revisits
        // a subject within one run in practice — subject_address is safe as the
        // real identity. Matches rows from BEFORE this fix too, since
        // subject_address/report_type_id/source_suburb were always populated.
        $uuid = (string) Str::uuid();

        $report = MarketReport::firstOrCreate(
            [
                'agency_id'       => $agencyId,
                'report_type_id'  => $reportTypeId,
                'subject_address' => $subject->address,
                'source_suburb'   => $suburb['name'],
            ],
            [
                'uploaded_by_user_id'    => $uploaderId,
                'file_path'              => 'demo/' . $uuid . '.pdf',
                'file_name'              => 'demo_' . $key . '_' . $suburbKey . '_slot' . $slotIndex . '.pdf',
                'file_hash'              => hash('sha256', $uuid),
                'source_town'            => $suburb['town'],
                'report_date'            => $reportDate->toDateString(),
                'parse_status'           => 'parsed',
                'parse_completed_at'     => $reportDate,
                'parser_version'         => 'demo_v1',
                'raw_extracted_json'     => ['note' => 'Demo-seeded report'],
                'data_points_count'      => 0,
                'spot_check_status'      => 'passed',
                'subject_address'        => $subject->address,
                'subject_scheme_name'    => $subject->complex_name,
                'subject_latitude'       => (float) $subject->latitude,
                'subject_longitude'      => (float) $subject->longitude,
                'is_demo'                => true,
            ]
        );
        return [$report->id, $report->wasRecentlyCreated];
    }

    /**
     * 8-15 comp rows around a Property Valuation subject — jittered ±300m
     * Haversine, dated over last 5 years, prices within suburb band ±20%.
     *
     * @return array{0:int}
     */
    private function seedPropertyValuationRows(
        int $agencyId,
        int $reportId,
        \stdClass $subject,
        array $suburb,
    ): array {
        $rows = random_int(8, 15);
        $inserted = 0;
        for ($i = 0; $i < $rows; $i++) {
            $jitter = $this->haversineJitter(
                (float) $subject->latitude, (float) $subject->longitude,
                metresMin: 50, metresMax: 300,
            );
            $price = $this->priceWithinBand($suburb['price_band_house']);
            $extent = random_int(220, 700);
            $street = $suburb['streets'][array_rand($suburb['streets'])];
            $address = random_int(1, 200) . ' ' . $street;
            $saleDate = Carbon::now()->subDays(random_int(60, 1800));

            MarketReportCompRow::create([
                'market_report_id'      => $reportId,
                'agency_id'             => $agencyId,
                'row_index'             => $i + 1,
                'row_type'              => MarketReportCompRow::ROW_COMP,
                'address'               => $address,
                'suburb_normalised'     => mb_strtolower($suburb['name']),
                'property_type'         => 'House',
                'extent_m2'             => $extent,
                'sale_date'             => $saleDate->toDateString(),
                'sale_price'            => $price,
                'r_per_m2'              => (int) round($price / max(1, $extent)),
                'distance_to_subject_m' => (int) round($this->distanceBetween(
                    (float) $subject->latitude, (float) $subject->longitude,
                    $jitter['lat'], $jitter['lng'],
                )),
                'latitude'              => $jitter['lat'],
                'longitude'             => $jitter['lng'],
                'raw_row_json'          => ['source' => 'demo_v1'],
                'is_demo'               => true,
            ]);
            $inserted++;
        }
        return [$inserted];
    }

    /**
     * 10-15 sectional comp rows clustered at scheme GPS points + 1-3 listing
     * rows per ST report.
     *
     * @return array{0:int, 1:int}
     */
    private function seedStSalesRows(
        int $agencyId,
        int $reportId,
        \stdClass $subject,
        array $suburb,
    ): array {
        $compRows = random_int(10, 15);
        $listingRows = random_int(1, 3);
        $compInserted = 0;
        $listingInserted = 0;

        for ($i = 0; $i < $compRows; $i++) {
            $scheme = $suburb['schemes'][array_rand($suburb['schemes'])];
            // Cluster at deterministic scheme GPS — same seed string as
            // DemoPropertiesSeeder's sectional GPS so they align.
            $schemeGps = $this->seededGps($scheme . '|scheme', $suburb['bounds']);
            $price  = $this->priceWithinBand($suburb['price_band_sectional']);
            $extent = random_int(70, 170);
            $section = (string) random_int(1, 18);
            $saleDate = Carbon::now()->subDays(random_int(60, 1800));

            MarketReportCompRow::create([
                'market_report_id'  => $reportId,
                'agency_id'         => $agencyId,
                'row_index'         => $i + 1,
                'row_type'          => MarketReportCompRow::ROW_COMP,
                'scheme_name'       => $scheme,
                'section_number'    => $section,
                'address'           => $scheme . ', ' . $section,
                'suburb_normalised' => mb_strtolower($suburb['name']),
                'property_type'     => 'Sectional Title',
                'extent_m2'         => $extent,
                'sale_date'         => $saleDate->toDateString(),
                'sale_price'        => $price,
                'r_per_m2'          => (int) round($price / max(1, $extent)),
                'latitude'          => $schemeGps['lat'],
                'longitude'         => $schemeGps['lng'],
                'raw_row_json'      => ['source' => 'demo_v1'],
                'is_demo'           => true,
            ]);
            $compInserted++;
        }

        for ($i = 0; $i < $listingRows; $i++) {
            $scheme = $suburb['schemes'][array_rand($suburb['schemes'])];
            $schemeGps = $this->seededGps($scheme . '|scheme', $suburb['bounds']);
            $price = $this->priceWithinBand($suburb['price_band_sectional']);
            $extent = random_int(70, 170);
            $section = (string) random_int(1, 18);

            MarketReportCompRow::create([
                'market_report_id'  => $reportId,
                'agency_id'         => $agencyId,
                'row_index'         => $compRows + $i + 1,
                'row_type'          => MarketReportCompRow::ROW_LISTING,
                'scheme_name'       => $scheme,
                'section_number'    => $section,
                'address'           => $scheme . ', ' . $section,
                'suburb_normalised' => mb_strtolower($suburb['name']),
                'property_type'     => 'Sectional Title',
                'extent_m2'         => $extent,
                'list_price'        => $price,
                'days_on_market'    => random_int(10, 400),
                'latitude'          => $schemeGps['lat'],
                'longitude'         => $schemeGps['lng'],
                'raw_row_json'      => ['source' => 'demo_v1'],
                'is_demo'           => true,
            ]);
            $listingInserted++;
        }

        return [$compInserted, $listingInserted];
    }

    private function priceWithinBand(array $band): int
    {
        // Centred slightly toward the band midpoint to avoid edge spikes.
        $mid  = ($band[0] + $band[1]) / 2;
        $range = ($band[1] - $band[0]) * 0.4; // ±20% of band
        $price = (int) ($mid + random_int(-(int) $range, (int) $range));
        return (int) (round($price / 10_000) * 10_000);
    }

    /**
     * Returns a point at random distance [metresMin, metresMax] in a random
     * direction from (lat, lng). Realistic for jittering comp rows around
     * a subject.
     */
    private function haversineJitter(float $lat, float $lng, int $metresMin, int $metresMax): array
    {
        $distance = random_int($metresMin, $metresMax);
        $bearing  = deg2rad(random_int(0, 359));

        // 1° latitude ≈ 111,320 m; 1° longitude scales by cos(lat).
        $dLat = ($distance * cos($bearing)) / 111_320;
        $dLng = ($distance * sin($bearing)) / (111_320 * cos(deg2rad($lat)));

        return [
            'lat' => round($lat + $dLat, 7),
            'lng' => round($lng + $dLng, 7),
        ];
    }

    private function distanceBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return HaversineDistance::distanceMetres($lat1, $lng1, $lat2, $lng2);
    }

    /**
     * Same deterministic GPS as DemoPropertiesSeeder so sectional schemes
     * line up across seeders.
     */
    private function seededGps(string $seed, array $bounds): array
    {
        $hash = crc32($seed);
        $cellX = $hash % 4;
        $cellY = intdiv($hash, 4) % 4;
        $cellWidth  = ($bounds['east']  - $bounds['west'])  / 4;
        $cellHeight = ($bounds['north'] - $bounds['south']) / 4;
        $jitterX = (($hash >> 8)  & 0xFF) / 0xFF;
        $jitterY = (($hash >> 16) & 0xFF) / 0xFF;
        $lng = $bounds['west']  + ($cellX * $cellWidth)  + ($jitterX * $cellWidth);
        $lat = $bounds['south'] + ($cellY * $cellHeight) + ($jitterY * $cellHeight);
        return ['lat' => round($lat, 7), 'lng' => round($lng, 7)];
    }
}
