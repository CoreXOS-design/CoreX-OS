<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalOwnerDecisionRecordedMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalRecordedOwnerDecision;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\RentalApprovalGateService;
use App\Services\Rentals\RentalOwnerDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-452 round 3 (rentals-faults-work-orders.md §20) - "no work without authority": A) the agent's two limits (ordinary / emergency-with-a-reason),
 * B) the owner on their link, C) the agent records the owner's decision. Every path and every limit boundary.
 */
final class OwnerAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $landlord;
    private RentalFaultType $faultType;
    private AgencyServiceProvider $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('public');
        $this->agency = Agency::create(['name' => 'Authority Agency', 'slug' => 'au-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'AU Unit', 'status' => 'active', 'listing_type' => 'rental',
            'rental_no_approval_spend_threshold' => 1000, 'rental_emergency_spend_threshold' => 3000]);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid']);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->faultType = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine', 'first_aid_steps' => 'Close the valve.', 'is_active' => true]);
        $this->supplier = AgencyServiceProvider::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Pipes Plumbing', 'email' => 'pipes@example.invalid', 'is_active' => true, 'specialty' => 'other']);
    }

    private function login(Contact $c): ClientUser
    {
        $cu = ClientUser::firstOrCreate(['email' => $c->email], ['current_agency_id' => $c->agency_id]);
        $c->forceFill(['client_user_id' => $cu->id])->saveQuietly();

        return $cu;
    }

    /** A fault already sent to the owner and waiting for their decision. */
    private function sentFault(): RentalFaultReport
    {
        $fault = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->faultType->id, 'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id, 'reported_channel' => 'app',
            'title' => 'TENANT WORDS', 'description' => 'TENANT ORIGINAL', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
        $fault->saveOwnerVersion(['owner_title' => 'Kitchen tap leaking', 'owner_description' => 'A tap drips.', 'owner_agent_note' => 'Plumber needed'], $this->agent);
        $fault->forceFill(['status' => RentalFaultReport::STATUS_AWAITING_APPROVAL, 'owner_approval_status' => RentalFaultReport::APPROVAL_PENDING, 'sent_to_owner_at' => now(), 'sent_to_owner_by_user_id' => $this->agent->id])->save();

        return $fault->fresh();
    }

    private function record(RentalFaultReport|RentalWorkOrder $subject, array $over = [], ?User $by = null)
    {
        $route = $subject instanceof RentalFaultReport ? 'corex.rental-fault-reports.owner-decision.store' : 'corex.rental-work-orders.owner-decision.store';

        return $this->actingAs($by ?? $this->agent)->post(route($route, $subject), array_merge([
            'owner_contact_id' => $this->landlord->id, 'decision' => 'approved', 'told_via' => 'phone_call', 'told_at' => now()->subMinutes(5)->format('Y-m-d\TH:i'),
            'note' => 'Lenny said go ahead on the phone', 'handled_by' => 'agency',
        ], $over));
    }

    /** An outside-supplier work order born from an approved fault, with a selected quote of $amount. */
    private function workOrderWithQuote(float $amount, bool $declareEmergency = false): RentalWorkOrder
    {
        $fault = $this->sentFault();
        $this->record($fault)->assertRedirect();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault->fresh()), ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $this->supplier->id])->assertRedirect();
        $wo = RentalWorkOrder::withoutGlobalScopes()->findOrFail($fault->fresh()->rental_work_order_id);
        if ($declareEmergency) {
            app(RentalOwnerDecisionService::class)->declareEmergency($wo, $this->agent, 'Water everywhere');
        }
        $quote = $wo->recordQuote(['agency_service_provider_id' => $this->supplier->id, 'amount' => $amount, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->agent);
        $wo->fresh()->selectQuote($quote, $this->agent);

        return $wo->fresh();
    }

    /** A bare work order (no fault) that the agent can declare an emergency on and quote. */
    private function bareWorkOrder(): RentalWorkOrder
    {
        $fault = $this->sentFault();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $this->supplier->id])->assertRedirect();

        return RentalWorkOrder::withoutGlobalScopes()->findOrFail($fault->fresh()->rental_work_order_id);
    }

    private function quote(RentalWorkOrder $wo, float $amount): void
    {
        $q = $wo->recordQuote(['agency_service_provider_id' => $this->supplier->id, 'amount' => $amount, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->agent);
        $wo->fresh()->selectQuote($q, $this->agent);
    }

    // ───────────────────────── A: the two limits ─────────────────────────

    public function test_the_emergency_limit_resolves_property_then_agency_then_the_built_in_default(): void
    {
        $this->assertEquals(3000.0, RentalWorkOrderSetting::emergencyThresholdFor($this->property), 'the property override wins');
        $this->property->forceFill(['rental_emergency_spend_threshold' => null])->save();
        $this->assertEquals(RentalWorkOrderSetting::DEFAULT_EMERGENCY_SPEND_THRESHOLD, RentalWorkOrderSetting::emergencyThresholdFor($this->property->fresh()), 'no setting yet: the built-in default');
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $this->agency->id], ['emergency_spend_threshold' => 4500]);
        $this->assertEquals(4500.0, RentalWorkOrderSetting::emergencyThresholdFor($this->property->fresh()), 'the agency default');
        $terms = app(RentalApprovalGateService::class)->termsFor($this->property->fresh());
        $this->assertEquals(4500.0, $terms->emergencyLimit);
        $this->assertSame('agency_default', $terms->emergencySource);
        $this->assertEquals(1000.0, $terms->noApprovalLimit);
    }

    public function test_the_ordinary_limit_boundary_exactly_at_the_limit_is_authorised_one_cent_over_goes_to_the_owner(): void
    {
        $wo = $this->bareWorkOrder();
        $this->quote($wo, 1000.00);
        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $wo->fresh()->owner_approval_status, 'R1000.00 = the limit: the agent may approve it');
        $this->assertSame(RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT, $wo->fresh()->approval_basis);

        $wo2 = $this->bareWorkOrder();
        $this->quote($wo2, 1000.01);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo2->fresh()->owner_approval_status, 'one cent over: the owner must approve');
        $this->assertFalse(app(RentalApprovalGateService::class)->authoriseToProceed($wo2->fresh(), false)->authorised);
    }

    public function test_an_emergency_needs_a_reason_and_without_one_the_ordinary_limit_applies(): void
    {
        $wo = $this->bareWorkOrder();
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.declare-emergency', $wo), ['emergency_reason' => '  '])->assertSessionHasErrors();
        $this->assertFalse($wo->fresh()->isDeclaredEmergency(), 'no reason, no emergency');
        try {
            app(RentalOwnerDecisionService::class)->declareEmergency($wo, $this->agent, '');
            $this->fail('an emergency without a reason must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('reason is required', $e->getMessage());
        }
        $this->quote($wo->fresh(), 2500);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status, 'R2500 is over the ordinary limit and there is no emergency');
    }

    public function test_a_declared_emergency_is_held_to_the_emergency_limit_exactly(): void
    {
        $wo = $this->bareWorkOrder();
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.declare-emergency', $wo), ['emergency_reason' => 'Burst pipe, water in the lounge'])->assertSessionHasNoErrors();
        $this->assertTrue($wo->fresh()->isDeclaredEmergency());
        $this->assertContains('emergency_declared', $wo->fresh()->updates()->pluck('update_type')->all());

        $this->quote($wo->fresh(), 3000.00);
        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $wo->fresh()->owner_approval_status, 'R3000.00 = the emergency limit');
        $this->assertSame(RentalWorkOrder::BASIS_EMERGENCY_LIMIT, $wo->fresh()->approval_basis);

        $wo2 = $this->bareWorkOrder();
        app(RentalOwnerDecisionService::class)->declareEmergency($wo2, $this->agent, 'Gas smell');
        $this->quote($wo2->fresh(), 3000.01);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo2->fresh()->owner_approval_status, 'one cent over the emergency limit: the owner must approve');
    }

    public function test_an_emergency_limit_below_the_ordinary_one_never_makes_an_emergency_stricter(): void
    {
        $this->property->forceFill(['rental_emergency_spend_threshold' => 200])->save();
        $wo = $this->bareWorkOrder();
        app(RentalOwnerDecisionService::class)->declareEmergency($wo, $this->agent, 'Flooding');
        $this->assertEquals(1000.0, $wo->fresh()->spendThreshold(), 'the larger of the two applies');
        $this->quote($wo->fresh(), 900);
        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $wo->fresh()->owner_approval_status);
    }

    public function test_going_ahead_as_an_emergency_needs_the_reason_and_uses_the_emergency_limit(): void
    {
        $fault = $this->sentFault();
        $fault->forceFill(['status' => RentalFaultReport::STATUS_UNDER_REVIEW, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'sent_to_owner_at' => null])->save();
        $crew = \App\Models\RentalCrew::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Team', 'is_active' => true]);

        // ticked, no reason: refused, nothing created
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'internal', 'rental_crew_id' => $crew->id, 'emergency' => 1])->assertSessionHasErrors();
        $this->assertNull($fault->fresh()->rental_work_order_id);

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'internal', 'rental_crew_id' => $crew->id, 'emergency' => 1, 'emergency_reason' => 'No water to the house'])->assertRedirect();
        $f = $fault->fresh();
        $wo = RentalWorkOrder::withoutGlobalScopes()->findOrFail($f->rental_work_order_id);
        $this->assertTrue($wo->isDeclaredEmergency());
        $this->assertSame('No water to the house', $wo->emergency_declared_reason);
        $this->assertSame(RentalFaultReport::ARRANGED_EMERGENCY, $f->arranged_basis);
        $this->assertEquals(3000.0, (float) $f->arranged_limit_amount, 'the emergency limit, not the ordinary one');
        $this->assertStringContainsString('emergency limit', $f->arrangedText());
        $this->assertStringContainsString('No water to the house', $f->arrangedText());
        $this->assertStringNotContainsString('informed, not asked', $f->arrangedText());
    }

    public function test_the_go_ahead_wording_no_longer_says_informed_not_asked_and_names_both_limits(): void
    {
        $fault = $this->sentFault();
        $fault->forceFill(['status' => RentalFaultReport::STATUS_UNDER_REVIEW, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'sent_to_owner_at' => null])->save();
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringNotContainsString('informed, not asked', $html);
        $this->assertStringContainsString('R1,000.00', $html);
        $this->assertStringContainsString('R3,000.00', $html);
        $this->assertStringContainsString('data-emergency-reason', $html);
    }

    // ───────────────────────── C: record the owner's decision ─────────────────────────

    public function test_recording_an_approval_on_a_fault_has_the_same_effect_as_the_owners_link(): void
    {
        $fault = $this->sentFault();
        $this->record($fault, ['approved_up_to' => '2500'])->assertRedirect()->assertSessionHasNoErrors();
        $f = $fault->fresh();

        $this->assertSame(RentalFaultReport::APPROVAL_APPROVED, $f->owner_approval_status);
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $f->status);
        $this->assertSame(RentalFaultReport::ROUTE_AGENCY_APPOINTS, $f->approval_route);
        $this->assertEquals(2500.0, (float) $f->owner_approved_up_to);

        $row = RentalRecordedOwnerDecision::withoutGlobalScopes()->firstOrFail();
        $this->assertSame([$this->agent->id, $this->landlord->id, 'approved', 'phone_call', RentalRecordedOwnerDecision::STEP_FAULT], [$row->recorded_by_user_id, $row->owner_contact_id, $row->decision, $row->told_via, $row->step]);
        $this->assertEquals(2500.0, (float) $row->approved_up_to);
        $this->assertSame('Lenny said go ahead on the phone', $row->note);

        $approval = $f->decision();
        $this->assertSame('verbal_note', $approval->evidence_type, 'a phone call is the "verbal" evidence of the existing record');
        $this->assertSame($this->agent->id, $approval->recorded_by_user_id);
        $this->assertContains('owner_decision_recorded', $f->updates()->pluck('update_type')->all());
        $this->assertStringContainsString('recorded the owner\'s decision', $f->updates()->where('update_type', 'owner_decision_recorded')->value('note'));
        $this->assertStringContainsString('phone call', $f->updates()->where('update_type', 'owner_decision_recorded')->value('note'));
    }

    public function test_the_owner_is_emailed_the_same_sentence_the_portal_shows(): void
    {
        $fault = $this->sentFault();
        $this->record($fault, ['told_via' => 'whatsapp'])->assertRedirect();

        $sentence = null;
        Mail::assertQueued(RentalOwnerDecisionRecordedMail::class, function ($m) use (&$sentence) {
            $sentence = $m->sentence;

            return $m->hasTo($this->landlord->email);
        });
        $this->assertMatchesRegularExpression('/^Authorised by your agent .+ on your instruction, given by WhatsApp on \d{1,2} \w{3} \d{4} \d{2}:\d{2}\.$/', $sentence);

        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $show = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->assertOk()->json('fault_report');
        $this->assertSame($sentence, $show['recorded_decision']['sentence'], 'the email and the portal say the same thing');
        $this->assertSame('Lenny said go ahead on the phone', $show['recorded_decision']['note']);
        $this->assertTrue($show['recorded_decision']['can_override']);
    }

    public function test_no_owner_email_when_the_agency_has_the_owner_notice_switched_off(): void
    {
        \App\Models\RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['notify_landlord_on_decision_needed' => false]);
        $fault = $this->sentFault();
        $this->record($fault)->assertRedirect();
        Mail::assertNotQueued(RentalOwnerDecisionRecordedMail::class);
    }

    public function test_declining_needs_what_the_owner_said_and_closes_the_fault_like_the_owners_own_decline(): void
    {
        $fault = $this->sentFault();
        $this->record($fault, ['decision' => 'declined', 'note' => ''])->assertSessionHasErrors();
        $this->assertSame(RentalFaultReport::APPROVAL_PENDING, $fault->fresh()->owner_approval_status);

        $this->record($fault, ['decision' => 'declined', 'note' => 'Too dear, get another'])->assertRedirect()->assertSessionHasNoErrors();
        $f = $fault->fresh();
        $this->assertSame(RentalFaultReport::STATUS_DECLINED, $f->status);
        $this->assertSame(RentalFaultReport::OUTCOME_OWNER_DECLINED, $f->outcome, 'the automatic close of an owner decline');
    }

    public function test_the_note_the_owner_and_the_way_they_told_us_are_all_required_and_checked(): void
    {
        $fault = $this->sentFault();
        $this->record($fault, ['note' => ''])->assertSessionHasErrors('note');
        $this->record($fault, ['told_via' => 'carrier_pigeon'])->assertSessionHasErrors('told_via');
        $this->record($fault, ['owner_contact_id' => $this->tenant->id])->assertSessionHasErrors('owner_decision');   // the tenant is not an owner of the lease
        $this->record($fault, ['told_at' => now()->addDays(2)->format('Y-m-d\TH:i')])->assertSessionHasErrors('owner_decision');
        $this->record($fault, ['decision' => 'get_a_quote_maybe'])->assertSessionHasErrors('decision');
        $this->assertSame(0, RentalRecordedOwnerDecision::withoutGlobalScopes()->count(), 'nothing was saved by any refused attempt');
        $this->assertSame(RentalFaultReport::APPROVAL_PENDING, $fault->fresh()->owner_approval_status);
    }

    public function test_proof_can_be_attached_and_is_kept(): void
    {
        $fault = $this->sentFault();
        $this->record($fault, ['proof' => UploadedFile::fake()->image('whatsapp.png', 400, 400)])->assertRedirect()->assertSessionHasNoErrors();
        $row = RentalRecordedOwnerDecision::withoutGlobalScopes()->firstOrFail();
        $this->assertNotNull($row->proof_path);
        $this->assertStringContainsString($row->proof_path, $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->getContent());
    }

    public function test_the_fault_step_offers_three_choices_the_quote_step_only_two(): void
    {
        $svc = app(RentalOwnerDecisionService::class);
        $this->assertSame(['approved', 'get_quote_first', 'declined'], $svc->optionsFor($this->sentFault()));
        $this->assertSame(['approved', 'declined'], $svc->optionsFor($this->bareWorkOrder()));
        $wo = $this->bareWorkOrder();
        $this->record($wo, ['decision' => 'get_quote_first', 'approved_up_to' => 500])->assertSessionHasErrors('decision');
    }

    // ───────────────────────── get a quote first ─────────────────────────

    public function test_get_a_quote_first_keeps_the_fault_waiting_and_the_quote_always_goes_to_the_owner(): void
    {
        $fault = $this->sentFault();
        $this->record($fault, ['decision' => 'get_quote_first', 'note' => 'Wants to see a price first'])->assertRedirect()->assertSessionHasNoErrors();
        $f = $fault->fresh();
        $this->assertNotNull($f->quote_first_at);
        $this->assertSame(RentalFaultReport::APPROVAL_PENDING, $f->owner_approval_status, 'the fault stays waiting');
        $this->assertSame('Waiting for a quote', $f->ownerStatusLabel());

        // the owner sees "waiting for a quote", never "needs your decision"
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $row = collect($this->getJson('/api/v1/client/rentals/landlord/fault-reports')->json('fault_reports'))->firstWhere('id', $f->id);
        $this->assertFalse($row['needs_decision']);
        $this->assertTrue($row['quote_first']);
        $show = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}")->json('fault_report');
        $this->assertFalse($show['awaiting_decision']);
        $this->assertStringContainsString('asked for a quote first', $show['recorded_decision']['sentence']);

        // the work order may be raised; a tiny quote (well inside the limit) STILL goes to the owner
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $f), ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $this->supplier->id])->assertRedirect();
        $wo = RentalWorkOrder::withoutGlobalScopes()->findOrFail($f->fresh()->rental_work_order_id);
        $this->assertTrue($wo->quote_first);
        $this->quote($wo, 100);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status, 'R100 would normally need nobody - the owner asked to see it');
        $this->assertFalse(app(RentalApprovalGateService::class)->authoriseToProceed($wo->fresh(), false)->authorised);

        // the owner approves the QUOTE on their link: the work order is approved and so is the fault
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/decision", ['decision' => 'approve'])->assertOk();
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $wo->fresh()->owner_approval_status);
        $f2 = $f->fresh();
        $this->assertSame(RentalFaultReport::APPROVAL_APPROVED, $f2->owner_approval_status);
        $this->assertNull($f2->quote_first_at);
    }

    public function test_the_owner_can_choose_get_a_quote_first_on_their_own_link_too(): void
    {
        $fault = $this->sentFault();
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}/decision", ['decision' => 'quote_first'])->assertOk()->assertJsonPath('fault_report.quote_first', true);
        $f = $fault->fresh();
        $this->assertNotNull($f->quote_first_at);
        $this->assertSame(RentalFaultReport::APPROVAL_PENDING, $f->owner_approval_status);
        $this->assertContains('owner_quote_first', $f->updates()->pluck('update_type')->all());
        $this->assertSame(0, RentalRecordedOwnerDecision::withoutGlobalScopes()->count(), 'the ledger is only for decisions an AGENT recorded');
        // and they can still decide for real afterwards
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}/decision", ['decision' => 'approve', 'handled_by' => 'agency'])->assertOk();
        $this->assertSame(RentalFaultReport::APPROVAL_APPROVED, $fault->fresh()->owner_approval_status);
        $this->assertNull($fault->fresh()->quote_first_at);
    }

    // ───────────────────────── the quote step and emergencies over the limit ─────────────────────────

    public function test_recording_the_owners_approval_of_a_quote_over_the_limit(): void
    {
        $wo = $this->workOrderWithQuote(1800);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->owner_approval_status);
        $this->assertFalse(app(RentalApprovalGateService::class)->authoriseToProceed($wo, false)->authorised, 'neither A nor B nor C yet: cannot proceed');
        $note = app(RentalApprovalGateService::class)->authoriseToProceed($wo, false)->note;
        $this->assertStringContainsString('approves it on their link, or you record what the owner told you', $note, 'the plain reason line');

        // less than the quote: refused
        $this->record($wo, ['approved_up_to' => 1700])->assertSessionHasErrors('owner_decision');
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status);

        $this->record($wo)->assertRedirect()->assertSessionHasNoErrors();   // blank = the quote itself
        $w = $wo->fresh();
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $w->owner_approval_status);
        $this->assertSame(RentalWorkOrder::BASIS_OWNER_DECISION, $w->approval_basis);
        $this->assertEquals(1800.0, (float) $w->approved_amount);
        $this->assertTrue(app(RentalApprovalGateService::class)->authoriseToProceed($w, false)->authorised, 'authority C');
        $this->assertSame('verbal_note', $w->approvals()->latest('id')->first()->evidence_type);
    }

    public function test_recording_the_owners_decline_of_a_quote_needs_the_reason(): void
    {
        $wo = $this->workOrderWithQuote(1800);
        $this->record($wo, ['decision' => 'declined', 'note' => ''])->assertSessionHasErrors();
        $this->record($wo, ['decision' => 'declined', 'note' => 'Far too dear'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(RentalWorkOrder::APPROVAL_DECLINED, $wo->fresh()->owner_approval_status);
    }

    public function test_an_emergency_over_the_emergency_limit_cannot_proceed_until_the_owner_decides_or_it_is_recorded(): void
    {
        $wo = $this->bareWorkOrder();
        app(RentalOwnerDecisionService::class)->declareEmergency($wo, $this->agent, 'Roof torn off in a storm');
        $this->quote($wo->fresh(), 5200);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status);
        $this->assertFalse(app(RentalApprovalGateService::class)->authoriseToProceed($wo->fresh(), false)->authorised);

        $this->record($wo, ['approved_up_to' => 5200])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(app(RentalApprovalGateService::class)->authoriseToProceed($wo->fresh(), false)->authorised);
    }

    public function test_an_emergency_with_no_quote_yet_needs_an_amount_from_the_owner_and_that_amount_is_the_ceiling(): void
    {
        $wo = $this->bareWorkOrder();
        app(RentalOwnerDecisionService::class)->declareEmergency($wo, $this->agent, 'Sewage backing up');
        $wo = $wo->fresh();
        $this->assertNull(app(RentalOwnerDecisionService::class)->blockReason($wo), 'a declared emergency with nothing authorised is a point where the owner is awaited');

        $this->record($wo, ['approved_up_to' => ''])->assertSessionHasErrors('owner_decision');   // nothing is ever authorised without a ceiling
        $this->record($wo, ['approved_up_to' => 4000])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(app(RentalApprovalGateService::class)->authoriseToProceed($wo->fresh(), false)->authorised);
        $this->assertEquals(4000.0, (float) $wo->fresh()->owner_approved_up_to);

        // a quote inside the ceiling is covered by it
        $this->quote($wo->fresh(), 3900);
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $wo->fresh()->owner_approval_status);
        $this->assertSame(RentalWorkOrder::BASIS_OWNER_DECISION, $wo->fresh()->approval_basis);

        // a quote one cent over the ceiling is not: it goes to the owner
        $over = $this->bareWorkOrder();
        app(RentalOwnerDecisionService::class)->declareEmergency($over, $this->agent, 'Sewage backing up again');
        $this->record($over, ['approved_up_to' => 4000])->assertRedirect()->assertSessionHasNoErrors();
        $this->quote($over->fresh(), 4000.01);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $over->fresh()->owner_approval_status);
    }

    public function test_the_amount_the_owner_approved_on_the_fault_covers_a_later_quote_up_to_it_and_no_further(): void
    {
        $fault = $this->sentFault();
        $this->record($fault, ['approved_up_to' => 2500])->assertRedirect();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault->fresh()), ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $this->supplier->id])->assertRedirect();
        $wo = RentalWorkOrder::withoutGlobalScopes()->findOrFail($fault->fresh()->rental_work_order_id);
        $this->assertEquals(2500.0, (float) $wo->owner_approved_up_to, 'the ceiling travels to the work order');

        $this->quote($wo, 2500.00);
        $this->assertTrue(app(RentalApprovalGateService::class)->authoriseToProceed($wo->fresh(), false)->authorised, 'at the ceiling: covered');
        $wo2 = $this->bareWorkOrderFromCeiling(2500);
        $this->quote($wo2, 2500.01);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo2->fresh()->owner_approval_status, 'one cent over: back to the owner');
    }

    private function bareWorkOrderFromCeiling(float $ceiling): RentalWorkOrder
    {
        $wo = $this->bareWorkOrder();
        $wo->forceFill(['owner_approved_up_to' => $ceiling])->save();

        return $wo;
    }

    // ───────────────────────── supersede, lock, immutability ─────────────────────────

    public function test_a_recorded_decision_can_be_replaced_by_a_new_recorded_one_until_a_work_order_exists(): void
    {
        $fault = $this->sentFault();
        $this->record($fault, ['note' => 'First: go ahead'])->assertRedirect();
        $this->record($fault, ['decision' => 'declined', 'note' => 'Second: changed his mind'])->assertRedirect()->assertSessionHasNoErrors();
        $f = $fault->fresh();
        $this->assertSame(RentalFaultReport::APPROVAL_DECLINED, $f->owner_approval_status);

        $this->record($fault, ['note' => 'Third: yes after all'])->assertRedirect()->assertSessionHasNoErrors();
        $f = $fault->fresh();
        $this->assertSame(RentalFaultReport::APPROVAL_APPROVED, $f->owner_approval_status);
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $f->status);
        $this->assertNull($f->outcome, 'the automatic "owner declined" close was undone');

        $rows = RentalRecordedOwnerDecision::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(3, $rows, 'the full history is kept');
        $this->assertNotNull($rows[0]->superseded_at);
        $this->assertSame('recorded:' . $rows[1]->id, $rows[0]->superseded_by);
        $this->assertSame('recorded:' . $rows[2]->id, $rows[1]->superseded_by);
        $this->assertNull($rows[2]->superseded_at, 'only the last one stands');
        $this->assertSame(['First: go ahead', 'Second: changed his mind', 'Third: yes after all'], $rows->pluck('note')->all(), 'a recorded decision is never edited');
        $this->assertSame(3, $f->approvals()->count(), 'the evidence rows are append-only too');

        // a work order locks it
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $f), ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $this->supplier->id])->assertRedirect();
        $this->record($fault->fresh(), ['decision' => 'declined', 'note' => 'Too late'])->assertSessionHasErrors('owner_decision');
        $this->assertStringContainsString('locked', app(RentalOwnerDecisionService::class)->blockReason($fault->fresh()));
        $this->assertSame(3, RentalRecordedOwnerDecision::withoutGlobalScopes()->count());
    }

    public function test_the_owners_own_decision_replaces_a_recorded_one_until_a_work_order_exists(): void
    {
        $fault = $this->sentFault();
        $this->record($fault)->assertRedirect();
        $row = RentalRecordedOwnerDecision::withoutGlobalScopes()->firstOrFail();

        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}/decision", ['decision' => 'decline', 'note' => 'I never said that'])->assertOk();
        $f = $fault->fresh();
        $this->assertSame(RentalFaultReport::APPROVAL_DECLINED, $f->owner_approval_status);
        $this->assertSame('owner', $row->fresh()->superseded_by);
        $this->assertContains('owner_decision_superseded', $f->updates()->pluck('update_type')->all());
        $this->assertNull(app(RentalOwnerDecisionService::class)->ownerPayload($f), 'nothing recorded stands any more');
    }

    public function test_once_a_work_order_exists_the_owner_cannot_replace_the_recorded_fault_decision(): void
    {
        $fault = $this->sentFault();
        $this->record($fault)->assertRedirect();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault->fresh()), ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $this->supplier->id])->assertRedirect();

        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}/decision", ['decision' => 'decline', 'note' => 'late'])->assertStatus(422);
        $this->assertSame(RentalFaultReport::APPROVAL_APPROVED, $fault->fresh()->owner_approval_status);
        $show = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->json('fault_report');
        $this->assertFalse($show['recorded_decision']['can_override'], 'the "Decide for myself" button is gone once locked');
    }

    public function test_the_owners_own_decision_on_a_recorded_quote_decision_replaces_it(): void
    {
        $wo = $this->workOrderWithQuote(1800);
        $this->record($wo)->assertRedirect();
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $wo->fresh()->owner_approval_status);

        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/decision", ['decision' => 'decline', 'note' => 'No - too dear'])->assertOk();
        $this->assertSame(RentalWorkOrder::APPROVAL_DECLINED, $wo->fresh()->owner_approval_status);
        $this->assertSame('owner', RentalRecordedOwnerDecision::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->firstOrFail()->superseded_by);
    }

    // ───────────────────────── the owner's screens ─────────────────────────

    public function test_the_owner_can_query_a_recorded_decision_on_a_fault_and_on_a_work_order(): void
    {
        $fault = $this->sentFault();
        $this->record($fault)->assertRedirect();
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}/query", ['note' => 'I did not say that'])->assertStatus(201);
        $this->assertContains('owner_query', $fault->fresh()->updates()->pluck('update_type')->all());

        $wo = $this->workOrderWithQuote(1800);
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/query", ['note' => 'nothing recorded yet'])->assertStatus(422);   // nothing recorded on this one
        $this->record($wo)->assertRedirect();
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/query", ['note' => 'Please confirm the amount'])->assertStatus(201);
        $this->assertContains('owner_query', $wo->fresh()->updates()->pluck('update_type')->all());
    }

    public function test_the_work_order_payload_carries_the_recorded_sentence_for_the_owner(): void
    {
        $wo = $this->workOrderWithQuote(1800);
        $this->record($wo, ['told_via' => 'email'])->assertRedirect();
        Sanctum::actingAs($this->login($this->landlord), ['client']);
        $p = collect($this->getJson('/api/v1/client/rentals/landlord/work-orders')->assertOk()->json('work_orders'))->firstWhere('id', $wo->id);
        $rd = $p['client']['recorded_decision'];
        $this->assertNotNull($rd);
        $this->assertMatchesRegularExpression('/^Authorised by your agent .+ on your instruction, given by email on /', $rd['sentence']);
        $this->assertTrue($rd['can_override']);
    }

    // ───────────────────────── the switch, scope, the pages ─────────────────────────

    public function test_with_the_switch_off_nothing_can_be_recorded_by_any_route(): void
    {
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $this->agency->id], ['agents_may_record_owner_decisions' => false]);
        $this->assertFalse(RentalWorkOrderSetting::agentsMayRecordOwnerDecisionsFor($this->agency->id));
        $fault = $this->sentFault();

        $this->record($fault)->assertSessionHasErrors('owner_decision');
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.approval.store', $fault), ['decision' => 'approved', 'approval_route' => 'agency_appoints', 'evidence_type' => 'verbal_note', 'evidence_text' => 'x'])->assertSessionHasErrors();
        $this->assertSame(RentalFaultReport::APPROVAL_PENDING, $fault->fresh()->owner_approval_status);
        $this->assertSame(0, RentalRecordedOwnerDecision::withoutGlobalScopes()->count());

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->getContent();
        $this->assertStringNotContainsString('data-owner-decision-open', $html, 'no button');
    }

    public function test_the_switch_defaults_to_yes_and_both_new_settings_are_in_the_setup_wizard(): void
    {
        $this->assertTrue(RentalWorkOrderSetting::agentsMayRecordOwnerDecisionsFor($this->agency->id), 'default Yes');
        $keys = collect(config('agency-onboarding-copy.steps') ?? config('agency-onboarding-copy'))->flatten(3)->all();
        $flat = json_encode(config('agency-onboarding-copy'));
        $this->assertStringContainsString('emergency_spend_threshold', $flat);
        $this->assertStringContainsString('agents_may_record_owner_decisions', $flat);
        $this->assertStringContainsString('Repairs the agent may approve without asking the owner', $flat);
    }

    public function test_the_wizard_saver_writes_the_new_settings_and_never_wipes_the_ones_it_did_not_post(): void
    {
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $this->agency->id], ['variation_tolerance_percent' => 7, 'emergency_spend_threshold' => 2500]);
        $this->actingAs($this->agent)->post(route('corex.settings.rental-work-orders.approvals'), ['agents_may_record_owner_decisions' => '0'])->assertRedirect();
        $s = RentalWorkOrderSetting::withoutGlobalScopes()->where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertFalse((bool) $s->agents_may_record_owner_decisions);
        $this->assertEquals(2500.0, (float) $s->emergency_spend_threshold, 'untouched');
        $this->assertEquals(7.0, (float) $s->variation_tolerance_percent, 'untouched');

        $this->actingAs($this->agent)->post(route('corex.settings.rental-work-orders.approvals'), ['emergency_spend_threshold' => '3750.50'])->assertRedirect();
        $s = $s->fresh();
        $this->assertEquals(3750.5, (float) $s->emergency_spend_threshold);
        $this->assertFalse((bool) $s->agents_may_record_owner_decisions, 'untouched');
    }

    public function test_the_property_work_terms_save_the_emergency_limit_with_a_change_log(): void
    {
        $this->property->forceFill(['rental_emergency_spend_threshold' => null, 'listing_type' => 'rental', 'listing_type_pending' => false])->save();
        $this->actingAs($this->agent)->put(route('corex.properties.rental-work-terms.update', $this->property), ['emergency_limit' => '3500', 'agreed_with' => 'by email with the owner'])->assertRedirect();
        $this->assertEquals(3500.0, (float) $this->property->fresh()->rental_emergency_spend_threshold);
        $log = \App\Models\RentalPropertyWorkTermChange::withoutGlobalScopes()->where('property_id', $this->property->id)->where('field', 'emergency_limit')->firstOrFail();
        $this->assertEquals(3500.0, (float) $log->new_value);
        $this->assertNull($log->old_value);
        $this->assertSame($this->agent->id, $log->changed_by_user_id);
    }

    public function test_an_agent_of_another_agency_cannot_record_anything_here(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'ot-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Main', 'code' => 'O-' . $other->id, 'is_active' => true]);
        $stranger = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $fault = $this->sentFault();
        $res = $this->record($fault, [], $stranger);
        $this->assertContains($res->getStatusCode(), [403, 404]);
        $this->assertSame(0, RentalRecordedOwnerDecision::withoutGlobalScopes()->count());
        $this->assertSame(RentalFaultReport::APPROVAL_PENDING, $fault->fresh()->owner_approval_status);
    }

    public function test_a_closed_fault_or_one_not_yet_sent_cannot_have_a_decision_recorded(): void
    {
        $fault = $this->sentFault();
        $fault->forceFill(['sent_to_owner_at' => null, 'status' => RentalFaultReport::STATUS_UNDER_REVIEW, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED])->save();
        $this->assertStringContainsString('not been sent to the owner', app(RentalOwnerDecisionService::class)->blockReason($fault->fresh()));
        $this->record($fault)->assertSessionHasErrors('owner_decision');

        $closed = $this->sentFault();
        $closed->forceFill(['status' => RentalFaultReport::STATUS_CANCELLED])->save();
        $this->assertSame('This fault is closed.', app(RentalOwnerDecisionService::class)->blockReason($closed->fresh()));
    }

    public function test_a_property_with_no_owner_on_file_says_so_plainly(): void
    {
        $this->property->contacts()->detach($this->landlord->id);
        $fault = $this->sentFault();
        $this->assertStringContainsString('No owner is on file', app(RentalOwnerDecisionService::class)->blockReason($fault));
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->getContent();
        $this->assertStringContainsString('data-owner-decision-blocked', $html);
    }

    public function test_the_fault_page_has_the_popup_button_and_not_the_old_inline_forms(): void
    {
        $fault = $this->sentFault();
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString('data-owner-decision-open', $html);
        $this->assertStringContainsString("Record the owner's decision", $html);
        $this->assertStringContainsString('data-od-owner', $html);
        $this->assertStringContainsString('data-od-proof', $html);
        $this->assertStringNotContainsString('id="record-approval-form"', $html);
        $this->assertStringNotContainsString("Record decision on the owner's behalf", $html);
    }

    public function test_the_work_order_page_has_the_popup_the_emergency_declaration_and_no_old_forms(): void
    {
        $wo = $this->workOrderWithQuote(1800);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo->fresh()))->assertOk()->getContent();
        $this->assertStringNotContainsString('wo-approval-form', $html);
        $this->assertStringNotContainsString('Record owner\'s emergency approval', $html);
        $this->assertStringContainsString('data-declare-emergency-form', $html);
        $this->assertStringContainsString('data-emergency-reason', $html);

        $pending = $this->bareWorkOrder();
        $this->quote($pending, 1800);
        $page = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $pending->fresh()))->assertOk()->getContent();
        $this->assertStringContainsString('data-owner-decision-open', $page);
        $this->assertStringContainsString('Work cannot start yet', $page, 'the plain reason line for the disabled step');
    }

    public function test_the_recorded_decisions_are_listed_on_the_page_with_who_how_when_and_what_was_said(): void
    {
        $fault = $this->sentFault();
        $this->record($fault, ['note' => 'Call at lunch time: yes please'])->assertRedirect();
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault->fresh()))->getContent();
        $this->assertStringContainsString('data-owner-decision-history', $html);
        $this->assertStringContainsString('Call at lunch time: yes please', $html);
        $this->assertStringContainsString('this is the decision that stands', $html);
        $this->assertStringContainsString('Lenny Landlord', $html);
    }
}
