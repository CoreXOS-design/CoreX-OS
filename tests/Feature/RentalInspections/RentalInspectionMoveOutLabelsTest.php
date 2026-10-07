<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Http\Controllers\CoreX\RentalListsWizardSaver;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionItemFinding;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use App\Services\Rentals\RentalInspectionReportPdfService;
use App\Services\RentalInspectionComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspections.md §45.14 — the three move-out classification labels (pre-existing / landlord's
 * responsibility / charge to tenant) are an agency setting. Keys fixed; defaults = the wording used so far; Settings
 * screen + Setup Wizard; logic never depends on the wording; a second agency is unaffected; nothing already printed
 * carries these words (so nothing already produced can change).
 */
final class RentalInspectionMoveOutLabelsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private Property $property;
    private Lease $lease;
    private RentalInspection $in;
    private RentalInspection $out;
    private RentalInspectionItem $floor;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Label Agency', 'slug' => 'label-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'HQ']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->admin->id,
            'title' => '9 Ocean View, Shelly Beach', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 10000, 'start_date' => now()->subMonths(10),
            'created_by_user_id' => $this->admin->id,
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thabo', 'last_name' => 'Mokoena',
            'email' => 'tenant-' . uniqid() . '@example.co.za',
        ])->id]);

        DB::table('role_permissions')->insert([
            ['role' => 'admin', 'permission_key' => 'rental_inspections.view', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'admin', 'permission_key' => 'rental_inspections.review_deposit_comparison', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'admin', 'permission_key' => 'rental_inspections.manage_settings', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $room = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => 'Bedroom', 'label' => 'Lounge',
            'source' => 'manual', 'sort_order' => 0, 'created_by_user_id' => $this->admin->id,
        ]);
        $this->floor = RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $room->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Floor', 'sort_order' => 0, 'created_by_user_id' => $this->admin->id,
        ]);
        $this->in = $this->inspection('in');
        $this->out = $this->inspection('out');
        $this->observe($this->in, 'good');
        $this->observe($this->out, 'damaged', 'Burn mark near the TV');
    }

    private function inspection(string $type): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id,
            'type' => $type, 'status' => RentalInspection::STATUS_COMPLETED, 'created_by_user_id' => $this->admin->id,
        ]);
    }

    private function observe(RentalInspection $insp, string $condition, ?string $notes = null): void
    {
        RentalInspectionObservation::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $insp->id, 'rental_inspection_item_id' => $this->floor->id,
            'observed_by_user_id' => $this->admin->id, 'condition' => $condition, 'notes' => $notes,
            'source' => $insp->type === 'in' ? RentalInspectionObservation::SOURCE_IN_INSPECTION : RentalInspectionObservation::SOURCE_OUT_INSPECTION,
        ]);
    }

    private function save(array $labels, bool $marker = true, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(route('corex.settings.rental-inspections.move-out-classification-labels'),
            ($marker ? ['move_out_classification_labels_submitted' => '1'] : []) + ['move_out_classification_labels' => $labels]);
    }

    private function page(?RentalInspection $out = null): string
    {
        return $this->actingAs($this->admin)->get(route('corex.rental-inspections.deposit-comparison', $out ?? $this->out))->assertOk()->getContent();
    }

    // ═══ Defaults and keys ═════════════════════════════════════════════════════

    public function test_the_defaults_are_the_wording_used_so_far_and_an_unconfigured_agency_gets_them(): void
    {
        $expected = [
            'pre_existing' => 'Pre-existing',
            'landlord_cost' => "Landlord's responsibility",
            'charge_tenant' => 'Charge to tenant',
        ];
        $this->assertSame($expected, RentalInspectionSetting::DEFAULT_MOVE_OUT_CLASSIFICATION_LABELS);
        $this->assertSame($expected, RentalInspectionSetting::moveOutClassificationLabelsFor($this->agency->id));
        $this->assertSame($expected, RentalInspectionSetting::moveOutClassificationLabelsFor(null));
        $this->assertSame($expected, RentalInspectionSetting::moveOutClassificationLabelsFor(999999));

        // The page itself is unchanged for an agency that never touched the setting.
        $html = $this->page();
        foreach (array_values($expected) as $label) {
            $this->assertStringContainsString(e($label), $html);
        }
    }

    public function test_every_disposition_key_is_still_offered_with_the_two_fixed_labels_untouched(): void
    {
        $this->save(['pre_existing' => 'Was there at move-in'])->assertSessionHasNoErrors();

        $all = RentalInspectionSetting::dispositionLabelsFor($this->agency->id);

        $this->assertSame(array_keys(RentalInspectionItemFinding::DISPOSITION_LABELS), array_keys($all), 'same keys, same order — validation and filters unchanged');
        $this->assertSame('Fair wear and tear', $all['wear_and_tear']);
        $this->assertSame('Flagged as a genuine difference', $all['flagged']);
        $this->assertSame('Was there at move-in', $all['pre_existing']);
        $this->assertSame("Landlord's responsibility", $all['landlord_cost'], 'a label not reworded keeps its default');
    }

    // ═══ Settings screen ═══════════════════════════════════════════════════════

    public function test_the_settings_screen_saves_trims_caps_blank_keeps_default_and_ignores_unknown_keys(): void
    {
        $this->save([
            'pre_existing' => '  Already there  ',
            'landlord_cost' => '',
            'charge_tenant' => str_repeat('z', 80),
            'invented_key' => 'Never stored',
        ])->assertSessionHasNoErrors();

        $labels = RentalInspectionSetting::moveOutClassificationLabelsFor($this->agency->id);
        $this->assertSame('Already there', $labels['pre_existing']);
        $this->assertSame("Landlord's responsibility", $labels['landlord_cost']);
        $this->assertSame(60, mb_strlen($labels['charge_tenant']));
        $this->assertArrayNotHasKey('invented_key', $labels);
        $this->assertCount(3, $labels);

        // Clearing every box returns to the defaults (stored as nothing, not as empty strings).
        $this->save(['pre_existing' => '', 'landlord_cost' => '', 'charge_tenant' => ''])->assertSessionHasNoErrors();
        $this->assertSame(RentalInspectionSetting::DEFAULT_MOVE_OUT_CLASSIFICATION_LABELS, RentalInspectionSetting::moveOutClassificationLabelsFor($this->agency->id));
        $this->assertNull(RentalInspectionSetting::withoutGlobalScopes()->where('agency_id', $this->agency->id)->value('move_out_classification_labels'));
    }

    public function test_the_settings_screen_shows_the_control_with_the_saved_wording(): void
    {
        $this->save(['charge_tenant' => 'Deduct from deposit']);

        $this->actingAs($this->admin)->get(route('corex.settings.rental-inspections.edit'))->assertOk()
            ->assertSee('Move-out classifications')
            ->assertSee('Deduct from deposit')
            ->assertSee('move_out_classification_labels_submitted', false);
    }

    public function test_without_the_marker_the_canonical_saver_refuses_rather_than_wiping(): void
    {
        $this->save(['pre_existing' => 'Kept'])->assertSessionHasNoErrors();
        $this->save(['pre_existing' => 'Hacked'], marker: false)->assertSessionHasErrors('move_out_classification_labels');
        $this->assertSame('Kept', RentalInspectionSetting::moveOutClassificationLabelsFor($this->agency->id)['pre_existing']);
    }

    public function test_a_user_without_the_manage_settings_permission_cannot_save_the_wording(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        $this->save(['pre_existing' => 'Sneaky'], true, $agent)->assertForbidden();
        $this->assertSame('Pre-existing', RentalInspectionSetting::moveOutClassificationLabelsFor($this->agency->id)['pre_existing']);
    }

    // ═══ Setup Wizard ══════════════════════════════════════════════════════════

    public function test_the_wizard_saver_is_a_no_op_unless_the_marker_is_posted_and_saves_when_it_is(): void
    {
        $this->save(['pre_existing' => 'Kept'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin);

        // A wizard step that never rendered this control posts without the marker: nothing is touched.
        app(RentalListsWizardSaver::class)->inspectionMoveOutClassificationLabels(request()->duplicate([], ['move_out_classification_labels' => ['pre_existing' => 'Hacked']]));
        $this->assertSame('Kept', RentalInspectionSetting::moveOutClassificationLabelsFor($this->agency->id)['pre_existing']);

        $request = request()->duplicate([], [
            'move_out_classification_labels_submitted' => '1',
            'move_out_classification_labels' => ['pre_existing' => 'From the wizard', 'landlord_cost' => 'Owner pays', 'charge_tenant' => 'Tenant pays'],
        ]);
        $request->setUserResolver(fn () => $this->admin);
        app(RentalListsWizardSaver::class)->inspectionMoveOutClassificationLabels($request);

        $this->assertSame(
            ['pre_existing' => 'From the wizard', 'landlord_cost' => 'Owner pays', 'charge_tenant' => 'Tenant pays'],
            RentalInspectionSetting::moveOutClassificationLabelsFor($this->agency->id),
        );
    }

    public function test_the_wizard_step_renders_the_control_with_explain_and_what_this_changes(): void
    {
        $html = view('agency-setup.steps.rentals-inspection-lists', [
            'wzRefusalPresets' => RentalInspectionSetting::refusalReasonPresetsFor($this->agency->id),
            'wzConditionStates' => RentalInspectionSetting::conditionStatesFor($this->agency->id),
            'wzBaselineConditionKey' => RentalInspectionSetting::baselineConditionKeyFor($this->agency->id),
            'wzPhotoClassifications' => RentalInspectionSetting::photoNoteClassificationsFor($this->agency->id),
            'wzInventoryConditionStates' => \App\Models\RentalInventorySetting::conditionStatesFor($this->agency->id),
            'wzCustomRoomTypes' => [],
            'wzMoveOutClassificationLabels' => ['pre_existing' => 'Saved wording', 'landlord_cost' => "Landlord's responsibility", 'charge_tenant' => 'Charge to tenant'],
        ])->render();

        $this->assertStringContainsString('move_out_classification_labels_submitted', $html);
        $this->assertStringContainsString('Move-out classifications', $html);
        $this->assertStringContainsString('Saved wording', $html);
        $this->assertStringContainsString('What this changes:', $html);
    }

    public function test_the_wizard_config_registers_the_saver_and_the_wizard_reads_the_current_values(): void
    {
        $savers = collect(config('agency-onboarding-copy.leases.savers', []));
        $this->assertTrue($savers->contains(fn ($s) => ($s['controller'] ?? null) === RentalListsWizardSaver::class && ($s['method'] ?? null) === 'inspectionMoveOutClassificationLabels'));

        $this->assertStringContainsString("'wzMoveOutClassificationLabels'", file_get_contents(app_path('Http/Controllers/CoreX/AgencySetupWizardController.php')));
    }

    // ═══ The wording is only wording ═══════════════════════════════════════════

    public function test_the_comparison_screen_shows_the_agencys_wording_and_the_stored_key_never_changes(): void
    {
        $this->save(['pre_existing' => 'Was there at move-in', 'landlord_cost' => 'Owner carries it', 'charge_tenant' => 'Deduct from deposit']);

        $html = $this->page();
        foreach (['Was there at move-in', 'Owner carries it', 'Deduct from deposit'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        // Recording goes by KEY; a label is not an accepted value.
        $url = route('corex.rental-inspections.deposit-comparison.finding', [$this->out, $this->floor]);
        $this->actingAs($this->admin)->post($url, ['disposition' => 'Deduct from deposit', 'note' => 'x'])->assertSessionHasErrors('disposition');
        $this->actingAs($this->admin)->post($url, ['disposition' => 'charge_tenant', 'note' => 'Cigarette burn'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('rental_inspection_item_findings', ['rental_inspection_item_id' => $this->floor->id, 'disposition' => 'charge_tenant', 'superseded_at' => null]);
        $this->assertStringContainsString('Classified <strong>Deduct from deposit</strong>', $this->page());

        // Reword again later: the same stored row now reads in the new words, still key charge_tenant.
        $this->save(['charge_tenant' => 'Recover from tenant']);
        $this->assertStringContainsString('Classified <strong>Recover from tenant</strong>', $this->page());
        $this->assertSame('charge_tenant', RentalInspectionItemFinding::firstOrFail()->disposition);
    }

    public function test_swapping_two_labels_cannot_change_what_the_code_does(): void
    {
        // The nastiest wording an agency could pick: each label is another key's DEFAULT label.
        $this->save(['pre_existing' => 'Charge to tenant', 'charge_tenant' => 'Pre-existing']);

        app(RentalInspectionComparisonService::class)->recordFinding($this->out, $this->floor, 'charge_tenant', 'Burn', $this->admin);
        $service = app(RentalInspectionComparisonService::class);
        $rows = fn (array $filters) => collect($service->moveOutComparison($this->out, $filters)['rooms'])->flatMap(fn ($r) => $r['rows'])->count();

        $this->assertSame(1, $rows(['classification' => 'charge_tenant']), 'filtering is by key');
        $this->assertSame(0, $rows(['classification' => 'pre_existing']), 'the other key is not matched by the swapped wording');
        $this->assertSame('charge_tenant', RentalInspectionItemFinding::firstOrFail()->disposition);
    }

    public function test_another_agency_is_unaffected_by_this_agencys_wording(): void
    {
        $this->save(['pre_existing' => 'Custom wording', 'charge_tenant' => 'Custom charge']);

        \Illuminate\Support\Facades\Auth::logout();
        $other = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);

        $this->assertSame(RentalInspectionSetting::DEFAULT_MOVE_OUT_CLASSIFICATION_LABELS, RentalInspectionSetting::moveOutClassificationLabelsFor($other->id));
        $this->assertSame('Custom wording', RentalInspectionSetting::moveOutClassificationLabelsFor($this->agency->id)['pre_existing']);

        // And the other agency's own save lands on its own row only.
        // A non-owner role (the global "admin" is a platform owner who deliberately acts across agencies), granted the key.
        \App\Models\Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $other->id]);
        \App\Models\RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'rental_inspections.manage_settings', 'agency_id' => $other->id], ['scope' => 'all']);
        \App\Services\PermissionService::clearCache();
        $otherAdmin = User::factory()->create(['agency_id' => $other->id, 'role' => 'agent']);
        $this->assertSame($other->id, $otherAdmin->fresh()->agency_id);
        $this->save(['pre_existing' => 'Cape wording'], true, $otherAdmin)->assertSessionHasNoErrors();
        $this->assertSame('Cape wording', RentalInspectionSetting::moveOutClassificationLabelsFor($other->id)['pre_existing']);
        $this->assertSame('Custom wording', RentalInspectionSetting::moveOutClassificationLabelsFor($this->agency->id)['pre_existing']);
    }

    public function test_nothing_already_produced_carries_the_classification_wording_so_nothing_can_change_retroactively(): void
    {
        $this->save(['pre_existing' => 'Zzz-pre', 'landlord_cost' => 'Zzz-landlord', 'charge_tenant' => 'Zzz-charge']);
        app(RentalInspectionComparisonService::class)->recordFinding($this->out, $this->floor, 'charge_tenant', 'Burn', $this->admin);

        // The signed PDF and the public report link are the produced artefacts of an inspection.
        $pdf = app(RentalInspectionReportPdfService::class)->generate($this->out->fresh())->getDomPDF()->outputHtml();
        $this->out->generatePublicLink();
        $public = $this->get(route('rental-inspections.public.show', $this->out->fresh()->public_token))->assertOk()->getContent();

        foreach (['Zzz-pre', 'Zzz-landlord', 'Zzz-charge', 'Pre-existing', "Landlord's responsibility", 'Charge to tenant'] as $needle) {
            $this->assertStringNotContainsString($needle, $pdf, "the PDF must not print classification wording ({$needle})");
            $this->assertStringNotContainsString($needle, $public, "the public report must not print classification wording ({$needle})");
        }
    }
}
