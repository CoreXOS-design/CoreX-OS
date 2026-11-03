<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsProspect;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * road-to-sold.md 23.25 items 1-2: step 5 reads "Appointment", and a step pill ticks when its STATION is reached (the record's stage), never because the agent opened
 * the screen. Both entry points (a Road to Sold row asks by prospect, MIC Pitch now asks by advert) get the same answer from the same plan.
 */
final class PitchPopupTicksTest extends TestCase
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

    /** The ticks the popup would draw: step => bool, asked the way each entry point asks. */
    private function reached(string $entry, ?\App\Models\User $as = null): array
    {
        $q = $entry === 'rts' ? ['prospect' => $this->prospect->id] : ['listing' => $this->listingId];

        return $this->actingAs($as ?? $this->agent)->getJson(route('seller-outreach.entry.pitch-steps', $q))->assertOk()->json('steps.reached');
    }

    private function stage(string $stage): void
    {
        DB::table('rts_prospects')->where('id', $this->prospect->id)->update(['stage' => $stage]);
    }

    private function ticked(array $r): array
    {
        return array_keys(array_filter($r));
    }

    /** @return array<string,array{0:string,1:list<string>}> */
    public static function stages(): array
    {
        return [
            'claimed: only Property' => ['claimed', ['property']],
            'owner found: Property and Owner' => ['owner_found', ['property', 'owner']],
            'contacted: up to Contact (Pitch is behind it)' => ['contacted', ['property', 'owner', 'pitch', 'contact']],
            'appointment set: all five' => ['appointment_set', ['property', 'owner', 'pitch', 'contact', 'evaluation']],
            'a later station counts the earlier ones' => ['presentation_prepared', ['property', 'owner', 'pitch', 'contact', 'evaluation']],
        ];
    }

    /** @dataProvider stages */
    public function test_the_ticks_are_the_stations_the_record_reached_from_either_entry_point(string $stage, array $expected): void
    {
        $this->stage($stage);

        $row = $this->reached('rts');
        $mic = $this->reached('mic');

        $this->assertSame($expected, $this->ticked($row), 'a Road to Sold row');
        $this->assertSame($row, $mic, 'MIC Pitch now on the same property shows the same ticks');
        $this->assertSame(['property', 'owner', 'pitch', 'contact', 'evaluation'], array_keys($row), 'one answer for every step the bar draws');
    }

    public function test_a_record_at_owner_found_shows_1_and_2_ticked_and_4_and_5_plain(): void
    {
        $this->stage('owner_found');

        $r = $this->reached('rts');

        $this->assertTrue($r['property'] && $r['owner']);
        $this->assertFalse($r['pitch'], 'the step the agent is on is "current", not ticked');
        $this->assertFalse($r['contact']);
        $this->assertFalse($r['evaluation']);
    }

    public function test_opening_the_appointment_screen_without_booking_leaves_it_unticked(): void
    {
        $this->stage('contacted');
        $plan = $this->actingAs($this->agent)->getJson(route('seller-outreach.entry.pitch-steps', ['prospect' => $this->prospect->id]))->assertOk()->json('steps');
        $this->assertStringContainsString('pitch_book=1', (string) $plan['evaluation'], 'the screen exists, so the pill is clickable');

        $this->actingAs($this->agent)->get($plan['evaluation'])->assertOk();   // the agent opens it and leaves

        $this->assertFalse($this->reached('rts')['evaluation']);
        $this->assertFalse($this->reached('mic')['evaluation']);

        $this->stage('appointment_set');   // the booking moves the record
        $this->assertTrue($this->reached('rts')['evaluation']);
    }

    public function test_a_parked_record_keeps_the_ticks_of_the_stations_it_reached(): void
    {
        $this->stage('contacted');
        DB::table('rts_prospects')->where('id', $this->prospect->id)->update(['parked_at' => now()]);

        $this->assertSame(['property', 'owner', 'pitch', 'contact'], $this->ticked($this->reached('rts')));
    }

    public function test_a_pitch_already_sent_by_this_agent_ticks_pitch_before_any_contact_is_recorded(): void
    {
        $this->stage('owner_found');
        $send = fn (int $agentId, string $code) => DB::table('seller_outreach_sends')->insert([
            'agency_id' => $this->agency->id, 'contact_id' => $this->owner->id, 'property_id' => $this->propertyId, 'agent_id' => $agentId, 'channel' => 'whatsapp',
            'body_snapshot' => 'Hi', 'facts_snapshot' => '[]', 'tracking_short_code' => $code, 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $send($this->makeAgent()->id, 'AAA111');
        $this->assertFalse($this->reached('rts')['pitch'], "another agent's pitch is not mine");

        $send($this->agent->id, 'BBB222');
        $this->assertSame(['property', 'owner', 'pitch'], $this->ticked($this->reached('rts')));
    }

    public function test_an_advert_nobody_has_claimed_has_nothing_reached(): void
    {
        $tp2 = $this->tp(['street_name' => 'Beach Road', 'street_number' => '9']);
        $listing2 = $this->listing([], $tp2);

        $r = $this->actingAs($this->agent)->getJson(route('seller-outreach.entry.pitch-steps', ['listing' => $listing2]))->assertOk()->json('steps.reached');

        $this->assertSame([], $this->ticked($r), 'a MIC advert not yet on the belt: no ticks, not even Property');
    }

    public function test_another_agents_record_is_never_read_for_the_ticks(): void
    {
        $this->stage('contacted');

        $this->assertSame([], $this->ticked($this->reached('rts', $this->makeAgent())));
        $this->assertSame([], $this->ticked($this->reached('mic', $this->makeAgent())));
    }

    public function test_the_popup_draws_ticks_from_reached_and_calls_step_5_appointment(): void
    {
        $js = (string) file_get_contents(public_path('js/pitch-popup.js'));

        $this->assertStringContainsString("['evaluation', 'Appointment']", $js);
        $this->assertStringContainsString("pop.reached[s[0]] === true ? 'done'", $js, 'done comes from the server answer');
        $this->assertStringNotContainsString('visited', $js, 'opening a screen ticks nothing');
        $this->assertStringContainsString('Appointment booked', $js);
        $this->assertDoesNotMatchRegularExpression("/'(Evaluation|Evaluation booked ·[^']*)'/", $js, 'the old word is not shown to the agent');
    }

    public function test_the_popups_step_5_screens_and_buttons_say_appointment(): void
    {
        $phone = (string) file_get_contents(resource_path('views/seller-outreach/_phone-call-form.blade.php'));
        $this->assertStringContainsString('Book appointment →', $phone);
        $this->assertStringContainsString('Next: book the appointment →', $phone);
        $this->assertStringNotContainsString('Book evaluation', $phone);
        $this->assertStringNotContainsString('book the evaluation', $phone);

        $booked = (string) file_get_contents(resource_path('views/seller-outreach/evaluation-booked.blade.php'));
        $this->assertStringContainsString('✓ Appointment booked', $booked);
        $this->assertStringNotContainsStringIgnoringCase('valuation booked', $booked);
    }
}
