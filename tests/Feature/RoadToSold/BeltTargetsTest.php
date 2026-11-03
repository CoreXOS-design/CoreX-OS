<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\RoadToSold\RtsSetting;
use App\Models\User;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\SummaryBarService;
use App\Services\RoadToSold\TargetService;
use App\Services\RoadToSold\TargetsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold belt TARGETS (.ai/specs/road-to-sold.md 23.29): the derived "should be here" figures, the override, the "estimate" label below the minimum
 * sample, the worked example (5 listings a month at 2.5% MIC -> Active = 200 from MIC a month = 10 a working day over 20 working days) and the me / branch /
 * agency scoping. February 2026 has exactly 20 working days for the standard Monday-Friday week.
 */
final class BeltTargetsTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const DAY = '2026-02-18';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::DAY . ' 09:00:00');
        SettingsService::forget();
        Cache::flush();
        $this->setUpAgency();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $attrs): void
    {
        RtsSetting::query()->withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], $attrs);
        SettingsService::forget();
    }

    /** Rates whose product is 2.5% (10% x 25% x 100% ...): one loaded record in forty reaches Active. */
    private function twoPointFivePercent(array $more = []): void
    {
        $this->settings(['target_rates' => ['loaded' => 10, 'claimed' => 25, 'owner' => 100, 'contacted' => 100, 'booked' => 100, 'prepared' => 100, 'held' => 100, 'won' => 100, 'draft' => 100]] + $more);
    }

    private function listings(User $u, int $n): void
    {
        app(TargetService::class)->saveMonthly($u, $u, '2026-02', ['listings_target' => $n]);
    }

    /** $n records placed at Loaded (stage found); the first $k of them go on to $stage (a stage event from found). */
    private function history(User $u, int $n, array $through, int $base = 1000): void
    {
        $at = '2026-02-02 10:00:00';
        for ($i = 1; $i <= $n; $i++) {
            DB::table('rts_prospect_events')->insert(['agency_id' => $u->agency_id, 'track' => 'sales', 'prospect_id' => $base + $i, 'user_id' => $u->id, 'occurred_at' => $at, 'kind' => 'placed', 'to_stage' => 'found', 'created_at' => $at, 'updated_at' => $at]);
        }
        foreach ($through as $stage => $k) {
            for ($i = 1; $i <= $k; $i++) {
                DB::table('rts_prospect_events')->insert(['agency_id' => $u->agency_id, 'track' => 'sales', 'prospect_id' => $base + $i, 'user_id' => $u->id, 'occurred_at' => $at, 'kind' => 'stage', 'from_stage' => 'found', 'to_stage' => $stage, 'created_at' => $at, 'updated_at' => $at]);
            }
        }
    }

    public function test_the_worked_example_five_listings_at_2_5_percent_is_200_from_mic_10_a_working_day(): void
    {
        $this->twoPointFivePercent();
        $this->listings($this->agent, 5);
        $f = app(TargetsService::class)->forScope($this->agent, 'me', Carbon::now());

        $this->assertSame(20, $f['working_days'], 'February 2026 has 20 working days');
        $this->assertSame(5, $f['listings']);
        $this->assertEqualsWithDelta(200.0, $f['mic_per_month'], 0.01);
        $this->assertEqualsWithDelta(10.0, $f['mic_per_day'], 0.01);
        $this->assertEqualsWithDelta(20.0, $f['stations']['claimed']['derived_month'], 0.01, 'Claimed = 200 x 10% a month');
        $this->assertEqualsWithDelta(5.0, $f['stations']['active']['month'], 0.01);
        $this->assertSame('set', $f['stations']['loaded']['source'], 'MIC intake per day stays the hand-set weekday target');
        $this->assertEqualsWithDelta(100.0, $f['stations']['loaded']['month'], 0.01, '5 a day x 20 working days');
    }

    public function test_the_one_line_to_list_one_property(): void
    {
        $this->twoPointFivePercent();
        $line = app(TargetsService::class)->perListingLine($this->agent, Carbon::now());

        $this->assertSame(40, $line['me'], '1 / 2.5%');
        $this->assertSame(40, $line['agency']);
        $this->assertStringContainsString('To list 1 property: you need 40 from MIC', $line['text']);
        $this->assertStringContainsString('branch avg 40', $line['text']);
        $this->assertStringContainsString('agency avg 40', $line['text']);
        $this->assertTrue($line['estimate'], 'no history yet: the standard rates, labelled estimate');
        $this->assertStringContainsString('(estimate)', $line['text']);
    }

    public function test_an_override_wins_and_the_derived_figure_stays_as_the_recommendation(): void
    {
        $this->twoPointFivePercent(['target_overrides' => ['booked' => 7]]);
        $this->listings($this->agent, 5);
        $b = app(TargetsService::class)->forScope($this->agent, 'me', Carbon::now())['stations']['booked'];

        $this->assertSame('override', $b['source']);
        $this->assertEqualsWithDelta(7.0, $b['month'], 0.01);
        $this->assertEqualsWithDelta(5.0, $b['derived_month'], 0.01, 'the derived figure is still worked out');
        $this->assertFalse($b['estimate'], 'a set figure is never an estimate');
    }

    public function test_below_the_minimum_sample_the_default_rate_is_used_and_labelled_estimate_and_above_it_the_real_rate(): void
    {
        $this->settings(['target_min_sample' => 30]);
        $this->history($this->agent, 20, ['claimed' => 10]);     // 20 through Loaded: under 30
        $r = app(TargetsService::class)->rates($this->agent, 'me', Carbon::now());
        $this->assertTrue($r['loaded']['estimate']);
        $this->assertEqualsWithDelta(0.4, $r['loaded']['rate'], 0.0001, 'the standard 40% for Loaded -> Claimed');

        $this->history($this->agent, 40, ['claimed' => 10], 5000);   // 60 through Loaded now, 20 of them reached Claimed
        $r = app(TargetsService::class)->rates($this->agent, 'me', Carbon::now());
        $this->assertFalse($r['loaded']['estimate'], '60 records went through Loaded');
        $this->assertEqualsWithDelta(20 / 60, $r['loaded']['rate'], 0.0001, 'the real rate: 20 of 60');
        $this->assertTrue($r['claimed']['estimate'], 'Claimed has only 20 records through it: still the standard rate');
    }

    public function test_scoping_me_branch_and_agency_read_only_their_own_people(): void
    {
        $this->twoPointFivePercent();
        $this->listings($this->agent, 5);
        $this->listings($this->mate, 3);                                   // same branch
        $otherBranch = \App\Models\Branch::create(['agency_id' => $this->agency->id, 'name' => 'Second']);
        $far = $this->makeAgent(null, $otherBranch);
        $this->listings($far, 2);                                          // same agency, other branch
        $stranger = $this->makeAgent(\App\Models\Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'o-' . uniqid()]));
        $row = (array) DB::table('targets')->where('user_id', $this->agent->id)->where('period', '2026-02')->first();   // another agency's row, copied from a real one
        unset($row['id']);
        DB::table('targets')->insert(array_merge($row, ['agency_id' => $stranger->agency_id, 'user_id' => $stranger->id, 'branch_id' => $stranger->branch_id, 'listings_target' => 50]));   // never counted

        $t = app(TargetsService::class);
        $this->assertSame(5, $t->forScope($this->agent, 'me', Carbon::now())['listings']);
        $this->assertSame(8, $t->forScope($this->agent, 'branch', Carbon::now())['listings'], 'my branch: 5 + 3');
        $this->assertSame(10, $t->forScope($this->agent, 'agency', Carbon::now())['listings'], 'the agency: 5 + 3 + 2, never the other agency');
        $this->assertEqualsWithDelta(320.0, $t->forScope($this->agent, 'branch', Carbon::now())['mic_per_month'], 0.01, '8 listings x 40 from MIC each');
    }

    public function test_the_listings_target_falls_back_to_the_agency_default_then_the_standard(): void
    {
        $this->twoPointFivePercent();
        $t = app(TargetsService::class);
        $this->assertSame(2, $t->listingsFor($this->agent, '2026-02'), 'nobody set anything: the standard');
        $this->settings(['target_listings_per_month' => 4]);
        $this->assertSame(4, $t->listingsFor($this->agent, '2026-02'), 'the agency standard setting');
        $this->settings(['default_monthly_targets' => ['points' => 0, 'deals' => 0, 'listings' => 6, 'value' => 0]]);
        $this->assertSame(6, $t->listingsFor($this->agent, '2026-02'), 'the agency default monthly target beats the standard');
        $this->listings($this->agent, 9);
        $this->assertSame(9, $t->listingsFor($this->agent, '2026-02'), "the agent's own monthly target wins");
    }

    public function test_the_summary_bar_reads_the_same_figure_as_the_targets_service(): void
    {
        $this->twoPointFivePercent(['target_overrides' => ['booked' => 10]]);
        $this->listings($this->agent, 5);
        $svc = app(TargetsService::class);
        $bar = collect(app(SummaryBarService::class)->forAgent($this->agent, Carbon::now(), 'sales')['steps'])->keyBy('key');

        foreach (['listings_live', 'evaluations_booked'] as $k) {
            if (! $bar->has($k)) {
                continue;   // a step the agency switched off is not drawn
            }
            $this->assertEqualsWithDelta($svc->shouldBeToday($this->agent, $k, Carbon::now()), $bar[$k]['should_be'], 0.01, "{$k}: one source");
        }
        // Feb 18 2026 is the 13th of 20 working days: 5 x 13 / 20
        $this->assertEqualsWithDelta(3.3, $svc->shouldBeToday($this->agent, 'listings_live', Carbon::now()), 0.06);
    }

    public function test_a_thin_step_of_the_agent_uses_the_agencys_measured_rate_before_the_seed(): void
    {
        $this->settings(['target_min_sample' => 30]);
        $this->history($this->mate, 60, ['claimed' => 30], 7000);     // a colleague: 60 through Loaded, half of them reached Claimed
        $r = app(TargetsService::class)->rates($this->agent, 'me', Carbon::now());   // my own history is empty

        $this->assertFalse($r['loaded']['estimate'], "the agency's measured rate stands in, so it is not a seed");
        $this->assertEqualsWithDelta(0.5, $r['loaded']['rate'], 0.0001);
        $this->assertTrue($r['claimed']['estimate'], 'nothing measured for Claimed anywhere: the seed');
    }

    public function test_a_recipe_task_target_is_the_override_for_its_step_and_a_blank_step_stays_derived(): void
    {
        $this->twoPointFivePercent();
        $this->listings($this->agent, 5);
        app(\App\Services\RoadToSold\RecipeService::class)->start($this->agency->id, 'sales');
        $f = app(TargetsService::class)->forScope($this->agent, 'me', Carbon::now())['stations'];

        $this->assertSame('recipe', $f['contacted']['source'], 'the recipe sets a daily contacted target: it wins over the derived figure');
        $this->assertEqualsWithDelta(10 * 20, $f['contacted']['month'], 0.01, '10 a day x 20 working days');
        $this->assertSame('derived', $f['claimed']['source'], 'no recipe task for Claimed: derived');
        $this->assertEqualsWithDelta(20.0, $f['claimed']['month'], 0.01);
        $this->settings(['target_overrides' => ['contacted' => 3]]);
        $this->assertSame('override', app(TargetsService::class)->forScope($this->agent, 'me', Carbon::now())['stations']['contacted']['source'], "the settings' own per-step target beats the recipe's");
    }

    public function test_the_panel_is_one_row_per_station_whole_numbers_rounded_up_with_est_on_the_seed_rate_only(): void
    {
        $this->listings($this->agent, 5);   // the standard rates: 2.5% MIC -> Active, February 2026 = 20 working days
        $p = app(TargetsService::class)->panel($this->agent, 'me', Carbon::now());
        $by = collect($p['rows'])->keyBy('station');

        $this->assertCount(10, $p['rows'], 'one row per station from Stock loaded to Active');
        // the walk back of 23.29.3 with the seed rates (their product is 2.495%, so 5 listings need 200.4 from MIC), each figure rounded UP
        $this->assertSame([201, 81, 49, 39, 20, 18, 17, 7, 7, 5], $by->pluck('should_month')->values()->all());
        $this->assertSame([11, 5, 3, 2, 1, 1, 1, 1, 1, 1], $by->pluck('should_day')->values()->all(), 'a day: the month figure over the 20 working days, rounded up');
        foreach ($p['rows'] as $r) {
            $this->assertIsInt($r['should_month']);
            $this->assertIsInt($r['should_day']);
        }
        foreach (['loaded', 'booked', 'held', 'draft', 'active'] as $k) {
            $this->assertSame(\App\Services\RoadToSold\BeltService::station($k)['short'], $by[$k]['label'], 'the row carries the TILE name, read from the belt config');
        }
        $this->assertSame("Today's work", $by['loaded']['label']);
        $this->assertSame('Booked', $by['booked']['label']);
        $this->assertSame('Held', $by['held']['label']);
        $this->assertSame('Won → Draft', $by['won']['label'], 'the one station without a tile is named for its step');
        foreach ($p['rows'] as $r) {
            $this->assertTrue($r['rate_next'] === null || is_int($r['rate_next']), 'whole percent');
        }
        $this->assertSame(201, $by['loaded']['should_month'], 'ONE figure for Stock loaded: what the walk back needs, the same arithmetic as the one line');
        $this->assertSame(5, $by['loaded']['set_day'], 'the hand-set intake stays the weekday target, shown beside it');
        $this->assertTrue($by['claimed']['rate_estimate'], 'no history: the seed rate, labelled est. at this station');
        $this->assertFalse($by['active']['rate_estimate'], 'the last station has no rate');
        $this->assertSame('To list 1 property: you need 41 from MIC · branch avg 41 · agency avg 41', $p['line']['plain'], 'the same rates as the table: 1 / 2.495% = 40.1, rounded up; 5 listings = 5 x 40.1 = 200.4, rounded up to the table\'s 201');
        $this->assertStringNotContainsString('estimate', $p['line']['plain'], 'the label sits per station, not once for the page');
    }

    public function test_the_settings_are_added_with_the_standard_numbers(): void
    {
        $s = SettingsService::forAgency($this->agency->id);
        $this->assertSame(2, $s->targetListingsPerMonth());
        $this->assertSame(90, $s->targetWindowDays());
        $this->assertSame(30, $s->targetMinSample());
        $this->assertSame(TargetsService::DEFAULT_RATES, $s->stepRates());
        $this->assertSame([], $s->stepOverrides());
        $product = 1.0;
        foreach (TargetsService::DEFAULT_RATES as $r) {
            $product *= $r / 100;
        }
        $this->assertEqualsWithDelta(0.025, $product, 0.0005, 'the standard rates come to 2.5% MIC -> Active (23.29.2)');
    }
}
