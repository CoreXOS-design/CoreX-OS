<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\LeaseSetting;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInventorySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner's ruling 2026-09-30: credit_bureau_name, tenanted_label,
 * show_lease_type_field, require_notes_blocks_progression, omr_mark_threshold and
 * the inspection / inventory repeater lists (condition states, refusal presets,
 * photo-note classifications) are IN the Setup Wizard's Rentals ('leases') step.
 * (agency-onboarding-setup.md §5.1, CLAUDE.md #10a, §6.1 subset-post rule.)
 */
final class RentalsStepRuledInSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function agency(): Agency
    {
        return Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-rentals-' . uniqid()]);
    }

    /** What the step posts before any of the newly ruled-in controls are involved. */
    private function basePayload(): array
    {
        return [
            'expiry_notice_window_days' => 60,
            'fault_report_window_days' => 7,
            'out_inspection_signing_window_days' => 7,
            'no_approval_spend_threshold' => 500,
            'shown_field_keys' => collect(RentalApplication::submissionFieldRegistry())->pluck('key')->all(),
            'required_field_keys' => [],
            'field_display_submitted' => '1',
            'required_fields_submitted' => '1',
            'return_gate_method' => 'id_number',
            'return_gate_attempt_max' => 6,
            'return_gate_attempt_window_minutes' => 15,
            'lock_property_after_submission' => '1',
            'tag_contact_as_tenant_on_approval' => '1',
            'require_fica_before_authorisation' => '0',
            'document_uploads_open_after_approval' => '1',
            // approval_mode is a required radio; require_checklist_complete is has()-guarded —
            // the step always posts both.
            'approval_mode' => 'two_step',
            // The step's other has()-guarded toggles / fields — a real page load always renders them.
            'auto_pair_photos_enabled' => '1',
            'auto_send_report_enabled' => '1',
            'inventory_auto_send_report_enabled' => '1',
            'public_link_expiry_days' => 90,
            'require_checklist_complete' => '0',
        ];
    }

    public function test_the_step_renders_every_ruled_in_control_and_list(): void
    {
        $agency = $this->agency();

        $this->actingAs($this->admin($agency))
            ->get(route('corex.agency-setup.step', ['step' => 'leases']))
            ->assertOk()
            ->assertSee('Credit bureau you use for tenant checks')
            ->assertSee('Wording for an approved application with an active lease')
            ->assertSee('Show the lease type field on a lease')
            ->assertSee('Stop an inspection moving on while a required note is missing')
            ->assertSee('Scanned inspection form: tick-box sensitivity')
            ->assertSee('Inspection condition ratings')
            ->assertSee('Photo note types')
            ->assertSee('Inventory condition ratings')
            ->assertSee('condition_states_submitted', false)
            ->assertSee('photo_note_classifications_submitted', false)
            ->assertSee('inventory_condition_states_submitted', false);
    }

    public function test_the_scalar_controls_save_and_reopen_showing_the_saved_values(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);

        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'leases']));
        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), $this->basePayload() + [
                'credit_bureau_name' => 'XDS',
                'tenanted_label' => 'Occupied',
                'show_lease_type_field' => '1',
                'require_notes_blocks_progression' => '0',
                'omr_mark_threshold' => '0.5',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('XDS', RentalApplicationQualifyingSetting::creditBureauNameFor($agency->id));
        $this->assertSame('Occupied', RentalApplicationQualifyingSetting::tenantedLabelFor($agency->id));
        $this->assertTrue(LeaseSetting::showLeaseTypeFieldFor($agency->id));
        $this->assertFalse(RentalInspectionSetting::requireNotesBlocksProgressionFor($agency->id));
        $this->assertEqualsWithDelta(0.5, RentalInspectionSetting::omrMarkThresholdFor($agency->id), 0.0001);

        // currentValues() must resolve each per-key, never fall through to the hardcoded default.
        $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => 'leases']))
            ->assertOk()
            ->assertSee('value="XDS"', false)
            ->assertSee('value="Occupied"', false)
            ->assertSee('value="0.5"', false);
    }

    public function test_a_post_that_never_rendered_the_new_controls_wipes_nothing(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);

        RentalApplicationQualifyingSetting::updateOrCreate(['agency_id' => $agency->id], [
            'credit_bureau_name' => 'XDS',
            'tenanted_label' => 'Occupied',
        ]);
        LeaseSetting::updateOrCreate(['agency_id' => $agency->id], [
            'expiry_notice_window_days' => 60,
            'show_lease_type_field' => true,
        ]);
        $classifications = [['key' => 'own_1', 'label' => 'Our own type']];
        $conditions = [['key' => 'own_ok', 'label' => 'Fine', 'requires_notes' => false, 'severity' => 'blue']];
        RentalInspectionSetting::updateOrCreate(['agency_id' => $agency->id], [
            'require_notes_blocks_progression' => false,
            'omr_mark_threshold' => 0.5,
            'photo_note_classifications' => $classifications,
            'condition_states' => $conditions,
        ]);
        $inventoryStates = [['key' => 'own_inv', 'label' => 'Tidy', 'requires_notes' => false]];
        RentalInventorySetting::updateOrCreate(['agency_id' => $agency->id], ['condition_states' => $inventoryStates]);

        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'leases']));
        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), $this->basePayload())
            ->assertSessionHasNoErrors();

        $this->assertSame('XDS', RentalApplicationQualifyingSetting::creditBureauNameFor($agency->id));
        $this->assertSame('Occupied', RentalApplicationQualifyingSetting::tenantedLabelFor($agency->id));
        $this->assertTrue(LeaseSetting::showLeaseTypeFieldFor($agency->id));
        $this->assertFalse(RentalInspectionSetting::requireNotesBlocksProgressionFor($agency->id));
        $this->assertEqualsWithDelta(0.5, RentalInspectionSetting::omrMarkThresholdFor($agency->id), 0.0001);
        $this->assertSame('own_1', RentalInspectionSetting::photoNoteClassificationsFor($agency->id)[0]['key']);
        $this->assertSame('own_ok', RentalInspectionSetting::conditionStatesFor($agency->id)[0]['key']);
        $this->assertSame('own_inv', RentalInventorySetting::conditionStatesFor($agency->id)[0]['key']);
    }

    public function test_the_lists_save_through_their_canonical_savers_when_their_markers_are_posted(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);

        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'leases']));
        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), $this->basePayload() + [
                'refusal_reason_presets' => [['key' => 'cust_1', 'label' => 'Tenant not reachable']],
                'condition_states_submitted' => '1',
                'condition_states' => [
                    ['key' => 'good', 'label' => 'Good', 'requires_notes' => '0', 'severity' => 'green'],
                    ['key' => 'bad', 'label' => 'Bad', 'requires_notes' => '1', 'severity' => 'red'],
                ],
                'baseline_condition_key' => 'good',
                'photo_note_classifications_submitted' => '1',
                'photo_note_classifications' => [['key' => 'defect', 'label' => 'Defect']],
                'inventory_condition_states_submitted' => '1',
                'inventory_condition_states' => [['key' => 'clean', 'label' => 'Clean', 'requires_notes' => '0']],
            ])
            ->assertSessionHasNoErrors();

        $labels = collect(RentalInspectionSetting::refusalReasonPresetsFor($agency->id))->pluck('label')->all();
        $this->assertContains('Tenant not reachable', $labels);
        $this->assertSame(['good', 'bad'], collect(RentalInspectionSetting::conditionStatesFor($agency->id))->pluck('key')->all());
        $this->assertSame('good', RentalInspectionSetting::baselineConditionKeyFor($agency->id));
        $this->assertSame(['defect'], collect(RentalInspectionSetting::photoNoteClassificationsFor($agency->id))->pluck('key')->all());
        $this->assertSame(['clean'], collect(RentalInventorySetting::conditionStatesFor($agency->id))->pluck('key')->all());
    }
}
