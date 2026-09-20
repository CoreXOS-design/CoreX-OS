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

    /** One tiny valid base64 PNG, reused wherever a test needs a real (if trivial) signature image. */
    private const TEST_SIGNATURE_IMAGE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private function makeTenant(): \App\Models\Contact
    {
        $contact = \App\Models\Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Thabo', 'last_name' => 'Tenant', 'email' => uniqid() . '@example.test',
        ]);
        \App\Models\LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);

        return $contact;
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

    // ── Starting an inspection (§0.5 — deliberate, never auto-created) ──

    public function test_the_tab_has_no_current_inspection_until_one_is_explicitly_started(): void
    {
        $response = $this->getJson(route('corex.properties.rental-inspection-tab.data', $this->property));

        $response->assertOk();
        $this->assertNull($response->json('in_inspection'));
        $this->assertSame(0, RentalInspection::count(), 'opening the tab must never silently create an inspection');
    }

    public function test_starting_an_in_inspection_makes_it_the_current_one(): void
    {
        $this->postJson(route('corex.properties.rental-inspections.start', $this->property), ['type' => RentalInspection::TYPE_IN])
            ->assertStatus(201);

        $response = $this->getJson(route('corex.properties.rental-inspection-tab.data', $this->property));
        $this->assertNotNull($response->json('in_inspection'));
        $this->assertSame($this->lease->id, $response->json('in_inspection.lease_id'));
    }

    public function test_starting_a_second_in_inspection_while_one_is_already_under_way_is_refused(): void
    {
        $this->postJson(route('corex.properties.rental-inspections.start', $this->property), ['type' => RentalInspection::TYPE_IN])
            ->assertStatus(201);

        $this->postJson(route('corex.properties.rental-inspections.start', $this->property), ['type' => RentalInspection::TYPE_IN])
            ->assertStatus(409);

        $this->assertSame(1, RentalInspection::where('type', RentalInspection::TYPE_IN)->count());
    }

    public function test_multiple_ad_hoc_inspections_can_be_started_at_once(): void
    {
        $this->postJson(route('corex.properties.rental-inspections.start', $this->property), ['type' => RentalInspection::TYPE_AD_HOC])
            ->assertStatus(201);
        $this->postJson(route('corex.properties.rental-inspections.start', $this->property), ['type' => RentalInspection::TYPE_AD_HOC])
            ->assertStatus(201);

        $this->assertSame(2, RentalInspection::where('type', RentalInspection::TYPE_AD_HOC)->count());
    }

    public function test_starting_an_inspection_with_no_active_lease_is_refused(): void
    {
        $this->lease->update(['status' => Lease::STATUS_EXPIRED]);

        $this->postJson(route('corex.properties.rental-inspections.start', $this->property), ['type' => RentalInspection::TYPE_IN])
            ->assertStatus(409);
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
     * §15, Stage 3 (2026-09-20) — the old single-tenant, auto-resolving
     * signer_role/refused_note request shape (and the sign_on_behalf
     * permission check that lived only inside its agent_on_behalf branch)
     * is fully retired now that out-inspection uses the same shared
     * per-party UI in-inspection has used since Stage 2. Replaces three
     * obsolete tests that covered that old shape's specific limitations
     * (permission bypass, temporarily-disabled refusal, multi-tenant
     * refusal) — the multi-tenant case in particular is no longer a
     * limitation at all: the new shape asks for an explicit
     * party_contact_id, so it never had to guess which tenant signed.
     *
     * NOTE for Stage 4: rental_inspections.sign_on_behalf has no caller at
     * all right now (its only check lived in the removed old branch) —
     * real refusal-recording needs to decide whether/how that permission
     * gates it, not assume it's already wired.
     */
    public function test_multiple_tenants_on_the_same_out_inspection_each_sign_independently(): void
    {
        $tenantOne = $this->makeTenant();
        $tenantTwo = $this->makeTenant();
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);
        $inspection->startAwaitingSignature();

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_TENANT,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'party_contact_id' => $tenantOne->id,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(201);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_TENANT,
            'disposition' => RentalInspectionSignature::DISPOSITION_REFUSED,
            'party_contact_id' => $tenantTwo->id,
            'refusal_reason_preset' => 'not_present',
        ])->assertStatus(201);

        $this->assertCount(2, $inspection->signatures()->where('party_role', 'tenant')->get());
    }

    public function test_the_landlord_can_sign_an_out_inspection_over_real_http(): void
    {
        $property = $this->property;
        $landlord = \App\Models\Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Lindiwe', 'last_name' => 'Landlord', 'email' => uniqid() . '@example.test',
        ]);
        \App\Models\ContactProperty::create(['contact_id' => $landlord->id, 'property_id' => $property->id, 'role' => 'landlord']);
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);
        $inspection->startAwaitingSignature();

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_LANDLORD,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'party_contact_id' => $landlord->id,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(201)->assertJsonFragment(['disposition' => 'signed', 'party_role' => 'landlord']);
    }

    public function test_the_new_canonical_signature_shape_works_directly(): void
    {
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_TENANT,
            'disposition' => RentalInspectionSignature::DISPOSITION_REFUSED,
            'party_contact_id' => $tenant->id,
            'refusal_reason_preset' => 'not_present',
        ])->assertStatus(201)
          ->assertJsonFragment(['disposition' => 'refused', 'party_role' => 'tenant']);
    }

    // ── §15.5, Stage 4 — refusal capture, gated on sign_on_behalf ───────

    /**
     * Seeding .view/.create ONLY (never .sign_on_behalf) flips the table
     * from unseeded (allow-all fallback) to strictly-enrolled — the same
     * technique the old agent_on_behalf test used, now proving the real
     * successor permission genuinely gates the new refusal action.
     */
    public function test_a_refusal_requires_the_sign_on_behalf_permission(): void
    {
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'rental_inspections.view', 'scope' => 'own']);
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'rental_inspections.create', 'scope' => 'own']);
        \App\Services\PermissionService::clearCache();
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_TENANT,
            'disposition' => RentalInspectionSignature::DISPOSITION_REFUSED,
            'party_contact_id' => $tenant->id,
            'refusal_reason_preset' => 'not_present',
        ])->assertStatus(403);
    }

    public function test_signing_does_not_require_the_sign_on_behalf_permission(): void
    {
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'rental_inspections.view', 'scope' => 'own']);
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'rental_inspections.create', 'scope' => 'own']);
        \App\Services\PermissionService::clearCache();
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_TENANT,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'party_contact_id' => $tenant->id,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(201);
    }

    public function test_the_agent_can_never_be_refused_over_real_http(): void
    {
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_AGENT,
            'disposition' => RentalInspectionSignature::DISPOSITION_REFUSED,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(422);
    }

    public function test_tab_payload_exposes_the_refusal_reason_presets(): void
    {
        $response = $this->getJson(route('corex.properties.rental-inspection-tab.data', $this->property));

        $response->assertOk();
        $presets = collect($response->json('refusal_reason_presets'));
        $this->assertSame('other', $presets->last()['key']);
    }

    // ── §15.3, Stage 2 — in-inspection signing, the whole new path ──────

    public function test_an_in_inspection_can_start_its_own_signing_window_over_real_http(): void
    {
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);

        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $inspection))
            ->assertOk()
            ->assertJsonFragment(['status' => RentalInspection::STATUS_AWAITING_SIGNATURE]);
    }

    public function test_a_tenant_can_sign_an_in_inspection_over_real_http(): void
    {
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);
        $inspection->startAwaitingSignature();

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_TENANT,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'party_contact_id' => $tenant->id,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(201)->assertJsonFragment(['disposition' => 'signed', 'party_role' => 'tenant']);
    }

    public function test_the_agent_cannot_sign_an_in_inspection_until_the_tenant_has_over_real_http(): void
    {
        $this->makeTenant();
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);
        $inspection->startAwaitingSignature();

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_AGENT,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(422);
    }

    public function test_the_agent_can_sign_an_in_inspection_once_the_tenant_has_over_real_http(): void
    {
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);
        $inspection->startAwaitingSignature();
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $tenant->id, 'party_signature_path' => 'signatures/tenant.png',
        ]);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => RentalInspectionSignature::PARTY_AGENT,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'signature_image' => self::TEST_SIGNATURE_IMAGE,
        ])->assertStatus(201)->assertJsonFragment(['disposition' => 'signed', 'party_role' => 'agent']);
    }

    /**
     * Stage 5 (§15.7) is what makes signing MANDATORY on an in-inspection —
     * this stage only adds the ABILITY to sign. Proving the old, unchanged
     * guard still lets an unsigned in-inspection complete, exactly as
     * before, so nothing here silently starts enforcing early.
     */
    public function test_an_in_inspection_still_completes_without_any_signature_in_this_stage(): void
    {
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))
            ->assertOk()
            ->assertJsonFragment(['status' => RentalInspection::STATUS_COMPLETED]);
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
