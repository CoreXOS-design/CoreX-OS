<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-439 follow-up, Item A — Johan's ruling 2026-10-04: "rent and dates live
 * on the LEASE; the property only links to it." Property::effectivePrice()
 * (and formattedPrice()) are left UNCHANGED on purpose — syndication (P24/PP),
 * matching, and notifications all read them and must not be touched. This
 * suite covers the new display-only path (Property::displayRentalPrice() /
 * formattedDisplayPrice()) consumed by the properties list, the property
 * header/overview, and the shared shell-header partial.
 */
final class RentalPriceDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_lease_rent_wins_over_the_stale_properties_rental_amount(): void
    {
        [$agency, $branch, $property] = $this->makeRentalProperty(['rental_amount' => 7150]);
        $this->makeLease($agency, $branch, $property, Lease::STATUS_ACTIVE, 8000);

        self::assertSame(8000.0, $property->fresh()->displayRentalPrice());
        self::assertSame('R 8 000', $property->fresh()->formattedDisplayPrice());
        // effectivePrice() must be untouched — it still reads properties.rental_amount directly.
        self::assertSame(7150.0, $property->fresh()->effectivePrice());
    }

    public function test_falls_back_to_properties_rental_amount_when_no_active_lease(): void
    {
        [, , $property] = $this->makeRentalProperty(['rental_amount' => 7150]);

        self::assertSame(7150.0, $property->fresh()->displayRentalPrice());
        self::assertSame('R 7 150', $property->fresh()->formattedDisplayPrice());
    }

    public function test_ignores_an_expired_lease_and_falls_back_to_properties_rental_amount(): void
    {
        [$agency, $branch, $property] = $this->makeRentalProperty(['rental_amount' => 7150]);
        $this->makeLease($agency, $branch, $property, Lease::STATUS_EXPIRED, 9999);

        self::assertSame(7150.0, $property->fresh()->displayRentalPrice());
    }

    public function test_picks_the_most_recent_active_lease_when_more_than_one_exists(): void
    {
        [$agency, $branch, $property] = $this->makeRentalProperty(['rental_amount' => 7150]);
        $this->makeLease($agency, $branch, $property, Lease::STATUS_ACTIVE, 6000, now()->subYear());
        $this->makeLease($agency, $branch, $property, Lease::STATUS_ACTIVE, 8500, now());

        self::assertSame(8500.0, $property->fresh()->displayRentalPrice());
    }

    public function test_sale_listings_are_identical_to_effective_price(): void
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Sale property ' . uniqid(),
            'status' => 'active', 'listing_type' => 'sale', 'price' => 1500000,
        ]);

        self::assertSame($property->effectivePrice(), $property->displayRentalPrice());
        self::assertSame($property->formattedPrice(), $property->formattedDisplayPrice());
    }

    public function test_properties_list_shows_the_active_lease_rent_not_the_stale_field(): void
    {
        [$agency, $branch, $property] = $this->makeRentalProperty(['rental_amount' => 7150]);
        $this->makeLease($agency, $branch, $property, Lease::STATUS_ACTIVE, 8000);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('corex.properties.index', ['scope' => 'branch', 'agent_ids' => 'all']));

        $response->assertOk();
        $response->assertSee('R 8 000', false);
        $response->assertDontSee('R 7 150', false);
    }

    public function test_property_header_overview_shows_the_active_lease_rent(): void
    {
        [$agency, $branch, $property] = $this->makeRentalProperty(['rental_amount' => 7150]);
        $this->makeLease($agency, $branch, $property, Lease::STATUS_ACTIVE, 8000);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('corex.properties.show', $property));

        $response->assertOk();
        $response->assertSee('R 8 000', false);
    }

    public function test_properties_list_does_not_n_plus_one_on_active_lease(): void
    {
        [$agency, $branch, , $agent] = $this->makeRentalProperty(['rental_amount' => 1000]);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $addBulkProperties = function (int $count) use ($agency, $branch, $agent) {
            for ($i = 0; $i < $count; $i++) {
                $property = Property::create([
                    'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
                    'title' => 'Bulk property ' . uniqid(),
                    'status' => 'active', 'listing_type' => 'rental', 'rental_amount' => 1000 + $i,
                ]);
                $this->makeLease($agency, $branch, $property, Lease::STATUS_ACTIVE, 2000 + $i);
            }
        };
        $countLeaseQueries = function () use ($user) {
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            $this->actingAs($user)->get(route('corex.properties.index', ['scope' => 'branch', 'agent_ids' => 'all']))->assertOk();
            $queries = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '`leases`'));
            \DB::disableQueryLog();

            return $queries->count();
        };

        $addBulkProperties(5);
        $queriesWith6Rows = $countLeaseQueries();

        $addBulkProperties(15);
        $queriesWith21Rows = $countLeaseQueries();

        // The fixed cost (the list's own eager load, plus the pre-existing
        // tile-count aggregate — `(clone $query)->selectRaw(...)->first()`,
        // PropertyController::index() — which reuses the same ->with(...)
        // list and so also re-fires the eager load once for its own single
        // aggregate pseudo-row, exactly the same pre-existing cost every
        // other already-eager-loaded relation here already pays, unrelated
        // to this fix) must NOT grow as the row count grows 6 → 21. That is
        // the actual "no N+1" claim — a flat query count, not a magic number.
        self::assertSame($queriesWith6Rows, $queriesWith21Rows);
        self::assertLessThanOrEqual(2, $queriesWith21Rows);
    }

    /** @return array{0: Agency, 1: Branch, 2: Property, 3: User} */
    private function makeRentalProperty(array $overrides = []): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::create(array_merge([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Rental property ' . uniqid(),
            'status' => 'active', 'listing_type' => 'rental',
        ], $overrides));

        return [$agency, $branch, $property, $agent];
    }

    private function makeLease(Agency $agency, Branch $branch, Property $property, string $status, float $rentalAmount, $startDate = null): Lease
    {
        return Lease::create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'property_id' => $property->id,
            'status' => $status,
            'rental_amount' => $rentalAmount,
            'start_date' => ($startDate ?? now())->toDateString(),
            'source' => 'manual',
        ]);
    }
}
