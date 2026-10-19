<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Http\Controllers\Tools\AdTemplateManagerController;
use App\Models\Agency;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyShowday;
use App\Models\User;
use App\Services\AdTemplates\AdListingFacts;
use App\Services\AdTemplates\ListingQrMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * AT-456 §B.9-1 — the server half of the building blocks: what Property::adData() hands the ad render kernel.
 *
 * Every new fact is ready-to-print text or '' (and an element with '' is left off a real ad, so a listing
 * without a levy shows no levy — never "R 0" / "null"). Facts come from the property's OWN columns; sales and
 * rentals differ; show day and auction come from CoreX's own records; the QR points at THIS agency's page.
 */
final class AdBuildingBlocksFactsTest extends TestCase
{
    use RefreshDatabase;

    private const FACT_KEYS = ['erf_size', 'levy', 'rates_taxes', 'price_per_m2', 'pet_friendly', 'available_from', 'rent_per_month', 'show_day', 'auction_date', 'auction_venue'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── sales facts ──────────────────────────────────────────────────────

    public function test_a_sale_listing_reports_erf_levy_rates_price_per_m2_and_pet_friendly(): void
    {
        [$agency, $user] = $this->agencyWithAgent();
        $p = $this->property($agency, $user, [
            'price' => 2450000, 'size_m2' => 320, 'erf_size_m2' => 1200, 'levy' => 1250, 'rates_taxes' => 900, 'pet_friendly' => true,
        ]);

        $d = $p->adData();

        $this->assertSame('1 200 m²', $d['erf_size']);
        $this->assertSame('R 1 250 pm', $d['levy']);
        $this->assertSame('R 900 pm', $d['rates_taxes']);
        $this->assertSame('R 7 656 per m²', $d['price_per_m2']);          // 2 450 000 ÷ 320 = 7 656.25
        $this->assertSame('Pet friendly', $d['pet_friendly']);
        // Rental-only facts stay empty on a sale.
        $this->assertSame('', $d['rent_per_month']);
        $this->assertSame('', $d['available_from']);
    }

    public function test_a_listing_with_nothing_prints_nothing_never_r0_or_null(): void
    {
        [$agency, $user] = $this->agencyWithAgent();
        $p = $this->property($agency, $user, ['price' => 0, 'size_m2' => null, 'levy' => 0, 'rates_taxes' => null, 'erf_size_m2' => null, 'pet_friendly' => null]);

        $d = $p->adData();

        foreach (self::FACT_KEYS as $k) {
            $this->assertSame('', $d[$k], "{$k} must be '' when the listing has no value");
        }
        $this->assertStringNotContainsString('R 0', implode('|', array_intersect_key($d, array_flip(self::FACT_KEYS))));
    }

    public function test_pet_friendly_only_shows_when_the_listing_says_yes(): void
    {
        [$agency, $user] = $this->agencyWithAgent();

        $this->assertSame('', $this->property($agency, $user, ['pet_friendly' => false])->adData()['pet_friendly']);
        $this->assertSame('', $this->property($agency, $user, ['pet_friendly' => null])->adData()['pet_friendly']);
        $this->assertSame('Pet friendly', $this->property($agency, $user, ['pet_friendly' => true])->adData()['pet_friendly']);
    }

    public function test_price_per_m2_is_sales_only_and_needs_a_price_and_a_floor_size(): void
    {
        [$agency, $user] = $this->agencyWithAgent();

        $this->assertSame('', $this->property($agency, $user, ['price' => 900000, 'size_m2' => null])->adData()['price_per_m2']);
        $this->assertSame('', $this->property($agency, $user, ['price' => 0, 'size_m2' => 100])->adData()['price_per_m2']);
        $this->assertSame('', $this->property($agency, $user, ['price' => 900000, 'size_m2' => 100, 'price_on_application' => true])->adData()['price_per_m2']);
        $this->assertSame('', $this->property($agency, $user, ['price' => 900000, 'size_m2' => 100, 'status' => 'sold'])->adData()['price_per_m2']);
        $this->assertSame('', $this->property($agency, $user, ['listing_type' => 'rental', 'rental_amount' => 9000, 'price' => 900000, 'size_m2' => 100])->adData()['price_per_m2']);
        $this->assertSame('R 9 000 per m²', $this->property($agency, $user, ['price' => 900000, 'size_m2' => 100])->adData()['price_per_m2']);
    }

    public function test_a_large_plot_captured_in_hectares_reads_in_hectares(): void
    {
        [$agency, $user] = $this->agencyWithAgent();

        $this->assertSame('2.5 ha', $this->property($agency, $user, ['erf_size_m2' => 25000, 'erf_area_unit' => 'Hectares'])->adData()['erf_size']);
        $this->assertSame('25 000 m²', $this->property($agency, $user, ['erf_size_m2' => 25000])->adData()['erf_size']);
        $this->assertSame('850 m²', $this->property($agency, $user, ['erf_size_m2' => 850, 'erf_area_unit' => 'ha'])->adData()['erf_size']);
    }

    // ── rental facts ─────────────────────────────────────────────────────

    public function test_a_rental_reports_rent_with_its_price_type_and_available_now(): void
    {
        [$agency, $user] = $this->agencyWithAgent();
        $r = $this->property($agency, $user, ['listing_type' => 'rental', 'price' => 0, 'rental_amount' => 12500, 'rental_price_type' => 'per month']);

        $d = $r->adData();

        $this->assertSame('R 12 500 per month', $d['rent_per_month']);
        $this->assertSame('Available now', $d['available_from']);
    }

    public function test_the_rent_unit_follows_the_agencys_rental_price_type(): void
    {
        [$agency, $user] = $this->agencyWithAgent();
        $rent = fn (?string $type) => $this->property($agency, $user, ['listing_type' => 'rental', 'price' => 0, 'rental_amount' => 800, 'rental_price_type' => $type])->adData()['rent_per_month'];

        $this->assertSame('R 800 per month', $rent(null));
        $this->assertSame('R 800 per month', $rent('Monthly'));
        $this->assertSame('R 800 per week', $rent('per week'));
        $this->assertSame('R 800 per day', $rent('Per Day'));
        $this->assertSame('R 800 per year', $rent('annually'));
        $this->assertSame('R 800 per m²', $rent('per sqm'));
        $this->assertSame('R 800 per fortnight', $rent('per fortnight'));
        $this->assertSame('R 800 per month', $rent('whatever the agency typed'));
    }

    public function test_available_from_shows_the_date_when_it_is_in_the_future_and_respects_the_hide_switch(): void
    {
        Carbon::setTestNow('2026-10-17 08:00:00');
        [$agency, $user] = $this->agencyWithAgent();
        $mk = fn (array $a) => $this->property($agency, $user, array_merge(['listing_type' => 'rental', 'price' => 0, 'rental_amount' => 9000], $a))->adData()['available_from'];

        $this->assertSame('Available from 1 Nov 2026', $mk(['lease_start_date' => '2026-11-01']));
        $this->assertSame('Available now', $mk(['lease_start_date' => '2026-10-17']));            // today = now
        $this->assertSame('Available now', $mk(['lease_start_date' => '2026-09-01']));            // already passed
        $this->assertSame('', $mk(['lease_start_date' => '2026-11-01', 'show_available_from_on_portals' => false]));
        $this->assertSame('', $mk(['status' => 'rented']));
        $this->assertSame('', $mk(['status' => 'let_out']));
    }

    public function test_a_closed_rental_advertises_no_rent(): void
    {
        [$agency, $user] = $this->agencyWithAgent();

        $this->assertSame('', $this->property($agency, $user, ['listing_type' => 'rental', 'price' => 0, 'rental_amount' => 9000, 'status' => 'rented'])->adData()['rent_per_month']);
        $this->assertSame('', $this->property($agency, $user, ['listing_type' => 'rental', 'price' => 0, 'rental_amount' => 0])->adData()['rent_per_month']);
    }

    // ── show day ─────────────────────────────────────────────────────────

    public function test_show_day_is_the_next_upcoming_one_with_date_and_time_range(): void
    {
        Carbon::setTestNow('2026-10-17 08:00:00');
        [$agency, $user] = $this->agencyWithAgent();
        $this->actingAs($user);
        $p = $this->property($agency, $user, []);

        $this->showday($agency, $p, '2026-10-10 10:00', '2026-10-10 13:00');                    // over
        $this->showday($agency, $p, '2026-10-31 10:00', '2026-10-31 13:00');                    // later
        $this->showday($agency, $p, '2026-10-24 10:00', '2026-10-24 13:00');                    // the next one
        $this->showday($agency, $p, '2026-10-20 10:00', '2026-10-20 13:00', active: false);    // switched off

        $this->assertSame('Sat 24 Oct · 10:00–13:00', $p->adData()['show_day']);
    }

    public function test_a_show_day_in_progress_still_counts_and_a_multi_day_one_names_both_days(): void
    {
        Carbon::setTestNow('2026-10-24 11:00:00');
        [$agency, $user] = $this->agencyWithAgent();
        $this->actingAs($user);
        $p = $this->property($agency, $user, []);
        $this->showday($agency, $p, '2026-10-24 10:00', '2026-10-24 13:00');
        $this->assertSame('Sat 24 Oct · 10:00–13:00', $p->adData()['show_day']);

        $q = $this->property($agency, $user, []);
        $this->showday($agency, $q, '2026-10-24 10:00', '2026-10-25 15:30');
        $this->assertSame('Sat 24 Oct 10:00 – Sun 25 Oct 15:30', $q->adData()['show_day']);
    }

    public function test_no_show_day_or_only_past_ones_means_nothing_to_show(): void
    {
        Carbon::setTestNow('2026-10-17 08:00:00');
        [$agency, $user] = $this->agencyWithAgent();
        $this->actingAs($user);
        $p = $this->property($agency, $user, []);
        $this->assertSame('', $p->adData()['show_day']);

        $this->showday($agency, $p, '2026-10-01 10:00', '2026-10-01 13:00');
        $this->assertSame('', $p->adData()['show_day']);
    }

    public function test_a_show_day_of_another_property_never_appears(): void
    {
        Carbon::setTestNow('2026-10-17 08:00:00');
        [$agency, $user] = $this->agencyWithAgent();
        $this->actingAs($user);
        $mine   = $this->property($agency, $user, []);
        $theirs = $this->property($agency, $user, []);
        $this->showday($agency, $theirs, '2026-10-24 10:00', '2026-10-24 13:00');

        $this->assertSame('', $mine->adData()['show_day']);
    }

    // ── auction ──────────────────────────────────────────────────────────

    public function test_auction_date_and_venue_come_from_the_open_auction_lot(): void
    {
        Carbon::setTestNow('2026-10-17 08:00:00');
        [$agency, $user, $branch] = $this->agencyWithAgent();
        $this->actingAs($user);
        $p = $this->property($agency, $user, []);
        $this->lot($agency, $branch, $p, ['starts_at' => '2026-11-12 11:00:00', 'venue_name' => 'The Town Hall', 'venue_address' => '1 Main Rd, Margate', 'status' => Auction::STATUS_SCHEDULED]);

        $d = $p->adData();

        $this->assertSame('12 Nov 2026 · 11:00', $d['auction_date']);
        $this->assertSame('The Town Hall, 1 Main Rd, Margate', $d['auction_venue']);
    }

    public function test_an_auction_that_is_not_announced_cancelled_postponed_over_or_already_started_shows_nothing(): void
    {
        Carbon::setTestNow('2026-10-17 08:00:00');
        [$agency, $user, $branch] = $this->agencyWithAgent();
        $this->actingAs($user);

        foreach ([Auction::STATUS_DRAFT, Auction::STATUS_CANCELLED, Auction::STATUS_POSTPONED, Auction::STATUS_CLOSED, Auction::STATUS_SETTLED] as $status) {
            $p = $this->property($agency, $user, []);
            $this->lot($agency, $branch, $p, ['starts_at' => '2026-11-12 11:00:00', 'venue_name' => 'Hall', 'status' => $status]);
            $d = $p->adData();
            $this->assertSame('', $d['auction_date'], "auction in status {$status} must not be advertised");
            $this->assertSame('', $d['auction_venue']);
        }

        $started = $this->property($agency, $user, []);
        $this->lot($agency, $branch, $started, ['starts_at' => '2026-10-01 11:00:00', 'venue_name' => 'Hall', 'status' => Auction::STATUS_SCHEDULED]);
        $this->assertSame('', $started->adData()['auction_date']);

        $concluded = $this->property($agency, $user, []);
        $this->lot($agency, $branch, $concluded, ['starts_at' => '2026-11-12 11:00:00', 'venue_name' => 'Hall', 'status' => Auction::STATUS_SCHEDULED], AuctionLot::STATUS_SOLD);
        $this->assertSame('', $concluded->adData()['auction_date']);

        $none = $this->property($agency, $user, []);
        $this->assertSame('', $none->adData()['auction_date']);
    }

    public function test_an_online_only_auction_without_a_venue_says_online_auction(): void
    {
        Carbon::setTestNow('2026-10-17 08:00:00');
        [$agency, $user, $branch] = $this->agencyWithAgent();
        $this->actingAs($user);
        $p = $this->property($agency, $user, []);
        $this->lot($agency, $branch, $p, ['starts_at' => '2026-11-12 09:30:00', 'is_online_streamed' => true, 'status' => Auction::STATUS_REGISTRATION_OPEN]);

        $d = $p->adData();

        $this->assertSame('12 Nov 2026 · 09:30', $d['auction_date']);
        $this->assertSame('Online auction', $d['auction_venue']);
    }

    // ── QR code ──────────────────────────────────────────────────────────

    public function test_the_qr_points_at_each_agencys_own_listing_page(): void
    {
        [$a, $ua] = $this->agencyWithAgent(['website_url' => 'https://alpha-realty.test']);
        [$b, $ub] = $this->agencyWithAgent([]);
        $pa = $this->property($a, $ua, ['title' => 'Sea Villa']);
        $pb = $this->property($b, $ub, ['title' => 'Sea Villa']);

        $da = $pa->adData();
        $db = $pb->adData();

        // Agency A has its own website: the code opens THAT site. Agency B has none: CoreX's public page for B.
        $this->assertSame("https://alpha-realty.test/property/sea-villa-{$pa->id}", $da['public_url']);
        $this->assertStringContainsString('/' . Agency::query()->withoutGlobalScopes()->find($b)->slug . '/properties/' . $pb->id, $db['public_url']);
        $this->assertStringNotContainsString('alpha-realty', $db['public_url']);
        $this->assertSame(ListingQrMatrix::forUrl($da['public_url']), $da['qr_matrix']);
        $this->assertSame(ListingQrMatrix::forUrl($db['public_url']), $db['qr_matrix']);
        $this->assertNotSame($da['qr_matrix'], $db['qr_matrix']);
    }

    public function test_the_qr_matrix_is_a_square_grid_of_ones_and_zeros_with_the_three_finder_squares(): void
    {
        $rows = ListingQrMatrix::forUrl('https://example-agency.test/property/seaside-villa-123');
        $n = count($rows);

        $this->assertGreaterThanOrEqual(21, $n);
        foreach ($rows as $row) {
            $this->assertSame($n, strlen($row));
            $this->assertMatchesRegularExpression('/^[01]+$/', $row);
        }
        // Finder patterns: a 7×7 dark ring at three corners (not the fourth).
        $this->assertSame('1111111', substr($rows[0], 0, 7));
        $this->assertSame('1111111', substr($rows[0], $n - 7));
        $this->assertSame('1111111', substr($rows[$n - 7], 0, 7));
        $this->assertSame('1000001', substr($rows[1], 0, 7));
        $this->assertNotSame('1111111', substr($rows[$n - 7], $n - 7));
    }

    public function test_a_link_that_cannot_be_encoded_gives_no_code_rather_than_a_broken_one(): void
    {
        $this->assertSame([], ListingQrMatrix::forUrl(''));
        $this->assertSame([], ListingQrMatrix::forUrl(null));
        $this->assertSame([], ListingQrMatrix::forUrl('javascript:alert(1)'));
        $this->assertSame([], ListingQrMatrix::forUrl('https://x.test/' . str_repeat('a', 300)));
    }

    // ── payload shape + sample ───────────────────────────────────────────

    public function test_ad_data_always_carries_every_fact_key_and_the_qr_keys(): void
    {
        [$agency, $user] = $this->agencyWithAgent();
        $d = $this->property($agency, $user, [])->adData();

        foreach (array_merge(self::FACT_KEYS, ['public_url', 'qr_matrix']) as $k) {
            $this->assertArrayHasKey($k, $d, "adData() must carry {$k}");
        }
        // Existing keys are untouched.
        foreach (['price', 'title', 'features_list', 'size_m2', 'land_size_m2', 'agent_name', 'status_badge'] as $k) {
            $this->assertArrayHasKey($k, $d);
        }
    }

    public function test_the_template_manager_sample_listing_has_neutral_values_and_the_agencys_own_qr(): void
    {
        [$agency, $user] = $this->agencyWithAgent(['website_url' => 'https://alpha-realty.test']);
        $this->actingAs($user);
        $sample = $this->callSample($user, Agency::query()->withoutGlobalScopes()->find($agency));

        foreach (self::FACT_KEYS as $k) {
            $this->assertArrayHasKey($k, $sample);
        }
        $this->assertSame('R 1 250 pm', $sample['levy']);
        $this->assertNotSame('', $sample['show_day']);
        $this->assertSame(ListingQrMatrix::forUrl('https://alpha-realty.test'), $sample['qr_matrix']);
        $this->assertStringNotContainsString('hfc', strtolower(json_encode($sample)), 'no HFC wording in the sample listing');

        // An agency with no website: no matrix, so the kernel draws its neutral sample code instead.
        [$b, $ub] = $this->agencyWithAgent([]);
        $this->assertSame([], $this->callSample($ub, Agency::query()->withoutGlobalScopes()->find($b))['qr_matrix']);
    }

    public function test_money_is_formatted_like_the_listing_price(): void
    {
        $this->assertSame('R 2 450 000', AdListingFacts::money(2450000));
        $this->assertSame('R 7 656', AdListingFacts::money(7656.25));
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    /** @return array{0:int,1:User,2:int} [agencyId, user, branchId] */
    private function agencyWithAgent(array $agencyAttrs = []): array
    {
        $agency = Agency::create(array_merge(['name' => 'Test Agency ' . uniqid(), 'slug' => 'test-' . uniqid()], $agencyAttrs));
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $user   = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        return [$agency->id, $user, $branch->id];
    }

    private function property(int $agencyId, User $agent, array $attrs): Property
    {
        return Property::create(array_merge([
            'agency_id' => $agencyId, 'branch_id' => $agent->branch_id, 'agent_id' => $agent->id,
            'title' => 'Test property', 'status' => 'active', 'listing_type' => 'sale', 'property_type' => 'house',
            'price' => 1000000, 'suburb' => 'Uvongo', 'city' => 'Margate', 'province' => 'KwaZulu-Natal',
        ], $attrs));
    }

    private function showday(int $agencyId, Property $p, string $start, string $end, bool $active = true): PropertyShowday
    {
        return PropertyShowday::create([
            'agency_id' => $agencyId, 'property_id' => $p->id, 'start_date' => $start, 'end_date' => $end,
            'description' => 'Open Showday', 'active' => $active,
        ]);
    }

    private function lot(int $agencyId, int $branchId, Property $p, array $auction, string $lotStatus = AuctionLot::STATUS_CATALOGUED): AuctionLot
    {
        $a = Auction::create(array_merge([
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'reference' => 'AUC-' . uniqid(), 'title' => 'Test auction',
            'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal',
        ], $auction));

        return AuctionLot::create([
            'agency_id' => $agencyId, 'auction_id' => $a->id, 'property_id' => $p->id, 'lot_number' => 1, 'status' => $lotStatus,
        ]);
    }

    private function callSample(User $user, ?Agency $agency): array
    {
        $m = new \ReflectionMethod(AdTemplateManagerController::class, 'samplePropertyData');
        $m->setAccessible(true);

        return $m->invoke(app(AdTemplateManagerController::class), $user, $agency);
    }
}
