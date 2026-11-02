<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Events\Prospecting\OwnerLinkedToTrackedProperty;
use App\Events\Prospecting\TrackedPropertyEnriched;
use App\Models\Contact;
use App\Models\DailyActivityEntry;
use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RoadToSold\RtsProspect;
use App\Models\User;
use App\Services\Activity\ActivityPointsService;
use App\Services\RoadToSold\BeltPoints;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\OutcomeService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** Road to Sold v4 belt points (road-to-sold.md 23.16): agency amounts, one credit per event, reversal, the log and the totals. */
final class BeltPointsTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 09:00:00');   // a Friday
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        ActivityPointsService::forgetCutovers();
        Cache::flush();
        $this->setUpAgency();
        config(['road-to-sold-belt.require_channel' => true, 'road-to-sold-belt.enforce_owner_gate' => false]);
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function points(): BeltPoints
    {
        return app(BeltPoints::class);
    }

    private function def(string $key, ?int $agencyId = null): Def
    {
        return Def::withoutGlobalScopes()->where('agency_id', $agencyId ?? $this->agency->id)->where('key', $key)->firstOrFail();
    }

    /** A property the agent has claimed (so the belt record exists). */
    private function claimed(?User $agent = null, ?int $agencyId = null): array
    {
        $agent ??= $this->agent;
        $tp = $this->tp(['street_name' => 'Bairn Street', 'street_number' => (string) random_int(1, 999)]);
        $listing = $this->listing([], $tp);
        ProspectingClaim::create(['agency_id' => $agencyId ?? $this->agency->id, 'prospecting_listing_id' => $listing, 'user_id' => $agent->id,
            'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now()]);
        $prospect = RtsProspect::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->where('user_id', $agent->id)->firstOrFail();

        return [$tp, $prospect];
    }

    private function entries(User $agent, string $slug)
    {
        $defIds = DB::table('activity_definition_calendar_classes')->where('agency_id', $agent->agency_id)->where('slug', $slug)->pluck('activity_definition_id');

        return DailyActivityEntry::query()->withoutGlobalScopes()->where('user_id', $agent->id)->whereIn('activity_definition_id', $defIds)->get();
    }

    private function pointsOf(User $agent): float
    {
        return ActivityPointsService::sum(ActivityPointsService::counted($agent->agency_id)->where('e.user_id', $agent->id));
    }

    public function test_every_agency_gets_its_own_credits_at_the_spec_defaults_and_a_second_agency_is_separate(): void
    {
        $amounts = $this->points()->amounts($this->agency->id);
        $this->assertSame(['rts.deed_linked' => 20, 'rts.owner_linked' => 30, 'rts.outcome.phone' => 20, 'rts.outcome.whatsapp' => 10, 'rts.outcome.email' => 10,
            'rts.outcome.in_person' => 40, 'rts.offramp_logged' => 20, 'rts.evaluation_feedback' => 50, 'rts.advertised' => 50], $amounts);

        $other = \App\Models\Agency::create(['name' => 'Cape ' . uniqid(), 'slug' => 'cape-' . uniqid()]);
        $this->points()->setAmount($this->agency->id, 'rts.deed_linked', 35);
        $this->assertSame(35, $this->points()->amount($this->agency->id, 'rts.deed_linked'));
        $this->points()->ensureAgency($other->id);
        $this->assertSame(20, $this->points()->amount($other->id, 'rts.deed_linked'), 'the second agency is not HFC: its own row, its own amount');
        $this->assertSame(0, $this->points()->ensureAgency($this->agency->id), 'idempotent: nothing is made twice');
    }

    public function test_an_outcome_pays_its_channel_once_and_the_amount_is_the_agencys_own(): void
    {
        [$tp] = $this->claimed();
        $this->actingAs($this->agent);
        $o = app(OutcomeService::class)->record($this->agent, (int) $tp->id, $this->def('spoke_call_back')->id, ['channel' => 'in_person', 'next_action_at' => '2026-11-10']);
        $this->assertSame(40.0, $this->pointsOf($this->agent));
        $this->assertCount(1, $this->entries($this->agent, 'rts.outcome.in_person'));

        // the listener runs again for the same outcome (a re-fire): nothing more is paid
        event(new \App\Events\RoadToSold\RoadToSoldOutcomeRecorded($o->id, $this->agency->id, 'sales', $o->outcome_key, $o->kind, (int) $tp->id, $this->agent->id, null, false, null, $this->agent->id, 'in_person'));
        $this->assertSame(40.0, $this->pointsOf($this->agent));

        $this->points()->setAmount($this->agency->id, 'rts.outcome.phone', 25);
        [$tp2] = $this->claimed();
        app(OutcomeService::class)->record($this->agent, (int) $tp2->id, $this->def('spoke_call_back')->id, ['channel' => 'phone', 'next_action_at' => '2026-11-10']);
        $this->assertSame(65.0, $this->pointsOf($this->agent), '40 + the agency\'s 25 for a phone call');
    }

    public function test_a_no_answer_streak_pays_only_the_first_attempt(): void
    {
        [$tp] = $this->claimed();
        $this->actingAs($this->agent);
        $svc = app(OutcomeService::class);
        $svc->record($this->agent, (int) $tp->id, $this->def('no_answer')->id, ['channel' => 'phone']);
        $svc->record($this->agent, (int) $tp->id, $this->def('no_answer')->id, ['channel' => 'phone']);
        $svc->record($this->agent, (int) $tp->id, $this->def('no_answer')->id, ['channel' => 'whatsapp']);
        $this->assertSame(20.0, $this->pointsOf($this->agent), 'three attempts in a row, one credit: the first');

        $svc->record($this->agent, (int) $tp->id, $this->def('spoke_call_back')->id, ['channel' => 'phone', 'next_action_at' => '2026-11-10']);
        $svc->record($this->agent, (int) $tp->id, $this->def('no_answer')->id, ['channel' => 'email']);
        $this->assertSame(20.0 + 20.0 + 10.0, $this->pointsOf($this->agent), 'a conversation breaks the streak; the next attempt starts a new one');
    }

    public function test_an_off_ramp_outcome_pays_for_the_work_as_well_as_its_channel(): void
    {
        [$tp] = $this->claimed();
        $this->actingAs($this->agent);
        app(OutcomeService::class)->record($this->agent, (int) $tp->id, $this->def('spoke_not_interested')->id, ['channel' => 'phone', 'next_action_at' => '2026-11-10']);
        $this->assertSame(20.0 + 20.0, $this->pointsOf($this->agent));
        $this->assertCount(1, $this->entries($this->agent, 'rts.offramp_logged'));

        [$tp2] = $this->claimed();
        app(OutcomeService::class)->record($this->agent, (int) $tp2->id, $this->def('spoke_call_back')->id, ['channel' => 'phone', 'next_action_at' => '2026-11-10']);
        $this->assertCount(1, $this->entries($this->agent, 'rts.offramp_logged'), 'a try-again outcome is not an off-ramp');
    }

    public function test_a_credit_switched_off_for_the_agency_pays_nothing_and_what_was_earned_stays(): void
    {
        [$tp] = $this->claimed();
        $this->actingAs($this->agent);
        app(OutcomeService::class)->record($this->agent, (int) $tp->id, $this->def('spoke_call_back')->id, ['channel' => 'phone', 'next_action_at' => '2026-11-10']);
        $this->assertSame(20.0, $this->pointsOf($this->agent));

        $this->points()->setAmount($this->agency->id, 'rts.outcome.phone', 0);
        $this->assertSame(0, $this->points()->amount($this->agency->id, 'rts.outcome.phone'));
        [$tp2] = $this->claimed();
        app(OutcomeService::class)->record($this->agent, (int) $tp2->id, $this->def('spoke_call_back')->id, ['channel' => 'phone', 'next_action_at' => '2026-11-10']);
        $this->assertSame(20.0, $this->pointsOf($this->agent), 'off: the second call earns nothing, the first stays earned');
    }

    public function test_deleting_an_outcome_takes_its_points_back_and_redoing_it_pays_again(): void
    {
        [$tp] = $this->claimed();
        $this->actingAs($this->agent);
        $o = app(OutcomeService::class)->record($this->agent, (int) $tp->id, $this->def('spoke_not_interested')->id, ['channel' => 'phone', 'next_action_at' => '2026-11-10']);
        $this->assertSame(40.0, $this->pointsOf($this->agent));

        RtsContactOutcome::query()->withoutGlobalScopes()->find($o->id)->delete();
        $this->assertSame(0.0, $this->pointsOf($this->agent), 'both credits of the outcome are taken back');
        $this->assertSame(DailyActivityEntry::STATE_REVOKED, $this->entries($this->agent, 'rts.outcome.phone')->first()->point_state);

        // the same subject credited again (the outcome restored) is paid again, not stuck revoked
        $restored = RtsContactOutcome::withTrashed()->withoutGlobalScopes()->find($o->id);
        $restored->restore();
        $this->assertTrue($this->points()->credit('rts.outcome.phone', $this->agent, $restored));
        $this->assertSame(20.0, $this->pointsOf($this->agent));
    }

    public function test_deed_and_owner_linked_pay_the_agent_working_the_property_once_per_belt_record(): void
    {
        [$tp, $prospect] = $this->claimed();
        $bystander = $this->mate;   // same agency, not working this property

        event(new OwnerLinkedToTrackedProperty((int) $tp->id, $this->agency->id, 99, $bystander->id));
        event(new OwnerLinkedToTrackedProperty((int) $tp->id, $this->agency->id, 99, $bystander->id));   // re-fired
        event(new TrackedPropertyEnriched((int) $tp->id, $this->agency->id, 'deeds_capture', ['owner_name'], $bystander->id));
        event(new TrackedPropertyEnriched((int) $tp->id, $this->agency->id, 'deeds_capture', ['owner_name'], $bystander->id));
        event(new TrackedPropertyEnriched((int) $tp->id, $this->agency->id, 'p24', ['price'], $bystander->id));   // not a deed

        $this->assertSame(50.0, $this->pointsOf($this->agent), '30 owner + 20 deed, once each');
        $this->assertSame(0.0, $this->pointsOf($bystander), 'the agent who linked it but is not working it earns nothing; the one working it does');
        $this->assertCount(1, $this->entries($this->agent, 'rts.owner_linked'));
        $this->assertSame(RtsProspect::class, $this->entries($this->agent, 'rts.deed_linked')->first()->subject_type);
        $this->assertSame((int) $prospect->id, (int) $this->entries($this->agent, 'rts.deed_linked')->first()->subject_id);
    }

    public function test_nobody_is_paid_for_a_property_nobody_works(): void
    {
        $tp = $this->tp(['street_name' => 'Empty Street', 'street_number' => '5']);
        event(new OwnerLinkedToTrackedProperty((int) $tp->id, $this->agency->id, 99, $this->agent->id));
        $this->assertSame(0, DailyActivityEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_the_waiting_credits_are_paid_by_the_lane_that_raises_their_event(): void
    {
        [, $prospect] = $this->claimed();
        $this->assertTrue($this->points()->credit('rts.advertised', $this->agent, $prospect));
        $this->assertFalse($this->points()->credit('rts.advertised', $this->agent, $prospect), 'once per prospect');
        $this->assertTrue($this->points()->credit('rts.evaluation_feedback', $this->agent, $prospect));
        $this->assertSame(100.0, $this->pointsOf($this->agent));
        $this->assertFalse($this->points()->credit('rts.not_a_credit', $this->agent, $prospect));
        $this->assertFalse($this->points()->credit('rts.advertised', null, $prospect));
    }

    public function test_totals_and_the_log_read_back_what_was_earned_and_only_the_agents_own(): void
    {
        [$tp] = $this->claimed();
        $this->actingAs($this->agent);
        app(OutcomeService::class)->record($this->agent, (int) $tp->id, $this->def('spoke_not_interested')->id, ['channel' => 'in_person', 'next_action_at' => '2026-11-10']);   // 40 + 20
        Carbon::setTestNow('2026-10-29 09:00:00');
        [, $earlier] = $this->claimed();
        $this->points()->credit('rts.advertised', $this->agent, $earlier, ['date' => '2026-10-29']);   // 50, yesterday
        Carbon::setTestNow('2026-10-30 09:00:00');
        [$tpMate] = $this->claimed($this->mate);
        event(new OwnerLinkedToTrackedProperty((int) $tpMate->id, $this->agency->id, 99, null));   // the colleague's 30

        $t = $this->points()->totals($this->agent, '2026-10-30');
        $this->assertSame(60.0, $t['day']);
        $this->assertSame(110.0, $t['month']);
        $this->assertSame(60.0, $t['belt_day']);
        $this->assertSame(110.0, $t['belt_month']);
        $this->assertSame(30.0, $this->points()->totals($this->mate, '2026-10-30')['day'], 'a colleague\'s points are their own');

        $log = $this->points()->log($this->agent);
        $this->assertSame(3, $log->total(), 'the agent\'s three credits and nothing of the colleague');
        $this->assertSame(['Dead end logged with a reason', 'Outcome captured - in person', 'Advertised'], $log->getCollection()->pluck('event')->all());
        $this->assertStringContainsString('Bairn Street', (string) $log->first()->property);
        $this->assertSame(1, $this->points()->log($this->agent, ['event' => 'rts.advertised'])->total());
        $this->assertSame(1, $this->points()->log($this->agent, ['from' => '2026-10-30', 'to' => '2026-10-30', 'event' => 'rts.offramp_logged'])->total());
        $this->assertSame(0, $this->points()->log($this->agent, ['q' => 'no such street'])->total());
    }

    public function test_the_points_screens_render_and_the_settings_save_is_audited(): void
    {
        [$tp] = $this->claimed();
        $this->actingAs($this->agent);
        app(OutcomeService::class)->record($this->agent, (int) $tp->id, $this->def('spoke_not_interested')->id, ['channel' => 'phone', 'next_action_at' => '2026-11-10']);

        $page = $this->actingAs($this->agent)->get(route('road-to-sold.points'));
        $page->assertOk()->assertSee('data-qa="points-log"', false)->assertSee('Bairn Street')->assertSee('Outcome captured - phone');
        $this->actingAs($this->agent)->get(route('road-to-sold.points', ['q' => 'zzz-none']))->assertOk()->assertSee('No points match this filter.');

        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->get(route('road-to-sold.settings.points.index'))->assertOk()->assertSee('data-qa="points-rts.deed_linked"', false);
        $this->actingAs($admin)->put(route('road-to-sold.settings.points.save'), ['points' => ['rts_deed_linked' => 33, 'rts_advertised' => 0]])->assertRedirect();
        $this->assertSame(33, $this->points()->amount($this->agency->id, 'rts.deed_linked'));
        $this->assertSame(0, $this->points()->amount($this->agency->id, 'rts.advertised'));
        $audit = \App\Models\RoadToSold\RtsSettingAuditEntry::query()->withoutGlobalScopes()->where('agency_id', $this->agency->id)->latest('id')->first();
        $this->assertEquals(['points.rts.deed_linked' => 20, 'points.rts.advertised' => 50], $audit->old_values);
        $this->assertEquals(['points.rts.deed_linked' => 33, 'points.rts.advertised' => 0], $audit->new_values);
        $this->actingAs($admin)->put(route('road-to-sold.settings.points.save'), ['points' => ['rts_deed_linked' => -4]])->assertSessionHasErrors();
    }
}
