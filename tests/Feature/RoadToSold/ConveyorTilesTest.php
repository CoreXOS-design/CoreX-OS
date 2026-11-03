<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RoadToSold\RtsSetting;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Performance\Period;
use App\Services\RoadToSold\Adapters\LaneFollowUpSource;
use App\Services\RoadToSold\AllocationSource;
use App\Services\RoadToSold\BeltFunnelService;
use App\Services\RoadToSold\BeltService;
use App\Services\RoadToSold\ConveyorService;
use App\Services\RoadToSold\Contracts\AreaProvider;
use App\Services\RoadToSold\Contracts\FollowUpSource;
use App\Services\RoadToSold\Contracts\WorkCalendar;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\Support\BuilderSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\NullSuggestionGuard;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v4 conveyor tiles, station lists and the day feed (road-to-sold.md 23.24): to do / moved on today / late on every tile, the last four stations as
 * "this month / today", the next-step windows, claims first on the day, and one agent's tiles never counting another's records.
 */
final class ConveyorTilesTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const WED = '2026-10-14';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::WED . ' 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id, 'scope' => 'own']);
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** a record of $agent at $stage, entered on $entered with an optional own next-action date */
    private function at(string $stage, string $street, ?string $entered = null, ?string $next = null, ?User $agent = null): RtsProspect
    {
        $agent ??= $this->agent;
        $p = app(ProspectService::class)->place($agent, $this->tp(['street_number' => '5', 'street_name' => $street])->id, 'sales', ['stage' => $stage]);
        $p->forceFill(['stage_entered_at' => ($entered ?? '2026-10-14') . ' 08:00:00', 'next_action_at' => $next])->saveQuietly();

        return $p->fresh();
    }

    private function tiles(?User $agent = null): \Illuminate\Support\Collection
    {
        $agent ??= $this->agent;

        return collect(app(BeltService::class)->tiles($agent, $agent, 'sales', now()))->keyBy('key');
    }

    private function figs(?User $agent = null): array
    {
        return app(ConveyorService::class)->figures($agent ?? $this->agent, 'sales', now());
    }

    // ── B: the windows ────────────────────────────────────────────────────

    public function test_each_station_has_its_own_window_and_late_means_the_date_is_before_today(): void
    {
        // Wednesday 14 Oct. Claimed: owner within 5 working days of the claim. Owner found: 3 working days. Contacted: 7 days. Held: 2 working days.
        $this->at(S::CLAIMED, 'Late Claim', '2026-10-05');       // +5 working days = Mon 12 Oct: late
        $this->at(S::CLAIMED, 'Fresh Claim', '2026-10-08');      // +5 = Thu 15 Oct: fine
        $this->at(S::OWNER_FOUND, 'Late Owner', '2026-10-08');   // +3 = Tue 13 Oct: late
        $this->at(S::OWNER_FOUND, 'Due Today', '2026-10-09');    // +3 = Wed 14 Oct: due today is not late
        $this->at(S::CONTACTED, 'Late Contact', '2026-10-06');   // +7 days = Tue 13 Oct: late
        $this->at(S::CONTACTED, 'Fresh Contact', '2026-10-08');  // +7 = Thu 15 Oct: fine
        $this->at(S::EVALUATION_DONE, 'Late Result', '2026-10-09');   // +2 working days = Tue 13 Oct: late
        $this->at(S::EVALUATION_DONE, 'Fresh Result', '2026-10-12');  // +2 = Wed 14 Oct: fine

        $f = $this->figs();
        $this->assertSame(['todo' => 2, 'late' => 1], ['todo' => $f['claimed']['todo'], 'late' => $f['claimed']['late']]);
        $this->assertSame(1, $f['owner']['late']);
        $this->assertSame(1, $f['contacted']['late']);
        $this->assertSame(1, $f['held']['late']);
        $this->assertSame(0, $f['won']['late'], 'the last stations have no window');
    }

    public function test_the_records_own_next_action_date_beats_the_station_window(): void
    {
        $this->at(S::CLAIMED, 'Own Date Late', '2026-10-13', '2026-10-10');     // fresh by the window, but its own date has passed
        $this->at(S::CLAIMED, 'Own Date Later', '2026-10-01', '2026-10-20');     // old by the window, but its own date is next week
        $this->assertSame(1, $this->figs()['claimed']['late']);

        // a booked evaluation is due when the appointment is
        $b1 = $this->at(S::APPOINTMENT_SET, 'Yesterday Eval');
        $b2 = $this->at(S::APPOINTMENT_SET, 'Today Eval');
        foreach ([[$b1, '2026-10-13 10:00:00'], [$b2, '2026-10-14 15:00:00']] as [$p, $when]) {
            DB::table('calendar_events')->insert(['agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'title' => 'Eval', 'event_type' => 'meeting', 'category' => 'property_evaluation',
                'event_date' => $when, 'status' => 'scheduled', 'rts_prospect_id' => $p->id, 'tracked_property_id' => $p->tracked_property_id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertSame(1, $this->figs()['booked']['late'], 'yesterday\'s appointment is late, today\'s is not');
    }

    public function test_a_window_is_an_agency_setting(): void
    {
        if (! RtsSetting::hasConveyorColumns()) {
            $this->markTestSkipped('the conveyor settings migration (2026_11_02_170000) has not been applied yet - held for Johan\'s go');
        }
        $this->at(S::CLAIMED, 'Window Claim', '2026-10-08');   // +5 working days = Thu 15 Oct: not late by the standard
        $this->assertSame(0, $this->figs()['claimed']['late']);

        RtsSetting::query()->withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], ['due_claimed_days' => 2]);   // +2 = Mon 12 Oct
        SettingsService::forget();
        $this->assertSame(1, $this->figs()['claimed']['late'], 'a stricter agency makes the same claim late');
        $this->assertSame(5, SettingsService::forAgency($this->agency->id + 999)->dueClaimedDays(), 'an agency that set nothing gets the standard');
    }

    // ── A: the three lines ─────────────────────────────────────────────────

    public function test_a_record_worked_from_loaded_to_owner_hops_the_tiles_and_agrees_with_the_funnel(): void
    {
        $p = app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '9', 'street_name' => 'Hop Road'])->id, 'sales', ['stage' => S::FOUND]);
        RtsListItem::withoutAgencyStamping(fn () => RtsListItem::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'user_id' => $this->agent->id, 'list_date' => self::WED, 'track' => 'sales',
            'tracked_property_id' => $p->tracked_property_id, 'selection' => 'hot_pockets', 'origin' => 'auto', 'position' => 1, 'status' => 'open', 'reason' => 'Test', 'prospect_id' => $p->id]));

        $t = $this->tiles();
        $this->assertSame(1, $t['loaded']['todo']);
        $this->assertSame(0, $t['loaded']['moved'], 'worked: 0');
        $this->assertSame(0, $t['claimed']['todo']);

        // the agent claims it: the day's row is worked, the record is at Claimed
        RtsListItem::query()->withoutGlobalScopes()->where('prospect_id', $p->id)->update(['status' => 'worked']);
        app(ProspectService::class)->advance($p->fresh(), S::CLAIMED, $this->agent, ['event_class' => 'test']);
        $t = $this->tiles();
        $this->assertSame(0, $t['loaded']['todo']);
        $this->assertSame(1, $t['loaded']['moved'], 'Loaded: 1 worked');
        $this->assertMatchesRegularExpression('/^1 of \d+ worked$/', $t['loaded']['lines'][1]['text']);
        $this->assertSame(1, $t['claimed']['todo'], 'Claimed +1');
        $this->assertSame(0, $t['claimed']['moved']);
        $this->assertSame(0, $t['owner']['todo']);

        // the owner is found: Claimed hands it on, Owner takes it
        app(ProspectService::class)->advance($p->fresh(), S::OWNER_FOUND, $this->agent, ['event_class' => 'test']);
        $t = $this->tiles();
        $this->assertSame(0, $t['claimed']['todo']);
        $this->assertSame(1, $t['claimed']['moved'], 'Claimed: 1 moved on today');
        $this->assertSame(1, $t['owner']['todo'], 'Owner +1');
        $this->assertSame(0, $t['owner']['moved']);

        // the tile and the funnel read the same history: moved on from a station = reached the next one, for today
        $day = CarbonImmutable::parse(self::WED);
        $funnel = app(BeltFunnelService::class)->forViewer($this->agent, new Period($day->startOfDay(), $day->endOfDay(), 'today', 'day'));
        $reached = collect($funnel['me']['stations'])->pluck('reached', 'key');
        foreach (['claimed', 'owner', 'contacted', 'booked', 'prepared', 'held'] as $k) {
            $this->assertSame((int) $reached[BeltFunnelService::nextStation($k)], $t[$k]['moved'], "{$k}: the tile and the funnel agree");
        }
        foreach (['won', 'draft', 'active', 'advertised'] as $k) {
            $this->assertSame((int) $reached[$k], $t[$k]['moved'], "{$k}: reached today agrees with the funnel");
        }
    }

    public function test_the_last_four_stations_read_this_month_and_today(): void
    {
        $a = $this->at(S::CONTACTED, 'Won Today');
        $b = $this->at(S::CONTACTED, 'Won Earlier');
        app(ProspectService::class)->advance($a, S::MANDATE_SIGNED, $this->agent, ['event_class' => 'test']);
        app(ProspectService::class)->advance($b, S::MANDATE_SIGNED, $this->agent, ['event_class' => 'test']);
        // the second one reached Won on the 2nd, not today
        DB::table('rts_prospect_events')->where('prospect_id', $b->id)->where('to_stage', S::MANDATE_SIGNED)->update(['occurred_at' => '2026-10-02 10:00:00']);
        $c = $this->at(S::MANDATE_SIGNED, 'Won Last Month');
        DB::table('rts_prospect_events')->where('prospect_id', $c->id)->update(['occurred_at' => '2026-09-20 10:00:00']);

        $t = $this->tiles();
        $this->assertSame('arrival', $t['won']['kind']);
        $this->assertSame(2, $t['won']['month'], 'two reached Won this month');
        $this->assertSame(1, $t['won']['moved'], 'one of them today (the line reads "today")');
        $this->assertSame(['2 this month', '1 today'], array_column($t['won']['lines'], 'text'));
        $this->assertSame(0, $t['won']['late']);
        $this->assertCount(2, $t['draft']['lines'], 'no late line on the last four');
    }

    public function test_a_claimed_record_on_the_days_load_counts_once_and_stays_in_the_days_five(): void
    {
        $p = $this->at(S::CLAIMED, 'Claimed And Loaded');
        $f = $this->at(S::FOUND, 'Plain Found');
        foreach ([$p, $f] as $i => $x) {
            RtsListItem::withoutAgencyStamping(fn () => RtsListItem::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'user_id' => $this->agent->id, 'list_date' => self::WED, 'track' => 'sales',
                'tracked_property_id' => $x->tracked_property_id, 'selection' => $i === 0 ? 'my_claims' : 'hot_pockets', 'origin' => 'auto', 'position' => $i + 1, 'status' => 'open', 'reason' => 'x', 'prospect_id' => $x->id]));
        }
        $t = $this->tiles();
        $this->assertSame(1, $t['loaded']['todo'], 'the claimed one is to do at Claimed, not twice');
        $this->assertSame(1, $t['claimed']['todo']);
        $this->assertSame('0 of 2 worked', $t['loaded']['lines'][1]['text'], 'but it is part of the day\'s load');
    }

    // ── scoping ───────────────────────────────────────────────────────────

    public function test_an_agents_tiles_and_lists_never_count_another_agents_records(): void
    {
        $this->at(S::CLAIMED, 'My Claim', '2026-10-01');
        $this->at(S::CLAIMED, 'Mates Claim', '2026-10-01', null, $this->mate);
        $this->at(S::CONTACTED, 'Mates Contact', '2026-10-01', null, $this->mate);
        $mine = $this->tiles();
        $this->assertSame(1, $mine['claimed']['todo']);
        $this->assertSame(1, $mine['claimed']['late']);
        $this->assertSame(0, $mine['contacted']['todo']);

        $html = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['block' => 'station', 'station' => 'claimed', 'filter' => 'all']))->assertOk()->getContent();
        $this->assertStringContainsString('My Claim', $html);
        $this->assertStringNotContainsString('Mates Claim', $html);
        // asking for a colleague's list by id still gives the viewer's own
        $other = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['block' => 'station', 'station' => 'claimed', 'agent' => $this->mate->id]))->getContent();
        $this->assertStringNotContainsString('Mates Claim', $other);
    }

    // ── D: the station list ───────────────────────────────────────────────

    public function test_the_station_list_uses_the_loaded_row_layout_orders_late_first_and_filters(): void
    {
        $this->at(S::CLAIMED, 'Fine Claim', '2026-10-13');
        $this->at(S::CLAIMED, 'Late Claim', '2026-10-01');
        $this->at(S::CLAIMED, 'Later Late Claim', '2026-10-05');
        $moved = $this->at(S::CLAIMED, 'Moving Claim', '2026-10-13');
        app(ProspectService::class)->advance($moved, S::OWNER_FOUND, $this->agent, ['event_class' => 'test']);

        $h = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['block' => 'station', 'station' => 'claimed']))->assertOk()->getContent();
        foreach (['Why - start here', 'Address', 'Price · days on market · agency', 'Owner', 'Last outcome', '>Due<'] as $col) {
            $this->assertStringContainsString($col, $h, "column {$col}");
        }
        $this->assertSame(3, substr_count($h, 'data-qa="station-row"'), 'to do: the three still at Claimed');
        $this->assertLessThan(strpos($h, 'Later Late Claim'), strpos($h, 'Late Claim'), 'late first, the oldest due first');
        $this->assertLessThan(strpos($h, 'Fine Claim'), strpos($h, 'Later Late Claim'), 'then the ones that are not late');
        $this->assertMatchesRegularExpression('/data-qa="station-action"[^>]*data-action="Find owner"/', $h, 'Claimed: Find owner');

        $late = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['block' => 'station', 'station' => 'claimed', 'filter' => 'late']))->getContent();
        $this->assertSame(2, substr_count($late, 'data-qa="station-row"'));
        $this->assertStringNotContainsString('Fine Claim', substr($late, (int) strpos($late, 'data-qa="station-list"')), 'the list holds only the late ones (the next-action card above it may name any record)');

        $movedList = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['block' => 'station', 'station' => 'claimed', 'filter' => 'moved']))->getContent();
        $this->assertSame(1, substr_count($movedList, 'data-qa="station-row"'));
        $this->assertStringContainsString('Moving Claim', $movedList);
        $this->assertStringContainsString('Now: Owner found', $movedList, 'a record that moved on says where it is now');

        $all = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['block' => 'station', 'station' => 'claimed', 'filter' => 'all']))->getContent();
        $this->assertSame(4, substr_count($all, 'data-qa="station-row"'));
    }

    public function test_every_station_offers_the_one_next_action_of_the_card(): void
    {
        $this->at(S::OWNER_FOUND, 'Owner Row');
        $this->at(S::CONTACTED, 'Contacted Row');
        $this->at(S::APPOINTMENT_SET, 'Booked Row');
        $this->at(S::EVALUATION_DONE, 'Held Row');
        $label = fn (string $station) => preg_match('/data-qa="station-action"[^>]*data-action="([^"]+)"/', $this->actingAs($this->agent)->get(route('road-to-sold.index', ['block' => 'station', 'station' => $station]))->getContent(), $m) ? $m[1] : null;
        $this->assertSame('Log contact', $label('owner'));
        $this->assertSame('Book evaluation', $label('contacted'));
        $this->assertSame('Evaluation feedback', $label('booked'));
        $this->assertSame('Result', $label('held'));
        $held = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['block' => 'station', 'station' => 'held']))->getContent();
        $this->assertStringContainsString('data-qa="result-modal"', $held, 'the Result pop-up is on the Held list');
    }

    public function test_the_header_line_adds_up_the_tiles(): void
    {
        $a = $this->at(S::CLAIMED, 'Header Claim', '2026-10-01');   // late
        $b = $this->at(S::CLAIMED, 'Header Owner', '2026-10-13');
        app(ProspectService::class)->advance($b, S::OWNER_FOUND, $this->agent, ['event_class' => 'test']);
        $t = $this->tiles();
        $moved = app(ConveyorService::class)->movedAlong($this->agent, 'sales', now());
        $late = (int) $t->sum('late');
        $this->assertGreaterThan(0, $moved);
        $this->assertSame(1, $late);

        $h = $this->actingAs($this->agent)->get(route('road-to-sold.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-qa="today-line">Today: <strong>\d+ of \d+<\/strong> worked/', $h);
        $this->assertMatchesRegularExpression('/data-qa="today-moved">' . $moved . '<\/strong> moved along/', $h);
        $this->assertMatchesRegularExpression('/data-qa="today-late"[^>]*>' . $late . '<\/strong> late/', $h);
    }

    public function test_moved_along_counts_a_record_once_however_many_stations_it_passed(): void
    {
        $svc = app(ProspectService::class);
        $a = $svc->place($this->agent, $this->tp(['street_number' => '1', 'street_name' => 'Fast Road'])->id, 'sales', ['stage' => S::FOUND]);
        $svc->advance($a, S::EVALUATION_DONE, $this->agent, ['event_class' => 'test']);   // passes Claimed .. Held in one move
        $svc->advance($a->fresh(), S::MANDATE_SIGNED, $this->agent, ['event_class' => 'test']);   // Held -> Won: leaves Held AND reaches Won
        $b = $svc->place($this->agent, $this->tp(['street_number' => '2', 'street_name' => 'Slow Road'])->id, 'sales', ['stage' => S::FOUND]);
        $svc->advance($b, S::OWNER_FOUND, $this->agent, ['event_class' => 'test']);

        $t = $this->tiles();
        $lines = (int) $t->where('key', '!=', 'loaded')->sum('moved');
        $this->assertGreaterThan(2, $lines, 'the tiles\' lines count a record at every station it left or reached');
        $this->assertSame(2, app(ConveyorService::class)->movedAlong($this->agent, 'sales', now()), 'two records moved today, each once');
        $this->assertSame(1, $t['held']['moved'], 'the per-tile line is unchanged');
        $this->assertSame(1, $t['won']['moved']);

        $h = $this->actingAs($this->agent)->get(route('road-to-sold.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-qa="today-moved">2<\/strong> moved along/', $h);
        // another agent's moves are never in this agent's header
        $c = $svc->place($this->mate, $this->tp(['street_number' => '3', 'street_name' => 'Mates Road'])->id, 'sales', ['stage' => S::FOUND]);
        $svc->advance($c, S::OWNER_FOUND, $this->mate, ['event_class' => 'test']);
        $this->assertSame(2, app(ConveyorService::class)->movedAlong($this->agent, 'sales', now()));
    }

    // ── E: the day feed ───────────────────────────────────────────────────

    private function builder(array $settings = []): ListBuilderService
    {
        $settings = (new BuilderSettings())->with($settings);
        $calendar = new class implements WorkCalendar {
            public function targetFor(User $u, CarbonInterface $d, string $track): int { return $this->isWorkday($u, $d, $track) ? 5 : 0; }
            public function isWorkday(User $u, CarbonInterface $d, string $track): bool { return ! $d->isWeekend(); }
            public function nextWorkday(User $u, CarbonInterface $from, string $track): ?Carbon { $x = Carbon::instance($from)->startOfDay(); do { $x = $x->addDay(); } while ($x->isWeekend()); return $x; }
        };
        $areas = new class implements AreaProvider {
            public function suburbIdsFor(User $u, string $t): array { return []; }
            public function suburbNamesNormalisedFor(User $u, string $t): array { return []; }
            public function suburbNamesLowerTrimFor(User $u, string $t): array { return []; }
            public function hasAreas(User $u, string $t): bool { return false; }
        };
        $followUps = new class implements FollowUpSource {
            public function dueOn(User $u, CarbonInterface $d, string $t): array { return []; }
        };

        return new ListBuilderService(calendar: $calendar, areas: $areas, guard: new NullSuggestionGuard(), followUps: $followUps, settingsResolver: fn (User $u, string $t) => $settings);
    }

    /** a claimed record with an active claim on the clock (the claim places the record at Claimed, the way Market Intelligence does) */
    private function claimed(string $street, string $entered, string $holdUntil, ?string $next = null): RtsProspect
    {
        $tp = $this->tp(['street_number' => '5', 'street_name' => $street]);
        $listing = $this->listing(['address' => '5 ' . $street], $tp);
        $claim = ProspectingClaim::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $listing, 'user_id' => $this->agent->id, 'status' => 'claimed',
            'claimed_at' => $entered . ' 08:00:00', 'last_updated_at' => $entered . ' 08:00:00', 'is_active' => true]);
        $p = RtsProspect::query()->withoutGlobalScopes()->where('tracked_property_id', $tp->id)->where('user_id', $this->agent->id)->firstOrFail();
        $p->forceFill(['stage' => S::CLAIMED, 'stage_entered_at' => $entered . ' 08:00:00', 'next_action_at' => $next])->saveQuietly();
        $claim->forceFill(['rts_prospect_id' => $p->id, 'hold_until' => $holdUntil])->saveQuietly();

        return $p->fresh();
    }

    public function test_the_days_five_start_with_the_agents_own_claims_late_first_then_nearest_hold_end(): void
    {
        $nearHold = $this->claimed('Near Hold Road', '2026-10-13', '2026-10-16');
        $farHold = $this->claimed('Far Hold Road', '2026-10-13', '2026-11-20');
        $late = $this->claimed('Late Claim Road', '2026-10-01', '2026-11-30');   // late by the window, but a long hold left
        $buyerListing = $this->listing(['address' => '1 Buyer Rd', 'first_seen_at' => '2026-03-01 08:00:00']);
        $mine = $this->buyer($this->agent->id);
        $this->buyerMatch($buyerListing, $mine->id);

        $items = $this->builder(['enabledSelectionKeys' => ['my_buyers_sourcing']])->buildFor($this->agent, self::WED);
        $this->assertSame([$late->tracked_property_id, $nearHold->tracked_property_id, $farHold->tracked_property_id], $items->take(3)->pluck('tracked_property_id')->all(), 'late first, then the nearest end of the hold');
        $this->assertSame(['my_claims', 'my_claims', 'my_claims', 'my_buyers_sourcing'], $items->pluck('selection')->all(), 'then the buyers\' stock');

        $why = app(\App\Services\RoadToSold\CallListService::class)->page($this->agent, $this->agent->id, ['date' => self::WED], 'sales')['rows']->first()['why'];
        $this->assertMatchesRegularExpression('/^Your claim · \d+ days? left on hold$/', $why);
    }

    public function test_the_claims_first_switch_and_the_preview_source(): void
    {
        $this->claimed('Switch Road', '2026-10-13', '2026-11-20');
        $on = $this->builder(['enabledSelectionKeys' => []])->preview($this->agent, self::WED);
        $this->assertSame(1, $on['by_source'][AllocationSource::MY_CLAIM] ?? 0, 'the preview shows the new source');
        $this->assertSame('Your claims', AllocationSource::LABELS[AllocationSource::MY_CLAIM]);

        $off = $this->builder(['enabledSelectionKeys' => [], 'fillFromClaimsFirst' => false])->preview($this->agent, self::WED);
        $this->assertArrayNotHasKey(AllocationSource::MY_CLAIM, $off['by_source'], 'switched off: claims are not put on the day');
        $this->assertTrue(SettingsService::forUser($this->agent)->fillFromClaimsFirst(), 'on by default');
    }

    public function test_a_claim_already_on_the_day_is_not_added_twice_and_another_agents_claim_is_never_offered(): void
    {
        $mine = $this->claimed('Once Road', '2026-10-13', '2026-11-20');
        $mates = $this->at(S::CLAIMED, 'Mates Road', '2026-10-01', null, $this->mate);
        $b = $this->builder(['enabledSelectionKeys' => []]);
        $first = $b->buildFor($this->agent, self::WED);
        $again = $b->buildFor($this->agent, self::WED);
        $this->assertSame(1, $first->count());
        $this->assertSame(1, $again->count(), 'built twice, on the list once');
        $this->assertNotContains($mates->tracked_property_id, $first->pluck('tracked_property_id')->all());
        $this->assertSame(1, RtsListItem::query()->withoutGlobalScopes()->where('user_id', $this->agent->id)->count());
    }
}
