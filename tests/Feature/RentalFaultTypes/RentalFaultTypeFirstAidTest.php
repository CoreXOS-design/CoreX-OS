<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultTypes;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\User;
use App\Services\Rentals\RentalFaultTypeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md §2.2/§3.2/§4.3a/§4.4 — Slice 2:
 * a fault report can reference a catalogue entry, first-aid steps pull
 * property-specific data, and "resolved by first aid" is still logged.
 */
final class RentalFaultTypeFirstAidTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private Property $property;
    private RentalFaultType $faultType;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'RFT FirstAid Agency', 'slug' => 'rft-fa-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Test Street', 'status' => 'active', 'listing_type' => 'rental',
            'rental_main_water_valve_location' => 'Outside, left of the front door',
        ]);
        $this->faultType = RentalFaultType::create([
            'agency_id' => $this->agency->id, 'name' => 'Burst pipe / water leak', 'category' => 'Plumbing',
            'urgency' => 'emergency', 'sort_order' => 1, 'created_by_user_id' => $this->admin->id,
            'first_aid_steps' => "Close the main water valve at: {{main_water_valve_location}} — close it now.\nUnknown: {{db_board_location}}.",
        ]);
    }

    // ── §3.2 — property data pulled into the rendered steps ─────────────

    public function test_renders_the_recorded_valve_location_into_the_placeholder(): void
    {
        $rendered = app(RentalFaultTypeService::class)->renderFirstAidSteps($this->faultType, $this->property);

        $this->assertStringContainsString('Outside, left of the front door', $rendered);
        $this->assertStringNotContainsString('{{main_water_valve_location}}', $rendered);
    }

    public function test_falls_back_honestly_when_the_property_has_no_location_recorded(): void
    {
        $rendered = app(RentalFaultTypeService::class)->renderFirstAidSteps($this->faultType, $this->property);

        $this->assertStringContainsString('not recorded', $rendered);
        $this->assertStringNotContainsString('{{db_board_location}}', $rendered);
    }

    public function test_first_aid_endpoint_returns_rendered_steps_for_the_given_property(): void
    {
        $response = $this->actingAs($this->admin)->getJson(
            route('corex.rental-fault-types.first-aid', $this->faultType) . '?property_id=' . $this->property->id
        );

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Burst pipe / water leak']);
        $this->assertStringContainsString('Outside, left of the front door', $response->json('first_aid_steps'));
    }

    // ── Johan's QA1 review, 2026-09-29 — the seeded defaults must actually
    //    USE the placeholders, and a missing location must read as a clean
    //    standalone sentence, never a raw {{token}} or a broken "is at: ." ──

    public function test_seeded_burst_pipe_default_uses_the_valve_location_token(): void
    {
        RentalFaultType::seedDefaultsFor($this->agency->id);
        $burstPipe = RentalFaultType::where('agency_id', $this->agency->id)->where('name', 'Burst pipe / water leak')->firstOrFail();

        $rendered = app(RentalFaultTypeService::class)->renderFirstAidSteps($burstPipe, $this->property);

        $this->assertStringContainsString('Your main water valve is at: Outside, left of the front door', $rendered);
    }

    public function test_seeded_power_tripping_default_uses_the_db_board_token_and_johans_procedure(): void
    {
        RentalFaultType::seedDefaultsFor($this->agency->id);
        $powerTripping = RentalFaultType::where('agency_id', $this->agency->id)->where('name', 'Power tripping / no power')->firstOrFail();
        $this->property->update(['rental_db_board_location' => 'Garage, back wall']);

        $rendered = app(RentalFaultTypeService::class)->renderFirstAidSteps($powerTripping, $this->property->fresh());

        $this->assertStringContainsString('Your DB board is at: Garage, back wall', $rendered);
        $this->assertStringContainsString('Switch all circuit breakers off, then on one at a time', $rendered);
        $this->assertStringContainsString('Unplug every appliance on that section', $rendered);
        $this->assertStringContainsString('If it still trips, leave it off and log the fault', $rendered);
    }

    public function test_missing_location_renders_a_clean_standalone_fallback_sentence_not_a_broken_clause(): void
    {
        RentalFaultType::seedDefaultsFor($this->agency->id);
        $burstPipe = RentalFaultType::where('agency_id', $this->agency->id)->where('name', 'Burst pipe / water leak')->firstOrFail();
        $propertyWithNoValve = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => 'No valve recorded', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $rendered = app(RentalFaultTypeService::class)->renderFirstAidSteps($burstPipe, $propertyWithNoValve);

        $this->assertStringNotContainsString('{{main_water_valve_location}}', $rendered);
        $this->assertStringNotContainsString('is at: .', $rendered);
        $this->assertStringNotContainsString('is at:.', $rendered);
        $this->assertStringContainsString("hasn't recorded where the main water valve is", $rendered);
    }

    public function test_backfill_migration_updates_only_unedited_default_rows(): void
    {
        // Seed as an OLDER agency would have been (pre-backfill wording),
        // simulating what QA1's already-seeded rows looked like before this
        // migration ran, then confirm the migration's own scoped UPDATE
        // (re-run here directly, matching its exact WHERE clause) only
        // touches the row whose text is EXACTLY the old default — never an
        // agency's own edit.
        $safetyLine = RentalFaultType::SAFETY_LINE;
        $oldText = $safetyLine . "\n\n" . 'Close the main water valve. Turn off any electrical '
            . 'appliances near the water — do not touch them if already wet.';
        $editedText = $safetyLine . "\n\n" . 'Agency-customised wording that must never be touched.';

        $unedited = RentalFaultType::create([
            'agency_id' => $this->agency->id, 'name' => 'Burst pipe / water leak', 'is_default' => true,
            'sort_order' => 1, 'created_by_user_id' => $this->admin->id, 'first_aid_steps' => $oldText,
        ]);
        $otherAgency = Agency::create(['name' => 'RFT Backfill Other', 'slug' => 'rft-fa-bf-' . uniqid()]);
        $edited = RentalFaultType::create([
            'agency_id' => $otherAgency->id, 'name' => 'Burst pipe / water leak', 'is_default' => true,
            'sort_order' => 1, 'created_by_user_id' => $this->admin->id, 'first_aid_steps' => $editedText,
        ]);

        $newText = $safetyLine . "\n\n" . 'Your main water valve is at: {{main_water_valve_location}}. '
            . 'Close it now. Turn off any electrical appliances near the water — do not touch them if '
            . 'already wet.';
        \Illuminate\Support\Facades\DB::table('rental_fault_types')
            ->where('name', 'Burst pipe / water leak')->where('is_default', true)->where('first_aid_steps', $oldText)
            ->update(['first_aid_steps' => $newText]);

        $this->assertSame($newText, $unedited->fresh()->first_aid_steps);
        $this->assertSame($editedText, $edited->fresh()->first_aid_steps);
    }

    // ── Johan's QA1 review, 2026-09-29 — documents/images/video + the
    //    property's own photo must reach the first-aid panel, not just text ──

    public function test_first_aid_endpoint_includes_property_photos_and_catalogue_documents(): void
    {
        $this->property->update([
            'rental_main_water_valve_photo_path' => '/storage/properties/1/valve.jpg',
            'rental_db_board_photo_path' => '/storage/properties/1/board.jpg',
        ]);
        $this->faultType->documents()->create([
            'agency_id' => $this->agency->id, 'document_type' => 'video_link',
            'external_url' => 'https://youtube.com/watch?v=example', 'caption' => 'How to find the valve',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->admin)->getJson(
            route('corex.rental-fault-types.first-aid', $this->faultType) . '?property_id=' . $this->property->id
        );

        $response->assertOk();
        $response->assertJsonPath('main_water_valve_photo_url', '/storage/properties/1/valve.jpg');
        $response->assertJsonPath('db_board_photo_url', '/storage/properties/1/board.jpg');
        $response->assertJsonFragment(['type' => 'video_link', 'caption' => 'How to find the valve']);
    }

    public function test_first_aid_endpoint_refuses_a_fault_type_from_another_agency(): void
    {
        $otherAgency = Agency::create(['name' => 'RFT Other', 'slug' => 'rft-fa-other-' . uniqid()]);
        $otherProperty = Property::forceCreate([
            'agency_id' => $otherAgency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->admin->id,
            'title' => 'Other property', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $response = $this->actingAs($this->admin)->getJson(
            route('corex.rental-fault-types.first-aid', $this->faultType) . '?property_id=' . $otherProperty->id
        );

        $response->assertNotFound();
    }

    // ── §2.2 — a fault report can reference the catalogue entry ─────────

    public function test_agent_can_log_a_fault_report_against_a_catalogue_fault_type(): void
    {
        $response = $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.store'), [
            'property_id' => $this->property->id,
            'rental_fault_type_id' => $this->faultType->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON,
            'title' => 'Burst pipe',
            'description' => 'Water everywhere.',
        ]);

        $response->assertRedirect();
        $faultReport = RentalFaultReport::where('title', 'Burst pipe')->firstOrFail();
        $this->assertSame($this->faultType->id, $faultReport->rental_fault_type_id);
    }

    public function test_a_fault_report_can_still_be_free_text_only_with_no_catalogue_reference(): void
    {
        $response = $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.store'), [
            'property_id' => $this->property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON,
            'title' => 'Something odd',
            'description' => "Doesn't fit any category.",
        ]);

        $response->assertRedirect();
        $faultReport = RentalFaultReport::where('title', 'Something odd')->firstOrFail();
        $this->assertNull($faultReport->rental_fault_type_id);
    }

    // ── §4.4 — "resolved by first aid," still logged ─────────────────────

    public function test_resolved_by_first_aid_outcome_requires_no_note_and_defaults_repaired_at_to_now(): void
    {
        $faultReport = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'rental_fault_type_id' => $this->faultType->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_by_contact_id' => null,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'captured_by_user_id' => $this->admin->id,
            'title' => 'Burst pipe', 'description' => 'Closed the valve myself.', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.outcome.store', $faultReport), [
            'outcome' => RentalFaultReport::OUTCOME_RESOLVED_BY_FIRST_AID,
        ]);

        $response->assertRedirect();
        $faultReport->refresh();
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $faultReport->status);
        $this->assertSame(RentalFaultReport::OUTCOME_RESOLVED_BY_FIRST_AID, $faultReport->outcome);
        $this->assertNotNull($faultReport->repaired_at);
    }

    public function test_resolved_by_first_aid_is_logged_on_the_evidence_history(): void
    {
        $faultReport = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'captured_by_user_id' => $this->admin->id,
            'title' => 'Test', 'description' => 'Test.', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);

        $faultReport->setOutcome(['outcome' => RentalFaultReport::OUTCOME_RESOLVED_BY_FIRST_AID], $this->admin);

        $this->assertDatabaseHas('rental_fault_report_updates', [
            'rental_fault_report_id' => $faultReport->id,
        ]);
        $history = $faultReport->history();
        $this->assertTrue($history->contains(fn ($e) => str_contains($e['action'], 'Outcome set')));
    }
}
