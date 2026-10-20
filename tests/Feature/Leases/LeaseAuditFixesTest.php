<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Contact;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\Template;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseSetting;
use App\Models\LeaseTenant;
use App\Models\RentalApplication;
use App\Models\RentalInspection;
use App\Models\RentalLeaseTemplate;
use App\Models\User;
use App\Services\Rentals\LeaseActivationService;
use App\Services\Rentals\LeaseHubService;
use App\Services\Rentals\LeaseTermAdvanceService;
use App\Services\Rentals\PropertyStatusFollowsLeaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Rental application/lease audit fixes, lease side (2026-10-19, items A1, A5, A7, A12, A16, A26; A24 is in
 * RentalInspectionMoveOutComparisonTest). A Cape-style agency that is not HFC; real services and HTTP routes.
 */
final class LeaseAuditFixesTest extends TestCase
{
    use BuildsLeaseAgreementFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpAgreementFixture();
        Mail::fake();
    }

    /** A "Create lease only" draft: no agreement, no signing state. */
    private function leaseOnlyDraft(array $over = []): Lease
    {
        $lease = Lease::create($over + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 8500, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
            'owner_agent_user_id' => $this->agent->id, 'tenant_agent_user_id' => $this->agent->id,
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);

        return $lease;
    }

    private function linkAgreement(array $extraMap = []): RentalLeaseTemplate
    {
        $template = new Template([
            'name' => 'Cape lease ' . uniqid(), 'render_type' => 'pdf', 'is_esign' => true,
            'signing_parties' => ['owner_party', 'acquiring_party', 'agent'],
        ]);
        $template->agency_id = $this->agency->id;
        $template->save();

        return RentalLeaseTemplate::create([
            'agency_id' => $this->agency->id, 'name' => 'Cape residential lease', 'docuperfect_template_id' => $template->id,
            'category' => RentalLeaseTemplate::CATEGORY_RESIDENTIAL, 'is_active' => true,
            'field_map' => [
                'rent' => ['field' => 'monthly_rental'], 'start_date' => ['field' => 'lease_start'], 'end_date' => ['field' => 'lease_end'],
                'tenant_name' => ['field' => 'lessee_name'], 'landlord_name' => ['field' => 'lessor_name'],
            ] + $extraMap,
        ]);
    }

    // ═══ A1 - a "Create lease only" draft can still be got signed ═══

    public function test_a_lease_only_draft_gets_a_follow_up_card_and_a_get_it_signed_next_step(): void
    {
        $lease = $this->leaseOnlyDraft();

        $step = app(LeaseHubService::class)->nextStep($lease, $this->agent);
        $this->assertSame('Get the lease signed', $step['label']);
        $this->assertSame('draft-follow-up', $step['fragment']);

        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $lease))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="draft-follow-up"', $html);
        $this->assertStringContainsString('data-qa="attach-signed-copy"', $html);
        $this->assertStringContainsString('Activate without a signed agreement', $html);
        $this->assertStringNotContainsString('data-qa="prepare-for-signing"', $html, 'the agency has no lease agreement set up');
        $this->assertStringContainsString('has not set up a lease agreement', $html);

        // A renewal draft keeps its own screens.
        $renewal = $this->leaseOnlyDraft(['previous_lease_id' => $lease->id]);
        $this->assertNull(app(\App\Services\Rentals\LeaseCaptureService::class)->draftFollowUpFor($renewal, $this->agent));
    }

    public function test_a_lease_only_draft_is_prepared_for_signing_later_with_the_same_gate_as_the_capture_screen(): void
    {
        $row = $this->linkAgreement(['adults' => ['field' => 'a', 'required' => true]]);
        $lease = $this->leaseOnlyDraft();

        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $lease))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="prepare-for-signing"', $html);
        $this->assertStringContainsString('name="agreement[adults]"', $html);

        // The required detail is blank: nothing is created, the gap is listed.
        $this->actingAs($this->agent)->from(route('corex.leases.show', $lease))
            ->post(route('corex.leases.signing.prepare', $lease), ['agreement_id' => $row->id, 'agreement' => ['adults' => '']])
            ->assertSessionHasErrors('agreement.adults');
        $this->assertSame(0, Flow::count());
        $this->assertNull($lease->fresh()->signing_flow_id);

        // Filled in: the agent lands on the prepared agreement; nothing is sent; the lease is still a draft.
        $response = $this->actingAs($this->agent)->post(route('corex.leases.signing.prepare', $lease), ['agreement_id' => $row->id, 'agreement' => ['adults' => '2']]);
        $flow = Flow::firstOrFail();
        $response->assertRedirect(route('docuperfect.esign.step', ['flow' => $flow->id, 'step' => 5]));
        $lease = $lease->fresh();
        $this->assertSame(Lease::SIGNING_PREPARED, $lease->signing_status);
        $this->assertSame($flow->id, $lease->signing_flow_id);
        $this->assertSame(Lease::STATUS_DRAFT, $lease->status);
        $this->assertSame(['agent', 'tenant', 'landlord'], array_column($flow->step_data['recipients']['recipients'], 'role'));
        $this->assertSame(2, \App\Models\LeaseAgreementTerms::where('lease_id', $lease->id)->firstOrFail()->adults);

        // Pressing it again hands back the same agreement (no second document).
        $this->actingAs($this->agent)->post(route('corex.leases.signing.prepare', $lease), ['agreement_id' => $row->id, 'agreement' => ['adults' => '2']])
            ->assertRedirect(route('docuperfect.esign.step', ['flow' => $flow->id, 'step' => 5]));
        $this->assertSame(1, Flow::count());
    }

    public function test_a_signed_paper_copy_can_be_attached_later_and_the_lease_goes_active(): void
    {
        Storage::fake('local');
        $lease = $this->leaseOnlyDraft();

        $this->actingAs($this->agent)->post(route('corex.leases.signing.signed-copy', $lease), [
            'signed_document' => UploadedFile::fake()->create('lease.pdf', 100, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect(route('corex.leases.show', $lease));

        $lease = $lease->fresh();
        $this->assertSame(Lease::STATUS_ACTIVE, $lease->status);
        $this->assertSame(Lease::SIGNING_SIGNED_ON_PAPER, $lease->signing_status);
        $this->assertSame(Lease::SOURCE_UPLOADED_SIGNED_COPY, $lease->source);
        $this->assertSame('let_out', $this->property->fresh()->status);
        $this->assertTrue(LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_SIGNED_COPY_ATTACHED_LATER)->exists());
        $this->assertSame(1, \App\Models\Document::where('source_type', 'lease')->where('source_id', $lease->id)->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('lease-signed-copies/' . $lease->id));
    }

    public function test_a_signed_copy_refused_by_the_one_active_lease_rule_leaves_nothing_behind(): void
    {
        Storage::fake('local');
        $running = $this->leaseOnlyDraft(['start_date' => '2025-11-01', 'end_date' => '2026-10-31']);
        app(LeaseActivationService::class)->activate($running);
        $lease = $this->leaseOnlyDraft();

        $this->actingAs($this->agent)->post(route('corex.leases.signing.signed-copy', $lease), [
            'signed_document' => UploadedFile::fake()->create('lease.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('status');

        $this->assertSame(Lease::STATUS_DRAFT, $lease->fresh()->status);
        $this->assertSame(Lease::SIGNING_NOT_SENT, $lease->fresh()->signing_status ?: Lease::SIGNING_NOT_SENT);
        $this->assertSame(0, \App\Models\Document::where('source_type', 'lease')->where('source_id', $lease->id)->count());
        $this->assertSame([], Storage::disk('local')->allFiles('lease-signed-copies/' . $lease->id), 'the stored file is removed');
    }

    public function test_the_follow_up_actions_are_scoped_to_the_leases_own_agency_and_state(): void
    {
        Storage::fake('local');
        $lease = $this->leaseOnlyDraft();

        $rivalAgency = \App\Models\Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
        $rivalBranch = \App\Models\Branch::create(['agency_id' => $rivalAgency->id, 'name' => 'Karoo']);
        $rival = User::factory()->create(['agency_id' => $rivalAgency->id, 'branch_id' => $rivalBranch->id, 'role' => 'agent', 'is_active' => true]);

        $this->actingAs($rival)->post(route('corex.leases.signing.signed-copy', $lease), [
            'signed_document' => UploadedFile::fake()->create('lease.pdf', 100, 'application/pdf'),
        ])->assertNotFound();
        $this->actingAs($rival)->post(route('corex.leases.signing.prepare', $lease))->assertNotFound();
        $this->assertSame(Lease::STATUS_DRAFT, $lease->fresh()->status);

        // An agreement already out for signing cannot be given a paper copy this way.
        $lease->update(['signing_status' => Lease::SIGNING_OUT_FOR_SIGNING]);
        $this->actingAs($this->agent)->post(route('corex.leases.signing.signed-copy', $lease), [
            'signed_document' => UploadedFile::fake()->create('lease.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('signed_document');
        $this->assertSame(Lease::STATUS_DRAFT, $lease->fresh()->status);
    }

    public function test_activating_a_draft_with_no_agreement_is_an_explicit_recorded_choice(): void
    {
        $lease = $this->leaseOnlyDraft();

        $this->actingAs($this->agent)->post(route('corex.leases.activate', $lease))->assertSessionHasNoErrors();

        $this->assertSame(Lease::STATUS_ACTIVE, $lease->fresh()->status);
        $this->assertSame('Lease activated without a signed agreement.', session('success'));
        $event = LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_ACTIVATED_WITHOUT_AGREEMENT)->first();
        $this->assertNotNull($event);
        $this->assertSame($this->agent->id, (int) $event->actor_user_id);
    }

    // ═══ A5 - an accepted notice ends the tenancy on its date ═══

    private function runningLeaseWithNotice(?string $moveOut, array $over = []): Lease
    {
        $lease = $this->leaseOnlyDraft($over + ['start_date' => '2026-11-01', 'end_date' => '2028-10-31']);
        app(LeaseActivationService::class)->activate($lease);
        if ($moveOut) {
            $lease->forceFill(['notice_date' => '2027-12-01', 'notice_given_by' => 'tenant', 'move_out_date' => $moveOut])->save();
        }

        return $lease->fresh();
    }

    public function test_an_accepted_notice_ends_the_tenancy_on_its_date_without_any_out_inspection(): void
    {
        $lease = $this->runningLeaseWithNotice('2028-01-31');
        $service = app(LeaseTermAdvanceService::class);

        $this->assertSame([], $service->endVacatedTenancies('2028-01-30'), 'the day before: nothing');
        $this->assertSame('would_end', $service->endVacatedTenancies('2028-01-31', true)[0]['action']);
        $this->assertSame(Lease::STATUS_ACTIVE, $lease->fresh()->status, 'a dry run changes nothing');
        $this->assertSame('tenancy_end', $service->pendingFor($lease)['kind']);

        $this->assertSame('ended', $service->endVacatedTenancies('2028-01-31')[0]['action']);

        $this->assertSame(Lease::STATUS_EXPIRED, $lease->fresh()->status);
        $this->assertSame('active', $this->property->fresh()->status, 'the property goes back to its pre-let status');
        $event = LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_TENANCY_ENDED)->firstOrFail();
        $this->assertStringContainsString('no move-out inspection completed yet', $event->description);
    }

    public function test_the_agency_can_switch_the_notice_date_ending_off_and_the_out_inspection_path_is_unchanged(): void
    {
        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['end_tenancy_on_move_out_date' => false]);
        $lease = $this->runningLeaseWithNotice('2028-01-31');
        $service = app(LeaseTermAdvanceService::class);

        $this->assertSame([], $service->endVacatedTenancies('2028-02-15'), 'switched off: the date alone ends nothing');
        $this->assertNull($service->pendingFor($lease));
        $this->assertSame(Lease::STATUS_ACTIVE, $lease->fresh()->status);

        RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'property_id' => $this->property->id,
            'type' => RentalInspection::TYPE_OUT, 'status' => RentalInspection::STATUS_COMPLETED,
        ]);
        $this->assertSame('ended', $service->endVacatedTenancies('2028-02-15')[0]['action'], 'a completed out-inspection still ends it');
        $this->assertSame(Lease::STATUS_EXPIRED, $lease->fresh()->status);
    }

    public function test_a_lease_past_its_plain_end_date_with_no_notice_is_not_ended_by_the_date_alone(): void
    {
        $lease = $this->runningLeaseWithNotice(null, ['end_date' => '2027-10-31', 'start_date' => '2026-11-01']);

        $this->assertSame([], app(LeaseTermAdvanceService::class)->endVacatedTenancies('2028-03-01'));
        $this->assertSame(Lease::STATUS_ACTIVE, $lease->fresh()->status, 'that is the record-an-outcome / month-to-month path');
    }

    public function test_a_notice_ending_does_not_put_the_property_back_when_the_agency_turned_that_off(): void
    {
        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['auto_restore_status_on_lease_ended' => false]);
        $lease = $this->runningLeaseWithNotice('2028-01-31');

        app(LeaseTermAdvanceService::class)->endVacatedTenancies('2028-01-31');

        $this->assertSame(Lease::STATUS_EXPIRED, $lease->fresh()->status, 'the tenancy still ends on its date');
        $this->assertSame('let_out', $this->property->fresh()->status, 'the property setting is respected');
    }

    public function test_the_new_setting_is_saved_audited_and_offered_in_the_setup_wizard(): void
    {
        $this->actingAs($this->agent)->post(route('corex.settings.leases.update'), [
            'expiry_notice_window_days' => 60, 'end_tenancy_on_move_out_date' => '0',
        ])->assertRedirect(route('corex.settings.leases.edit'));
        $this->assertFalse(LeaseSetting::endTenancyOnMoveOutDateFor($this->agency->id));
        $this->assertDatabaseHas('rental_setting_audit', ['agency_id' => $this->agency->id, 'setting_key' => 'end_tenancy_on_move_out_date']);

        // A partial post that does not carry it (the wizard step, a mobile client) leaves it alone.
        $this->actingAs($this->agent)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 45])->assertSessionHasNoErrors();
        $this->assertFalse(LeaseSetting::endTenancyOnMoveOutDateFor($this->agency->id));

        $this->assertStringContainsString("'key' => 'end_tenancy_on_move_out_date', 'source' => 'leases'", (string) file_get_contents(config_path('agency-onboarding-copy.php')), 'the wizard config carries the control');
    }

    // ═══ A7 - no lease against a non-approved application ═══

    private function application(string $status): RentalApplication
    {
        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->tenant->id,
            'created_by_user_id' => $this->agent->id, 'status' => $status, 'approved_rental_amount' => 10000,
            'current_generation' => 1, 'token' => 'tok-' . uniqid(),
        ]);
    }

    public function test_a_lease_cannot_be_created_against_a_declined_returned_or_withdrawn_application(): void
    {
        foreach (['declined', 'returned', 'withdrawn', 'under_assessment'] as $status) {
            $application = $this->application($status);
            $this->actingAs($this->agent)->post(route('corex.leases.store'), [
                'intent' => 'lease_only', 'property_id' => $this->property->id, 'rental_application_id' => $application->id,
                'tenant_contact_ids' => [$this->tenant->id], 'rental_amount' => 8500, 'start_date' => '2026-11-01',
            ])->assertSessionHasErrors('rental_application_id');
            $this->assertStringContainsString('approved rental application', session('errors')->first('rental_application_id'));
        }
        $this->assertSame(0, Lease::count());
    }

    public function test_the_writer_refuses_it_too_and_an_approved_application_still_works(): void
    {
        $declined = $this->application('declined');
        try {
            app(\App\Services\Rentals\LeaseCaptureService::class)->capture([
                'property_id' => $this->property->id, 'rental_amount' => 8500, 'start_date' => '2026-11-01',
                'tenant_contact_ids' => [$this->tenant->id], 'rental_application_id' => $declined->id,
            ], 'lease_only', $this->agent);
            $this->fail('the service must refuse a declined application');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('rental_application_id', $e->errors());
        }
        $this->assertSame(0, Lease::count());

        $approved = $this->application('approved');
        $this->actingAs($this->agent)->post(route('corex.leases.store'), [
            'intent' => 'lease_only', 'property_id' => $this->property->id, 'rental_application_id' => $approved->id,
            'tenant_contact_ids' => [$this->tenant->id], 'rental_amount' => 8500, 'start_date' => '2026-11-01',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, Lease::count());
    }

    public function test_the_capture_screen_for_a_non_approved_application_sends_the_agent_back_with_the_reason(): void
    {
        $declined = $this->application('declined');

        $this->actingAs($this->agent)->get(route('corex.leases.create', ['rental_application_id' => $declined->id]))
            ->assertRedirect(route('corex.rental-applications.show', $declined->id))
            ->assertSessionHas('warning');

        $this->actingAs($this->agent)->get(route('corex.leases.create', ['rental_application_id' => $this->application('approved')->id]))->assertOk();
    }

    // ═══ A12 - lease update: month-to-month kept when absent, XOR with the end date ═══

    public function test_a_partial_update_keeps_month_to_month(): void
    {
        $lease = $this->leaseOnlyDraft(['end_date' => null, 'is_month_to_month' => true]);

        $this->actingAs($this->agent)->put(route('corex.leases.update', $lease), ['deposit_amount' => '9000'])->assertSessionHasNoErrors();

        $lease = $lease->fresh();
        $this->assertTrue($lease->is_month_to_month, 'a client posting only the deposit does not switch month-to-month off');
        $this->assertSame('9000.00', (string) $lease->deposit_amount);
        $this->assertNull($lease->end_date);
    }

    public function test_month_to_month_with_an_end_date_is_refused_the_same_way_as_on_capture(): void
    {
        $lease = $this->leaseOnlyDraft();

        $this->actingAs($this->agent)->put(route('corex.leases.update', $lease), ['is_month_to_month' => '1', 'end_date' => '2028-01-31'])
            ->assertSessionHasErrors(['end_date' => 'A month-to-month lease has no end date — clear one or the other.']);
        $this->assertFalse($lease->fresh()->is_month_to_month);
        $this->assertSame('2027-10-31', $lease->fresh()->end_date->toDateString());

        // Unticking works (the web form posts a hidden 0), and setting a fixed end date then is fine.
        $mtm = $this->leaseOnlyDraft(['end_date' => null, 'is_month_to_month' => true, 'property_id' => $this->property->id]);
        $this->actingAs($this->agent)->put(route('corex.leases.update', $mtm), ['is_month_to_month' => '0', 'end_date' => '2028-01-31'])->assertSessionHasNoErrors();
        $this->assertFalse($mtm->fresh()->is_month_to_month);
        $this->assertSame('2028-01-31', $mtm->fresh()->end_date->toDateString());
    }

    // ═══ A16 - the partner on the application is carried into the lease contacts ═══

    private function applicationWithPartner(string $partnerId = '8501015800083'): RentalApplication
    {
        $application = $this->application('approved');
        $application->forceFill(['spouse_name' => 'Zinzi Dlamini', 'spouse_id' => $partnerId])->save();

        return $application->fresh();
    }

    public function test_a_partner_who_is_already_a_contact_is_seeded_as_the_second_tenant(): void
    {
        $partner = $this->contact('Zinzi', 'Dlamini', '850101 5800 083');
        $application = $this->applicationWithPartner('8501015800083');

        $response = $this->actingAs($this->agent)->get(route('corex.leases.create', ['rental_application_id' => $application->id]))->assertOk();

        $seed = $response->viewData('oldTenants');
        $this->assertSame([$this->tenant->id, $partner->id], array_column($seed, 'id'));
        $this->assertNull($response->viewData('coApplicantOffer'));
    }

    public function test_a_partner_who_is_not_a_contact_is_offered_and_one_click_creates_the_contact_once(): void
    {
        $application = $this->applicationWithPartner();

        $response = $this->actingAs($this->agent)->get(route('corex.leases.create', ['rental_application_id' => $application->id]))->assertOk();
        $this->assertSame([$this->tenant->id], array_column($response->viewData('oldTenants'), 'id'));
        $this->assertSame(['name' => 'Zinzi Dlamini', 'id_number' => '8501015800083'], $response->viewData('coApplicantOffer'));
        $this->assertStringContainsString('data-qa="co-applicant-offer"', $response->getContent());

        $before = Contact::count();
        $first = $this->actingAs($this->agent)->postJson(route('corex.leases.application-co-applicant'), ['rental_application_id' => $application->id])
            ->assertOk()->assertJsonPath('created', true)->assertJsonPath('name', 'Zinzi Dlamini');
        $this->assertSame($before + 1, Contact::count());
        $contact = Contact::findOrFail($first->json('id'));
        $this->assertSame($this->agency->id, (int) $contact->agency_id);
        $this->assertSame('8501015800083', $contact->id_number);

        // Pressed again: the same contact, never a duplicate.
        $this->actingAs($this->agent)->postJson(route('corex.leases.application-co-applicant'), ['rental_application_id' => $application->id])
            ->assertOk()->assertJsonPath('created', false)->assertJsonPath('id', $contact->id);
        $this->assertSame($before + 1, Contact::count());
    }

    public function test_the_partner_endpoint_refuses_another_agency_and_a_non_approved_application(): void
    {
        $application = $this->applicationWithPartner();
        $declined = $this->application('declined');
        $declined->forceFill(['spouse_name' => 'Nobody Here'])->save();

        $rivalAgency = \App\Models\Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
        $rivalBranch = \App\Models\Branch::create(['agency_id' => $rivalAgency->id, 'name' => 'Karoo']);
        $rival = User::factory()->create(['agency_id' => $rivalAgency->id, 'branch_id' => $rivalBranch->id, 'role' => 'admin', 'is_active' => true]);

        $before = Contact::count();
        $this->actingAs($rival)->postJson(route('corex.leases.application-co-applicant'), ['rental_application_id' => $application->id])->assertNotFound();
        $this->actingAs($this->agent)->postJson(route('corex.leases.application-co-applicant'), ['rental_application_id' => $declined->id])->assertStatus(422);
        $this->actingAs($this->agent)->postJson(route('corex.leases.application-co-applicant'), [])->assertStatus(422);
        $this->assertSame($before, Contact::count());
    }

    public function test_an_application_with_no_partner_offers_nothing(): void
    {
        $application = $this->application('approved');

        $response = $this->actingAs($this->agent)->get(route('corex.leases.create', ['rental_application_id' => $application->id]))->assertOk();
        $this->assertNull($response->viewData('coApplicantOffer'));
        $this->assertSame([$this->tenant->id], array_column($response->viewData('oldTenants'), 'id'));
    }

    // ═══ A26 - no literal let status; a visible warning instead of a silent skip ═══

    public function test_the_let_status_comes_from_one_resolver_and_no_service_carries_the_literal(): void
    {
        $this->assertSame('let_out', app(PropertyStatusFollowsLeaseService::class)->leasedOutStatusFor($this->agency->id));

        $lease = $this->leaseOnlyDraft();
        app(LeaseActivationService::class)->activate($lease);
        $this->assertSame('let_out', $this->property->fresh()->status);
        $this->assertNull(app(LeaseActivationService::class)->propertyStatusWarning($lease->fresh()));

        foreach (['LeaseActivationService.php'] as $file) {
            $this->assertStringNotContainsString("'let_out'", (string) file_get_contents(app_path('Services/Rentals/' . $file)), "{$file} must not name the status");
        }
        $service = (string) file_get_contents(app_path('Services/Rentals/PropertyStatusFollowsLeaseService.php'));
        $this->assertSame(1, substr_count($service, "'let_out'") , 'the status is named once, in the candidate list');
    }

    public function test_a_vocabulary_with_no_let_status_leaves_the_property_alone_but_says_so(): void
    {
        $stub = new class extends PropertyStatusFollowsLeaseService {
            public function leasedOutStatusFor(?int $agencyId): ?string
            {
                return null;
            }
        };
        $this->app->instance(PropertyStatusFollowsLeaseService::class, $stub);

        $lease = $this->leaseOnlyDraft();
        $this->actingAs($this->agent)->post(route('corex.leases.activate', $lease))->assertSessionHasNoErrors();

        $this->assertSame(Lease::STATUS_ACTIVE, $lease->fresh()->status);
        $this->assertSame('active', $this->property->fresh()->status, 'untouched');
        $this->assertStringContainsString('no property status for a let property', (string) session('warning'));
        $this->assertTrue(LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_PROPERTY_STATUS_NOT_UPDATED)->exists());

        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $lease))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="property-status-warning"', $html);
    }
}
