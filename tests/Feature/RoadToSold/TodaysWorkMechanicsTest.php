<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RoadToSold\RtsSetting;
use App\Models\User;
use App\Services\RoadToSold\ClaimBeltService;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\LoadedRowClaimService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold 23.30.3 (3 Nov 2026): "Today's work" mechanics. Loading a property onto an agent's day does not claim it (it stays in the pool, no hold); the first
 * action - Find owner - is the claim; a swap is an off-ramp; the midnight job releases what was loaded and never started (or carries it over, per the agency
 * setting); one record is in one stage at all times.
 */
final class TodaysWorkMechanicsTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const MON = '2026-11-02';   // a Monday
    private const TUE = '2026-11-03';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::MON . ' 09:00:00');
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

    /** A property file with one advert, loaded on $agent's day as the engine loads it (record at Loaded, a list row, NO claim). @return array{0:RtsListItem,1:RtsProspect,2:int,3:int} row, record, listing id, tracked id */
    private function loaded(User $agent, string $day = self::MON, string $origin = RtsListItem::ORIGIN_AUTO): array
    {
        $tp = $this->tp(['street_name' => 'Bairn Street', 'street_number' => (string) random_int(1, 9999)]);
        $listingId = $this->listing([], $tp);
        $p = app(ProspectService::class)->place($agent, (int) $tp->id, 'sales', ['prospecting_listing_id' => $listingId, 'selection' => 'agency_buyers_sourcing', 'reason' => 'A buyer wants this', 'stage' => ProspectStages::FOUND]);
        $row = RtsListItem::withoutAgencyStamping(fn () => RtsListItem::create([
            'agency_id' => $this->agency->id, 'branch_id' => $agent->branch_id, 'user_id' => $agent->id, 'list_date' => $day, 'track' => 'sales',
            'tracked_property_id' => $tp->id, 'prospecting_listing_id' => $listingId, 'selection' => 'agency_buyers_sourcing', 'origin' => $origin,
            'position' => (int) RtsListItem::query()->where('user_id', $agent->id)->where('list_date', $day)->max('position') + 1,
            'status' => RtsListItem::STATUS_OPEN, 'reason' => 'A buyer wants this', 'prospect_id' => $p->id,
        ]));

        return [$row, $p, $listingId, (int) $tp->id];
    }

    private function activeClaims(int $listingId)
    {
        return ProspectingClaim::query()->withoutGlobalScopes()->where('prospecting_listing_id', $listingId)->where('is_active', true)->whereNull('released_at');
    }

    public function test_a_loaded_row_holds_nothing_another_agent_can_claim_it_in_market_intelligence(): void
    {
        $other = $this->makeAgent();
        [$row, $p, $listingId, $tracked] = $this->loaded($this->agent);

        $this->assertSame(ProspectStages::FOUND, $p->stage);
        $this->assertSame(0, $this->activeClaims($listingId)->count(), 'loading wrote no claim');
        $this->assertNull(app(ClaimBeltService::class)->holderOfFile($this->agency->id, $tracked, (int) $other->id), 'a loaded property is not held');

        // the other agent hand-claims it in Market Intelligence: it is theirs, and it leaves the first agent's day without counting as missed
        $claim = ProspectingClaim::create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $listingId, 'user_id' => $other->id,
            'status' => ProspectingClaim::STATUS_CLAIMED, 'claimed_at' => now(), 'last_updated_at' => now(),
        ]);

        $mine = app(ProspectService::class)->find($this->agency->id, $tracked, 'sales');
        $this->assertSame((int) $other->id, (int) $mine->user_id);
        $this->assertSame(ProspectStages::CLAIMED, $mine->stage);
        $this->assertSame($mine->id, (int) $claim->fresh()->rts_prospect_id);
        $this->assertSame(RtsListItem::STATUS_SWAPPED, RtsListItem::query()->withoutGlobalScopes()->find($row->id)->status, 'off the first agent\'s day, not missed');
    }

    public function test_find_owner_claims_the_property_and_moves_it_from_loaded_to_claimed_in_one_step(): void
    {
        [$row, $p, $listingId, $tracked] = $this->loaded($this->agent);

        $r = app(LoadedRowClaimService::class)->claim($this->agent, (int) $row->id);

        $this->assertTrue($r['ok']);
        $this->assertFalse($r['already']);
        $this->assertSame(ProspectStages::CLAIMED, $p->fresh()->stage);
        $claim = $this->activeClaims($listingId)->first();
        $this->assertNotNull($claim, 'one active claim now holds it');
        $this->assertSame((int) $this->agent->id, (int) $claim->user_id);
        $this->assertNotNull($claim->fresh()->hold_until, 'the hold clock started');
        $this->assertSame($p->id, (int) $claim->fresh()->rts_prospect_id);
        $this->assertSame(1, RtsProspect::query()->withoutGlobalScopes()->where('tracked_property_id', $tracked)->count(), 'one record');

        // pressing it again changes nothing
        $again = app(LoadedRowClaimService::class)->claim($this->agent, (int) $row->id);
        $this->assertTrue($again['already']);
        $this->assertSame(1, $this->activeClaims($listingId)->count());
    }

    public function test_find_owner_is_refused_when_somebody_started_on_the_property_first(): void
    {
        $other = $this->makeAgent();
        [$row, , $listingId] = $this->loaded($this->agent);
        // the other agent claimed it (the first agent's loaded record is displaced), then the first agent presses Find owner on a stale tab
        ProspectingClaim::create(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $listingId, 'user_id' => $other->id,
            'status' => ProspectingClaim::STATUS_CLAIMED, 'claimed_at' => now(), 'last_updated_at' => now()]);

        $this->expectException(\InvalidArgumentException::class);
        app(LoadedRowClaimService::class)->claim($this->agent, (int) $row->id);
    }

    public function test_a_swap_takes_the_loaded_row_off_the_day_and_releases_the_property(): void
    {
        [$row, $p] = $this->loaded($this->agent);

        app(ListBuilderService::class)->swap($this->agent, (int) $row->id, 'Not for me: wrong area', 'not_for_me_wrong_area', 'Wrong area');

        $this->assertSame(RtsListItem::STATUS_SWAPPED, RtsListItem::query()->withoutGlobalScopes()->find($row->id)->status);
        $this->assertNotNull($p->fresh()->released_at);
        $this->assertNull(app(ClaimBeltService::class)->holderOfFile($this->agency->id, (int) $p->tracked_property_id, 0));
    }

    public function test_at_midnight_a_loaded_row_never_started_goes_back_to_the_pool_and_counts_as_missed(): void
    {
        [$untouched, $pu] = $this->loaded($this->agent);
        [$claimedRow, $pc, $lc] = $this->loaded($this->agent);
        app(LoadedRowClaimService::class)->claim($this->agent, (int) $claimedRow->id);

        Carbon::setTestNow(self::TUE . ' 00:05:00');
        $r = app(ListBuilderService::class)->closePastDays($this->agent, self::TUE);

        $this->assertSame(2, $r['missed']);
        $this->assertSame(1, $r['released'], 'only the one that was never started');
        $this->assertSame(RtsListItem::STATUS_MISSED, $untouched->fresh()->status);
        $this->assertNotNull($pu->fresh()->released_at, 'back in the pool');
        $this->assertNull($pc->fresh()->released_at, 'a claimed property keeps its agent');
        $this->assertSame(1, $this->activeClaims($lc)->count());
        $this->assertSame(RtsListItem::STATUS_MISSED, $claimedRow->fresh()->status, 'the row still closes as missed');
    }

    public function test_with_carry_over_on_an_untouched_row_moves_to_the_top_of_tomorrow_and_is_not_released(): void
    {
        RtsSetting::query()->withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], ['missed_items_mode' => 'roll_over']);
        SettingsService::forget();
        [$row, $p] = $this->loaded($this->agent);

        Carbon::setTestNow(self::TUE . ' 00:05:00');
        $r = app(ListBuilderService::class)->closePastDays($this->agent, self::TUE);

        $this->assertSame(1, $r['rolled']);
        $this->assertSame(0, $r['released']);
        $this->assertSame(RtsListItem::STATUS_ROLLED, $row->fresh()->status);
        $this->assertNull($p->fresh()->released_at, 'still on the agent\'s desk');
        $carried = RtsListItem::query()->where('user_id', $this->agent->id)->where('list_date', self::TUE)->where('origin', RtsListItem::ORIGIN_ROLLOVER)->first();
        $this->assertNotNull($carried);
        $this->assertSame(1, (int) $carried->position, 'top of the list');
        $this->assertSame(1, RtsListItem::query()->forDay($this->agent, self::TUE, 'sales')->countingTowardTarget()->count(), 'it counts toward the day, so new intake is reduced by one');
    }

    public function test_the_midnight_command_does_the_same_for_every_agent_with_open_rows(): void
    {
        $b = $this->makeAgent();
        [, $pa] = $this->loaded($this->agent);
        [, $pb] = $this->loaded($b);
        Carbon::setTestNow(self::TUE . ' 00:05:00');

        $this->artisan('rts:close-day', ['--dry-run' => true])->assertExitCode(0);
        $this->assertNull($pa->fresh()->released_at, 'a dry run changes nothing');

        $this->artisan('rts:close-day')->assertExitCode(0);
        $this->assertNotNull($pa->fresh()->released_at);
        $this->assertNotNull($pb->fresh()->released_at);
        $this->artisan('rts:close-day')->assertExitCode(0);   // safe to run twice
    }

    public function test_an_added_row_follows_the_same_rules(): void
    {
        [$row, $p] = $this->loaded($this->agent, self::MON, RtsListItem::ORIGIN_ADDED);
        $this->assertSame(0, $this->activeClaims((int) $row->prospecting_listing_id)->count(), 'adding to the day does not claim');

        Carbon::setTestNow(self::TUE . ' 00:05:00');
        app(ListBuilderService::class)->closePastDays($this->agent, self::TUE);

        $this->assertNotNull($p->fresh()->released_at);
    }

    public function test_no_property_is_ever_in_two_stages_at_once(): void
    {
        [$row] = $this->loaded($this->agent);
        $this->loaded($this->makeAgent());
        app(LoadedRowClaimService::class)->claim($this->agent, (int) $row->id);

        $twice = DB::table('rts_prospects')->where('agency_id', $this->agency->id)->whereNull('released_at')->whereNull('closed_at')->whereNull('deleted_at')
            ->groupBy('tracked_property_id', 'track')->havingRaw('count(*) > 1')->pluck('tracked_property_id');
        $this->assertCount(0, $twice);
    }
}
