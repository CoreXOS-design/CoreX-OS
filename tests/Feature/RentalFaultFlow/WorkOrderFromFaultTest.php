<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Models\RentalFaultType;
use App\Models\RentalWorkOrder;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\RentalCommandCentreService;
use App\Services\Rentals\RentalFaultContractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Johan, 8 Oct 2026 - "no title, no description. choose a contractor. that should create the work order." Creating a work order FROM a fault
 * asks only WHO does the work. Everything else (title, description, photos the owner saw, property, lease/tenant, the owner's decision) comes from
 * the fault. Plus the fault screen showing only what fits its stage, the agent being told when the owner decides, a completed work order resolving
 * its fault, a cancelled one sending it back to "appoint", and the supplier pick lists offering maintenance contractors only.
 */
final class WorkOrderFromFaultTest extends TestCase
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
        \Illuminate\Support\Facades\Storage::fake('local');

        $this->agency = Agency::create(['name' => 'From Fault Agency', 'slug' => 'ff-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'FF Unit', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.invalid',
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid',
        ]);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->faultType = RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine',
            'first_aid_steps' => 'Close the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function fault(array $over = []): RentalFaultReport
    {
        return RentalFaultReport::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->faultType->id, 'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id,
            'reported_channel' => 'app', 'title' => 'TENANT WORDS: my landlord is useless, tap leaks', 'description' => 'TENANT ORIGINAL: Tina in unit 3 says drip',
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
            'created_by_user_id' => $this->agent->id,
        ], $over));
    }

    /** A fault with the agent-sanitised owner version saved, sent, and (optionally) decided. */
    private function approved(string $route = 'agency_appoints', array $contractor = [], array $faultOver = []): RentalFaultReport
    {
        $fault = $this->fault($faultOver);
        $shared = RentalFaultReportPhoto::create(['agency_id' => $this->agency->id, 'rental_fault_report_id' => $fault->id, 'storage_path' => '/storage/ff/owner-sees.jpg', 'uploaded_by_user_id' => $this->agent->id]);
        RentalFaultReportPhoto::create(['agency_id' => $this->agency->id, 'rental_fault_report_id' => $fault->id, 'storage_path' => '/storage/ff/tenant-only.jpg', 'uploaded_by_user_id' => $this->agent->id]);
        $fault->saveOwnerVersion(['owner_title' => 'Kitchen tap leaking', 'owner_description' => 'A tap in the kitchen drips.', 'owner_agent_note' => 'Plumber needed', 'owner_photo_ids' => [$shared->id]], $this->agent);
        $fault->fresh()->requestApproval($this->agent);
        $fault->fresh()->recordApproval($this->agent, array_merge([
            'decision' => 'approved', 'approval_route' => $route, 'evidence_type' => 'verbal_note', 'evidence_text' => 'Owner agreed.',
        ], $contractor));

        return $fault->fresh();
    }

    private function contractor(string $name = 'Ramsgate Plumbing', string $specialty = 'plumber', array $over = []): AgencyServiceProvider
    {
        return AgencyServiceProvider::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'name' => $name, 'phone' => '0315550000', 'is_active' => true, 'specialty' => $specialty,
        ], $over));
    }

    private function raise(RentalFaultReport $fault, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->agent)->post(route('corex.rental-fault-reports.raise-work-order', $fault), $data);
    }

    private function userWith(array $grants, string $role = 'agent'): User
    {
        foreach (array_keys($grants) as $key) {
            RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        Role::firstOrCreate(['name' => $role, 'agency_id' => $this->agency->id], ['label' => ucfirst($role)]);
        foreach ($grants as $key => $scope) {
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => $scope]);
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role]);
    }

    // ── 1. the action asks only for a contractor ─────────────────────────────

    public function test_agency_route_pick_a_contractor_and_the_work_order_exists_made_from_the_fault_alone(): void
    {
        $fault = $this->approved('agency_appoints');
        $plumber = $this->contractor();

        // NOTHING but the contractor is posted: no title, no description.
        $this->raise($fault, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $plumber->id])->assertSessionHasNoErrors()->assertRedirect();

        $wo = $fault->fresh()->workOrder;
        $this->assertNotNull($wo);
        $this->assertSame('Kitchen tap leaking', $wo->title, 'the title the owner saw, not the tenant\'s words');
        $this->assertSame('A tap in the kitchen drips.', $wo->description);
        $this->assertStringNotContainsString('TENANT', $wo->title . $wo->description);
        $this->assertSame($this->property->id, $wo->property_id);
        $this->assertSame($this->lease->id, $wo->lease_id);
        $this->assertSame($fault->id, $wo->reported_fault_report_id);
        $this->assertSame(RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, $wo->assignment_type);
        $this->assertSame($plumber->id, (int) $wo->agency_service_provider_id);
        $this->assertSame(RentalWorkOrder::STATUS_REPORTED, $wo->status, 'ordering still follows the quote and authorisation steps');
        $this->assertSame(RentalFaultReport::STATUS_WORK_ORDER_RAISED, $fault->fresh()->status);
        $this->assertSame(['/storage/ff/owner-sees.jpg'], $wo->photos()->pluck('storage_path')->all(), 'only the photo the owner saw travels');
        $note = $wo->updates()->where('update_type', 'note')->latest('id')->first()?->note;
        $this->assertStringContainsString('fault report #' . $fault->id, (string) $note);
        $this->assertStringContainsString('approved', (string) $note);
    }

    public function test_whatever_an_old_form_posts_for_title_and_description_is_ignored_and_they_stay_editable_afterwards(): void
    {
        $fault = $this->approved();
        $plumber = $this->contractor();

        $this->raise($fault, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $plumber->id, 'title' => 'TYPED', 'description' => 'TYPED TOO'])->assertSessionHasNoErrors();
        $wo = $fault->fresh()->workOrder;
        $this->assertSame('Kitchen tap leaking', $wo->title);

        $this->actingAs($this->agent)->put(route('corex.rental-work-orders.update', $wo), ['title' => 'Edited later', 'description' => 'Edited description'])->assertSessionHasNoErrors();
        $this->assertSame('Edited later', $wo->fresh()->title);
    }

    public function test_internal_crew_also_makes_the_job_card(): void
    {
        $fault = $this->approved();

        $res = $this->raise($fault, ['assignment_type' => 'internal']);

        $wo = $fault->fresh()->workOrder;
        $card = $wo->jobCard;
        $this->assertNotNull($card);
        $res->assertRedirect(route('corex.rental-job-cards.show', $card));
        $this->assertSame('Kitchen tap leaking', $wo->title);
        $this->assertSame(RentalWorkOrder::ASSIGNMENT_INTERNAL, $wo->assignment_type);
    }

    public function test_owner_chose_their_own_contractor_the_agent_only_confirms_and_cannot_swap_them(): void
    {
        $fault = $this->approved('owner_handles', ['contractor_name' => 'Owner Bob', 'contractor_phone' => '0830001111']);
        $plumber = $this->contractor();

        // nothing posted, or even a different choice posted: the owner's decision rules
        $this->raise($fault, ['assignment_type' => 'internal', 'agency_service_provider_id' => $plumber->id])->assertSessionHasNoErrors();

        $wo = $fault->fresh()->workOrder;
        $this->assertSame(RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR, $wo->assignment_type);
        $this->assertSame('Owner Bob', $wo->contractor_name);
        $this->assertSame('0830001111', $wo->contractor_phone);
        $this->assertNull($wo->agency_service_provider_id);
        $this->assertNull($wo->jobCard);
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->status);
    }

    public function test_the_agency_route_refuses_the_owners_contractor_a_missing_contractor_and_an_attorney(): void
    {
        $fault = $this->approved('agency_appoints');
        $attorney = $this->contractor('Jafta Incorporated', 'transfer_attorney', ['is_transfer_attorney' => true]);
        $originator = $this->contractor('Ooba Westrand', 'bond_originator');
        $inactive = $this->contractor('Closed Plumbers', 'plumber', ['is_active' => false]);

        foreach ([
            ['assignment_type' => 'owner_contractor', 'contractor_name' => 'Sneaky'],
            ['assignment_type' => 'outside_supplier'],
            ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $attorney->id],
            ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $originator->id],
            ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $inactive->id],
            ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => 999999],
        ] as $bad) {
            $this->raise($fault, $bad)->assertSessionHasErrors('rental_fault_report');
        }
        $this->assertSame(0, RentalWorkOrder::withoutGlobalScopes()->count());
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $fault->fresh()->status);
    }

    // ── 2. double submit, rollback, permission and scope ─────────────────────

    public function test_a_second_press_or_second_agent_is_taken_to_the_one_work_order_never_a_second(): void
    {
        $fault = $this->approved();
        $plumber = $this->contractor();
        $data = ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $plumber->id];

        $this->raise($fault, $data)->assertSessionHasNoErrors();
        $wo = $fault->fresh()->workOrder;
        $this->raise($fault->fresh(), $data)->assertRedirect(route('corex.rental-work-orders.show', $wo))->assertSessionHasErrors('rental_fault_report');
        $this->raise($fault->fresh(), ['assignment_type' => 'internal'])->assertRedirect(route('corex.rental-work-orders.show', $wo));

        $this->assertSame(1, RentalWorkOrder::withoutGlobalScopes()->where('reported_fault_report_id', $fault->id)->count());
        $this->assertSame(0, \App\Models\RentalJobCard::withoutGlobalScopes()->count(), 'the second press did not make a job card either');
    }

    public function test_a_failure_half_way_leaves_nothing_behind(): void
    {
        $fault = $this->approved();
        $plumber = $this->contractor();
        \App\Models\RentalWorkOrderPhoto::creating(fn () => throw new \RuntimeException('boom'));

        try {
            $this->withoutExceptionHandling();
            $this->raise($fault, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $plumber->id]);
            $this->fail('the failure should surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        } finally {
            \App\Models\RentalWorkOrderPhoto::flushEventListeners();
        }

        $this->assertSame(0, RentalWorkOrder::withoutGlobalScopes()->count(), 'no work order');
        $fresh = $fault->fresh();
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $fresh->status);
        $this->assertNull($fresh->rental_work_order_id);
    }

    public function test_permission_and_record_scope_are_unchanged(): void
    {
        $fault = $this->approved();
        $plumber = $this->contractor();
        $data = ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $plumber->id];
        $without = $this->userWith(['rental_fault_reports.view' => 'all']);
        $ownStranger = $this->userWith(['rental_fault_reports.view' => 'own', 'rental_fault_reports.raise_work_order' => 'own']);

        $this->raise($fault, $data, $without)->assertForbidden();
        $this->raise($fault, $data, $ownStranger)->assertForbidden();
        $this->assertSame(0, RentalWorkOrder::withoutGlobalScopes()->count());

        // another agency's agent never reaches it
        $other = Agency::create(['name' => 'Other', 'slug' => 'o-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Main', 'code' => 'O-' . $other->id, 'is_active' => true]);
        $outsider = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'role' => 'agent']);   // (an 'admin' is a platform owner role and crosses agencies by design)
        Role::firstOrCreate(['name' => 'agent', 'agency_id' => $other->id], ['label' => 'Agent']);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'rental_fault_reports.raise_work_order', 'agency_id' => $other->id], ['scope' => 'all']);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'rental_fault_reports.view', 'agency_id' => $other->id], ['scope' => 'all']);
        PermissionService::clearCache();
        $this->assertContains($this->raise($fault, $data, $outsider)->getStatusCode(), [403, 404]);
        $this->assertSame(0, RentalWorkOrder::withoutGlobalScopes()->count());

        // the lease's agent (own scope) can
        $this->lease->forceFill(['owner_agent_user_id' => $ownStranger->id])->save();
        $this->raise($fault, $data, $ownStranger)->assertSessionHasNoErrors();
        $this->assertNotNull($fault->fresh()->workOrder);
    }

    // ── 3. the agent is told, and the command centre row opens the action ────

    public function test_the_agent_is_told_when_the_owner_approves_and_the_row_opens_the_action_then_leaves_when_it_is_done(): void
    {
        $fault = $this->approved();   // recorded by the agent: the owner-side agent for this lease is the property's agent (= $this->agent)

        $note = $this->agent->notifications()->get()->first(fn ($n) => str_contains((string) json_encode($n->data), 'Owner approved - appoint contractor'));
        $this->assertNotNull($note, 'the agent got "Owner approved - appoint contractor"');
        $this->assertStringContainsString('create_work_order=1', json_encode($note->data));

        $rows = fn () => app(RentalCommandCentreService::class)->queueItems($this->agent, 'all')->filter(fn ($i) => $i['type'] === 'fault_appoint_contractor');
        $this->assertCount(1, $rows());
        $row = $rows()->first();
        $this->assertSame('Owner approved - appoint contractor', $row['label']);
        $this->assertSame(['rentalFaultReport' => $fault->id, 'create_work_order' => 1], $row['route_params']);

        // a fault still waiting on the owner is not one of these
        $this->fault(['title' => 'Another']);
        $this->assertCount(1, $rows());

        $this->raise($fault, ['assignment_type' => 'internal'])->assertSessionHasNoErrors();
        $this->assertCount(0, $rows(), 'the row leaves by itself once the work order exists');
    }

    // ── 4. the fault screen shows what fits its stage ────────────────────────

    public function test_a_just_reported_fault_offers_the_owner_version_and_a_secondary_early_path_only(): void
    {
        $fault = $this->fault();

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();

        $this->assertStringContainsString('owner-version-card', $html);
        $this->assertStringNotContainsString('data-appoint-contractor', $html, 'no primary Create work order yet');
        $this->assertStringNotContainsString('id="owner-decision-card"', $html, 'no Record decision yet');
        $this->assertStringNotContainsString('record-approval-form', $html);
        $this->assertStringNotContainsString('Save outcome', $html, 'no Outcome yet');
        $this->assertStringContainsString('data-early-work-order', $html);
        $this->assertStringContainsString('Start a work order before the owner decides', $html);
        $this->assertStringContainsString('no-approval spend limit (R500.00)', $html);
        $this->assertStringNotContainsString('name="title"', $html);
        $this->assertStringNotContainsString('name="description"', $html);
    }

    public function test_the_fault_screen_moves_with_the_fault(): void
    {
        // sent to the owner, not yet decided -> record the decision on their behalf
        $sent = $this->fault();
        $sent->saveOwnerVersion(['owner_title' => 'T', 'owner_description' => 'D'], $this->agent);
        $sent->fresh()->requestApproval($this->agent);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $sent))->assertOk()->getContent();
        $this->assertStringContainsString('data-waiting-for-owner', $html);
        $this->assertStringContainsString('record-approval-form', $html);
        $this->assertStringNotContainsString('data-appoint-contractor', $html);
        $this->assertStringNotContainsString('Save outcome', $html);

        // owner approved -> appoint contractor is THE action; the form asks for the contractor only
        $approved = $this->approved();
        $this->contractor('Ramsgate Plumbing');
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', ['rentalFaultReport' => $approved, 'create_work_order' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('data-appoint-contractor', $html);
        $this->assertStringContainsString('data-raise-work-order', $html);
        $this->assertStringContainsString('data-contractor-picker', $html);
        $this->assertStringContainsString('Ramsgate Plumbing', $html);
        $this->assertStringNotContainsString('record-approval-form', $html, 'the decision is made: nothing left to record');
        $this->assertStringNotContainsString('data-early-work-order', $html);
        $this->assertStringNotContainsString('Save outcome', $html);
        $this->assertDoesNotMatchRegularExpression('/<form id="raise-work-order-form"[^>]*class="[^"]*\bhidden\b/', $html, 'opened directly by ?create_work_order=1');

        // work order exists -> the outcome appears; no appoint form any more
        $this->raise($approved, ['assignment_type' => 'internal']);
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $approved))->assertOk()->getContent();
        $this->assertStringContainsString('Save outcome', $html);
        $this->assertStringNotContainsString('data-raise-work-order', $html);
    }

    public function test_the_owner_approved_with_their_own_contractor_screen_shows_a_confirm_not_a_picker(): void
    {
        $fault = $this->approved('owner_handles', ['contractor_name' => 'Owner Bob', 'contractor_phone' => '0830001111']);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', ['rentalFaultReport' => $fault, 'create_work_order' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString('data-owner-contractor', $html);
        $this->assertStringContainsString('Owner Bob', $html);
        $this->assertStringContainsString('Confirm and create work order', $html);
        $this->assertStringNotContainsString('data-contractor-picker', $html);
    }

    public function test_no_button_on_the_fault_screens_uses_a_browser_confirm_box_and_irreversible_ones_use_the_app_modal(): void
    {
        foreach ([$this->fault(), $this->approved()] as $fault) {
            $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
            $this->assertStringNotContainsString('confirm(', $html);
        }
        $reported = $this->fault();
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $reported))->getContent();
        $this->assertStringContainsString('data-confirm-modal', $html);
        $this->assertStringContainsString('Save and send to owner', $html);
        $this->assertStringContainsString('name="send_now"', $html);
        $this->assertStringContainsString('Archive this fault report?', $html);
        $source = (string) file_get_contents(base_path('resources/views/corex/rental-fault-reports/show.blade.php'));
        $this->assertStringNotContainsString('confirm(', $source);
        $this->assertStringContainsString('<x-confirm-submit title="Record the owner\'s decision"', $source);
        $this->assertStringContainsString('<x-confirm-submit title="Record the outcome"', $source);
    }

    // ── 5. the contractor list: no type = everyone (maintenance only) ────────

    public function test_a_fault_with_no_type_offers_all_maintenance_contractors_and_never_attorneys(): void
    {
        $this->contractor('Ramsgate Plumbing');
        $this->contractor('Sparky Electrical', 'electrician');
        $this->contractor('Jafta Incorporated', 'transfer_attorney', ['is_transfer_attorney' => true]);
        $this->contractor('Ooba Westrand', 'bond_originator');
        $this->contractor('Van Zyl Retief', 'other', ['is_bond_attorney' => true]);
        $typeless = $this->fault(['rental_fault_type_id' => null]);

        $svc = app(RentalFaultContractorService::class);
        $names = $svc->optionsFor($typeless)->pluck('name')->all();
        sort($names);
        $this->assertSame(['Ramsgate Plumbing', 'Sparky Electrical'], $names);
        $this->assertSame(['Ramsgate Plumbing', 'Sparky Electrical'], $svc->pickerFor($typeless)->pluck('name')->sort()->values()->all());
        $this->assertTrue($svc->isOption($typeless, (int) $svc->optionsFor($typeless)->first()['id']));

        // the agent's Record decision screen lists them too, searchable
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $this->sentFault($typeless)))->assertOk()->getContent();
        $this->assertStringContainsString('data-decision-contractor-search', $html);
        $this->assertStringContainsString('Ramsgate Plumbing', $html);
        $this->assertStringNotContainsString('Jafta Incorporated', $html);
        $this->assertStringNotContainsString('No suppliers are set up', $html);
    }

    private function sentFault(RentalFaultReport $fault): RentalFaultReport
    {
        $fault->saveOwnerVersion(['owner_title' => 'T', 'owner_description' => 'D'], $this->agent);
        $fault->fresh()->requestApproval($this->agent);

        return $fault->fresh();
    }

    // ── 6. completing the work order resolves the fault; cancelling sends it back ────

    public function test_completing_the_work_order_resolves_the_fault_as_repaired_and_the_outcome_stays_editable_once(): void
    {
        $fault = $this->approved('owner_handles', ['contractor_name' => 'Owner Bob', 'contractor_phone' => '0830001111']);
        $this->raise($fault, []);
        $wo = $fault->fresh()->workOrder;
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_IN_PROGRESS])->save();

        $wo->fresh()->complete($this->agent, ['paid_by' => 'owner']);

        $resolved = $fault->fresh();
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $resolved->status);
        $this->assertSame(RentalFaultReport::OUTCOME_REPAIRED, $resolved->outcome);
        $this->assertTrue($resolved->outcome_set_automatically);
        $this->assertNotNull($resolved->repaired_at);
        $progress = app(\App\Services\Rentals\RentalFaultProgressService::class)->forFault($resolved, 'tenant');
        $this->assertSame('completed', $progress['current'], 'the tenant\'s line is on its final step');

        // still editable - once; a hand-recorded outcome is final
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.outcome.store', $resolved), ['outcome' => 'repaired_partially', 'outcome_note' => 'One tap still drips', 'repaired_at' => now()->toDateString()])->assertSessionHasNoErrors();
        $edited = $fault->fresh();
        $this->assertSame('repaired_partially', $edited->outcome);
        $this->assertFalse($edited->outcome_set_automatically);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.outcome.store', $edited), ['outcome' => 'repaired', 'repaired_at' => now()->toDateString()])->assertSessionHasErrors('rental_fault_report');
    }

    public function test_the_fault_waits_while_the_tenant_has_the_work_disputed_and_resolves_when_it_is_settled(): void
    {
        $fault = $this->approved();
        $this->raise($fault, ['assignment_type' => 'internal']);
        $wo = $fault->fresh()->workOrder;
        $round = \App\Models\RentalWorkCompletionRound::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'round_no' => 1,
            'outcome' => \App\Models\RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, 'opened_at' => now()->subDays(6), 'window_ends_at' => now()->subDay(),
            'reported_by_label' => 'Crew', 'reported_via' => 'office',
        ]);
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_COMPLETED, 'completed_at' => now(), 'paid_by' => 'owner'])->save();

        $this->assertFalse($fault->fresh()->resolveFromCompletedWorkOrder($wo->fresh(), $this->agent), 'refused while the tenant has not answered');
        $this->assertSame(RentalFaultReport::STATUS_WORK_ORDER_RAISED, $fault->fresh()->status);

        app(\App\Services\Rentals\RentalCompletionService::class)->settleSilent();   // "no answer in N days = accepted"
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $fault->fresh()->status);
        $this->assertTrue($fault->fresh()->outcome_set_automatically);
    }

    public function test_cancelling_the_work_order_sends_the_fault_back_to_appoint_a_contractor(): void
    {
        $fault = $this->approved();
        $this->raise($fault, ['assignment_type' => 'internal']);
        $wo = $fault->fresh()->workOrder;
        $this->assertSame(RentalFaultReport::STATUS_WORK_ORDER_RAISED, $fault->fresh()->status);

        $wo->fresh()->cancel($this->agent, 'Contractor fell through');

        $back = $fault->fresh();
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $back->status);
        $this->assertNull($back->workOrderBlockReason());
        $rows = app(RentalCommandCentreService::class)->queueItems($this->agent, 'all')->filter(fn ($i) => $i['type'] === 'fault_appoint_contractor');
        $this->assertCount(1, $rows);

        $this->raise($back, ['assignment_type' => 'internal'])->assertSessionHasNoErrors();   // appoint again
        $this->assertSame(2, RentalWorkOrder::withoutGlobalScopes()->where('reported_fault_report_id', $fault->id)->count());

        // an owner-handles fault goes back to its own status; one never decided goes back to where it was
        $own = $this->approved('owner_handles', ['contractor_name' => 'Bob']);
        $this->raise($own, []);
        $own->fresh()->workOrder->cancel($this->agent, 'x');
        $this->assertSame(RentalFaultReport::STATUS_OWNER_HANDLING, $own->fresh()->status);
        $early = $this->fault();
        $this->raise($early, ['assignment_type' => 'internal']);
        $early->fresh()->workOrder->cancel($this->agent, 'x');
        $this->assertSame(RentalFaultReport::STATUS_REPORTED, $early->fresh()->status);
    }

    // ── 7. the work order screen: one set of names, one contractor, stage-fitting actions ──

    public function test_the_work_order_badge_history_and_header_agree_with_the_real_stage(): void
    {
        $fault = $this->approved();
        $plumber = $this->contractor();
        $this->raise($fault, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $plumber->id]);
        $wo = $fault->fresh()->workOrder;

        $this->assertSame('Created', $wo->stageLabel('agent'));
        $quote = $wo->recordQuote(['agency_service_provider_id' => $plumber->id, 'amount' => 300, 'quote_date' => now(), 'detail_text' => 'x'], $this->agent);
        $wo->selectQuote($quote, $this->agent);
        $this->assertSame('Approved - ready to send', $wo->fresh()->stageLabel('agent'), 'within the limit: approved, ready to send');

        $wo->fresh()->assignSupplier($plumber->id, null, $this->agent);
        $sent = $wo->fresh();
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $sent->status);
        $this->assertSame('Sent to contractor', $sent->stageLabel('agent'), 'the badge follows the real stage');
        $this->assertSame('Created', $sent->stageLabel('tenant'), 'the tenant and owner keep their plain stage words');

        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $sent))->assertOk()->getContent();
        $this->assertStringContainsString('Sent to contractor', $html);
        $this->assertStringContainsString('(Created &rarr; Sent to contractor)', $html);
        $this->assertStringNotContainsString('Ordered', $html);
        $this->assertStringContainsString('Ramsgate Plumbing', $sent->contractorLabel());
    }

    public function test_the_header_names_the_contractor_from_the_selected_quote_before_the_work_order_is_sent(): void
    {
        $fault = $this->approved();
        $plumber = $this->contractor();
        $other = $this->contractor('Quote Giver Plumbing');
        $this->raise($fault, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $plumber->id]);
        $wo = $fault->fresh()->workOrder;
        $wo->forceFill(['agency_service_provider_id' => null])->save();
        $this->assertNull($wo->fresh()->contractorLabel());

        $quote = $wo->recordQuote(['agency_service_provider_id' => $other->id, 'amount' => 700, 'quote_date' => now(), 'detail_text' => 'x'], $this->agent);
        $wo->selectQuote($quote, $this->agent);

        $this->assertSame('Quote Giver Plumbing', $wo->fresh()->contractorLabel());
        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Supplier:<\/span>\s*Quote Giver Plumbing/', $html);
        $this->assertStringNotContainsString('Supplier:</span> —', $html);
    }

    public function test_the_work_order_screen_shows_only_the_actions_that_fit_its_stage(): void
    {
        $fault = $this->approved();
        $plumber = $this->contractor();
        $this->raise($fault, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $plumber->id]);
        $wo = $fault->fresh()->workOrder;
        $get = fn () => $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo->fresh()))->assertOk()->getContent();

        // just created: capture the quote; nothing for later stages
        $html = $get();
        $this->assertStringContainsString('data-next-step', $html);
        $this->assertStringContainsString('R500.00 or less', $html);
        $this->assertStringContainsString("Capture Ramsgate Plumbing's quote", $html);
        $this->assertStringNotContainsString('data-complete-work-order', $html, 'no Complete before it is sent');
        $this->assertStringContainsString('data-complete-later', $html);
        $this->assertStringNotContainsString('data-invoices-panel', $html, 'no supplier invoices before it is sent');
        $this->assertStringNotContainsString('data-fee-collapse', $html, 'no fee block before there is a quote');
        $this->assertStringContainsString('data-emergency-collapse', $html, 'the emergency route is there, collapsed');

        // a quote over the limit: the owner is asked
        $quote = $wo->recordQuote(['agency_service_provider_id' => $plumber->id, 'amount' => 2000, 'quote_date' => now(), 'detail_text' => 'x'], $this->agent);
        $wo->fresh()->selectQuote($quote, $this->agent);
        $html = $get();
        $this->assertStringContainsString('data-quote-sent-to-owner', $html);
        $this->assertStringContainsString('for approval.', $html);
        $this->assertStringContainsString("Record decision on the owner's behalf", $html);
        $this->assertStringContainsString('data-fee-collapse', $html);

        // the owner approved: no Record decision, no emergency panel, still no Complete
        $wo->fresh()->recordApproval($this->agent, ['decision' => 'approved', 'evidence_type' => 'verbal_note', 'evidence_text' => 'ok']);
        $html = $get();
        $this->assertStringNotContainsString("Record decision on the owner's behalf", $html);
        $this->assertStringNotContainsString('wo-approval-form', $html);
        $this->assertStringNotContainsString('id="emergency-panel"', $html);
        $this->assertStringNotContainsString('data-complete-work-order', $html);

        // sent + started: invoices and Complete appear
        $wo->fresh()->assignSupplier($plumber->id, null, $this->agent);
        $this->assertStringContainsString('data-invoices-panel', $get());
        $wo->fresh()->startProgress($this->agent);
        $html = $get();
        $this->assertStringContainsString('data-complete-work-order', $html);
        $this->assertStringNotContainsString('data-complete-later', $html);
    }

    public function test_quote_and_invoice_pick_lists_offer_maintenance_contractors_only(): void
    {
        $fault = $this->approved();
        $plumber = $this->contractor();
        $attorney = $this->contractor('Jafta Incorporated', 'transfer_attorney', ['is_transfer_attorney' => true]);
        $this->raise($fault, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $plumber->id]);
        $wo = $fault->fresh()->workOrder;
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_ORDERED])->save();

        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('Ramsgate Plumbing', $html);
        $this->assertStringNotContainsString('Jafta Incorporated', $html);

        // and the server refuses one posted by hand
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.quotes.store', $wo), [
            'agency_service_provider_id' => $attorney->id, 'amount' => 100, 'quote_date' => now()->toDateString(), 'detail_text' => 'x',
        ])->assertSessionHasErrors('agency_service_provider_id');
        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.invoices.store', $wo), [
            'invoice_number' => 'A1', 'invoice_date' => now()->toDateString(), 'amount' => 10, 'agency_service_provider_id' => $attorney->id,
            'document' => \Illuminate\Http\UploadedFile::fake()->create('i.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('agency_service_provider_id');
        $this->assertSame(0, $wo->quotes()->count());
    }

    // ── 8. Report a Fault: a searchable pick of tenancies ────────────────────

    public function test_report_a_fault_from_the_list_is_a_searchable_pick_of_tenancies_not_a_dropdown_of_every_property(): void
    {
        // a rental with NO lease (must not be offered) and a second tenancy
        Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'EMPTY Vacant Unit', 'status' => 'active', 'listing_type' => 'rental']);
        $second = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'Second Unit Zulu', 'status' => 'active', 'listing_type' => 'rental']);
        $lease2 = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $second->id, 'status' => 'active', 'rental_amount' => 7000,
            'deposit_amount' => 7000, 'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $zed = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Zandile', 'last_name' => 'Zulu', 'email' => 'z+' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $lease2->id, 'contact_id' => $zed->id, 'is_primary' => true]);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.create'))->assertOk()->getContent();
        $this->assertStringContainsString('data-fault-lease-picker', $html);
        $this->assertStringNotContainsString('<select name="property_id"', $html);
        $this->assertStringContainsString('Tina Tenant', $html);
        $this->assertStringContainsString('Zandile Zulu', $html);
        $this->assertStringNotContainsString('EMPTY Vacant Unit', $html, 'a property with no lease is not offered');
        $this->assertStringNotContainsString('name="title"', $html, 'the fault form opens after the tenancy is chosen');

        // search by tenant
        $found = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.create', ['q' => 'Zandile']))->assertOk()->getContent();
        $this->assertStringContainsString('Zandile Zulu', $found);
        $this->assertStringNotContainsString('Tina Tenant', $found);

        // choosing one is the same entry as "report a fault" from a lease
        $this->assertStringContainsString('property_id=' . $second->id . '&amp;lease_id=' . $lease2->id, $found);
        $form = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.create', ['property_id' => $second->id, 'lease_id' => $lease2->id]))->assertOk()->getContent();
        $this->assertStringContainsString('name="title"', $form);
        $this->assertStringContainsString('Zandile Zulu', $form);
        $this->assertStringNotContainsString('data-fault-lease-picker', $form);
    }
}
