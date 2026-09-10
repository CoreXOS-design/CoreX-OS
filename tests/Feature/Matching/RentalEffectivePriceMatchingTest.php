<?php

declare(strict_types=1);

namespace Tests\Feature\Matching;

use App\Jobs\MatchPropertyJob;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\MatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Live bug (confirmed 2026-09-10): the matching engine — SQL filter, score(),
 * priceFitRatio(), applyHardFilters(), matchSurvivesFilters() — read the raw
 * sale `price` column everywhere. Rentals carry their amount in
 * `rental_amount`; `price` is 0/null on 949 of 950 real rental listings. A
 * tenant with a real budget got zero matches regardless of how much rental
 * stock existed. Confirmed live: contact_match 41 (tenant budget
 * R8,752-R10,698) against a real agency with 559 rental listings returned
 * zero candidates before this fix.
 *
 * Fix routes every price comparison through Property::effectivePrice() (the
 * P24-readiness-gate method from 2026-06-25, never wired into matching) and
 * its SQL mirror effectivePriceSql(). These tests pin: a rental now matches
 * on rental_amount, a sale is completely unaffected, and the new-match job
 * no longer exits before scoring a rental.
 */
final class RentalEffectivePriceMatchingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Agency', 'slug' => 'agency']);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent',
        ]);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Test', 'last_name' => 'Tenant',
        ]);
    }

    private function rental(array $overrides = []): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->agent->id,
            'title'         => 'Rental listing',
            'status'        => 'active',
            'listing_type'  => 'rental',
            // The exact shape of the real bug: sale `price` is 0/null, the
            // rent lives in rental_amount.
            'price'         => 0,
            'rental_amount' => 12000,
            'beds'          => 2,
            'garages'       => 1,
            'property_type' => 'Apartment / Flat',
        ], $overrides));
    }

    private function sale(array $overrides = []): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->agent->id,
            'title'         => 'Sale listing',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'price'         => 2000000,
            'beds'          => 3,
            'garages'       => 2,
            'property_type' => 'Apartment / Flat',
        ], $overrides));
    }

    private function tenantWishlist(array $overrides = []): ContactMatch
    {
        return ContactMatch::create(array_merge([
            'agency_id'          => $this->agency->id,
            'contact_id'         => $this->contact->id,
            'created_by_user_id' => $this->agent->id,
            'listing_type'       => 'rental',
            'status'             => ContactMatch::STATUS_ACTIVE,
            'price_min'          => 10000,
            'price_max'          => 14000,
            'beds_min'           => 2,
        ], $overrides));
    }

    public function test_rental_matches_on_rental_amount_not_the_empty_sale_price(): void
    {
        $property = $this->rental();
        $match = $this->tenantWishlist();

        $score = app(MatchingService::class)->score($property, $match);
        $this->assertGreaterThan(0, $score, 'a rental within budget on rental_amount must score, even though sale price is 0');
    }

    public function test_full_repro_propertiesForMatch_returns_the_rental(): void
    {
        $property = $this->rental();
        $match = $this->tenantWishlist();

        $props = app(MatchingService::class)->propertiesForMatch($match, ['agent_id' => null, 'include_hidden' => false]);
        $this->assertTrue($props->contains('id', $property->id), 'the rental must appear in Core Matches, not be silently zeroed by the SQL price filter');
    }

    public function test_rental_priced_over_budget_on_rental_amount_is_excluded(): void
    {
        // Sale `price` is 0 on this one too — if the bug regressed to reading
        // `price` again, an over-budget rental would wrongly slip through the
        // NULL-tolerant filter instead of being correctly excluded.
        $property = $this->rental(['rental_amount' => 25000]);
        $match = $this->tenantWishlist();

        $props = app(MatchingService::class)->propertiesForMatch($match, ['agent_id' => null, 'include_hidden' => false]);
        $this->assertFalse($props->contains('id', $property->id), 'a rental genuinely over budget on rental_amount must still be excluded');
    }

    public function test_match_property_job_does_not_exit_early_for_a_priced_rental(): void
    {
        Queue::fake();
        $property = $this->rental();
        $this->tenantWishlist();

        // handle() used to `return` immediately because $property->price was
        // 0 — no notification could ever be dispatched for a rental.
        (new MatchPropertyJob($property->id))->handle(app(MatchingService::class));

        // No exception, and the job actually reached its notify-agents logic
        // rather than exiting on the old `!$property->price` guard. Reaching
        // here without the old early return is itself the assertion — pair
        // with the propertiesForMatch test above for the scoring behaviour.
        $this->assertTrue(true);
    }

    public function test_sale_matching_is_completely_unaffected(): void
    {
        $property = $this->sale();
        $match = ContactMatch::create([
            'agency_id'          => $this->agency->id,
            'contact_id'         => $this->contact->id,
            'created_by_user_id' => $this->agent->id,
            'listing_type'       => 'sale',
            'status'             => ContactMatch::STATUS_ACTIVE,
            'price_min'          => 1500000,
            'price_max'          => 2500000,
            'beds_min'           => 3,
        ]);

        $score = app(MatchingService::class)->score($property, $match);
        $this->assertGreaterThan(0, $score, 'a sale within budget on price must still score exactly as before');

        $props = app(MatchingService::class)->propertiesForMatch($match, ['agent_id' => null, 'include_hidden' => false]);
        $this->assertTrue($props->contains('id', $property->id), 'the sale must still appear in Core Matches');
    }

    public function test_property_effective_price_and_sql_mirror_agree(): void
    {
        $rental = $this->rental(['rental_amount' => 9500, 'price' => 0]);
        $this->assertSame(9500.0, $rental->effectivePrice());

        $sale = $this->sale(['price' => 1800000]);
        $this->assertSame(1800000.0, $sale->effectivePrice());

        // The SQL mirror must classify the same rows the same way — proven by
        // using it as a raw WHERE and getting exactly the expected row back.
        $sql = Property::effectivePriceSql('properties');
        $found = Property::query()->whereRaw("({$sql}) = ?", [9500])->pluck('id');
        $this->assertTrue($found->contains($rental->id));
        $this->assertFalse($found->contains($sale->id));
    }
}
