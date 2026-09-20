<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultReports;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalApproval;
use App\Models\RentalFaultReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stage 2 verification for .ai/specs/rental-work-orders.md §3a.1/§3a.2/§3.4a
 * (settled 2026-09-25, §0c). The two facts this suite exists to prove:
 *
 * 1. THE SPINE IS "WAS IT REPAIRED AND WHEN", NOT THE WORK ORDER. A fault
 *    the owner handled themselves reaches a complete, resolved outcome with
 *    rental_work_order_id staying null forever — that is a normal case,
 *    not a degenerate one. See
 *    test_owner_handles_route_reaches_repaired_outcome_with_no_work_order.
 * 2. setOutcome() is callable regardless of approval state — Johan's own
 *    situation 3 (nobody ever asked, nobody ever fixed it) reaches a real
 *    outcome without ever touching approval at all. See
 *    test_outcome_can_be_set_directly_from_reported_with_no_approval_step.
 */
final class RentalFaultReportLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'RFR Lifecycle Agency', 'slug' => 'rfr-lifecycle-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Test Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function faultReport(array $attrs = []): RentalFaultReport
    {
        return RentalFaultReport::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'captured_by_user_id' => $this->admin->id,
            'title' => 'Geyser burst', 'description' => 'Water in the ceiling.', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    // ── The two facts this suite exists to prove ────────────────────────

    public function test_owner_handles_route_reaches_repaired_outcome_with_no_work_order(): void
    {
        $faultReport = $this->faultReport();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.approval.store', $faultReport), [
            'decision' => 'approved',
            'approval_route' => RentalFaultReport::ROUTE_OWNER_HANDLES,
            'evidence_type' => RentalApproval::EVIDENCE_WHATSAPP,
            'evidence_text' => 'Owner replied: "go ahead, my own plumber will fix it."',
        ])->assertRedirect();

        $faultReport->refresh();
        $this->assertSame(RentalFaultReport::STATUS_OWNER_HANDLING, $faultReport->status);
        $this->assertSame(RentalFaultReport::ROUTE_OWNER_HANDLES, $faultReport->approval_route);
        $this->assertNull($faultReport->rental_work_order_id);

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.outcome.store', $faultReport), [
            'outcome' => RentalFaultReport::OUTCOME_REPAIRED,
            'repaired_at' => now()->subDay()->format('Y-m-d'),
        ])->assertRedirect();

        $faultReport->refresh();
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $faultReport->status);
        $this->assertSame(RentalFaultReport::OUTCOME_REPAIRED, $faultReport->outcome);
        $this->assertNotNull($faultReport->repaired_at);
        // The fact this whole amendment exists for: complete, resolved,
        // repaired — with NO work order anywhere near it, permanently.
        $this->assertNull($faultReport->rental_work_order_id);
    }

    public function test_outcome_can_be_set_directly_from_reported_with_no_approval_step(): void
    {
        $faultReport = $this->faultReport();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.outcome.store', $faultReport), [
            'outcome' => RentalFaultReport::OUTCOME_NOT_REPAIRED,
            'outcome_note' => 'Owner never responded; move-out is in two weeks.',
        ])->assertRedirect();

        $faultReport->refresh();
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $faultReport->status);
        $this->assertSame(RentalFaultReport::OUTCOME_NOT_REPAIRED, $faultReport->outcome);
        // Never touched approval at all.
        $this->assertSame(RentalFaultReport::APPROVAL_NOT_REQUIRED, $faultReport->owner_approval_status);
    }

    // ── Approval ─────────────────────────────────────────────────────

    public function test_agency_appoints_route_moves_to_approved_awaiting_a_work_order(): void
    {
        $faultReport = $this->faultReport();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.approval.store', $faultReport), [
            'decision' => 'approved',
            'approval_route' => RentalFaultReport::ROUTE_AGENCY_APPOINTS,
            'evidence_type' => RentalApproval::EVIDENCE_EMAIL,
            'evidence_text' => 'Owner emailed back: approved, please get a plumber.',
        ])->assertRedirect();

        $faultReport->refresh();
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $faultReport->status);
        $this->assertSame(RentalFaultReport::ROUTE_AGENCY_APPOINTS, $faultReport->approval_route);
    }

    public function test_declined_records_no_approval_route(): void
    {
        $faultReport = $this->faultReport();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.approval.store', $faultReport), [
            'decision' => 'declined',
            'evidence_type' => RentalApproval::EVIDENCE_VERBAL_NOTE,
            'evidence_text' => 'Owner said no over the phone.',
        ])->assertRedirect();

        $faultReport->refresh();
        $this->assertSame(RentalFaultReport::STATUS_DECLINED, $faultReport->status);
        $this->assertNull($faultReport->approval_route);

        $approval = RentalApproval::firstOrFail();
        $this->assertSame('declined', $approval->decision);
        $this->assertNull($approval->approval_route);
        $this->assertNull($approval->rental_work_order_id);
        $this->assertSame($faultReport->id, $approval->rental_fault_report_id);
    }

    public function test_approving_without_a_route_is_rejected(): void
    {
        $faultReport = $this->faultReport();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.approval.store', $faultReport), [
            'decision' => 'approved',
            'evidence_type' => RentalApproval::EVIDENCE_WHATSAPP,
            'evidence_text' => 'Approved.',
        ])->assertSessionHasErrors();

        $this->assertSame(RentalFaultReport::STATUS_REPORTED, $faultReport->fresh()->status);
        $this->assertSame(0, RentalApproval::count());
    }

    public function test_request_approval_marks_awaiting_without_recording_a_decision(): void
    {
        $faultReport = $this->faultReport();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.request-approval', $faultReport))
            ->assertRedirect();

        $faultReport->refresh();
        $this->assertSame(RentalFaultReport::STATUS_AWAITING_APPROVAL, $faultReport->status);
        $this->assertSame(RentalFaultReport::APPROVAL_PENDING, $faultReport->owner_approval_status);
        $this->assertSame(0, RentalApproval::count());
    }

    public function test_recording_a_decision_directly_does_not_require_having_requested_first(): void
    {
        // §3a.1's own point: an agent who already has the written reply in
        // hand should not need a pointless intermediate click.
        $faultReport = $this->faultReport();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.approval.store', $faultReport), [
            'decision' => 'approved',
            'approval_route' => RentalFaultReport::ROUTE_OWNER_HANDLES,
            'evidence_type' => RentalApproval::EVIDENCE_EMAIL,
            'evidence_text' => 'Owner emailed straight away.',
        ])->assertRedirect();

        $this->assertSame(RentalFaultReport::STATUS_OWNER_HANDLING, $faultReport->fresh()->status);
    }

    public function test_approval_evidence_screenshot_reuses_the_property_image_pipeline(): void
    {
        Storage::fake('public');
        $faultReport = $this->faultReport();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.approval.store', $faultReport), [
            'decision' => 'approved',
            'approval_route' => RentalFaultReport::ROUTE_AGENCY_APPOINTS,
            'evidence_type' => RentalApproval::EVIDENCE_WHATSAPP,
            'evidence_text' => 'See attached screenshot.',
            'evidence_file' => UploadedFile::fake()->image('whatsapp.jpg'),
        ])->assertRedirect();

        $approval = RentalApproval::firstOrFail();
        $this->assertNotNull($approval->evidence_file_path);
    }

    public function test_cannot_record_approval_on_an_already_resolved_report(): void
    {
        $faultReport = $this->faultReport([
            'status' => RentalFaultReport::STATUS_RESOLVED, 'outcome' => RentalFaultReport::OUTCOME_REPAIRED,
            'repaired_at' => now(), 'resolved_at' => now(),
        ]);

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.approval.store', $faultReport), [
            'decision' => 'approved',
            'approval_route' => RentalFaultReport::ROUTE_AGENCY_APPOINTS,
            'evidence_type' => RentalApproval::EVIDENCE_EMAIL,
            'evidence_text' => 'x',
        ])->assertSessionHasErrors();

        $this->assertSame(0, RentalApproval::count());
    }

    // ── Outcome ──────────────────────────────────────────────────────

    public function test_outcome_note_is_required_unless_repaired(): void
    {
        $faultReport = $this->faultReport();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.outcome.store', $faultReport), [
            'outcome' => RentalFaultReport::OUTCOME_TENANT_LIABLE,
        ])->assertSessionHasErrors();

        $this->assertNull($faultReport->fresh()->outcome);

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.outcome.store', $faultReport), [
            'outcome' => RentalFaultReport::OUTCOME_TENANT_LIABLE,
            'outcome_note' => 'Tenant admitted causing the damage.',
        ])->assertRedirect();

        $this->assertSame(RentalFaultReport::OUTCOME_TENANT_LIABLE, $faultReport->fresh()->outcome);
    }

    public function test_repaired_at_is_required_when_outcome_is_repaired_or_partially_repaired(): void
    {
        $faultReport = $this->faultReport();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.outcome.store', $faultReport), [
            'outcome' => RentalFaultReport::OUTCOME_REPAIRED,
        ])->assertSessionHasErrors();

        $this->assertNull($faultReport->fresh()->outcome);

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.outcome.store', $faultReport), [
            'outcome' => RentalFaultReport::OUTCOME_REPAIRED,
            'repaired_at' => now()->format('Y-m-d'),
        ])->assertRedirect();

        $this->assertNotNull($faultReport->fresh()->repaired_at);
    }

    public function test_owner_declined_is_a_distinct_outcome_from_not_repaired(): void
    {
        $faultReport = $this->faultReport();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.outcome.store', $faultReport), [
            'outcome' => RentalFaultReport::OUTCOME_OWNER_DECLINED,
            'outcome_note' => 'Owner said the tenant must live with it.',
        ])->assertRedirect();

        $this->assertSame(RentalFaultReport::OUTCOME_OWNER_DECLINED, $faultReport->fresh()->outcome);
    }

    public function test_cannot_set_outcome_on_an_already_cancelled_report(): void
    {
        $faultReport = $this->faultReport(['status' => RentalFaultReport::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => 'x']);

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.outcome.store', $faultReport), [
            'outcome' => RentalFaultReport::OUTCOME_REPAIRED,
            'repaired_at' => now()->format('Y-m-d'),
        ])->assertSessionHasErrors();

        $this->assertNull($faultReport->fresh()->outcome);
    }

    // ── Permission separation (§10) ──────────────────────────────────

    public function test_recording_approval_requires_its_own_permission_not_just_create(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        // grantCreateButNotSignOnBehalf-style: seed exactly what's granted so
        // the unseeded allow-all fallback doesn't mask this check.
        $this->grant($agent, ['rental_fault_reports.view', 'rental_fault_reports.create']);
        $faultReport = $this->faultReport();

        $this->actingAs($agent)->post(route('corex.rental-fault-reports.approval.store', $faultReport), [
            'decision' => 'approved', 'approval_route' => RentalFaultReport::ROUTE_OWNER_HANDLES,
            'evidence_type' => RentalApproval::EVIDENCE_EMAIL, 'evidence_text' => 'x',
        ])->assertForbidden();
    }

    public function test_setting_outcome_requires_its_own_permission_not_just_create(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->grant($agent, ['rental_fault_reports.view', 'rental_fault_reports.create']);
        $faultReport = $this->faultReport();

        $this->actingAs($agent)->post(route('corex.rental-fault-reports.outcome.store', $faultReport), [
            'outcome' => RentalFaultReport::OUTCOME_NOT_REPAIRED, 'outcome_note' => 'x',
        ])->assertForbidden();
    }

    private function grant(User $user, array $permissionKeys): void
    {
        foreach ($permissionKeys as $key) {
            \App\Models\RolePermission::create(['role' => $user->role, 'permission_key' => $key, 'scope' => 'own']);
        }
        \App\Services\PermissionService::clearCache();
    }
}
