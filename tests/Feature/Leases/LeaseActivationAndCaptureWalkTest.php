<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Docuperfect\Flow;
use App\Models\Lease;
use App\Models\LeaseEscalation;
use App\Models\LeaseEvent;
use App\Models\LeaseTenant;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\User;
use App\Services\Rentals\LeaseAgreementCheck;
use App\Services\Rentals\LeaseAgreementConfirmService;
use App\Services\Rentals\LeaseRenewalService;
use App\Exceptions\Rentals\LeaseAgreementConfirmationRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rentals front-half walk (QA1, 8 Oct 2026) - lease activation and the capture screen. Each test pins one defect found by
 * walking "approved application -> lease -> signed -> active" and fixed:
 *  - the lease's manual Activate must not put a lease live while its agreement is being prepared / out for signing /
 *    waiting for approval, nor skip the confirm screen for a signed agreement that differs from its lease;
 *  - Activate on a renewal draft goes through the renewal activation (escalation row + "renewal activated" line);
 *  - the signing safety net (no acting user) still records a renewal's escalation;
 *  - the change-confirm service (and so the API twin) never rewrites an ACTIVE lease from its document;
 *  - the capture screen refuses the same tenant twice and an application the user may not see, instead of a 500 / a
 *    cross-branch link;
 *  - a lease agreement is e-signed only: the wizard's wet-ink / download-only modes are refused for a lease-launched flow.
 */
final class LeaseActivationAndCaptureWalkTest extends TestCase
{
    use RefreshDatabase;
    use BuildsLeaseAgreementFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        $this->setUpAgreementFixture();
    }

    private function draftLease(array $overrides = []): Lease
    {
        $lease = Lease::create($overrides + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 8500, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id, 'signing_status' => Lease::SIGNING_NOT_SENT,
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);

        return $lease;
    }

    // ── manual Activate vs the signing state ──────────────────────────────

    public function test_manual_activate_is_refused_while_the_agreement_is_being_prepared_out_or_waiting_for_approval(): void
    {
        foreach ([Lease::SIGNING_PREPARED, Lease::SIGNING_OUT_FOR_SIGNING, Lease::SIGNING_AWAITING_AGENT_REVIEW] as $state) {
            $lease = $this->draftLease(['signing_status' => $state]);

            $this->actingAs($this->agent)->post(route('corex.leases.activate', $lease))->assertSessionHasErrors('lease');

            $this->assertSame(Lease::STATUS_DRAFT, $lease->fresh()->status, "$state: the lease must stay a draft");
            $lease->forceFill(['deleted_at' => now()])->save(); // free the property for the next state
        }
    }

    public function test_the_lease_screen_does_not_offer_activate_while_the_agreement_is_in_flight_but_does_for_a_plain_draft(): void
    {
        $plain = $this->draftLease();
        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $plain))->assertOk()->getContent();
        $this->assertStringContainsString('lease-activate-form', $html);
        $this->assertMatchesRegularExpression('/form="lease-activate-form"/', $html, 'a plain draft keeps its Activate button');

        $plain->forceFill(['signing_status' => Lease::SIGNING_OUT_FOR_SIGNING])->save();
        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $plain))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/form="lease-activate-form"/', $html, 'no Activate button while the agreement is out for signing');
    }

    public function test_a_signed_agreement_that_differs_from_its_lease_sends_the_agent_to_the_confirm_screen_instead_of_activating(): void
    {
        $lease = $this->draftLease(['signing_status' => Lease::SIGNING_SIGNED]);
        LeaseEvent::create([
            'lease_id' => $lease->id, 'event_type' => LeaseEvent::TYPE_AGREEMENT_NEEDS_CONFIRMATION,
            'description' => 'Signed with a difference', 'occurred_at' => now(), 'created_at' => now(),
        ]);

        $this->actingAs($this->agent)->post(route('corex.leases.activate', $lease))
            ->assertRedirect(route('corex.leases.agreement.confirm', $lease));

        $this->assertSame(Lease::STATUS_DRAFT, $lease->fresh()->status);
    }

    // ── renewal bookkeeping ───────────────────────────────────────────────

    public function test_activating_a_renewal_draft_by_hand_records_the_escalation_and_the_renewal_line_and_expires_the_old_term(): void
    {
        $previous = $this->draftLease(['status' => Lease::STATUS_ACTIVE, 'rental_amount' => 8000, 'start_date' => '2025-11-01', 'end_date' => '2026-10-31']);
        $renewal = $this->draftLease(['previous_lease_id' => $previous->id, 'rental_amount' => 8800, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31']);

        $this->actingAs($this->agent)->post(route('corex.leases.activate', $renewal))->assertSessionHas('success');

        $this->assertSame(Lease::STATUS_ACTIVE, $renewal->fresh()->status);
        $this->assertSame(Lease::STATUS_EXPIRED, $previous->fresh()->status);
        $escalation = LeaseEscalation::where('lease_id', $renewal->id)->first();
        $this->assertNotNull($escalation, 'the escalation from the old rent to the new one is recorded');
        $this->assertEquals(8800, $escalation->new_rental_amount);
        $this->assertTrue(LeaseEvent::where('lease_id', $renewal->id)->where('event_type', LeaseEvent::TYPE_RENEWAL_ACTIVATED)->exists());
    }

    public function test_the_signing_safety_net_has_no_acting_user_but_still_records_the_renewal_escalation(): void
    {
        $previous = $this->draftLease(['status' => Lease::STATUS_ACTIVE, 'rental_amount' => 8000]);
        $renewal = $this->draftLease(['previous_lease_id' => $previous->id, 'rental_amount' => 8800]);

        app(LeaseRenewalService::class)->activateRenewalTerm($renewal, null);

        $escalation = LeaseEscalation::where('lease_id', $renewal->id)->first();
        $this->assertNotNull($escalation);
        $this->assertNull($escalation->created_by_user_id);
        $this->assertTrue(LeaseEvent::where('lease_id', $renewal->id)->where('event_type', LeaseEvent::TYPE_RENEWAL_ACTIVATED)->whereNull('actor_user_id')->exists());
    }

    // ── the change-confirm service ────────────────────────────────────────

    public function test_confirming_never_rewrites_an_active_lease_from_its_document(): void
    {
        [$lease] = $this->agreementOut(['status' => Lease::STATUS_ACTIVE, 'signing_status' => Lease::SIGNING_SIGNED], \App\Models\Docuperfect\SignatureTemplate::STATUS_COMPLETED);
        $this->print($lease->agreementDocument, ['rent_f' => '1.00']);

        // The REAL fingerprint, so the only thing that can refuse this is the lease's own state.
        $fingerprint = app(LeaseAgreementCheck::class)->verdict($lease->fresh())['fingerprint'];

        $service = app(LeaseAgreementConfirmService::class);
        $this->expectException(LeaseAgreementConfirmationRefused::class);
        try {
            $service->confirm($lease->fresh(), $this->agent, (string) $fingerprint);
        } finally {
            $this->assertEquals(8500, $lease->fresh()->rental_amount, 'the live lease keeps its rent');
        }
    }

    public function test_the_api_confirm_refuses_an_active_lease(): void
    {
        [$lease] = $this->agreementOut(['status' => Lease::STATUS_ACTIVE, 'signing_status' => Lease::SIGNING_SIGNED], \App\Models\Docuperfect\SignatureTemplate::STATUS_COMPLETED);
        $this->print($lease->agreementDocument, ['rent_f' => '1.00']);

        $fingerprint = app(LeaseAgreementCheck::class)->verdict($lease->fresh())['fingerprint'];

        $this->actingAs($this->agent, 'sanctum')->postJson(route('v1.leases.signing.confirm', $lease), ['fingerprint' => (string) $fingerprint])
            ->assertStatus(422)->assertJson(['code' => LeaseAgreementConfirmationRefused::NOT_APPLICABLE]);

        $this->assertEquals(8500, $lease->fresh()->rental_amount);
    }

    // ── the capture screen's validation ───────────────────────────────────

    private function capturePayload(array $overrides = []): array
    {
        return $overrides + [
            'intent' => 'lease_only', 'property_id' => $this->property->id, 'tenant_contact_ids' => [$this->tenant->id],
            'start_date' => '2026-12-01', 'end_date' => '2027-11-30', 'rental_amount' => '9000', 'capture_key' => bin2hex(random_bytes(8)),
        ];
    }

    public function test_the_same_tenant_twice_is_a_validation_error_not_a_500(): void
    {
        $res = $this->actingAs($this->agent)->post(route('corex.leases.store'), $this->capturePayload(['tenant_contact_ids' => [$this->tenant->id, $this->tenant->id]]));

        $res->assertSessionHasErrors();
        $this->assertSame(0, Lease::where('property_id', $this->property->id)->count());
    }

    public function test_an_application_the_user_may_not_see_cannot_be_linked_to_a_new_lease(): void
    {
        $otherBranch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Durban']);
        $stranger = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $otherBranch->id, 'first_name' => 'Zola', 'last_name' => 'Dube', 'email' => 'zola@example.test']);
        $foreignApp = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $otherBranch->id, 'contact_id' => $stranger->id,
            'created_by_user_id' => User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $otherBranch->id, 'role' => 'agent'])->id,
            'status' => 'approved', 'full_name' => 'Zola Dube',
        ]);
        $agentInOwnBranch = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        $this->property->update(['agent_id' => $agentInOwnBranch->id]);

        $res = $this->actingAs($agentInOwnBranch)->post(route('corex.leases.store'), $this->capturePayload(['rental_application_id' => $foreignApp->id]));

        $res->assertSessionHasErrors('rental_application_id');
        $this->assertNull($foreignApp->fresh()->property_id, "the other branch's application was not re-pointed at this property");
        $this->assertSame(0, Lease::where('property_id', $this->property->id)->count());
    }

    // ── a lease agreement is e-signed only ────────────────────────────────

    public function test_a_lease_launched_flow_cannot_go_down_the_wet_ink_or_download_only_route(): void
    {
        $lease = $this->draftLease(['signing_status' => Lease::SIGNING_PREPARED]);
        $template = \App\Models\Docuperfect\Template::query()->create(['name' => 'Cape residential lease', 'render_type' => 'pdf', 'is_esign' => true, 'agency_id' => $this->agency->id]);
        $flow = Flow::create(['user_id' => $this->agent->id, 'agency_id' => $this->agency->id, 'type' => 'esign', 'template_id' => $template->id, 'current_step' => 6, 'step_data' => [], 'lease_id' => $lease->id]);

        $this->actingAs($this->agent)->post(route('docuperfect.esign.prepareWetInk', $flow->id))
            ->assertRedirect(route('docuperfect.esign.step', [$flow->id, 6]))->assertSessionHas('error');
        $this->actingAs($this->agent)->post(route('docuperfect.esign.prepareDownload', $flow->id))
            ->assertRedirect(route('docuperfect.esign.step', [$flow->id, 6]))->assertSessionHas('error');

        $this->assertNull($lease->fresh()->signature_template_id);
        $this->assertSame(0, \App\Models\Docuperfect\Document::where('agency_id', $this->agency->id)->count(), 'no document was created down the paper route');
    }
}
