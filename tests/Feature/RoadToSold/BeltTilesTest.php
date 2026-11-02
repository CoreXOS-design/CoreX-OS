<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Contact;
use App\Models\ProspectingClaim;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\BeltService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** Road to Sold v4 (spec 23.13): the belt tile row, the counts from today's data and the lists behind each tile. */
final class BeltTilesTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const WED = '2026-10-14';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::WED . ' 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        $this->manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true, 'name' => 'Mia Manager']);
        foreach (['agent' => 'own', 'branch_manager' => 'branch'] as $role => $scope) {
            RolePermission::create(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
            RolePermission::create(['role' => $role, 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id, 'scope' => $scope]);
        }
        PermissionService::clearCache();
        // the agent already works Road to Sold (one prospect): counts as started, so the tiles show
        app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '1', 'street_name' => 'Start Road'])->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function page(User $as, array $q = [])
    {
        return $this->actingAs($as)->get(route('road-to-sold.index', $q));
    }

    private function owner(User $agent, string $name = 'Piet'): int
    {
        return Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $agent->id, 'agent_id' => $agent->id,
            'first_name' => $name, 'last_name' => 'Owner', 'phone' => '082' . random_int(1000000, 9999999)])->id;
    }

    /** an open claim by $agent on a listing whose property has (or has not) an owner */
    private function claim(User $agent, string $street, bool $owner, ?Carbon $since = null): ProspectingClaim
    {
        $tp = $this->tp(['street_number' => '9', 'street_name' => $street] + ($owner ? ['owner_contact_id' => $this->owner($agent), 'deeds_captured_at' => now()] : []));
        $listing = $this->listing(['address' => '9 ' . $street . ', Margate', 'portal_ref' => 'P24-' . random_int(100000000, 999999999)], $tp);

        $claim = ProspectingClaim::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $listing, 'user_id' => $agent->id, 'status' => 'claimed',
            'claimed_at' => $since ?? now()->subDay(), 'last_updated_at' => $since ?? now()->subDay(), 'is_active' => true]);
        if ($owner) {
            // v4 A2: a claim whose property already has its owner and deed is at Owner found, not Claimed
            app(\App\Services\RoadToSold\OwnerStationService::class)->evaluate($this->agency->id, (int) $tp->id);
        }

        return $claim;
    }

    public function test_the_tile_row_shows_the_built_stations_in_belt_order_with_todays_counts(): void
    {
        $this->claim($this->agent, 'Claimed Road', false);
        $this->claim($this->agent, 'Owner Road', true);
        $this->claim($this->mate, 'Colleague Road', false);   // a colleague's claim never counts for this agent
        $this->property($this->agent->id, ['status' => 'draft']);
        $this->property($this->agent->id, ['status' => 'active', 'address' => '2 Active Road']);

        $html = $this->page($this->agent)->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="belt-tiles"', $html);
        $keys = array_column(BeltService::stations(), 'key');
        $this->assertSame(['loaded', 'claimed', 'owner', 'contacted', 'booked', 'prepared', 'held', 'won', 'draft', 'active', 'advertised'], $keys);
        $at = -1;
        foreach ($keys as $k) {
            $pos = strpos($html, 'data-qa="belt-tile-' . $k . '"');
            $this->assertNotFalse($pos, $k);
            $this->assertGreaterThan($at, $pos, "{$k} in belt order");
            $at = $pos;
        }
        $fig = fn (string $k) => preg_match('/data-qa="belt-tile-' . $k . '".*?data-qa="belt-figure">([^<]*)/s', $html, $m) ? trim($m[1]) : null;
        $this->assertSame('1', $fig('claimed'), "the claim with no owner is at Claimed (v4 A1: a claim IS the prospect); a colleague's never counts");
        $this->assertSame('1', $fig('owner'), 'the claim whose owner and deed are on record is at Owner found (v4 A2)');
        $this->assertSame('1', $fig('draft'));
        $this->assertSame('1', $fig('active'), 'active and not on a portal yet');
        $this->assertSame('0', $fig('advertised'));
        $this->assertMatchesRegularExpression('/^\d+ \/ \d+$/', (string) $fig('loaded'), 'stock loaded reads done / target');
        $this->assertStringNotContainsString('data-qa="belt-start-card"', $html, 'a started agent has no Start card');
    }

    public function test_a_stage_station_counts_the_prospects_at_it_and_the_new_stage_keys_are_real_stages(): void
    {
        $p = app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '3', 'street_name' => 'Talk Road'])->id);
        $p->forceFill(['stage' => S::CONTACTED, 'next_action_at' => '2026-10-10'])->save();   // overdue
        $html = $this->page($this->agent)->getContent();
        $this->assertMatchesRegularExpression('/data-qa="belt-tile-contacted".*?data-qa="belt-figure">1<span class="bad" data-qa="belt-alert">!1</s', str_replace("\n", ' ', $html), 'one at Contacted, one needs attention');
        $this->assertStringContainsString('p_stage=contacted', $html, 'the tile opens the existing prospects list at that stage');
        foreach (['CLAIMED' => 'claimed', 'OWNER_FOUND' => 'owner_found', 'PRESENTATION_PREPARED' => 'presentation_prepared', 'DRAFT' => 'draft', 'ACTIVE' => 'active'] as $const => $key) {
            $this->assertSame($key, constant(S::class . '::' . $const));
            $this->assertTrue(S::has($key), "{$key} became a real stage in Lane A1");
        }
    }

    public function test_each_tile_opens_its_list_with_search_scoping_and_an_empty_state(): void
    {
        $this->claim($this->agent, 'Alpha Road', false);
        $this->claim($this->agent, 'Beta Road', false);
        $this->claim($this->mate, 'Mates Road', false);

        $h = $this->page($this->agent, ['block' => 'station', 'station' => 'claimed'])->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="station-list"', $h);
        $this->assertSame(2, substr_count($h, 'data-qa="station-row"'));
        $this->assertStringContainsString('Alpha Road', $h);
        $this->assertStringNotContainsString('Mates Road', $h, "a colleague's claim is not in the list");

        $s = $this->page($this->agent, ['block' => 'station', 'station' => 'claimed', 's_q' => 'beta'])->getContent();
        $this->assertSame(1, substr_count($s, 'data-qa="station-row"'));
        $this->assertStringContainsString('Beta Road', $s);

        $none = $this->page($this->agent, ['block' => 'station', 'station' => 'owner'])->getContent();
        $this->assertStringContainsString('data-qa="station-empty"', $none);
        $this->page($this->agent, ['block' => 'station', 'station' => 'nonsense'])->assertNotFound();

        // draft / active / advertised read the agent's own stock
        $this->property($this->agent->id, ['status' => 'draft', 'address' => '7 Draft Lane']);
        $this->property($this->mate->id, ['status' => 'draft', 'address' => '8 Mates Lane']);
        $d = $this->page($this->agent, ['block' => 'station', 'station' => 'draft'])->getContent();
        $this->assertStringContainsString('7 Draft Lane', $d);
        $this->assertStringNotContainsString('8 Mates Lane', $d);
    }

    public function test_a_presentation_with_no_answer_is_on_the_prepared_tile_and_one_with_an_answer_is_not(): void
    {
        $mk = fn (string $addr) => DB::table('presentations')->insertGetId(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id, 'title' => 'Pres', 'property_address' => $addr, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $mk('11 Waiting Road');
        $answered = $mk('12 Answered Road');
        DB::table('presentation_outcomes')->insert(['presentation_id' => $answered, 'agency_id' => $this->agency->id, 'outcome' => 'won_mandate', 'recorded_by_user_id' => $this->agent->id, 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $html = $this->page($this->agent)->getContent();
        $this->assertMatchesRegularExpression('/data-qa="belt-tile-prepared".*?data-qa="belt-figure">1</s', $html);
        $l = $this->page($this->agent, ['block' => 'station', 'station' => 'prepared'])->getContent();
        $this->assertStringContainsString('11 Waiting Road', $l);
        $this->assertStringNotContainsString('12 Answered Road', $l);
    }

    public function test_evaluations_that_took_place_with_no_feedback_are_owed_and_show_on_the_held_tile(): void
    {
        $base = ['agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'category' => 'property_evaluation', 'event_type' => 'meeting', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now()];
        $owed = DB::table('calendar_events')->insertGetId($base + ['title' => 'Evaluation: 5 Owed Road', 'event_date' => now()->subDays(3)]);
        $given = DB::table('calendar_events')->insertGetId($base + ['title' => 'Evaluation: 6 Given Road', 'event_date' => now()->subDays(2)]);
        DB::table('calendar_event_feedback')->insert(['calendar_event_id' => $given, 'agency_id' => $this->agency->id, 'captured_at' => now(), 'captured_by_user_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('calendar_events')->insert($base + ['title' => 'Evaluation: 7 Future Road', 'event_date' => now()->addDays(2)]);

        $html = $this->page($this->agent)->getContent();
        $this->assertStringContainsString('1 owed', $html);
        $this->assertStringContainsString('station=held', $html, 'the Held tile opens the owed list first');
        $h = $this->page($this->agent, ['block' => 'station', 'station' => 'held'])->getContent();
        $this->assertStringContainsString('5 Owed Road', $h);
        $this->assertStringNotContainsString('6 Given Road', $h);
        $this->assertStringNotContainsString('7 Future Road', $h);
        $this->assertStringContainsString('capture_feedback=' . $owed, html_entity_decode($h), 'each row opens the feedback form');
    }

    public function test_a_manager_sees_the_agents_tiles_and_an_agent_never_sees_a_colleagues(): void
    {
        $this->claim($this->agent, 'Alpha Road', false);
        $this->claim($this->mate, 'Mates Road', false);
        $m = $this->page($this->manager, ['agent' => $this->agent->id])->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-qa="belt-tile-claimed".*?data-qa="belt-figure">1</s', $m);
        // an agent who types a colleague's id is shown their own page
        $own = $this->page($this->agent, ['agent' => $this->mate->id])->getContent();
        $this->assertMatchesRegularExpression('/data-qa="belt-tile-claimed".*?data-qa="belt-figure">1</s', $own);
        $svc = app(BeltService::class);
        $this->assertSame([], $svc->tiles($this->agent, $this->agent, 'rentals', now()), 'Road to Rented has no belt yet');
    }

    public function test_a_tile_shows_done_over_target_where_the_recipe_sets_one_and_the_count_where_it_does_not(): void
    {
        app(\App\Services\RoadToSold\RecipeService::class)->start($this->agency->id, 'sales');
        $html = str_replace("\n", ' ', $this->page($this->agent)->getContent());
        $fig = fn (string $k) => preg_match('/data-qa="belt-tile-' . $k . '".*?data-qa="belt-figure">([^<]*)/', $html, $m) ? trim($m[1]) : null;
        $this->assertSame('0 / 10', $fig('contacted'), 'a daily recipe target: calls recorded today / 10');
        $this->assertSame('0 / 1', $fig('booked'), 'a weekly target');
        $this->assertSame('0 / 1', $fig('won'), 'a monthly target');
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $fig('claimed'), 'no target for Claimed: the count');
        $this->assertMatchesRegularExpression('/data-qa="belt-tile-booked".*?<span class="sub">this week<\/span>/', $html, 'the period is written under a figure that is not a day');
        $this->assertMatchesRegularExpression('/data-qa="belt-tile-won".*?<span class="sub">this month<\/span>/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-qa="belt-tile-contacted".*?<span class="sub">today<\/span>/', $html, '"today" is the default and is not repeated');
    }

    public function test_the_compare_popover_gives_me_my_branch_and_the_agency_as_figures_only(): void
    {
        $this->claim($this->agent, 'Alpha Road', false);
        $this->claim($this->mate, 'Mates Road', false);
        $other = $this->makeAgent(null, \App\Models\Branch::create(['agency_id' => $this->agency->id, 'name' => 'Other branch']));
        $this->claim($other, 'Far Road', false);
        $stranger = \App\Models\Agency::create(['name' => 'Else ' . uniqid(), 'slug' => 'else-' . uniqid()]);
        $theirs = $this->makeAgent($stranger, \App\Models\Branch::create(['agency_id' => $stranger->id, 'name' => 'B']));
        ProspectingClaim::withoutGlobalScopes()->create(['agency_id' => $stranger->id, 'prospecting_listing_id' => $this->listing([], true, $stranger), 'user_id' => $theirs->id, 'status' => 'claimed', 'claimed_at' => now(), 'is_active' => true]);

        $j = $this->actingAs($this->agent)->getJson(route('road-to-sold.belt.scopes', ['station' => 'claimed']))->assertOk()->json();
        $by = collect($j['scopes'])->keyBy('scope');
        $this->assertSame(['me', 'branch', 'agency'], array_keys($by->all()));
        $this->assertSame(1, $by['me']['now']);
        $this->assertSame(2, $by['branch']['now'], 'my branch: mine and my colleague\'s');
        $this->assertSame(3, $by['agency']['now'], 'the agency: the other branch too, never another agency');
        $this->assertSame('Me', $by['me']['label']);
        $this->assertArrayNotHasKey('rows', $j);
        $this->assertStringNotContainsString('Mates Road', json_encode($j), 'figures only, no rows');

        $this->actingAs($this->agent)->getJson(route('road-to-sold.belt.scopes', ['station' => 'nonsense']))->assertNotFound();
        $this->actingAs($this->agent)->getJson(route('road-to-sold.belt.scopes', ['station' => 'claimed', 'agent' => $other->id]))->assertNotFound();
        $this->actingAs($this->manager)->getJson(route('road-to-sold.belt.scopes', ['station' => 'claimed', 'agent' => $this->agent->id]))->assertOk();
        $html = $this->page($this->agent)->getContent();
        $this->assertStringContainsString('data-qa="belt-compare-claimed"', $html);
        $this->assertStringContainsString('function beltCompare(', $html);
    }
}
