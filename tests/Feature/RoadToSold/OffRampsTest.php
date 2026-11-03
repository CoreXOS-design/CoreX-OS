<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Branch;
use App\Models\Property;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RoadToSold\RtsSetting;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Performance\Period;
use App\Services\RoadToSold\BeltFunnelService;
use App\Services\RoadToSold\BlockerService;
use App\Services\RoadToSold\FunnelService;
use App\Services\RoadToSold\ManagerActionService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ParkReasonService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v4, belt step A6 (road-to-sold.md 23.11 / 23.12): off-ramp reasons by station, derived blockers, the funnel and off-ramp figures (own vs branch vs
 * agency, periods, compare) and the training flag.
 */
final class OffRampsTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private ProspectService $prospects;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        ParkReasonService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        $this->prospects = app(ProspectService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function period(string $from = '2026-10-01', string $to = '2026-10-31'): Period
    {
        return new Period(CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->endOfDay(), 'Test', 'custom');
    }

    private function at(User $agent, string $stage = S::FOUND): RtsProspect
    {
        static $n = 100;

        return $this->prospects->place($agent, (int) $this->tp(['street_name' => 'Road', 'street_number' => (string) $n++])->id, 'sales', ['stage' => $stage]);
    }

    private function settings(array $set): void
    {
        RtsSetting::query()->withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], $set);
        SettingsService::forget();
    }

    // ── reasons by station ───────────────────────────────────────────────

    public function test_the_reason_list_is_by_station_with_its_own_reasons_first(): void
    {
        $svc = app(ParkReasonService::class);

        $contacted = $svc->choices($this->agency->id, 'sales', 'contacted')->pluck('key')->all();
        $this->assertSame('not_interested', $contacted[0], "the station's own reasons come first");
        $this->assertContains('already_listed_open', $contacted);
        $this->assertContains('other', $contacted, 'the any-station reasons follow');
        $this->assertNotContains('wrong_number', $contacted, "another station's own reasons are left out");
        $this->assertNotContains('blk_mandate', $contacted, 'a blocker is never a choice');

        $result = $svc->choices($this->agency->id, 'sales', 'result')->pluck('label')->all();
        $this->assertContains('Not selling - seller withdrew', $result);
        $this->assertContains('Not selling - family decision', $result);

        $this->assertSame('contacted', ParkReasonService::stationOfStage('contacted'));
        $this->assertSame('result', ParkReasonService::stationOfStage('mandate_signed'));
        $this->assertNull(ParkReasonService::stationOfStage('lead'));
    }

    public function test_an_agency_list_seeded_before_keeps_its_edits_and_gets_the_station_reasons_added(): void
    {
        $svc = app(ParkReasonService::class);
        $svc->choices($this->agency->id);   // seeds
        \App\Models\RoadToSold\RtsParkReason::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'other')->update(['label' => 'Something else']);
        \App\Models\RoadToSold\RtsParkReason::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'selling_privately')->delete();
        ParkReasonService::forgetMemo();

        $labels = app(ParkReasonService::class)->choices($this->agency->id, 'sales', 'contacted')->pluck('label', 'key');

        $this->assertSame('Something else', $labels['other'], "the agency's wording stays");
        $this->assertArrayNotHasKey('selling_privately', $labels->all(), 'an archived reason stays archived');
    }

    public function test_a_do_not_contact_reason_closes_the_record_for_good(): void
    {
        $p = $this->at($this->agent, S::CONTACTED);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id], ['scope' => 'own']);
        \App\Services\PermissionService::clearCache();

        $this->actingAs($this->agent)->post(route('road-to-sold.prospects.park', $p->id), ['reason' => 'do_not_contact'])->assertSessionHas('status');

        $p->refresh();
        $this->assertNotNull($p->closed_at);
        $this->assertSame('do_not_contact', $p->closed_reason);
        $this->assertNull($p->parked_at);
    }

    // ── the funnel ───────────────────────────────────────────────────────

    public function test_a_property_counts_once_per_station_and_a_skipped_station_counts(): void
    {
        $a = $this->at($this->agent);                                        // placed at Found -> Loaded
        $this->prospects->advance($a, S::CONTACTED, $this->agent, ['event_class' => 't']);   // skipped Claimed and Owner
        $this->prospects->advance($a, S::APPOINTMENT_SET, $this->agent, ['event_class' => 't']);
        $b = $this->at($this->agent, S::CLAIMED);                            // placed directly at Claimed: it was on Loaded too

        $f = app(BeltFunnelService::class);
        $fig = $f->figures($this->agency->id, $this->period());
        $mine = $f->funnelOf([$this->agent->id], $fig);
        $reach = collect($mine['stations'])->pluck('reached', 'key');

        $this->assertSame(2, $reach['loaded'], 'the one that was loaded, and the one that came onto the belt at Claimed (it was on Stock loaded too)');
        $this->assertSame(2, $reach['claimed'], 'one skipped through it, one was placed there');
        $this->assertSame(1, $reach['owner'], 'skipped through');
        $this->assertSame(1, $reach['contacted']);
        $this->assertSame(1, $reach['booked']);
        $this->assertSame(0, $reach['held']);
        $this->assertSame(50.0, collect($mine['stations'])->firstWhere('key', 'claimed')['to_next_pct'], '1 of the 2 that reached Claimed reached Owner found');
    }

    public function test_the_period_bounds_the_figures_and_two_periods_compare(): void
    {
        Carbon::setTestNow('2026-09-10 09:00:00');
        $this->at($this->agent);
        Carbon::setTestNow('2026-10-10 09:00:00');
        $this->at($this->agent);
        $this->at($this->agent);

        $f = app(BeltFunnelService::class);
        $oct = $f->funnelOf([$this->agent->id], $f->figures($this->agency->id, $this->period('2026-10-01', '2026-10-31')));
        $sep = $f->funnelOf([$this->agent->id], $f->figures($this->agency->id, $this->period('2026-09-01', '2026-09-30')));

        $this->assertSame(2, collect($oct['stations'])->firstWhere('key', 'loaded')['reached']);
        $this->assertSame(1, collect($sep['stations'])->firstWhere('key', 'loaded')['reached']);

        $data = $f->forViewer($this->agent, $this->period('2026-10-01', '2026-10-31'), $this->period('2026-09-01', '2026-09-30'));
        $this->assertSame(2, collect($data['me']['stations'])->firstWhere('key', 'loaded')['reached']);
        $this->assertSame(1, collect($data['compare_me']['stations'])->firstWhere('key', 'loaded')['reached']);
    }

    public function test_off_ramps_are_counted_at_their_station_with_the_agencys_wording_and_not_now_is_not_a_loss(): void
    {
        $a = $this->at($this->agent, S::CONTACTED);
        $b = $this->at($this->agent, S::CONTACTED);
        $c = $this->at($this->agent, S::CONTACTED);
        $this->prospects->park($a, 'not_interested', null, null, $this->agent);
        $this->prospects->park($b, 'not_interested', null, null, $this->agent);
        $this->prospects->park($c, 'not_now', null, null, $this->agent);

        $f = app(BeltFunnelService::class);
        $row = collect($f->funnelOf([$this->agent->id], $f->figures($this->agency->id, $this->period()))['stations'])->firstWhere('key', 'contacted');

        $this->assertSame(2, $row['reasons']['Not interested']);
        $this->assertSame(1, $row['reasons']['Not now'], 'shown');
        $this->assertSame(2, $row['lost'], 'but a follow-up is not a loss');
        $this->assertSame(66.7, $row['lost_pct'], '2 lost of 3 that reached Contacted');
    }

    public function test_an_agent_sees_own_branch_and_agency_but_never_another_agents_rows_and_a_manager_sees_the_branch(): void
    {
        $other = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Other branch']);
        $far = $this->makeAgent($this->agency, $other);
        $this->at($this->agent);
        $this->at($this->mate);
        $this->at($far);
        $manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true]);
        foreach (['agent' => 'own', 'branch_manager' => 'branch'] as $role => $scope) {
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id], ['scope' => $scope]);
        }
        \App\Services\PermissionService::clearCache();
        $f = app(BeltFunnelService::class);
        $loaded = fn (array $x) => collect($x['stations'])->firstWhere('key', 'loaded')['reached'];

        $mine = $f->forViewer($this->agent, $this->period());
        $this->assertSame(1, $loaded($mine['me']));
        $this->assertSame(2, $loaded($mine['branch']), 'the branch: me and my colleague (figures only)');
        $this->assertSame(3, $loaded($mine['agency']));
        $this->assertSame([], $mine['agents'], "an agent never gets another agent's row");

        $mgr = $f->forViewer($manager, $this->period());
        $names = collect($mgr['agents'])->pluck('id')->all();
        $this->assertContains($this->agent->id, $names);
        $this->assertContains($this->mate->id, $names);
        $this->assertNotContains($far->id, $names, "a branch manager sees their branch's agents, not another branch's");
    }

    public function test_another_agencys_work_is_never_in_the_figures(): void
    {
        $b = \App\Models\Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'o-' . uniqid()]);
        $branchB = Branch::create(['agency_id' => $b->id, 'name' => 'B']);
        $agentB = $this->makeAgent($b, $branchB);
        $this->prospects->place($agentB, (int) $this->tp(['street_name' => 'B Road'], $b)->id, 'sales', ['stage' => S::FOUND]);
        $this->at($this->agent);

        $f = app(BeltFunnelService::class);
        $a = collect($f->funnelOf([$this->agent->id, $agentB->id], $f->figures($this->agency->id, $this->period()))['stations'])->firstWhere('key', 'loaded');

        $this->assertSame(1, $a['reached']);
    }

    public function test_the_funnel_page_renders_for_an_agent_with_a_comparison(): void
    {
        $this->at($this->agent);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id], ['scope' => 'own']);
        \App\Services\PermissionService::clearCache();

        $res = $this->actingAs($this->agent)->get(route('road-to-sold.belt-funnel', ['period' => 'this_month', 'compare' => 'previous']));

        $res->assertOk()->assertSee('data-qa="belt-funnel-table"', false)->assertSee("Today's work")->assertSee('data-qa="belt-offramps"', false);
        $res->assertDontSee('data-qa="belt-agents"', false);
        $this->actingAs($this->agent)->get(route('road-to-sold.belt-funnel', ['period' => 'custom', 'start' => '2026-10-31', 'end' => '2026-10-01']))->assertOk()->assertSee('Showing the last 30 days instead');
    }

    // ── the training flag ────────────────────────────────────────────────

    /** Five agents' worth of work at Contacted: the agent loses $lostA of $n, each mate loses 1 of $n. */
    private function contactedWork(int $n, int $lostA, ?User $agent = null): void
    {
        $agent ??= $this->agent;
        foreach ([[$agent, $lostA, 'not_interested'], [$this->mate, 1, 'not_interested']] as [$who, $lost, $reason]) {
            for ($i = 0; $i < $n; $i++) {
                $p = $this->at($who, S::CONTACTED);
                if ($i < $lost) {
                    $this->prospects->park($p, $reason, null, null, $who);
                }
            }
        }
    }

    public function test_an_agent_who_loses_far_more_at_a_station_than_their_branch_is_flagged_with_the_station_and_the_top_reason(): void
    {
        $this->settings(['flag_min_sample' => 5]);
        $this->contactedWork(10, 5);   // agent 50%, mate 10%

        $f = app(BeltFunnelService::class);
        $flags = $f->flags($this->agency->id, $f->figures($this->agency->id, $this->period()), [$this->agent->id, $this->mate->id], SettingsService::forAgency($this->agency->id));

        $flag = $flags[$this->agent->id]['offramp_contacted'] ?? null;
        $this->assertNotNull($flag);
        $this->assertSame(50.0, $flag['pct']);
        $this->assertSame(10.0, $flag['median']);
        $this->assertSame('branch rate', $flag['compare_label']);
        $this->assertSame('Not interested', $flag['top_reason']);
        $this->assertStringContainsString('loses 50% of properties at Contacted (branch 10%)', $flag['text']);
        $this->assertStringContainsString('mostly: Not interested', $flag['text']);
        $this->assertArrayNotHasKey($this->mate->id, $flags, 'the colleague is not flagged');
        $this->assertSame($flag['text'], FunnelService::flagText('offramp_contacted', $flag));
    }

    public function test_no_flag_below_the_sample_or_below_the_threshold_and_the_threshold_is_the_agencys_setting(): void
    {
        $this->settings(['flag_min_sample' => 20]);
        $this->contactedWork(10, 5);
        $f = app(BeltFunnelService::class);
        $run = fn () => $f->flags($this->agency->id, $f->figures($this->agency->id, $this->period()), [$this->agent->id, $this->mate->id], SettingsService::forAgency($this->agency->id));

        $this->assertSame([], $run(), 'ten properties through the station is below the sample of twenty: never a flag');

        $this->settings(['flag_min_sample' => 5, 'flag_offramp_excess_pct' => 500]);
        $this->assertSame([], $run(), '50% is not 500% above 10%');

        $this->settings(['flag_offramp_excess_pct' => 25]);
        $this->assertArrayHasKey('offramp_contacted', $run()[$this->agent->id]);
        $this->assertSame(25, SettingsService::forAgency($this->agency->id)->flagOffRampExcessPct());
    }

    public function test_when_the_branch_is_too_small_the_agency_is_the_yardstick(): void
    {
        $this->settings(['flag_min_sample' => 5]);
        $other = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Other branch']);
        $far = $this->makeAgent($this->agency, $other);
        for ($i = 0; $i < 10; $i++) {   // the agent works alone in their branch
            $p = $this->at($this->agent, S::CONTACTED);
            if ($i < 6) {
                $this->prospects->park($p, 'not_interested', null, null, $this->agent);
            }
        }
        for ($i = 0; $i < 10; $i++) {
            $p = $this->at($far, S::CONTACTED);
            if ($i < 1) {
                $this->prospects->park($p, 'not_interested', null, null, $far);
            }
        }
        $this->agent->update(['branch_id' => $this->branch->id]);
        $this->mate->update(['branch_id' => $this->branch->id]);   // the mate has no work: the branch has nothing to compare against

        $f = app(BeltFunnelService::class);
        $flags = $f->flags($this->agency->id, $f->figures($this->agency->id, $this->period()), [$this->agent->id, $this->mate->id, $far->id], SettingsService::forAgency($this->agency->id));

        $this->assertSame('agency rate', $flags[$this->agent->id]['offramp_contacted']['compare_label']);
    }

    public function test_an_off_ramp_flag_goes_through_the_same_manager_actions_and_the_snapshot(): void
    {
        $this->settings(['flag_min_sample' => 5]);
        $this->contactedWork(10, 5);
        $manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true]);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);   // the funnel counts agents who may use Road to Sold
        RolePermission::updateOrCreate(['role' => 'branch_manager', 'permission_key' => 'road_to_sold.view_funnel', 'agency_id' => $this->agency->id]);
        RolePermission::updateOrCreate(['role' => 'branch_manager', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
        RolePermission::updateOrCreate(['role' => 'branch_manager', 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id], ['scope' => 'branch']);
        \App\Services\PermissionService::clearCache();

        $snap = app(FunnelService::class)->snapshot($this->agency->id, $this->period(), 'sales', SettingsService::forAgency($this->agency->id));
        $this->assertArrayHasKey('offramp_contacted', $snap['flags'][$this->agent->id] ?? []);

        $a = app(ManagerActionService::class)->coachingNote($manager, $this->agent, 'offramp_contacted', 'Listen for the reason on the first call');
        $this->assertSame('offramp_contacted', $a->subject_key);
        $this->assertNotNull(app(ManagerActionService::class)->acknowledge($manager, $this->agent, 'offramp_contacted'));
        $this->assertNotNull(FunnelService::meta('offramp_contacted'));
        $this->assertNull(FunnelService::meta('made_up'));
    }

    // ── derived blockers ─────────────────────────────────────────────────

    private function stuck(string $stage, array $property, int $daysAgo = 20): array
    {
        $tp = $this->tp(['street_name' => 'Stuck Street', 'street_number' => (string) random_int(1, 900)]);
        $prop = Property::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id, 'status' => 'draft',
            'title' => '1 Stuck Street', 'listing_type' => 'sale', 'property_type' => 'house', 'address' => '1 Stuck Street', 'suburb' => 'Margate'] + $property);
        $p = $this->prospects->place($this->agent, (int) $tp->id, 'sales', ['property_id' => $prop->id, 'stage' => $stage]);
        DB::table('rts_prospects')->where('id', $p->id)->update(['stage_entered_at' => now()->subDays($daysAgo)]);

        return [$p->refresh(), $prop];
    }

    public function test_a_stalled_draft_names_what_is_missing_first_and_a_fresh_one_names_nothing(): void
    {
        [$stalled] = $this->stuck(S::DRAFT, []);
        [$fresh] = $this->stuck(S::DRAFT, [], 2);

        $b = app(BlockerService::class)->firstBlocker($stalled);

        $this->assertNotNull($b);
        $this->assertSame('draft', $b['station']);
        $this->assertContains($b['key'], ['blk_mandate', 'blk_compliance', 'blk_photos'], 'one of the three draft blockers, the first that fails');
        $this->assertNull(app(BlockerService::class)->firstBlocker($fresh), 'not stuck yet (prospect_stall_days)');
    }

    public function test_an_active_property_that_is_not_published_or_was_turned_down_names_that(): void
    {
        [$notPublished] = $this->stuck(S::ACTIVE, ['published_at' => null]);
        [$rejected] = $this->stuck(S::ACTIVE, ['published_at' => now(), 'p24_syndication_status' => 'error']);
        [$fine] = $this->stuck(S::ACTIVE, ['published_at' => now()]);

        $svc = app(BlockerService::class);
        $this->assertSame('blk_not_published', $svc->firstBlocker($notPublished)['key']);
        $this->assertSame('blk_portal_rejected', $svc->firstBlocker($rejected)['key']);
        $this->assertNull($svc->firstBlocker($fine), 'nothing fails: nothing is named');
    }

    public function test_blockers_are_counted_in_the_stats_as_they_stand_and_never_park_the_record(): void
    {
        [$p] = $this->stuck(S::ACTIVE, ['published_at' => null]);

        $counts = app(BeltFunnelService::class)->blockerCounts($this->agent);

        $this->assertSame(1, $counts['active']['Not published'] ?? 0);
        $this->assertNull($p->fresh()->parked_at);
    }
}
