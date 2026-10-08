<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\RentalInspection;
use App\Models\RentalInspectionSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalInspections\Concerns\BuildsSigningFixture;
use Tests\TestCase;

/**
 * 8 Oct 2026 (Johan's rulings 3 and 4, spec §49):
 *
 *  3. Whether an inspection needs the three signatures (every tenant, the landlord, the agent) to be completed is the AGENCY's
 *     own setting per type, in Settings and the Setup Wizard. Defaults: In, Out and Interim required; Routine optional.
 *     "Required" = cannot be completed without them; "optional" = can.
 *  4. Every place an inspection can be started or scheduled offers all four types — In, Routine, Interim, Out — with the
 *     one-line meaning beside Routine and Interim.
 */
final class RentalInspectionRulingsSettingsAndPickersTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
        parent::tearDown();
    }

    /** @return array<string, array{0:string, 1:bool}> type => default */
    public static function defaults(): array
    {
        return ['in' => ['in', true], 'out' => ['out', true], 'interim' => ['interim', true], 'routine' => ['ad_hoc', false]];
    }

    private function setRequired(string $type, bool $required): void
    {
        $column = RentalInspectionSetting::SIGNATURE_REQUIREMENT_COLUMNS[$type];
        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], [$column => $required]);
    }

    // ═══ Rule 3 — signatures per type are the agency's setting ═══════════════════

    /** @dataProvider defaults */
    public function test_the_defaults_are_in_out_interim_required_and_routine_optional(string $type, bool $expected): void
    {
        $this->assertSame($expected, RentalInspectionSetting::signaturesRequiredFor($this->agency->id, $type), 'agency with no settings row');
        $this->assertSame($expected, RentalInspectionSetting::signaturesRequiredFor(null, $type), 'no agency at all: the neutral default');
        $this->assertSame($expected, $this->recording($type)->signaturesRequired());
    }

    public function test_an_unknown_type_is_treated_as_required_never_silently_optional(): void
    {
        $this->assertTrue(RentalInspectionSetting::signaturesRequiredFor($this->agency->id, 'mystery'));
    }

    /** @dataProvider defaults */
    public function test_a_required_type_cannot_be_completed_without_the_signatures_and_an_optional_one_can(string $type, bool $required): void
    {
        $inspection = $this->recording($type);

        $response = $this->postJson(route('corex.rental-inspections.complete', $inspection));

        if ($required) {
            $response->assertStatus(409);
            $this->assertStringContainsString('Cannot complete', (string) $response->json('message'));
            $this->assertSame(RentalInspection::STATUS_DRAFT, $inspection->fresh()->status, 'Nothing changed.');
        } else {
            $response->assertOk();
            $this->assertSame(RentalInspection::STATUS_COMPLETED, $inspection->fresh()->status);
        }
    }

    /** @dataProvider defaults */
    public function test_a_required_type_completes_once_all_three_have_signed(string $type): void
    {
        $inspection = $this->ready($type);
        $this->everyoneSigns($inspection);

        $this->complete($inspection);

        $this->assertSame(RentalInspection::STATUS_COMPLETED, $inspection->fresh()->status);
    }

    public function test_the_agency_can_make_routine_required(): void
    {
        $this->setRequired('ad_hoc', true);
        $inspection = $this->recording(RentalInspection::TYPE_AD_HOC);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertStatus(409);
        $this->assertSame(RentalInspection::STATUS_DRAFT, $inspection->fresh()->status);

        $this->readyToSign($inspection);
        $this->everyoneSigns($inspection);
        $this->complete($inspection);
        $this->assertSame(RentalInspection::STATUS_COMPLETED, $inspection->fresh()->status);
    }

    /** @dataProvider defaults */
    public function test_the_agency_can_make_any_type_optional_and_it_then_completes_without_signatures(string $type): void
    {
        $this->setRequired($type, false);
        $inspection = $this->recording($type);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();
        $this->assertSame(RentalInspection::STATUS_COMPLETED, $inspection->fresh()->status);
    }

    public function test_optional_still_lets_signatures_be_collected_and_a_refusal_never_blocks_completion(): void
    {
        $inspection = $this->ready(RentalInspection::TYPE_AD_HOC);
        $this->signByLink($inspection, 'tenant', $this->tenant, 'Naledi Dlamini');

        $this->complete($inspection); // optional: completes with only one signature on file
        $this->assertSame(RentalInspection::STATUS_COMPLETED, $inspection->fresh()->status);
    }

    public function test_the_setting_is_per_agency_another_agency_is_unaffected(): void
    {
        $this->setRequired('ad_hoc', true);
        $this->setRequired('in', false);

        \Illuminate\Support\Facades\Auth::logout();
        $other = Agency::create(['name' => 'Durban Lettings', 'slug' => 'durban-' . uniqid()]);
        $this->actingAs($this->admin);

        $this->assertFalse(RentalInspectionSetting::signaturesRequiredFor($other->id, 'ad_hoc'));
        $this->assertTrue(RentalInspectionSetting::signaturesRequiredFor($other->id, 'in'));
        $this->assertTrue(RentalInspectionSetting::signaturesRequiredFor($this->agency->id, 'ad_hoc'));
        $this->assertFalse(RentalInspectionSetting::signaturesRequiredFor($this->agency->id, 'in'));
    }

    // ═══ Settings page, saver, wizard ═══════════════════════════════════════════

    public function test_the_four_settings_save_from_the_settings_page_and_a_post_that_omits_them_cannot_wipe_them(): void
    {
        $this->setRequired('ad_hoc', true);
        $this->setRequired('out', false);
        $base = ['fault_report_window_days' => 7, 'out_inspection_signing_window_days' => 7];

        // A step that renders none of the four posts without them: nothing changes.
        $this->post(route('corex.settings.rental-inspections.update'), $base)->assertRedirect();
        $this->assertTrue(RentalInspectionSetting::signaturesRequiredFor($this->agency->id, 'ad_hoc'));
        $this->assertFalse(RentalInspectionSetting::signaturesRequiredFor($this->agency->id, 'out'));

        // A step that renders only one posts only that one: the others stay.
        $this->post(route('corex.settings.rental-inspections.update'), $base + ['signatures_required_interim' => '0'])->assertRedirect();
        $this->assertFalse(RentalInspectionSetting::signaturesRequiredFor($this->agency->id, 'interim'));
        $this->assertTrue(RentalInspectionSetting::signaturesRequiredFor($this->agency->id, 'ad_hoc'));
        $this->assertFalse(RentalInspectionSetting::signaturesRequiredFor($this->agency->id, 'out'));

        // The full page posts all four (hidden 0, then 1 when ticked).
        $this->post(route('corex.settings.rental-inspections.update'), $base + [
            'signatures_required_in' => '1', 'signatures_required_out' => '1', 'signatures_required_interim' => '1', 'signatures_required_routine' => '0',
        ])->assertRedirect();
        foreach (['in' => true, 'out' => true, 'interim' => true, 'ad_hoc' => false] as $type => $expected) {
            $this->assertSame($expected, RentalInspectionSetting::signaturesRequiredFor($this->agency->id, $type), $type);
        }
    }

    public function test_the_settings_page_shows_all_four_with_their_current_state(): void
    {
        $this->setRequired('ad_hoc', true);
        $this->setRequired('in', false);

        $html = $this->get(route('corex.settings.rental-inspections.edit'))->assertOk()
            ->assertSee('data-qa="signatures-required"', false)
            ->assertSee('In-inspection (move-in) — signatures required')
            ->assertSee('Out-inspection (move-out) — signatures required')
            ->assertSee('Interim inspection (planned, mid-tenancy) — signatures required')
            ->assertSee('Routine inspection (unplanned, mid-tenancy) — signatures required')
            ->getContent();

        $this->assertMatchesRegularExpression('/name="signatures_required_routine" value="1"\s+checked/', $html, 'Routine was switched to required.');
        $this->assertDoesNotMatchRegularExpression('/name="signatures_required_in" value="1"\s+checked/', $html, 'In was switched to optional.');
        $this->assertMatchesRegularExpression('/name="signatures_required_out" value="1"\s+checked/', $html);
    }

    public function test_all_four_are_in_the_setup_wizard_with_an_explanation_a_consequence_and_the_right_defaults(): void
    {
        $controls = collect(config('agency-onboarding-copy'))
            ->flatMap(fn ($step) => is_array($step) ? ($step['controls'] ?? []) : [])
            ->keyBy('key');

        foreach (['signatures_required_in' => 1, 'signatures_required_out' => 1, 'signatures_required_interim' => 1, 'signatures_required_routine' => 0] as $key => $default) {
            $this->assertTrue($controls->has($key), "{$key} must be offered in the Setup Wizard (CLAUDE.md #10a).");
            $this->assertSame('toggle', $controls[$key]['type']);
            $this->assertSame('rental_inspections', $controls[$key]['source']);
            $this->assertSame($default, $controls[$key]['default'], $key);
            $this->assertNotSame('', trim((string) $controls[$key]['explain']));
            $this->assertNotSame('', trim((string) $controls[$key]['affects']));
        }

        // …saved by the same shared saver the Settings page uses.
        $savers = collect(config('agency-onboarding-copy'))->flatMap(fn ($step) => is_array($step) ? ($step['savers'] ?? []) : []);
        $this->assertTrue($savers->contains(fn ($s) => ($s['controller'] ?? null) === \App\Http\Controllers\CoreX\RentalInspectionSettingsController::class && ($s['method'] ?? null) === 'update'));
    }

    public function test_the_wizard_shows_the_agencys_saved_values_not_the_hardcoded_defaults(): void
    {
        $this->setRequired('in', false);
        $this->setRequired('ad_hoc', true);

        $config = require config_path('agency-onboarding-copy.php');
        $controller = app(\App\Http\Controllers\CoreX\AgencySetupWizardController::class);
        $method = new \ReflectionMethod($controller, 'currentValues');
        $method->setAccessible(true);
        $values = $method->invoke($controller, $config['leases'], $this->agency->fresh());

        $this->assertFalse($values['signatures_required_in']);
        $this->assertTrue($values['signatures_required_out']);
        $this->assertTrue($values['signatures_required_interim']);
        $this->assertTrue($values['signatures_required_routine']);
    }

    // ═══ Rule 4 — all four types, everywhere, with the meaning beside them ═══════

    private const MEANINGS = [
        'In — move-in condition',
        'Routine — an unplanned mid-tenancy check',
        'Interim — a planned mid-tenancy inspection, from a date you loaded',
        'Out — move-out condition',
    ];

    public function test_the_one_shared_list_has_all_four_types_in_order(): void
    {
        $this->assertSame(['in', 'ad_hoc', 'interim', 'out'], array_column(RentalInspection::typePickerOptions(), 'value'));
        $this->assertSame(self::MEANINGS, array_column(RentalInspection::typePickerOptions(), 'text'));
    }

    public function test_the_new_inspection_form_offers_all_four_with_the_meanings(): void
    {
        $page = $this->get(route('corex.rental-inspections.create'))->assertOk();
        foreach (self::MEANINGS as $text) {
            $page->assertSee($text);
        }
        foreach (['in', 'ad_hoc', 'interim', 'out'] as $value) {
            $page->assertSee('<option value="' . $value . '"', false);
        }
    }

    public function test_the_form_opened_from_the_lease_or_the_command_centre_preselects_and_still_offers_all_four(): void
    {
        $page = $this->get(route('corex.rental-inspections.create', ['property_id' => $this->property->id, 'lease_id' => $this->lease->id, 'type' => 'interim']))->assertOk();
        $page->assertSee('<option value="interim" selected', false);
        foreach (self::MEANINGS as $text) {
            $page->assertSee($text);
        }
    }

    /** @dataProvider defaults */
    public function test_every_type_can_be_started_or_scheduled_from_the_form(string $type): void
    {
        $this->post(route('corex.rental-inspections.store'), ['property_id' => $this->property->id, 'type' => $type])->assertRedirect();
        $this->assertSame(1, RentalInspection::where('type', $type)->count());
    }

    public function test_the_start_message_says_routine_never_ad_hoc(): void
    {
        $this->post(route('corex.rental-inspections.store'), ['property_id' => $this->property->id, 'type' => 'ad_hoc'])
            ->assertSessionHas('success', 'Routine inspection started.');
    }

    public function test_the_inspection_page_next_picker_offers_all_four_in_is_greyed_out_when_the_tenancy_has_one(): void
    {
        $in = $this->ready(RentalInspection::TYPE_IN);
        $this->everyoneSigns($in);
        $this->complete($in);

        $page = $this->get(route('corex.rental-inspections.show', $in))->assertOk();
        foreach (self::MEANINGS as $text) {
            $page->assertSee($text);
        }
        $page->assertSee('<option value="in" disabled>', false)->assertSee('already recorded for this tenancy (#' . $in->id . ')');
    }

    public function test_in_is_offered_and_enabled_on_the_next_picker_when_the_tenancy_has_no_in_yet(): void
    {
        $routine = $this->recording(RentalInspection::TYPE_AD_HOC);
        $this->complete($routine);

        $page = $this->get(route('corex.rental-inspections.show', $routine))->assertOk();
        $page->assertSee('<option value="in" >', false)->assertDontSee('already recorded for this tenancy');
        foreach (self::MEANINGS as $text) {
            $page->assertSee($text);
        }
    }

    public function test_starting_an_in_from_next_works_when_the_tenancy_has_none_and_is_refused_plainly_when_it_has_one(): void
    {
        $this->setRequired('in', false); // this test is about the picker rule, not the signatures
        $routine = $this->recording(RentalInspection::TYPE_AD_HOC);
        $this->complete($routine);

        // none yet: allowed, chained after the routine like any other link
        $this->post(route('corex.rental-inspections.next', $routine), ['type' => 'in'])->assertRedirect();
        $in = RentalInspection::where('type', 'in')->firstOrFail();
        $this->assertSame($routine->id, $in->previous_inspection_id);

        // the tenancy now has an In (even one still being recorded): a second one is refused with the reason, nothing is created
        $this->assertSame(1, RentalInspection::where('type', 'in')->count());
        $next = $this->post(route('corex.rental-inspections.next', $in->fresh()), ['type' => 'in']);
        $next->assertSessionHasErrors('rental_inspection');
        $this->assertStringContainsString('already has an In-inspection', session('errors')->first('rental_inspection'));
        $this->assertSame(1, RentalInspection::where('type', 'in')->count());
    }

    public function test_the_property_tab_endpoints_accept_all_four_types(): void
    {
        foreach (['interim', 'ad_hoc', 'out', 'in'] as $type) {
            \Illuminate\Support\Facades\DB::table('rental_inspections')->delete();
            $this->postJson(route('corex.properties.rental-inspections.start', $this->property), ['type' => $type])->assertStatus(201);
            $this->assertSame(1, RentalInspection::where('type', $type)->count(), $type);
        }
        $this->postJson(route('corex.properties.rental-inspections.start', $this->property), ['type' => 'bogus'])->assertStatus(422);
    }

    public function test_the_property_tab_next_endpoint_takes_in_and_refuses_it_once_there_is_one(): void
    {
        $this->setRequired('in', false); // this test is about the picker rule, not the signatures
        $routine = $this->recording(RentalInspection::TYPE_AD_HOC);
        $this->complete($routine);

        $this->postJson(route('corex.properties.rental-inspections.next', [$this->property, $routine]), ['type' => 'in'])->assertStatus(201);
        $in = RentalInspection::where('type', 'in')->firstOrFail();

        $this->postJson(route('corex.properties.rental-inspections.next', [$this->property, $in->fresh()]), ['type' => 'in'])
            ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'already has an In-inspection'));
        $this->postJson(route('corex.properties.rental-inspections.next', [$this->property, $in->fresh()]), ['type' => 'interim'])->assertStatus(201);
    }

    public function test_the_property_inspections_tab_offers_all_four_both_before_and_after_the_first_inspection(): void
    {
        // before: the first-inspection picker
        $page = $this->get(route('corex.properties.show', $this->property->id))->assertOk();
        $page->assertSee('data-qa="start-type"', false);
        foreach (self::MEANINGS as $text) {
            $page->assertSee($text);
        }

        // after: the Next-inspection picker, with the tail's flag for the greyed-out In
        $routine = $this->recording(RentalInspection::TYPE_AD_HOC);
        $payload = RentalInspection::tabPayloadFor($this->property->fresh());
        $this->assertNull($payload['chain_tail']->lease_in_inspection_id);

        $page = $this->get(route('corex.properties.show', $this->property->id))->assertOk();
        $page->assertSee('data-qa="next-type"', false);
        foreach (self::MEANINGS as $text) {
            $page->assertSee($text);
        }

        $this->complete($routine);
        $in = RentalInspection::startNext($routine->fresh(), 'in', $this->admin);
        $this->assertSame($in->id, RentalInspection::tabPayloadFor($this->property->fresh())['chain_tail']->lease_in_inspection_id);
    }

    public function test_the_list_filter_still_offers_all_four_and_names_them_the_same_way(): void
    {
        $this->get(route('corex.rental-inspections.index'))->assertOk()
            ->assertSee('In-inspection')->assertSee('Routine')->assertSee('Interim')->assertSee('Out-inspection');
    }
}
