<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-renewals.md §19 — Johan's ruling 2026-10-05 (3): the
 * property's own "rental marketing settings" (the Rental Details tab) get
 * a persistent "Show available-from date on portals" toggle, same
 * unchecked-checkbox-means-false rule as has_deposit/water_included/
 * electricity_included/levies_included on the same form.
 */
final class ShowAvailableFromOnPortalsSettingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Branch A']);
        $this->owner = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function rentalProperty(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'title' => 'Test Rental', 'agency_id' => $this->agency->id,
            'agent_id' => $this->owner->id, 'branch_id' => $this->branch->id,
            'listing_type' => 'rental', 'listing_type_pending' => false,
        ], $overrides));
    }

    public function test_defaults_to_true_for_a_new_property(): void
    {
        $property = $this->rentalProperty();

        // The DB column default (not an in-memory Eloquent default) is what
        // applies here — the model instance must be re-fetched to see it.
        self::assertTrue((bool) $property->fresh()->show_available_from_on_portals);
    }

    public function test_ticking_the_checkbox_persists_true(): void
    {
        $property = $this->rentalProperty(['show_available_from_on_portals' => false]);

        $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [
            'show_available_from_on_portals' => '1',
        ])->assertSessionDoesntHaveErrors();

        self::assertTrue((bool) $property->fresh()->show_available_from_on_portals);
    }

    public function test_omitting_the_checkbox_persists_false(): void
    {
        $property = $this->rentalProperty(['show_available_from_on_portals' => true]);

        $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [])
            ->assertSessionDoesntHaveErrors();

        self::assertFalse((bool) $property->fresh()->show_available_from_on_portals, 'An unchecked checkbox submits nothing — must persist as false, not keep the old value.');
    }
}
