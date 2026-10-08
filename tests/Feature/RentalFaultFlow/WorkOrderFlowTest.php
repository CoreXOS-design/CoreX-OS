<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalWorkOrderAppointmentMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderServiceType;
use App\Models\DealV2\AgencyServiceType;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\RentalApprovalGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rentals - fault report -> owner decision -> WORK ORDER always -> JOB CARD only for the internal crew (Johan, 8 Oct 2026,
 * W1-W6). The work order is the EXTERNAL record the owner and tenant see (who is doing it, the appointment, progress);
 * the job card is INTERNAL and never reaches them. Mail::fake() intercepts every send.
 */
class WorkOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $landlord;
    private RentalFaultType $faultType;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->agency = Agency::create(['name' => 'WO Flow Agency', 'slug' => 'wo-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $branch->id,
            'title' => 'WO Unit', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'property_id' => $this->property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.com',
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.com',
        ]);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->faultType = RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine',
            'first_aid_steps' => 'Close the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    // ── W1 / W3 / W5: a work order on EVERY route; a job card only for the internal crew ─────────

    public function test_owner_own_contractor_route_creates_a_work_order_and_no_job_card(): void
    {
        $fault = $this->approvedFault('owner_handles', ['contractor_name' => 'Owner Bob', 'contractor_phone' => '0830001111']);

        // W5: "Save decision and create work order" is offered on this route too (it used to be blocked).
        $this->assertNull($fault->fresh()->workOrderBlockReason());

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'assignment_type' => 'owner_contractor', 'title' => 'Fix the tap', 'description' => 'Leaking tap',
            'contractor_name' => 'Owner Bob', 'contractor_phone' => '0830001111',
        ])->assertRedirect();

        $wo = $fault->fresh()->workOrder;
        $this->assertNotNull($wo);
        $this->assertSame(RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR, $wo->assignment_type);
        $this->assertSame('Owner Bob', $wo->contractor_name);
        $this->assertSame('0830001111', $wo->contractor_phone);
        $this->assertNull($wo->agency_service_provider_id);
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->status, "the owner's contractor is already appointed");
        $this->assertSame(0, RentalJobCard::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->count(), 'no job card for an outside contractor');
        $this->assertSame(RentalFaultReport::STATUS_WORK_ORDER_RAISED, $fault->fresh()->status);
        // The agency prices and authorises nothing for the owner's own contractor.
        $this->assertTrue(app(RentalApprovalGateService::class)->authoriseToProceed($wo)->authorised);
    }

    public function test_agency_contractor_route_creates_a_work_order_with_the_supplier_preselected_and_no_job_card(): void
    {
        $plumber = $this->supplier('Acme Plumbing', 'Plumbing');
        $fault = $this->approvedFault('agency_appoints', ['agency_service_provider_id' => $plumber->id]);

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'assignment_type' => 'outside_supplier', 'title' => 'Fix the tap', 'description' => 'Leaking tap', 'agency_service_provider_id' => $plumber->id,
        ])->assertRedirect();

        $wo = $fault->fresh()->workOrder;
        $this->assertSame(RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, $wo->assignment_type);
        $this->assertSame($plumber->id, $wo->agency_service_provider_id);
        $this->assertSame(RentalWorkOrder::STATUS_REPORTED, $wo->status, 'ordering still follows the quote / authorisation steps');
        $this->assertSame(0, RentalJobCard::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->count());
        // ...and the existing money gate still holds this one back (nothing priced / approved yet).
        $this->assertFalse(app(RentalApprovalGateService::class)->authoriseToProceed($wo)->authorised);
        try {
            $wo->startProgress($this->agent);
            $this->fail('an agency contractor job with nothing approved must not start');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Nothing has been priced', $e->getMessage());
        }
    }

    public function test_internal_crew_route_creates_the_work_order_and_its_job_card(): void
    {
        $fault = $this->approvedFault('agency_appoints');

        $res = $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'assignment_type' => 'internal', 'title' => 'Fix the tap', 'description' => 'Leaking tap',
        ]);
        $res->assertRedirect();

        $wo = $fault->fresh()->workOrder;
        $this->assertSame(RentalWorkOrder::ASSIGNMENT_INTERNAL, $wo->assignment_type);
        $card = RentalJobCard::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->first();
        $this->assertNotNull($card, 'the internal crew gets a job card');
        $this->assertSame($wo->id, $card->rental_work_order_id);
    }

    public function test_the_save_decision_button_hands_over_on_every_approved_route(): void
    {
        foreach (['owner_handles', 'agency_appoints'] as $route) {
            $fault = $this->tenantFault("Fault via $route");
            $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.approval.store', $fault), [
                'decision' => 'approved', 'approval_route' => $route, 'evidence_type' => 'verbal_note', 'evidence_text' => 'ok', 'after' => 'create_work_order',
            ])->assertRedirect(route('corex.rental-fault-reports.show', ['rentalFaultReport' => $fault, 'create_work_order' => 1]));
        }

        // The work-order form opens pre-filled with the owner's own contractor when that was the decision.
        $own = $this->approvedFault('owner_handles', ['contractor_name' => 'Owner Bob', 'contractor_phone' => '0830001111']);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', ['rentalFaultReport' => $own, 'create_work_order' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('value="Owner Bob"', $html);
        $this->assertStringContainsString("Owner's contractor", $html);
    }

    // ── W2 / W4: tenant and owner see the WORK ORDER; never the job card ──────────────────────

    public function test_tenant_and_owner_see_the_work_order_and_never_the_job_card(): void
    {
        // The portal page is public (a guest); fetch it before any portal login is simulated.
        $page = $this->get('/portal')->assertOk()->getContent();
        $this->assertStringNotContainsString('/job-cards', $page);

        $fault = $this->approvedFault('agency_appoints');
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'assignment_type' => 'internal', 'title' => 'Fix the tap', 'description' => 'Leaking tap',
        ]);
        $wo = $fault->fresh()->workOrder;
        $card = RentalJobCard::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->firstOrFail();
        $card->forceFill(['worker_signed_off_at' => now(), 'worker_sign_off_name' => 'Crew Chief', 'status' => 'approved'])->saveQuietly();

        // Tenant: the work order, with who / stage / appointment - and no job-card fields or endpoints.
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
        $tenantWo = $this->getJson('/api/v1/client/rentals/work-orders/' . $wo->id)->assertOk()->json('work_order');
        $this->assertSame('Our maintenance team', $tenantWo['who_label']);
        $this->assertArrayNotHasKey('crew_completion', $tenantWo);
        $this->assertArrayNotHasKey('due_at', $tenantWo);
        $this->assertStringNotContainsString('Crew Chief', json_encode($tenantWo));
        $this->getJson('/api/v1/client/rentals/job-cards')->assertNotFound();
        $this->getJson('/api/v1/client/rentals/job-cards/' . $card->id)->assertNotFound();
        $this->getJson('/api/v1/client/rentals/work-orders')->assertOk()->assertJsonCount(1, 'work_orders');

        // Owner: same.
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $ownerList = $this->getJson('/api/v1/client/rentals/landlord/work-orders')->assertOk();
        $this->assertStringNotContainsString('Crew Chief', $ownerList->getContent());
        $this->assertStringNotContainsString('crew_completion', $ownerList->getContent());
        $this->getJson('/api/v1/client/rentals/landlord/job-cards')->assertNotFound();
        $this->getJson('/api/v1/client/rentals/landlord/job-cards/' . $card->id)->assertNotFound();
        $this->getJson('/api/v1/client/rentals/landlord/work-orders/' . $wo->id)->assertOk()->assertJsonPath('work_order.who', 'our_team');
    }

    public function test_the_tenants_fault_carries_its_work_order_with_who_and_the_appointment(): void
    {
        $fault = $this->approvedFault('owner_handles', ['contractor_name' => 'Owner Bob', 'contractor_phone' => '0830001111']);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'assignment_type' => 'owner_contractor', 'title' => 'Fix the tap', 'description' => 'x', 'contractor_name' => 'Owner Bob', 'contractor_phone' => '0830001111',
        ]);
        $wo = $fault->fresh()->workOrder;
        app(\App\Services\Rentals\RentalWorkOrderService::class)->setAppointment($wo, now()->addDays(2)->setTime(9, 0), 'Please be home', $this->agent);

        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
        $rows = $this->getJson('/api/v1/client/rentals/fault-reports')->assertOk()->json('fault_reports');
        $summary = collect($rows)->firstWhere('id', $fault->id)['work_order_stage'];
        $this->assertSame('appointment_set', $summary['stage']);
        $this->assertSame("Owner's contractor", $summary['who_label']);
        $this->assertSame('Owner Bob', $summary['contractor_name']);
        $this->assertNotNull($summary['appointment_at']);

        // The tenant never gets the owner's contractor phone; the owner does.
        $this->assertStringNotContainsString('0830001111', json_encode($this->getJson('/api/v1/client/rentals/work-orders/' . $wo->id)->json()));
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $this->assertSame('0830001111', $this->getJson('/api/v1/client/rentals/landlord/work-orders/' . $wo->id)->json('work_order.contractor_phone'));
    }

    // ── W2: plain stages as DATA ─────────────────────────────────────────────────────────────

    public function test_stage_words_come_from_the_config_data(): void
    {
        $fault = $this->approvedFault('owner_handles');
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'owner_contractor', 'title' => 'T', 'description' => 'D']);
        $wo = $fault->fresh()->workOrder;

        $this->assertSame('created', $wo->stageKey());
        $this->assertSame('Created', $wo->stageLabel('tenant'));
        config(['rental-work-order-stages.created.tenant' => 'Logged with the agency']);
        $this->assertSame('Logged with the agency', $wo->stageLabel('tenant'));

        $wo->forceFill(['appointment_at' => now()->addDay()])->save();
        $this->assertSame('appointment_set', $wo->stageKey());
        $wo->startProgress($this->agent);
        $this->assertSame('in_progress', $wo->fresh()->stageKey());
        $this->assertSame('In progress', $wo->fresh()->stageLabel('owner'));
    }

    // ── W2 / W6: the appointment - agent, owner, job-card booking; tenant told on set AND change ─

    public function test_the_tenant_is_emailed_when_the_agent_sets_and_then_changes_the_appointment(): void
    {
        $wo = $this->ownerContractorWorkOrder();

        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.appointment.store', $wo), [
            'appointment_at' => now()->addDays(3)->format('Y-m-d') . 'T09:00', 'appointment_note' => 'Plumber calls first',
        ])->assertRedirect();

        $wo = $wo->fresh();
        $this->assertNotNull($wo->appointment_at);
        $this->assertSame('Plumber calls first', $wo->appointment_note);
        $this->assertSame($this->agent->id, $wo->appointment_set_by_user_id);
        Mail::assertQueued(RentalWorkOrderAppointmentMail::class, fn ($m) => $m->hasTo($this->tenant->email) && $m->changed === false);

        // Same date and note again: nothing changes, nothing is sent.
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.appointment.store', $wo), [
            'appointment_at' => now()->addDays(3)->format('Y-m-d') . 'T09:00', 'appointment_note' => 'Plumber calls first',
        ])->assertRedirect();
        Mail::assertQueued(RentalWorkOrderAppointmentMail::class, 1);

        // A genuinely new time: changed mail.
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.appointment.store', $wo), [
            'appointment_at' => now()->addDays(4)->format('Y-m-d') . 'T14:30', 'appointment_note' => 'Moved',
        ])->assertRedirect();
        Mail::assertQueued(RentalWorkOrderAppointmentMail::class, fn ($m) => $m->changed === true && $m->note === 'Moved');
        Mail::assertQueued(RentalWorkOrderAppointmentMail::class, 2);

        $actions = $wo->fresh()->history()->pluck('action')->all();
        $this->assertContains('Appointment set', $actions);
        $this->assertContains('Appointment changed', $actions);
    }

    public function test_no_appointment_mail_when_the_agency_has_tenant_notifications_off_or_the_work_order_is_closed(): void
    {
        $wo = $this->ownerContractorWorkOrder();
        RentalPortalSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id], ['notify_tenant_on_status_change' => false]);

        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.appointment.store', $wo), ['appointment_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertRedirect();
        Mail::assertNothingQueued();
        $this->assertNotNull($wo->fresh()->appointment_at, 'the appointment is still saved');

        $wo->forceFill(['status' => RentalWorkOrder::STATUS_CANCELLED])->saveQuietly();
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.appointment.store', $wo), ['appointment_at' => now()->addDays(2)->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('rental_work_order');
    }

    public function test_booking_the_crew_on_the_job_card_sets_the_work_order_appointment_and_tells_the_tenant(): void
    {
        $fault = $this->approvedFault('agency_appoints');
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'internal', 'title' => 'T', 'description' => 'D']);
        $wo = $fault->fresh()->workOrder;
        $card = RentalJobCard::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->firstOrFail();
        $wo->forceFill(['approval_basis' => RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT, 'approved_amount' => 1])->saveQuietly();

        $when = now()->addDays(2)->setTime(10, 0);
        $card->fresh()->schedule($when, null, $this->agent);

        $this->assertTrue($wo->fresh()->appointment_at->equalTo($when));
        Mail::assertQueued(RentalWorkOrderAppointmentMail::class, fn ($m) => $m->hasTo($this->tenant->email));
    }

    public function test_the_owner_can_set_the_appointment_from_the_link_and_the_tenant_is_told(): void
    {
        $wo = $this->ownerContractorWorkOrder();
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);

        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/appointment", [
            'appointment_at' => now()->addDays(2)->format('Y-m-d') . 'T11:00', 'note' => 'Contractor arrives mid-morning',
        ])->assertOk()->assertJsonPath('work_order.stage', 'appointment_set');

        $wo = $wo->fresh();
        $this->assertSame($this->landlord->id, $wo->appointment_set_by_contact_id);
        $this->assertNull($wo->appointment_set_by_user_id);
        Mail::assertQueued(RentalWorkOrderAppointmentMail::class, fn ($m) => $m->hasTo($this->tenant->email));
        $this->assertStringContainsString('(owner, on the portal)', $wo->history()->firstWhere('action', 'Appointment set')['note']);

        // Validation + scoping: another owner / another work order is a 404, a missing date a 422.
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/appointment", [])->assertStatus(422);
        $stranger = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id,
            'first_name' => 'Sam', 'last_name' => 'Stranger', 'email' => 'sam+' . uniqid() . '@example.com',
        ]);
        Sanctum::actingAs($this->clientUserFor($stranger), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/appointment", ['appointment_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertNotFound();
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/progress", ['action' => 'started'])->assertNotFound();
    }

    public function test_the_owner_can_report_the_work_started_and_finished_but_not_for_the_internal_crew(): void
    {
        $wo = $this->ownerContractorWorkOrder();
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);

        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/progress", ['action' => 'started'])->assertOk();
        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $wo->fresh()->status);

        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/progress", ['action' => 'finished', 'note' => 'All fixed'])->assertOk()
            ->assertJsonPath('work_order.stage', 'check_requested');
        $round = RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->firstOrFail();
        $this->assertSame('owner_portal', $round->reported_via);

        // The agency's own team reports through its job card, never through the owner.
        $fault = $this->approvedFault('agency_appoints');
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'internal', 'title' => 'T', 'description' => 'D']);
        $internal = $fault->fresh()->workOrder;
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$internal->id}/progress", ['action' => 'started'])->assertStatus(422);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function tenantFault(string $title = 'Leaking tap in the kitchen'): RentalFaultReport
    {
        return RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->faultType->id, 'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id,
            'reported_channel' => 'app', 'title' => $title, 'description' => 'Dripping',
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    /** A fault the owner has approved, on the given route, as the agent would record it. */
    private function approvedFault(string $route, array $contractor = []): RentalFaultReport
    {
        $fault = $this->tenantFault();
        $fault->saveOwnerVersion(['owner_title' => $fault->title], $this->agent);
        $fault->fresh()->requestApproval($this->agent);
        $fault->fresh()->recordApproval($this->agent, array_merge([
            'decision' => 'approved', 'approval_route' => $route, 'evidence_type' => 'verbal_note', 'evidence_text' => 'Owner agreed.',
        ], $contractor));

        return $fault->fresh();
    }

    private function ownerContractorWorkOrder(): RentalWorkOrder
    {
        $fault = $this->approvedFault('owner_handles', ['contractor_name' => 'Owner Bob', 'contractor_phone' => '0830001111']);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'assignment_type' => 'owner_contractor', 'title' => 'Fix the tap', 'description' => 'Leaking tap', 'contractor_name' => 'Owner Bob', 'contractor_phone' => '0830001111',
        ])->assertRedirect();
        Mail::fake();   // forget the creation mails; each test asserts only what it triggers

        return $fault->fresh()->workOrder;
    }

    private function supplier(string $name, string $tradeLabel): AgencyServiceProvider
    {
        $type = AgencyServiceType::withoutGlobalScopes()->firstOrCreate(
            ['agency_id' => $this->agency->id, 'code' => $tradeLabel],
            ['label' => $tradeLabel, 'sort_order' => 1, 'is_active' => true]
        );
        $provider = AgencyServiceProvider::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => $name, 'phone' => '0315550000', 'is_active' => true, 'specialty' => 'other']);
        AgencyServiceProviderServiceType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'service_provider_id' => $provider->id, 'service_type' => $type->code]);

        return $provider;
    }

    private function clientUserFor(Contact $contact): ClientUser
    {
        $clientUser = ClientUser::firstOrCreate(['email' => $contact->email], ['current_agency_id' => $contact->agency_id]);
        $contact->forceFill(['client_user_id' => $clientUser->id])->saveQuietly();

        return $clientUser;
    }
}
