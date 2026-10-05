<?php

declare(strict_types=1);

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * CLAUDE.md non-negotiable #1 — no hard deletes, ever. AgentPpController::purgeListing()
 * used to call $property->forceDelete(), permanently destroying the row. Fixed
 * 2026-10-05 to go through the same guarded Property::delete() every other archive
 * action uses, so it's a real soft delete AND Property::blockingActiveLease()
 * (.ai/specs/leases.md "Archive guard") applies here too.
 */
final class PrivatePropertyPurgeListingArchivesTest extends TestCase
{
    use RefreshDatabase;

    public function test_purging_a_listing_soft_deletes_it_never_hard_deletes(): void
    {
        [$agency, , $property, $admin] = $this->makeAgencyBranchPropertyAdmin();
        $this->mockDeactivateListingSucceeds($property->id);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.pp.agents.purge-listing', ['id' => $property->id]));

        $response->assertOk();
        $response->assertJson(['success' => true]);
        self::assertStringContainsString('archived in CoreX', $response->json('message'));

        // The row must still physically exist — forceDelete() would remove it entirely.
        $stillThere = Property::withTrashed()->find($property->id);
        self::assertNotNull($stillThere, 'forceDelete() must never run — the row must survive, just soft-deleted');
        self::assertNotNull($stillThere->deleted_at);
    }

    public function test_purging_a_listing_with_an_active_lease_is_blocked(): void
    {
        [$agency, , $property, $admin] = $this->makeAgencyBranchPropertyAdmin();
        $lease = Lease::create([
            'agency_id' => $agency->id, 'branch_id' => $property->branch_id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000,
            'start_date' => now()->subMonth()->toDateString(), 'source' => 'manual',
        ]);
        $this->mockDeactivateListingSucceeds($property->id);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.pp.agents.purge-listing', ['id' => $property->id]));

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'lease_id' => $lease->id]);
        self::assertStringContainsString('active lease', $response->json('message'));

        self::assertNull(Property::withTrashed()->find($property->id)->deleted_at);
    }

    public function test_purging_an_already_archived_listing_does_not_re_delete_it(): void
    {
        [, , $property, $admin] = $this->makeAgencyBranchPropertyAdmin();
        $property->delete();
        $firstDeletedAt = $property->fresh()->deleted_at;
        $this->mockDeactivateListingSucceeds($property->id);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.pp.agents.purge-listing', ['id' => $property->id]));

        $response->assertOk();
        $response->assertJson(['success' => true]);
        self::assertSame(
            $firstDeletedAt->toDateTimeString(),
            Property::withTrashed()->find($property->id)->deleted_at->toDateTimeString(),
            'an already-archived row must not be re-deleted (no fresh deleted_at timestamp)'
        );
    }

    public function test_purging_an_orphan_id_still_tells_pp_to_deactivate(): void
    {
        $admin = $this->makeAdmin();
        $this->mockDeactivateListingSucceeds(999999);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.pp.agents.purge-listing', ['id' => 999999]));

        $response->assertOk();
        $response->assertJson(['success' => true]);
        self::assertStringContainsString('orphan', $response->json('message'));
    }

    /** @return array{0: Agency, 1: Branch, 2: Property, 3: User} */
    private function makeAgencyBranchPropertyAdmin(): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $admin->id,
            'title' => 'Test listing ' . uniqid(), 'status' => 'active', 'listing_type' => 'sale',
        ]);

        return [$agency, $branch, $property, $admin];
    }

    private function makeAdmin(): User
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);

        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
    }

    private function mockDeactivateListingSucceeds(int $propertyId): void
    {
        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('deactivateListing')
            ->with((string) $propertyId, 'Sale')
            ->andReturn(['error' => false]);
        $this->app->instance(PrivatePropertySoapClient::class, $client);
    }
}
