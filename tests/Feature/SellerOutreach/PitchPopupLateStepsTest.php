<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\Contact;
use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RolePermission;
use App\Services\PermissionService;
use App\Services\RoadToSold\BeltService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ProspectStepService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * road-to-sold.md 23.25 steps 6-9 of the pitch popup: Presentation, Presentation results, Draft, Active. Each reuses a screen that already exists; the plan names the
 * address of each for the agent's OWN record (so both entry points get the same answer), a step with no screen yet is null (a plain label), and the new row buttons
 * exist only for the new stations.
 */
final class PitchPopupLateStepsTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private $tp;
    private int $propertyId;
    private Contact $owner;
    private int $listingId;
    private RtsProspect $prospect;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        foreach (['agent', 'admin'] as $role) {
            foreach (['access_road_to_sold', 'access_properties', 'outreach.compose', 'command_center.calendar.view', 'create_presentations'] as $perm) {
                RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $perm, 'agency_id' => $this->agency->id]);
            }
        }
        RolePermission::create(['role' => 'admin', 'permission_key' => 'properties.view', 'scope' => 'all', 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();

        $this->tp = $this->tp(['street_name' => 'Shepstone Avenue', 'street_number' => '25']);
        $this->owner = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Belinda', 'last_name' => 'Owner', 'phone' => '0825512345', 'email' => 'belinda@example.test']);
        $this->propertyId = $this->property($this->agent->id, ['address' => '25 Shepstone Avenue', 'suburb' => 'Uvongo', 'tracked_property_id' => $this->tp->id]);
        DB::table('contact_property')->insert(['contact_id' => $this->owner->id, 'property_id' => $this->propertyId, 'role' => 'seller', 'created_at' => now(), 'updated_at' => now()]);
        $this->tp->update(['owner_contact_id' => $this->owner->id, 'deeds_captured_at' => now(), 'promoted_to_property_id' => $this->propertyId]);
        $this->listingId = $this->listing([], $this->tp);
        DB::table('prospecting_listings')->where('id', $this->listingId)->update(['matched_property_id' => $this->propertyId]);
        ProspectingClaim::create(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $this->listingId, 'user_id' => $this->agent->id, 'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now()]);
        $this->prospect = RtsProspect::query()->where('tracked_property_id', $this->tp->id)->firstOrFail();
        $this->prospect->forceFill(['property_id' => $this->propertyId])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stage(string $stage): void
    {
        DB::table('rts_prospects')->where('id', $this->prospect->id)->update(['stage' => $stage]);
    }

    /** The plan as each entry point asks for it. */
    private function plan(string $entry, ?\App\Models\User $as = null): array
    {
        $q = $entry === 'rts' ? ['prospect' => $this->prospect->id] : ['listing' => $this->listingId];

        return $this->actingAs($as ?? $this->agent)->getJson(route('seller-outreach.entry.pitch-steps', $q))->assertOk()->json('steps');
    }

    /** @return array<string,array{0:string}> */
    public static function entries(): array
    {
        return ['a Road to Sold row' => ['rts'], 'MIC Pitch now' => ['mic']];
    }

    /** @dataProvider entries */
    public function test_step_6_presentation_opens_the_generator_on_the_property(string $entry): void
    {
        $this->stage('contacted');

        $url = $this->plan($entry)['presentation'];
        $this->assertStringContainsString('/prospects/' . $this->prospect->id . '/presentation-path', (string) $url);

        $r = $this->actingAs($this->agent)->get($url)->assertRedirect();
        $to = $r->headers->get('Location');
        $this->assertStringContainsString('/properties/' . $this->propertyId, $to);
        $this->assertStringContainsString('generate_presentation=1', $to);
        $this->assertStringContainsString('embed=1', $to);
    }

    /** @dataProvider entries */
    public function test_step_7_results_is_the_feedback_form_until_held_then_the_result_form(string $entry): void
    {
        $this->assertNull($this->plan($entry)['results'] ?? null, 'before an appointment is booked there is nothing to give results on');

        $this->stage('appointment_set');
        $event = CalendarEvent::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'event_type' => 'manual', 'title' => 'Evaluation',
            'category' => 'property_evaluation', 'event_date' => '2026-11-03 10:00:00', 'status' => 'pending', 'rts_prospect_id' => $this->prospect->id]);
        $url = $this->plan($entry)['results'];
        $this->assertStringContainsString('/results-path', (string) $url);
        $to = $this->actingAs($this->agent)->get($url)->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('/command-center/calendar', $to);
        $this->assertStringContainsString('capture_feedback=' . $event->id, $to);
        $this->assertStringContainsString('embed=1', $to);

        $this->stage('evaluation_done');
        $to = $this->actingAs($this->agent)->get($url)->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('/prospects/' . $this->prospect->id . '/result-form', $to, 'the same pill now opens the Result form');

        $h = $this->actingAs($this->agent)->get($to)->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="pitch-result"', $h);
        $this->assertStringContainsString('action="' . route('road-to-sold.prospects.result', $this->prospect->id) . '"', $h, 'it posts to the one Result route');
        $this->assertStringContainsString('name="result_key"', $h);
        $this->assertStringContainsString('25 Shepstone Avenue', $h);
    }

    /** @dataProvider entries */
    public function test_steps_8_and_9_are_the_propertys_gates_and_appear_only_when_the_record_is_there(string $entry): void
    {
        $this->stage('evaluation_done');
        $p = $this->plan($entry);
        $this->assertNull($p['draft'], 'not won yet: a plain label');
        $this->assertNull($p['active']);

        $this->stage('mandate_signed');
        $p = $this->plan($entry);
        $this->assertStringContainsString('/property-step/draft', (string) $p['draft']);
        $this->assertNull($p['active'], 'Active has no screen until the record is a Draft');

        $this->stage('draft');
        $p = $this->plan($entry);
        $this->assertStringContainsString('/property-step/active', (string) $p['active']);
        $this->assertTrue($this->plan($entry)['reached']['draft']);
        $this->assertFalse($this->plan($entry)['reached']['active']);

        $this->stage('active');
        $this->assertTrue($this->plan($entry)['reached']['active']);
    }

    public function test_the_draft_screen_shows_the_checklist_and_the_existing_move_to_draft(): void
    {
        $this->stage('mandate_signed');
        DB::table('properties')->where('id', $this->propertyId)->update(['status' => 'prospecting']);

        $h = $this->actingAs($this->agent)->get(route('road-to-sold.prospects.property-step', ['prospect' => $this->prospect->id, 'step' => 'draft', 'embed' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="step-heading">Draft<', $h);
        $this->assertStringContainsString('data-qa="draft-checklist"', $h);
        $this->assertStringContainsString('data-qa="move-to-draft"', $h);
        $this->assertStringContainsString('action="' . route('corex.properties.convert-from-prospecting', $this->propertyId) . '"', $h, 'the property page\'s own move');

        DB::table('properties')->where('id', $this->propertyId)->update(['status' => 'draft']);
        $h = $this->actingAs($this->agent)->get(route('road-to-sold.prospects.property-step', ['prospect' => $this->prospect->id, 'step' => 'draft', 'embed' => 1]))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-qa="move-to-draft"', $h);
        $this->assertStringContainsString('The property is a Draft.', $h);
    }

    public function test_the_active_screen_shows_the_two_gates_and_the_existing_actions(): void
    {
        $this->stage('draft');
        $url = route('road-to-sold.prospects.property-step', ['prospect' => $this->prospect->id, 'step' => 'active', 'embed' => 1]);

        $h = $this->actingAs($this->agent)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="gate-compliance" data-passed="0"', $h);
        $this->assertStringContainsString('data-qa="gate-published" data-passed="0"', $h);
        $this->assertStringContainsString(str_replace('/', '\\/', route('corex.properties.go-live', $this->propertyId)), $h, 'Make active records compliance through the existing route');

        DB::table('properties')->where('id', $this->propertyId)->update(['compliance_snapshot_at' => now()]);
        $h = $this->actingAs($this->agent)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="gate-compliance" data-passed="1"', $h);
        $this->assertStringContainsString('action="' . route('corex.properties.publish-toggle', $this->propertyId) . '"', $h, 'then the existing publish');

        DB::table('properties')->where('id', $this->propertyId)->update(['published_at' => now()]);
        $h = $this->actingAs($this->agent)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="active-done"', $h);
    }

    public function test_the_late_screens_are_only_ever_the_agents_own_record(): void
    {
        $this->stage('draft');
        $other = $this->makeAgent();
        foreach ([
            route('road-to-sold.prospects.property-step', ['prospect' => $this->prospect->id, 'step' => 'active']),
            route('road-to-sold.prospects.property-step', ['prospect' => $this->prospect->id, 'step' => 'draft']),
            route('road-to-sold.prospects.results-path', $this->prospect->id),
            route('road-to-sold.prospects.result-form', $this->prospect->id),
            route('road-to-sold.prospects.presentation-path', $this->prospect->id),
        ] as $url) {
            $this->actingAs($other)->get($url)->assertForbidden();
        }
        $this->assertNull($this->plan('rts', $other)['active'] ?? null, "another agent's record has no screens");
        $this->actingAs($this->agent)->get(route('road-to-sold.prospects.property-step', ['prospect' => $this->prospect->id, 'step' => 'nonsense']))->assertNotFound();

        DB::table('rts_prospects')->where('id', $this->prospect->id)->update(['property_id' => null]);
        $this->actingAs($this->agent)->get(route('road-to-sold.prospects.property-step', ['prospect' => $this->prospect->id, 'step' => 'draft']))->assertNotFound();
    }

    public function test_row_buttons_only_the_new_stations_gain_one_and_the_old_ones_keep_theirs(): void
    {
        $action = new \ReflectionMethod(BeltService::class, 'actionFor');
        $action->setAccessible(true);
        $belt = app(BeltService::class);
        $label = fn (string $station) => ($action->invoke($belt, $station, 7, true)['label'] ?? null);

        // Belt v2 (3 Nov): one button per row, named for the next step
        $this->assertSame('Contact', $label('owner'));
        $this->assertSame('Book appointment', $label('contacted'));
        $this->assertSame('Prepare presentation', $label('booked'));
        $this->assertSame('Record result', $label('held'));
        $this->assertSame('Appointment results', $label('prepared'));
        $this->assertSame('Move to Draft', $label('won'));
        $this->assertSame('Make active', $label('draft'));
        $this->assertNull($label('active'));

        $steps = app(ProspectStepService::class);
        $this->stage('presentation_prepared');
        $this->assertStringContainsString('/results-path', (string) $steps->urlFor($this->prospect->fresh(), 'results'));
        $this->assertStringContainsString('/property-step/draft', (string) $steps->urlFor($this->prospect->fresh(), 'draft'));
        $this->assertStringContainsString('/property-step/active', (string) $steps->urlFor($this->prospect->fresh(), 'active'));
    }

    public function test_the_popup_draws_seven_steps_and_knows_the_screens_of_6_to_9(): void
    {
        $js = (string) file_get_contents(public_path('js/pitch-popup.js'));

        $this->assertStringContainsString("['presentation', 'Presentation'], ['results', 'Results'], ['draft', 'Draft'], ['active', 'Active']", $js);
        foreach (['generate_presentation', 'capture_feedback', 'result-form', 'property-step'] as $needle) {
            $this->assertStringContainsString($needle, $js, 'stepOf knows ' . $needle);
        }
        $bar = (string) file_get_contents(resource_path('views/road-to-sold/_blocks-bar.blade.php'));
        $this->assertStringContainsString('find_owner|evaluation|presentation|evaluation_feedback|results|draft|active', $bar, 'every row button runs in the one popup');
    }

    public function test_step_7_before_held_is_the_feedback_form_alone_in_the_popups_own_words(): void
    {
        $this->stage('appointment_set');
        $event = CalendarEvent::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'event_type' => 'manual', 'title' => 'Evaluation: 25 Shepstone Avenue',
            'category' => 'property_evaluation', 'event_date' => '2026-11-03 10:00:00', 'status' => 'pending', 'rts_prospect_id' => $this->prospect->id]);
        $q = ['capture_feedback' => $event->id, 'rts_prospect' => $this->prospect->id, 'rts_stage' => 'appointment_set'];

        $h = $this->actingAs($this->agent)->get(route('command-center.calendar', $q + ['embed' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('[data-qa="cal-root"] { visibility: hidden !important; }', $h, 'nothing of the calendar is shown');
        $this->assertStringContainsString('data-qa="feedback-heading">Appointment feedback<', $h);
        $this->assertStringContainsString('The appointment was held', $h);
        $this->assertStringNotContainsString('The evaluation was held', $h);
        $this->assertStringContainsString('Appointment: ', $h, 'the appointment title reads in the popup\'s wording');
        $this->assertStringContainsString('prospects\/' . $this->prospect->id . '\/results-path?embed=1', $h, 'after saving the popup goes on to where the record now is');
        $this->assertStringContainsString('data-qa="feedback-cancel"', $h);   // hidden by the popup rule, present for the calendar itself

        $plain = $this->actingAs($this->agent)->get(route('command-center.calendar', $q))->assertOk()->getContent();
        $this->assertStringContainsString('Capture Feedback', $plain);
        $this->assertStringContainsString('The evaluation was held', $plain, 'the calendar page itself is unchanged');
        $this->assertStringNotContainsString('[data-qa="cal-root"] { visibility: hidden', $plain);
    }

    public function test_step_6_is_the_generate_presentation_window_alone_inside_the_popup(): void
    {
        $this->stage('contacted');
        $url = route('corex.properties.show', $this->propertyId);
        $admin = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);

        $h = $this->actingAs($admin)->get($url . '?generate_presentation=1&embed=1&rts_prospect=' . $this->prospect->id)->assertOk()->getContent();
        $this->assertStringContainsString('body > *:not([data-qa="presentation-modal"]) { display: none !important; }', $h);
        $this->assertStringContainsString('popup: true,', $h);
        $this->assertStringContainsString('data-qa="presentation-modal"', $h);
        $this->assertStringContainsString('data-qa="presentation-generated"', $h, 'the window says when it is done instead of closing into an empty screen');

        $plain = $this->actingAs($admin)->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('body > *:not([data-qa="presentation-modal"])', $plain);
        $this->assertStringContainsString('popup: false,', $plain, 'the property page itself is unchanged');
    }

    public function test_the_result_form_keeps_only_the_positive_answers_and_the_route_still_takes_the_old_ones(): void
    {
        $this->stage('evaluation_done');

        $h = $this->actingAs($this->agent)->get(route('road-to-sold.prospects.result-form', ['prospect' => $this->prospect->id, 'embed' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString('Won - mandate agreed', $h);
        $this->assertStringContainsString('Undecided - follow up', $h);
        foreach (['Lost to another agency', 'Price too far apart', 'Not ready to sell yet', 'Not selling'] as $lost) {
            $this->assertStringNotContainsString($lost, $h, $lost . ' is the Stops here control now');
        }

        $r = $this->actingAs($this->agent)->post(route('road-to-sold.prospects.result', $this->prospect->id), ['result_key' => 'lost_price']);
        $r->assertSessionHasNoErrors();   // an old value is still understood by the route
    }

    public function test_step_6_never_creates_a_property_on_a_click_and_the_button_does_exactly_that(): void
    {
        $this->stage('contacted');
        DB::table('rts_prospects')->where('id', $this->prospect->id)->update(['property_id' => null]);
        $before = DB::table('properties')->count();

        $h = $this->actingAs($this->agent)->get(route('road-to-sold.prospects.presentation-path', ['prospect' => $this->prospect->id, 'embed' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString('Create the property file to prepare a presentation', $h);
        $this->assertStringContainsString('action="' . route('road-to-sold.prospects.create-property', $this->prospect->id) . '"', $h);
        $this->assertSame($before, DB::table('properties')->count(), 'opening the step made nothing');
        $this->assertNull(DB::table('rts_prospects')->where('id', $this->prospect->id)->value('property_id'));

        $to = $this->actingAs($this->agent)->post(route('road-to-sold.prospects.create-property', $this->prospect->id), ['embed' => 1])->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('/presentation-path', $to);
        $this->assertNotNull(DB::table('rts_prospects')->where('id', $this->prospect->id)->value('property_id'), 'the button made the file');

        $go = $this->actingAs($this->agent)->get($to)->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('generate_presentation=1', $go, 'with a property the Generate window opens as before');

        $this->actingAs($this->makeAgent())->post(route('road-to-sold.prospects.create-property', $this->prospect->id))->assertForbidden();
    }

    public function test_the_popup_mutes_a_step_with_no_screen_and_step_5_says_appointment_everywhere(): void
    {
        $js = (string) file_get_contents(public_path('js/pitch-popup.js'));
        $this->assertStringContainsString('.cxp-step[data-click="0"]:not([data-state="current"])', $js);
        $this->assertStringContainsString("'Not yet'", $js);
        $this->assertStringContainsString('pop.planned', $js, 'nothing is muted before the plan has answered');

        $this->assertSame('Book appointment', ProspectStepService::LABELS['evaluation']);
        $done = view('layouts.partials._embed-done')->render();
        $this->assertStringContainsString('Done - back to Road to Sold', $done);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function starts(): array
    {
        return ['claimed' => ['claimed', 'owner'], 'owner found' => ['owner_found', 'contact'], 'contacted' => ['contacted', 'evaluation'], 'booked' => ['appointment_set', 'presentation'],
            'prepared' => ['presentation_prepared', 'results'], 'held' => ['evaluation_done', 'results'], 'won' => ['mandate_signed', 'draft'], 'draft' => ['draft', 'draft'], 'active' => ['active', 'active']];
    }

    /** @dataProvider starts */
    public function test_both_entry_points_open_at_the_records_own_stage(string $stage, string $step): void
    {
        $this->stage($stage);

        foreach (['rts', 'mic'] as $entry) {
            $this->assertSame($step, $this->plan($entry)['start'], $entry . ' at ' . $stage);
        }
    }

    public function test_the_plan_has_seven_steps_a_header_line_and_no_pitch_step(): void
    {
        $this->stage('owner_found');
        $p = $this->plan('mic');

        $this->assertArrayNotHasKey('pitch', $p);
        $this->assertSame(['owner', 'contact', 'evaluation', 'presentation', 'results', 'draft', 'active'], array_keys($p['reached']));
        $this->assertSame((int) $this->listingId, (int) ($p['card']['listing'] ?? 0), 'the header line is the advert of the record');
        $this->assertSame($this->plan('rts')['card'], $p['card'], 'the same line from either entry point');
        $this->assertStringContainsString('/contacts/' . $this->owner->id . '/outreach/compose', (string) $p['contact']);
    }

    public function test_contact_starts_with_the_seller_chooser_one_line_for_one_seller_a_list_for_more(): void
    {
        $this->stage('owner_found');
        $url = route('seller-outreach.composer.show', ['contact' => $this->owner->id, 'property_id' => $this->propertyId, 'embed' => 1]);

        $h = $this->actingAs($this->agent)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="seller-chooser" data-count="1"', $h);
        $this->assertStringContainsString('data-selected="1"', $h, 'one seller: a single preselected line');
        $this->assertStringNotContainsString('data-selected="0"', $h);
        $this->assertStringNotContainsString('data-pitch-peek', $h, 'the fold-out is the popup\'s header, not a second one on the screen');

        $second = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Pieter', 'last_name' => 'Co-owner', 'phone' => '0825599999']);
        DB::table('contact_property')->insert(['contact_id' => $second->id, 'property_id' => $this->propertyId, 'role' => 'seller', 'created_at' => now(), 'updated_at' => now()]);

        $h = $this->actingAs($this->agent)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="seller-chooser" data-count="2"', $h);
        $this->assertStringContainsString('data-selected="0"', $h);
        $this->assertStringContainsString('/contacts/' . $second->id . '/outreach/compose', $h, 'one click selects the other seller');
    }

    public function test_the_old_pitch_screen_goes_straight_to_contact_inside_the_popup_only(): void
    {
        $this->stage('owner_found');

        $to = $this->actingAs($this->agent)->get(route('seller-outreach.entry.pitch-ready-prospecting', ['prospectingListingId' => $this->listingId, 'embed' => 1]))->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('/contacts/' . $this->owner->id . '/outreach/compose', $to);

        $this->actingAs($this->agent)->get(route('seller-outreach.entry.pitch-ready-prospecting', ['prospectingListingId' => $this->listingId]))->assertOk();   // the page itself is unchanged
    }

    public function test_the_popup_shell_has_the_folded_header_and_opens_at_the_plans_start(): void
    {
        $js = (string) file_get_contents(public_path('js/pitch-popup.js'));

        $this->assertStringContainsString("data-qa': 'pitch-header'", $js);
        $this->assertStringContainsString("'data-pitch-peek': ''", $js, 'the one fold-out component');
        $this->assertStringContainsString('d.steps[d.steps.start]', $js);
        $this->assertStringNotContainsString("['property', 'Property']", $js);
    }
}
