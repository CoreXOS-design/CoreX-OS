<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\User;
use App\Services\Rentals\LeaseRenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * "Cancel renewal draft" — a DRAFT lease chained via previous_lease_id gets
 * an explicit cancel path distinct from an ordinary lease cancellation:
 * requires a reason, soft-cancels (never deletes), logs who/when/why on
 * BOTH leases, drops the property out of the Command Centre's "Renewals in
 * progress" tile, and tells rentals:prepare-renewal-drafts never to
 * silently re-create it.
 */
final class LeaseRenewalDraftCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_renewal_draft_soft_cancels_and_logs_who_when_why_on_itself(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $current = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = $this->makeUser($agency, $branch);
        $draft = app(LeaseRenewalService::class)->createRenewalTerm($current, [
            'start_date' => now()->addYear()->toDateString(),
            'rental_amount' => 9900,
        ], $user);

        $cancelled = app(LeaseRenewalService::class)->cancelRenewalDraft($draft, 'Owner wants to sell instead', $user);

        self::assertSame(Lease::STATUS_CANCELLED, $cancelled->status);
        self::assertNotNull($cancelled->cancelled_at);
        self::assertSame($user->id, $cancelled->cancelled_by_user_id);
        self::assertSame('Owner wants to sell instead', $cancelled->cancel_reason);
        // Never a hard delete — the row stays, just cancelled.
        self::assertDatabaseHas('leases', ['id' => $draft->id, 'status' => Lease::STATUS_CANCELLED]);
        self::assertNotNull(Lease::find($draft->id));
    }

    public function test_cancel_renewal_draft_logs_an_event_on_both_leases(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $current = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = $this->makeUser($agency, $branch);
        $draft = app(LeaseRenewalService::class)->createRenewalTerm($current, [
            'start_date' => now()->addYear()->toDateString(),
            'rental_amount' => 9900,
        ], $user);

        app(LeaseRenewalService::class)->cancelRenewalDraft($draft, 'Tenant withdrew', $user);

        self::assertTrue(
            LeaseEvent::where('lease_id', $draft->id)->where('event_type', LeaseEvent::TYPE_RENEWAL_DRAFT_CANCELLED)->exists()
        );
        self::assertTrue(
            LeaseEvent::where('lease_id', $current->id)->where('event_type', LeaseEvent::TYPE_RENEWAL_DRAFT_CANCELLED)->exists()
        );
    }

    public function test_cancel_renewal_draft_rejects_a_lease_that_is_not_a_pending_renewal_draft(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $plainDraft = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_DRAFT]));
        $user = $this->makeUser($agency, $branch);

        $this->expectException(ValidationException::class);

        app(LeaseRenewalService::class)->cancelRenewalDraft($plainDraft, 'Not a renewal', $user);
    }

    public function test_cancel_renewal_draft_http_requires_a_reason(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $current = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = $this->makeUser($agency, $branch);
        $draft = app(LeaseRenewalService::class)->createRenewalTerm($current, [
            'start_date' => now()->addYear()->toDateString(),
            'rental_amount' => 9900,
        ], $user);

        $response = $this->actingAs($user)->post(route('corex.leases.renewal.cancel-draft', $draft), []);

        $response->assertSessionHasErrors('cancel_reason');
        self::assertSame(Lease::STATUS_DRAFT, $draft->fresh()->status);
    }

    public function test_cancel_renewal_draft_http_succeeds_with_a_reason(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $current = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = $this->makeUser($agency, $branch);
        $draft = app(LeaseRenewalService::class)->createRenewalTerm($current, [
            'start_date' => now()->addYear()->toDateString(),
            'rental_amount' => 9900,
        ], $user);

        $response = $this->actingAs($user)->post(route('corex.leases.renewal.cancel-draft', $draft), [
            'cancel_reason' => 'Agent and tenant agreed to walk away',
        ]);

        $response->assertRedirect(route('corex.leases.show', $draft));
        self::assertSame(Lease::STATUS_CANCELLED, $draft->fresh()->status);
        self::assertFalse($current->fresh()->hasPendingRenewalDraft());
    }

    public function test_cancel_draft_http_blocks_cross_agency_access(): void
    {
        [$agencyA, $branchA, $propertyA] = $this->makeAgencyBranchProperty();
        [$agencyB, $branchB] = $this->makeAgencyBranchProperty();

        $current = Lease::create($this->baseLeaseAttributes($agencyA, $branchA, $propertyA, ['status' => Lease::STATUS_ACTIVE]));
        $userA = $this->makeUser($agencyA, $branchA);
        $draft = app(LeaseRenewalService::class)->createRenewalTerm($current, [
            'start_date' => now()->addYear()->toDateString(),
            'rental_amount' => 9900,
        ], $userA);
        $userB = $this->makeUser($agencyB, $branchB);

        $this->actingAs($userB)->post(route('corex.leases.renewal.cancel-draft', $draft), [
            'cancel_reason' => 'Should never reach here',
        ])->assertNotFound();

        self::assertSame(Lease::STATUS_DRAFT, $draft->fresh()->status);
    }

    /**
     * rental-renewals.md — rentals:prepare-renewal-drafts must never
     * silently re-create a draft the agent explicitly cancelled. Only the
     * manual "Renew lease" path (createRenewalTerm(), unaffected by this
     * check) starts another one.
     */
    public function test_command_does_not_recreate_a_cancelled_renewal_draft(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $agent = $this->makeUser($agency, $branch);
        $current = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE, 'source' => 'manual',
            'end_date' => now()->addDays(10)->toDateString(), 'created_by_user_id' => $agent->id,
        ]));
        $tenant = $this->makeContact($agency, $branch, 'Tenant', 'Renewing');
        LeaseTenant::create(['lease_id' => $current->id, 'contact_id' => $tenant->id, 'is_primary' => true]);

        $draft = app(LeaseRenewalService::class)->createRenewalTerm($current, [
            'start_date' => now()->addDays(11)->toDateString(),
            'rental_amount' => 9900,
        ], $agent);
        app(LeaseRenewalService::class)->cancelRenewalDraft($draft, 'Owner selling', $agent);

        self::assertTrue($current->fresh()->hasCancelledRenewalDraft());

        Artisan::call('rentals:prepare-renewal-drafts');

        self::assertSame(1, Lease::where('previous_lease_id', $current->id)->count());
        self::assertFalse($current->fresh()->hasPendingRenewalDraft());
        self::assertStringContainsString('cancelled draft on file: 1', Artisan::output());
    }

    /**
     * The manual "Renew lease" path is NOT blocked by an earlier
     * cancellation — only the automated command skips.
     */
    public function test_manual_renew_lease_still_works_after_a_cancelled_draft(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $current = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = $this->makeUser($agency, $branch);
        $firstDraft = app(LeaseRenewalService::class)->createRenewalTerm($current, [
            'start_date' => now()->addYear()->toDateString(),
            'rental_amount' => 9900,
        ], $user);
        app(LeaseRenewalService::class)->cancelRenewalDraft($firstDraft, 'Terms not agreed yet', $user);

        $secondDraft = app(LeaseRenewalService::class)->createRenewalTerm($current, [
            'start_date' => now()->addYear()->toDateString(),
            'rental_amount' => 9700,
        ], $user);

        self::assertSame(Lease::STATUS_DRAFT, $secondDraft->fresh()->status);
        self::assertTrue($current->fresh()->hasPendingRenewalDraft());
    }

    /** @return array{0: Agency, 1: Branch, 2: Property} */
    private function makeAgencyBranchProperty(): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Test property ' . uniqid(),
            'status' => 'active', 'listing_type' => 'rental',
        ]);

        return [$agency, $branch, $property];
    }

    private function baseLeaseAttributes(Agency $agency, Branch $branch, Property $property, array $overrides = []): array
    {
        return array_merge([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'property_id' => $property->id,
            'status' => Lease::STATUS_DRAFT,
            'rental_amount' => 9000,
            'start_date' => now()->toDateString(),
            'source' => 'manual',
        ], $overrides);
    }

    private function makeContact(Agency $agency, Branch $branch, string $first, string $last): Contact
    {
        return Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($first . '.' . $last) . '-' . uniqid() . '@example.test',
        ]);
    }

    private function makeUser(Agency $agency, Branch $branch): User
    {
        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
    }
}
