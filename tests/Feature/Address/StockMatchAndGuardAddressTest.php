<?php

declare(strict_types=1);

namespace Tests\Feature\Address;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\ProspectingListing;
use App\Models\User;
use App\Services\Contact\ContactAddressPropertyGuard;
use App\Services\Prospecting\MicPropertyReconciliationService;
use App\Services\Prospecting\ProspectingStockMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Structured address matching, step 6 — ContactAddressPropertyGuard (stock side), the MIC reconciliation
 * sibling lookup and the portal-listing stock match (Pass 2) now answer "same property?" with the shared scorer.
 */
final class StockMatchAndGuardAddressTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function property(array $over = []): Property
    {
        return Property::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->user->id,
            'street_number' => '19', 'street_name' => 'Grindewald', 'suburb' => 'Uvongo', 'property_type' => 'house',
            'beds' => 3, 'baths' => 2, 'garages' => 1, 'price' => 1500000, 'title' => 'T', 'status' => 'active', 'listing_type' => 'sale',
        ], $over));
    }

    // ── ContactAddressPropertyGuard::findHeldFromComponents (stock side) ────

    private function held(array $components): ?array
    {
        return app(ContactAddressPropertyGuard::class)->findHeldFromComponents($this->agency->id, $components);
    }

    public function test_the_guard_finds_stock_when_the_street_type_is_missing_on_one_side(): void
    {
        $p = $this->property();
        $held = $this->held(['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo']);

        $this->assertSame('stock', $held['kind'] ?? null);
        $this->assertSame($p->id, $held['property_id'] ?? null);
    }

    public function test_the_guard_never_finds_stock_at_another_street_number(): void
    {
        $this->property(['street_number' => '29']);
        $held = $this->held(['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo']);

        $this->assertNull($held['property_id'] ?? null);
    }

    public function test_the_guard_keeps_a_conflicting_unit_as_a_veto_but_a_missing_unit_never_blocks(): void
    {
        $unit5 = $this->property(['street_name' => 'Marine Drive', 'street_number' => '12', 'unit_number' => '5']);

        $conflict = $this->held(['street_number' => '12', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo', 'unit_number' => '9']);
        $this->assertNull($conflict['property_id'] ?? null, 'a different unit is a different property');

        $partial = $this->held(['street_number' => '12', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo']);
        $this->assertSame($unit5->id, $partial['property_id'] ?? null, 'a partial capture without a unit still warns');
    }

    public function test_the_guard_cannot_see_another_agencys_stock(): void
    {
        $other = Agency::create(['name' => 'Other', 'slug' => 'o-' . uniqid()]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        Property::create(['agency_id' => $other->id, 'branch_id' => $ob->id, 'agent_id' => User::factory()->create(['agency_id' => $other->id, 'branch_id' => $ob->id])->id,
            'street_number' => '19', 'street_name' => 'Grindewald', 'suburb' => 'Uvongo', 'property_type' => 'house', 'beds' => 1, 'baths' => 1, 'garages' => 0,
            'price' => 1, 'title' => 'x', 'status' => 'active', 'listing_type' => 'sale']);

        $this->assertNull($this->held(['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo']));
    }

    // ── MIC reconciliation sibling ────────────────────────────────────────

    public function test_the_mic_sibling_lookup_finds_the_promoted_twin_by_the_scored_comparison(): void
    {
        $prop = $this->property(['street_name' => 'Grindewald Drive']);
        $promoted = TrackedProperty::create(['agency_id' => $this->agency->id, 'street_number' => '19', 'street_name' => 'Grindewald', 'suburb' => 'Uvongo',
            'promoted_to_property_id' => $prop->id, 'promoted_at' => now(), 'source_chain' => []]);
        $twin = TrackedProperty::create(['agency_id' => $this->agency->id, 'street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo', 'source_chain' => []]);

        $found = app(MicPropertyReconciliationService::class)->resolveExistingProperty($this->agency->id, ['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo']);

        $this->assertSame($prop->id, $found?->id);
        $this->assertNotNull($twin->id);
        $this->assertNotNull($promoted->id);
    }

    public function test_the_mic_sibling_lookup_never_crosses_street_numbers(): void
    {
        $prop = $this->property(['street_number' => '29', 'street_name' => 'Grindewald Drive']);
        TrackedProperty::create(['agency_id' => $this->agency->id, 'street_number' => '29', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo',
            'promoted_to_property_id' => $prop->id, 'promoted_at' => now(), 'source_chain' => []]);

        $found = app(MicPropertyReconciliationService::class)->resolveExistingProperty($this->agency->id, ['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo']);

        $this->assertNull($found);
    }

    // ── portal listing -> stock (Pass 2) ──────────────────────────────────

    private function prospect(array $over): ProspectingListing
    {
        $address = (string) ($over['address'] ?? '');
        $suburb = (string) ($over['suburb'] ?? '');
        $id = (int) \DB::table('prospecting_listings')->insertGetId([
            'agency_id' => $this->agency->id, 'portal_source' => 'p24', 'portal_ref' => 'test-' . \Illuminate\Support\Str::random(10),
            'portal_url' => 'https://example.test/' . \Illuminate\Support\Str::random(6), 'captured_by_user_id' => $this->user->id,
            'is_active' => true, 'address' => $address, 'suburb' => $suburb, 'normalized_address' => ProspectingListing::normalizeAddress($address, $suburb),
            'price' => 0, 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ProspectingListing::findOrFail($id);
    }

    public function test_a_portal_listing_with_the_street_type_written_matches_stock_without_it(): void
    {
        $p = $this->property(['address' => '19 Grindewald']);
        $matched = app(ProspectingStockMatchService::class)->matchProspect($this->prospect(['address' => '19 Grindewald Drive, Uvongo', 'suburb' => 'Uvongo']));
        $this->assertSame($p->id, $matched?->id);
    }

    public function test_a_portal_listing_never_matches_a_different_street_number_or_a_neighbouring_suburb(): void
    {
        $this->property(['street_number' => '29', 'address' => '29 Grindewald']);
        $svc = app(ProspectingStockMatchService::class);

        $this->assertNull($svc->matchProspect($this->prospect(['address' => '19 Grindewald Drive, Uvongo', 'suburb' => 'Uvongo'])));
        $this->assertNull($svc->matchProspect($this->prospect(['address' => '29 Grindewald Drive, Uvongo Beach', 'suburb' => 'Uvongo Beach'])),
            'Uvongo Beach is a neighbour, never the badge');
    }

    public function test_a_portal_listing_with_no_readable_number_never_fuzzy_matches(): void
    {
        $this->property(['address' => '19 Grindewald']);
        $this->assertNull(app(ProspectingStockMatchService::class)->matchProspect($this->prospect(['address' => 'Grindewald Drive, Uvongo', 'suburb' => 'Uvongo'])));
    }

    public function test_off_market_stock_is_never_matched(): void
    {
        $this->property(['status' => 'sold', 'address' => '19 Grindewald']);
        $this->assertNull(app(ProspectingStockMatchService::class)->matchProspect($this->prospect(['address' => '19 Grindewald Drive, Uvongo', 'suburb' => 'Uvongo'])));
    }
}
