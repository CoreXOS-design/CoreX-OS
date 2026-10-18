<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Agency;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Core Matches 500'd for the System Owner (super_admin, agency_id NULL) who had
 * not picked an agency: ContactMatchController::renderBoard() passed
 * effectiveAgencyId() === null into AgencyContactSettings::forAgency(int).
 *
 * The app's existing answer for "owner with no agency" is `agency.required`
 * (RequireAgencyContext) -> the agency picker, remembering the URL so the owner
 * lands back on the board after choosing. All four board entry points carry it.
 */
final class CoreMatchesOwnerNoAgencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::clearCache();
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function boardRoutes(): array
    {
        return [
            'sales board'        => ['corex.core-matches.index'],
            'sales all view'     => ['corex.core-matches.all'],
            'rentals board'      => ['corex.rentals.core-matches.index'],
            'rentals all view'   => ['corex.rentals.core-matches.all'],
        ];
    }

    #[DataProvider('boardRoutes')]
    public function test_owner_without_an_agency_is_sent_to_the_agency_picker_not_a_500(string $routeName): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->get(route($routeName))
            ->assertRedirect(route('agency.select'));

        $this->assertSame(
            route($routeName),
            session('intended_after_agency_select'),
            'The picker must send the owner back to the Core Matches screen they asked for.'
        );
    }

    public function test_owner_with_an_agency_selected_gets_the_board(): void
    {
        $owner  = $this->makeOwner();
        $agency = Agency::create(['name' => 'Agency', 'slug' => 'agency']);

        $this->actingAs($owner)
            ->withSession(['active_agency_id' => $agency->id])
            ->get(route('corex.core-matches.index'))
            ->assertOk();
    }

    private function makeOwner(): User
    {
        // forceFill: `is_owner` is not mass-assignable, and a NULL there makes
        // isOwnerRole() quietly false (same note as ActivityMappingAccessTest).
        $role = Role::query()->firstOrNew(['name' => 'super_admin', 'agency_id' => null]);
        $role->forceFill([
            'label'          => 'System Owner',
            'is_owner'       => true,
            'can_be_deleted' => false,
        ])->save();
        Role::clearCache();

        return User::factory()->create([
            'role' => 'super_admin', 'agency_id' => null, 'branch_id' => null, 'is_active' => 1,
        ]);
    }
}
