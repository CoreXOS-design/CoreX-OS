<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-439 item 2(b) — LeaseController previously relied ONLY on query-layer
 * scoping (Lease::scopeVisibleTo()) for the list screen; show()/update()/
 * destroy() had no per-record re-check at all, so a user scoped to 'own'
 * could still open/mutate ANY lease in the agency by direct URL/ID. Proves
 * AuthorizesRentalRecordScope::guardRentalRecordScope() closes that gap.
 */
final class LeaseRecordScopeGuardTest extends TestCase
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

        $this->agency = Agency::create(['name' => 'Scope Guard Agency', 'slug' => 'sga-' . uniqid()]);
        $this->branch = Branch::forceCreate(['agency_id' => $this->agency->id, 'name' => 'Branch A']);

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => 'leases.view', 'agency_id' => $this->agency->id],
            ['scope' => 'own'],
        );
        PermissionService::clearCache();

        $this->own = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->other->id,
            'title' => 'Scope guard property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function leaseOwnedByOther(): Lease
    {
        return Lease::create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT,
            'rental_amount' => 9000,
            'start_date' => now()->toDateString(),
            'source' => 'manual',
            'created_by_user_id' => $this->other->id,
        ]);
    }

    public function test_own_scope_user_gets_403_opening_another_users_lease(): void
    {
        $lease = $this->leaseOwnedByOther();

        $this->actingAs($this->own)
            ->get(route('corex.leases.show', $lease))
            ->assertForbidden();
    }

    public function test_own_scope_user_gets_403_updating_another_users_lease(): void
    {
        $lease = $this->leaseOwnedByOther();

        $this->actingAs($this->own)
            ->put(route('corex.leases.update', $lease), ['deposit_amount' => 1000])
            ->assertForbidden();
    }

    public function test_own_scope_user_gets_403_destroying_another_users_lease(): void
    {
        $lease = $this->leaseOwnedByOther();

        $this->actingAs($this->own)
            ->delete(route('corex.leases.destroy', $lease))
            ->assertForbidden();
    }

    public function test_own_scope_user_can_open_their_own_lease(): void
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 9000, 'start_date' => now()->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $this->own->id,
        ]);

        $this->actingAs($this->own)
            ->get(route('corex.leases.show', $lease))
            ->assertOk();
    }
}
