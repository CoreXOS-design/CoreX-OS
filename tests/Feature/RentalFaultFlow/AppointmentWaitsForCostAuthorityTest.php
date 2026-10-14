<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Models\RentalAuthorisation;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalSecureAccessTokenService;
use App\Services\Rentals\RentalWorkOrderClientViewService;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * cc5's second walk (WO 182, 14 Oct 2026) + spec 22.17 / 22.18 E: a job that has only been SENT could be booked - and the tenant and contractor mailed - before the
 * owner had authorised the cost. An appointment now waits for the owner's go-ahead (a cost authority, or an authorised look-and-quote visit); the owner's own contractor
 * is exempt. And the contractor's private link always shows an appointment that exists, with the agent's private note.
 */
final class AppointmentWaitsForCostAuthorityTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('ApptWait');
        $this->ownerLimits(500.0);
    }

    /** A job that has been SENT to the contractor (ordered) with a quote of R850 - above the owner's R500 limit - and NO authorisation of any kind. */
    private function sentButUnauthorised(): RentalWorkOrder
    {
        $supplier = $this->supplier('Acme Plumbing', 'acme@example.invalid');
        $wo = $this->externalWorkOrder(['agency_service_provider_id' => $supplier->id, 'status' => RentalWorkOrder::STATUS_ORDERED, 'ordered_at' => now(), 'contractor_response' => 'accepted']);
        $quote = $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 850, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->admin);
        $wo->selectQuote($quote, $this->admin);

        return $wo->fresh();
    }

    public function test_a_sent_job_cannot_be_booked_until_the_owner_has_authorised_the_cost(): void
    {
        $wo = $this->sentButUnauthorised();
        $view = app(RentalWorkOrderClientViewService::class);
        $this->assertStringContainsString('has not authorised the cost', (string) $view->appointmentBlock($wo));

        // the service refuses it (so no mail goes to anyone)
        \Illuminate\Support\Facades\Mail::fake();
        try {
            app(RentalWorkOrderService::class)->setAppointment($wo, now()->addDays(2)->setTime(9, 0), null, $this->admin, null, 'Phone the tenant first');
            $this->fail('the appointment was booked before the cost was authorised');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('An appointment can only be set once the job has been approved', $e->getMessage());
            $this->assertStringContainsString('has not authorised the cost', $e->getMessage());
        }
        $this->assertNull($wo->fresh()->appointment_at);
        \Illuminate\Support\Facades\Mail::assertNothingQueued();

        // the staff form says why instead of offering it
        $page = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('data-appointment-locked', $page);
        $this->assertStringNotContainsString('name="appointment_contractor_note"', $page);
        // and a direct post is refused with the same words
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.appointment.store', $wo), ['appointment_at' => now()->addDays(2)->format('Y-m-d') . 'T09:00'])->assertSessionHasErrors();
        $this->assertNull($wo->fresh()->appointment_at);

        // the owner's go-ahead: now it can be booked
        $wo->fresh()->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => 'portal', 'evidence_text' => 'ok', 'approved_up_to' => 850.0]);
        $this->assertNull($view->appointmentBlock($wo->fresh()));
        $this->assertTrue(app(RentalWorkOrderService::class)->setAppointment($wo->fresh(), now()->addDays(2)->setTime(9, 0), null, $this->admin));
    }

    public function test_an_authorised_look_and_quote_visit_may_be_booked_without_a_cost_authority(): void
    {
        $wo = $this->sentButUnauthorised();
        RentalAuthorisation::withoutGlobalScopes()->forceCreate([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'point' => RentalAuthorisation::POINT_INSPECT, 'outcome' => RentalAuthorisation::OUTCOME_AUTHORISED,
            'basis' => 'agent_no_callout_fee', 'told_at' => now(), 'by_user_id' => $this->admin->id, 'created_at' => now(),
        ]);
        $this->assertNull(app(RentalWorkOrderClientViewService::class)->appointmentBlock($wo->fresh()), 'the visit to look and quote was authorised');
    }

    public function test_an_appointment_booked_before_the_rule_is_hidden_from_tenant_and_owner_until_the_cost_is_authorised_but_the_office_and_contractor_see_it(): void
    {
        $wo = $this->sentButUnauthorised();
        $wo->forceFill(['appointment_at' => now()->addDays(2)->setTime(9, 0), 'appointment_note' => 'Gate is open', 'appointment_contractor_note' => 'Phone the tenant first'])->save();
        $view = app(RentalWorkOrderClientViewService::class);

        $this->assertNull($view->payload($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_TENANT)['appointment_at']);
        $owner = $view->payload($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_LANDLORD);
        $this->assertNull($owner['appointment_at']);
        $this->assertFalse($owner['owner_can_appoint']);
        $this->assertNotNull($view->appointmentAt($wo->fresh(), true), 'the office sees what is stored');

        // the contractor's private link always shows an appointment that exists - the mail says the link "shows the details" - with the agent's private note
        $raw = app(RentalSecureAccessTokenService::class)->issueFor($wo->fresh(), $this->admin)['raw_token'];
        $html = $this->get(route('rentals.secure-link.show', $raw))->assertOk()->getContent();
        $this->assertStringContainsString('data-contractor-appointment', $html);
        $this->assertStringContainsString('Gate is open', $html);
        $this->assertStringContainsString('Note from the agent:</strong> Phone the tenant first', $html);

        // once the owner authorises, the tenant and owner see it
        $wo->fresh()->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => 'portal', 'evidence_text' => 'ok', 'approved_up_to' => 850.0]);
        $this->assertNotNull($view->payload($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_TENANT)['appointment_at']);
        $html = $this->get(route('rentals.secure-link.show', $raw))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-contractor-appointment'), 'one appointment card, never two');
        $this->assertStringContainsString('Note from the agent:</strong> Phone the tenant first', $html);
    }

    public function test_the_owners_own_contractor_is_exempt(): void
    {
        $wo = $this->externalWorkOrder(['status' => RentalWorkOrder::STATUS_ORDERED, 'ordered_at' => now(), 'assignment_type' => RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR, 'contractor_name' => 'Owner Bob']);
        $this->assertNull(app(RentalWorkOrderClientViewService::class)->appointmentBlock($wo->fresh()));
    }
}
