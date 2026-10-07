<?php

declare(strict_types=1);

namespace Tests\Feature\Address;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Map\MapBoundsRequest;
use App\Services\Map\MapPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Structured address matching, step 6 — the map's "T-pin fold": a tracked property whose address is a
 * property already on our books no longer draws its own pin. The key was number | street name WITH its type |
 * suburb, with the number only when it sat in its own column; it is now number | street core | suburb, the
 * number read out of whichever column holds it, with a street type written on both sides having to agree.
 */
final class MapPinFoldAddressTest extends TestCase
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
        MapPinService::forgetAgencyAddressIndex($this->agency->id);
    }

    private function property(array $over): Property
    {
        return Property::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->user->id, 'suburb' => 'Uvongo',
            'property_type' => 'house', 'beds' => 3, 'baths' => 2, 'garages' => 1, 'price' => 1500000, 'title' => 'T', 'status' => 'active', 'listing_type' => 'sale',
        ], $over));
    }

    private function tp(array $row): int
    {
        return (int) DB::table('tracked_properties')->insertGetId(array_merge([
            'agency_id' => $this->agency->id, 'external_id' => 'TP-' . Str::random(8), 'status' => 'active', 'suburb' => 'Uvongo',
            'latitude' => -30.8290, 'longitude' => 30.3950, 'first_seen_at' => now()->subDay(), 'created_at' => now()->subDay(), 'updated_at' => now(),
        ], $row));
    }

    private function trackedPinIds(): array
    {
        $resp = (new MapPinService())->getPinsInBounds(new MapBoundsRequest(
            north: -30.4, south: -31.0, east: 30.9, west: 30.0, layers: ['tracked_properties'], viewMode: 'agent',
            agencyId: $this->agency->id, scope: 'agency', actorUserId: null, search: null,
        ));

        return collect($resp['locations'])
            ->flatMap(fn ($loc) => collect($loc['records'])->where('category', 'tracked_properties')->pluck('id'))
            ->map(fn ($id) => (int) $id)->values()->all();
    }

    public function test_a_capture_with_the_street_type_folds_onto_stock_without_it(): void
    {
        $this->property(['street_number' => '19', 'street_name' => 'Grindewald']);
        $tp = $this->tp(['street_number' => '19', 'street_name' => 'Grindewald Drive']);

        $this->assertNotContains($tp, $this->trackedPinIds());
    }

    public function test_a_number_written_inside_the_street_name_folds_too(): void
    {
        $this->property(['street_number' => null, 'street_name' => '19 Grindewald Drive']);
        $tp = $this->tp(['street_number' => null, 'street_name' => '19 Grindewald Drive']);

        $this->assertNotContains($tp, $this->trackedPinIds(), 'the number is read from the street text on both sides');
    }

    public function test_a_different_number_never_folds(): void
    {
        $this->property(['street_number' => '29', 'street_name' => 'Grindewald Drive']);
        $tp = $this->tp(['street_number' => '19', 'street_name' => 'Grindewald Drive']);

        $this->assertContains($tp, $this->trackedPinIds());
    }

    public function test_a_different_street_type_written_on_both_sides_never_folds(): void
    {
        $this->property(['street_number' => '19', 'street_name' => 'Grindewald Road']);
        $tp = $this->tp(['street_number' => '19', 'street_name' => 'Grindewald Drive']);

        $this->assertContains($tp, $this->trackedPinIds());
    }

    public function test_another_unit_at_the_address_never_folds_and_an_unknown_unit_does(): void
    {
        $this->property(['street_number' => '12', 'street_name' => 'Marine Drive', 'unit_number' => '5']);
        $other = $this->tp(['street_number' => '12', 'street_name' => 'Marine Drive', 'unit_number' => '9']);
        $unknown = $this->tp(['street_number' => '12', 'street_name' => 'Marine Drive']);

        $pins = $this->trackedPinIds();
        $this->assertContains($other, $pins);
        $this->assertNotContains($unknown, $pins);
    }

    public function test_uvongo_beach_stock_does_not_fold_an_uvongo_capture(): void
    {
        $this->property(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo Beach']);
        $tp = $this->tp(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo']);

        $this->assertContains($tp, $this->trackedPinIds());
    }
}
