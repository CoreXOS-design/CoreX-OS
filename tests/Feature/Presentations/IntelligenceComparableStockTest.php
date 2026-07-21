<?php

namespace Tests\Feature\Presentations;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Presentations\CompetitorStockMatchService;
use App\Services\PropertyIntelligenceService;
use App\Services\TitleTypeClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-288 — the Property Intelligence "Comparable Listings" set must use the SAME
 * vetted competitive-stock rules (on-market only, same title-family, price band,
 * beds tolerance, suburb scope) — no junk: no off-market, no wrong-type, no
 * out-of-band, no wrong-suburb, no foreign-agency, never the subject itself.
 */
class IntelligenceComparableStockTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Shelly Beach']);
        $this->agent  = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
    }

    private function makeProp(array $over = [], ?int $agencyId = null): Property
    {
        return Property::withoutEvents(fn () => Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'external_id'  => 'T-' . Str::random(8),
            'title'        => 'Home ' . Str::random(4),
            'address'      => '12 Marine Dr',
            'suburb'       => 'Shelly Beach',
            'agent_id'     => $this->agent->id,
            'agency_id'    => $agencyId ?? $this->agency->id,
            'branch_id'    => $this->branch->id,
            'price'        => 2_000_000,
            'beds'         => 3,
            'baths'        => 2,
            'garages'      => 2,
            'size_m2'      => 180,
            'erf_size_m2'  => 500,
            'property_type' => 'House',
            'title_type'   => TitleTypeClassifier::TITLE_FULL,
            'listing_type' => 'sale',
            'status'       => 'active',
        ], $over)));
    }

    public function test_only_clean_on_market_in_band_same_family_stock_is_returned(): void
    {
        $subject = $this->makeProp();

        $good      = $this->makeProp(['title' => 'GOOD comp']);                              // identical-ish, on-market
        $sold      = $this->makeProp(['title' => 'SOLD comp', 'status' => 'sold']);          // off-market → junk
        $withdrawn = $this->makeProp(['title' => 'WITHDRAWN comp', 'status' => 'withdrawn']);// off-market → junk
        $commercial = $this->makeProp(['title' => 'COMMERCIAL', 'property_type' => 'Commercial', 'title_type' => null]);
        $outOfBand = $this->makeProp(['title' => 'PRICEY', 'price' => 3_500_000]);           // out of ±20% band
        $wrongSub  = $this->makeProp(['title' => 'MARGATE', 'suburb' => 'Margate']);         // wrong suburb
        $foreign   = $this->makeProp(['title' => 'FOREIGN'], Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()])->id);

        $result = app(CompetitorStockMatchService::class)->findComparableStock($subject);
        $ids = $result->pluck('id')->all();

        $this->assertContains($good->id, $ids, 'a clean on-market in-band same-family comp must be included');
        $this->assertNotContains($subject->id, $ids, 'never the subject itself');
        $this->assertNotContains($sold->id, $ids, 'SOLD (off-market) must be excluded — the primary junk driver');
        $this->assertNotContains($withdrawn->id, $ids, 'WITHDRAWN (off-market) must be excluded');
        $this->assertNotContains($commercial->id, $ids, 'commercial must be excluded');
        $this->assertNotContains($outOfBand->id, $ids, 'out-of-band price must be excluded');
        $this->assertNotContains($wrongSub->id, $ids, 'wrong suburb must be excluded');
        $this->assertNotContains($foreign->id, $ids, 'foreign-agency stock must be excluded');
    }

    public function test_intelligence_page_maps_the_clean_set_to_the_blade_shape(): void
    {
        $subject = $this->makeProp();
        $good    = $this->makeProp(['title' => 'GOOD comp']);
        $this->makeProp(['title' => 'SOLD comp', 'status' => 'sold']);

        $comps = app(PropertyIntelligenceService::class)->getComparableListings($subject->id);

        $this->assertNotEmpty($comps);
        $first = $comps->first();
        $this->assertEqualsCanonicalizing(['id', 'title', 'price', 'suburb', 'days_on_market'], array_keys($first));
        $this->assertTrue($comps->contains(fn ($c) => $c['id'] === $good->id));
        $this->assertFalse($comps->contains(fn ($c) => $c['title'] === 'SOLD comp'), 'no off-market junk on the intelligence page');
    }

    public function test_subject_without_suburb_returns_empty_never_junk(): void
    {
        // A subject with no suburb cannot be scoped — the old code fell back to the
        // WHOLE agency's stock (junk); the shared criteria returns null → empty set.
        $subject = $this->makeProp(['suburb' => '']);
        $this->makeProp(['title' => 'would-be comp']);

        $this->assertTrue(
            app(CompetitorStockMatchService::class)->findComparableStock($subject)->isEmpty(),
            'a subject with no suburb cannot be compared — empty, never a wide junk fallback'
        );
    }
}
