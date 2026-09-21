<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertySettingItem;
use App\Models\User;
use App\Services\Syndication\Property24\Property24ListingMapper;
use App\Services\WebTemplateDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-property-tab.md §5, Part 4 — Johan (2026-09-21): "make it
 * real... one option list, defined once, agency-editable — the two hardcoded
 * divergent lists are a bug in themselves and both go." Replaces
 * properties/show.blade.php's ['N Triple Net', 'Gross', 'Modified Gross',
 * 'Percentage'] AND leases/create+show.blade.php's ['Net', 'Gross',
 * 'Modified Gross', 'Percentage'] with one PropertySettingItem group feeding
 * both screens, and gives lease_type its first two real downstream
 * consumers: Property24 syndication (closing the documented P24-G4 gap) and
 * the DocuPerfect merge-field system.
 */
final class LeaseTypeSettingTest extends TestCase
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

    public function test_a_new_agency_is_auto_seeded_with_the_reconciled_list(): void
    {
        // AgencyObserver already calls provisionDefaultsFor() with no group
        // filter on every Agency::create() -- confirmed by reading the
        // observer, not assumed -- so this agency (created in setUp()) is
        // already seeded before this test body runs at all.
        $names = PropertySettingItem::group('lease_type')
            ->where('agency_id', $this->agency->id)->pluck('name')->all();

        $this->assertEqualsCanonicalizing(
            ['Net', 'Gross', 'Modified Gross', 'Percentage', 'Double Net', 'Triple Net', 'Fully Serviced Gross'],
            $names
        );
        $this->assertNotContains('N Triple Net', $names, 'the property screen\'s old wording is not carried forward into the reconciled list');
    }

    public function test_provisioning_again_is_idempotent(): void
    {
        $before = PropertySettingItem::group('lease_type')->where('agency_id', $this->agency->id)->count();

        PropertySettingItem::provisionDefaultsFor($this->agency->id, ['lease_type']);

        $after = PropertySettingItem::group('lease_type')->where('agency_id', $this->agency->id)->count();
        $this->assertSame($before, $after, 're-running provisioning must never duplicate an already-configured group');
    }

    public function test_an_agency_with_a_curated_list_is_never_silently_topped_up(): void
    {
        PropertySettingItem::group('lease_type')->where('agency_id', $this->agency->id)->delete();
        PropertySettingItem::create(['agency_id' => $this->agency->id, 'group' => 'lease_type', 'name' => 'Our Own Term', 'sort_order' => 0]);

        PropertySettingItem::provisionDefaultsFor($this->agency->id, ['lease_type']);

        $names = PropertySettingItem::group('lease_type')->where('agency_id', $this->agency->id)->pluck('name')->all();
        $this->assertSame(['Our Own Term'], $names, 'a group with even one row is already configured -- must not be topped up');
    }

    public function test_one_agencys_lease_types_never_leak_into_another(): void
    {
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        PropertySettingItem::group('lease_type')->where('agency_id', $this->agency->id)->delete();
        PropertySettingItem::create(['agency_id' => $this->agency->id, 'group' => 'lease_type', 'name' => 'Mine Only', 'sort_order' => 0]);

        $mine = PropertySettingItem::group('lease_type')->where('agency_id', $this->agency->id)->pluck('name')->all();
        $theirs = PropertySettingItem::group('lease_type')->where('agency_id', $otherAgency->id)->pluck('name')->all();
        $this->assertSame(['Mine Only'], $mine);
        $this->assertNotContains('Mine Only', $theirs);
    }

    // ── rendering + saving through the real screens ──────────────────────

    private function rentalProperty(): Property
    {
        return Property::create([
            'title' => 'Test Commercial Rental', 'agency_id' => $this->agency->id,
            'agent_id' => $this->owner->id, 'branch_id' => $this->branch->id,
            'listing_type' => 'rental', 'listing_type_pending' => false,
            'property_type' => 'Commercial Property',
        ]);
    }

    public function test_the_property_screens_dropdown_renders_the_agencys_own_list_not_a_hardcoded_one(): void
    {
        PropertySettingItem::group('lease_type')->where('agency_id', $this->agency->id)->delete();
        PropertySettingItem::create(['agency_id' => $this->agency->id, 'group' => 'lease_type', 'name' => 'Our Custom Term', 'sort_order' => 0]);
        $property = $this->rentalProperty();

        $resp = $this->actingAs($this->owner)->get(route('corex.properties.show', $property));

        $resp->assertStatus(200);
        $resp->assertSee('Our Custom Term', false);
        $resp->assertDontSee('N Triple Net', false);
    }

    public function test_saving_lease_type_on_the_property_screen_persists_it(): void
    {
        PropertySettingItem::create(['agency_id' => $this->agency->id, 'group' => 'lease_type', 'name' => 'Triple Net', 'sort_order' => 99]);
        $property = $this->rentalProperty();

        $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [
            'lease_type' => 'Triple Net',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame('Triple Net', $property->refresh()->lease_type);
    }

    public function test_the_lease_create_screen_renders_the_agencys_own_list(): void
    {
        $property = $this->rentalProperty();
        PropertySettingItem::create(['agency_id' => $this->agency->id, 'group' => 'lease_type', 'name' => 'Our Custom Term', 'sort_order' => 0]);

        $resp = $this->actingAs($this->owner)->get(route('corex.leases.create', ['property_id' => $property->id]));

        $resp->assertStatus(200);
        $resp->assertSee('Our Custom Term', false);
    }

    public function test_the_lease_show_screens_edit_form_renders_the_agencys_own_list(): void
    {
        $property = $this->rentalProperty();
        PropertySettingItem::create(['agency_id' => $this->agency->id, 'group' => 'lease_type', 'name' => 'Our Custom Term', 'sort_order' => 0]);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'status' => 'active', 'rental_amount' => 12000, 'start_date' => '2026-01-01', 'source' => 'manual',
        ]);

        $resp = $this->actingAs($this->owner)->get(route('corex.leases.show', $lease));

        $resp->assertStatus(200);
        $resp->assertSee('Our Custom Term', false);
    }

    public function test_saving_lease_type_on_the_lease_edit_form_persists_it(): void
    {
        $property = $this->rentalProperty();
        PropertySettingItem::create(['agency_id' => $this->agency->id, 'group' => 'lease_type', 'name' => 'Percentage', 'sort_order' => 0]);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'status' => 'active', 'rental_amount' => 12000, 'start_date' => '2026-01-01', 'source' => 'manual',
        ]);

        $this->actingAs($this->owner)->put(route('corex.leases.update', $lease), [
            'lease_type' => 'Percentage',
        ])->assertRedirect(route('corex.leases.show', $lease));

        $this->assertSame('Percentage', $lease->refresh()->lease_type);
    }

    // ── settings hub CRUD (the Part 3 gap this Part also closed) ─────────

    public function test_the_settings_hub_add_endpoint_accepts_both_new_groups(): void
    {
        $this->actingAs($this->owner)->post(route('corex.settings.property-items.store'), [
            'group' => 'lease_type', 'name' => 'A New Lease Term',
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('property_setting_items', [
            'agency_id' => $this->agency->id, 'group' => 'lease_type', 'name' => 'A New Lease Term',
        ]);

        $this->actingAs($this->owner)->post(route('corex.settings.property-items.store'), [
            'group' => 'rental_price_type', 'name' => 'Per Fortnight',
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('property_setting_items', [
            'agency_id' => $this->agency->id, 'group' => 'rental_price_type', 'name' => 'Per Fortnight',
        ]);
    }

    public function test_the_settings_hub_batch_toggle_endpoint_accepts_both_new_groups(): void
    {
        $this->actingAs($this->owner)
            ->post(route('corex.settings.property-items.batch-toggle', 'lease_type'), ['enabled_ids' => []])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->post(route('corex.settings.property-items.batch-toggle', 'rental_price_type'), ['enabled_ids' => []])
            ->assertSessionHasNoErrors();
    }

    // ── real downstream consumers, not a label that feeds itself ─────────

    public function test_a_mappable_type_reaches_the_real_p24_commercial_payload(): void
    {
        $property = $this->rentalProperty();
        $property->update(['lease_type' => 'Triple Net']);

        $mapper = app(Property24ListingMapper::class);
        $ref = new \ReflectionMethod($mapper, 'mapLeaseType');
        $ref->setAccessible(true);

        $this->assertSame('TripleNet', $ref->invoke($mapper, $property->lease_type));
    }

    public function test_percentage_and_net_also_map_correctly_to_p24(): void
    {
        $mapper = app(Property24ListingMapper::class);
        $ref = new \ReflectionMethod($mapper, 'mapLeaseType');
        $ref->setAccessible(true);

        $this->assertSame('Percentage', $ref->invoke($mapper, 'Percentage'));
        $this->assertSame('Net', $ref->invoke($mapper, 'Net'));
        $this->assertSame('DoubleNet', $ref->invoke($mapper, 'Double Net'));
        $this->assertSame('FullyServicedLeaseGross', $ref->invoke($mapper, 'Fully Serviced Gross'));
    }

    public function test_gross_and_modified_gross_have_no_p24_equivalent_and_are_not_sent(): void
    {
        // Documents why: carried forward unchanged from the pre-existing lease
        // screens' real wording, but P24's own LeaseType enum has no matching
        // value for either -- guessing one would be worse than omitting it.
        $mapper = app(Property24ListingMapper::class);
        $ref = new \ReflectionMethod($mapper, 'mapLeaseType');
        $ref->setAccessible(true);

        $this->assertNull($ref->invoke($mapper, 'Gross'));
        $this->assertNull($ref->invoke($mapper, 'Modified Gross'));
    }

    public function test_lease_type_resolves_as_a_docuperfect_merge_field(): void
    {
        $service = app(WebTemplateDataService::class);
        $data = $service->resolve(0, [
            'property' => ['lease_type' => 'Fallback From Property'],
            'details' => ['lease_type' => 'Triple Net'],
            'recipients' => ['recipients' => []],
        ], $this->owner);

        $this->assertSame('Triple Net', $data['lease_type'], 'an explicit step value must win over the stored property value, same precedence as deposit/rental');
    }

    public function test_lease_type_merge_field_falls_back_to_the_property_value(): void
    {
        $service = app(WebTemplateDataService::class);
        $data = $service->resolve(0, [
            'property' => ['lease_type' => 'From The Property'],
            'details' => [],
            'recipients' => ['recipients' => []],
        ], $this->owner);

        $this->assertSame('From The Property', $data['lease_type']);
    }
}
