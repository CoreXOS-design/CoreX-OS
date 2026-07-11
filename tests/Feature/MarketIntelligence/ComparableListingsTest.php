<?php

namespace Tests\Feature\MarketIntelligence;

use App\Models\Agency;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Services\PropertyIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Seller "Similar properties in your area" comparables.
 *
 * Locks the fixes: (1) comparables read effectivePrice() — the single source of
 * truth — so a RENTAL comparable shows its rental_amount, never R0 (the sale
 * `price` column is 0 on a rental); (2) comparables match the subject's
 * listing_type, so a rental subject never lists a sale (and vice versa); and
 * (3) any price-less listing is dropped rather than rendered as an R0 card.
 */
class ComparableListingsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private PropertyIntelligenceService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $this->svc = app(PropertyIntelligenceService::class);
    }

    private function prop(array $extra = []): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $this->agency->id,
            'external_id' => (string) Str::uuid(),
            'title' => 'Listing ' . Str::random(4),
            'suburb' => 'Margate',
            'property_type' => 'apartment',
            'status' => 'active',
            'listing_type' => 'sale',
            'price' => 900000,
            'published_at' => now(),
        ], $extra));
    }

    /** A rental subject: comparables are rentals priced off rental_amount — no R0, no sales. */
    public function test_rental_comparables_use_rental_amount_and_exclude_sales(): void
    {
        $subject = $this->prop(['listing_type' => 'rental', 'price' => 0, 'rental_amount' => 6135]);

        $rentalComp = $this->prop(['title' => 'Flat to let', 'listing_type' => 'rental', 'price' => 0, 'rental_amount' => 7250]);
        $saleComp   = $this->prop(['title' => 'House for sale', 'listing_type' => 'sale', 'price' => 1500000]);

        $comps = $this->svc->getComparableListings($subject->id);
        $ids = $comps->pluck('id')->all();

        // The rental comparable is present, priced off rental_amount (not R0).
        $this->assertContains($rentalComp->id, $ids);
        $this->assertSame(7250.0, (float) $comps->firstWhere('id', $rentalComp->id)['price']);
        // The sale is NOT surfaced under a rental subject.
        $this->assertNotContains($saleComp->id, $ids);
        // Never an R0 card.
        $this->assertFalse($comps->contains(fn ($c) => ($c['price'] ?? 0) <= 0));
    }

    /** A sale subject: comparables are sales priced off `price` — no rentals leak in. */
    public function test_sale_comparables_exclude_rentals(): void
    {
        $subject = $this->prop(['listing_type' => 'sale', 'price' => 850000]);

        $saleComp   = $this->prop(['title' => 'Nearby sale', 'listing_type' => 'sale', 'price' => 1020000]);
        $rentalComp = $this->prop(['title' => 'Nearby rental', 'listing_type' => 'rental', 'price' => 0, 'rental_amount' => 8000]);

        $comps = $this->svc->getComparableListings($subject->id);
        $ids = $comps->pluck('id')->all();

        $this->assertContains($saleComp->id, $ids);
        $this->assertNotContains($rentalComp->id, $ids); // rental never a sale comparable
        $this->assertFalse($comps->contains(fn ($c) => ($c['price'] ?? 0) <= 0));
    }

    /** A same-type listing with no price figure is dropped, never rendered as R0. */
    public function test_priceless_comparable_is_excluded(): void
    {
        $subject = $this->prop(['listing_type' => 'sale', 'price' => 850000]);

        $priced   = $this->prop(['title' => 'Priced sale', 'listing_type' => 'sale', 'price' => 999000]);
        $priceless = $this->prop(['title' => 'No price sale', 'listing_type' => 'sale', 'price' => 0]);

        $comps = $this->svc->getComparableListings($subject->id);
        $ids = $comps->pluck('id')->all();

        $this->assertContains($priced->id, $ids);
        $this->assertNotContains($priceless->id, $ids);
    }
}
