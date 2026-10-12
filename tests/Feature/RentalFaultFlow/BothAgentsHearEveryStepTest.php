<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Events\Rentals\RentalCrewLinesSubmitted;
use App\Listeners\Rentals\NotifyAgentOfCrewLines;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalApproval;
use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\RentalCompletionService;
use App\Services\Rentals\RentalFaultReportService;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * AT-451 round 2 (Johan, 12 Oct 2026): "the same people who are told about a new fault are told what happens next."
 *
 * Every alert in the fault -> owner decision -> work order -> quote -> appointment -> completion chain goes to
 * LeaseAgentService::recipientsForEvent(): BOTH lease agents (the owner's and the tenant's), else the property's agent, else
 * the branch manager / office admin / agency admin — and never the staff member who performed the action.
 *
 * Fixture: lease 1 has an owner's agent AND a different tenant's agent AND a third person as the property's agent. The property's
 * agent is deliberately NOT a lease agent: with lease agents named they are the people told (the "new fault" rule).
 */
final class BothAgentsHearEveryStepTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $ownerAgent;
    private User $tenantAgent;
    private User $propertyAgent;
    private Property $property;
    private Lease $lease;
    private Contact $landlord;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->agency = Agency::create(['name' => 'Both Agents Agency', 'slug' => 'ba-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'B-' . $this->agency->id, 'is_active' => true]);
        $mk = fn (string $role = 'agent') => User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true]);
        $this->ownerAgent = $mk();
        $this->tenantAgent = $mk();
        $this->propertyAgent = $mk();
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->propertyAgent->id, 'branch_id' => $this->branch->id,
            'title' => 'Unit 33', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000, 'start_date' => now()->subMonth(),
            'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
            'owner_agent_user_id' => $this->ownerAgent->id, 'tenant_agent_user_id' => $this->tenantAgent->id,
        ]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.com',
        ]);
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────

    private function fault(): RentalFaultReport
    {
        return app(RentalFaultReportService::class)->report($this->property, [
            'lease_id' => $this->lease->id, 'reported_by_type' => 'tenant', 'reported_channel' => 'app',
            'title' => 'Roof leak', 'description' => 'Water in the passage',
        ]);
    }

    private function workOrder(): RentalWorkOrder
    {
        return RentalWorkOrder::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, 'title' => 'Fix the roof', 'description' => 'x',
            'status' => RentalWorkOrder::STATUS_REPORTED, 'owner_approval_status' => RentalWorkOrder::APPROVAL_PENDING,
            'reported_by_type' => 'agent_noticed', 'reported_at' => now(), 'created_by_user_id' => $this->propertyAgent->id,
        ]);
    }

    /** Who got an in-app alert for $eventKey, as sorted user ids. */
    private function told(string $eventKey): array
    {
        return \Illuminate\Support\Facades\DB::table('notifications')->get()
            ->filter(fn ($n) => (json_decode($n->data, true)['event_key'] ?? null) === $eventKey)
            ->pluck('notifiable_id')->map(fn ($i) => (int) $i)->sort()->values()->all();
    }

    private function both(): array
    {
        $ids = [$this->ownerAgent->id, $this->tenantAgent->id];
        sort($ids);

        return $ids;
    }

    // ── the chain ──────────────────────────────────────────────────────────────────────────

    public function test_a_new_fault_tells_both_lease_agents_and_not_the_property_agent(): void
    {
        $this->fault();

        $this->assertSame($this->both(), $this->told('rental_fault_report.created'));
    }

    public function test_the_owners_decision_from_the_portal_tells_both_lease_agents(): void
    {
        $fault = $this->fault();

        $fault->recordApproval($this->landlord, [
            'decision' => RentalFaultReport::APPROVAL_APPROVED, 'approval_route' => RentalFaultReport::ROUTE_AGENCY_APPOINTS,
            'evidence_type' => RentalApproval::EVIDENCE_PORTAL,
        ]);

        $this->assertSame($this->both(), $this->told('rental_fault_report.owner_decided'));
    }

    public function test_a_decline_tells_both_too(): void
    {
        $fault = $this->fault();

        $fault->recordApproval($this->landlord, [
            'decision' => RentalFaultReport::APPROVAL_DECLINED, 'evidence_type' => RentalApproval::EVIDENCE_PORTAL, 'evidence_text' => 'Too dear',
        ]);

        $this->assertSame($this->both(), $this->told('rental_fault_report.owner_decided'));
    }

    public function test_an_agent_recording_the_owners_decision_is_not_alerted_about_their_own_click(): void
    {
        $fault = $this->fault();

        $fault->recordApproval($this->tenantAgent, [
            'decision' => RentalFaultReport::APPROVAL_APPROVED, 'approval_route' => RentalFaultReport::ROUTE_AGENCY_APPOINTS,
            'evidence_type' => RentalApproval::EVIDENCE_VERBAL_NOTE, 'evidence_text' => 'Owner said yes on the phone',
        ]);

        $this->assertSame([$this->ownerAgent->id], $this->told('rental_fault_report.owner_decided'), 'the other lease agent hears; the recorder does not');
    }

    public function test_the_fault_outcome_tells_both_except_the_agent_who_set_it(): void
    {
        $fault = $this->fault();

        app(RentalFaultReportService::class)->notifyResolved($fault->fresh()->forceFill(['outcome' => 'repaired']), $this->tenantAgent);

        $this->assertSame([$this->ownerAgent->id], $this->told('rental_fault_report.resolved'));
    }

    public function test_work_order_created_completed_and_overdue_tell_both(): void
    {
        $wo = $this->workOrder();
        $service = app(RentalWorkOrderService::class);

        $service->notifyCreated($wo);
        $service->notifyCompleted($wo);
        $service->notifyOverdue($wo);

        foreach (['rental_work_order.created', 'rental_work_order.completed', 'rental_work_order.overdue'] as $key) {
            $this->assertSame($this->both(), $this->told($key), $key);
        }
    }

    public function test_what_the_owner_does_on_a_work_order_tells_both(): void
    {
        $wo = $this->workOrder();
        $service = app(RentalWorkOrderService::class);

        // quote decision, appointment and progress all go through the one helper the portal controllers call
        foreach (['rental_work_order.owner_decided', 'rental_work_order.owner_appointment', 'rental_work_order.owner_progress'] as $key) {
            $service->notifyAgentOfOwnerAction($wo, $key, 'Owner did something', 'b', now());
            $this->assertSame($this->both(), $this->told($key), $key);
        }
    }

    public function test_the_staff_member_who_creates_a_work_order_is_not_told_about_their_own_action(): void
    {
        $wo = $this->workOrder();
        $wo->forceFill(['created_by_user_id' => $this->tenantAgent->id])->saveQuietly();

        app(RentalWorkOrderService::class)->notifyCreated($wo->fresh());

        $this->assertSame([$this->ownerAgent->id], $this->told('rental_work_order.created'));
    }

    public function test_a_staff_member_logged_in_in_the_same_browser_is_still_told_what_the_owner_does_on_the_portal(): void
    {
        // The portal's API calls carry the browser's session cookie too: a staff member testing the owner portal must not be
        // mistaken for "the person who did it" - the actor is only ever passed by a staff action, never guessed from the login.
        $wo = $this->workOrder();
        $this->actingAs($this->tenantAgent);

        app(RentalWorkOrderService::class)->notifyAgentOfOwnerAction($wo, 'rental_work_order.owner_appointment', 'Owner booked', 'b', now());

        $this->assertSame($this->both(), $this->told('rental_work_order.owner_appointment'));
    }

    public function test_the_tenants_completion_answer_tells_both_and_a_dispute_adds_the_branch_manager(): void
    {
        $manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true]);
        $wo = $this->workOrder();
        $notify = new \ReflectionMethod(RentalCompletionService::class, 'notifyStaff');
        $notify->setAccessible(true);
        $service = app(RentalCompletionService::class);

        $notify->invoke($service, $wo, 'rental_work_order.completion_confirmed', 'Tenant confirmed', 'b');
        $notify->invoke($service, $wo, 'rental_work_order.disputed', 'Tenant says NOT complete', 'b', true);

        $this->assertSame($this->both(), $this->told('rental_work_order.completion_confirmed'));
        $disputed = collect($this->both())->push($manager->id)->sort()->values()->all();
        $this->assertSame($disputed, $this->told('rental_work_order.disputed'), 'both lease agents AND the branch manager');
    }

    public function test_the_crews_lines_tell_both(): void
    {
        $wo = $this->workOrder();
        $card = new RentalJobCard();
        $card->forceFill([
            'id' => 4242, 'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id, 'title' => 'Roof job',
        ]);
        $card->exists = true;

        app(NotifyAgentOfCrewLines::class)->handle(new RentalCrewLinesSubmitted($card, 3, 'link', null));

        $this->assertSame($this->both(), $this->told('rental_job_card.crew_lines_submitted'));
    }

    public function test_with_no_lease_agents_the_property_agent_is_told_and_with_no_one_the_branch_manager(): void
    {
        $this->lease->forceFill(['owner_agent_user_id' => null, 'tenant_agent_user_id' => null])->save();
        $this->fault();
        $this->assertSame([$this->propertyAgent->id], $this->told('rental_fault_report.created'));

        // the property's agent has left (inactive users are skipped) -> the branch manager is the last resort
        $gone = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => false]);
        $this->property->forceFill(['agent_id' => $gone->id])->save();
        $manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true]);
        $wo = $this->workOrder();
        app(RentalWorkOrderService::class)->notifyCreated($wo);
        $this->assertSame([$manager->id], $this->told('rental_work_order.created'));
    }

    public function test_one_person_who_is_both_lease_agents_is_told_once(): void
    {
        $this->lease->forceFill(['owner_agent_user_id' => $this->ownerAgent->id, 'tenant_agent_user_id' => $this->ownerAgent->id])->save();
        $this->fault();

        $this->assertSame([$this->ownerAgent->id], $this->told('rental_fault_report.created'));
    }
}
