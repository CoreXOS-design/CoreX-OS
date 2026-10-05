<?php

namespace Tests\Feature\CommandCenter;

use App\Console\Commands\CommandCenter\ReconcileCalendarEvents;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\Docuperfect\LeaseRecord;
use App\Models\Lease;
use App\Models\LeaseEscalation;
use App\Models\Property;
use App\Models\Rental;
use App\Models\User;
use App\Services\CommandCenter\Calendar\Sources\RentalCalendarSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-439 — Johan's ruling, 2026-10-05: repoint the Command Centre calendar's
 * lease_expiry/rent_due/rent_escalation sources off the legacy lease_records/
 * rentals/rental_amount_versions tables onto the single `leases` store —
 * repoint, do not delete.
 */
class RentalCalendarSourceLeasesRepointTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgencyBranchUser(): array
    {
        $agency = Agency::create(['name' => 'Test Agency ' . uniqid(), 'slug' => 'test-agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Default']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        return [$agency, $branch, $user];
    }

    private function makeProperty(Agency $agency, Branch $branch, User $agent): Property
    {
        return Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Rental property ' . uniqid(), 'status' => 'active', 'listing_type' => 'rental',
            'address' => '1 Test Street',
        ]);
    }

    private function makeLease(Agency $agency, Branch $branch, Property $property, User $agent, array $overrides = []): Lease
    {
        return Lease::create(array_merge([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 8000,
            'start_date' => now()->subMonths(6), 'end_date' => now()->addDays(10),
            'created_by_user_id' => $agent->id,
        ], $overrides));
    }

    public function test_active_lease_with_end_date_in_window_produces_lease_expiry_event(): void
    {
        [$agency, $branch, $user] = $this->makeAgencyBranchUser();
        $property = $this->makeProperty($agency, $branch, $user);
        $lease = $this->makeLease($agency, $branch, $property, $user, ['end_date' => now()->addDays(10)]);

        $events = app(RentalCalendarSource::class)->syncAll();
        $expiry = $events->firstWhere('category', 'lease_expiry');

        $this->assertNotNull($expiry, 'An active lease with an end_date must produce a lease_expiry event.');
        $this->assertSame(Lease::class, $expiry['source_type']);
        $this->assertSame($lease->id, $expiry['source_id']);
        $this->assertSame($agency->id, $expiry['agency_id']);
        $this->assertSame($branch->id, $expiry['branch_id']);
        $this->assertSame($property->id, $expiry['property_id']);
        $this->assertStringContainsString('1 Test Street', $expiry['title']);
    }

    public function test_draft_lease_produces_no_lease_expiry_event(): void
    {
        [$agency, $branch, $user] = $this->makeAgencyBranchUser();
        $property = $this->makeProperty($agency, $branch, $user);
        $this->makeLease($agency, $branch, $property, $user, ['status' => Lease::STATUS_DRAFT, 'end_date' => now()->addDays(10)]);

        $events = app(RentalCalendarSource::class)->syncAll();

        $this->assertNull($events->firstWhere('category', 'lease_expiry'), 'A draft lease has nothing upcoming to flag yet.');
    }

    public function test_active_lease_with_future_end_date_produces_rent_due_event(): void
    {
        [$agency, $branch, $user] = $this->makeAgencyBranchUser();
        $property = $this->makeProperty($agency, $branch, $user);
        $lease = $this->makeLease($agency, $branch, $property, $user, ['end_date' => now()->addMonths(3)]);

        $events = app(RentalCalendarSource::class)->syncAll();
        $rentDue = $events->firstWhere('category', 'rent_due');

        $this->assertNotNull($rentDue);
        $this->assertSame(Lease::class, $rentDue['source_type']);
        $this->assertSame($lease->id, $rentDue['source_id']);
        $this->assertSame($agency->id, $rentDue['agency_id']);
    }

    public function test_upcoming_escalation_produces_rent_escalation_event(): void
    {
        [$agency, $branch, $user] = $this->makeAgencyBranchUser();
        $property = $this->makeProperty($agency, $branch, $user);
        $lease = $this->makeLease($agency, $branch, $property, $user, ['end_date' => now()->addMonths(6)]);
        LeaseEscalation::create([
            'lease_id' => $lease->id,
            'effective_date' => now()->addDays(15),
            'previous_rental_amount' => 8000,
            'new_rental_amount' => 8500,
            'escalation_rate_percent' => 6.25,
            'created_by_user_id' => $user->id,
            'created_at' => now(),
        ]);

        $events = app(RentalCalendarSource::class)->syncAll();
        $escalation = $events->firstWhere('category', 'rent_escalation');

        $this->assertNotNull($escalation);
        $this->assertSame(\App\Models\LeaseEscalation::class, $escalation['source_type']);
        $this->assertSame($agency->id, $escalation['agency_id']);
        $this->assertSame($branch->id, $escalation['branch_id']);
        $this->assertSame(8500.0, (float) $escalation['metadata']['new_rental_amount']);
    }

    /** The legacy LeaseRecord/Rental-sourced rows are soft-deleted once, so the board self-heals after this repoint deploys. */
    public function test_reconcile_command_soft_deletes_stale_legacy_sourced_calendar_events(): void
    {
        [$agency, $branch, $user] = $this->makeAgencyBranchUser();

        $staleLeaseRecordEvent = CalendarEvent::create([
            'event_type' => 'lease', 'category' => 'lease_expiry', 'title' => 'Stale',
            'event_date' => now()->addDays(5), 'status' => 'pending',
            'source_type' => LeaseRecord::class, 'source_id' => 999,
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'user_id' => $user->id,
        ]);
        $staleRentalEvent = CalendarEvent::create([
            'event_type' => 'lease', 'category' => 'rent_due', 'title' => 'Stale rent due',
            'event_date' => now()->addDays(5), 'status' => 'pending',
            'source_type' => Rental::class, 'source_id' => 888,
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'user_id' => $user->id,
        ]);
        $unrelatedEvent = CalendarEvent::create([
            'event_type' => 'property', 'category' => 'mandate_expiry', 'title' => 'Keep me',
            'event_date' => now()->addDays(5), 'status' => 'pending',
            'source_type' => Property::class, 'source_id' => 777,
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'user_id' => $user->id,
        ]);

        $this->artisan(ReconcileCalendarEvents::class)->assertExitCode(0);

        $this->assertSoftDeleted($staleLeaseRecordEvent);
        $this->assertSoftDeleted($staleRentalEvent);
        $this->assertNotSoftDeleted($unrelatedEvent);
    }
}
