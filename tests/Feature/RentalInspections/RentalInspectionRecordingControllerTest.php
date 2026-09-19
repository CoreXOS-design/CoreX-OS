<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionDiscrepancy;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionSignature;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Stage 3 (recording) verification for .ai/specs/rental-inspections.md §14.
 * RentalInspectionRecordingController is deliberately thin — every test here
 * is really proving the controller calls the right model method with the
 * right data, not re-testing the model logic itself (already covered by
 * RentalInspectionDataModelTest/RentalInspectionWorkflowTest).
 */
final class RentalInspectionRecordingControllerTest extends TestCase
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

        $this->agency = Agency::create(['name' => 'RI Recording Agency', 'slug' => 'ri-recording-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'RI Recording Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12000, 'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeItem(): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Bedroom 1', 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeInspection(string $type = RentalInspection::TYPE_IN): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    // ── Items ────────────────────────────────────────────────────────

    public function test_agent_can_add_an_item_to_the_property(): void
    {
        $this->postJson(route('corex.properties.rental-inspection-items.store', $this->property), [
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Geyser cupboard',
        ])->assertOk();

        $this->assertDatabaseHas('rental_inspection_items', [
            'property_id' => $this->property->id, 'label' => 'Geyser cupboard', 'agency_id' => $this->agency->id,
        ]);
    }

    public function test_retiring_an_item_from_a_different_property_404s(): void
    {
        $item = $this->makeItem();
        $otherProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Other', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $this->postJson(route('corex.properties.rental-inspection-items.retire', [$otherProperty, $item]))
            ->assertNotFound();
    }

    public function test_retiring_an_item_sets_is_retired(): void
    {
        $item = $this->makeItem();

        $this->postJson(route('corex.properties.rental-inspection-items.retire', [$this->property, $item]))
            ->assertOk();

        $this->assertTrue($item->fresh()->is_retired);
    }

    // ── Observations ────────────────────────────────────────────────

    public function test_recording_an_observation_calls_the_atomic_record_path(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id,
            'condition' => RentalInspectionObservation::CONDITION_GOOD,
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ])->assertOk();

        $this->assertDatabaseHas('rental_inspection_observations', [
            'rental_inspection_item_id' => $item->id, 'condition' => 'good', 'observed_by_user_id' => $this->agent->id,
        ]);
    }

    public function test_recording_a_bad_condition_without_notes_is_rejected(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id,
            'condition' => RentalInspectionObservation::CONDITION_DAMAGED,
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('rental_inspection_observations', ['rental_inspection_item_id' => $item->id]);
    }

    public function test_two_conflicting_observations_through_the_controller_produce_one_discrepancy(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();

        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'good', 'source' => 'in_inspection',
        ])->assertOk();
        $this->postJson(route('corex.rental-inspections.observations.store', $inspection), [
            'rental_inspection_item_id' => $item->id, 'condition' => 'damaged', 'notes' => 'Cracked tile.', 'source' => 'in_inspection',
        ])->assertOk();

        $this->assertSame(1, RentalInspectionDiscrepancy::count());
    }

    // ── Photos ──────────────────────────────────────────────────────

    public function test_uploading_a_photo_attaches_it_to_the_observation(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $observation = RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'good', 'source' => 'in_inspection',
        ]);

        $this->postJson(
            route('corex.rental-inspections.observations.photos.store', [$inspection, $observation]),
            ['photo' => UploadedFile::fake()->image('geyser.jpg', 800, 600)]
        )->assertStatus(201);

        $this->assertSame(1, RentalInspectionPhoto::where('rental_inspection_observation_id', $observation->id)->count());
    }

    public function test_a_retried_photo_upload_with_the_same_key_returns_the_existing_record_not_a_duplicate(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $observation = RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'good', 'source' => 'in_inspection',
        ]);
        $key = (string) \Illuminate\Support\Str::uuid();

        $this->postJson(
            route('corex.rental-inspections.observations.photos.store', [$inspection, $observation]),
            ['photo' => UploadedFile::fake()->image('a.jpg'), 'client_idempotency_key' => $key]
        )->assertStatus(201);

        $this->postJson(
            route('corex.rental-inspections.observations.photos.store', [$inspection, $observation]),
            ['photo' => UploadedFile::fake()->image('a.jpg'), 'client_idempotency_key' => $key]
        )->assertOk();

        $this->assertSame(1, RentalInspectionPhoto::where('client_idempotency_key', $key)->count());
    }

    // ── Discrepancy resolution ──────────────────────────────────────

    public function test_resolving_a_discrepancy_requires_the_accepted_observation_to_be_a_participant(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'good', 'source' => 'in_inspection',
        ]);
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'damaged', 'notes' => 'x', 'source' => 'in_inspection',
        ]);
        $discrepancy = RentalInspectionDiscrepancy::first();

        $outsideObservation = RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $this->makeInspection()->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'good', 'source' => 'ad_hoc',
        ]);

        $this->postJson(route('corex.rental-inspections.discrepancies.resolve', [$inspection, $discrepancy]), [
            'accepted_observation_id' => $outsideObservation->id,
        ])->assertStatus(422);
    }

    public function test_resolving_a_discrepancy_with_a_real_participant_succeeds(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'good', 'source' => 'in_inspection',
        ]);
        $winner = RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'damaged', 'notes' => 'x', 'source' => 'in_inspection',
        ]);
        $discrepancy = RentalInspectionDiscrepancy::first();

        $this->postJson(route('corex.rental-inspections.discrepancies.resolve', [$inspection, $discrepancy]), [
            'accepted_observation_id' => $winner->id, 'resolution_note' => 'Confirmed damaged.',
        ])->assertOk();

        $this->assertNotNull($discrepancy->fresh()->resolved_at);
    }

    // ── Signatures ──────────────────────────────────────────────────

    /**
     * Grants 'agent' both .view (the route GROUP's own gate — every route
     * under corex.rental-inspections.* stacks it, this one included) and
     * .create (this specific route's gate), but NOT .sign_on_behalf. This
     * isolates the inline check (§6) from both route-level gates: a bare
     * forceProductionPosture() would deny the whole request before the
     * inline check ever ran, proving nothing about which check actually
     * fired — found by direct debugging when granting .create alone still
     * 403'd, tracing it to the group-level .view middleware.
     */
    private function grantCreateButNotSignOnBehalf(): void
    {
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'rental_inspections.view', 'scope' => 'own']);
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'rental_inspections.create', 'scope' => 'own']);
        PermissionService::clearCache();
    }

    public function test_tenant_signature_does_not_require_sign_on_behalf_permission(): void
    {
        $this->grantCreateButNotSignOnBehalf();
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'signer_role' => RentalInspectionSignature::SIGNER_TENANT,
        ])->assertStatus(201);
    }

    public function test_agent_on_behalf_signature_is_refused_without_the_sign_on_behalf_permission(): void
    {
        $this->grantCreateButNotSignOnBehalf();
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'signer_role' => RentalInspectionSignature::SIGNER_AGENT_ON_BEHALF,
            'refused_note' => 'Called three times — tenant refused to sign out inspection.',
        ])->assertStatus(403);
    }

    public function test_agent_on_behalf_signature_without_the_required_phrase_is_rejected(): void
    {
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'signer_role' => RentalInspectionSignature::SIGNER_AGENT_ON_BEHALF,
            'refused_note' => 'Tenant did not respond.',
        ])->assertStatus(422);
    }

    // ── Lifecycle passthroughs ──────────────────────────────────────

    public function test_completing_an_inspection_with_an_unresolved_discrepancy_returns_409_not_500(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'good', 'source' => 'in_inspection',
        ]);
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'damaged', 'notes' => 'x', 'source' => 'in_inspection',
        ]);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertStatus(409);
    }
}
