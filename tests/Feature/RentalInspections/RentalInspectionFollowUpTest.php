<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Contact;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §15 (AT-447) — the inspection Follow-up
 * block: list contents (non-baseline observations only), the three create
 * actions (fault report / work order / job card), idempotency, "combine
 * into one," and the lease-first property resolution.
 */
final class RentalInspectionFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Follow-up Agency', 'slug' => 'followup-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Follow-up Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12000, 'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeItem(string $label = 'Geyser'): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => $label, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeInspection(string $type = RentalInspection::TYPE_IN): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeObservation(RentalInspection $inspection, RentalInspectionItem $item, string $condition, array $extra = []): RentalInspectionObservation
    {
        return RentalInspectionObservation::record(array_merge([
            'agency_id' => $this->agency->id,
            'rental_inspection_id' => $inspection->id,
            'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id,
            'condition' => $condition,
            'notes' => 'Test note',
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ], $extra));
    }

    public function test_follow_up_block_lists_only_non_baseline_observations(): void
    {
        $inspection = $this->makeInspection();
        $good = $this->makeObservation($inspection, $this->makeItem('Bedroom'), RentalInspectionObservation::CONDITION_GOOD);
        $damaged = $this->makeObservation($inspection, $this->makeItem('Geyser'), RentalInspectionObservation::CONDITION_DAMAGED);

        $response = $this->actingAs($this->agent)->get(route('corex.rental-inspections.show', $inspection));

        $response->assertOk();
        $response->assertSee('Follow-up');
        $response->assertSee('Geyser');
        $response->assertDontSee('observation_ids[]" value="' . $good->id . '"', false);
    }

    public function test_create_fault_report_sets_fks_and_resolves_lease_and_property(): void
    {
        $inspection = $this->makeInspection();
        $item = $this->makeItem('Geyser');
        $observation = $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_DAMAGED);

        $response = $this->actingAs($this->agent)->post(
            route('corex.rental-inspections.follow-up.fault-reports', $inspection),
            ['observation_ids' => [$observation->id]]
        );

        $response->assertRedirect(route('corex.rental-inspections.show', $inspection));
        $faultReport = RentalFaultReport::firstOrFail();
        $this->assertSame($item->id, $faultReport->rental_inspection_item_id);
        $this->assertSame($observation->id, $faultReport->reported_inspection_observation_id);
        $this->assertSame($this->lease->id, $faultReport->lease_id);
        $this->assertSame($this->property->id, $faultReport->property_id);
        // No room set on the item — defaultTitleFor() falls back to "General".
        $this->assertSame('General — Geyser: Damaged', $faultReport->title);
    }

    public function test_out_inspection_defaults_reported_by_to_agent_not_tenant(): void
    {
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);
        $observation = $this->makeObservation($inspection, $this->makeItem(), RentalInspectionObservation::CONDITION_DAMAGED);

        $this->actingAs($this->agent)->post(
            route('corex.rental-inspections.follow-up.fault-reports', $inspection),
            ['observation_ids' => [$observation->id]]
        );

        $faultReport = RentalFaultReport::firstOrFail();
        $this->assertSame(RentalFaultReport::REPORTED_BY_AGENT_NOTICED, $faultReport->reported_by_type);
    }

    public function test_in_inspection_defaults_reported_by_to_tenant(): void
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => uniqid() . '@example.test',
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);

        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);
        $observation = $this->makeObservation($inspection, $this->makeItem(), RentalInspectionObservation::CONDITION_DAMAGED);

        $this->actingAs($this->agent)->post(
            route('corex.rental-inspections.follow-up.fault-reports', $inspection),
            ['observation_ids' => [$observation->id]]
        );

        $faultReport = RentalFaultReport::firstOrFail();
        $this->assertSame(RentalFaultReport::REPORTED_BY_TENANT, $faultReport->reported_by_type);
        $this->assertSame($contact->id, $faultReport->reported_by_contact_id);
    }

    public function test_an_already_raised_item_is_not_duplicated(): void
    {
        $inspection = $this->makeInspection();
        $observation = $this->makeObservation($inspection, $this->makeItem(), RentalInspectionObservation::CONDITION_DAMAGED);

        $this->actingAs($this->agent)->post(route('corex.rental-inspections.follow-up.fault-reports', $inspection), ['observation_ids' => [$observation->id]]);
        $this->assertSame(1, RentalFaultReport::count());

        $response = $this->actingAs($this->agent)->post(route('corex.rental-inspections.follow-up.fault-reports', $inspection), ['observation_ids' => [$observation->id]]);

        $response->assertRedirect(route('corex.rental-inspections.show', $inspection));
        $this->assertSame(1, RentalFaultReport::count(), 'The same observation must not raise a second fault report.');

        // The show page now shows the link, not the create button.
        $show = $this->actingAs($this->agent)->get(route('corex.rental-inspections.show', $inspection));
        $show->assertSee('Fault report #');
    }

    public function test_combine_ticked_items_into_one_fault_report(): void
    {
        $inspection = $this->makeInspection();
        $obsA = $this->makeObservation($inspection, $this->makeItem('Geyser'), RentalInspectionObservation::CONDITION_DAMAGED);
        $obsB = $this->makeObservation($inspection, $this->makeItem('Roof'), RentalInspectionObservation::CONDITION_NOT_WORKING);

        $response = $this->actingAs($this->agent)->post(
            route('corex.rental-inspections.follow-up.fault-reports', $inspection),
            ['observation_ids' => [$obsA->id, $obsB->id], 'combine' => '1']
        );

        $response->assertRedirect(route('corex.rental-inspections.show', $inspection));
        $this->assertSame(1, RentalFaultReport::count(), 'Combine must produce exactly one fault report for two ticked items.');
        $faultReport = RentalFaultReport::firstOrFail();
        $this->assertStringContainsString('Multiple items (2)', $faultReport->title);
        $this->assertStringContainsString('Geyser', $faultReport->description);
        $this->assertStringContainsString('Roof', $faultReport->description);
    }

    public function test_uncombined_multiple_items_create_one_fault_report_each(): void
    {
        $inspection = $this->makeInspection();
        $obsA = $this->makeObservation($inspection, $this->makeItem('Geyser'), RentalInspectionObservation::CONDITION_DAMAGED);
        $obsB = $this->makeObservation($inspection, $this->makeItem('Roof'), RentalInspectionObservation::CONDITION_NOT_WORKING);

        $this->actingAs($this->agent)->post(
            route('corex.rental-inspections.follow-up.fault-reports', $inspection),
            ['observation_ids' => [$obsA->id, $obsB->id]]
        );

        $this->assertSame(2, RentalFaultReport::count(), 'Without "combine", one record per ticked item is the stated default.');
    }

    public function test_single_item_create_work_order_opens_prefilled_create_form(): void
    {
        $inspection = $this->makeInspection();
        $item = $this->makeItem('Geyser');
        $observation = $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_DAMAGED);

        $response = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.create', [
            'rental_inspection_id' => $inspection->id,
            'observation_ids' => [$observation->id],
        ]));

        $response->assertOk();
        $response->assertSee('General — Geyser: Damaged', false);
        $response->assertSee('name="reported_inspection_observation_id" value="' . $observation->id . '"', false);
    }

    public function test_single_item_work_order_store_sets_fks(): void
    {
        $inspection = $this->makeInspection();
        $item = $this->makeItem('Geyser');
        $observation = $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_DAMAGED);

        $response = $this->actingAs($this->agent)->post(route('corex.rental-work-orders.store'), [
            'property_id' => $this->property->id,
            'lease_id' => $this->lease->id,
            'rental_inspection_item_id' => $item->id,
            'reported_inspection_observation_id' => $observation->id,
            'assignment_type' => 'outside_supplier',
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_INSPECTION,
            'title' => 'Geyser — Geyser: Damaged',
            'description' => 'Test note',
        ]);

        $workOrder = RentalWorkOrder::firstOrFail();
        $response->assertRedirect(route('corex.rental-work-orders.show', $workOrder));
        $this->assertSame($observation->id, $workOrder->reported_inspection_observation_id);
        $this->assertSame($item->id, $workOrder->rental_inspection_item_id);
        $this->assertSame(RentalWorkOrder::REPORTED_BY_INSPECTION, $workOrder->reported_by_type);
    }

    public function test_job_card_shortcut_presets_internal_on_the_create_form(): void
    {
        $inspection = $this->makeInspection();
        $observation = $this->makeObservation($inspection, $this->makeItem('Geyser'), RentalInspectionObservation::CONDITION_DAMAGED);

        $response = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.create', [
            'rental_inspection_id' => $inspection->id,
            'observation_ids' => [$observation->id],
            'assignment_type' => 'internal',
        ]));

        $response->assertOk();
        // The "Our maintenance team" radio is the one marked checked.
        $response->assertSee('value="internal" checked', false);
    }

    public function test_uncombined_multiple_items_batch_creates_one_work_order_each(): void
    {
        $inspection = $this->makeInspection();
        $obsA = $this->makeObservation($inspection, $this->makeItem('Geyser'), RentalInspectionObservation::CONDITION_DAMAGED);
        $obsB = $this->makeObservation($inspection, $this->makeItem('Roof'), RentalInspectionObservation::CONDITION_NOT_WORKING);

        $response = $this->actingAs($this->agent)->post(route('corex.rental-work-orders.store'), [
            'property_id' => $this->property->id,
            'lease_id' => $this->lease->id,
            'rental_inspection_id' => $inspection->id,
            'assignment_type' => 'outside_supplier',
            'batch_items' => [
                ['observation_id' => $obsA->id, 'rental_inspection_item_id' => $obsA->rental_inspection_item_id, 'title' => 'Geyser issue', 'description' => 'Leaking'],
                ['observation_id' => $obsB->id, 'rental_inspection_item_id' => $obsB->rental_inspection_item_id, 'title' => 'Roof issue', 'description' => 'Tile cracked'],
            ],
        ]);

        $response->assertRedirect(route('corex.rental-inspections.show', $inspection));
        $this->assertSame(2, RentalWorkOrder::count());
        $this->assertSame(
            [$obsA->id, $obsB->id],
            RentalWorkOrder::orderBy('id')->pluck('reported_inspection_observation_id')->all()
        );
    }

    public function test_batch_work_order_with_internal_assignment_creates_job_cards(): void
    {
        $inspection = $this->makeInspection();
        $obsA = $this->makeObservation($inspection, $this->makeItem('Geyser'), RentalInspectionObservation::CONDITION_DAMAGED);
        $obsB = $this->makeObservation($inspection, $this->makeItem('Roof'), RentalInspectionObservation::CONDITION_NOT_WORKING);

        $response = $this->actingAs($this->agent)->post(route('corex.rental-work-orders.store'), [
            'property_id' => $this->property->id,
            'lease_id' => $this->lease->id,
            'rental_inspection_id' => $inspection->id,
            'assignment_type' => 'internal',
            'batch_items' => [
                ['observation_id' => $obsA->id, 'rental_inspection_item_id' => $obsA->rental_inspection_item_id, 'title' => 'Geyser issue', 'description' => 'Leaking'],
                ['observation_id' => $obsB->id, 'rental_inspection_item_id' => $obsB->rental_inspection_item_id, 'title' => 'Roof issue', 'description' => 'Tile cracked'],
            ],
        ]);

        $response->assertRedirect(route('corex.rental-inspections.show', $inspection));
        $this->assertSame(2, RentalJobCard::count());
        $this->assertSame(2, RentalWorkOrder::where('assignment_type', RentalWorkOrder::ASSIGNMENT_INTERNAL)->count());
    }

    public function test_batch_skips_an_item_already_raised_between_render_and_submit(): void
    {
        $inspection = $this->makeInspection();
        $obsA = $this->makeObservation($inspection, $this->makeItem('Geyser'), RentalInspectionObservation::CONDITION_DAMAGED);
        $obsB = $this->makeObservation($inspection, $this->makeItem('Roof'), RentalInspectionObservation::CONDITION_NOT_WORKING);

        // obsA already has a work order by the time the batch submits (a race/resubmit).
        RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'lease_id' => $this->lease->id, 'reported_inspection_observation_id' => $obsA->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_INSPECTION, 'title' => 'Already raised', 'description' => 'x',
            'status' => RentalWorkOrder::STATUS_REPORTED, 'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
        ]);

        $this->actingAs($this->agent)->post(route('corex.rental-work-orders.store'), [
            'property_id' => $this->property->id,
            'lease_id' => $this->lease->id,
            'rental_inspection_id' => $inspection->id,
            'assignment_type' => 'outside_supplier',
            'batch_items' => [
                ['observation_id' => $obsA->id, 'rental_inspection_item_id' => $obsA->rental_inspection_item_id, 'title' => 'Geyser issue', 'description' => 'Leaking'],
                ['observation_id' => $obsB->id, 'rental_inspection_item_id' => $obsB->rental_inspection_item_id, 'title' => 'Roof issue', 'description' => 'Tile cracked'],
            ],
        ]);

        $this->assertSame(2, RentalWorkOrder::count(), 'obsA\'s existing work order must not be duplicated — only obsB gets a new one.');
    }

    /**
     * Just-merged origin/QA1 audit fix (f057e8dd2, "Audit M1") added
     * RentalInspection::resolveRouteBinding(), scoping the route-model
     * binding itself through scopeVisibleTo() — an out-of-scope id now
     * resolves to NO model at all (404) before this controller's own
     * guardRentalRecordScope() is ever reached, same as every other
     * RentalInspectionController action (RentalInspectionScopeGuardTest's
     * own assertNotFound() calls, not assertForbidden()). This test follows
     * that same, now-standard expectation.
     */
    public function test_an_out_of_scope_user_cannot_raise_a_fault_report_from_this_inspection(): void
    {
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        \App\Models\Role::firstOrCreate(['name' => 'agent', 'agency_id' => $this->agency->id], ['label' => 'Agent']);
        \App\Models\RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => 'rental_inspections.view', 'agency_id' => $this->agency->id],
            ['scope' => 'own'],
        );
        \App\Models\RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => 'rental_fault_reports.create', 'agency_id' => $this->agency->id],
            ['scope' => 'own'],
        );
        \App\Services\PermissionService::clearCache();

        $inspection = $this->makeInspection(); // created_by_user_id = $this->agent
        $observation = $this->makeObservation($inspection, $this->makeItem(), RentalInspectionObservation::CONDITION_DAMAGED);

        $this->actingAs($other)
            ->post(route('corex.rental-inspections.follow-up.fault-reports', $inspection), ['observation_ids' => [$observation->id]])
            ->assertNotFound();

        $this->assertSame(0, RentalFaultReport::count());
    }

    public function test_cross_agency_inspection_id_is_absorbed_not_an_error(): void
    {
        // A different agency's inspection id must never leak a raw
        // exception — AgencyScope 404s the route-model binding itself
        // (BUILD_STANDARD §4 — "not found" is a 404, never a 500).
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        $otherAgent = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $otherProperty = Property::forceCreate([
            'agency_id' => $otherAgency->id, 'agent_id' => $otherAgent->id, 'branch_id' => $otherBranch->id,
            'title' => 'Other Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $otherLease = Lease::create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'property_id' => $otherProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 8000, 'start_date' => now()->subMonth(),
            'created_by_user_id' => $otherAgent->id,
        ]);
        $otherInspection = RentalInspection::create([
            'agency_id' => $otherAgency->id, 'lease_id' => $otherLease->id, 'type' => RentalInspection::TYPE_IN, 'created_by_user_id' => $otherAgent->id,
        ]);

        $this->actingAs($this->agent)
            ->post(route('corex.rental-inspections.follow-up.fault-reports', $otherInspection), ['observation_ids' => [1]])
            ->assertStatus(404);
    }
}
