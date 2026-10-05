<?php

namespace Tests\Feature\RentalPortalAccess;

use App\Mail\Rentals\RentalNoticeMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalNotice;
use App\Models\RentalNoticeTemplate;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-445 — .ai/specs/rental-portal-access.md §2/§3/§8/§11. First-aid
 * resolution, the landlord decision driving the EXISTING owner-approval
 * mechanism, and a sent notice logging to the tenancy. No real email
 * address is ever addressed — Mail::fake() intercepts every send.
 */
class RentalPortalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $landlord;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->agency = Agency::create(['name' => 'Workflow Agency', 'slug' => 'wf-' . uniqid()]);
        Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'admin']);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => Branch::where('agency_id', $this->agency->id)->value('id'),
            'title' => 'Workflow Unit', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id, 'property_id' => $this->property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);

        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id,
            'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.com',
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);

        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id,
            'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.com',
        ]);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
    }

    private function grant(User $user, array $permissionKeys): void
    {
        foreach ($permissionKeys as $key) {
            \App\Models\RolePermission::create(['role' => $user->role, 'permission_key' => $key, 'scope' => 'own']);
        }
        \App\Services\PermissionService::clearCache();
    }

    private function clientUserFor(Contact $contact): ClientUser
    {
        $clientUser = ClientUser::create(['email' => $contact->email, 'current_agency_id' => $contact->agency_id]);
        $contact->forceFill(['client_user_id' => $clientUser->id])->saveQuietly();

        return $clientUser;
    }

    public function test_tenant_first_aid_resolution_closes_the_report_with_no_staff_actor(): void
    {
        $faultType = RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'plumbing', 'urgency' => 'low',
            'first_aid_steps' => 'Turn off the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);

        $res = $this->postJson('/api/v1/client/rentals/properties/' . $this->property->id . '/fault-reports', [
            'rental_fault_type_id' => $faultType->id,
            'resolution' => 'first_aid_resolved',
            'title' => 'Leaking tap',
        ]);

        $res->assertStatus(201);
        $faultReportId = $res->json('fault_report.id');

        $fault = RentalFaultReport::withoutGlobalScopes()->findOrFail($faultReportId);
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $fault->status);
        $this->assertSame(RentalFaultReport::OUTCOME_RESOLVED_BY_FIRST_AID, $fault->outcome);
        $this->assertSame($this->tenant->id, $fault->reported_by_contact_id);
    }

    public function test_tenant_still_a_problem_creates_a_normal_open_report(): void
    {
        $faultType = RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Broken window', 'category' => 'general', 'urgency' => 'routine',
            'first_aid_steps' => 'n/a', 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);

        $res = $this->postJson('/api/v1/client/rentals/properties/' . $this->property->id . '/fault-reports', [
            'rental_fault_type_id' => $faultType->id,
            'resolution' => 'still_a_problem',
            'title' => 'Broken window',
            'description' => 'Glass cracked',
        ]);

        $res->assertStatus(201);
        $fault = RentalFaultReport::withoutGlobalScopes()->findOrFail($res->json('fault_report.id'));
        $this->assertSame(RentalFaultReport::STATUS_REPORTED, $fault->status);
        $this->assertNull($fault->outcome);
    }

    public function test_landlord_approve_agency_appoints_drives_the_existing_approval_mechanism(): void
    {
        $fault = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_by_contact_id' => $this->tenant->id,
            'reported_channel' => RentalFaultReport::CHANNEL_APP, 'title' => 'Roof leak', 'description' => 'Leaking',
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
        ]);
        $fault->requestApproval($this->agent);

        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);

        $res = $this->postJson('/api/v1/client/rentals/landlord/fault-reports/' . $fault->id . '/decision', [
            'decision' => 'approve_agency_appoints',
        ]);
        $res->assertOk();

        $fault->refresh();
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $fault->status);
        $this->assertSame(RentalFaultReport::APPROVAL_APPROVED, $fault->owner_approval_status);

        $approval = $fault->approvals()->latest('id')->first();
        $this->assertSame($this->landlord->id, $approval->recorded_by_contact_id);
        $this->assertNull($approval->recorded_by_user_id);
        $this->assertSame(\App\Models\RentalApproval::EVIDENCE_PORTAL, $approval->evidence_type);
    }

    public function test_landlord_handle_it_myself_requires_a_note_and_never_raises_a_work_order(): void
    {
        $fault = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_by_contact_id' => $this->tenant->id,
            'reported_channel' => RentalFaultReport::CHANNEL_APP, 'title' => 'Gutter blocked', 'description' => 'Blocked',
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
        ]);
        $fault->requestApproval($this->agent);

        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);

        $this->postJson('/api/v1/client/rentals/landlord/fault-reports/' . $fault->id . '/decision', [
            'decision' => 'approve_owner_handles',
        ])->assertStatus(422);

        $res = $this->postJson('/api/v1/client/rentals/landlord/fault-reports/' . $fault->id . '/decision', [
            'decision' => 'approve_owner_handles', 'note' => "I'll sort the gutters myself this weekend.",
        ]);
        $res->assertOk();

        $fault->refresh();
        $this->assertSame(RentalFaultReport::STATUS_OWNER_HANDLING, $fault->status);
        $this->assertNull($fault->rental_work_order_id);
    }

    public function test_landlord_cannot_decide_on_a_fault_report_for_a_property_they_do_not_own(): void
    {
        $otherLandlord = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id,
            'first_name' => 'Other', 'last_name' => 'Landlord', 'email' => 'other+' . uniqid() . '@example.com',
        ]);
        $fault = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_by_contact_id' => $this->tenant->id,
            'reported_channel' => RentalFaultReport::CHANNEL_APP, 'title' => 'Fence down', 'description' => 'Down',
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
        ]);
        $fault->requestApproval($this->agent);

        Sanctum::actingAs($this->clientUserFor($otherLandlord), ['client']);

        $this->postJson('/api/v1/client/rentals/landlord/fault-reports/' . $fault->id . '/decision', [
            'decision' => 'approve_agency_appoints',
        ])->assertStatus(404);
    }

    public function test_sending_a_notice_logs_a_document_and_appears_in_the_tenancy_timeline(): void
    {
        $template = RentalNoticeTemplate::create([
            'agency_id' => $this->agency->id, 'name' => 'Standard Breach', 'notice_type' => RentalNoticeTemplate::TYPE_BREACH,
            'body_html' => '<p>Dear {{tenant_name}}, arrears are {{arrears_amount}}.</p>', 'is_active' => true,
        ]);

        $this->grant($this->agent, ['rental_notices.create', 'leases.view']);
        $this->actingAs($this->agent);

        $res = $this->post('/corex/leases/' . $this->lease->id . '/notices', [
            'rental_notice_template_id' => $template->id,
            'figures_raw' => "tenant_name=Tina\narrears_amount=R 1,500",
            'send_to_tenant' => '1',
        ]);
        $res->assertRedirect();

        $notice = RentalNotice::where('lease_id', $this->lease->id)->first();
        $this->assertNotNull($notice);
        $this->assertTrue((bool) $notice->sent_to_tenant);
        $this->assertNotNull($notice->document_id);

        // RentalNoticeMail implements ShouldQueue, so Mail::send() queues it
        // rather than sending synchronously — assertQueued, not assertSent.
        Mail::assertQueued(RentalNoticeMail::class, function ($mail) {
            return $mail->hasTo($this->tenant->email);
        });

        $timeline = app(\App\Services\Rentals\LeaseTimelineService::class)->allEntriesFor($this->lease);
        $this->assertTrue($timeline->contains(fn ($e) => $e['type'] === 'notice'));
    }
}
