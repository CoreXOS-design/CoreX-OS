<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Webinar prep (2026-09-03) — Johan: "seller live links working and showing
 * the graphs."
 *
 * Root cause: every presentation's Seller Live screen (PresentationController
 * @sellerLive -> AnalysisDataService::compile()) computes its CMA valuation
 * and active-competition graphs from `presentation_sold_comps` and
 * `presentation_active_listings` — both were empty for all 57 demo
 * presentations, so cmaLower/cmaMiddle/cmaUpper and the competition rows all
 * came back null. Confirmed by reading AnalysisDataService::compile() and
 * running it against live demo data before writing this seeder, not assumed.
 *
 * This writes realistic sold-comp and active-listing rows per presentation,
 * clustered around the presentation's own asking price (or the linked
 * property's price), so CmaComputeService has a real pool to compute from.
 * Also backfills the `suburb.*` PresentationField rows the suburb-overview
 * panel reads, when missing.
 *
 * INERT: plain DB rows, no observer/booted() side effects, no external call.
 * Fictional street addresses only (reuses the same generic KZN South Coast
 * street-name flavour as DemoDataSeeder::SPINE_STREETS) — no HFC references,
 * no real comparable-sale data.
 *
 * Idempotent: skips any presentation that already has sold-comp rows tagged
 * with self::SOURCE_TAG (checked via raw_row_json marker, since neither
 * table has its own source_ref column) OR already has ANY sold comps at all
 * (never overwrites a presentation someone populated a different way).
 */
final class DemoPresentationMarketDataSeeder
{
    private const SOURCE_TAG = 'demo-presentation-market-data';

    private const STREETS = [
        'Lighthouse Way', 'Sardine Run Crescent', 'Aloe Ridge Close', 'Whale Watch Drive',
        'Dolphin Coast Avenue', 'Sunset Bluff Road', 'Palm Grove Street', 'Coral Tree Lane',
        'Banana Boat Road', 'Tidal Pool Crescent', 'Bluewater Bay Drive', 'Sugar Cane Close',
        'Estuary View Road', 'Marlin Point Avenue', 'Milkwood Close',
    ];

    public function run(int $agencyId): array
    {
        $presentations = DB::table('presentations')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->get(['id', 'property_id', 'property_address', 'asking_price_inc', 'status']);

        if ($presentations->isEmpty()) {
            return ['inserted' => 0, 'note' => "Skipped — agency {$agencyId} has no presentations."];
        }

        // Backfill floor size (size_m2) for presentation-linked properties that
        // are missing it — beds/baths/erf were already populated by
        // DemoPropertiesSeeder, but size_m2 was left null, which reads as an
        // incomplete listing on the exact properties Seller Live showcases.
        // Only ever fills a NULL — never overwrites a real value.
        $sizeBackfilled = 0;
        foreach (DB::table('properties')
            ->whereIn('id', $presentations->pluck('property_id')->filter()->all())
            ->whereNull('size_m2')
            ->get(['id', 'beds']) as $prop) {
            $base = match (true) {
                ($prop->beds ?? 3) <= 2 => 85,
                $prop->beds === 3       => 140,
                default                 => 190,
            };
            DB::table('properties')->where('id', $prop->id)->update([
                'size_m2' => $base + mt_rand(-15, 25),
            ]);
            $sizeBackfilled++;
        }

        $properties = DB::table('properties')
            ->whereIn('id', $presentations->pluck('property_id')->filter()->all())
            ->get(['id', 'suburb', 'property_type'])
            ->keyBy('id');

        $suburbPool = DB::table('properties')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereNotNull('suburb')
            ->distinct()
            ->pluck('suburb')
            ->filter()
            ->values();
        if ($suburbPool->isEmpty()) {
            $suburbPool = collect(['Margate', 'Shelly Beach', 'Uvongo', 'Ramsgate', 'St Michaels-on-Sea']);
        }

        $presCovered = 0;
        $soldInserted = 0;
        $activeInserted = 0;
        $fieldsBackfilled = 0;

        foreach ($presentations as $i => $p) {
            $alreadyHasComps = DB::table('presentation_sold_comps')
                ->where('presentation_id', $p->id)
                ->whereNull('deleted_at')
                ->exists();
            if ($alreadyHasComps) {
                continue; // never overwrite — could be real agent-entered data.
            }

            $property = $p->property_id ? ($properties->get($p->property_id)) : null;
            $suburb = $property->suburb ?? $suburbPool[$i % $suburbPool->count()];
            $propertyType = $property->property_type ?? 'House';
            $askingPrice = (int) ($p->asking_price_inc ?: 2200000);

            // ── Sold comps: 6 per presentation, spread over the last 3–11 months,
            //    prices banded ±14% around asking so the CMA middle lands close
            //    to (but not exactly on) the asking price — realistic, not rigged.
            for ($c = 0; $c < 6; $c++) {
                $variance = 1 + (mt_rand(-14, 14) / 100);
                $soldPrice = (int) round(($askingPrice * $variance) / 5000) * 5000;
                $soldDate = now()->subDays(mt_rand(30, 330));
                $streetIdx = ($i * 7 + $c) % count(self::STREETS);
                $houseNo = 10 + (($i * 13 + $c * 5) % 190);

                DB::table('presentation_sold_comps')->insert([
                    'presentation_id' => $p->id,
                    'agency_id'       => $agencyId,
                    'sold_date'       => $soldDate->toDateString(),
                    'sold_price_inc'  => $soldPrice,
                    'suburb'          => $suburb,
                    'property_type'   => $propertyType,
                    'beds'            => mt_rand(2, 4),
                    'baths'           => mt_rand(1, 3),
                    'size_m2'         => mt_rand(110, 320),
                    'listed_date'     => $soldDate->copy()->subDays(mt_rand(20, 90))->toDateString(),
                    'raw_row_json'    => json_encode([
                        'source'  => self::SOURCE_TAG,
                        'address' => "{$houseNo} " . self::STREETS[$streetIdx],
                    ]),
                    'parser_version'  => self::SOURCE_TAG,
                    'created_at'      => now(),
                    'is_demo'         => 1,
                ]);
                $soldInserted++;
            }

            // ── Active competition: 5 per presentation, currently on market,
            //    priced a little above asking (sellers ask more than they get).
            for ($a = 0; $a < 5; $a++) {
                $variance = 1 + (mt_rand(0, 18) / 100);
                $listPrice = (int) round(($askingPrice * $variance) / 5000) * 5000;
                $listedDate = now()->subDays(mt_rand(5, 150));
                $streetIdx = ($i * 11 + $a * 3 + 4) % count(self::STREETS);
                $houseNo = 5 + (($i * 17 + $a * 9) % 195);
                $address = "{$houseNo} " . self::STREETS[$streetIdx];

                DB::table('presentation_active_listings')->insert([
                    'presentation_id' => $p->id,
                    'agency_id'       => $agencyId,
                    'listing_date'    => $listedDate->toDateString(),
                    'list_price_inc'  => $listPrice,
                    'suburb'          => $suburb,
                    'property_type'   => $propertyType,
                    'beds'            => mt_rand(2, 4),
                    'baths'           => mt_rand(1, 3),
                    'size_m2'         => mt_rand(110, 320),
                    'status'          => 'active',
                    'raw_row_json'    => json_encode([
                        'source'         => self::SOURCE_TAG,
                        'address'        => $address,
                        'property_type'  => $propertyType,
                        'list_date'      => $listedDate->format('Y/m/d'),
                        'days_on_market' => now()->diffInDays($listedDate),
                    ]),
                    'parser_version'  => self::SOURCE_TAG,
                    'external_key'    => self::SOURCE_TAG . '-' . $p->id . '-' . $a,
                    'first_seen_at'   => $listedDate,
                    'last_seen_at'    => now(),
                    'is_active'       => 1,
                    'created_at'      => now(),
                    'is_demo'         => 1,
                ]);
                $activeInserted++;
            }

            // ── Suburb overview fields — only backfill keys that are missing,
            //    never touch a field an agent (or another seeder) already set.
            $existingKeys = DB::table('presentation_fields')
                ->where('presentation_id', $p->id)
                ->whereNull('deleted_at')
                ->pluck('field_key')
                ->all();

            $suburbFields = [
                'suburb.latest_year'         => (string) now()->year,
                'suburb.latest_sales_count'  => (string) mt_rand(18, 46),
                'suburb.latest_median_price' => (string) $askingPrice,
                'suburb.latest_low'          => (string) (int) round($askingPrice * 0.72),
                'suburb.latest_high'         => (string) (int) round($askingPrice * 1.35),
                'suburb.latest_max'          => (string) (int) round($askingPrice * 1.9),
            ];

            foreach ($suburbFields as $key => $value) {
                if (in_array($key, $existingKeys, true)) {
                    continue;
                }
                DB::table('presentation_fields')->insert([
                    'presentation_id' => $p->id,
                    'agency_id'       => $agencyId,
                    'field_key'       => $key,
                    'extracted_value' => $value,
                    'final_value'     => $value,
                    'confidence'      => 0.75,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
                $fieldsBackfilled++;
            }

            $presCovered++;
        }

        return [
            'presentations_covered' => $presCovered,
            'sold_comps_inserted'   => $soldInserted,
            'active_listings_inserted' => $activeInserted,
            'suburb_fields_backfilled' => $fieldsBackfilled,
            'property_size_m2_backfilled' => $sizeBackfilled,
        ];
    }
}
