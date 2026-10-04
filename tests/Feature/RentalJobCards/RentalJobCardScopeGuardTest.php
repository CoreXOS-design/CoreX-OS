<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalJobCard;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-442 follow-up (conductor instruction, 2026-10-04) — same gap AT-439
 * found and fixed on Leases/Fault Reports/Work Orders, applied here: a
 * same-agency user whose role scope is 'own' must get a real 403 opening
 * ANOTHER user's job card by direct URL, not just a filtered list. Mirrors
 * RentalWorkOrderScopeGuardTest exactly. A CROSS-agency id is a different
 * mechanism (the global AgencyScope 404s before this guard is ever
 * reached) — covered separately by RentalJobCardLifecycleTest's own
 * cross-agency test.
 */
final class RentalJobCardScopeGuardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $own;
    private User $other;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'RJC Scope Guard Agency', 'slug' => 'rjcsg-' . uniqid()]);
        $this->branch = Branch::forceCreate(['agency_id' => $this->agency->id, 'name' => 'Branch A']);

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => 'rental_job_cards.view', 'agency_id' => $this->agency->id],
            ['scope' => 'own'],
        );
        PermissionService::clearCache();

        $this->own = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->other->id,
            'title' => 'Scope guard property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function jobCardOwnedByOther(): RentalJobCard
    {
        return app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Geyser burst'], $this->other);
    }

    public function test_own_scope_user_gets_403_opening_another_users_job_card(): void
    {
        $jobCard = $this->jobCardOwnedByOther();

        $this->actingAs($this->own)
            ->get(route('corex.rental-job-cards.show', $jobCard))
            ->assertForbidden();
    }

    public function test_own_scope_user_gets_403_printing_another_users_job_card(): void
    {
        $jobCard = $this->jobCardOwnedByOther();

        $this->actingAs($this->own)
            ->get(route('corex.rental-job-cards.print', $jobCard))
            ->assertForbidden();
    }

    public function test_own_scope_user_gets_403_via_the_mobile_api_too(): void
    {
        $jobCard = $this->jobCardOwnedByOther();

        $this->actingAs($this->own)
            ->getJson('/api/v1/mobile/rental-job-cards/' . $jobCard->id)
            ->assertForbidden();
    }

    public function test_own_scope_user_can_open_their_own_job_card(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'My own job'], $this->own);

        $this->actingAs($this->own)
            ->get(route('corex.rental-job-cards.show', $jobCard))
            ->assertOk();
    }
}
