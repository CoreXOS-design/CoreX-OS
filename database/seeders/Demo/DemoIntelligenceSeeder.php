<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo property Intelligence data — populates the parts of the property
 * show page's "Intelligence" tab (PropertyIntelligenceService) that need
 * real rows to render anything: portal engagement history, cross-portal
 * matched listings, and a CMA/Market Snapshot (which is empty even on LIVE
 * TESTING today — presentations.listing_id is null on every real row there
 * — so this is synthesized from the schema, not copied from a real example).
 *
 * Deliberately NOT touched: the feedback-rollup section of the Intelligence
 * tab is calendar-driven — cc2's scope, left alone here.
 *
 * IDEMPOTENT BY CONSTRUCTION:
 *   - property_portal_metrics: updateOrInsert keyed on the table's own real
 *     unique index (property_id, portal, metric_date) — a re-run overwrites
 *     the same 60 days with the same values, never inserts a second set.
 *   - prospecting_listings matched_property_id: only ever written on rows
 *     currently NULL, so a re-run finds fewer (or zero) candidates as the
 *     target is approached — never re-matches or duplicates.
 *   - presentations/presentation_fields: one presentation per hero property,
 *     found by (agency_id, listing_id) before ever creating a new one;
 *     presentation_fields written via updateOrInsert keyed on
 *     (presentation_id, field_key). No property gets a second CMA
 *     presentation on a re-run.
 */
class DemoIntelligenceSeeder extends Seeder
{
    private const HERO_COUNT = 15;
    private const PORTAL_METRIC_DAYS = 60;
    private const MATCHED_LISTINGS_PER_HERO = 4;
    /** Of the HERO_COUNT properties, how many get a full CMA snapshot presentation. */
    private const CMA_PRESENTATIONS = 10;

    /** @return array{portal_metric_rows:int, matched_listings:int, presentations_created:int, note?:string} */
    public function run(int $agencyId = 1): array
    {
        $heroes = DB::table('properties')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit(self::HERO_COUNT)
            ->get(['id', 'suburb', 'price', 'title', 'address', 'branch_id', 'agent_id']);

        if ($heroes->isEmpty()) {
            $note = 'Skipped — agency has no properties yet.';
            $this->note($note);
            return ['portal_metric_rows' => 0, 'matched_listings' => 0, 'presentations_created' => 0, 'note' => $note];
        }

        $fallbackUserId = DB::table('users')->where('agency_id', $agencyId)
            ->whereIn('role', ['admin', 'agent', 'branch_manager'])->orderBy('id')->value('id');
        $fallbackBranchId = DB::table('branches')->where('agency_id', $agencyId)->whereNull('deleted_at')->orderBy('id')->value('id');

        $metricRows = $this->topUpPortalMetrics($agencyId, $heroes);
        $matchedListings = $this->topUpMatchedListings($agencyId, $heroes);
        $presentationsCreated = $this->topUpCmaPresentations($agencyId, $heroes, $fallbackUserId, $fallbackBranchId);

        $note = "Intelligence: {$metricRows} portal-metric rows top-up, +{$matchedListings} matched listings, "
            . "+{$presentationsCreated} CMA presentations, across {$heroes->count()} hero properties";
        $this->note($note);

        return [
            'portal_metric_rows' => $metricRows,
            'matched_listings' => $matchedListings,
            'presentations_created' => $presentationsCreated,
            'note' => $note,
        ];
    }

    private function topUpPortalMetrics(int $agencyId, $heroes): int
    {
        $written = 0;
        foreach ($heroes as $p) {
            for ($i = 0; $i < self::PORTAL_METRIC_DAYS; $i++) {
                $date = Carbon::today()->subDays($i);
                $seed = crc32($p->id . '|' . $date->toDateString());
                $views = 2 + ($seed % 14);
                $leads = ($seed % 9 === 0) ? 1 : 0;

                DB::table('property_portal_metrics')->updateOrInsert(
                    ['property_id' => $p->id, 'portal' => 'p24', 'metric_date' => $date->toDateString()],
                    [
                        'agency_id'    => $agencyId,
                        // NOT NULL, no default — required even though the update-key tuple
                        // above (property_id, portal, metric_date) never uses it.
                        'portal_listing_number' => 'DEMO-P24-' . $p->id,
                        'view_count'   => $views,
                        'alert_count'  => $seed % 5,
                        'tel_leads'    => $leads,
                        'sms_leads'    => 0,
                        'request_details_leads' => $leads ? ($seed % 2) : 0,
                        'total_leads'  => $leads,
                        'total_contact_leads' => $leads,
                        'price'        => $p->price,
                        'synced_at'    => now(),
                        'updated_at'   => now(),
                        'created_at'   => now(),
                    ]
                );
                $written++;
            }
        }
        return $written;
    }

    private function topUpMatchedListings(int $agencyId, $heroes): int
    {
        $matched = 0;
        foreach ($heroes as $p) {
            // Check what THIS property already has matched before fetching more —
            // without this a re-run keeps grabbing another batch of unmatched
            // listings every time (caught in testing: +39 on a second run).
            $existing = DB::table('prospecting_listings')
                ->where('agency_id', $agencyId)
                ->where('matched_property_id', $p->id)
                ->whereNull('deleted_at')
                ->count();
            $need = max(0, self::MATCHED_LISTINGS_PER_HERO - $existing);
            if ($need === 0) {
                continue;
            }

            $candidates = DB::table('prospecting_listings')
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->whereNull('matched_property_id')
                ->where('suburb', $p->suburb)
                ->orderBy('id')
                ->limit($need)
                ->pluck('id');

            if ($candidates->isEmpty()) {
                continue;
            }

            DB::table('prospecting_listings')->whereIn('id', $candidates)
                ->update(['matched_property_id' => $p->id, 'updated_at' => now()]);
            $matched += $candidates->count();
        }
        return $matched;
    }

    private function topUpCmaPresentations(int $agencyId, $heroes, ?int $fallbackUserId, ?int $fallbackBranchId): int
    {
        if (!$fallbackUserId) {
            return 0;
        }

        $created = 0;
        $i = 0;
        foreach ($heroes as $p) {
            if ($i >= self::CMA_PRESENTATIONS) {
                break;
            }
            $i++;

            $existing = DB::table('presentations')
                ->where('agency_id', $agencyId)
                ->where('listing_id', $p->id)
                ->whereNull('deleted_at')
                ->value('id');

            if ($existing) {
                $this->writeCmaFields($agencyId, (int) $existing, $p);
                continue;
            }

            $sellerName = DemoNames::name('demo-intel-seller-' . $p->id);
            $presentationId = DB::table('presentations')->insertGetId([
                'agency_id'          => $agencyId,
                'branch_id'          => $p->branch_id ?: $fallbackBranchId,
                'created_by_user_id' => $p->agent_id ?: $fallbackUserId,
                'listing_id'         => $p->id,
                'property_id'        => $p->id,
                'title'              => 'CMA — ' . $p->title,
                'property_address'   => $p->address,
                'suburb'             => $p->suburb,
                'asking_price_inc'   => $p->price,
                'seller_name'        => $sellerName,
                'seller_email'       => 'seller-' . $p->id . '@example.com',
                'status'             => 'finalized',
                'currency'           => 'ZAR',
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
            $created++;

            $this->writeCmaFields($agencyId, $presentationId, $p);
        }
        return $created;
    }

    private function writeCmaFields(int $agencyId, int $presentationId, $p): void
    {
        $seed = crc32('demo-intel-cma-' . $p->id);
        $price = (float) $p->price;
        $lower = round($price * 0.92, -3);
        $upper = round($price * 1.08, -3);
        $middle = round(($lower + $upper) / 2, -3);
        $indexed = round($price * 1.11, -3);
        $saleDate = Carbon::today()->subYears(3 + ($seed % 4))->subDays($seed % 300);

        $fields = [
            'subject.erf'              => (string) (100 + ($p->id % 4000)),
            'subject.gps'              => round(-30.7 - (($seed % 400) / 1000), 6) . ',' . round(30.4 + (($seed % 400) / 1000), 6),
            'subject.extent_m2'        => (string) (600 + ($seed % 1800)),
            'municipal.total_value'    => (string) round($price * 0.8, -3),
            'municipal.valuation_year' => '2025',
            'cma.lower_range'          => (string) $lower,
            'cma.middle_range'         => (string) $middle,
            'cma.upper_range'          => (string) $upper,
            'subject.purchase_date'    => $saleDate->toDateString(),
            'subject.purchase_price'   => (string) round($price * 0.78, -3),
            'subject.indexed_value'    => (string) $indexed,
            'subject.cagr'             => '6.4',
        ];

        foreach ($fields as $key => $value) {
            DB::table('presentation_fields')->updateOrInsert(
                ['presentation_id' => $presentationId, 'field_key' => $key],
                [
                    'agency_id'        => $agencyId,
                    'extracted_value'  => $value,
                    'final_value'      => $value,
                    // confidence is decimal(5,2), not the string scale used elsewhere in
                    // this app (e.g. market_data_points.confidence) — getCmaSnapshot()
                    // never reads it, so leave it null rather than guess a numeric scale.
                    'confidence'       => null,
                    'updated_at'       => now(),
                    'created_at'       => now(),
                ]
            );
        }
    }

    private function note(string $message): void
    {
        $this->command?->info('    ' . $message);
    }
}
