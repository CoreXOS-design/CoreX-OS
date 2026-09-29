<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertySettingItem;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use App\Services\Syndication\Property24\Property24ListingMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-property-tab.md §3, Part 3 — Johan (2026-09-21): "only 1
 * price allowed to match portals. not lots. so simple. select price type as
 * thats the dictating factor then enter the price." The dropdown moves from
 * a hardcoded array to an agency-editable PropertySettingItem list; the
 * single rental_amount column remains the one value both portals already
 * read — nothing about the mapper side changes.
 */
final class RentalPriceTypeSettingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->owner = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    // ── default seeding ──────────────────────────────────────────────────

    public function test_a_new_agency_is_auto_seeded_with_the_four_portal_safe_types_only(): void
    {
        // AgencyObserver already calls provisionDefaultsFor() with no group
        // filter on every Agency::create() -- confirmed by reading the
        // observer, not assumed -- so this agency (created in setUp()) is
        // already seeded before this test body runs at all.
        $names = PropertySettingItem::group('rental_price_type')
            ->where('agency_id', $this->agency->id)->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Per Month', 'Per Week', 'Per Day', 'Per Sqm'], $names);
        $this->assertNotContains('Per Year', $names, 'PP has no yearly rate in its enum -- not a safe default');
    }

    public function test_provisioning_again_is_idempotent(): void
    {
        $before = PropertySettingItem::group('rental_price_type')->where('agency_id', $this->agency->id)->count();

        PropertySettingItem::provisionDefaultsFor($this->agency->id, ['rental_price_type']);

        $after = PropertySettingItem::group('rental_price_type')->where('agency_id', $this->agency->id)->count();
        $this->assertSame($before, $after, 're-running provisioning must never duplicate an already-configured group');
    }

    public function test_an_agency_with_a_curated_list_is_never_silently_topped_up(): void
    {
        // Curate: retire the auto-seeded defaults, add the agency's own choice.
        PropertySettingItem::group('rental_price_type')->where('agency_id', $this->agency->id)->delete();
        PropertySettingItem::create(['agency_id' => $this->agency->id, 'group' => 'rental_price_type', 'name' => 'Per Fortnight', 'sort_order' => 0]);

        PropertySettingItem::provisionDefaultsFor($this->agency->id, ['rental_price_type']);

        $names = PropertySettingItem::group('rental_price_type')->where('agency_id', $this->agency->id)->pluck('name')->all();
        $this->assertSame(['Per Fortnight'], $names, 'a group with even one row is already configured -- must not be topped up');
    }

    public function test_one_agencys_price_types_never_leak_into_another(): void
    {
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        // Both agencies auto-seeded on creation; curate each distinctly.
        PropertySettingItem::group('rental_price_type')->where('agency_id', $this->agency->id)->delete();
        PropertySettingItem::create(['agency_id' => $this->agency->id, 'group' => 'rental_price_type', 'name' => 'Per Month Only', 'sort_order' => 0]);

        $mine = PropertySettingItem::group('rental_price_type')->where('agency_id', $this->agency->id)->pluck('name')->all();
        $theirs = PropertySettingItem::group('rental_price_type')->where('agency_id', $otherAgency->id)->pluck('name')->all();
        $this->assertSame(['Per Month Only'], $mine);
        $this->assertNotContains('Per Month Only', $theirs);
    }

    // ── rendering + saving through the real screen ──────────────────────

    private function rentalProperty(): Property
    {
        return Property::create([
            'title' => 'Test Rental', 'agency_id' => $this->agency->id,
            'agent_id' => $this->owner->id, 'branch_id' => $this->branch->id,
            'listing_type' => 'rental', 'listing_type_pending' => false,
        ]);
    }

    public function test_the_price_type_dropdown_renders_the_agencys_own_list_not_a_hardcoded_one(): void
    {
        PropertySettingItem::create(['agency_id' => $this->agency->id, 'group' => 'rental_price_type', 'name' => 'Per Fortnight', 'sort_order' => 0]);
        $property = $this->rentalProperty();

        $resp = $this->actingAs($this->owner)->get(route('corex.properties.show', $property));

        $resp->assertStatus(200);
        $resp->assertSee('Per Fortnight', false);
    }

    public function test_the_grid_of_optional_prices_is_gone(): void
    {
        $property = $this->rentalProperty();

        $resp = $this->actingAs($this->owner)->get(route('corex.properties.show', $property));

        $resp->assertDontSee('name="price_per_day"', false);
        $resp->assertDontSee('name="price_per_week"', false);
        $resp->assertDontSee('name="price_per_year"', false);
        $resp->assertDontSee('Monthly Rental', false, 'the label must no longer claim the price is always monthly');
    }

    public function test_saving_one_price_and_one_type_persists_both(): void
    {
        PropertySettingItem::create(['agency_id' => $this->agency->id, 'group' => 'rental_price_type', 'name' => 'Per Week', 'sort_order' => 0]);
        $property = $this->rentalProperty();

        $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [
            'rental_amount' => '1250', 'rental_price_type' => 'Per Week',
        ])->assertSessionDoesntHaveErrors();

        $property->refresh();
        $this->assertSame(1250.0, (float) $property->rental_amount);
        $this->assertSame('Per Week', $property->rental_price_type);
    }

    // ── portal mapping is unchanged — same column, same match() logic ───

    public function test_a_type_from_the_new_agency_list_still_maps_correctly_to_p24(): void
    {
        $property = $this->rentalProperty();
        $property->update(['rental_amount' => 900, 'rental_price_type' => 'Per Week']);

        $mapper = app(Property24ListingMapper::class);
        $ref = new \ReflectionMethod($mapper, 'mapRentalRate');
        $ref->setAccessible(true);

        $this->assertSame('Week', $ref->invoke($mapper, $property->rental_price_type));
    }

    public function test_a_type_from_the_new_agency_list_still_maps_correctly_to_pp(): void
    {
        $property = $this->rentalProperty();
        $property->update(['rental_amount' => 900, 'rental_price_type' => 'Per Day']);

        $mapper = app(PrivatePropertyListingMapper::class);
        $ref = new \ReflectionMethod($mapper, 'mapRentalPriceType');
        $ref->setAccessible(true);

        $this->assertSame('PerDay', $ref->invoke($mapper, $property));
    }

    public function test_per_year_falls_back_to_permonth_on_pp_confirming_why_it_is_excluded_from_the_default(): void
    {
        $property = $this->rentalProperty();
        $property->update(['rental_amount' => 12000, 'rental_price_type' => 'Per Year']);

        $mapper = app(PrivatePropertyListingMapper::class);
        $ref = new \ReflectionMethod($mapper, 'mapRentalPriceType');
        $ref->setAccessible(true);

        $this->assertSame('PerMonth', $ref->invoke($mapper, $property), 'documents the real, pre-existing PP gap this default list is designed around');
    }
}
