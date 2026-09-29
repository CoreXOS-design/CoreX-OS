<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan, 2026-09-22, verbatim: "The freaking lease type is showing here
 * again. do not know why the hell we have this. dont remove it, but hide
 * it... Same on rental tab on properties." Agency-configurable
 * (LeaseSetting::showLeaseTypeFieldFor()), sensible default HIDDEN, never a
 * commented-out block — the column, model, agency-editable list, and
 * Property24 mapping (.ai/specs/rental-property-tab.md §5.3) stay intact.
 *
 * Also covers the companion ask: the lease EDIT screen shows the monthly
 * rental amount, read-only, so an agent typing the deposit (which normally
 * equals a month's rent) doesn't have to leave the screen to check it.
 */
final class LeaseTypeVisibilityTest extends TestCase
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

    private function rentalProperty(): Property
    {
        return Property::create([
            'title' => 'Test Rental', 'agency_id' => $this->agency->id,
            'agent_id' => $this->owner->id, 'branch_id' => $this->branch->id,
            'listing_type' => 'rental', 'listing_type_pending' => false,
            'property_type' => 'House',
        ]);
    }

    private function activeLease(Property $property, array $overrides = []): Lease
    {
        return Lease::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'status' => 'active', 'rental_amount' => 12500, 'start_date' => '2026-01-01', 'source' => 'manual',
            'lease_type' => 'Net',
        ], $overrides));
    }

    // ── default: hidden ──────────────────────────────────────────────────

    public function test_default_is_hidden(): void
    {
        self::assertFalse(LeaseSetting::showLeaseTypeFieldFor(null));
        self::assertFalse(LeaseSetting::showLeaseTypeFieldFor($this->agency->id));
    }

    public function test_lease_type_is_hidden_by_default_on_the_lease_screen(): void
    {
        $property = $this->rentalProperty();
        $lease = $this->activeLease($property);

        $resp = $this->actingAs($this->owner)->get(route('corex.leases.show', $lease));

        $resp->assertStatus(200);
        $resp->assertDontSee('Lease type', false);
        // The underlying value is untouched -- it is only the on-screen
        // control that goes away.
        self::assertSame('Net', $lease->fresh()->lease_type);
    }

    public function test_lease_type_shows_on_the_lease_screen_once_the_agency_setting_is_on(): void
    {
        LeaseSetting::create(['agency_id' => $this->agency->id, 'show_lease_type_field' => true]);
        $property = $this->rentalProperty();
        $lease = $this->activeLease($property);

        $resp = $this->actingAs($this->owner)->get(route('corex.leases.show', $lease));

        $resp->assertStatus(200);
        $resp->assertSee('Lease type', false);
    }

    public function test_lease_type_is_hidden_by_default_on_the_property_rental_tab(): void
    {
        $property = $this->rentalProperty();

        $resp = $this->actingAs($this->owner)->get(route('corex.properties.show', $property));

        $resp->assertStatus(200);
        $resp->assertDontSee('Lease Type', false);
    }

    public function test_lease_type_shows_on_the_property_rental_tab_once_the_agency_setting_is_on(): void
    {
        LeaseSetting::create(['agency_id' => $this->agency->id, 'show_lease_type_field' => true]);
        $property = $this->rentalProperty();

        $resp = $this->actingAs($this->owner)->get(route('corex.properties.show', $property));

        $resp->assertStatus(200);
        $resp->assertSee('Lease Type', false);
    }

    // ── hiding the control never touches the stored value ───────────────

    public function test_updating_a_lease_with_the_control_hidden_leaves_lease_type_untouched(): void
    {
        $property = $this->rentalProperty();
        $lease = $this->activeLease($property, ['lease_type' => 'Gross']);

        // A hidden select never submits a lease_type field at all -- this
        // mirrors the real form exactly (no lease_type key in the POST body).
        $this->actingAs($this->owner)->put(route('corex.leases.update', $lease), [
            'deposit_amount' => 9000,
        ])->assertSessionDoesntHaveErrors();

        self::assertSame('Gross', $lease->fresh()->lease_type);
        self::assertSame('9000.00', $lease->fresh()->deposit_amount);
    }

    // ── settings screen ───────────────────────────────────────────────────

    public function test_settings_screen_defaults_to_unchecked_and_saves_a_new_value(): void
    {
        $resp = $this->actingAs($this->owner)->get(route('corex.settings.leases.edit'));
        $resp->assertOk();
        $resp->assertDontSee('name="show_lease_type_field" value="1" checked', false);

        $this->actingAs($this->owner)->post(route('corex.settings.leases.update'), [
            'expiry_notice_window_days' => 60,
            'show_lease_type_field' => '1',
        ])->assertRedirect(route('corex.settings.leases.edit'));

        self::assertTrue(LeaseSetting::showLeaseTypeFieldFor($this->agency->id));

        // Unchecking (omitted from the POST, as a real unchecked checkbox
        // would be) actually turns it back off -- not silently ignored.
        $this->actingAs($this->owner)->post(route('corex.settings.leases.update'), [
            'expiry_notice_window_days' => 60,
        ]);

        self::assertFalse(LeaseSetting::showLeaseTypeFieldFor($this->agency->id));
    }

    // ── A2: read-only rent on the lease edit screen ──────────────────────

    public function test_the_lease_edit_screen_shows_the_monthly_rental_read_only(): void
    {
        $property = $this->rentalProperty();
        $lease = $this->activeLease($property, ['rental_amount' => 15750]);

        $resp = $this->actingAs($this->owner)->get(route('corex.leases.show', $lease));

        $resp->assertStatus(200);
        $resp->assertSee('Monthly rental (R)', false);
        $resp->assertSee('R15,750.00', false);
        // Read-only: no editable input named rental_amount anywhere on the page.
        $resp->assertDontSee('name="rental_amount"', false);
    }
}
