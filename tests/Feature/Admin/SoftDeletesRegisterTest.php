<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Admin\SoftDeleteRegistryService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks in the Admin → Soft Deletes Register behaviour.
 * Spec: .ai/specs/soft-deletes-admin.md.
 *
 * Permission convention: with `role_permissions` unseeded the PermissionService
 * grants every permission, so factory users pass the `access_soft_deletes`
 * middleware. We clear its static cache in tearDown so we don't leak the
 * "grant-all" state into later suites.
 */
class SoftDeletesRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    /** @return array{0: Agency, 1: Branch, 2: User} */
    private function makeAgencyUser(string $slug): array
    {
        $agency = Agency::create(['name' => "Agency {$slug}", 'slug' => "agency-{$slug}"]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => "{$slug} Main"]);
        $user = User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'admin',
        ]);

        return [$agency, $branch, $user];
    }

    private function makeProperty(Agency $agency, Branch $branch, User $user): Property
    {
        return Property::create([
            'agency_id'     => $agency->id,
            'agent_id'      => $user->id,
            'branch_id'     => $branch->id,
            'external_id'   => (string) Str::uuid(),
            'title'         => 'Test ' . $agency->slug,
            'suburb'        => 'Margate',
            'property_type' => 'house',
            'status'        => 'draft',
            'price'         => 0,
        ]);
    }

    public function test_index_renders_and_shows_archived_property_category(): void
    {
        [$agency, $branch, $user] = $this->makeAgencyUser('a');
        $this->makeProperty($agency, $branch, $user)->delete();

        $this->actingAs($user)
            ->get(route('admin.soft-deletes.index'))
            ->assertOk()
            ->assertSee('Soft Deletes')
            ->assertSee('Properties');
    }

    public function test_registry_counts_only_callers_own_agency(): void
    {
        [$agencyA, $branchA, $userA] = $this->makeAgencyUser('a');
        [$agencyB, $branchB, $userB] = $this->makeAgencyUser('b');

        $this->makeProperty($agencyA, $branchA, $userA)->delete();
        $this->makeProperty($agencyB, $branchB, $userB)->delete();
        $this->makeProperty($agencyB, $branchB, $userB)->delete();

        $this->actingAs($userA);
        $registry = app(SoftDeleteRegistryService::class);

        $pillars = $registry->categoriesWithCounts($userA)->firstWhere('category', 'Pillars');
        $this->assertNotNull($pillars, 'Pillars category should be present');

        $propEntry = collect($pillars['models'])->firstWhere('key', 'Property');
        $this->assertNotNull($propEntry, 'Property should appear with archived records');
        $this->assertSame(1, $propEntry['count'], 'User A must only count their own agency archived properties');
    }

    public function test_restore_brings_record_back_and_writes_audit_row(): void
    {
        [$agency, $branch, $user] = $this->makeAgencyUser('a');
        $property = $this->makeProperty($agency, $branch, $user);
        $property->delete();

        $this->assertNull(Property::find($property->id), 'Precondition: property is archived');

        $this->actingAs($user)
            ->post(route('admin.soft-deletes.restore', ['Property', $property->id]))
            ->assertRedirect(route('admin.soft-deletes.show', 'Property'))
            ->assertSessionHas('success');

        $this->assertNotNull(Property::find($property->id), 'Property should be restored');
        $this->assertDatabaseHas('soft_delete_restorations', [
            'model_type'          => Property::class,
            'model_id'            => $property->id,
            'agency_id'           => $agency->id,
            'restored_by_user_id' => $user->id,
        ]);
    }

    public function test_user_cannot_restore_another_agencys_record(): void
    {
        [$agencyA, $branchA, $userA] = $this->makeAgencyUser('a');
        [$agencyB, $branchB, $userB] = $this->makeAgencyUser('b');

        $propertyB = $this->makeProperty($agencyB, $branchB, $userB);
        $propertyB->delete();

        // User A attempts to restore Agency B's archived property.
        $this->actingAs($userA)
            ->post(route('admin.soft-deletes.restore', ['Property', $propertyB->id]))
            ->assertRedirect(route('admin.soft-deletes.show', 'Property'))
            ->assertSessionHas('error');

        // It must remain archived and unaudited.
        $this->actingAs($userB);
        $this->assertNull(Property::find($propertyB->id), 'Agency B property must still be archived');
        $this->assertNotNull(Property::onlyTrashed()->find($propertyB->id));
        $this->assertDatabaseMissing('soft_delete_restorations', [
            'model_type' => Property::class,
            'model_id'   => $propertyB->id,
        ]);
    }

    public function test_unknown_model_key_404s(): void
    {
        [, , $user] = $this->makeAgencyUser('a');

        $this->actingAs($user)
            ->get(route('admin.soft-deletes.show', 'NotARealModelKey'))
            ->assertNotFound();
    }
}
