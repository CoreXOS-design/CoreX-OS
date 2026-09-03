<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Webinar prep 2026-09-03 — Johan's #1 named complaint: "the property
 * intelligence - I could not find a property that shows the graph", and
 * separately the seller-live "Active Competition" section reading
 * "0 live listings competing in this market" while the audit grid below it
 * shows real cards.
 *
 * Root cause, confirmed by reading CompetitorStockMatchService::
 * loadCandidates()/resolveCriteria() and reproducing the exact query: the
 * headline count comes from a SEPARATE matching pipeline than the audit
 * grid — CompetitorStockMatchService::findCompetitors(), which queries
 * `prospecting_listings` scoped to the subject's own suburb, price
 * (±competitor_stock_default_price_tolerance_pct, default 20%) and beds
 * (±competitor_stock_default_beds_tolerance, default 1). The 8 hero
 * properties (Uvongo, House, R1.08M–R2.46M) have real prospecting_listings
 * rows in Uvongo, but none fall inside BOTH bands simultaneously for any of
 * them — a genuine seed-density gap, not a code bug. The same service
 * (findComparableStock) also backs the Property Intelligence comparable-
 * stock panel, so this one fix serves both complaints.
 *
 * Writes 3 competitor rows per hero property, deliberately placed inside
 * both tolerance bands (±10% price, same/±1 bed) so a real, non-zero,
 * scored competitor pool exists. INERT: plain prospecting_listings rows,
 * fictional addresses, no portal calls.
 *
 * Idempotent: keyed on portal_ref (DEMO-COMP-{property_id}-{n}), updateOrInsert.
 */
final class DemoCompetitorStockSeeder
{
    private const HERO_PROPERTY_IDS = [8, 9, 10, 11, 12, 13, 14, 15];

    private const STREETS = [
        'Lighthouse Way', 'Sardine Run Crescent', 'Aloe Ridge Close', 'Whale Watch Drive',
        'Dolphin Coast Avenue', 'Sunset Bluff Road', 'Palm Grove Street', 'Coral Tree Lane',
    ];

    public function run(int $agencyId): array
    {
        $heroes = DB::table('properties')
            ->where('agency_id', $agencyId)
            ->whereIn('id', self::HERO_PROPERTY_IDS)
            ->whereNull('deleted_at')
            ->get(['id', 'suburb', 'price', 'beds', 'property_type']);

        if ($heroes->isEmpty()) {
            return ['note' => "Skipped — none of the hero property ids found for agency {$agencyId}."];
        }

        $capturedByUserId = DB::table('users')->where('agency_id', $agencyId)->orderBy('id')->value('id');

        $written = 0;
        foreach ($heroes as $i => $p) {
            $variants = [
                ['pricePct' => -0.08, 'bedsDelta' => 0],
                ['pricePct' =>  0.05, 'bedsDelta' => 0],
                ['pricePct' =>  0.12, 'bedsDelta' => -1],
            ];

            foreach ($variants as $n => $v) {
                $price = (int) round(((int) $p->price) * (1 + $v['pricePct']) / 5000) * 5000;
                $beds = max(1, (int) $p->beds + $v['bedsDelta']);
                $streetIdx = ($i * 3 + $n) % count(self::STREETS);
                $houseNo = 20 + (($i * 11 + $n * 7) % 180);
                $address = "{$houseNo} " . self::STREETS[$streetIdx] . ', ' . $p->suburb;
                $ref = "DEMO-COMP-{$p->id}-{$n}";

                DB::table('prospecting_listings')->updateOrInsert(
                    ['portal_ref' => $ref],
                    [
                        'agency_id'         => $agencyId,
                        'captured_by_user_id' => $capturedByUserId,
                        'portal_source'     => $n % 2 === 0 ? 'p24' : 'pp',
                        'portal_url'        => 'https://demo.' . ($n % 2 === 0 ? 'p24' : 'pp') . '.example/listing/' . $ref,
                        'address'           => $address,
                        'normalized_address' => strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $address)),
                        'suburb'            => $p->suburb,
                        'district'          => $p->suburb,
                        'price'             => $price,
                        'bedrooms'          => $beds,
                        'bathrooms'         => max(1, $beds - 1),
                        'garages'           => 1,
                        'erf_size_m2'       => 450 + mt_rand(-80, 150),
                        'property_type'     => $p->property_type,
                        'agent_name'        => 'Portal Listing',
                        'agency_name'       => 'Competing Agency',
                        'first_seen_at'     => now()->subDays(mt_rand(15, 90)),
                        'last_seen_at'      => now()->subDays(mt_rand(0, 5)),
                        'first_seen_email_date' => now()->subDays(mt_rand(15, 90))->toDateString(),
                        'is_active'         => 1,
                        'created_at'        => now()->subDays(mt_rand(15, 90)),
                        'updated_at'        => now(),
                    ]
                );
                $written++;
            }
        }

        return [
            'heroes_covered'    => $heroes->count(),
            'competitor_rows_written' => $written,
        ];
    }
}
