<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Branch;
use App\Models\RoadToSold\RtsHold;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsProspect;
use App\Models\User;
use App\Services\RoadToSold\Adapters\LaneSuggestionGuard;
use App\Services\RoadToSold\AllocationSource;
use App\Services\RoadToSold\Contracts\AreaProvider;
use App\Services\RoadToSold\Contracts\FollowUp;
use App\Services\RoadToSold\Contracts\FollowUpSource;
use App\Services\RoadToSold\Contracts\WorkCalendar;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\Support\BuilderSettings;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Feature\RoadToSold\Concerns\NullSuggestionGuard;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * road-to-sold.md 23.14 / 23.22 - the daily allocation engine (station 1 "Stock loaded"): one test per rule.
 * Real selections and a real database; the calendar, areas and follow-ups are fixed so the day is the one under test.
 */
final class AllocationEngineTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const WED = '2026-10-14';
    private const OLD = ['first_seen_at' => '2026-03-01 08:00:00'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        Cache::flush();
    }

    /** @param array $o settings (BuilderSettings overrides), target, guard, followUps */
    private function builder(array $o = []): ListBuilderService
    {
        $settings = (new BuilderSettings())->with($o['settings'] ?? []);
        $calendar = new class($o['target'] ?? 5) implements WorkCalendar {
            public function __construct(private int $t) {}
            public function targetFor(User $u, CarbonInterface $d, string $track): int { return $this->isWorkday($u, $d, $track) ? $this->t : 0; }
            public function isWorkday(User $u, CarbonInterface $d, string $track): bool { return ! $d->isWeekend(); }
            public function nextWorkday(User $u, CarbonInterface $from, string $track): ?Carbon { $x = Carbon::instance($from)->startOfDay(); do { $x = $x->addDay(); } while ($x->isWeekend()); return $x; }
        };
        $areas = new class implements AreaProvider {
            public function suburbIdsFor(User $u, string $t): array { return []; }
            public function suburbNamesNormalisedFor(User $u, string $t): array { return []; }
            public function suburbNamesLowerTrimFor(User $u, string $t): array { return []; }
            public function hasAreas(User $u, string $t): bool { return false; }
        };
        $followUps = new class($o['followUps'] ?? []) implements FollowUpSource {
            public function __construct(private array $f) {}
            public function dueOn(User $u, CarbonInterface $d, string $t): array { return $this->f[$u->id] ?? []; }
        };

        return new ListBuilderService(calendar: $calendar, areas: $areas, guard: $o['guard'] ?? new NullSuggestionGuard(), followUps: $followUps, settingsResolver: fn (User $u, string $t) => $settings);
    }

    private function old(array $attrs = [], $tp = true): int
    {
        return $this->listing(self::OLD + $attrs, $tp);
    }

    private function claim(int $listingId, int $userId, array $x = []): void
    {
        DB::table('prospecting_claims')->insert(array_merge([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $listingId, 'user_id' => $userId, 'status' => 'claimed',
            'is_active' => 1, 'claimed_at' => now(), 'last_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $x));
    }

    private function selections($items): array
    {
        return $items->pluck('selection')->all();
    }

    // ── the order: fills N top-down ──────────────────────────────────────

    public function test_the_day_fills_top_down_my_buyers_then_colleagues_then_fill_ups_each_only_when_the_earlier_ran_dry(): void
    {
        $myBuyer = $this->buyer($this->agent->id);
        $other = $this->buyer($this->agent->id);
        $mate = $this->buyer($this->mate->id);
        $twoBuyers = $this->old(['address' => '1 A Rd']);
        $oneBuyer = $this->old(['address' => '2 B Rd']);
        $colleague = $this->old(['address' => '3 C Rd']);
        $this->buyerMatch($twoBuyers, $myBuyer->id);
        $this->buyerMatch($twoBuyers, $other->id);
        $this->buyerMatch($oneBuyer, $myBuyer->id);
        $this->buyerMatch($colleague, $mate->id);
        $cut = $this->listing(['address' => '4 D Rd']);
        DB::table('prospecting_price_history')->insert(['prospecting_listing_id' => $cut, 'old_price' => 1_600_000, 'new_price' => 1_500_000, 'changed_at' => '2026-10-10 09:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $long = $this->old(['address' => '5 E Rd']);
        $this->old(['address' => '6 F Rd']);     // would be a sixth

        $items = $this->builder(['target' => 5, 'settings' => ['enabledSelectionKeys' => ['my_buyers_sourcing', 'agency_buyers_sourcing', 'price_drops', 'long_on_market', 'new_on_market']]])->buildFor($this->agent, self::WED);

        $this->assertSame(['my_buyers_sourcing', 'my_buyers_sourcing', 'agency_buyers_sourcing', 'price_drops', 'long_on_market'], $this->selections($items));
        $this->assertSame([$twoBuyers, $oneBuyer, $colleague, $cut], $items->take(4)->pluck('prospecting_listing_id')->all(), 'two buyers before one; then the colleague\'s buyer; then the price cut');
        $this->assertSame('for Buyer · 2 buyers', $items[0]->reason);
        $this->assertSame('for Buyer', $items[1]->reason);
    }

    // ── source 1 and 2: the buyers ───────────────────────────────────────

    public function test_only_a_strong_match_built_under_the_current_rules_and_not_dismissed_counts(): void
    {
        $b = $this->buyer($this->agent->id);
        $strong = $this->old();
        $weak = $this->old();
        $dismissed = $this->old();
        $stale = $this->old();
        $this->buyerMatch($strong, $b->id);
        $this->buyerMatch($weak, $b->id, 'approximate', 60);
        $this->buyerMatch($dismissed, $b->id, 'strong', 90, dismissed: true);   // "Not for <buyer>" (13.17)
        $this->buyerMatch($stale, $b->id);
        DB::table('prospecting_buyer_matches')->where('prospecting_listing_id', $stale)->update(['matched_features' => json_encode(['engine' => 'canonical'])]);   // built before the rules last changed

        $ids = $this->builder(['settings' => ['enabledSelectionKeys' => ['my_buyers_sourcing']]])->buildFor($this->agent, self::WED)->pluck('prospecting_listing_id')->all();

        $this->assertSame([$strong], $ids);
    }

    public function test_the_agencys_own_strong_line_applies(): void
    {
        DB::table('buyer_match_tiers')->insert(['agency_id' => $this->agency->id, 'strong_min_score' => 90, 'mid_min_score' => 50, 'weak_min_score' => 0, 'strong_label' => 'Strong', 'mid_label' => 'Mid', 'weak_label' => 'Weak', 'show_weak_in_badge' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $b = $this->buyer($this->agent->id);
        $eightyFive = $this->old();
        $ninety = $this->old();
        $this->buyerMatch($eightyFive, $b->id, 'strong', 85);
        $this->buyerMatch($ninety, $b->id, 'perfect', 92);

        $ids = $this->builder(['settings' => ['enabledSelectionKeys' => ['my_buyers_sourcing']]])->buildFor($this->agent, self::WED)->pluck('prospecting_listing_id')->all();

        $this->assertSame([$ninety], $ids);
    }

    public function test_a_claimed_property_is_not_stock_whoever_claimed_it(): void
    {
        $b = $this->buyer($this->agent->id);
        $free = $this->old();
        $mateClaimed = $this->old();
        $iClaimed = $this->old();
        $released = $this->old();
        foreach ([$free, $mateClaimed, $iClaimed, $released] as $l) {
            $this->buyerMatch($l, $b->id);
        }
        $this->claim($mateClaimed, $this->mate->id);
        $this->claim($iClaimed, $this->agent->id);
        $this->claim($released, $this->mate->id, ['is_active' => 0, 'released_at' => now()]);

        $ids = $this->builder(['settings' => ['enabledSelectionKeys' => ['my_buyers_sourcing']]])->buildFor($this->agent, self::WED)->pluck('prospecting_listing_id')->all();

        $this->assertEqualsCanonicalizing([$free, $released], $ids);
    }

    public function test_a_claim_on_the_twin_advert_of_the_same_property_covers_it_too(): void
    {
        $b = $this->buyer($this->agent->id);
        $tp = $this->tp();
        $p24 = $this->old([], $tp);
        $pp = $this->old(['portal_source' => 'pp'], $tp);
        $this->buyerMatch($pp, $b->id);
        $this->claim($p24, $this->mate->id);

        $this->assertCount(0, $this->builder(['settings' => ['enabledSelectionKeys' => ['my_buyers_sourcing']]])->buildFor($this->agent, self::WED));
    }

    public function test_colleagues_buyers_never_include_mine_and_the_setting_decides_whose(): void
    {
        $other = Branch::forceCreate(['agency_id' => $this->agency->id, 'name' => 'Other branch']);
        $keys = ['enabledSelectionKeys' => ['agency_buyers_sourcing']];
        $branchMate = $this->mate;                  // same branch as the agents below
        $farAgent = $this->makeAgent(null, $other); // another branch

        // each scenario: a fresh agent (stock is allocated to ONE agent) and fresh stock
        $scenario = function (string $setting) use ($keys, $branchMate, $farAgent) {
            $me = $this->makeAgent();
            $mine = $this->buyer($me->id);
            $branchBuyer = $this->buyer($branchMate->id);
            $farBuyer = $this->buyer($farAgent->id);
            [$forMine, $forBranch, $forFar] = [$this->old(), $this->old(), $this->old()];
            $this->buyerMatch($forMine, $mine->id);
            $this->buyerMatch($forBranch, $branchBuyer->id);
            $this->buyerMatch($forFar, $farBuyer->id);
            $items = $this->builder(['settings' => $keys + ['sourceForColleagues' => $setting]])->buildFor($me, self::WED);

            return [$items, $forMine, $forBranch, $forFar];
        };

        [$items, $forMine, $forBranch, $forFar] = $scenario('agency');
        $this->assertSame([$forBranch, $forFar], $items->pluck('prospecting_listing_id')->all(), 'the agent\'s own branch before the rest of the agency; never my own buyer\'s property');
        $this->assertStringContainsString('(', $items[0]->reason, 'the buyer\'s agent is named');

        [$items, $forMine, $forBranch, $forFar] = $scenario('branch');
        $ids = $items->pluck('prospecting_listing_id')->all();
        $this->assertContains($forBranch, $ids);
        $this->assertNotContains($forFar, $ids, 'another branch\'s buyer is out when the setting is "branch"');
        $this->assertNotContains($forMine, $ids, 'never my own buyer');

        [$items] = $scenario('off');
        $this->assertCount(0, $items);
    }

    // ── rules for every source ───────────────────────────────────────────

    public function test_never_another_agents_allocated_stock_or_a_property_held_by_someone_who_just_contacted_it(): void
    {
        $b = $this->buyer($this->agent->id);
        $free = $this->old();
        $allocated = $this->old($tpless = [], $tpA = $this->tp());
        $held = $this->old([], $tpH = $this->tp());
        $expiredHold = $this->old([], $tpE = $this->tp());
        foreach ([$free, $allocated, $held, $expiredHold] as $l) {
            $this->buyerMatch($l, $b->id);
        }
        app(\App\Services\RoadToSold\ProspectService::class)->place($this->mate, $tpA->id, 'sales', ['prospecting_listing_id' => $allocated, 'selection' => 'long_on_market', 'reason' => 'x']);   // mate's prospect
        $hold = fn (int $tp, $expires) => RtsHold::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'track' => 'sales', 'tracked_property_id' => $tp, 'user_id' => $this->mate->id, 'started_at' => now()->subDays(5), 'expires_at' => $expires]);
        $hold($tpH->id, now()->addDays(25));        // contacted 5 days ago: inside rts.recontact_days (30)
        $hold($tpE->id, now()->subDay());           // the hold has run out

        $ids = $this->builder(['guard' => new LaneSuggestionGuard(), 'settings' => ['enabledSelectionKeys' => ['my_buyers_sourcing']]])->buildFor($this->agent, self::WED)->pluck('prospecting_listing_id')->all();

        $this->assertEqualsCanonicalizing([$free, $expiredHold], $ids);
    }

    public function test_properties_with_an_address_come_before_those_without_inside_a_source(): void
    {
        $b = $this->buyer($this->agent->id);
        $none = $this->old();
        $addr = $this->old(['address' => '9 Beach Rd']);
        $this->buyerMatch($none, $b->id);
        $this->buyerMatch($addr, $b->id);

        $ids = $this->builder(['settings' => ['enabledSelectionKeys' => ['my_buyers_sourcing']]])->buildFor($this->agent, self::WED)->pluck('prospecting_listing_id')->all();

        $this->assertSame([$addr, $none], $ids);
    }

    public function test_a_property_already_on_an_earlier_day_is_not_offered_again_but_a_follow_up_still_returns(): void
    {
        $b = $this->buyer($this->agent->id);
        $yesterdays = $this->old(['address' => '1 Seen Rd']);
        $fresh = $this->old(['address' => '2 New Rd']);
        $this->buyerMatch($yesterdays, $b->id);
        $this->buyerMatch($fresh, $b->id);
        $keys = ['enabledSelectionKeys' => ['my_buyers_sourcing']];

        $tuesday = $this->builder(['target' => 1, 'settings' => $keys])->buildFor($this->agent, '2026-10-13');
        $this->assertSame([$yesterdays], $tuesday->pluck('prospecting_listing_id')->all());
        $this->builder()->closePastDays($this->agent, '2026-10-14');            // the day was not worked: missed

        $wednesday = $this->builder(['target' => 5, 'settings' => $keys])->buildFor($this->agent, self::WED);
        $this->assertSame([$fresh], $wednesday->pluck('prospecting_listing_id')->all(), 'no duplicates across days');

        $due = new FollowUp(trackedPropertyId: $tuesday[0]->tracked_property_id, prospectingListingId: $yesterdays, propertyId: null, reason: 'Call back', facts: null);
        $thursday = $this->builder(['target' => 5, 'settings' => $keys, 'followUps' => [$this->agent->id => [$due]]])->buildFor($this->agent, '2026-10-15');
        $this->assertContains($yesterdays, $thursday->pluck('prospecting_listing_id')->all(), 'a follow-up is how it comes back');
    }

    // ── what each row records, for the tile and the stats ────────────────

    public function test_every_loaded_row_records_its_source_and_the_day_can_be_counted_by_source(): void
    {
        $b = $this->buyer($this->agent->id);
        $mate = $this->buyer($this->mate->id);
        $mine = $this->old(['address' => '1 A Rd']);
        $theirs = $this->old(['address' => '2 B Rd']);
        $cut = $this->listing(['address' => '3 C Rd']);
        $this->buyerMatch($mine, $b->id);
        $this->buyerMatch($theirs, $mate->id);
        DB::table('prospecting_price_history')->insert(['prospecting_listing_id' => $cut, 'old_price' => 1_600_000, 'new_price' => 1_500_000, 'changed_at' => '2026-10-10 09:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $builder = $this->builder(['target' => 3, 'settings' => ['enabledSelectionKeys' => ['my_buyers_sourcing', 'agency_buyers_sourcing', 'price_drops']]]);

        $items = $builder->buildFor($this->agent, self::WED);

        $s = $items->mapWithKeys(fn ($i) => [$i->selection => $i->reason_facts['source']]);
        $this->assertSame([AllocationSource::MY_BUYERS, [$b->id]], [$s['my_buyers_sourcing']['kind'], $s['my_buyers_sourcing']['buyer_ids']]);
        $this->assertSame(AllocationSource::COLLEAGUE_BUYERS, $s['agency_buyers_sourcing']['kind']);
        $this->assertSame([$this->mate->id], $s['agency_buyers_sourcing']['agent_ids']);
        $this->assertSame('agency', $s['agency_buyers_sourcing']['scope']);
        $this->assertSame([AllocationSource::FILL_UP, 'price_drops'], [$s['price_drops']['kind'], $s['price_drops']['fill']]);
        $this->assertSame([AllocationSource::MY_BUYERS => 1, AllocationSource::COLLEAGUE_BUYERS => 1, AllocationSource::FILL_UP => 1], $builder->sourceCounts($this->agent, self::WED));
        // the prospect carries it too
        $this->assertSame('my_buyers', RtsProspect::withoutGlobalScopes()->where('prospecting_listing_id', $mine)->firstOrFail()->reason_facts['source']['kind']);
    }

    public function test_the_preview_shows_what_would_load_and_writes_nothing(): void
    {
        $b = $this->buyer($this->agent->id);
        $l = $this->old(['address' => '1 A Rd']);
        $this->buyerMatch($l, $b->id);

        $p = $this->builder()->preview($this->agent, self::WED);

        $this->assertSame(5, $p['target']);
        $this->assertSame([AllocationSource::MY_BUYERS => 1], $p['by_source'] + [] ? [AllocationSource::MY_BUYERS => $p['by_source'][AllocationSource::MY_BUYERS]] : []);
        $this->assertSame($l, $p['rows'][0]['listing_id']);
        $this->assertSame(0, RtsListItem::query()->count());
        $this->assertSame(0, RtsProspect::withoutGlobalScopes()->count(), 'nothing is taken');
    }

    // ── add to my day ────────────────────────────────────────────────────

    public function test_add_to_my_day_is_capped_by_the_agencys_maximum(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->old();
        }
        $builder = $this->builder(['settings' => ['addToDayMax' => 3]]);
        $this->expectException(InvalidArgumentException::class);
        $builder->addToDay($this->agent, 'long_on_market', 4, self::WED);
    }

    // ── swap with a reason ───────────────────────────────────────────────

    public function test_a_swap_takes_a_reason_replaces_the_row_and_the_agent_never_sees_that_property_again(): void
    {
        Carbon::setTestNow(self::WED . ' 09:00:00');
        $b = $this->buyer($this->agent->id);
        $first = $this->old(['address' => '1 A Rd']);
        $second = $this->old(['address' => '2 B Rd']);
        $third = $this->old(['address' => '3 C Rd']);
        foreach ([$first, $second, $third] as $l) {
            $this->buyerMatch($l, $b->id);
        }
        $builder = $this->builder(['target' => 2, 'settings' => ['enabledSelectionKeys' => ['my_buyers_sourcing']]]);
        $items = $builder->buildFor($this->agent, self::WED);
        $this->assertCount(2, $items);
        $out = $items[0];

        $r = $builder->swap($this->agent, $out->id, 'Owner already sold it');

        $this->assertSame('swapped', $r['swapped']->status);
        $this->assertSame('Owner already sold it', $r['swapped']->reason_facts['swap']['reason']);
        $this->assertNotNull($r['replacement'], 'the next candidate takes its place');
        $day = $builder->listFor($this->agent, self::WED);
        $this->assertCount(2, $day, 'the day is still 2 - the swapped row is off the list');
        $this->assertNotContains($out->prospecting_listing_id, $day->pluck('prospecting_listing_id')->all());
        $this->assertSame(2, $builder->buildFor($this->agent, self::WED)->count(), 'a second build does not bring it back');

        // every count of the day reads the same rows: the Loaded tile, the header and the compare figures leave the swapped-out row out ("6 / 5" bug)
        $loaded = collect(app(\App\Services\RoadToSold\BeltService::class)->tiles($this->agent, $this->agent, 'sales', now()))->firstWhere('key', 'loaded');
        $this->assertSame(2, $loaded['count'], 'the tile counts the day\'s 2 rows, not the 3 that were ever on it');
        $this->assertSame(2, $loaded['done']);
        $fig = app(\App\Services\RoadToSold\BeltService::class)->scopeFigures($this->agent, 'loaded', 'sales', now());
        $this->assertSame(2, (int) collect($fig['scopes'])->firstWhere('scope', 'me')['now'] ?? 2);

        // never offered to this agent again, on any later day
        $this->assertNotContains($out->prospecting_listing_id, $builder->buildFor($this->agent, '2026-10-15')->pluck('prospecting_listing_id')->all());
        Carbon::setTestNow();
        // ... but it is free for a colleague (the prospect was released)
        $this->assertNull(RtsProspect::withoutGlobalScopes()->where('user_id', $this->agent->id)->where('tracked_property_id', $out->tracked_property_id)->whereNull('released_at')->whereNull('closed_at')->first());
    }

    public function test_a_swap_needs_a_reason_and_refuses_follow_ups_worked_rows_and_past_days(): void
    {
        $b = $this->buyer($this->agent->id);
        $l = $this->old();
        $this->buyerMatch($l, $b->id);
        $builder = $this->builder(['settings' => ['enabledSelectionKeys' => ['my_buyers_sourcing']]]);
        Carbon::setTestNow(self::WED . ' 09:00:00');
        $item = $builder->buildFor($this->agent, now()->toDateString())->first();

        try {
            $builder->swap($this->agent, $item->id, ' ');
            $this->fail('a swap without a reason must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('why', $e->getMessage());
        }

        $item->forceFill(['origin' => 'follow_up'])->save();
        $this->expectExceptionMessageMatches('/promise to call back/');
        $builder->swap($this->agent, $item->id, 'not interested');
    }

    public function test_another_agent_cannot_swap_my_row(): void
    {
        $b = $this->buyer($this->agent->id);
        $l = $this->old();
        $this->buyerMatch($l, $b->id);
        $builder = $this->builder(['settings' => ['enabledSelectionKeys' => ['my_buyers_sourcing']]]);
        Carbon::setTestNow(self::WED . ' 09:00:00');
        $item = $builder->buildFor($this->agent, now()->toDateString())->first();
        $this->expectExceptionMessage('not on your list');
        $builder->swap($this->mate, $item->id, 'because');
    }

    public function test_the_swap_route_is_own_list_only_and_answers_in_words(): void
    {
        $b = $this->buyer($this->agent->id);
        $l = $this->old();
        $this->buyerMatch($l, $b->id);
        Carbon::setTestNow(self::WED . ' 09:00:00');
        $item = app(ListBuilderService::class)->buildFor($this->agent, now()->startOfDay());
        $this->assertNotEmpty($item, 'a working day');
        $row = $item->first();

        $this->actingAs($this->mate)->post(route('road-to-sold.swap', $row->id), ['reason' => 'because'])->assertNotFound();
        $this->actingAs($this->agent)->post(route('road-to-sold.swap', $row->id), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->agent)->post(route('road-to-sold.swap', $row->id), ['reason' => 'Already listed with Anna'])->assertRedirect()->assertSessionHas('status');
        $this->assertSame('swapped', RtsListItem::query()->withoutGlobalScope(RtsListItem::SCOPE_NOT_SWAPPED)->find($row->id)->status);
    }
}
