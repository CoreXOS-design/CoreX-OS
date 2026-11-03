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
        // v4 A7: Draft and Active read the stage count - the records the property's own state moved there
        app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '1', 'street_name' => 'Draft Road'])->id, 'sales', ['stage' => S::DRAFT]);
        app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '2', 'street_name' => 'Active Road'])->id, 'sales', ['stage' => S::ACTIVE]);

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
        // Draft and Active read "this month" (the funnel's rule): the record placed at Active also passed Draft, so Draft has 2 and Active 1
        $this->assertSame('2', $fig('draft'));
        $this->assertSame('1', $fig('active'));
        $this->assertSame('0', $fig('advertised'));
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $fig('loaded'), 'stock loaded reads how many are still to do (and "n of N worked" under it)');
        $this->assertStringNotContainsString('data-qa="belt-start-card"', $html, 'a started agent has no Start card');
    }

    public function test_a_stage_station_counts_the_prospects_at_it_and_the_new_stage_keys_are_real_stages(): void
    {
        $p = app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '3', 'street_name' => 'Talk Road'])->id);
        $p->forceFill(['stage' => S::CONTACTED, 'next_action_at' => '2026-10-10'])->save();   // overdue
        $html = $this->page($this->agent)->getContent();
        $flat = str_replace("\n", ' ', $html);
        $this->assertMatchesRegularExpression('/data-qa="belt-tile-contacted".*?data-qa="belt-figure">1</s', $flat, 'one at Contacted');
        $this->assertMatchesRegularExpression('/data-qa="belt-line-contacted-late"[^>]*>1 late</', $flat, 'and its call-back date is past: one late');
        $this->assertStringContainsString('station=contacted', $html, 'the tile opens the HOME list filtered to that station');
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
        app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '7', 'street_name' => 'Draft Lane'])->id, 'sales', ['stage' => S::DRAFT]);
        app(ProspectService::class)->place($this->mate, $this->tp(['street_number' => '8', 'street_name' => 'Mates Lane'])->id, 'sales', ['stage' => S::DRAFT]);
        $d = $this->page($this->agent, ['block' => 'station', 'station' => 'draft'])->getContent();
        $this->assertStringContainsString('7 Draft Lane', $d);
        $this->assertStringNotContainsString('8 Mates Lane', $d);
    }

    public function test_the_prepared_tile_counts_the_records_at_presentation_prepared(): void
    {
        // v4 A4: a presentation generated for the property moves the record, and the tile reads the stage (the list behind it lists only those)
        $p = app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '11', 'street_name' => 'Waiting Road'])->id);
        $other = app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '12', 'street_name' => 'Found Road'])->id);
        app(ProspectService::class)->advance($p, S::PRESENTATION_PREPARED, $this->agent, ['event_class' => 'test']);

        $html = $this->page($this->agent)->getContent();
        $this->assertMatchesRegularExpression('/data-qa="belt-tile-prepared".*?data-qa="belt-figure">1</s', $html);
        $l = $this->page($this->agent, ['block' => 'station', 'station' => 'prepared'])->getContent();
        $this->assertStringContainsString('Waiting Road', $l);
        $this->assertStringNotContainsString('Found Road', $l);
    }

    public function test_evaluations_that_took_place_with_no_feedback_are_owed_and_one_click_from_the_held_list(): void
    {
        $base = ['agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'category' => 'property_evaluation', 'event_type' => 'meeting', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now()];
        $owed = DB::table('calendar_events')->insertGetId($base + ['title' => 'Evaluation: 5 Owed Road', 'event_date' => now()->subDays(3)]);
        $given = DB::table('calendar_events')->insertGetId($base + ['title' => 'Evaluation: 6 Given Road', 'event_date' => now()->subDays(2)]);
        DB::table('calendar_event_feedback')->insert(['calendar_event_id' => $given, 'agency_id' => $this->agency->id, 'captured_at' => now(), 'captured_by_user_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('calendar_events')->insert($base + ['title' => 'Evaluation: 7 Future Road', 'event_date' => now()->addDays(2)]);

        // the Held tile is the conveyor tile now (to do / moved on / late); the owed list is one click inside the Held list
        $held = $this->page($this->agent, ['block' => 'station', 'station' => 'held'])->getContent();
        $this->assertStringContainsString('data-qa="held-owed"', $held);
        $this->assertStringContainsString('1 evaluation owed feedback', $held);
        $h = $this->page($this->agent, ['block' => 'station', 'station' => 'held', 'owed' => 1])->getContent();
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

    public function test_a_tile_keeps_the_recipe_target_in_its_hover_and_reads_the_conveyor_lines(): void
    {
        app(\App\Services\RoadToSold\RecipeService::class)->start($this->agency->id, 'sales');
        $tiles = collect(app(BeltService::class)->tiles($this->agent, $this->agent, 'sales', now()))->keyBy('key');
        // 23.29.3 (Johan, 3 Nov 11:02): a recipe task target IS the hand-set per-step target - it wins over the derived figure, read as a MONTH figure (reached this month / the month's target)
        $wd = app(\App\Services\RoadToSold\TargetsService::class)->workingDays($this->agent, now());
        $this->assertSame('0 / ' . (int) ceil(10 * $wd), $tiles['contacted']['figure'], 'a daily recipe target (10) x the month\'s working days');
        $this->assertSame('0 / ' . (int) ceil(round($wd / 5, 1)), $tiles['booked']['figure'], 'a weekly target x working days / 5');
        $this->assertSame('0 / 1', $tiles['won']['figure'], 'a monthly target as it stands');
        $this->assertSame('recipe', $tiles['contacted']['tgt']['source']);
        $html = str_replace("\n", ' ', $this->page($this->agent)->getContent());
        $this->assertMatchesRegularExpression('/data-qa="belt-line-contacted-moved"[^>]*>0 moved on today</', $html);
        $this->assertMatchesRegularExpression('/data-qa="belt-line-won-moved"[^>]*>0 today</', $html, 'the last four read "this month" and "today"');
        $this->assertMatchesRegularExpression('/data-qa="belt-tile-won"[^>]*><span class="n" data-qa="belt-figure">0<\/span> this month</', $html);
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

    /** Johan, 2 Nov / 23.24: a tile list says where the record is, when its next step is due and what comes next - not a list-item open / closed flag. */
    public function test_a_tile_list_shows_when_the_next_step_is_due_and_the_one_next_action(): void
    {
        $p = app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '73', 'street_name' => 'Marine Drive'])->id);
        $p->forceFill(['stage' => S::APPOINTMENT_SET, 'next_action_key' => 'evaluation_feedback', 'next_action_label' => 'Evaluation feedback', 'next_action_at' => '2026-10-15'])->save();
        DB::table('calendar_events')->insert(['agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'title' => 'Evaluation 73 Marine Drive', 'event_type' => 'meeting', 'category' => 'property_evaluation',
            'event_date' => '2026-10-15 10:00:00', 'status' => 'scheduled', 'rts_prospect_id' => $p->id, 'tracked_property_id' => $p->tracked_property_id, 'created_at' => now(), 'updated_at' => now()]);

        $h = $this->page($this->agent, ['station' => 'booked'])->assertOk()->getContent();
        $this->assertStringContainsString('>Due<', $h);
        $this->assertMatchesRegularExpression('/data-qa="station-due"[^>]*>\s*<div[^>]*>Thu 15 Oct 10:00/', $h, 'a booked evaluation is due when the appointment is, with its time');
        $this->assertMatchesRegularExpression('/data-qa="station-action"[^>]*data-action="Appointment feedback"/', $h, 'Booked: Appointment feedback');
        $this->assertStringNotContainsString('>Open</a>', $h, 'the row button is not a status');
    }
}
