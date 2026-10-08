<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalLandlordDecisionNeededMail;
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
use App\Models\RentalApproval;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Models\RentalFaultType;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rentals fault-report flow (Johan 2026-10-08, F1-F7): a tenant (or agent) reports a fault; the OWNER sees nothing until
 * the agent has reviewed it, prepared the owner's version and sent it; the owner (portal link) or the agent (captured)
 * records ONE decision, with who appoints the contractor. Mail::fake() intercepts every send - no real address is used.
 */
class FaultOwnerFlowTest extends TestCase
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

        $this->agency = Agency::create(['name' => 'Fault Flow Agency', 'slug' => 'ff-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $branch->id,
            'title' => 'Flow Unit', 'status' => 'active', 'listing_type' => 'rental',
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

    // ── F1 + F2: reported by the tenant; the owner cannot see it yet ─────

    public function test_owner_cannot_see_an_unsent_fault_anywhere(): void
    {
        $fault = $this->tenantReports('Tenant words: the geyser is hissing and smells of gas');

        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $base = '/api/v1/client/rentals/landlord';

        $this->assertSame(RentalFaultReport::STATUS_REPORTED, $fault->status);
        $this->getJson("$base/fault-reports")->assertOk()->assertJsonCount(0, 'fault_reports');
        $this->getJson("$base/fault-reports/{$fault->id}")->assertNotFound();
        $this->getJson("$base/decisions")->assertOk()->assertJsonCount(0, 'fault_reports');
        $this->postJson("$base/fault-reports/{$fault->id}/decision", ['decision' => 'decline', 'note' => 'no'])->assertNotFound();
        Mail::assertNothingQueued();
        Mail::assertNothingSent();

        // 'under review' (version saved, NOT sent) is still invisible.
        $fault->saveOwnerVersion(['owner_title' => 'Geyser fault'], $this->agent);
        $this->assertSame(RentalFaultReport::STATUS_UNDER_REVIEW, $fault->fresh()->status);
        $this->getJson("$base/fault-reports")->assertOk()->assertJsonCount(0, 'fault_reports');
        $this->getJson("$base/fault-reports/{$fault->id}")->assertNotFound();
        Mail::assertNothingQueued();
    }

    public function test_status_walks_reported_under_review_sent_owner_decided_and_history_records_each_step(): void
    {
        $fault = $this->tenantReports('Dripping tap');
        $this->assertSame('Reported', $fault->statusLabel());

        $fault->saveOwnerVersion(['owner_title' => 'Dripping tap'], $this->agent);
        $this->assertSame('Under agent review', $fault->fresh()->statusLabel());

        $fault->fresh()->requestApproval($this->agent);
        $fault = $fault->fresh();
        $this->assertSame('Sent to owner', $fault->statusLabel());
        $this->assertNotNull($fault->sent_to_owner_at);
        $this->assertSame($this->agent->id, $fault->sent_to_owner_by_user_id);

        $fault->recordApproval($this->agent, ['decision' => 'approved', 'approval_route' => 'agency_appoints', 'evidence_type' => 'verbal_note', 'evidence_text' => 'Phoned.']);
        $this->assertStringStartsWith('Owner decided', $fault->fresh()->statusLabel());

        $actions = $fault->fresh()->history()->pluck('action')->all();
        $this->assertContains('Owner version prepared', $actions);
        $this->assertContains('Sent to owner for a decision', $actions);
        $this->assertContains('Approved', $actions);
    }

    // ── F2: sanitised version vs original ────────────────────────────────

    public function test_owner_sees_only_the_agents_version_and_the_tenants_original_is_untouched(): void
    {
        $fault = $this->tenantReports('RAW tenant wording: landlord is useless, fix the damn pipe', 'RAW long rant about the landlord');
        $keep = RentalFaultReportPhoto::create(['agency_id' => $this->agency->id, 'rental_fault_report_id' => $fault->id, 'storage_path' => '/storage/test/pipe.jpg']);
        $hide = RentalFaultReportPhoto::create(['agency_id' => $this->agency->id, 'rental_fault_report_id' => $fault->id, 'storage_path' => '/storage/test/tenant-bedroom.jpg']);

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.owner-version.store', $fault), [
            'owner_title' => 'Leaking pipe under the sink',
            'owner_description' => 'Water is leaking from the pipe under the kitchen sink.',
            'owner_agent_note' => 'I recommend approving - a plumber can come this week.',
            'owner_photo_ids' => [$keep->id],
        ])->assertRedirect();

        // The tenant's original is exactly as reported.
        $fault = $fault->fresh();
        $this->assertStringContainsString('RAW tenant wording', $fault->title);
        $this->assertSame('RAW long rant about the landlord', $fault->description);
        $this->assertSame(2, $fault->photos()->count());

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-owner', $fault))->assertRedirect();

        Mail::assertQueued(RentalLandlordDecisionNeededMail::class, function ($mail) {
            return $mail->hasTo($this->landlord->email) && $mail->title === 'Leaking pipe under the sink';
        });

        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $detail = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->assertOk();
        $detail->assertJsonPath('fault_report.title', 'Leaking pipe under the sink')
            ->assertJsonPath('fault_report.agent_note', 'I recommend approving - a plumber can come this week.')
            ->assertJsonPath('fault_report.awaiting_decision', true)
            ->assertJsonCount(1, 'fault_report.photos')
            ->assertJsonPath('fault_report.photos.0.url', '/storage/test/pipe.jpg');

        // Nowhere in the owner's payloads: the tenant's words or the photo the agent did not share.
        foreach (["/fault-reports/{$fault->id}", '/fault-reports', '/decisions'] as $path) {
            $raw = $this->getJson('/api/v1/client/rentals/landlord' . $path)->assertOk()->getContent();
            $this->assertStringNotContainsString('RAW', $raw);
            $this->assertStringNotContainsString('tenant-bedroom', $raw);
        }
        $this->assertSame($hide->id, $fault->photos()->orderByDesc('id')->value('id'), 'the unshared photo still exists on the original record');
    }

    public function test_the_version_cannot_be_changed_after_it_is_sent_and_a_report_cannot_be_sent_twice(): void
    {
        $fault = $this->tenantReports('Door lock stuck');
        $fault->saveOwnerVersion(['owner_title' => 'Door lock'], $this->agent);
        $fault->fresh()->requestApproval($this->agent);

        $this->expectException(\LogicException::class);
        try {
            $fault->fresh()->saveOwnerVersion(['owner_title' => 'Changed after sending'], $this->agent);
        } finally {
            $this->assertSame('Door lock', $fault->fresh()->owner_title);
            try {
                $fault->fresh()->requestApproval($this->agent);
                $this->fail('a second send must be refused');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('already been sent', $e->getMessage());
            }
        }
    }

    public function test_the_agent_must_review_before_sending_from_the_screen(): void
    {
        $fault = $this->tenantReports('Window broken');

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-owner', $fault))
            ->assertSessionHasErrors('rental_fault_report');
        $this->assertNull($fault->fresh()->sent_to_owner_at);
        Mail::assertNothingQueued();
    }

    public function test_the_tenants_report_cannot_be_edited_in_place(): void
    {
        $fault = $this->tenantReports('Original evidence');

        $this->actingAs($this->agent)->put(route('corex.rental-fault-reports.update', $fault), ['title' => 'Edited', 'description' => 'Edited'])
            ->assertStatus(409);
        $this->assertSame('Original evidence', $fault->fresh()->title);
    }

    // ── F3 / F4: the owner decides on the link ───────────────────────────

    public function test_owner_approves_with_their_own_contractor_name_and_phone_optional(): void
    {
        $fault = $this->sentFault();
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);

        $this->postJson($this->decisionUrl($fault), [
            'decision' => 'approve', 'handled_by' => 'own', 'contractor_name' => 'Joe the plumber', 'contractor_phone' => '082 111 2222',
        ])->assertOk()->assertJsonPath('fault_report.owner_approval_status', 'approved');

        $approval = RentalApproval::where('rental_fault_report_id', $fault->id)->sole();
        $this->assertSame('own', $approval->contractor_source);
        $this->assertSame('Joe the plumber', $approval->contractor_name);
        $this->assertSame('082 111 2222', $approval->contractor_phone);
        $this->assertSame(RentalFaultReport::ROUTE_OWNER_HANDLES, $approval->approval_route);
        $this->assertSame(RentalApproval::EVIDENCE_PORTAL, $approval->evidence_type);
        $this->assertSame($this->landlord->id, $approval->recorded_by_contact_id);
        $this->assertSame(RentalFaultReport::STATUS_OWNER_HANDLING, $fault->fresh()->status);

        // Name and phone really are optional.
        $second = $this->sentFault('Second fault');
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $this->postJson($this->decisionUrl($second), ['decision' => 'approve', 'handled_by' => 'own'])->assertOk();
        $this->assertNull(RentalApproval::where('rental_fault_report_id', $second->id)->sole()->contractor_name);
    }

    public function test_owner_approves_with_a_contractor_from_the_list_for_that_type_of_work(): void
    {
        $plumber = $this->supplier('Acme Plumbing', 'Plumbing');
        $electrician = $this->supplier('Spark Electrical', 'Electrical');
        $fault = $this->sentFault();

        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $detail = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->assertOk();
        $ids = collect($detail->json('fault_report.contractors'))->pluck('id')->all();
        $this->assertSame([$plumber->id], $ids, 'only suppliers set up for this type of work (plumbing) are offered');

        // A supplier that is not on the list for this work is refused.
        $this->postJson($this->decisionUrl($fault), ['decision' => 'approve', 'handled_by' => 'list', 'agency_service_provider_id' => $electrician->id])
            ->assertStatus(422);
        $this->postJson($this->decisionUrl($fault), ['decision' => 'approve', 'handled_by' => 'list'])->assertStatus(422);

        $this->postJson($this->decisionUrl($fault), ['decision' => 'approve', 'handled_by' => 'list', 'agency_service_provider_id' => $plumber->id])->assertOk();

        $approval = RentalApproval::where('rental_fault_report_id', $fault->id)->sole();
        $this->assertSame('agency', $approval->contractor_source);
        $this->assertSame($plumber->id, $approval->agency_service_provider_id);
        $this->assertSame(RentalFaultReport::ROUTE_AGENCY_APPOINTS, $approval->approval_route);
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $fault->fresh()->status);
    }

    public function test_an_empty_contractor_list_is_handled_and_the_owner_can_still_decide(): void
    {
        $fault = $this->sentFault();   // no suppliers exist at all
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);

        $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")
            ->assertOk()->assertJsonCount(0, 'fault_report.contractors');
        $this->postJson($this->decisionUrl($fault), ['decision' => 'approve', 'handled_by' => 'list', 'agency_service_provider_id' => 999])->assertStatus(422);

        // The way out: let the agent arrange it.
        $this->postJson($this->decisionUrl($fault), ['decision' => 'approve', 'handled_by' => 'agency'])->assertOk();
        $approval = RentalApproval::where('rental_fault_report_id', $fault->id)->sole();
        $this->assertNull($approval->contractor_source);
        $this->assertSame(RentalFaultReport::ROUTE_AGENCY_APPOINTS, $approval->approval_route);
    }

    public function test_declining_needs_a_reason_and_approving_needs_a_route(): void
    {
        $fault = $this->sentFault();
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);

        $this->postJson($this->decisionUrl($fault), ['decision' => 'decline'])->assertStatus(422);
        $this->postJson($this->decisionUrl($fault), ['decision' => 'decline', 'note' => '   '])->assertStatus(422);
        $this->postJson($this->decisionUrl($fault), ['decision' => 'approve'])->assertStatus(422);
        $this->assertSame(0, RentalApproval::where('rental_fault_report_id', $fault->id)->count());

        $this->postJson($this->decisionUrl($fault), ['decision' => 'decline', 'note' => 'Tenant caused it.'])->assertOk();
        $approval = RentalApproval::where('rental_fault_report_id', $fault->id)->sole();
        $this->assertSame('declined', $approval->decision);
        $this->assertSame('Tenant caused it.', $approval->evidence_text);
        $this->assertNull($approval->contractor_source);
        $this->assertSame(RentalFaultReport::STATUS_DECLINED, $fault->fresh()->status);
    }

    // ── F5 / F6: the agent records the decision ──────────────────────────

    public function test_agent_captures_the_decision_with_the_owners_own_contractor(): void
    {
        $fault = $this->sentFault();

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.approval.store', $fault), [
            'decision' => 'approved', 'approval_route' => 'owner_handles', 'contractor_name' => 'Owner Bob', 'contractor_phone' => '083 000 1111',
            'evidence_type' => 'verbal_note', 'evidence_text' => 'Owner phoned, will use his own man.',
        ])->assertRedirect(route('corex.rental-fault-reports.show', $fault));

        $approval = RentalApproval::where('rental_fault_report_id', $fault->id)->sole();
        $this->assertSame('own', $approval->contractor_source);
        $this->assertSame('Owner Bob', $approval->contractor_name);
        $this->assertSame($this->agent->id, $approval->recorded_by_user_id);
        $this->assertSame(RentalFaultReport::STATUS_OWNER_HANDLING, $fault->fresh()->status);
    }

    public function test_agent_captures_the_decision_with_an_agency_contractor_and_can_hand_over_to_work_order_creation(): void
    {
        $plumber = $this->supplier('Acme Plumbing', 'Plumbing');
        $other = $this->supplier('Spark Electrical', 'Electrical');
        $fault = $this->sentFault();

        // A supplier outside the list for this type of work is refused.
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.approval.store', $fault), [
            'decision' => 'approved', 'approval_route' => 'agency_appoints', 'agency_service_provider_id' => $other->id,
            'evidence_type' => 'email', 'evidence_text' => 'x',
        ])->assertSessionHasErrors('rental_fault_report');
        $this->assertSame(0, RentalApproval::where('rental_fault_report_id', $fault->id)->count());

        $res = $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.approval.store', $fault), [
            'decision' => 'approved', 'approval_route' => 'agency_appoints', 'agency_service_provider_id' => $plumber->id,
            'evidence_type' => 'email', 'evidence_text' => 'Owner emailed: go ahead with Acme.', 'after' => 'create_work_order',
        ]);
        $res->assertRedirect(route('corex.rental-fault-reports.show', ['rentalFaultReport' => $fault, 'create_work_order' => 1]));

        $approval = RentalApproval::where('rental_fault_report_id', $fault->id)->sole();
        $this->assertSame('agency', $approval->contractor_source);
        $this->assertSame($plumber->id, $approval->agency_service_provider_id);
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $fault->fresh()->status);
        $this->assertNull($fault->fresh()->rental_work_order_id, '"Save decision and create work order" only HANDS OVER; it creates nothing itself');

        // The existing work-order form opens pre-filled with the chosen contractor...
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', ['rentalFaultReport' => $fault, 'create_work_order' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('name="agency_service_provider_id" value="' . $plumber->id . '"', $html);
        $this->assertStringContainsString('Acme Plumbing', $html);

        // ...and the existing creation takes it through.
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'assignment_type' => 'outside_supplier', 'title' => 'Fix the tap', 'description' => 'Leaking tap', 'agency_service_provider_id' => $plumber->id,
        ])->assertRedirect();
        $workOrder = $fault->fresh()->workOrder;
        $this->assertNotNull($workOrder);
        $this->assertSame($plumber->id, $workOrder->agency_service_provider_id);
        $this->assertSame(\App\Models\RentalWorkOrder::STATUS_REPORTED, $workOrder->status, 'pre-selected, not ordered: the usual quote/authorisation steps still apply');
    }

    public function test_the_hand_over_button_now_works_on_the_owners_own_contractor_route_and_not_on_a_decline(): void
    {
        // W5 (8 Oct 2026): "Save decision and create work order" is available on EVERY approved route.
        $fault = $this->sentFault();
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.approval.store', $fault), [
            'decision' => 'approved', 'approval_route' => 'owner_handles', 'evidence_type' => 'verbal_note', 'evidence_text' => 'ok', 'after' => 'create_work_order',
        ])->assertRedirect(route('corex.rental-fault-reports.show', ['rentalFaultReport' => $fault, 'create_work_order' => 1]));
        $this->assertNull($fault->fresh()->workOrderBlockReason());

        $declined = $this->sentFault('Another fault');
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.approval.store', $declined), [
            'decision' => 'declined', 'evidence_type' => 'verbal_note', 'evidence_text' => 'no', 'after' => 'create_work_order',
        ])->assertRedirect(route('corex.rental-fault-reports.show', $declined));
        $this->assertNotNull($declined->fresh()->workOrderBlockReason());
    }

    public function test_agent_declining_needs_a_reason(): void
    {
        $fault = $this->sentFault();

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.approval.store', $fault), [
            'decision' => 'declined', 'evidence_type' => 'verbal_note', 'evidence_text' => '   ',
        ])->assertSessionHasErrors();
        $this->assertSame(0, RentalApproval::where('rental_fault_report_id', $fault->id)->count());

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.approval.store', $fault), [
            'decision' => 'declined', 'evidence_type' => 'verbal_note', 'evidence_text' => 'Owner says no - tenant damage.',
        ])->assertRedirect();
        $this->assertSame('declined', RentalApproval::where('rental_fault_report_id', $fault->id)->sole()->decision);
    }

    // ── F7: one decision, shown read-only on the other side ──────────────

    public function test_a_second_decision_is_blocked_from_either_side(): void
    {
        // Owner first, then the agent.
        $fault = $this->sentFault();
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $this->postJson($this->decisionUrl($fault), ['decision' => 'approve', 'handled_by' => 'agency'])->assertOk();

        $this->actingAs($this->agent, 'web')->post(route('corex.rental-fault-reports.approval.store', $fault), [
            'decision' => 'declined', 'evidence_type' => 'verbal_note', 'evidence_text' => 'changed my mind',
        ])->assertSessionHasErrors('rental_fault_report');
        $this->assertSame(1, RentalApproval::where('rental_fault_report_id', $fault->id)->count());
        $this->assertSame('approved', $fault->fresh()->owner_approval_status);

        // Agent first, then the owner.
        $other = $this->sentFault('Another fault');
        $other->recordApproval($this->agent, ['decision' => 'declined', 'evidence_type' => 'whatsapp', 'evidence_text' => 'No budget.']);
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $this->postJson($this->decisionUrl($other), ['decision' => 'approve', 'handled_by' => 'agency'])->assertStatus(422);
        $this->assertSame(1, RentalApproval::where('rental_fault_report_id', $other->id)->count());

        // The model refuses a second row outright and says who decided.
        try {
            $other->fresh()->recordApproval($this->agent, ['decision' => 'approved', 'approval_route' => 'agency_appoints', 'evidence_type' => 'email', 'evidence_text' => 'x']);
            $this->fail('a second decision must be refused');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('already been recorded', $e->getMessage());
            $this->assertStringContainsString('captured by the agent', $e->getMessage());
        }
    }

    public function test_the_other_side_shows_the_decision_read_only_with_who_how_and_when(): void
    {
        // Decided by the owner on the link: the AGENT screen shows it read-only.
        $fault = $this->sentFault();
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $this->postJson($this->decisionUrl($fault), ['decision' => 'approve', 'handled_by' => 'own', 'contractor_name' => 'Owner Bob', 'contractor_phone' => '0830001111'])->assertOk();

        $html = $this->actingAs($this->agent, 'web')->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString('Decided by the owner on the portal link', $html);
        $this->assertStringContainsString('Lenny Landlord', $html);
        $this->assertStringContainsString("Owner&#039;s own contractor: Owner Bob (0830001111)", $html);
        $this->assertStringNotContainsString('id="record-approval-form"', $html, 'once decided the record form is gone');

        // Decided by the agent: the OWNER's detail shows it read-only.
        $captured = $this->sentFault('Captured one');
        $this->actingAs($this->agent, 'web')->post(route('corex.rental-fault-reports.approval.store', $captured), [
            'decision' => 'declined', 'evidence_type' => 'verbal_note', 'evidence_text' => 'Owner declined by phone.',
        ])->assertRedirect();

        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $detail = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$captured->id}")->assertOk();
        $detail->assertJsonPath('fault_report.awaiting_decision', false)
            ->assertJsonPath('fault_report.decision.decision', 'declined')
            ->assertJsonPath('fault_report.decision.via_link', false)
            ->assertJsonPath('fault_report.decision.reason', 'Owner declined by phone.');
        $this->assertStringContainsString('Captured by the agent', $detail->json('fault_report.decision.how'));
        $this->assertSame($this->agent->name, $detail->json('fault_report.decision.by'));
    }

    // ── F1 (agent) + scoping ─────────────────────────────────────────────

    public function test_an_agent_can_report_a_fault_and_it_goes_through_the_same_review_before_the_owner_sees_it(): void
    {
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.store'), [
            'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'reported_by_type' => 'agent_noticed', 'reported_channel' => 'phone', 'title' => 'Gate motor jammed', 'description' => 'Seen on inspection.',
        ]);
        $fault = RentalFaultReport::withoutGlobalScopes()->where('title', 'Gate motor jammed')->firstOrFail();
        $this->assertSame(RentalFaultReport::STATUS_REPORTED, $fault->status);

        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->assertNotFound();
    }

    public function test_owners_of_other_properties_and_other_agencies_see_and_decide_nothing(): void
    {
        $stranger = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id,
            'first_name' => 'Sam', 'last_name' => 'Stranger', 'email' => 'sam+' . uniqid() . '@example.com',
        ]);
        $otherProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->property->branch_id,
            'title' => 'Other Unit', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $otherProperty->contacts()->attach($stranger->id, ['role' => 'landlord']);

        $fault = $this->sentFault();
        Sanctum::actingAs($this->clientUserFor($stranger), ['client']);
        $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->assertNotFound();
        $this->postJson($this->decisionUrl($fault), ['decision' => 'decline', 'note' => 'x'])->assertNotFound();
        $this->getJson('/api/v1/client/rentals/landlord/fault-reports')->assertOk()->assertJsonCount(0, 'fault_reports');
    }

    public function test_a_supplier_of_another_agency_cannot_be_chosen(): void
    {
        $foreign = Agency::create(['name' => 'Other Agency', 'slug' => 'oa-' . uniqid()]);
        $foreignSupplier = $this->supplier('Foreign Plumbing', 'Plumbing', $foreign);
        $fault = $this->sentFault();

        $this->expectException(\InvalidArgumentException::class);
        $fault->recordApproval($this->agent, [
            'decision' => 'approved', 'approval_route' => 'agency_appoints', 'agency_service_provider_id' => $foreignSupplier->id,
            'evidence_type' => 'email', 'evidence_text' => 'x',
        ]);
    }

    public function test_agent_scope_own_hides_another_agents_fault(): void
    {
        $fault = $this->sentFault();
        $otherAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id, 'role' => 'agent']);

        $this->assertTrue(RentalFaultReport::visibleTo($this->agent)->whereKey($fault->id)->exists());
        $this->assertFalse(RentalFaultReport::visibleTo($otherAgent, 'own')->whereKey($fault->id)->exists());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** A tenant reports through the portal API (F1) - lands as 'reported', invisible to the owner. */
    private function tenantReports(string $title, string $description = 'Description as the tenant wrote it'): RentalFaultReport
    {
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
        $res = $this->postJson('/api/v1/client/rentals/properties/' . $this->property->id . '/fault-reports', [
            'rental_fault_type_id' => $this->faultType->id, 'resolution' => 'still_a_problem', 'title' => $title, 'description' => $description,
        ])->assertStatus(201);

        return RentalFaultReport::withoutGlobalScopes()->findOrFail($res->json('fault_report.id'));
    }

    /** A fault the agent has reviewed and SENT to the owner (visible, waiting for the decision). */
    private function sentFault(string $title = 'Leaking tap in the kitchen'): RentalFaultReport
    {
        $fault = $this->tenantReports($title);
        $fault->saveOwnerVersion(['owner_title' => $title, 'owner_description' => 'Agent-checked description.'], $this->agent);
        $fault->fresh()->requestApproval($this->agent);

        return $fault->fresh();
    }

    private function decisionUrl(RentalFaultReport $fault): string
    {
        return "/api/v1/client/rentals/landlord/fault-reports/{$fault->id}/decision";
    }

    private function supplier(string $name, string $tradeLabel, ?Agency $agency = null): AgencyServiceProvider
    {
        $agency ??= $this->agency;
        $type = AgencyServiceType::withoutGlobalScopes()->firstOrCreate(
            ['agency_id' => $agency->id, 'code' => $tradeLabel],
            ['label' => $tradeLabel, 'sort_order' => 1, 'is_active' => true]
        );
        $provider = AgencyServiceProvider::withoutGlobalScopes()->create(['agency_id' => $agency->id, 'name' => $name, 'phone' => '0315550000', 'is_active' => true, 'specialty' => 'other']);
        AgencyServiceProviderServiceType::withoutGlobalScopes()->create(['agency_id' => $agency->id, 'service_provider_id' => $provider->id, 'service_type' => $type->code]);

        return $provider;
    }

    private function clientUserFor(Contact $contact): ClientUser
    {
        $clientUser = ClientUser::firstOrCreate(['email' => $contact->email], ['current_agency_id' => $contact->agency_id]);
        $contact->forceFill(['client_user_id' => $clientUser->id])->saveQuietly();

        return $clientUser;
    }
}
