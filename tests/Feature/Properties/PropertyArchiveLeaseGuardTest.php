<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Exceptions\PropertyHasActiveLeaseException;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan's ruling (2026-10-05) — a property with an active lease must never be
 * archived. See .ai/specs/leases.md "Archive guard". Covers every soft-delete
 * path found in the codebase: the single archive action
 * (PropertyController::destroy), change-listing-type's archive-the-original
 * step (PropertyController::changeType), and the upload wizard's discard-draft
 * path (PropertyWizardController::discardDraft) — all three call
 * Property::delete(), so PropertyObserver::deleting() is the one choke point
 * that guards all of them, plus any future caller, without re-implementing the
 * check at each site. No bulk-archive route exists for properties today (see
 * the build report) — nothing to test there yet.
 */
final class PropertyArchiveLeaseGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_archive_action_is_blocked_by_an_active_lease(): void
    {
        [$agency, , $property, $admin] = $this->makeAgencyBranchPropertyAdmin();
        $lease = $this->makeLease($agency, $property, Lease::STATUS_ACTIVE);

        $this->actingAs($admin)
            ->delete(route('corex.properties.destroy', $property))
            ->assertRedirect(route('corex.leases.show', $lease))
            ->assertSessionHas('error');

        self::assertNull($property->fresh()->deleted_at, 'property must not be archived while the lease is active');
    }

    public function test_single_archive_action_json_caller_gets_422_with_lease_url(): void
    {
        [$agency, , $property, $admin] = $this->makeAgencyBranchPropertyAdmin();
        $lease = $this->makeLease($agency, $property, Lease::STATUS_ACTIVE);

        $response = $this->actingAs($admin)
            ->delete(route('corex.properties.destroy', $property), [], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $response->assertJson([
            'ok'       => false,
            'lease_id' => $lease->id,
        ]);
        self::assertSame(route('corex.leases.show', $lease), $response->json('lease_url'));
        self::assertStringContainsString('active lease', $response->json('error'));

        self::assertNull($property->fresh()->deleted_at);
    }

    public function test_archive_message_names_the_tenant_and_end_date(): void
    {
        [$agency, , $property, $admin] = $this->makeAgencyBranchPropertyAdmin();
        $lease = $this->makeLease($agency, $property, Lease::STATUS_ACTIVE, [
            'end_date' => '2027-03-31',
        ]);
        $this->attachTenant($lease, 'Thandiwe', 'Mokoena');

        $response = $this->actingAs($admin)
            ->delete(route('corex.properties.destroy', $property), [], ['Accept' => 'application/json']);

        $message = $response->json('error');
        self::assertStringContainsString('Thandiwe Mokoena', $message);
        self::assertStringContainsString('2027-03-31', $message);
        self::assertStringContainsString('End or cancel the lease before archiving.', $message);
    }

    public function test_month_to_month_active_lease_blocks_without_an_end_date(): void
    {
        [$agency, , $property, $admin] = $this->makeAgencyBranchPropertyAdmin();
        $lease = $this->makeLease($agency, $property, Lease::STATUS_ACTIVE, [
            'end_date' => null,
            'is_month_to_month' => true,
        ]);

        $this->actingAs($admin)
            ->delete(route('corex.properties.destroy', $property))
            ->assertRedirect(route('corex.leases.show', $lease));

        self::assertNull($property->fresh()->deleted_at);
    }

    public function test_a_future_dated_active_lease_signed_but_not_yet_started_still_blocks(): void
    {
        [$agency, , $property, $admin] = $this->makeAgencyBranchPropertyAdmin();
        // Activated already (status=active) even though the tenancy itself starts next month —
        // leases.md's point: activation, not the start date, is what "in force" means.
        $lease = $this->makeLease($agency, $property, Lease::STATUS_ACTIVE, [
            'start_date' => now()->addMonth()->toDateString(),
        ]);

        $this->actingAs($admin)
            ->delete(route('corex.properties.destroy', $property))
            ->assertRedirect(route('corex.leases.show', $lease));

        self::assertNull($property->fresh()->deleted_at);
    }

    /** @dataProvider nonBlockingStatuses */
    public function test_single_archive_action_is_allowed_for_non_active_lease_statuses(string $status): void
    {
        [$agency, , $property, $admin] = $this->makeAgencyBranchPropertyAdmin();
        $this->makeLease($agency, $property, $status);

        $this->actingAs($admin)
            ->delete(route('corex.properties.destroy', $property))
            ->assertRedirect(route('corex.properties.index'));

        self::assertNotNull($property->fresh()->deleted_at, "archive must succeed when the only lease is '{$status}'");
    }

    public static function nonBlockingStatuses(): array
    {
        return [
            'draft' => [Lease::STATUS_DRAFT],
            'cancelled' => [Lease::STATUS_CANCELLED],
            'expired' => [Lease::STATUS_EXPIRED],
        ];
    }

    public function test_a_draft_renewal_on_an_ended_lease_does_not_block_archiving(): void
    {
        [$agency, , $property, $admin] = $this->makeAgencyBranchPropertyAdmin();
        $ended = $this->makeLease($agency, $property, Lease::STATUS_EXPIRED);
        $this->makeLease($agency, $property, Lease::STATUS_DRAFT, [
            'previous_lease_id' => $ended->id,
            'start_date' => now()->addMonth()->toDateString(),
        ]);

        $this->actingAs($admin)
            ->delete(route('corex.properties.destroy', $property))
            ->assertRedirect(route('corex.properties.index'));

        self::assertNotNull($property->fresh()->deleted_at);
    }

    public function test_property_with_no_lease_at_all_archives_normally(): void
    {
        [, , $property, $admin] = $this->makeAgencyBranchPropertyAdmin();

        $this->actingAs($admin)
            ->delete(route('corex.properties.destroy', $property))
            ->assertRedirect(route('corex.properties.index'));

        self::assertNotNull($property->fresh()->deleted_at);
    }

    public function test_change_type_archive_step_is_blocked_by_an_active_lease(): void
    {
        [$agency, , $property, $admin] = $this->makeAgencyBranchPropertyAdmin(['status' => 'draft', 'listing_type' => 'rental']);
        $lease = $this->makeLease($agency, $property, Lease::STATUS_ACTIVE);

        $this->actingAs($admin)
            ->post(route('corex.properties.change-type', $property))
            ->assertRedirect(route('corex.leases.show', $lease));

        self::assertNull($property->fresh()->deleted_at, 'the original listing must survive a blocked change-type');
        self::assertSame('draft', $property->fresh()->status, 'status=archived must roll back with the delete inside the same transaction');
    }

    public function test_wizard_discard_draft_is_blocked_by_an_active_lease(): void
    {
        [$agency, , $property, $admin] = $this->makeAgencyBranchPropertyAdmin(['status' => 'draft', 'published_at' => null]);
        $lease = $this->makeLease($agency, $property, Lease::STATUS_ACTIVE);

        $this->actingAs($admin)
            ->delete(route('corex.properties.wizard.discard', $property))
            ->assertRedirect(route('corex.leases.show', $lease));

        self::assertNull($property->fresh()->deleted_at);
    }

    public function test_force_delete_is_also_blocked_the_same_way_as_soft_delete(): void
    {
        // Confirms the guard protects the hard-delete path too (forceDelete() routes through the
        // same Eloquent `deleting` event soft-delete fires) — defence in depth for any existing or
        // future forceDelete() caller, even though none is in this task's own scope to change.
        [$agency, , $property] = $this->makeAgencyBranchPropertyAdmin();
        $this->makeLease($agency, $property, Lease::STATUS_ACTIVE);

        $this->expectException(PropertyHasActiveLeaseException::class);
        $property->forceDelete();
    }

    public function test_blocking_active_lease_resolves_correctly_without_an_authenticated_user(): void
    {
        // Console/import/job context has no Auth::user(), so AgencyScope is skipped (see
        // AgencyScope's own docblock) — the guard must still resolve correctly via its explicit
        // property_id + agency_id filter, not rely on the ambient scope.
        [$agency, , $property] = $this->makeAgencyBranchPropertyAdmin();
        $lease = $this->makeLease($agency, $property, Lease::STATUS_ACTIVE);

        self::assertGuest();
        $found = $property->blockingActiveLease();
        self::assertNotNull($found);
        self::assertSame($lease->id, $found->id);
    }

    public function test_blocking_active_lease_is_null_for_a_different_property(): void
    {
        [$agency, $branch, $property, $admin] = $this->makeAgencyBranchPropertyAdmin();
        $otherProperty = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $admin->id,
            'title' => 'Other property ' . uniqid(), 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->makeLease($agency, $otherProperty, Lease::STATUS_ACTIVE);

        self::assertNull($property->blockingActiveLease());
    }

    /** @return array{0: Agency, 1: Branch, 2: Property, 3: User} */
    private function makeAgencyBranchPropertyAdmin(array $propertyOverrides = []): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::create(array_merge([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $admin->id,
            'title' => 'Test property ' . uniqid(),
            'status' => 'active', 'listing_type' => 'rental',
        ], $propertyOverrides));

        return [$agency, $branch, $property, $admin];
    }

    private function makeLease(Agency $agency, Property $property, string $status, array $overrides = []): Lease
    {
        return Lease::create(array_merge([
            'agency_id' => $agency->id,
            'branch_id' => $property->branch_id,
            'property_id' => $property->id,
            'status' => $status,
            'rental_amount' => 9000,
            'start_date' => now()->subMonth()->toDateString(),
            'source' => 'manual',
        ], $overrides));
    }

    private function attachTenant(Lease $lease, string $firstName, string $lastName): void
    {
        $contact = \App\Models\Contact::create([
            'agency_id' => $lease->agency_id, 'branch_id' => $lease->branch_id,
            'first_name' => $firstName, 'last_name' => $lastName,
            'email' => strtolower($firstName) . '-' . uniqid() . '@example.test',
        ]);
        \App\Models\LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);
    }
}
