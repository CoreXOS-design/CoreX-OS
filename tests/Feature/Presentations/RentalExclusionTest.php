<?php

namespace Tests\Feature\Presentations;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Presentations\CompetitorStockMatchService;
use App\Services\Presentations\PresentationGeneratorService;
use App\Services\PropertyIntelligenceService;
use App\Support\Presentations\SubjectFieldCompleteness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-400 (Johan, 2026-09-10) — rentals are excluded from Presentations,
 * comparables, and the "Market Positioning" card: CoreX doesn't have rental
 * comp data the way it has sale comp data, so a rental is never compared to
 * other stock. Every gate below is per-method (not class/controller/tab
 * level) precisely so it does NOT touch owner feedback, portal viewings, or
 * portal leads — see RentalIntelligenceKeepTest for proof those still work.
 *
 * Also pins the standalone defence-in-depth fix: SubjectFieldCompleteness
 * must never call a rental "missing price" when it has a real rental_amount
 * — even though the gates above make this currently unreachable in
 * practice, Johan asked for it fixed regardless so a future caller can't
 * resurrect the old bug.
 */
class RentalExclusionTest extends TestCase
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

    private function makeProp(array $over = []): Property
    {
        return Property::withoutEvents(fn () => Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'external_id'  => 'T-' . Str::random(8),
            'title'        => 'Home ' . Str::random(4),
            'address'      => '12 Marine Dr',
            'suburb'       => 'Shelly Beach',
            'agent_id'     => $this->agent->id,
            'agency_id'    => $this->agency->id,
            'branch_id'    => $this->branch->id,
            'price'        => 2_000_000,
            'beds'         => 3,
            'baths'        => 2,
            'garages'      => 2,
            'size_m2'      => 180,
            'erf_size_m2'  => 500,
            'property_type' => 'House',
            'listing_type' => 'sale',
            'status'       => 'active',
        ], $over)));
    }

    private function rental(array $over = []): Property
    {
        return $this->makeProp(array_merge([
            'listing_type'  => 'rental',
            'price'         => 0,
            'rental_amount' => 12500,
        ], $over));
    }

    // ── Comparables gate (CompetitorStockMatchService::resolveCriteria) ────

    public function test_rental_subject_gets_no_comparable_stock(): void
    {
        $subject = $this->rental();
        // A same-suburb, same-family, on-market RENTAL that would otherwise match.
        $this->rental(['title' => 'Would-be comp', 'rental_amount' => 13000]);

        $result = app(CompetitorStockMatchService::class)->findComparableStock($subject);
        $this->assertTrue($result->isEmpty(), 'a rental subject must never receive comparable stock, even when a matching rental comp exists');
    }

    public function test_rental_subject_gets_no_competitors(): void
    {
        $subject = $this->rental();
        $result = app(CompetitorStockMatchService::class)->findCompetitors($subject);
        $this->assertTrue($result->isEmpty(), 'a rental subject must never receive competitor stock');
    }

    public function test_sale_subject_comparables_are_completely_unaffected(): void
    {
        $subject = $this->makeProp();
        $good    = $this->makeProp(['title' => 'GOOD comp']);

        $result = app(CompetitorStockMatchService::class)->findComparableStock($subject);
        $this->assertTrue($result->pluck('id')->contains($good->id), 'a sale subject must still receive its comparable stock, unchanged by the rental gate');
    }

    // ── Market Positioning gate (PropertyIntelligenceService::getLatestMarketPosition) ──

    public function test_rental_has_no_market_position_card(): void
    {
        $rental = $this->rental();
        $result = app(PropertyIntelligenceService::class)->getLatestMarketPosition($rental->id);
        $this->assertNull($result, 'a rental must never get a recommended-sale-price / market-positioning card');
    }

    public function test_sale_market_position_is_unaffected_when_data_exists(): void
    {
        $sale = $this->makeProp();
        // calculateAreaAverages() sources from property_sold_records, not active
        // stock — seed one directly so avg_price is deterministically non-null.
        \Illuminate\Support\Facades\DB::table('property_sold_records')->insert([
            'suburb'     => $sale->suburb,
            'sold_price' => 1_900_000,
            'sold_date'  => now()->subMonths(2)->toDateString(),
            'agency_id'  => $this->agency->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(PropertyIntelligenceService::class)->getLatestMarketPosition($sale->id);
        // Not asserting the exact figures (that's MarketDataSnapshotService's own
        // test suite's job) — only that the rental gate does not touch this path.
        $this->assertNotNull($result, 'a sale with real sold-comp data must still get a market-position card');
        $this->assertNotNull($result['area_avg_price'], 'the area average must still compute for a sale');
    }

    // ── Generate Presentation service guard ─────────────────────────────

    public function test_generating_a_presentation_for_a_rental_throws(): void
    {
        $rental = $this->rental();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('property is a rental');

        app(PresentationGeneratorService::class)->generateForProperty(
            propertyId: $rental->id,
            agentUserId: $this->agent->id,
            agencyId: $this->agency->id,
        );
    }

    public function test_generating_a_presentation_for_a_sale_still_works(): void
    {
        $sale = $this->makeProp();

        $version = app(PresentationGeneratorService::class)->generateForProperty(
            propertyId: $sale->id,
            agentUserId: $this->agent->id,
            agencyId: $this->agency->id,
        );

        $this->assertNotNull($version->id, 'generating a presentation for a sale property must still succeed');
    }

    // ── SubjectFieldCompleteness defence-in-depth ───────────────────────

    public function test_rental_with_rental_amount_is_not_reported_as_missing_price(): void
    {
        $rental = $this->rental(['rental_amount' => 28125]);
        $missing = SubjectFieldCompleteness::missingSoftInputs($rental);
        $this->assertNotContains('price', $missing, 'a rental with a real rental_amount must not be flagged as missing a price');
    }

    public function test_rental_with_no_rental_amount_is_still_correctly_flagged(): void
    {
        $rental = $this->rental(['rental_amount' => 0]);
        $missing = SubjectFieldCompleteness::missingSoftInputs($rental);
        $this->assertContains('price', $missing, 'a genuinely incomplete rental (no rent set) must still be flagged — this is not about disabling the warning');
    }

    public function test_sale_price_completeness_is_unaffected(): void
    {
        $priced   = $this->makeProp(['price' => 1_800_000]);
        $unpriced = $this->makeProp(['price' => 0]);

        $this->assertNotContains('price', SubjectFieldCompleteness::missingSoftInputs($priced));
        $this->assertContains('price', SubjectFieldCompleteness::missingSoftInputs($unpriced));
    }
}
