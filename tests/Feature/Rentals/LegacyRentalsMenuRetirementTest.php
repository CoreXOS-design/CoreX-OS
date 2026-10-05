<?php

namespace Tests\Feature\Rentals;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-439 part 2 — legacy Rentals menu/route/view retirement, Johan's ruling
 * 2026-10-05: old URLs must redirect to the new screens, never 404; the old
 * rental commission worksheet and "Rental Properties" list go; the rental
 * reminders screen+menu entry retires (table stays); RentalDocumentType
 * stays untouched.
 */
class LegacyRentalsMenuRetirementTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $agency = Agency::create(['name' => 'Test Agency ' . uniqid(), 'slug' => 'test-agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Default']);

        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
    }

    public function test_old_rentals_index_redirects_to_leases_never_404s(): void
    {
        $this->actingAs($this->makeUser())->get(route('rentals.index'))
            ->assertRedirect(route('corex.leases.index'));
    }

    public function test_old_rental_dashboard_redirects_to_command_centre(): void
    {
        $this->actingAs($this->makeUser())->get(route('rental.dashboard'))
            ->assertRedirect(route('corex.rentals.command-centre.index'));
    }

    public function test_old_rental_active_and_expired_leases_redirect_with_status_filter(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->get(route('rental.active-leases'))
            ->assertRedirect(route('corex.leases.index', ['status' => 'active']));
        $this->actingAs($user)->get(route('rental.expired-leases'))
            ->assertRedirect(route('corex.leases.index', ['status' => 'expired']));
    }

    public function test_old_rental_properties_settings_redirects_to_real_properties_screen(): void
    {
        $this->actingAs($this->makeUser())->get(route('rental.settings.properties.index'))
            ->assertRedirect(route('corex.properties.index'));
    }

    public function test_old_rental_reminders_settings_redirects_to_main_settings_not_404(): void
    {
        $this->actingAs($this->makeUser())->get(route('rental.settings.reminders.index'))
            ->assertRedirect(route('corex.settings'));
    }

    public function test_document_types_screen_is_unaffected_by_the_retirement(): void
    {
        $this->actingAs($this->makeUser())->get(route('rental.settings.document-types.index'))
            ->assertOk();
    }

    public function test_settings_page_drops_rental_properties_and_reminders_but_keeps_document_types(): void
    {
        $response = $this->actingAs($this->makeUser())->get(route('corex.settings', ['s' => 'feature-rentals']));

        $response->assertOk();
        $response->assertDontSee('Rental Properties');
        $response->assertDontSee('Email Reminders');
        $response->assertSee('Rental Document Types');
    }

    public function test_worksheet_page_no_longer_shows_the_rentals_inclusion_card(): void
    {
        $user = $this->makeUser();
        $response = $this->actingAs($user)->get(route('worksheet.index'));

        $response->assertOk();
        $response->assertDontSee('Rentals (This Period)');
    }

    public function test_view_rentals_and_access_rental_signatures_permission_keys_are_retired(): void
    {
        $keys = collect(config('corex-permissions.permissions'))->pluck('key');

        $this->assertFalse($keys->contains('view_rentals'), 'view_rentals must be fully retired.');
        $this->assertFalse($keys->contains('access_rental_signatures'), 'access_rental_signatures must be fully retired.');
        // manage_rentals is KEPT — RentalPermissionsController (unrelated, unflagged) still needs it.
        $this->assertTrue($keys->contains('manage_rentals'), 'manage_rentals must NOT be removed — a different, unrelated screen still depends on it.');
    }
}
