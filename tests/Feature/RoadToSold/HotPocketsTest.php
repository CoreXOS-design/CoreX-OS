<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Services\RoadToSold\HotPocketService;
use App\Services\RoadToSold\Selections\HotPocketSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * AT-454 pieces 4 + 6 — hottest sales pockets: buyer demand by suburb PLUS the agency's own leads and
 * sales by suburb (the join the investigation found: portal_leads.listing_id and deals_v2.property_id
 * both resolve to properties).
 */
final class HotPocketsTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private HotPocketService $svc;
    private Carbon $asOf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->svc = new HotPocketService();
        $this->asOf = Carbon::parse('2026-10-14');
    }

    private function lead(int $propertyId, array $x = [], ?int $agencyId = null): void
    {
        DB::table('portal_leads')->insert(array_merge([
            'agency_id' => $agencyId ?? $this->agency->id, 'portal' => 'p24', 'lead_type' => 'Email',
            'listing_id' => $propertyId, 'name' => 'Enquirer', 'received_at' => '2026-10-01 10:00:00', 'lead_source_raw' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ], $x));
    }

    private function deal(int $propertyId, string $status = 'completed', string $offer = '2026-09-01', ?int $agencyId = null): void
    {
        DB::table('deals_v2')->insert([
            'agency_id' => $agencyId ?? $this->agency->id, 'reference' => 'D-' . uniqid(), 'deal_type' => 'cash', 'status' => $status,
            'property_id' => $propertyId, 'offer_date' => $offer, 'purchase_price' => 1_500_000,
            'branch_id' => $this->branch->id, 'commission_amount' => 0, 'commission_vat' => 0,
            'created_by_id' => $this->agent->id, 'listing_agent_id' => $this->agent->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_pockets_score_is_buyers_plus_leads_plus_sales_by_suburb(): void
    {
        // Uvongo: 2 buyers (one buyer on two listings counts once), 3 leads, 1 sale
        $b1 = $this->buyer($this->agent->id); $b2 = $this->buyer($this->mate->id);
        $u1 = $this->listing(['suburb' => 'Uvongo']); $u2 = $this->listing(['suburb' => 'Uvongo']);
        $this->buyerMatch($u1, $b1->id); $this->buyerMatch($u2, $b1->id); $this->buyerMatch($u2, $b2->id);
        $uvongoProp = $this->property($this->agent->id, ['suburb' => 'Uvongo']);
        foreach ([1, 2, 3] as $_) { $this->lead($uvongoProp); }
        $this->deal($uvongoProp);
        // Margate: 1 sale only
        $this->deal($this->property($this->agent->id, ['suburb' => 'Margate']));

        $r = $this->svc->rank($this->agency->id, 'sales', $this->asOf, 180);

        $this->assertSame(['uvongo', 'margate'], array_keys($r));
        $this->assertSame(['buyers' => 2, 'leads' => 3, 'sales' => 1, 'score' => 6], array_intersect_key($r['uvongo'], array_flip(['buyers', 'leads', 'sales', 'score'])));
        $this->assertSame(['buyers' => 0, 'leads' => 0, 'sales' => 1, 'score' => 1], array_intersect_key($r['margate'], array_flip(['buyers', 'leads', 'sales', 'score'])));
        $this->assertSame('2 buyers, 3 leads, 1 sale in Uvongo', $this->svc->describe($r['uvongo']));
        $this->assertSame('1 sale in Margate', $this->svc->describe($r['margate']));
    }

    public function test_only_real_leads_and_real_sales_inside_the_window_count(): void
    {
        $p = $this->property($this->agent->id, ['suburb' => 'Ramsgate']);
        $rental = $this->property($this->agent->id, ['suburb' => 'Ramsgate', 'listing_type' => 'rental']);

        $this->lead($p);                                                         // counts
        $this->lead($p, ['lead_type' => 'Test']);                                // a test enquiry
        $this->lead($p, ['received_at' => '2026-01-01 10:00:00']);                // outside the window
        $this->lead($rental);                                                    // a rental enquiry is not a SALES signal
        $this->deal($p, 'completed');                                            // counts
        $this->deal($p, 'granted');                                              // counts
        $this->deal($p, 'active');                                               // an offer in progress counts
        $this->deal($p, 'cancelled'); $this->deal($p, 'declined'); $this->deal($p, 'on_hold');
        $this->deal($p, 'completed', '2025-01-01');                              // outside the window

        $r = $this->svc->rank($this->agency->id, 'sales', $this->asOf, 180);

        $this->assertSame(1, $r['ramsgate']['leads']);
        $this->assertSame(3, $r['ramsgate']['sales']);
    }

    public function test_the_window_is_the_agency_setting_passed_in(): void
    {
        $p = $this->property($this->agent->id, ['suburb' => 'Ramsgate']);
        $this->lead($p, ['received_at' => '2026-09-01 10:00:00']);

        $this->assertArrayNotHasKey('ramsgate', $this->svc->rank($this->agency->id, 'sales', $this->asOf, 10));
        $this->assertArrayHasKey('ramsgate', $this->svc->rank($this->agency->id, 'sales', $this->asOf, 90));
    }

    public function test_another_agencys_buyers_leads_and_sales_never_count(): void
    {
        $other = \App\Models\Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'o-' . uniqid()]);
        $otherUser = $this->makeAgent($other, \App\Models\Branch::create(['agency_id' => $other->id, 'name' => 'Main']));
        $theirProp = $this->property($this->agent->id, ['suburb' => 'Uvongo'], $other);
        $this->lead($theirProp, [], $other->id);
        $this->deal($theirProp, 'completed', '2026-09-01', $other->id);
        $theirs = $this->listing(['suburb' => 'Uvongo'], $this->tp([], $other), $other);
        $this->buyerMatch($theirs, $this->buyer($otherUser->id, 'new', null, $other)->id, 'strong', 90, $other);

        $this->assertSame([], $this->svc->rank($this->agency->id, 'sales', $this->asOf, 180));
    }

    public function test_the_rentals_track_has_no_pockets_yet_and_a_missing_agency_is_safe(): void
    {
        $this->deal($this->property($this->agent->id, ['suburb' => 'Uvongo']));

        $this->assertSame([], $this->svc->rank($this->agency->id, 'rentals', $this->asOf, 180));
        $this->assertSame([], $this->svc->rank(0, 'sales', $this->asOf, 180));
    }

    public function test_the_selection_offers_listings_in_the_hottest_pockets_hottest_first(): void
    {
        $hotProp = $this->property($this->agent->id, ['suburb' => 'Uvongo']);
        $warmProp = $this->property($this->agent->id, ['suburb' => 'Margate']);
        foreach ([1, 2, 3] as $_) { $this->lead($hotProp); }
        $this->lead($warmProp);

        $warm = $this->listing(['suburb' => 'Margate']);
        $hot  = $this->listing(['suburb' => 'Uvongo']);
        $cold = $this->listing(['suburb' => 'Port Edward']);

        $sel = new HotPocketSelection($this->svc);
        $c = $sel->candidates($this->ctx(), 50);

        $this->assertSame([$hot, $warm], $this->listingIds($c));
        $this->assertSame('Hot pocket: 3 leads in Uvongo', $c[0]->reason);
        $this->assertNotContains($cold, $this->listingIds($c));
    }

    public function test_the_selection_is_empty_when_there_are_no_pockets(): void
    {
        $this->listing(['suburb' => 'Uvongo']);

        $this->assertSame([], (new HotPocketSelection($this->svc))->candidates($this->ctx(), 50));
    }

    public function test_within_a_pocket_the_address_comes_first_and_the_agents_area_before_the_rest(): void
    {
        $this->lead($this->property($this->agent->id, ['suburb' => 'Uvongo']));
        $this->lead($this->property($this->agent->id, ['suburb' => 'Margate']));

        $uvNoAddr = $this->listing(['suburb' => 'Uvongo'], $this->tp(['suburb_normalised' => 'uvongo']));
        $uvAddr   = $this->listing(['suburb' => 'Uvongo', 'address' => '4 Hill Rd'], $this->tp(['suburb_normalised' => 'uvongo']));
        $mgAddr   = $this->listing(['suburb' => 'Margate', 'address' => '9 Sea Rd']);

        $c = (new HotPocketSelection($this->svc))->candidates($this->ctx(areaNames: ['margate']), 50);

        $this->assertSame($mgAddr, $c[0]->prospectingListingId, 'the agent\'s own suburb leads');
        $this->assertSame([$uvAddr, $uvNoAddr], array_slice($this->listingIds($c), 1));
    }
}
