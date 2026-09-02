<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AI\AINarrativeCache;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\MarketReports\MarketDataPoint;
use App\Models\MarketReports\MarketReport;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAddress;
use App\Models\ProspectingClaim;
use App\Models\ProspectingListing;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MIC Phase J1 — top up demo data to realistic levels so every MIC surface
 * has something to show during walkthroughs.
 *
 * Idempotent: re-running tops up to the targets but never duplicates. Safe
 * to run on a populated database too — it ONLY adds rows when current
 * counts fall short of the targets.
 *
 * Targets (per spec §J1):
 *   - ≥ 50 TPs with primary address
 *   - ≥ 200 TPs without primary address
 *   - ≥ 15 active claims (some expiring, some flagged)
 *   - ≥ 30 contact_matches (buyer wishlists)
 *   - ≥ 500 prospecting_buyer_matches
 *   - ≥ 5 p24_listings per common suburb
 *   - ≥ 3 parsed market_reports
 *   - Pre-seeded ai_narrative_cache rows for the agency's admin
 *
 * Run with:  php artisan db:seed --class=DemoMicEnrichmentSeeder
 */
class DemoMicEnrichmentSeeder extends Seeder
{
    private const TARGET_TPS_WITH_ADDRESS = 50;
    private const TARGET_TPS_WITHOUT_ADDRESS = 200;
    private const TARGET_ACTIVE_CLAIMS = 15;
    private const TARGET_CONTACT_MATCHES = 30;
    private const TARGET_BUYER_MATCHES = 500;
    private const TARGET_P24_PER_SUBURB = 5;
    private const TARGET_MARKET_REPORTS = 3;

    private const COMMON_SUBURBS = ['Margate', 'Manaba Beach', 'Uvongo', 'Shelly Beach', 'Ramsgate'];

    public function run(): void
    {
        $agency = Agency::query()->orderBy('id')->first();
        if (!$agency) {
            $this->command?->warn('No agency found — DemoMicEnrichmentSeeder skipped.');
            return;
        }
        $agencyId = (int) $agency->id;

        $admin = User::query()
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->orderByRaw("FIELD(role, 'admin', 'super_admin', 'branch_manager', 'agent')")
            ->first();
        if (!$admin) {
            $this->command?->warn('No active user — DemoMicEnrichmentSeeder skipped.');
            return;
        }

        $this->command?->info("Enriching MIC demo data for agency #{$agencyId} ({$agency->name})…");

        $this->topUpTrackedProperties($agencyId, $admin);
        $this->topUpClaims($agencyId, $admin);
        $this->topUpBuyerMatches($agencyId);
        $this->topUpP24Listings($agencyId);
        $this->topUpMarketReports($agencyId, $admin);
        $this->warmAiCacheRows($agencyId, $admin);

        $this->command?->info('DemoMicEnrichmentSeeder complete.');
    }

    private function topUpTrackedProperties(int $agencyId, User $admin): void
    {
        $withAddress = (int) DB::table('tracked_property_addresses')
            ->where('agency_id', $agencyId)
            ->where('is_primary', true)
            ->whereNotNull('street_name')
            ->whereNull('deleted_at')
            ->count();
        $withoutAddress = (int) DB::table('tracked_properties')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereNull('street_name')
            ->count();

        $this->command?->info("  TPs with address: {$withAddress} / target " . self::TARGET_TPS_WITH_ADDRESS);
        $this->command?->info("  TPs without address: {$withoutAddress} / target " . self::TARGET_TPS_WITHOUT_ADDRESS);

        // Create TPs with addresses up to the target.
        $needWith = max(0, self::TARGET_TPS_WITH_ADDRESS - $withAddress);
        for ($i = 0; $i < $needWith; $i++) {
            $suburb = self::COMMON_SUBURBS[$i % count(self::COMMON_SUBURBS)];
            $streetNum = 10 + $i;
            $streetName = ['Marine Drive', 'Beach Road', 'Sea View', 'Coastal Crescent', 'Sunset Way'][$i % 5];
            $tp = TrackedProperty::create([
                'agency_id'              => $agencyId,
                'street_number'          => (string) $streetNum,
                'street_name'            => $streetName,
                'suburb'                 => $suburb,
                'suburb_normalised'      => TrackedProperty::normaliseSuburb($suburb),
                'town'                   => $suburb,
                'province'               => 'KwaZulu-Natal',
                'source_chain'           => [['type' => 'demo_seed', 'ref' => 'mic-enrichment', 'date' => Carbon::now()->toIso8601String()]],
                'first_seen_at'          => Carbon::now()->subDays(rand(1, 60)),
                'last_enriched_at'       => Carbon::now()->subDays(rand(0, 7)),
                'last_enrichment_source' => 'demo_seed',
                'status'                 => TrackedProperty::STATUS_ACTIVE,
            ]);
            TrackedPropertyAddress::create([
                'agency_id'           => $agencyId,
                'tracked_property_id' => $tp->id,
                'street_number'       => (string) $streetNum,
                'street_name'         => $streetName,
                'suburb'              => $suburb,
                'town'                => $suburb,
                'province'            => 'KwaZulu-Natal',
                'source_type'         => TrackedPropertyAddress::SOURCE_CHROME_CAPTURE,
                'source_ref'          => 'demo-seed-' . Str::random(8),
                'confidence'          => TrackedPropertyAddress::CONFIDENCE_MEDIUM,
                'is_primary'          => true,
                'first_seen_at'       => Carbon::now()->subDays(rand(1, 60)),
                'last_seen_at'        => Carbon::now()->subDays(rand(0, 7)),
            ]);
        }

        // Create TPs without addresses (suburb-only) up to the target.
        $needWithout = max(0, self::TARGET_TPS_WITHOUT_ADDRESS - $withoutAddress);
        for ($i = 0; $i < $needWithout; $i++) {
            $suburb = self::COMMON_SUBURBS[$i % count(self::COMMON_SUBURBS)];
            TrackedProperty::create([
                'agency_id'              => $agencyId,
                'suburb'                 => $suburb,
                'suburb_normalised'      => TrackedProperty::normaliseSuburb($suburb),
                'town'                   => $suburb,
                'source_chain'           => [['type' => 'demo_seed', 'ref' => 'mic-enrichment-suburb-only', 'date' => Carbon::now()->toIso8601String()]],
                'first_seen_at'          => Carbon::now()->subDays(rand(1, 90)),
                'last_enriched_at'       => Carbon::now()->subDays(rand(0, 30)),
                'last_enrichment_source' => 'demo_seed',
                'status'                 => TrackedProperty::STATUS_ACTIVE,
            ]);
        }

        if ($needWith + $needWithout > 0) {
            $this->command?->info("    Added: +{$needWith} with address, +{$needWithout} without address.");
        }
    }

    private function topUpClaims(int $agencyId, User $admin): void
    {
        $active = (int) ProspectingClaim::query()
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->count();
        $this->command?->info("  Active claims: {$active} / target " . self::TARGET_ACTIVE_CLAIMS);

        $need = max(0, self::TARGET_ACTIVE_CLAIMS - $active);
        if ($need === 0) return;

        // Use existing listings (cheap, no need to fabricate). Filter by agency.
        $listings = ProspectingListing::query()
            ->where('agency_id', $agencyId)
            ->whereNull('matched_property_id')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereDoesntHave('activeClaim')
            ->orderBy('id')
            ->limit($need)
            ->get();

        $added = 0;
        foreach ($listings as $i => $listing) {
            $hours = $i < 5 ? rand(1, 12) : ($i < 10 ? rand(30, 50) : rand(60, 80));
            ProspectingClaim::create([
                'agency_id'              => $agencyId,
                'prospecting_listing_id' => $listing->id,
                'user_id'                => $admin->id,
                'status'                 => 'pitched',
                'notes'                  => '[demo seed] Demo claim — varied age for tile display.',
                'claimed_at'              => Carbon::now()->subHours($hours),
                'last_updated_at'         => Carbon::now()->subHours($hours),
                'is_active'               => true,
            ]);
            $added++;
        }
        if ($added > 0) $this->command?->info("    Added: +{$added} active claims.");
    }

    private function topUpBuyerMatches(int $agencyId): void
    {
        $count = (int) DB::table('prospecting_buyer_matches as pbm')
            ->join('prospecting_listings as pl', 'pl.id', '=', 'pbm.prospecting_listing_id')
            ->where('pl.agency_id', $agencyId)
            ->count();
        $this->command?->info("  Buyer matches: {$count} / target " . self::TARGET_BUYER_MATCHES);

        if ($count >= self::TARGET_BUYER_MATCHES) return;

        $this->command?->info("    Note: buyer matches generated by ProspectingBuyerMatchEngine — skipping synthetic top-up to avoid corrupting tier scoring.");
    }

    private function topUpP24Listings(int $agencyId): void
    {
        foreach (self::COMMON_SUBURBS as $suburb) {
            $count = (int) DB::table('p24_listings')->where('suburb', $suburb)->count();
            if ($count >= self::TARGET_P24_PER_SUBURB) continue;

            $need = self::TARGET_P24_PER_SUBURB - $count;
            for ($i = 0; $i < $need; $i++) {
                DB::table('p24_listings')->updateOrInsert(
                    ['p24_listing_number' => 'DEMO-' . $suburb . '-' . $i],
                    [
                        // agency_id is NOT NULL with no default on this schema (added after
                        // this seeder was written) — omitting it 500s the insert outright.
                        'agency_id'          => $agencyId,
                        'suburb'             => $suburb,
                        'property_type'      => ['House', 'Apartment', 'Townhouse'][$i % 3],
                        'asking_price'       => 1_500_000 + $i * 250_000,
                        'bedrooms'           => 2 + ($i % 4),
                        'bathrooms'          => 1 + ($i % 3),
                        'listing_status'     => 'active',
                        'first_seen_date'    => Carbon::now()->subDays(rand(1, 60))->toDateString(),
                        // last_seen_date is likewise NOT NULL with no default — same gap.
                        'last_seen_date'     => Carbon::now()->toDateString(),
                        'p24_url'            => 'https://www.property24.com/demo/' . $suburb . '/' . $i,
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ],
                );
            }
            $this->command?->info("  P24 listings (+{$need}) for {$suburb}");
        }
    }

    private function topUpMarketReports(int $agencyId, User $admin): void
    {
        $count = (int) MarketReport::query()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->count();
        $this->command?->info("  Market reports: {$count} / target " . self::TARGET_MARKET_REPORTS);

        $need = max(0, self::TARGET_MARKET_REPORTS - $count);
        for ($i = 0; $i < $need; $i++) {
            $suburb = self::COMMON_SUBURBS[$i % count(self::COMMON_SUBURBS)];
            $report = MarketReport::create([
                'agency_id'           => $agencyId,
                'uploaded_by_user_id' => $admin->id,
                'report_type_id'      => 1, // cma_info_market_analysis
                'file_path'           => 'market-reports/demo/seed-' . $i . '.pdf',
                'file_name'           => "demo-cma-{$suburb}.pdf",
                'file_hash'           => hash('sha256', 'demo-seed-' . $suburb . '-' . $i),
                'source_suburb'       => $suburb,
                'source_town'         => $suburb,
                'report_date'         => Carbon::now()->subDays($i * 30)->toDateString(),
                'parse_status'        => MarketReport::PARSE_PARSED,
                'parser_version'      => '1.0.0',
                'data_points_count'   => 4,
                'spot_check_status'   => MarketReport::SPOT_PASSED,
                'parse_started_at'    => Carbon::now()->subDays($i * 30),
                'parse_completed_at'  => Carbon::now()->subDays($i * 30),
            ]);

            // Seed 4 data points per report so the Property Intel + suburb
            // deep-dive surfaces have real numbers.
            $points = [
                ['metric_key' => 'suburb_median_price_12m', 'metric_value_numeric' => 1_800_000 + $i * 100_000, 'confidence' => 'high'],
                ['metric_key' => 'suburb_total_sales_12m',  'metric_value_numeric' => 45 + $i * 5,             'confidence' => 'high'],
                ['metric_key' => 'cma_value_lower',         'metric_value_numeric' => 1_500_000 + $i * 80_000,  'confidence' => 'medium'],
                ['metric_key' => 'cma_value_upper',         'metric_value_numeric' => 2_100_000 + $i * 120_000, 'confidence' => 'medium'],
            ];
            foreach ($points as $p) {
                MarketDataPoint::create([
                    'agency_id'            => $agencyId,
                    'report_id'            => $report->id,
                    'suburb_normalised'    => TrackedProperty::normaliseSuburb($suburb),
                    'town'                 => $suburb,
                    'metric_key'           => $p['metric_key'],
                    'metric_value_numeric' => $p['metric_value_numeric'],
                    'metric_date'          => Carbon::now()->toDateString(),
                    'confidence'           => $p['confidence'],
                    'source_type'          => 'market_report',
                    'source_ref'           => 'report:' . $report->id,
                ]);
            }
        }
        if ($need > 0) $this->command?->info("    Added: +{$need} parsed market reports with 4 data points each.");
    }

    private function warmAiCacheRows(int $agencyId, User $admin): void
    {
        // Pre-seed a weekly_brief cache row for the agency so demo walkthroughs
        // don't burn fresh Anthropic tokens on every load. The row mimics the
        // shape StrategicBriefService writes.
        $week = Carbon::now()->format('o-W');
        $key = "weekly_brief:agency:{$agencyId}:week:{$week}";
        $existing = AINarrativeCache::query()->where('cache_key', $key)->whereNull('deleted_at')->first();
        if ($existing) {
            $this->command?->info("  AI cache: weekly_brief row exists (id={$existing->id}) — skipping.");
            return;
        }
        AINarrativeCache::create([
            'agency_id'      => $agencyId,
            'narrative_type' => AINarrativeCache::TYPE_WEEKLY_BRIEF,
            'cache_key'      => $key,
            'input_hash'     => hash('sha256', $key . ':demo-seed'),
            'prompt_version' => 'v1',
            'model'          => 'demo-seed',
            'input_tokens'   => 0,
            'output_tokens'  => 0,
            'cost_zar'       => 0,
            'output_text'    => 'Margate 3-bed homes are the standout opportunity this week, with 8 strong-tier buyers chasing 2 active listings. RE/MAX Coast and Country holds 31% of the suburb supply — securing extra mandates there directly counters their share.',
            'generated_at'   => Carbon::now(),
            'expires_at'     => Carbon::now()->addHours(24),
        ]);
        $this->command?->info('  AI cache: pre-seeded weekly_brief row.');
    }
}
