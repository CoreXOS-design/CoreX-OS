<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ParkReasonService;
use App\Services\RoadToSold\PipelineLineService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v3 - THE PRODUCTION LINE (road-to-sold.md 22.24): the Prospects tab as 18 stations in 5 phases, every property as a chip under its
 * station, the month's flow between phases, the property card, the manager's sums and scoping, and the manual move that must say what happened.
 */
final class ProductionLineTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const WED = '2026-10-14';

    private ProspectService $prospects;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::WED . ' 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        ParkReasonService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        $this->prospects = app(ProspectService::class);
        $this->manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true, 'name' => 'Mia Manager']);
        foreach (['agent' => 'own', 'branch_manager' => 'branch'] as $role => $scope) {
            RolePermission::create(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
            RolePermission::create(['role' => $role, 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id, 'scope' => $scope]);
        }
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owned(User $agent, string $street, string $suburb = 'Uvongo'): RtsProspect
    {
        $tp = $this->tp(['street_number' => '5', 'street_name' => $street, 'suburb' => $suburb, 'owner_contact_id' => Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $agent->id, 'agent_id' => $agent->id,
            'first_name' => 'Owner', 'last_name' => $street, 'phone' => '0821234567',
        ])->id]);

        return $this->prospects->place($agent, $tp->id);
    }

    private function move(RtsProspect $p, string $stage, ?User $by = null, ?string $next = null, ?string $at = null): void
    {
        static $n = 1000;
        $this->assertTrue($this->prospects->advance($p->refresh(), $stage, $by ?? $this->agent, ['subject_type' => 'x', 'subject_id' => $n++, 'next_label' => $next ?? S::defaultNext($stage), 'next_at' => $at ? Carbon::parse($at) : null]));
    }

    /** The page's own data as plain JSON text: entities decoded, the \u0022 quotes and \/ slashes that @js writes turned back. */
    private function pageData(string $html): string
    {
        return str_replace(['\\u0022', '\\u0027', '\\u0026', '\\\\/', '\\/'], ['"', "'", '&', '/', '/'], html_entity_decode($html));
    }

    private function page(User $as, array $q = [])
    {
        return $this->actingAs($as)->get(route('road-to-sold.index', ['block' => 'prospects'] + $q));
    }

    /** The HTML of one station's cards: from its heading to the next heading (all stations view). */
    private function column(string $html, string $stage): string
    {
        $at = strpos($html, 'data-chips-for="' . $stage . '"');
        $this->assertNotFalse($at, "no heading for {$stage}");
        $rest = substr($html, $at);
        $next = strpos($rest, 'class="rts-gh"');

        return $next === false ? $rest : substr($rest, 0, $next);
    }

    // ── the line ────────────────────────────────────────────────────────

    public function test_the_line_is_five_phases_and_eighteen_stations_in_the_order_johan_gave(): void
    {
        $this->owned($this->agent, 'Alpha Road');
        $html = $this->page($this->agent)->assertOk()->getContent();

        preg_match_all('/data-phase="([a-z_]+)"/', $html, $ph);
        $this->assertSame(['get_stock', 'win', 'market', 'sell', 'close'], $ph[1]);
        foreach (['Get stock', 'Win the mandate', 'Market it', 'Sell it', 'Close it'] as $label) {
            $this->assertStringContainsString('>' . $label . '</span>', $html);
        }
        preg_match_all('/data-station="([a-z_]+)"/', $html, $st);
        $this->assertSame(S::keys(), $st[1]);
        $this->assertCount(18, $st[1]);
        // phase membership is exactly the brief's
        $this->assertSame([S::FOUND, S::CONTACTED, S::APPOINTMENT_SET, S::EVALUATION_DONE], S::PHASES['get_stock']['stages']);
        $this->assertSame([S::PRESENTATION_GIVEN, S::PRESENTATION_FEEDBACK, S::MANDATE_SIGNED], S::PHASES['win']['stages']);
        $this->assertSame([S::LISTING_LIVE, S::ADVERTISING], S::PHASES['market']['stages']);
        $this->assertSame([S::LEAD, S::LEAD_CONTACTED, S::CORE_MATCH, S::VIEWING_SCHEDULED, S::VIEWING_PACK, S::VIEWING_FEEDBACK, S::OFFER], S::PHASES['sell']['stages']);
        $this->assertSame([S::DEAL, S::REGISTERED], S::PHASES['close']['stages']);
        // every station has a short name for a narrow screen and its full name in the hover; nothing scrolls sideways (the line is a grid of 18)
        foreach (S::keys() as $k) {
            $this->assertNotSame('', S::short($k));
            $this->assertStringContainsString('title="' . e(S::label($k)) . ':', $html);
        }
        $this->assertStringContainsString('repeat(18, minmax(0, 1fr))', $html);
    }

    public function test_a_station_shows_how_many_are_there_and_a_red_figure_for_overdue_or_stalled(): void
    {
        $a = $this->owned($this->agent, 'Alpha Road');
        $b = $this->owned($this->agent, 'Beta Road');
        $c = $this->owned($this->agent, 'Gamma Road');
        $this->prospects->setNextAction($a, 'Call again', Carbon::parse('2026-10-10'), $this->agent);   // overdue
        $this->prospects->setNextAction($b, 'Call again', Carbon::parse('2026-10-30'), $this->agent);   // fine
        $this->prospects->setNextAction($c, 'Call again', Carbon::parse('2026-10-30'), $this->agent);
        DB::table('rts_prospects')->where('id', $c->id)->update(['updated_at' => now()->subDays(40)]);   // stalled, not overdue

        $html = $this->page($this->agent)->getContent();
        $this->assertSame(1, preg_match('/data-station="found" data-count="3"[^>]*>.*?data-qa="station-behind"[^>]*>!2</s', $html), 'three at Found, two of them need attention (counted once each)');
        $this->assertSame(1, preg_match('/data-station="contacted" data-count="0"/', $html));
        $this->assertStringContainsString('1 overdue, 1 stalled', $html);
        // an agent with nothing wrong has no red figure
        $x = $this->owned($this->mate, 'Delta Road');
        $this->prospects->setNextAction($x, 'Call', Carbon::parse('2026-10-30'), $this->mate);
        $this->assertStringNotContainsString('station-behind', $this->page($this->mate)->getContent());
    }

    public function test_a_chip_sits_under_its_station_with_address_days_next_action_and_due_date(): void
    {
        $a = $this->owned($this->agent, 'Alpha Road');
        $b = $this->owned($this->agent, 'Beta Road');
        $this->move($b, S::APPOINTMENT_SET, null, 'Do the evaluation', '2026-10-10');   // overdue
        Carbon::setTestNow('2026-10-17 09:00:00');

        $html = $this->page($this->agent)->getContent();
        $found = $this->column($html, S::FOUND);
        $appt = $this->column($html, S::APPOINTMENT_SET);
        $this->assertStringContainsString('5 Alpha Road', $found);
        $this->assertStringNotContainsString('5 Beta Road', $found);
        $this->assertStringContainsString('5 Beta Road', $appt);
        $this->assertStringContainsString('data-qa="next-action">Do the evaluation<', $appt);
        $this->assertStringContainsString('rts-ch is-overdue', $appt, 'overdue is marked on the chip');
        $this->assertStringContainsString('data-due="overdue"', $appt);
        $this->assertStringContainsString('Sat 10', $appt, 'the due date');
        $this->assertStringContainsString('3d', $appt, 'days at this station (it moved on the 14th, today is the 17th)');
        $this->assertStringContainsString('3 days here', $appt);
    }

    public function test_all_stations_view_is_readable_cards_under_a_slim_heading_per_station_in_line_order(): void
    {
        $a = $this->owned($this->agent, 'Alpha Road', 'Uvongo');
        $b = $this->owned($this->agent, 'Beta Road', 'Margate');
        $c = $this->owned($this->agent, 'Gamma Road', 'Shelly');
        $this->move($b, S::APPOINTMENT_SET, null, 'Do the evaluation', '2026-10-20');
        $this->move($c, S::LISTING_LIVE, null, 'Share the advert', '2026-10-21');

        $html = $this->page($this->agent)->assertOk()->getContent();

        // one heading per station that has properties, in the order of the line, with its count; empty stations get none
        preg_match_all('/data-chips-for="([a-z_]+)"[^>]*><span>([^<]+)<\/span>/', $html, $m);
        $this->assertSame([S::FOUND, S::APPOINTMENT_SET, S::LISTING_LIVE], $m[1]);
        $this->assertSame(['Found · 1', 'Appointment set · 1', 'Listing live · 1'], array_map(fn ($t) => html_entity_decode($t), $m[2]));

        // the same readable card as the single-station view: suburb, owner line and the due words are all there; no 18-column chip grid
        $this->assertStringNotContainsString('rts-col18', $html);
        $this->assertStringNotContainsString('rts-cols', $html);
        $this->assertMatchesRegularExpression('/<div class="rts-wide" data-qa="chips">/', $html);
        $found = $this->column($html, S::FOUND);
        $this->assertStringContainsString('5 Alpha Road', $found);
        $this->assertStringContainsString('Uvongo', $found);
        $this->assertStringContainsString('<span class="x rts-sub">', $found, 'the owner line is on the card');

        // every card still opens the property card
        $this->assertSame(3, substr_count($html, '@click="openCard('));
    }

    public function test_an_agent_who_types_a_colleagues_address_gets_their_own_page_not_a_404(): void
    {
        $this->owned($this->agent, 'Alpha Road');
        $this->owned($this->mate, 'Mate Road');

        $res = $this->page($this->agent, ['agent' => $this->mate->id])->assertOk();
        $this->assertStringContainsString('5 Alpha Road', $res->getContent());
        $this->assertStringNotContainsString('5 Mate Road', $res->getContent(), 'the colleague\'s properties are never read for an agent');
        $this->page($this->agent, ['agent' => 999999])->assertOk();
        // a manager asking for someone outside their reach still gets nothing of theirs
        $this->assertNotSame(200, $this->page($this->manager, ['agent' => 999999])->getStatusCode());
    }

    public function test_clicking_a_station_shows_only_that_stations_chips_wider_and_all_stations_is_the_default(): void
    {
        $a = $this->owned($this->agent, 'Alpha Road', 'Uvongo');
        $b = $this->owned($this->agent, 'Beta Road', 'Margate');
        $this->move($b, S::APPOINTMENT_SET, null, 'Do the evaluation', '2026-10-20');

        $all = $this->page($this->agent)->getContent();
        $this->assertStringContainsString('5 Alpha Road', $all);
        $this->assertStringContainsString('5 Beta Road', $all);
        $this->assertStringNotContainsString('data-qa="all-stations"', $all);
        $this->assertStringContainsString(route('road-to-sold.index', ['block' => 'prospects', 'p_stage' => 'appointment_set']), html_entity_decode($all), 'the station is a link that filters');

        $one = $this->page($this->agent, ['p_stage' => 'appointment_set'])->getContent();
        $slice = substr($one, (int) strpos($one, 'data-qa="block-prospects"'));
        $this->assertStringContainsString('5 Beta Road', $slice);
        $this->assertStringNotContainsString('<span class="a">5 Alpha Road', $slice);
        $this->assertStringContainsString('class="rts-wide"', $slice, 'the chosen station gets wide chips with all the words');
        $this->assertStringContainsString('Margate', $slice, 'the suburb shows on the wide chip');
        $this->assertStringContainsString('data-qa="all-stations"', $slice);
        $this->assertMatchesRegularExpression('/class="rts-st is-on[^"]*"[^>]*data-station="appointment_set"/', $slice, 'the chosen station is lit');
    }

    public function test_chips_are_sorted_inside_a_station_by_due_days_or_address_and_searched_and_filtered(): void
    {
        $a = $this->owned($this->agent, 'Alpha Road', 'Uvongo');
        $b = $this->owned($this->agent, 'Beta Road', 'Margate');
        $this->prospects->setNextAction($a, 'Call', Carbon::parse('2026-10-25'), $this->agent);
        $this->prospects->setNextAction($b, 'Call', Carbon::parse('2026-10-15'), $this->agent);
        DB::table('rts_prospects')->where('id', $a->id)->update(['stage_entered_at' => '2026-10-01 08:00:00']);   // longest here

        $pos = fn (string $h) => [strpos($h, '5 Alpha Road'), strpos($h, '5 Beta Road')];
        [$al, $be] = $pos($this->column($this->page($this->agent)->getContent(), S::FOUND));
        $this->assertLessThan($al, $be, 'soonest due first by default');
        [$al, $be] = $pos($this->column($this->page($this->agent, ['p_sort' => 'days_in_stage'])->getContent(), S::FOUND));
        $this->assertLessThan($be, $al, 'most days at the station first');
        [$al, $be] = $pos($this->column($this->page($this->agent, ['p_sort' => 'address'])->getContent(), S::FOUND));
        $this->assertLessThan($be, $al, 'by address');

        $slice = fn (array $q) => substr($h = $this->page($this->agent, $q)->assertOk()->getContent(), (int) strpos($h, 'data-qa="prospect-board"'));
        $this->assertStringNotContainsString('5 Beta Road', $slice(['p_q' => 'alpha']));
        $this->assertStringNotContainsString('5 Alpha Road', $slice(['p_suburb' => 'Margate']));
        $this->assertStringNotContainsString('5 Alpha Road', $slice(['p_due' => 'overdue']) , 'nothing is overdue');
        $this->assertStringContainsString('Nothing matches this search.', $slice(['p_q' => 'zzz nothing']));
        // the line's counts are the whole pile, a search only narrows the chips
        $this->assertStringContainsString('data-station="found" data-count="2"', $slice(['p_q' => 'alpha']));
    }

    public function test_an_empty_line_says_how_prospects_start(): void
    {
        $this->page($this->agent)->assertOk()->assertSee("No prospects yet - they start when you work today's intake.", false)->assertSee("Go to today's intake", false);
    }

    // ── the card ────────────────────────────────────────────────────────

    public function test_the_card_draws_the_whole_line_with_the_place_lit_and_says_here_and_next(): void
    {
        $p = $this->owned($this->agent, 'Alpha Road');
        Carbon::setTestNow('2026-10-16 10:00:00');
        $this->move($p, S::APPOINTMENT_SET, null, 'Do the evaluation', '2026-10-20');
        $this->move($p, S::PRESENTATION_GIVEN, null, 'Get the presentation feedback', '2026-10-24');   // skipped Evaluation done

        $html = $this->page($this->agent)->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="prospect-card"', $html);
        $this->assertStringContainsString('data-qa="card-line"', $html);
        $this->assertStringContainsString('data-qa="card-here"', $html);
        $this->assertStringContainsString('<strong>Here:</strong>', $html);
        $this->assertStringContainsString('<strong>next:</strong>', $html);
        $json = $this->pageData($html);
        $this->assertStringContainsString('"stage":"presentation_given"', $json);
        $this->assertStringContainsString('"next":"Get the presentation feedback"', $json);
        $this->assertStringContainsString('"due_words":"Due Sat 24 Oct"', $json);
        // the stations it has been through carry the date it entered them; the one it skipped does not
        $this->assertStringContainsString('"journey":{"found":"2026-10-14","appointment_set":"2026-10-16","presentation_given":"2026-10-16"}', $json);
        $this->assertStringNotContainsString('"evaluation_done":"2026', $json);
        // the station's own button opens the right screen as a pop-up
        $this->assertStringContainsString('prospects/' . $p->id . '/step/', $json);
        $this->assertStringContainsString('rts-step', $html);
    }

    // ── the flow between phases ─────────────────────────────────────────

    /** Four properties with a month of movement: one crossed one boundary, one skipped a phase, one set aside, one lost, one registered. */
    private function monthOfMovement(): array
    {
        $a = $this->owned($this->agent, 'Alpha Road');   $this->move($a, S::PRESENTATION_GIVEN);                    // Get stock -> Win
        $b = $this->owned($this->agent, 'Beta Road');    $this->move($b, S::LISTING_LIVE);                          // skipped Win: crosses two boundaries
        $c = $this->owned($this->agent, 'Gamma Road');   $this->prospects->park($c, 'not_selling_now', Carbon::parse('2026-11-30'), null, $this->agent);   // fell out of Get stock
        $d = $this->owned($this->agent, 'Delta Road');   $this->move($d, S::LEAD); $this->prospects->close($d, 'do_not_contact', $this->agent);   // fell out of Sell it
        $e = $this->owned($this->agent, 'Epsilon Road'); $this->move($e, S::REGISTERED);                           // closes as registered: not a fall-out

        return compact('a', 'b', 'c', 'd', 'e');
    }

    public function test_the_flow_counts_who_moved_on_and_who_fell_out_this_month_per_phase(): void
    {
        $this->monthOfMovement();
        $phases = collect(app(PipelineLineService::class)->phases($this->agent, $this->agent->id, null, Carbon::parse('2026-10-01'), Carbon::parse(self::WED)))->keyBy('key');

        // moved on from Get stock: a, b, d (Found -> Lead), e (Found -> Registered) = 4;   out of Get stock: c
        $this->assertSame([4, 1], [$phases['get_stock']['moved'], $phases['get_stock']['out']]);
        // out of Win the mandate: nobody;  moved on from it: b (skipped through it), d, e
        $this->assertSame([3, 0], [$phases['win']['moved'], $phases['win']['out']]);
        // Market it: b did not leave it;  d (Found -> Lead) and e crossed it
        $this->assertSame([2, 0], [$phases['market']['moved'], $phases['market']['out']]);
        // Sell it: e crossed it; d fell out of it (closed, not a registration)
        $this->assertSame([1, 1], [$phases['sell']['moved'], $phases['sell']['out']]);
        // Close it: one registered; the registration is not a fall-out
        $this->assertSame([1, 0], [$phases['close']['moved'], $phases['close']['out']]);
        $this->assertTrue($phases['close']['last']);
    }

    public function test_the_flow_only_counts_this_month_and_a_property_counts_once_per_boundary(): void
    {
        Carbon::setTestNow('2026-09-20 09:00:00');
        $old = $this->owned($this->agent, 'Old Road');
        $this->move($old, S::PRESENTATION_GIVEN);
        Carbon::setTestNow(self::WED . ' 09:00:00');
        $p = $this->owned($this->agent, 'Alpha Road');
        $this->move($p, S::APPOINTMENT_SET);
        $this->move($p, S::PRESENTATION_GIVEN);
        $this->move($p, S::PRESENTATION_FEEDBACK);
        $phases = collect(app(PipelineLineService::class)->phases($this->agent, $this->agent->id, null, Carbon::parse('2026-10-01'), Carbon::parse(self::WED)))->keyBy('key');

        $this->assertSame(1, $phases['get_stock']['moved'], 'September does not count and the property counts once however many stations it passed');
        $this->assertSame(0, $phases['win']['moved'], 'Presentation given -> feedback stays inside the phase');
    }

    public function test_the_line_shows_the_flow_figures_on_the_phase_boundaries_and_clicking_one_lists_who(): void
    {
        $x = $this->monthOfMovement();
        $html = $this->page($this->agent)->getContent();
        $this->assertSame(1, preg_match('/data-qa="flow-get_stock">.*?data-flow="moved"[^>]*>→4<.*?data-flow="out"[^>]*>✕1</s', $html), 'Get stock: 4 moved on, 1 fell out');
        $this->assertSame(1, preg_match('/data-qa="flow-close">.*?data-flow="moved"[^>]*>✓1</s', $html), 'the last phase reads registered');
        $this->assertStringContainsString('1 fell out of Get stock this month (set aside, not selling or lost)', $html);

        $out = $this->page($this->agent, ['p_flow' => 'out', 'p_flow_phase' => 'get_stock'])->getContent();
        $this->assertStringContainsString('data-qa="flow-list"', $out);
        $this->assertStringContainsString('data-flow-row="' . $x['c']->id . '"', $out);
        $this->assertStringContainsString('5 Gamma Road', $out);
        $this->assertStringContainsString('Set aside - Not selling now', $out, 'where it is now');
        $this->assertStringNotContainsString('5 Alpha Road', substr($out, (int) strpos($out, 'data-qa="flow-list"')), 'only the ones that fell out');
        $this->assertStringContainsString('data-qa="back-to-line"', $out);

        $moved = $this->page($this->agent, ['p_flow' => 'moved', 'p_flow_phase' => 'get_stock'])->getContent();
        foreach (['a', 'b', 'd', 'e'] as $k) {
            $this->assertStringContainsString('data-flow-row="' . $x[$k]->id . '"', $moved);
        }
        $this->assertStringContainsString('Closed - do not contact', $moved);
        $this->assertStringNotContainsString('data-flow-row="' . $x['c']->id . '"', $moved);
    }

    // ── the manager and scoping ─────────────────────────────────────────

    public function test_a_manager_sees_the_line_for_an_agent_the_whole_branch_and_nothing_is_writable(): void
    {
        $a = $this->owned($this->agent, 'Alpha Road');
        $m = $this->owned($this->mate, 'Mate Road');
        $this->prospects->setNextAction($a, 'Call', Carbon::parse('2026-10-01'), $this->agent);   // overdue

        $one = $this->page($this->manager, ['agent' => $this->agent->id])->assertOk()->getContent();
        $this->assertStringContainsString('5 Alpha Road', $one);
        $this->assertStringNotContainsString('5 Mate Road', $one);
        $this->assertStringContainsString('data-qa="line-who"', $one, 'the manager may switch to the branch');
        $this->assertStringContainsString('Whole branch - Main', $one);
        $this->assertStringNotContainsString('Whole agency', $one, 'a branch manager is not offered the agency');
        $this->assertStringContainsString('!1', $one, 'stalled / overdue per station for a manager too');

        $branch = $this->page($this->manager, ['agent' => $this->agent->id, 'p_who' => 'branch'])->assertOk()->getContent();
        $this->assertStringContainsString('5 Alpha Road', $branch);
        $this->assertStringContainsString('5 Mate Road', $branch, 'the branch sum includes the colleague');
        $this->assertStringContainsString('data-station="found" data-count="2"', $branch);
        $this->assertStringContainsString('Whole branch - Main - read only', $branch);
        foreach ([$one, $branch] as $h) {
            $this->assertStringNotContainsString('data-qa="prospect-step"', $h);
            $this->assertStringNotContainsString('data-qa="prospect-move"', $h);
            $this->assertStringNotContainsString('Record a result', $h);
        }
        // a manager's own page in branch mode is still read only (the chips are other people's)
        $own = $this->page($this->manager, ['p_who' => 'branch'])->assertOk()->getContent();
        $this->assertStringNotContainsString('data-qa="prospect-step"', $own);
        // the routes stay shut
        $this->actingAs($this->manager)->post(route('road-to-sold.prospects.capture', $a->id), ['stage' => 'contacted', 'note' => 'x', 'next_label' => 'y', 'next_at' => '2026-10-20'])->assertForbidden();
    }

    public function test_an_agent_cannot_widen_the_line_to_the_branch_or_see_a_colleague(): void
    {
        $this->owned($this->agent, 'Alpha Road');
        $this->owned($this->mate, 'Mate Road');
        $html = $this->page($this->agent, ['p_who' => 'branch'])->assertOk()->getContent();
        $this->assertStringContainsString('5 Alpha Road', $html);
        $this->assertStringNotContainsString('5 Mate Road', $html);
        $this->assertStringNotContainsString('data-qa="line-who"', $html);
        // an agent of the same branch is outside an own-level breadth: the address is not a dead page, it just shows the agent's own line
        $same = $this->page($this->agent, ['agent' => $this->mate->id])->assertOk()->getContent();
        $this->assertStringContainsString('5 Alpha Road', $same);
        $this->assertStringNotContainsString('5 Mate Road', $same);
        $this->page($this->agent, ['p_who' => 'agency'])->assertOk();
    }

    public function test_the_flow_and_the_counts_never_cross_to_another_agency(): void
    {
        $this->owned($this->agent, 'Alpha Road');
        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);
        $otherBranch = DB::table('branches')->insertGetId(['agency_id' => $other->id, 'name' => 'Theirs', 'created_at' => now(), 'updated_at' => now()]);
        $stranger = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $otherBranch, 'role' => 'agent', 'is_active' => true]);
        $tpId = DB::table('tracked_properties')->insertGetId([
            'agency_id' => $other->id, 'external_id' => 'ext-' . uniqid(), 'street_number' => '9', 'street_name' => 'Hidden Road', 'suburb' => 'Elsewhere', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $theirs = DB::table('rts_prospects')->insertGetId([
            'agency_id' => $other->id, 'branch_id' => $otherBranch, 'track' => 'sales', 'user_id' => $stranger->id, 'tracked_property_id' => $tpId,
            'stage' => S::LISTING_LIVE, 'stage_entered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rts_prospect_events')->insert([
            'agency_id' => $other->id, 'track' => 'sales', 'prospect_id' => $theirs, 'user_id' => $stranger->id, 'occurred_at' => now(), 'kind' => 'stage',
            'from_stage' => S::FOUND, 'to_stage' => S::LISTING_LIVE, 'created_at' => now(),
        ]);

        $html = $this->page($this->agent)->getContent();
        $this->assertStringNotContainsString('Hidden Road', $html);
        $phases = collect(app(PipelineLineService::class)->phases($this->agent, null, null, Carbon::parse('2026-10-01'), Carbon::parse(self::WED)))->keyBy('key');
        $this->assertSame(0, $phases['get_stock']['moved'], 'the other agency\'s movement is not in the figures');
        $this->assertSame(0, $phases['market']['total']);
        $this->page($this->agent, ['agent' => $stranger->id])->assertOk()->assertDontSee('Hidden Road');   // an unknown agent id is ignored for an agent, never a 404
    }

    // ── moving by hand ──────────────────────────────────────────────────

    public function test_a_manual_move_needs_what_happened_a_next_action_and_a_date_and_leaves_a_history_line(): void
    {
        $p = $this->owned($this->agent, 'Alpha Road');
        $post = fn (array $d) => $this->actingAs($this->agent)->post(route('road-to-sold.prospects.capture', $p->id), $d);

        $post(['stage' => 'presentation_given'])->assertSessionHasErrors(['note', 'next_label', 'next_at']);
        $post(['stage' => 'presentation_given', 'note' => 'Presented at the kitchen table'])->assertSessionHasErrors(['next_label', 'next_at']);
        $this->assertSame(S::FOUND, $p->fresh()->stage, 'nothing saved on a refusal');

        $post(['stage' => 'presentation_given', 'note' => 'Presented at the kitchen table', 'next_label' => 'Get the feedback', 'next_at' => '2026-10-21'])->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame([S::PRESENTATION_GIVEN, 'Get the feedback', '2026-10-21'], [$p->stage, $p->next_action_label, $p->next_action_at->toDateString()]);
        $this->assertSame('Presented at the kitchen table', DB::table('rts_prospect_events')->where('prospect_id', $p->id)->where('to_stage', S::PRESENTATION_GIVEN)->value('note'));

        $html = $this->page($this->agent)->getContent();
        $this->assertStringContainsString('data-qa="prospect-move"', $html, 'the card offers Move to...');
        $this->assertStringContainsString('data-qa="move-modal"', $html);
        $this->assertStringContainsString('Use this only for work CoreX does not see.', $html);
    }
}
