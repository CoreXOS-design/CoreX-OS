<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RoadToSold\RtsSetting;
use App\Models\User;
use App\Services\RoadToSold\ClaimBeltService;
use App\Services\RoadToSold\ClaimHoldService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\WorkdayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v4, belt step A1 (road-to-sold.md 23.5): a MIC claim IS station 2 of the prospect; the claim hold in working days replaces the
 * 48-hour rule; a daily command releases what has run out; one record per property file; the one-time adoption is journalled and reversible.
 */
final class ClaimBeltTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const FRI = '2026-10-30';   // a Friday: the weekend after it is two days that do not count

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::FRI . ' 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A claim by $agent on a fresh advert of a fresh property file. @return array{0:ProspectingClaim,1:int,2:int} claim, listing id, tracked property id */
    private function claimFor(User $agent, ?int $trackedId = null, array $attrs = []): array
    {
        $tp = $trackedId ? \App\Models\Prospecting\TrackedProperty::withoutGlobalScopes()->find($trackedId) : $this->tp(['street_name' => 'Bairn Street', 'street_number' => (string) random_int(1, 999)]);
        $listingId = $this->listing([], $tp);
        $claim = ProspectingClaim::create(array_merge([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $listingId, 'user_id' => $agent->id,
            'status' => ProspectingClaim::STATUS_CLAIMED, 'claimed_at' => now(), 'last_updated_at' => now(),
        ], $attrs));

        return [$claim->refresh(), $listingId, (int) $tp->id];
    }

    private function prospectOf(int $trackedId): ?RtsProspect
    {
        return app(ProspectService::class)->find($this->agency->id, $trackedId, 'sales');
    }

    public function test_a_claim_puts_the_property_on_the_belt_at_claimed_with_a_hold(): void
    {
        [$claim, , $tp] = $this->claimFor($this->agent);

        $p = $this->prospectOf($tp);
        $this->assertNotNull($p);
        $this->assertSame(ProspectStages::CLAIMED, $p->stage);
        $this->assertSame($this->agent->id, (int) $p->user_id);
        $this->assertSame($p->id, (int) $claim->rts_prospect_id);
        // 30 working days from Friday 30 Oct 2026 (weekends skipped; no holiday data in the test DB)
        $this->assertSame(
            app(WorkdayService::class)->addWorkdays(now(), 30, 'ZA', $this->agent)->toDateString(),
            $claim->hold_until->toDateString()
        );
        $this->assertTrue($claim->hold_until->gt(now()->addDays(30)), 'working days, not calendar days');
    }

    public function test_the_hold_counts_working_days_and_skips_weekends_and_paused_days(): void
    {
        $w = app(WorkdayService::class);
        $this->assertSame('2026-11-02', $w->addWorkdays('2026-10-30', 1, 'ZA')->toDateString(), 'Friday + 1 working day = Monday');
        $this->assertSame('2026-11-06', $w->addWorkdays('2026-10-30', 5, 'ZA')->toDateString());
        $this->assertSame(5, $w->workdaysBetween('2026-10-30', '2026-11-06', 'ZA'));
        $this->assertSame(0, $w->workdaysBetween('2026-11-06', '2026-10-30', 'ZA'));
    }

    public function test_the_hold_length_is_the_agencys_own_setting(): void
    {
        RtsSetting::query()->withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], ['claim_hold_days' => 10]);
        SettingsService::forget();

        [$claim] = $this->claimFor($this->agent);

        $this->assertSame('2026-11-13', $claim->hold_until->toDateString());
    }

    public function test_a_claim_on_a_property_already_on_your_list_moves_that_record_forward(): void
    {
        $tp = $this->tp(['street_name' => 'Kerk Street', 'street_number' => '4']);
        $listed = app(ProspectService::class)->place($this->agent, (int) $tp->id, 'sales', ['selection' => 'area', 'reason' => 'From your list']);
        $this->assertSame(ProspectStages::FOUND, $listed->stage);

        [$claim] = $this->claimFor($this->agent, (int) $tp->id);

        $p = $this->prospectOf((int) $tp->id);
        $this->assertSame($listed->id, $p->id, 'one record, not two');
        $this->assertSame(ProspectStages::CLAIMED, $p->stage);
        $this->assertSame($p->id, (int) $claim->rts_prospect_id);
    }

    public function test_another_agent_is_refused_on_any_advert_of_a_held_file(): void
    {
        [, , $tp] = $this->claimFor($this->agent);
        $other = $this->mate;

        $holder = app(ClaimBeltService::class)->holderOfFile($this->agency->id, $tp, (int) $other->id, (int) $other->ownershipUserId());
        $this->assertSame($this->agent->id, $holder);
        $this->assertNull(app(ClaimBeltService::class)->holderOfFile($this->agency->id, $tp, (int) $this->agent->id), 'your own file is not held against you');

        // and through the real claim door: a second advert of the same file
        $second = $this->listing([], \App\Models\Prospecting\TrackedProperty::withoutGlobalScopes()->find($tp));
        $this->actingAs($other)->post(route('market-intelligence.claim', $second))->assertSessionHas('error');
        $this->assertSame(0, ProspectingClaim::withoutGlobalScopes()->where('prospecting_listing_id', $second)->count(), 'no second claim was written');
        $this->assertSame($this->agent->id, (int) $this->prospectOf($tp)->user_id);
    }

    public function test_another_agencys_file_is_never_seen(): void
    {
        $b = \App\Models\Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'o-' . uniqid()]);
        $branchB = \App\Models\Branch::create(['agency_id' => $b->id, 'name' => 'B']);
        $agentB = $this->makeAgent($b, $branchB);
        [, , $tp] = $this->claimFor($this->agent);

        $this->assertNull(app(ClaimBeltService::class)->holderOfFile((int) $b->id, $tp, (int) $agentB->id));
    }

    public function test_working_the_prospect_restarts_the_hold_and_mirrors_the_claim_status(): void
    {
        [$claim, , $tp] = $this->claimFor($this->agent);
        $first = $claim->hold_until->copy();

        Carbon::setTestNow('2026-11-09 09:00:00');   // a week and a half later
        $p = $this->prospectOf($tp);
        app(ProspectService::class)->advance($p, ProspectStages::APPOINTMENT_SET, $this->agent, ['event_class' => 'test']);

        $claim->refresh();
        $this->assertTrue($claim->hold_until->gt($first), 'the count started again from the day of the work');
        $this->assertSame(ProspectingClaim::STATUS_MEETING_SET, $claim->status, 'the claim follows the station');
    }

    public function test_the_daily_command_warns_then_releases_and_sends_the_property_back_to_the_pool(): void
    {
        [$claim, , $tp] = $this->claimFor($this->agent);

        // 3 working days left: inside the 5-day warning
        $claim->forceFill(['hold_until' => app(WorkdayService::class)->addWorkdays(now(), 3, 'ZA')->toDateString()])->saveQuietly();
        $this->artisan('rts:release-held-claims')->assertSuccessful();
        $claim->refresh();
        $this->assertNotNull($claim->warned_at, 'the agent was told once');
        $this->assertTrue($claim->is_active);

        // the hold ran out yesterday
        $claim->forceFill(['hold_until' => now()->subDay()->toDateString()])->saveQuietly();
        $this->artisan('rts:release-held-claims')->assertSuccessful();

        $claim->refresh();
        $this->assertFalse((bool) $claim->is_active);
        $this->assertNotNull($claim->released_at);
        $this->assertSame('hold_ended', $claim->release_reason);
        $p = RtsProspect::withoutGlobalScopes()->find($claim->rts_prospect_id);
        $this->assertNotNull($p->released_at, 'the belt record went back to the pool');
        $this->assertNull(app(ProspectService::class)->ownerOf($this->agency->id, $tp), 'the property is free again');
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        [$claim] = $this->claimFor($this->agent);
        $claim->forceFill(['hold_until' => now()->subDay()->toDateString()])->saveQuietly();

        $this->artisan('rts:release-held-claims --dry-run')->assertSuccessful();

        $this->assertTrue((bool) $claim->fresh()->is_active);
    }

    public function test_a_parked_property_is_not_on_a_clock_and_a_won_one_is_not_chased(): void
    {
        [$parkedClaim, , $tpA] = $this->claimFor($this->agent);
        [$wonClaim, , $tpB] = $this->claimFor($this->agent);
        foreach ([$parkedClaim, $wonClaim] as $c) {
            $c->forceFill(['hold_until' => now()->subDays(3)->toDateString()])->saveQuietly();
        }
        $svc = app(ProspectService::class);
        $svc->park($this->prospectOf($tpA), 'other', now()->addDays(60), 'Not now', $this->agent);
        $svc->advance($this->prospectOf($tpB), ProspectStages::MANDATE_SIGNED, $this->agent, ['event_class' => 'test']);
        foreach ([$parkedClaim, $wonClaim] as $c) {
            $c->forceFill(['hold_until' => now()->subDays(3)->toDateString()])->saveQuietly();
        }

        $this->artisan('rts:release-held-claims')->assertSuccessful();

        $this->assertTrue((bool) $parkedClaim->fresh()->is_active);
        $this->assertTrue((bool) $wonClaim->fresh()->is_active);
    }

    public function test_one_extension_for_the_agent_then_refused_and_a_manager_may_grant_more(): void
    {
        [$claim] = $this->claimFor($this->agent);
        $hold = app(ClaimHoldService::class);
        $was = $claim->hold_until->copy();

        $this->assertTrue($hold->extend($claim, $this->agent)['ok']);
        $claim->refresh();
        $this->assertTrue($claim->hold_until->gt($was));
        $this->assertSame(1, (int) $claim->hold_extensions);
        $this->assertNotNull($claim->hold_extended_at);

        $second = $hold->extend($claim, $this->agent);
        $this->assertFalse($second['ok']);
        $this->assertStringContainsString('already been extended', $second['message']);

        $manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true]);
        $this->assertTrue($hold->extend($claim->refresh(), $manager, true)['ok']);
        $this->assertSame(2, (int) $claim->fresh()->hold_extensions);
    }

    public function test_the_number_of_extensions_is_the_agencys_setting(): void
    {
        RtsSetting::query()->withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], ['claim_extensions_allowed' => 0]);
        SettingsService::forget();
        [$claim] = $this->claimFor($this->agent);

        $this->assertFalse(app(ClaimHoldService::class)->extend($claim, $this->agent)['ok']);
    }

    public function test_a_pitched_claim_is_no_longer_permanent(): void
    {
        [$claim] = $this->claimFor($this->agent, null, ['pitched_at' => now()->subDays(60)]);
        $this->assertFalse($claim->isExpired(), 'inside its hold');

        $claim->forceFill(['hold_until' => now()->subDay()->toDateString()])->saveQuietly();
        $this->assertTrue($claim->fresh()->isExpired(), 'a pitched claim runs out like any other');
    }

    public function test_a_claim_with_no_hold_keeps_the_old_48_hour_rule(): void
    {
        $tp = $this->tp(['street_name' => null]);
        $listingId = $this->listing([], $tp);
        $claim = ProspectingClaim::withoutEvents(fn () => ProspectingClaim::create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $listingId, 'user_id' => $this->agent->id,
            'status' => 'claimed', 'claimed_at' => now()->subHours(50), 'last_updated_at' => now()->subHours(50),
        ]))->refresh();

        $this->assertNull($claim->hold_until);
        $this->assertTrue($claim->isExpired());
    }

    public function test_a_claim_on_a_property_with_no_file_stays_a_plain_claim(): void
    {
        $listingId = $this->listing([], null);
        $claim = ProspectingClaim::create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $listingId, 'user_id' => $this->agent->id,
            'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now(),
        ])->refresh();

        $this->assertNull($claim->rts_prospect_id);
        $this->assertNull($claim->hold_until);
    }

    public function test_giving_the_claim_up_gives_the_belt_record_up(): void
    {
        [$claim, , $tp] = $this->claimFor($this->agent);

        app(\App\Services\Prospecting\ProspectingClaimService::class)->releaseClaim((int) $claim->id, (int) $this->agent->id, 'not for me');

        $this->assertNotNull(RtsProspect::withoutGlobalScopes()->find($claim->rts_prospect_id)->released_at);
        $this->assertNull(app(ProspectService::class)->ownerOf($this->agency->id, $tp));
    }

    public function test_a_claim_brought_onto_the_belt_late_carries_its_own_date_into_the_entry_date_and_the_hold(): void
    {
        $taken = now()->subDays(12);   // Sunday 18 Oct: still inside the hold
        $tp = $this->tp(['street_name' => 'Bairn Street', 'street_number' => (string) random_int(1, 999)]);
        $claim = ProspectingClaim::withoutEvents(fn () => ProspectingClaim::create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $this->listing([], $tp), 'user_id' => $this->agent->id,
            'status' => 'claimed', 'claimed_at' => $taken, 'last_updated_at' => $taken,
        ]))->refresh();

        $p = app(ClaimBeltService::class)->placeClaim($claim);

        $this->assertNotNull($p);
        $this->assertSame($taken->toDateString(), $p->fresh()->stage_entered_at->toDateString(), 'Since is the day the claim was taken');
        $this->assertSame(
            app(WorkdayService::class)->addWorkdays($taken, 30, 'ZA', $this->agent)->toDateString(),
            $claim->fresh()->hold_until->toDateString(),
            '30 working days from the claim date, not from the day it reached the belt'
        );
    }

    public function test_a_claim_taken_today_is_unchanged_and_a_claim_whose_hold_has_run_out_gets_the_whole_hold_from_today(): void
    {
        $oldTp = $this->tp(['street_name' => 'Bairn Street', 'street_number' => (string) random_int(1, 999)]);
        $old = ProspectingClaim::withoutEvents(fn () => ProspectingClaim::create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $this->listing([], $oldTp), 'user_id' => $this->agent->id,
            'status' => 'claimed', 'claimed_at' => now()->subDays(200), 'last_updated_at' => now()->subDays(200),
        ]))->refresh();
        $p = app(ClaimBeltService::class)->placeClaim($old);

        $this->assertSame(now()->subDays(200)->toDateString(), $p->fresh()->stage_entered_at->toDateString(), 'the real date still shows as Since');
        $this->assertSame(app(WorkdayService::class)->addWorkdays(now(), 30, 'ZA', $this->agent)->toDateString(), $old->fresh()->hold_until->toDateString(), 'an ended hold is not released: it gets the whole hold from today');

        [$new] = $this->claimFor($this->agent);
        $this->assertSame(now()->toDateString(), $this->prospectOf((int) app(ClaimBeltService::class)->trackedPropertyOf($new))->stage_entered_at->toDateString());
        $this->assertSame(app(WorkdayService::class)->addWorkdays(now(), 30, 'ZA', $this->agent)->toDateString(), $new->fresh()->hold_until->toDateString());
    }

    public function test_adoption_dry_run_writes_nothing_then_apply_is_journalled_and_undo_puts_it_back(): void
    {
        // three claims written before v4: no belt record, no hold - one contacted, one a meeting, one with no property file
        $rows = [];
        foreach (['claimed', 'contacted', 'meeting_set'] as $status) {
            $tp = $this->tp(['street_name' => 'Old Street', 'street_number' => (string) random_int(1, 999)]);
            $rows[$status] = ProspectingClaim::withoutEvents(fn () => ProspectingClaim::create([
                'agency_id' => $this->agency->id, 'prospecting_listing_id' => $this->listing([], $tp), 'user_id' => $this->agent->id,
                'status' => $status, 'claimed_at' => now()->subDays(80), 'last_updated_at' => now()->subDays(80), 'pitched_at' => $status === 'contacted' ? now()->subDays(70) : null,
            ]));
        }
        $noFile = ProspectingClaim::withoutEvents(fn () => ProspectingClaim::create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $this->listing([], null), 'user_id' => $this->agent->id,
            'status' => 'claimed', 'claimed_at' => now()->subDays(5), 'last_updated_at' => now()->subDays(5),
        ]));
        $dir = sys_get_temp_dir() . '/rts-adopt-' . uniqid();

        $this->artisan("rts:adopt-claims --agency={$this->agency->id} --journal-dir={$dir}")->assertSuccessful();
        $this->assertSame(0, ProspectingClaim::withoutGlobalScopes()->where('agency_id', $this->agency->id)->whereNotNull('rts_prospect_id')->count(), 'a dry run writes nothing');

        $this->artisan("rts:adopt-claims --agency={$this->agency->id} --apply --journal-dir={$dir}")->assertSuccessful();

        $this->assertNull($noFile->fresh()->rts_prospect_id, 'no property file, no belt record');
        foreach ($rows as $status => $claim) {
            $c = $claim->fresh();
            $this->assertNotNull($c->rts_prospect_id, "$status claim adopted");
            // the clock starts at the adoption date, not at the 80-day-old claim date: nothing is released on the first run
            $this->assertTrue($c->hold_until->gte(now()->addDays(30)), "$status claim holds from today");
        }
        $this->assertSame(ProspectStages::CLAIMED, RtsProspect::withoutGlobalScopes()->find($rows['claimed']->fresh()->rts_prospect_id)->stage);
        $this->assertSame(ProspectStages::CLAIMED, RtsProspect::withoutGlobalScopes()->find($rows['contacted']->fresh()->rts_prospect_id)->stage, 'contacted only counts with an outcome on record');
        $this->assertSame(ProspectStages::APPOINTMENT_SET, RtsProspect::withoutGlobalScopes()->find($rows['meeting_set']->fresh()->rts_prospect_id)->stage);
        $this->artisan('rts:release-held-claims')->assertSuccessful();
        $this->assertSame(3, ProspectingClaim::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('is_active', 1)->whereNotNull('rts_prospect_id')->count(), 'none released by the first daily run');

        $journal = glob($dir . '/rts-adopt-claims-agency' . $this->agency->id . '-*.jsonl');
        $this->assertCount(1, $journal);
        $this->assertSame(3, count(file($journal[0])));

        $this->artisan("rts:adopt-claims --undo={$journal[0]}")->assertSuccessful();

        foreach ($rows as $claim) {
            $c = $claim->fresh();
            $this->assertNull($c->rts_prospect_id);
            $this->assertNull($c->hold_until);
        }
        $this->assertSame(0, RtsProspect::query()->where('agency_id', $this->agency->id)->count(), 'prospects the run made are archived, not left open');
        $this->assertSame(3, RtsProspect::withoutGlobalScopes()->withTrashed()->where('agency_id', $this->agency->id)->count(), 'and nothing was hard-deleted');
    }

    public function test_adoption_leaves_another_agents_property_alone(): void
    {
        $tp = $this->tp(['street_name' => 'Shared Street', 'street_number' => '9']);
        app(ProspectService::class)->place($this->mate, (int) $tp->id, 'sales', ['selection' => 'area']);
        $claim = ProspectingClaim::withoutEvents(fn () => ProspectingClaim::create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $this->listing([], $tp), 'user_id' => $this->agent->id,
            'status' => 'claimed', 'claimed_at' => now()->subDays(3), 'last_updated_at' => now()->subDays(3),
        ]));

        $this->artisan("rts:adopt-claims --agency={$this->agency->id} --apply --journal-dir=" . sys_get_temp_dir() . '/rts-adopt-' . uniqid())->assertSuccessful();

        $this->assertNull($claim->fresh()->rts_prospect_id);
        $this->assertSame($this->mate->id, (int) $this->prospectOf((int) $tp->id)->user_id);
    }
}
