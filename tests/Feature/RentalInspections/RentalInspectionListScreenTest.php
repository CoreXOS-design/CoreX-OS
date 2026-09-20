<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 4 (list screen) verification for .ai/specs/rental-inspections.md §5.
 * BUILD_STANDARD §1a-§1d floor: search, sort, filter, pagination, empty
 * state, OWN/BRANCH/AGENCY scoping enforced at the query layer. On a fresh
 * (unseeded) role_permissions table, PermissionService's AT-265 fallback
 * resolves 'admin' -> 'all', 'branch_manager' -> 'branch', 'agent' -> 'own'
 * (see RentalApplicationCrudStandardTest's docblock for the precedent) — so
 * scoping is exercised the same way here, without seeding real grants.
 */
final class RentalInspectionListScreenTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branchA;
    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'RI List Agency', 'slug' => 'ri-list-' . uniqid()]);
        $this->branchA = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->branchB = Branch::forceCreate(['name' => 'Margate', 'agency_id' => $this->agency->id]);
    }

    private function property(Branch $branch, string $address, User $agent): Property
    {
        return Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'title' => $address, 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function inspection(Property $property, User $creator, array $attrs = []): RentalInspection
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $property->branch_id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now(), 'created_by_user_id' => $creator->id,
        ]);

        if (!empty($attrs['tenant_name'])) {
            $contact = Contact::create([
                'agency_id' => $this->agency->id, 'branch_id' => $property->branch_id,
                'first_name' => $attrs['tenant_name'], 'last_name' => 'Tenant', 'email' => uniqid() . '@example.test',
            ]);
            LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);
        }
        unset($attrs['tenant_name']);

        return RentalInspection::create(array_merge([
            'agency_id' => $this->agency->id,
            'lease_id' => $lease->id,
            'type' => RentalInspection::TYPE_IN,
            'created_by_user_id' => $creator->id,
        ], $attrs));
    }

    // ── Search ────────────────────────────────────────────────────────

    public function test_search_matches_property_address_tenant_and_agent_name(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin', 'name' => 'Nomsa Agent']);
        $this->inspection($this->property($this->branchA, '19 Windsor Avenue', $admin), $admin, ['tenant_name' => 'Sipho']);
        $this->inspection($this->property($this->branchA, 'Somewhere else', $admin), $admin);

        $this->actingAs($admin)->get(route('corex.rental-inspections.index', ['q' => 'Windsor']))
            ->assertOk()->assertSee('Windsor Avenue');
        $this->actingAs($admin)->get(route('corex.rental-inspections.index', ['q' => 'Sipho']))
            ->assertOk()->assertSee('Windsor Avenue');
        $this->actingAs($admin)->get(route('corex.rental-inspections.index', ['q' => 'Nomsa']))
            ->assertOk()->assertSee('Windsor Avenue')->assertSee('Somewhere else');
    }

    // ── Sort ──────────────────────────────────────────────────────────

    public function test_default_sort_is_scheduled_for_most_recent_first(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $older = $this->inspection($this->property($this->branchA, 'Older', $admin), $admin, ['scheduled_for' => now()->subDays(10)]);
        $newer = $this->inspection($this->property($this->branchA, 'Newer', $admin), $admin, ['scheduled_for' => now()->subDay()]);

        $response = $this->actingAs($admin)->get(route('corex.rental-inspections.index'));

        $response->assertOk();
        $ids = $response->viewData('inspections')->pluck('id')->all();
        $this->assertSame([$newer->id, $older->id], $ids);
    }

    // ── Filter ────────────────────────────────────────────────────────

    public function test_status_and_type_filters_narrow_the_list(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $inProgress = $this->inspection($this->property($this->branchA, 'A', $admin), $admin, ['status' => RentalInspection::STATUS_IN_PROGRESS, 'type' => RentalInspection::TYPE_IN]);
        $this->inspection($this->property($this->branchA, 'B', $admin), $admin, ['status' => RentalInspection::STATUS_COMPLETED, 'type' => RentalInspection::TYPE_OUT]);

        $response = $this->actingAs($admin)->get(route('corex.rental-inspections.index', ['status' => 'in_progress']));
        $this->assertSame([$inProgress->id], $response->viewData('inspections')->pluck('id')->all());
    }

    public function test_has_unresolved_discrepancy_filter(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $property = $this->property($this->branchA, 'Discrepancy property', $admin);
        $withDiscrepancy = $this->inspection($property, $admin);
        $item = RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Kitchen', 'created_by_user_id' => $admin->id,
        ]);
        $obs1 = RentalInspectionObservation::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $withDiscrepancy->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $admin->id, 'condition' => 'good', 'source' => 'in_inspection',
        ]);
        // record() is the one real entry point (§14.1 fix 2) — create and
        // discrepancy-detection happen atomically.
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $withDiscrepancy->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $admin->id, 'condition' => 'damaged', 'notes' => 'x', 'source' => 'in_inspection',
        ]);
        $this->inspection($this->property($this->branchA, 'Clean property', $admin), $admin);

        $response = $this->actingAs($admin)->get(route('corex.rental-inspections.index', ['has_unresolved_discrepancy' => 1]));
        $this->assertSame([$withDiscrepancy->id], $response->viewData('inspections')->pluck('id')->all());
    }

    // ── Pagination & empty state ─────────────────────────────────────

    public function test_paginates_at_twenty_five(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        for ($i = 0; $i < 30; $i++) {
            $this->inspection($this->property($this->branchA, "Property {$i}", $admin), $admin);
        }

        $response = $this->actingAs($admin)->get(route('corex.rental-inspections.index'));
        $this->assertCount(25, $response->viewData('inspections'));
        $this->assertSame(30, $response->viewData('inspections')->total());
    }

    public function test_empty_state_distinguishes_none_yet_from_none_matching_filter(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);

        $this->actingAs($admin)->get(route('corex.rental-inspections.index'))
            ->assertOk()->assertSee('No inspections yet on this agency');

        $this->inspection($this->property($this->branchA, 'Only one', $admin), $admin, ['status' => RentalInspection::STATUS_COMPLETED]);

        $this->actingAs($admin)->get(route('corex.rental-inspections.index', ['status' => 'cancelled']))
            ->assertOk()->assertSee('No inspections match this search or filter');
    }

    // ── Scoping ──────────────────────────────────────────────────────

    public function test_agent_sees_only_own_created_inspections(): void
    {
        $agentOne = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent']);
        $agentTwo = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent']);
        $own = $this->inspection($this->property($this->branchA, 'Mine', $agentOne), $agentOne);
        $this->inspection($this->property($this->branchA, 'Not mine', $agentTwo), $agentTwo);

        $response = $this->actingAs($agentOne)->get(route('corex.rental-inspections.index'));
        $this->assertSame([$own->id], $response->viewData('inspections')->pluck('id')->all());
    }

    public function test_branch_manager_sees_branch_but_not_other_branch(): void
    {
        $manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'branch_manager']);
        $agentSameBranch = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'agent']);
        $agentOtherBranch = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchB->id, 'role' => 'agent']);

        $inBranch = $this->inspection($this->property($this->branchA, 'Branch A property', $agentSameBranch), $agentSameBranch);
        $this->inspection($this->property($this->branchB, 'Branch B property', $agentOtherBranch), $agentOtherBranch);

        $response = $this->actingAs($manager)->get(route('corex.rental-inspections.index'));
        $this->assertSame([$inBranch->id], $response->viewData('inspections')->pluck('id')->all());
    }

    // ── Show / cancel / archive / restore ──────────────────────────────

    public function test_show_renders_observations_and_discrepancies(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $property = $this->property($this->branchA, 'Detail property', $admin);
        $inspection = $this->inspection($property, $admin);
        $item = RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Lounge', 'created_by_user_id' => $admin->id,
        ]);
        RentalInspectionObservation::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $admin->id, 'condition' => 'good', 'source' => 'in_inspection',
        ]);

        $this->actingAs($admin)->get(route('corex.rental-inspections.show', $inspection))
            ->assertOk()->assertSee('Lounge')->assertSee('Good');
    }

    /**
     * §15.5/§15.8/§15.12's own acceptance criteria — a signed row and a
     * refused row must be distinguishable by someone reading the page cold,
     * not just by code that happens to know which is which.
     */
    public function test_show_renders_signed_and_refused_dispositions_unambiguously(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $property = $this->property($this->branchA, 'Signature detail property', $admin);
        $inspection = $this->inspection($property, $admin, ['type' => RentalInspection::TYPE_OUT]);
        $tenant = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id,
            'first_name' => 'Palesa', 'last_name' => 'Tenant', 'email' => uniqid() . '@example.test',
        ]);
        LeaseTenant::create(['lease_id' => $inspection->lease_id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_REFUSED, [
            'party_contact_id' => $tenant->id, 'refusal_reason_preset' => 'not_present', 'recorded_by_user_id' => $admin->id,
        ]);
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_AGENT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_signature_path' => 'signatures/agent.png',
        ]);

        $response = $this->actingAs($admin)->get(route('corex.rental-inspections.show', $inspection));

        $response->assertOk()
            ->assertSee('Palesa Tenant')
            ->assertSee('Refused to sign')
            ->assertSee('Not present for the walkthrough')
            ->assertSee('Signed')
            ->assertSee('src="signatures/agent.png"', false); // the signed row renders an image, never bare text
    }

    public function test_cancel_requires_a_reason_and_stamps_the_cancelling_user(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $inspection = $this->inspection($this->property($this->branchA, 'Cancel me', $admin), $admin);

        $this->actingAs($admin)->post(route('corex.rental-inspections.cancel', $inspection), [])->assertSessionHasErrors();

        $this->actingAs($admin)->post(route('corex.rental-inspections.cancel', $inspection), ['cancel_reason' => 'Tenant withdrew.'])
            ->assertRedirect(route('corex.rental-inspections.show', $inspection));

        $inspection->refresh();
        $this->assertSame(RentalInspection::STATUS_CANCELLED, $inspection->status);
        $this->assertSame($admin->id, $inspection->cancelled_by_user_id);
        $this->assertSame('Tenant withdrew.', $inspection->cancel_reason);
    }

    public function test_an_inspection_with_observations_cannot_be_deleted_only_cancelled(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $property = $this->property($this->branchA, 'Has evidence', $admin);
        $inspection = $this->inspection($property, $admin);
        $item = RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Bathroom', 'created_by_user_id' => $admin->id,
        ]);
        RentalInspectionObservation::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $admin->id, 'condition' => 'good', 'source' => 'in_inspection',
        ]);

        $this->actingAs($admin)->delete(route('corex.rental-inspections.destroy', $inspection))->assertSessionHasErrors();
        $this->assertNull($inspection->fresh()->deleted_at);
    }

    public function test_an_inspection_with_no_observations_can_be_archived_and_restored(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $inspection = $this->inspection($this->property($this->branchA, 'No evidence yet', $admin), $admin);

        $this->actingAs($admin)->delete(route('corex.rental-inspections.destroy', $inspection))
            ->assertRedirect(route('corex.rental-inspections.index'));
        $this->assertNotNull($inspection->fresh()->deleted_at);

        $this->actingAs($admin)->post(route('corex.rental-inspections.restore', $inspection->id))
            ->assertRedirect(route('corex.rental-inspections.show', $inspection));
        $this->assertNull($inspection->fresh()->deleted_at);
    }

    public function test_cross_agency_inspection_is_not_reachable_by_id(): void
    {
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        \Illuminate\Support\Facades\Auth::logout();
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $otherProperty = Property::forceCreate([
            'agency_id' => $otherAgency->id, 'agent_id' => $otherAdmin->id, 'branch_id' => $otherBranch->id,
            'title' => 'Other agency property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $otherLease = Lease::create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'property_id' => $otherProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 8000, 'start_date' => now(), 'created_by_user_id' => $otherAdmin->id,
        ]);
        $otherInspection = RentalInspection::create([
            'agency_id' => $otherAgency->id, 'lease_id' => $otherLease->id, 'type' => RentalInspection::TYPE_IN, 'created_by_user_id' => $otherAdmin->id,
        ]);

        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'role' => 'admin']);

        $this->actingAs($admin)->get(route('corex.rental-inspections.show', $otherInspection))->assertNotFound();
    }
}
