<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Contact;
use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RolePermission;
use App\Services\PermissionService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\OutcomeService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages;
use App\Services\RoadToSold\RoadToSoldException;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v4, belt step A3 (road-to-sold.md 23.7 + the 2 Nov ruling): ONE contact record with a channel; the Market Intelligence feedback is an
 * adapter onto it; "Log contact" is gated on the owner and refreshes in place; the outcome list is one vocabulary (forward / try again / off-ramps).
 */
final class ChannelOutcomeTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 09:00:00');   // a Friday
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        config(['road-to-sold-belt.require_channel' => true]);
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function def(string $key): Def
    {
        return Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', $key)->firstOrFail();
    }

    /** A claimed property whose owner is found, so the gate is open. */
    private function ready(?\App\Models\User $agent = null): array
    {
        $agent ??= $this->agent;
        $tp = $this->tp(['street_name' => 'Bairn Street', 'street_number' => (string) random_int(1, 999)]);
        $listing = $this->listing([], $tp);
        $claim = ProspectingClaim::create(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $listing, 'user_id' => $agent->id,
            'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now()])->refresh();
        $owner = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $agent->id,
            'agent_id' => $agent->id, 'first_name' => 'Pieter', 'last_name' => 'Owner', 'phone' => '0825512345']);
        $tp->update(['owner_contact_id' => $owner->id, 'deeds_captured_at' => now()]);

        return [$tp, $claim, $listing];
    }

    public function test_a_contact_outcome_needs_its_channel_and_stores_it(): void
    {
        [$tp] = $this->ready();
        $this->actingAs($this->agent);
        $svc = app(OutcomeService::class);

        try {
            $svc->record($this->agent, (int) $tp->id, $this->def('no_answer')->id);
            $this->fail('a contact with no channel should be refused');
        } catch (RoadToSoldException $e) {
            $this->assertSame('Pick how you contacted them.', $e->getMessage());
        }
        try {
            $svc->record($this->agent, (int) $tp->id, $this->def('no_answer')->id, ['channel' => 'carrier_pigeon']);
            $this->fail('an unknown channel should be refused');
        } catch (RoadToSoldException $e) {
            $this->assertStringContainsString('phone, WhatsApp, email or in person', $e->getMessage());
        }

        $o = $svc->record($this->agent, (int) $tp->id, $this->def('no_answer')->id, ['channel' => 'whatsapp']);

        $this->assertSame('whatsapp', $o->fresh()->channel);
    }

    public function test_the_outcomes_that_only_report_an_owner_problem_need_no_channel(): void
    {
        $tp = $this->tp(['street_name' => 'Nobody Street']);
        $this->actingAs($this->agent);

        $o = app(OutcomeService::class)->record($this->agent, (int) $tp->id, $this->def('no_owner_found')->id);

        $this->assertNull($o->fresh()->channel, 'nothing was contacted, so no channel');
    }

    public function test_the_label_reads_for_the_way_contact_was_made(): void
    {
        $noAnswer = $this->def('no_answer');
        $spoke = $this->def('spoke_call_back');
        $this->assertSame('No answer', $noAnswer->labelFor('phone'));
        $this->assertSame('No reply', $noAnswer->labelFor('whatsapp'));
        $this->assertSame('No reply', $noAnswer->labelFor('email'));
        $this->assertSame('Not there', $noAnswer->labelFor('in_person'));
        $this->assertSame('Spoke - call back', $spoke->labelFor('phone'));
        $this->assertSame('Replied - call back', $spoke->labelFor('email'));
        $this->assertSame('Met - call back', $spoke->labelFor('in_person'));

        [$tp] = $this->ready();
        $this->actingAs($this->agent);
        $o = app(OutcomeService::class)->record($this->agent, (int) $tp->id, $noAnswer->id, ['channel' => 'email']);
        $this->assertSame('No reply', $o->outcome_label, 'the history keeps the wording the agent saw');
    }

    public function test_one_vocabulary_forward_try_again_and_off_ramps(): void
    {
        $this->assertSame(Def::GROUP_FORWARD, $this->def('spoke_evaluation_booked')->group());
        $this->assertSame(Def::GROUP_AGAIN, $this->def('no_answer')->group());
        $this->assertSame(Def::GROUP_AGAIN, $this->def('spoke_call_back')->group());
        foreach (['spoke_not_interested', 'wrong_number', 'listed_other_agency_open', 'mandated_other_agency_sole', 'do_not_contact', 'no_owner_found'] as $k) {
            $this->assertSame(Def::GROUP_OFF_RAMP, $this->def($k)->group(), $k);
        }
    }

    public function test_the_mic_feedback_contacted_is_a_call_back_outcome_three_working_days_ahead_on_the_phone(): void
    {
        [$tp, $claim, $listing] = $this->ready();

        $this->actingAs($this->agent)->post(route('market-intelligence.feedback', $listing), ['status' => 'contacted', 'notes' => 'Left a message'])
            ->assertSessionHas('success');

        $o = RtsContactOutcome::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->firstOrFail();
        $this->assertSame('spoke_call_back', $o->outcome_key);
        $this->assertSame('phone', $o->channel);
        $this->assertSame('Left a message', $o->note);
        $this->assertSame('2026-11-04', $o->next_action_at->toDateString(), 'Friday + 3 working days');
        $claim->refresh();
        $this->assertSame(ProspectingClaim::STATUS_CONTACTED, $claim->status, 'the claim status follows the station');
        $this->assertNotNull($claim->feedback_at);
        $this->assertSame(ProspectStages::CONTACTED, app(ProspectService::class)->find($this->agency->id, (int) $tp->id)->stage);
    }

    public function test_the_call_back_days_are_the_agencys_setting(): void
    {
        \App\Models\RoadToSold\RtsSetting::query()->withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], ['default_callback_days' => 5]);
        SettingsService::forget();
        [$tp, , $listing] = $this->ready();

        $this->actingAs($this->agent)->post(route('market-intelligence.feedback', $listing), ['status' => 'contacted']);

        $this->assertSame('2026-11-06', RtsContactOutcome::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->value('next_action_at') ? RtsContactOutcome::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->first()->next_action_at->toDateString() : null);
    }

    public function test_mic_feedback_not_interested_is_the_six_month_outcome_and_the_claim_stays_on_the_belt(): void
    {
        [$tp, $claim, $listing] = $this->ready();

        $this->actingAs($this->agent)->post(route('market-intelligence.feedback', $listing), ['status' => 'not_interested'])->assertSessionHas('success');

        $o = RtsContactOutcome::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->firstOrFail();
        $this->assertSame('spoke_not_interested', $o->outcome_key);
        $this->assertSame('2027-04-30', $o->next_action_at->toDateString(), 'six months, the outcome\'s own rule');
        $this->assertTrue((bool) $claim->fresh()->is_active, 'the property comes back on that date, so the claim is not thrown away');
    }

    public function test_mic_feedback_that_needs_a_date_or_a_result_points_to_the_belt(): void
    {
        [$tp, , $listing] = $this->ready();

        $this->actingAs($this->agent)->post(route('market-intelligence.feedback', $listing), ['status' => 'meeting_set'])
            ->assertSessionHas('error', 'Book the evaluation from Road to Sold - it needs a date and a time.');
        $this->actingAs($this->agent)->post(route('market-intelligence.feedback', $listing), ['status' => 'listing'])
            ->assertSessionHas('error', 'Record the result from Road to Sold - it needs the result of the evaluation.');

        $this->assertSame(0, RtsContactOutcome::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->count());
    }

    public function test_mic_feedback_no_longer_pays_the_claim_feedback_credit(): void
    {
        // InstantPointService is final (cannot be mocked): the handler must not reach the credit call any more
        $src = file_get_contents(app_path('Listeners/Activity/CreditInstantActionListener.php'));
        $this->assertStringNotContainsString("safeCredit('mic.claim_feedback'", $src, 'one call is never paid twice: the outcome carries the points now');
        [, $claim] = $this->ready();
        app(\App\Listeners\Activity\CreditInstantActionListener::class)->handleClaimFeedbackRecorded(new \App\Events\Prospecting\ClaimFeedbackRecorded($claim, 'contacted', null));
        $this->addToAssertionCount(1);
    }

    private function pageAs(\App\Models\User $u)
    {
        foreach (['agent' => 'own'] as $role => $scope) {
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id], ['scope' => $scope]);
        }
        PermissionService::clearCache();

        return $this->actingAs($u)->get(route('road-to-sold.index'));
    }

    private function listRow(\App\Models\Prospecting\TrackedProperty $tp): void
    {
        RtsListItem::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'user_id' => $this->agent->id, 'list_date' => '2026-10-30', 'track' => 'sales',
            'tracked_property_id' => $tp->id, 'selection' => 'price_drops', 'origin' => 'auto', 'position' => 1, 'status' => 'open', 'reason' => 'Price dropped',
        ]);
    }

    public function test_log_contact_waits_for_the_owner_then_opens_with_the_channel_first(): void
    {
        config(['road-to-sold-belt.enforce_owner_gate' => true]);
        $tp = $this->tp(['street_name' => 'Marine Drive', 'street_number' => '14']);
        $this->listRow($tp);

        $blocked = $this->pageAs($this->agent)->assertOk();
        // no greyed duplicate on the row, and the card says what the next step really is
        $blocked->assertSee('Find the owner of 14 Marine Drive')->assertDontSee('Call 14 Marine Drive')->assertDontSee('Find owner first')
            ->assertDontSee('data-qa="log-contact-blocked"', false)->assertDontSee('data-qa="log-contact"', false);

        $owner = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => 'Pieter', 'last_name' => 'Owner', 'phone' => '0825512345']);
        $tp->update(['owner_contact_id' => $owner->id, 'deeds_captured_at' => now()]);

        $open = $this->pageAs($this->agent)->assertOk();
        $open->assertSee('data-qa="log-contact"', false)->assertSee('Log contact')->assertDontSee('data-qa="log-contact-blocked"', false);
        $open->assertSee('How did you contact them?')->assertSee('name="channel"', false);
        $open->assertDontSee('Contacted client');
    }

    public function test_the_page_swaps_the_rows_in_place_instead_of_reloading_after_a_pop_up(): void
    {
        $tp = $this->tp(['street_name' => 'Marine Drive', 'street_number' => '14']);
        $this->listRow($tp);

        $html = $this->pageAs($this->agent)->getContent();

        $this->assertStringContainsString('window.rtsRefreshLive', $html);
        $this->assertStringContainsString('corex-pitch-closed', $html);
        $this->assertStringContainsString("if (window.rtsRefreshLive) { window.rtsRefreshLive(); }", $html);
    }
}
