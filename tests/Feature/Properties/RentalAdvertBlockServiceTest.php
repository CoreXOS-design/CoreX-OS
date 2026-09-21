<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyRentalDetailsCustomField;
use App\Services\Properties\RentalAdvertBlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-property-tab.md §4, Part 5. The one assembly point every
 * portal mapper and the website API call instead of reading
 * $property->description directly.
 */
final class RentalAdvertBlockServiceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private RentalAdvertBlockService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->service = app(RentalAdvertBlockService::class);
    }

    private function rentalProperty(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'listing_type' => 'rental',
            'description' => 'A lovely home.',
        ], $overrides));
    }

    /** §4.0 — off is the default, on every property, including every existing one. */
    public function test_master_tick_off_by_default_leaves_description_byte_for_byte_unchanged(): void
    {
        $property = $this->rentalProperty([
            'admin_fee' => 1500,
            'advertise_core_fields' => ['admin_fee'],
        ]);

        $this->assertFalse($property->rental_advert_block_enabled);
        $this->assertSame('A lovely home.', $this->service->descriptionForSyndication($property));
    }

    /** §4.0 — an existing hand-typed advert is untouched even with fields ticked, until the master tick is on. */
    public function test_ticked_fields_have_no_effect_while_master_tick_is_off(): void
    {
        $property = $this->rentalProperty([
            'description' => 'Existing hand-typed advert text.',
            'admin_fee' => 1500,
            'marketing_fee' => 750,
            'advertise_core_fields' => ['admin_fee', 'marketing_fee'],
            'rental_advert_block_enabled' => false,
        ]);

        $this->assertSame('Existing hand-typed advert text.', $this->service->descriptionForSyndication($property));
    }

    public function test_master_tick_on_appends_only_ticked_core_fields_with_real_values(): void
    {
        $property = $this->rentalProperty([
            'description' => 'A lovely home.',
            'admin_fee' => 1500,
            'marketing_fee' => 750,
            // Only admin_fee ticked — marketing_fee has a real value but must not appear.
            'advertise_core_fields' => ['admin_fee'],
            'rental_advert_block_enabled' => true,
        ]);

        $result = $this->service->descriptionForSyndication($property);

        $this->assertStringContainsString('A lovely home.', $result);
        $this->assertStringContainsString('Once-off admin fee: R 1 500', $result);
        $this->assertStringNotContainsString('Marketing fee', $result);
    }

    /** §4.1 — a field with no value and a field that isn't ticked both produce nothing, never a blank line or "R0". */
    public function test_a_ticked_field_with_no_value_or_a_zero_value_produces_no_line(): void
    {
        $propertyNull = $this->rentalProperty([
            'admin_fee' => null,
            'advertise_core_fields' => ['admin_fee'],
            'rental_advert_block_enabled' => true,
        ]);
        $this->assertSame('', $this->service->buildBlock($propertyNull));

        $propertyZero = $this->rentalProperty([
            'admin_fee' => 0,
            'advertise_core_fields' => ['admin_fee'],
            'rental_advert_block_enabled' => true,
        ]);
        $this->assertSame('', $this->service->buildBlock($propertyZero));
    }

    /**
     * §4.4 — rental_amount and deposit_amount are deliberately NOT in
     * CORE_FIELDS at all (they already reach both portals via native slots),
     * so even a malformed/stray key in the stored column can never surface
     * them in the text block.
     */
    public function test_only_the_two_named_core_fields_are_ever_eligible_regardless_of_what_is_stored(): void
    {
        $property = $this->rentalProperty([
            'rental_amount' => 9500,
            'deposit_amount' => 9500,
            'advertise_core_fields' => ['admin_fee', 'marketing_fee', 'rental_amount', 'deposit_amount', 'not_a_real_field'],
            'admin_fee' => null,
            'marketing_fee' => null,
            'rental_advert_block_enabled' => true,
        ]);

        $this->assertSame('', $this->service->buildBlock($property));
        $this->assertSame(['admin_fee', 'marketing_fee'], array_keys(RentalAdvertBlockService::CORE_FIELDS));
    }

    public function test_agency_defined_field_appears_only_when_its_own_advertise_flag_is_ticked(): void
    {
        $advertised = PropertyRentalDetailsCustomField::create([
            'agency_id' => $this->agency->id,
            'key' => PropertyRentalDetailsCustomField::generateKey($this->agency->id, 'Lets Assist'),
            'label' => 'Lets Assist',
            'field_type' => PropertyRentalDetailsCustomField::TYPE_TEXT,
            'shown' => true,
            'advertise' => true,
            'sort_order' => 1,
        ]);
        $notAdvertised = PropertyRentalDetailsCustomField::create([
            'agency_id' => $this->agency->id,
            'key' => PropertyRentalDetailsCustomField::generateKey($this->agency->id, 'Internal Note'),
            'label' => 'Internal Note',
            'field_type' => PropertyRentalDetailsCustomField::TYPE_TEXT,
            'shown' => true,
            'advertise' => false,
            'sort_order' => 2,
        ]);

        $property = $this->rentalProperty([
            'rental_advert_block_enabled' => true,
            'rental_details_custom_field_values' => [
                $advertised->key => 'R150/month, first month free',
                $notAdvertised->key => 'Agent: check with owner first',
            ],
        ]);

        $result = $this->service->buildBlock($property);

        $this->assertStringContainsString('Lets Assist: R150/month, first month free', $result);
        $this->assertStringNotContainsString('Internal Note', $result);
    }

    public function test_a_yes_no_custom_field_renders_as_yes_or_no_not_the_raw_stored_value(): void
    {
        $field = PropertyRentalDetailsCustomField::create([
            'agency_id' => $this->agency->id,
            'key' => PropertyRentalDetailsCustomField::generateKey($this->agency->id, 'Pets Considered'),
            'label' => 'Pets Considered',
            'field_type' => PropertyRentalDetailsCustomField::TYPE_YES_NO,
            'shown' => true,
            'advertise' => true,
            'sort_order' => 1,
        ]);

        $property = $this->rentalProperty([
            'rental_advert_block_enabled' => true,
            'rental_details_custom_field_values' => [$field->key => true],
        ]);

        $this->assertStringContainsString('Pets Considered: Yes', $this->service->buildBlock($property));
    }

    /** §4.1 — two different agencies never see each other's advertised custom fields. */
    public function test_cross_agency_isolation(): void
    {
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        PropertyRentalDetailsCustomField::create([
            'agency_id' => $otherAgency->id,
            'key' => 'custom_other_agency_field',
            'label' => 'Other Agency Field',
            'field_type' => PropertyRentalDetailsCustomField::TYPE_TEXT,
            'shown' => true,
            'advertise' => true,
            'sort_order' => 1,
        ]);

        $property = $this->rentalProperty([
            'rental_advert_block_enabled' => true,
            'rental_details_custom_field_values' => ['custom_other_agency_field' => 'Should never appear'],
        ]);

        $this->assertSame('', $this->service->buildBlock($property));
    }
}
