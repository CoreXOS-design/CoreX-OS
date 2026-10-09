<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalLandlordDecisionNeededMail;
use App\Mail\Rentals\RentalTenantStatusChangeMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Rentals\RentalFaultProgressService as Progress;
use App\Services\Rentals\RentalFaultReportService;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Johan's QA1 test of 8 Oct 2026, round 3: the owner's fault screen (no Decisions tab; the decision lives on the fault), the
 * email that opens THAT fault, the agent-reported fault staying invisible until sent, both lease agents told about a new fault,
 * the progress line the tenant (and owner) follow, and the tenant mails at each step. Mail::fake() intercepts every send.
 */
class OwnerFaultScreenTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->agency = Agency::create(['name' => 'Owner Screen Agency', 'slug' => 'os-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Screen Unit', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.com',
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.com',
        ]);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->faultType = RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine',
            'first_aid_steps' => 'Close the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    // ── Why the owner saw no tabs at all ─────────────────────────────────────────────────────

    public function test_a_portal_login_with_no_agency_selected_adopts_its_only_agency_instead_of_answering_409(): void
    {
        // The state Johan hit: the login exists (linked to the owner's contact) but current_agency_id was never set.
        $login = ClientUser::create(['email' => $this->landlord->email]);
        $this->landlord->forceFill(['client_user_id' => $login->id])->saveQuietly();
        $this->assertNull($login->fresh()->current_agency_id);

        Sanctum::actingAs($login, ['client']);
        $this->getJson('/api/v1/client/rentals/landlord/properties')->assertOk()->assertJsonCount(1, 'properties');
        $this->assertSame($this->agency->id, $login->fresh()->current_agency_id, 'the single agency is remembered');
    }

    public function test_a_login_created_by_the_lease_portal_link_starts_in_its_agency(): void
    {
        $result = app(\App\Services\Rentals\RentalPortalAccessService::class)->attach($this->landlord, null, $this->agent);

        $this->assertSame($this->agency->id, $result['client_user']->fresh()->current_agency_id);
    }

    // ── F2 for an AGENT-reported fault: nothing to the owner until the agent sends it ───────

    public function test_an_agent_reported_fault_sends_the_owner_nothing_and_is_invisible_until_the_agent_sends_it(): void
    {
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.store'), [
            'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'reported_by_type' => 'agent_noticed', 'reported_channel' => 'phone', 'title' => 'Gate motor jammed', 'description' => 'Seen on a visit.',
        ]);
        $fault = RentalFaultReport::withoutGlobalScopes()->where('title', 'Gate motor jammed')->firstOrFail();

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $this->getJson('/api/v1/client/rentals/landlord/fault-reports')->assertOk()->assertJsonCount(0, 'fault_reports');
        $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->assertNotFound();

        // Saving the owner version is still not a send.
        $fault->saveOwnerVersion(['owner_title' => 'Gate motor'], $this->agent);
        Mail::assertNothingQueued();
        $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->assertNotFound();

        // The send: ONE email, whose button opens THAT fault.
        $this->actingAs($this->agent, 'web')->post(route('corex.rental-fault-reports.send-to-owner', $fault))->assertRedirect();
        Mail::assertQueued(RentalLandlordDecisionNeededMail::class, 1);
        Mail::assertQueued(RentalLandlordDecisionNeededMail::class, function ($m) use ($fault) {
            return $m->hasTo($this->landlord->email)
                && str_contains($m->portalUrl, '/portal?')
                && str_contains($m->portalUrl, 'fault=' . $fault->id)
                // who it is for: the signed recipient reference (App\Support\PortalLink), and the side it opens in
                && \App\Support\PortalLink::parse(self::queryOf($m->portalUrl)['r'] ?? null)['email'] === strtolower($this->landlord->email)
                && (self::queryOf($m->portalUrl)['as'] ?? null) === 'owner';
        });
        $html = (new RentalLandlordDecisionNeededMail($fault->fresh(), 'Lenny', $this->landlord->email))->render();
        $this->assertStringContainsString('Review and decide', $html);
        $this->assertStringContainsString('fault=' . $fault->id, $html);
    }

    // ── The owner's fault screen ─────────────────────────────────────────────────────────────

    public function test_the_fault_waiting_for_the_owner_is_flagged_in_the_list_and_opens_with_everything_to_decide(): void
    {
        $fault = $this->sentFault('Kitchen tap');
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);

        $rows = $this->getJson('/api/v1/client/rentals/landlord/fault-reports')->assertOk()->json('fault_reports');
        $row = collect($rows)->firstWhere('id', $fault->id);
        $this->assertTrue($row['needs_decision']);

        $detail = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->assertOk()->json('fault_report');
        $this->assertTrue($detail['awaiting_decision']);
        $this->assertSame('Kitchen tap', $detail['title']);
        $this->assertArrayHasKey('contractors', $detail);
        $this->assertSame('sent_to_owner', $detail['progress']['current']);
        $this->assertSame('Sent to you for approval', $detail['progress']['current_label']);

        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}/decision", ['decision' => 'approve', 'handled_by' => 'agency'])->assertOk();

        // After deciding: read-only, no longer flagged, the progress moved on.
        $rows = $this->getJson('/api/v1/client/rentals/landlord/fault-reports')->json('fault_reports');
        $this->assertFalse(collect($rows)->firstWhere('id', $fault->id)['needs_decision']);
        $detail = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->json('fault_report');
        $this->assertFalse($detail['awaiting_decision']);
        $this->assertSame('approved', $detail['decision']['decision']);
        $this->assertSame('You approved', $detail['progress']['current_label']);
    }

    public function test_once_a_work_order_exists_the_fault_links_to_it_with_its_status_and_appointment(): void
    {
        $fault = $this->approvedFault('owner_handles', ['contractor_name' => 'Owner Bob']);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'assignment_type' => 'owner_contractor', 'title' => 'Fix the tap', 'description' => 'x', 'contractor_name' => 'Owner Bob',
        ]);
        $wo = $fault->fresh()->workOrder;
        app(RentalWorkOrderService::class)->setAppointment($wo, now()->addDays(2)->setTime(9, 0), null, $this->agent);

        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $detail = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}")->assertOk()->json('fault_report');
        $this->assertSame($wo->id, $detail['work_order']['id']);
        $this->assertSame('appointment_set', $detail['work_order']['stage']);
        $this->assertNotNull($detail['work_order']['appointment_at']);
        $row = collect($this->getJson('/api/v1/client/rentals/landlord/fault-reports')->json('fault_reports'))->firstWhere('id', $fault->id);
        $this->assertSame($wo->id, $row['work_order_id']);
        $this->assertSame('appointment_set', $detail['progress']['current']);
    }

    /** @return array<string, string> */
    private static function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return $q;
    }

    // ── The portal page: no Decisions tab; Faults and Work orders ───────────────────────────

    public function test_the_portal_has_no_decisions_tab_and_names_the_tabs_faults_then_work_orders(): void
    {
        $html = $this->get('/portal')->assertOk()->getContent();

        $this->assertStringNotContainsString('Decisions</button>', $html);
        $this->assertStringNotContainsString("landlordTab === 'decisions'", $html);
        $this->assertStringNotContainsString('Jobs</button>', $html);
        $this->assertSame(2, substr_count($html, 'Work orders<span class="count"'), 'owner and tenant both have a Work orders tab with a count');
        $this->assertStringContainsString('data-faults-count', $html);
        $this->assertStringContainsString('data-workorders-count', $html);

        // The fault is the primary route: the needs-your-decision block, the fault card with the decision controls, and the deep link.
        $this->assertStringContainsString('data-needs-decision', $html);
        $this->assertStringContainsString('data-fault-detail', $html);
        $this->assertStringContainsString('submitFaultDecision()', $html);
        $this->assertStringContainsString("get('fault')", $html);
        // Other owner decisions moved onto their work order (quotes, extra work), not left in a separate list.
        $this->assertStringContainsString('data-wo-decision', $html);
        $this->assertStringContainsString('data-variation-card', $html);
        $this->assertStringContainsString('variationsFor(w.id)', $html);
        // The tenant's faults carry the progress line; the fault card links to its work order.
        $this->assertStringContainsString('data-tenant-fault', $html);
        $this->assertStringContainsString('data-progress', $html);
    }

    public function test_owner_decisions_that_used_the_decisions_tab_stay_reachable_on_their_records(): void
    {
        $wo = $this->agencyWorkOrderAwaitingOwner();
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);

        // The work-order list the Work orders tab reads carries the pending quote on that work order...
        $row = collect($this->getJson('/api/v1/client/rentals/landlord/work-orders')->assertOk()->json('work_orders'))->firstWhere('id', $wo->id);
        $this->assertSame('pending', $row['client']['owner_approval_status']);
        $this->assertSame('Needs your decision', $row['client']['stage_label']);
        // ...and the approve / decline endpoints are unchanged.
        $this->getJson('/api/v1/client/rentals/landlord/decisions')->assertOk()->assertJsonPath('work_orders.0.id', $wo->id);
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/decision", ['decision' => 'approve'])->assertOk();
        $this->assertSame('approved', $wo->fresh()->owner_approval_status);
    }

    // ── Who is told about a NEW fault (item 6) ───────────────────────────────────────────────

    public function test_a_new_fault_tells_the_tenant_side_and_owner_side_agent_once_each(): void
    {
        $ownerAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $tenantAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->lease->forceFill(['owner_agent_user_id' => $ownerAgent->id, 'tenant_agent_user_id' => $tenantAgent->id])->saveQuietly();

        $told = $this->reportAndCollectRecipients();
        $this->assertEqualsCanonicalizing([$ownerAgent->id, $tenantAgent->id], $told);

        // One person on both sides: told once.
        $this->lease->forceFill(['owner_agent_user_id' => $ownerAgent->id, 'tenant_agent_user_id' => $ownerAgent->id])->saveQuietly();
        $this->assertSame([$ownerAgent->id], $this->reportAndCollectRecipients());
    }

    public function test_a_new_fault_never_goes_to_nobody_the_fallback_chain(): void
    {
        $dead = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => false]);
        $this->lease->forceFill(['owner_agent_user_id' => $dead->id, 'tenant_agent_user_id' => $dead->id])->saveQuietly();

        // No active lease agent: the property's agent.
        $this->assertSame([$this->agent->id], $this->reportAndCollectRecipients());

        // The property's agent is gone too: the branch manager.
        User::withoutGlobalScopes()->whereKey($this->agent->id)->update(['is_active' => false]);
        $manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager']);
        $this->assertSame([$manager->id], $this->reportAndCollectRecipients());

        // No manager: the branch's office admin.
        User::withoutGlobalScopes()->whereKey($manager->id)->update(['is_active' => false]);
        $office = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'office_admin']);
        $this->assertSame([$office->id], $this->reportAndCollectRecipients());

        // No office admin: the agency's admin.
        User::withoutGlobalScopes()->whereKey($office->id)->update(['is_active' => false]);
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'admin']);
        $this->assertSame([$admin->id], $this->reportAndCollectRecipients());
    }

    public function test_the_owner_reporting_a_fault_tells_the_same_people(): void
    {
        $ownerAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->lease->forceFill(['owner_agent_user_id' => $ownerAgent->id, 'tenant_agent_user_id' => $ownerAgent->id])->saveQuietly();
        $ids = [];
        $mock = \Mockery::mock(NotificationDispatcher::class);
        $mock->shouldReceive('fire')->andReturnUsing(function ($user, $key) use (&$ids) {
            $key === 'rental_fault_report.created' && $ids[] = $user->id;

            return true;
        });
        $this->app->instance(NotificationDispatcher::class, $mock);

        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $this->postJson('/api/v1/client/rentals/landlord/properties/' . $this->property->id . '/fault-reports', ['title' => 'Fence down', 'description' => 'x'])->assertStatus(201);

        $this->assertSame([$ownerAgent->id], $ids);
    }

    // ── The progress line (fault AND work order, derived from the real records) ──────────────

    public function test_progress_follows_the_real_state_of_the_fault_step_by_step(): void
    {
        $fault = $this->tenantFault('Dripping tap');
        $p = $this->progress($fault);
        $this->assertSame('sent_to_agent', $p['current']);
        $this->assertSame('Sent to your agent', $p['current_label']);
        $this->assertSame(['done', 'todo', 'todo', 'todo', 'todo', 'todo', 'todo', 'todo'], array_column($p['steps'], 'state'));
        $this->assertNotNull($p['steps'][0]['at'], 'every reached step shows its date');
        $this->assertNull($p['steps'][1]['at']);

        $fault->saveOwnerVersion(['owner_title' => 'Dripping tap'], $this->agent);
        $this->assertSame('Agent reviewing', $this->progress($fault->fresh())['current_label']);

        $fault->fresh()->requestApproval($this->agent);
        $p = $this->progress($fault->fresh());
        $this->assertSame('Sent to owner for approval', $p['current_label']);
        $this->assertNotNull($p['steps'][2]['at']);

        $fault->fresh()->recordApproval($this->landlord, ['decision' => 'approved', 'approval_route' => 'agency_appoints', 'evidence_type' => 'portal']);
        $p = $this->progress($fault->fresh());
        $this->assertSame('Owner approved', $p['current_label']);
        $this->assertNotNull($p['steps'][3]['at']);
    }

    public function test_progress_on_the_internal_crew_route(): void
    {
        $fault = $this->approvedFault('agency_appoints');
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'internal', 'title' => 'T', 'description' => 'D']);
        $wo = $fault->fresh()->workOrder;

        $p = $this->progress($fault->fresh());
        $this->assertSame('sent_to_contractor', $p['current']);
        $this->assertSame('Sent to our maintenance team for scheduling', $p['current_label']);
        $this->assertNotNull(collect($p['steps'])->firstWhere('key', 'sent_to_contractor')['at']);

        // 9 Oct 2026 (Johan): an appointment only once the job is approved - refused until the owner's go-ahead is recorded
        try {
            app(RentalWorkOrderService::class)->setAppointment($wo, now()->addDays(3)->setTime(10, 30), null, $this->agent);
            $this->fail('an unapproved job must not get an appointment');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('once the job has been approved', $e->getMessage());
        }
        $wo->fresh()->recordApproval($this->agent, ['decision' => 'approved', 'evidence_type' => \App\Models\RentalApproval::EVIDENCE_VERBAL_NOTE, 'evidence_text' => 'owner agreed by phone']);
        app(RentalWorkOrderService::class)->setAppointment($wo->fresh(), now()->addDays(3)->setTime(10, 30), null, $this->agent);
        $p = $this->progress($fault->fresh());
        $step = collect($p['steps'])->firstWhere('key', 'appointment_set');
        $this->assertSame('appointment_set', $p['current']);
        $this->assertSame('done', $step['state']);
        $this->assertStringContainsString('10:30', $step['detail'], 'the date and time');
        $this->assertStringContainsString('our maintenance team', $step['detail'], 'who is coming');
    }

    public function test_progress_on_the_agency_contractor_route(): void
    {
        $supplier = \App\Models\DealV2\AgencyServiceProvider::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Acme Plumbing', 'is_active' => true, 'specialty' => 'other']);
        $fault = $this->approvedFault('agency_appoints');
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'assignment_type' => 'outside_supplier', 'title' => 'T', 'description' => 'D', 'agency_service_provider_id' => $supplier->id,
        ]);
        $wo = $fault->fresh()->workOrder;

        $this->assertSame('owner_decided', $this->progress($fault->fresh())['current'], 'not sent to the contractor until the office sends it');
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_ORDERED, 'ordered_at' => now()])->save();
        $this->assertSame('Sent to contractor for scheduling', $this->progress($fault->fresh())['current_label']);
        app(RentalWorkOrderService::class)->setAppointment($wo, now()->addDays(2)->setTime(8, 0), null, $this->agent);
        $step = collect($this->progress($fault->fresh())['steps'])->firstWhere('key', 'appointment_set');
        $this->assertStringContainsString('Acme Plumbing', $step['detail']);
    }

    public function test_progress_on_the_owners_contractor_route_through_to_completed_and_the_check_step(): void
    {
        $fault = $this->approvedFault('owner_handles', ['contractor_name' => 'Owner Bob', 'contractor_phone' => '0830001111']);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'assignment_type' => 'owner_contractor', 'title' => 'T', 'description' => 'D', 'contractor_name' => 'Owner Bob', 'contractor_phone' => '0830001111',
        ]);
        $wo = $fault->fresh()->workOrder;

        $this->assertSame("Sent to the owner's contractor for scheduling", $this->progress($fault->fresh())['current_label']);
        $this->assertSame('Sent to your contractor for scheduling', $this->progress($fault->fresh(), Progress::AUDIENCE_OWNER)['current_label']);

        app(RentalWorkOrderService::class)->setAppointment($wo, now()->addDays(2)->setTime(9, 0), null, $this->agent);
        $step = collect($this->progress($fault->fresh())['steps'])->firstWhere('key', 'appointment_set');
        $this->assertStringContainsString("the owner's contractor Owner Bob", $step['detail']);

        $wo->fresh()->startProgress($this->agent);
        $p = $this->progress($fault->fresh());
        $this->assertSame('Work in progress', $p['current_label']);
        $this->assertNotNull(collect($p['steps'])->firstWhere('key', 'in_progress')['at']);

        // Reported done (T1, 9 Oct 2026): NOT a tenant hold - the line stays "in progress" and "Work completed" is still ahead until the agent closes it.
        app(\App\Services\Rentals\RentalCompletionService::class)->openRound($wo->fresh(), ['reported_by_label' => 'Owner Bob', 'reported_via' => 'owner_portal']);
        $tenantP = $this->progress($fault->fresh());
        $last = end($tenantP['steps']);
        $this->assertSame('Work reported finished', $tenantP['current_label']);
        $this->assertNotSame('done', $last['state']);
        $this->assertNull($last['action'], 'no "please check" action holds the line');
        $this->assertNull(end($this->progress($fault->fresh(), Progress::AUDIENCE_OWNER)['steps'])['action']);

        // Closed: Work completed, dated.
        $wo->fresh()->complete($this->agent, ['paid_by' => 'owner']);
        RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->update(['outcome' => RentalWorkCompletionRound::OUTCOME_CONFIRMED]);
        $p = $this->progress($fault->fresh());
        $this->assertSame('completed', $p['current']);
        $this->assertSame('Work completed', $p['current_label']);
        $this->assertSame(array_fill(0, 8, 'done'), array_column($p['steps'], 'state'));
        foreach ($p['steps'] as $s) {
            $this->assertNotNull($s['at'], $s['key'] . ' shows the date it was reached');
        }
    }

    public function test_a_declined_fault_ends_at_a_neutral_line_and_never_shows_the_reason(): void
    {
        $fault = $this->tenantFault('Cracked tile');
        $fault->saveOwnerVersion(['owner_title' => 'Cracked tile', 'owner_agent_note' => 'INTERNAL: owner is difficult'], $this->agent);
        $fault->fresh()->requestApproval($this->agent);
        $fault->fresh()->recordApproval($this->landlord, ['decision' => 'declined', 'evidence_type' => 'portal', 'evidence_text' => 'SECRET reason: too expensive']);

        $tenant = $this->progress($fault->fresh());
        $this->assertSame('Not approved — your agent will contact you', $tenant['current_label']);
        $this->assertSame(['sent_to_agent', 'agent_reviewing', 'sent_to_owner', 'owner_decided'], array_column($tenant['steps'], 'key'), 'nothing after the decision');
        $this->assertSame('not_approved', $tenant['ended']);
        $this->assertStringNotContainsString('SECRET', json_encode($tenant));
        $this->assertStringNotContainsString('INTERNAL', json_encode($tenant));
        $this->assertSame('You declined', $this->progress($fault->fresh(), Progress::AUDIENCE_OWNER)['current_label']);

        // The tenant's API: the neutral line, no reason, no agent note, and no outcome note that could carry either.
        $fault->fresh()->setOutcome(['outcome' => 'owner_declined', 'outcome_note' => 'SECRET reason repeated'], $this->agent);
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
        $body = $this->getJson("/api/v1/client/rentals/fault-reports/{$fault->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('SECRET', $body);
        $this->assertStringNotContainsString('INTERNAL', $body);
        $this->assertStringContainsString('Not approved', $body);
    }

    public function test_cancelled_and_reopened_are_shown_plainly(): void
    {
        $fault = $this->approvedFault('owner_handles');
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'owner_contractor', 'title' => 'T', 'description' => 'D']);
        $wo = $fault->fresh()->workOrder;
        $wo->startProgress($this->agent);
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_DISPUTED])->save();
        $this->assertSame('Not complete — being put right', $this->progress($fault->fresh())['current_label']);

        $wo->forceFill(['status' => RentalWorkOrder::STATUS_CANCELLED, 'cancelled_at' => now()])->save();
        $p = $this->progress($fault->fresh());
        $this->assertSame('cancelled', $p['current']);
        $this->assertSame('Cancelled', $p['current_label']);
        $this->assertSame('cancelled', $p['ended']);
    }

    public function test_the_words_are_data_not_hardcoded(): void
    {
        $fault = $this->tenantFault('Tap');
        config(['rental-fault-progress.sent_to_agent.tenant' => 'We have your report']);

        $this->assertSame('We have your report', $this->progress($fault)['current_label']);
    }

    public function test_the_tenant_and_owner_apis_carry_the_progress_line(): void
    {
        $fault = $this->sentFault('Kitchen tap');
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
        $list = $this->getJson('/api/v1/client/rentals/fault-reports')->assertOk()->json('fault_reports');
        $mine = collect($list)->firstWhere('id', $fault->id);
        $this->assertSame('sent_to_owner', $mine['progress']['current']);
        $this->assertSame('Sent to owner for approval', $mine['progress']['current_label']);
        $this->assertCount(8, $mine['progress']['steps']);
        $show = $this->getJson("/api/v1/client/rentals/fault-reports/{$fault->id}")->assertOk()->json('fault_report');
        $this->assertSame($mine['progress']['current'], $show['progress']['current']);
    }

    // ── The tenant is told at each step - once ───────────────────────────────────────────────

    public function test_the_tenant_is_told_at_sent_to_owner_owner_approved_and_work_completed_once_each(): void
    {
        $fault = $this->tenantFault('Dripping tap');
        $fault->saveOwnerVersion(['owner_title' => 'Dripping tap'], $this->agent);
        Mail::assertNothingQueued();   // reviewing is not a mail

        $fault->fresh()->requestApproval($this->agent);
        Mail::assertQueued(RentalTenantStatusChangeMail::class, fn ($m) => $m->hasTo($this->tenant->email) && $m->stepLabel === 'Sent to owner for approval');

        $fault->fresh()->recordApproval($this->landlord, ['decision' => 'approved', 'approval_route' => 'owner_handles', 'evidence_type' => 'portal', 'contractor_name' => 'Bob']);
        Mail::assertQueued(RentalTenantStatusChangeMail::class, fn ($m) => $m->stepLabel === 'Owner approved');

        // Raising the work order is not a tenant mail (the appointment has its own); completing it is.
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'owner_contractor', 'title' => 'T', 'description' => 'D', 'contractor_name' => 'Bob']);
        Mail::assertQueued(RentalTenantStatusChangeMail::class, 2);

        $wo = $fault->fresh()->workOrder;
        $wo->fresh()->complete($this->agent, ['paid_by' => 'owner']);
        Mail::assertQueued(RentalTenantStatusChangeMail::class, fn ($m) => $m->stepLabel === 'Work completed');
        Mail::assertQueued(RentalTenantStatusChangeMail::class, 3);

        // The fault outcome that follows is the SAME step: no second "completed".
        $fault->fresh()->setOutcome(['outcome' => 'repaired', 'repaired_at' => now()->toDateString()], $this->agent);
        Mail::assertQueued(RentalTenantStatusChangeMail::class, 3);
    }

    public function test_the_tenant_mail_for_a_declined_fault_is_neutral_and_the_setting_switches_them_off(): void
    {
        $fault = $this->sentFault('Cracked tile');
        $fault->recordApproval($this->landlord, ['decision' => 'declined', 'evidence_type' => 'portal', 'evidence_text' => 'SECRET reason']);

        Mail::assertQueued(RentalTenantStatusChangeMail::class, function ($m) {
            $html = $m->render();

            return $m->stepLabel === 'Not approved — your agent will contact you' && ! str_contains($html, 'SECRET') && ! str_contains($html, 'Declined');
        });

        // The agency's tenant-notification setting off: nothing.
        Mail::fake();
        RentalPortalSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id], ['notify_tenant_on_status_change' => false]);
        $other = $this->tenantFault('Another');
        $other->saveOwnerVersion(['owner_title' => 'Another'], $this->agent);
        $other->fresh()->requestApproval($this->agent);
        Mail::assertNotQueued(RentalTenantStatusChangeMail::class);
    }

    public function test_the_owners_fault_screen_and_the_tenants_see_the_same_line_in_their_own_words(): void
    {
        $fault = $this->sentFault('Kitchen tap');
        $tenantLabel = $this->progress($fault, Progress::AUDIENCE_TENANT)['current_label'];
        $ownerLabel = $this->progress($fault, Progress::AUDIENCE_OWNER)['current_label'];

        $this->assertSame('Sent to owner for approval', $tenantLabel);
        $this->assertSame('Sent to you for approval', $ownerLabel);
        $this->assertSame($this->progress($fault, Progress::AUDIENCE_TENANT)['current'], $this->progress($fault, Progress::AUDIENCE_OWNER)['current']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function progress(RentalFaultReport $fault, string $audience = Progress::AUDIENCE_TENANT): array
    {
        return app(Progress::class)->forFault($fault->fresh(), $audience);
    }

    private function tenantFault(string $title = 'Leaking tap in the kitchen'): RentalFaultReport
    {
        return RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->faultType->id, 'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id,
            'reported_channel' => 'app', 'title' => $title, 'description' => 'Dripping',
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function sentFault(string $title = 'Leaking tap in the kitchen'): RentalFaultReport
    {
        $fault = $this->tenantFault($title);
        $fault->saveOwnerVersion(['owner_title' => $title, 'owner_description' => 'Agent-checked.'], $this->agent);
        $fault->fresh()->requestApproval($this->agent);

        return $fault->fresh();
    }

    private function approvedFault(string $route, array $contractor = []): RentalFaultReport
    {
        $fault = $this->sentFault();
        $fault->recordApproval($this->agent, array_merge([
            'decision' => 'approved', 'approval_route' => $route, 'evidence_type' => 'verbal_note', 'evidence_text' => 'Owner agreed.',
        ], $contractor));

        return $fault->fresh();
    }

    /** A new fault through the real service; returns the ids of the people the "fault reported" alert was fired at. */
    private function reportAndCollectRecipients(): array
    {
        $ids = [];
        $mock = \Mockery::mock(NotificationDispatcher::class);
        $mock->shouldReceive('fire')->andReturnUsing(function ($user, $key) use (&$ids) {
            if ($key === 'rental_fault_report.created') {
                $ids[] = $user->id;
            }

            return true;
        });
        $this->app->instance(NotificationDispatcher::class, $mock);

        app(RentalFaultReportService::class)->report($this->property, [
            'lease_id' => $this->lease->id, 'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id,
            'reported_channel' => 'app', 'title' => 'Fault ' . uniqid(), 'description' => 'x',
        ]);

        return array_values(array_unique($ids));
    }

    /** A work order from the agency's contractor whose quote is waiting on the owner. */
    private function agencyWorkOrderAwaitingOwner(): RentalWorkOrder
    {
        return RentalWorkOrder::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, 'title' => 'Geyser', 'description' => 'x',
            'status' => RentalWorkOrder::STATUS_REPORTED, 'owner_approval_status' => RentalWorkOrder::APPROVAL_PENDING,
            'reported_by_type' => 'agent_noticed', 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function clientUserFor(Contact $contact): ClientUser
    {
        $clientUser = ClientUser::firstOrCreate(['email' => $contact->email], ['current_agency_id' => $contact->agency_id]);
        $contact->forceFill(['client_user_id' => $clientUser->id])->saveQuietly();

        return $clientUser;
    }
}
