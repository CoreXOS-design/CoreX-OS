<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\LeaseSetting;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan, 2026-09-22 (property 4283) — "the property lets you say 'deposit:
 * yes' with no amount... has_deposit = 1 and deposit_amount blank is what
 * produced the empty field downstream. Validate it: if the property says it
 * takes a deposit, an amount is required. Be careful how you enforce this
 * on EXISTING records — properties already saved in that state must not
 * become un-editable or start throwing on unrelated saves."
 *
 * Enforced via PropertyController::applyDepositDefault(), not a blocking
 * validation rule: a blank deposit_amount on a has_deposit=1 save is
 * auto-filled from LeaseSetting::defaultDepositMonthsFor() x rental_amount
 * and flagged deposit_amount_is_default=true, rather than the request being
 * rejected. This is deliberately how an existing non-compliant record
 * self-heals on its very next save (even one touching an unrelated field)
 * instead of becoming un-editable.
 */
final class PropertyDepositDefaultTest extends TestCase
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

    private function rentalProperty(array $attrs = []): Property
    {
        return Property::create(array_merge([
            'title' => 'Test Rental', 'agency_id' => $this->agency->id,
            'agent_id' => $this->owner->id, 'branch_id' => $this->branch->id,
            'listing_type' => 'rental', 'listing_type_pending' => false,
        ], $attrs));
    }

    public function test_a_blank_deposit_defaults_to_one_months_rent_and_is_flagged_computed(): void
    {
        $property = $this->rentalProperty();

        $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [
            'rental_amount' => '10000', 'has_deposit' => '1',
        ])->assertSessionDoesntHaveErrors();

        $property->refresh();
        self::assertSame(10000.0, (float) $property->deposit_amount, 'default multiple is 1 month per the codebase\'s own SA-market assumption');
        self::assertTrue($property->deposit_amount_is_default, 'a computed figure must be identifiable as computed');
    }

    public function test_an_agency_configured_multiple_is_used_instead_of_the_constant_default(): void
    {
        LeaseSetting::create(['agency_id' => $this->agency->id, 'default_deposit_months' => 2]);
        $property = $this->rentalProperty();

        $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [
            'rental_amount' => '10000', 'has_deposit' => '1',
        ])->assertSessionDoesntHaveErrors();

        $property->refresh();
        self::assertSame(20000.0, (float) $property->deposit_amount);
        self::assertTrue($property->deposit_amount_is_default);
    }

    public function test_a_typed_deposit_amount_is_saved_as_is_and_never_flagged_computed(): void
    {
        $property = $this->rentalProperty();

        $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [
            'rental_amount' => '10000', 'has_deposit' => '1', 'deposit_amount' => '7500',
        ])->assertSessionDoesntHaveErrors();

        $property->refresh();
        self::assertSame(7500.0, (float) $property->deposit_amount, 'a real, human-typed figure must never be overwritten by the computed default');
        self::assertFalse($property->deposit_amount_is_default);
    }

    public function test_no_deposit_taken_means_no_default_is_computed(): void
    {
        $property = $this->rentalProperty();

        // has_deposit omitted entirely — an unchecked checkbox.
        $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [
            'rental_amount' => '10000',
        ])->assertSessionDoesntHaveErrors();

        $property->refresh();
        self::assertFalse((bool) $property->has_deposit);
        self::assertNull($property->deposit_amount);
        self::assertFalse($property->deposit_amount_is_default);
    }

    /**
     * The exact scenario Johan measured on property 4283: an EXISTING
     * record already saved with has_deposit=1 and deposit_amount blank —
     * this must self-heal on its next save, whether or not that save
     * touches the deposit fields at all, and must never throw or block.
     */
    public function test_an_existing_non_compliant_record_self_heals_on_the_next_unrelated_save(): void
    {
        $property = $this->rentalProperty([
            'has_deposit' => true, 'deposit_amount' => null, 'rental_amount' => 6000,
        ]);
        self::assertNull($property->fresh()->deposit_amount, 'sanity check: the bad state actually exists before the save under test');

        // An UNRELATED field changes; has_deposit is still checked in the
        // form (the agent never touched that control), deposit_amount is
        // still blank (never was set) — this must save cleanly, not 422,
        // not 500, and the property must not become un-editable.
        $resp = $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [
            'rental_amount' => '6000', 'has_deposit' => '1', 'furnished_status' => 'Furnished',
        ]);

        $resp->assertSessionDoesntHaveErrors();
        $property->refresh();
        self::assertSame(6000.0, (float) $property->deposit_amount, 'self-healed to 1 month\'s rent, the default multiple');
        self::assertTrue($property->deposit_amount_is_default);
        self::assertSame('Furnished', $property->furnished_status, 'the unrelated field the agent actually meant to change must still save');
    }

    public function test_default_deposit_months_resolver_falls_back_to_one_month_with_no_agency_row(): void
    {
        self::assertSame(1.0, LeaseSetting::defaultDepositMonthsFor($this->agency->id));
        self::assertSame(1.0, LeaseSetting::DEFAULT_DEPOSIT_MONTHS);
    }
}
