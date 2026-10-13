<?php

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RoadToSold\RtsAgentArea;
use App\Models\RoadToSold\RtsSetting;
use App\Models\RoadToSold\RtsSettingAuditEntry;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\AreaService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\WorkdayService;
use Carbon\Carbon;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * AT-454 - the eight audit gaps in lane 1's files (conductor, 13 Oct 2026; spec 5.6, 5.7, 9.11):
 * A per-portal refresh prompts; B areas match by suburb id + town, never by bare name; C holiday
 * country is an agency setting; D the settings memo cannot go stale in a long process; E the track
 * comes from where the screens are mounted; F rts_settings soft-deletes (audit stays append-only);
 * G the values lane 2 hard-coded are agency settings; H an assistant works the agent's areas.
 */
final class AuditGapsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private User $agent;
    private int $cityMargate;
    private int $cityPort;
    private array $sub = [];

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid(), 'assistants_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'North']);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);

        $this->admin = $this->makeUser('Alice Admin', 'admin');
        $this->agent = $this->makeUser('Retha Kelly', 'agent');

        foreach ([
            ['admin', 'access_road_to_sold', null], ['admin', 'road_to_sold.view', 'all'],
            ['admin', 'road_to_sold.manage_areas', null], ['admin', 'road_to_sold.manage_settings', null],
            ['admin', 'road_to_sold.manage_targets', null],
            ['agent', 'access_road_to_sold', null], ['agent', 'road_to_sold.view', 'own'],
        ] as [$role, $key, $scope]) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();

        $country  = DB::table('p24_countries')->insertGetId(['p24_id' => 9001, 'name' => 'South Africa']);
        $province = DB::table('p24_provinces')->insertGetId(['p24_id' => 9001, 'p24_country_id' => $country, 'name' => 'KwaZulu-Natal']);
        $this->cityMargate = DB::table('p24_cities')->insertGetId(['p24_id' => 9011, 'p24_province_id' => $province, 'name' => 'Margate']);
        $this->cityPort    = DB::table('p24_cities')->insertGetId(['p24_id' => 9012, 'p24_province_id' => $province, 'name' => 'Port Shepstone']);

        // The same suburb name in two of the agency's towns.
        $this->sub['uvongo_margate'] = $this->suburb('Uvongo', $this->cityMargate, 910101);
        $this->sub['uvongo_port']    = $this->suburb('Uvongo', $this->cityPort, 910102);
        $this->sub['manaba']         = $this->suburb('Manaba', $this->cityMargate, 910103);

        foreach ([['Margate', $this->cityMargate, 1], ['Port Shepstone', $this->cityPort, 2]] as [$n, $city, $order]) {
            DB::table('towns')->insert([
                'agency_id' => $this->agency->id, 'name' => $n, 'slug' => Str::slug($n), 'p24_city_id' => $city,
                'display_order' => $order, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function makeUser(string $name, string $role, bool $assistant = false): User
    {
        return User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true,
            'is_assistant' => $assistant, 'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
        ]);
    }

    private function suburb(string $name, int $cityId, int $p24Id): int
    {
        return DB::table('p24_suburbs')->insertGetId([
            'name' => $name, 'slug' => Str::slug($name) . '-' . $p24Id, 'p24_id' => $p24Id,
            'p24_city_id' => $cityId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assign(User $who, int $suburbId, string $track = 'sales'): RtsAgentArea
    {
        return RtsAgentArea::create([
            'agency_id' => $this->agency->id, 'branch_id' => $who->branch_id, 'track' => $track,
            'user_id' => $who->id, 'p24_suburb_id' => $suburbId, 'assigned_by_user_id' => $this->admin->id,
        ]);
    }

    private function tracked(array $cols): int
    {
        return DB::table('tracked_properties')->insertGetId($cols + [
            'agency_id' => $this->agency->id, 'external_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function listing(string $suburb, string $portal, string $seen, ?int $trackedId = null): void
    {
        DB::table('prospecting_listings')->insert([
            'agency_id' => $this->agency->id, 'captured_by_user_id' => $this->admin->id, 'portal_source' => $portal,
            'portal_ref' => 'ref-' . Str::random(8), 'suburb' => $suburb, 'tracked_property_id' => $trackedId,
            'first_seen_at' => $seen, 'last_seen_at' => $seen, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Run AreaService::matchSql against one in-memory row shaped like (tp, pl). */
    private function inArea(array $matches, array $tp, string $plSuburb): bool
    {
        [$sql, $bind] = AreaService::matchSql($matches);
        $row = DB::selectOne(
            "SELECT {$sql} AS hit FROM (SELECT ? AS p24_suburb_id, ? AS suburb_normalised, ? AS p24_city_id, ? AS town) tp, (SELECT ? AS suburb) pl",
            array_merge($bind, [$tp['p24_suburb_id'] ?? null, $tp['suburb_normalised'] ?? null, $tp['p24_city_id'] ?? null, $tp['town'] ?? null, $plSuburb])
        );

        return (int) $row->hit === 1;
    }

    // ── A: per-portal refresh ─────────────────────────────────────────────

    public function test_each_portal_prompts_on_its_own(): void
    {
        $svc = app(AreaService::class);

        $this->assertSame(['pp'], $svc->portalsNeedingRefresh(['p24' => now()->subDay(), 'pp' => null], 7), 'fresh P24 must not hide a missing PP capture');
        $this->assertSame(['p24'], $svc->portalsNeedingRefresh(['p24' => now()->subDays(20), 'pp' => now()->subDay()], 7));
        $this->assertSame(['p24', 'pp'], $svc->portalsNeedingRefresh(['p24' => null, 'pp' => null], 7));
        $this->assertSame([], $svc->portalsNeedingRefresh(['p24' => now()->subDays(2), 'pp' => now()->subDays(3)], 7));
        $this->assertTrue($svc->needsRefresh(['p24' => now()->subDay(), 'pp' => null], 7));
        $this->assertFalse($svc->needsRefresh(['p24' => now()->subDay(), 'pp' => now()->subDays(6)], 7));
    }

    public function test_the_areas_screen_names_the_portal_that_is_stale(): void
    {
        $this->assign($this->agent, $this->sub['manaba']);
        $this->listing('Manaba', 'p24', now()->subDay()->toDateTimeString());

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index'))
            ->assertOk()->assertSee('Needs a refresh: PP')->assertDontSee('Needs a refresh: P24');
    }

    public function test_an_agency_without_private_property_is_never_asked_to_refresh_it(): void
    {
        $this->assertSame(['p24', 'pp'], SettingsService::forAgency($this->agency->id)->refreshPortals(), 'both on by default');

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['refresh_portals' => ['', 'p24']])->assertSessionHasNoErrors();
        SettingsService::forget();
        $portals = SettingsService::forAgency($this->agency->id)->refreshPortals();
        $this->assertSame(['p24'], $portals);

        $svc = app(AreaService::class);
        $this->assertSame([], $svc->portalsNeedingRefresh(['p24' => now()->subDay(), 'pp' => null], 7, $portals), 'PP is not one of ours');
        $this->assertFalse($svc->needsRefresh(['p24' => now()->subDay(), 'pp' => null], 7, $portals));
        $this->assertSame(['p24'], $svc->portalsNeedingRefresh(['p24' => null, 'pp' => null], 7, $portals));

        // The screens: no PP wording, no PP column, and the suburb with a fresh P24 capture is not stale.
        $this->assign($this->agent, $this->sub['manaba']);
        $this->listing('Manaba', 'p24', now()->subDay()->toDateTimeString());
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index'))
            ->assertOk()->assertDontSee('Needs a refresh:')->assertDontSee('· PP', false)->assertDontSee('&middot; PP', false);
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['view' => 'suburbs']))
            ->assertOk()->assertDontSee('Last captured (PP)')->assertSee('Last captured (P24)');

        // Switching it back on asks again.
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['refresh_portals' => ['', 'p24', 'pp']]);
        SettingsService::forget();
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index'))->assertOk()->assertSee('Needs a refresh: PP');
    }

    public function test_at_least_one_portal_must_stay_ticked(): void
    {
        foreach ([[''], ['', 'bogus']] as $bad) {
            $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['refresh_portals' => $bad])->assertSessionHasErrors();
        }
        $this->assertDatabaseMissing('rts_settings', ['agency_id' => $this->agency->id]);
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.index'))->assertOk()->assertSee('Portals we capture from')->assertSee('Private Property');
    }

    // ── B: id + town, not name ────────────────────────────────────────────

    public function test_last_captured_does_not_merge_two_towns_that_share_a_suburb_name(): void
    {
        // A fresh P24 capture of Uvongo in PORT SHEPSTONE (its tracked property says so)...
        $tp = $this->tracked(['suburb' => 'Uvongo', 'suburb_normalised' => 'uvongo', 'p24_suburb_id' => $this->sub['uvongo_port'], 'p24_city_id' => $this->cityPort]);
        $this->listing('Uvongo', 'p24', now()->subDay()->toDateTimeString(), $tp);

        $cap = app(AreaService::class)->lastCaptured($this->agency->id);

        $this->assertNotNull($cap[$this->sub['uvongo_port']]['p24'], 'counted for the suburb it belongs to');
        $this->assertNull($cap[$this->sub['uvongo_margate']]['p24'], 'must not leak onto the same-named suburb in another town');

        // A listing with no tracked property says only "Uvongo": ambiguous in this pool, so it is nobody's.
        $this->listing('Uvongo', 'pp', now()->subDay()->toDateTimeString());
        $cap = app(AreaService::class)->lastCaptured($this->agency->id);
        $this->assertNull($cap[$this->sub['uvongo_margate']]['pp']);
        $this->assertNull($cap[$this->sub['uvongo_port']]['pp']);

        // A name that is unique in the pool is still matched by name when the row has no town.
        $this->listing('Manaba', 'pp', now()->subDays(2)->toDateTimeString());
        $this->assertNotNull(app(AreaService::class)->lastCaptured($this->agency->id)[$this->sub['manaba']]['pp']);
    }

    public function test_area_matching_uses_the_suburb_id_first_and_the_town_for_name_only_rows(): void
    {
        $this->assign($this->agent, $this->sub['uvongo_margate']);
        $matches = app(AreaService::class)->suburbMatchesFor($this->agent);
        $this->assertCount(1, $matches);
        $this->assertFalse($matches[0]['unique_in_pool'], 'two Uvongos in this pool');

        // By id: in / out.
        $this->assertTrue($this->inArea($matches, ['p24_suburb_id' => $this->sub['uvongo_margate']], 'whatever'));
        $this->assertFalse($this->inArea($matches, ['p24_suburb_id' => $this->sub['uvongo_port'], 'suburb_normalised' => 'uvongo'], 'Uvongo'), 'has an id of another suburb - the name must not rescue it');
        // No id, name + city: the right town in, the other town out.
        $this->assertTrue($this->inArea($matches, ['suburb_normalised' => 'uvongo', 'p24_city_id' => $this->cityMargate], 'Uvongo'));
        $this->assertFalse($this->inArea($matches, ['suburb_normalised' => 'uvongo', 'p24_city_id' => $this->cityPort], 'Uvongo'));
        // No id, no city, a town NAME: matched on the town.
        $this->assertTrue($this->inArea($matches, ['suburb_normalised' => 'uvongo', 'town' => 'margate'], 'Uvongo'));
        $this->assertFalse($this->inArea($matches, ['suburb_normalised' => 'uvongo', 'town' => 'Port Shepstone'], 'Uvongo'));
        // Nothing but a name, and the name is shared: not in anyone's area.
        $this->assertFalse($this->inArea($matches, [], 'Uvongo'));

        // A unique name with no town information is matched by name alone.
        $this->assign($this->agent, $this->sub['manaba']);
        $matches = app(AreaService::class)->suburbMatchesFor($this->agent);
        $manaba = collect($matches)->firstWhere('p24_suburb_id', $this->sub['manaba']);
        $this->assertTrue($manaba['unique_in_pool']);
        $this->assertTrue($this->inArea([$manaba], [], 'Manaba'));
        $this->assertSame(['0', []], AreaService::matchSql([]), 'no areas = nothing matches');
    }

    // ── C: holiday country ────────────────────────────────────────────────

    public function test_the_holiday_country_is_an_agency_setting_defaulting_to_za(): void
    {
        $this->assertSame('ZA', SettingsService::forAgency($this->agency->id)->holidayCountry());

        // A weekday that is a holiday only in country XX.
        $monday = Carbon::parse('2027-03-01');
        DB::table('public_holidays')->insert(['country_code' => 'XX', 'holiday_date' => $monday->toDateString(), 'name' => 'Founders Day', 'is_movable' => 0, 'applies_to_year' => 2027]);
        $this->assertSame(5, app(WorkdayService::class)->targetFor($this->agent, $monday), 'ZA agency: not a holiday');

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['public_holiday_country' => 'xx'])->assertSessionHasNoErrors();
        SettingsService::forget();
        $this->assertSame('XX', SettingsService::forAgency($this->agency->id)->holidayCountry());
        $this->assertSame(0, app()->make(WorkdayService::class)->targetFor($this->agent, $monday), 'XX agency: that Monday is a public holiday, no target');
    }

    public function test_a_country_without_holidays_loaded_is_refused(): void
    {
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['public_holiday_country' => 'QQ'])
            ->assertSessionHasErrors('public_holiday_country');
        $this->assertSame('ZA', SettingsService::forAgency($this->agency->id, 'sales', true)->holidayCountry());
    }

    // ── D: the memo cannot go stale in a long process ─────────────────────

    public function test_a_setting_saved_mid_process_is_seen_after_a_reset_hook_or_a_fresh_read(): void
    {
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['hold_days' => '31']);
        SettingsService::forget();
        $this->assertSame(31, SettingsService::forAgency($this->agency->id)->holdDays());

        // Another process saves while this one is mid-run.
        DB::table('rts_settings')->where('agency_id', $this->agency->id)->update(['hold_days' => 45]);

        $this->assertSame(45, SettingsService::forAgency($this->agency->id, 'sales', true)->holdDays(), 'fresh: true re-reads');

        DB::table('rts_settings')->where('agency_id', $this->agency->id)->update(['hold_days' => 60]);
        event(new JobProcessing('sync', new class {
            public function __call($n, $a) { return null; }
        }));
        $this->assertSame(60, SettingsService::forAgency($this->agency->id)->holdDays(), 'a new queued job starts clean');

        DB::table('rts_settings')->where('agency_id', $this->agency->id)->update(['hold_days' => 75]);
        event(new CommandStarting('rts:nightly', new ArrayInput([]), new NullOutput()));
        $this->assertSame(75, SettingsService::forAgency($this->agency->id)->holdDays(), 'a new artisan command starts clean');

        $this->assertSame(15, SettingsService::MEMO_SECONDS, 'and even without a hook the memo lives seconds, not the life of the process');
    }

    // ── E: the track comes from where the screens are mounted ─────────────

    private function mountRentals(): void
    {
        Route::middleware(['web', 'auth', 'feature:road-to-sold'])
            ->prefix('road-to-rented/settings')->name('road-to-rented.settings.')
            ->group(require base_path('routes/road-to-sold-settings.php'));
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    public function test_the_same_screens_serve_the_rentals_track_when_mounted_as_road_to_rented(): void
    {
        $this->mountRentals();

        // Save a setting on the rentals mount: a rentals row appears, the sales row does not.
        $this->actingAs($this->admin)->put(route('road-to-rented.settings.general.update'), ['hold_days' => '12'])
            ->assertSessionHasNoErrors()->assertRedirect(route('road-to-rented.settings.index'));
        SettingsService::forget();
        $this->assertSame(12, SettingsService::forAgency($this->agency->id, 'rentals')->holdDays());
        $this->assertSame(30, SettingsService::forAgency($this->agency->id, 'sales')->holdDays());
        $this->assertDatabaseHas('rts_settings', ['agency_id' => $this->agency->id, 'track' => 'rentals']);
        $this->assertDatabaseMissing('rts_settings', ['agency_id' => $this->agency->id, 'track' => 'sales']);

        // Areas: assigned on the rentals mount belong to the rentals track only; links stay on that mount.
        $this->actingAs($this->admin)->post(route('road-to-rented.settings.areas.sync', $this->agent->id), ['add' => [$this->sub['manaba']]])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, RtsAgentArea::where('track', 'rentals')->count());
        $this->assertSame(0, RtsAgentArea::where('track', 'sales')->count());
        $page = $this->actingAs($this->admin)->get(route('road-to-rented.settings.areas.index'))->assertOk();
        $page->assertSee('road-to-rented/settings', false)->assertDontSee('road-to-sold/settings/areas', false);

        // Targets likewise.
        $this->actingAs($this->admin)->put(route('road-to-rented.settings.targets.agent', $this->agent->id), ['week' => [1 => 9]])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rts_weekday_targets', ['user_id' => $this->agent->id, 'track' => 'rentals', 'weekday' => 1, 'target' => 9]);
        $this->assertDatabaseMissing('rts_weekday_targets', ['user_id' => $this->agent->id, 'track' => 'sales']);
    }

    public function test_the_sales_mount_is_unchanged(): void
    {
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['hold_days' => '31'])->assertRedirect(route('road-to-sold.settings.index'));
        $this->assertDatabaseHas('rts_settings', ['agency_id' => $this->agency->id, 'track' => 'sales', 'hold_days' => 31]);
        $this->assertSame('sales', \App\Services\RoadToSold\Support\Routing::track());
        $this->assertSame('road-to-sold.settings.', \App\Services\RoadToSold\Support\Routing::base());
    }

    // ── F: soft delete on rts_settings; the audit stays append-only ───────

    public function test_back_to_the_defaults_archives_the_row_and_the_next_save_restores_it_from_the_defaults(): void
    {
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['hold_days' => '99', 'lock_agents_to_areas' => '1']);
        $id = RtsSetting::where('agency_id', $this->agency->id)->value('id');

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.general.reset'))->assertRedirect();

        $this->assertSoftDeleted('rts_settings', ['id' => $id]);
        SettingsService::forget();
        $this->assertSame(30, SettingsService::forAgency($this->agency->id)->holdDays());
        $this->assertFalse(SettingsService::forAgency($this->agency->id)->lockAgentsToAreas());
        $this->assertFalse(SettingsService::forAgency($this->agency->id)->isCustomised());

        // Save one thing: the SAME row comes back, holding the defaults plus that one change.
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['new_on_market_days' => '20']);
        SettingsService::forget();
        $this->assertSame(1, RtsSetting::withTrashed()->where('agency_id', $this->agency->id)->count(), 'no duplicate row');
        $this->assertNull(RtsSetting::withTrashed()->find($id)->deleted_at);
        $s = SettingsService::forAgency($this->agency->id);
        $this->assertSame(20, $s->newOnMarketDays());
        $this->assertSame(30, $s->holdDays(), 'back to the default, not the archived 99');
        $this->assertFalse($s->lockAgentsToAreas());

        // Resetting again, and resetting when already on the defaults, are both harmless.
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.general.reset'));
        $before = RtsSettingAuditEntry::count();
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.general.reset'))->assertRedirect();
        $this->assertSame($before, RtsSettingAuditEntry::count(), 'nothing to undo = no audit noise');
    }

    public function test_the_audit_table_is_append_only_it_has_no_soft_delete(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('rts_settings_audit', 'deleted_at'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('rts_settings', 'deleted_at'));

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['hold_days' => '99']);
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.general.reset'));
        $entries = RtsSettingAuditEntry::orderBy('id')->get();
        $this->assertCount(2, $entries, 'the save and the reset are both on the record');
        $this->assertSame(30, $entries[1]->new_values['hold_days']);
        $this->assertSame(99, $entries[1]->old_values['hold_days']);
    }

    // ── G: the values lane 2 hard-coded are agency settings ───────────────

    public function test_lane_two_values_have_neutral_defaults_for_an_agency_that_saved_nothing(): void
    {
        $s = SettingsService::forAgency($this->agency->id);
        $this->assertSame(['perfect', 'strong'], $s->buyerMatchTiers());
        $this->assertSame(['new', 'warm'], $s->activeBuyerStates());
        $this->assertSame(['active', 'for_sale', 'on_show'], $s->onMarketStatuses());
        $this->assertSame([100, 200], [$s->addToDayMax(), $s->scanPageSize()]);
        $this->assertSame([1, 1, 1], [$s->hotPocketWeightBuyers(), $s->hotPocketWeightLeads(), $s->hotPocketWeightSales()]);
    }

    public function test_the_general_screen_saves_the_lists_and_numbers_and_a_second_agency_is_unaffected(): void
    {
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.index'))->assertOk()
            ->assertSee('Buyer matches that count')->assertSee('Approximate')->assertSee('Hottest pockets: weight of a buyer');

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), [
            'buyer_match_tiers' => ['', 'perfect', 'strong', 'approximate'],
            'active_buyer_states' => ['', 'warm'],
            'on_market_statuses' => ['', 'active', 'under_offer'],
            'add_to_day_max' => '40', 'scan_page_size' => '100',
            'hot_pocket_weight_buyers' => '3', 'hot_pocket_weight_leads' => '2', 'hot_pocket_weight_sales' => '0',
        ])->assertSessionHasNoErrors();
        SettingsService::forget();
        $s = SettingsService::forAgency($this->agency->id);
        $this->assertSame(['perfect', 'strong', 'approximate'], $s->buyerMatchTiers());
        $this->assertSame(['warm'], $s->activeBuyerStates());
        $this->assertSame(['active', 'under_offer'], $s->onMarketStatuses());
        $this->assertSame([40, 100, 3, 2, 0], [$s->addToDayMax(), $s->scanPageSize(), $s->hotPocketWeightBuyers(), $s->hotPocketWeightLeads(), $s->hotPocketWeightSales()]);

        $other = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->assertSame(['perfect', 'strong'], SettingsService::forAgency($other->id)->buyerMatchTiers());
        $this->assertSame(100, SettingsService::forAgency($other->id)->addToDayMax());
    }

    public function test_the_lists_refuse_nothing_ticked_and_unknown_choices_and_numbers_are_ranged(): void
    {
        foreach ([
            ['buyer_match_tiers' => ['']],
            ['buyer_match_tiers' => ['', 'bogus']],
            ['on_market_statuses' => ['']],
            ['add_to_day_max' => '0'], ['add_to_day_max' => '501'], ['add_to_day_max' => 'many'],
            ['scan_page_size' => '5'], ['hot_pocket_weight_buyers' => '-1'],
        ] as $bad) {
            $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), $bad)->assertSessionHasErrors();
        }
        $this->assertDatabaseMissing('rts_settings', ['agency_id' => $this->agency->id]);

        // A stored list that has gone stale (a value no longer offered) reads back as the clean subset.
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['hold_days' => '31']);
        DB::table('rts_settings')->where('agency_id', $this->agency->id)->update(['buyer_match_tiers' => json_encode(['gone']), 'on_market_statuses' => json_encode(['under_offer', 'active', 'gone'])]);
        SettingsService::forget();
        $s = SettingsService::forAgency($this->agency->id);
        $this->assertSame(['perfect', 'strong'], $s->buyerMatchTiers(), 'nothing usable = the default');
        $this->assertSame(['active', 'under_offer'], $s->onMarketStatuses(), 'unknown dropped, choice order kept');
    }

    public function test_a_partial_post_leaves_the_lists_alone(): void
    {
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['active_buyer_states' => ['', 'cold']]);
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['hold_days' => '31']);
        SettingsService::forget();
        $this->assertSame(['cold'], SettingsService::forAgency($this->agency->id)->activeBuyerStates());
    }

    // ── H: assistants work the agent's areas ──────────────────────────────

    public function test_an_assistant_works_the_areas_of_the_agent_they_act_for(): void
    {
        $assistant = $this->makeUser('Thandi Mokoena', 'assistant', true);
        AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $assistant->id, 'agent_user_id' => $this->agent->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);
        $this->assign($this->agent, $this->sub['manaba']);

        $svc = app(AreaService::class);
        $this->assertSame([$this->sub['manaba']], $svc->suburbIdsFor($assistant->fresh()));
        $this->assertTrue($svc->hasAreas($assistant->fresh()));
        $this->assertSame(['manaba'], $svc->suburbNamesLowerTrimFor($assistant->fresh()));
        $this->assertCount(1, $svc->suburbMatchesFor($assistant->fresh()));

        // One rule (spec 11.1): the list owner's areas, nothing else - rows assigned to the assistant directly
        // are not used, and the agent does not inherit anything from the assistant.
        $this->assign($assistant, $this->sub['uvongo_margate']);
        $this->assertSame([$this->sub['manaba']], $svc->suburbIdsFor($assistant->fresh()));
        $this->assertSame([$this->sub['manaba']], $svc->suburbIdsFor($this->agent));
    }

    public function test_a_direct_call_with_an_assistant_uses_the_agents_target_and_leave(): void
    {
        $assistant = $this->makeUser('Thandi Mokoena', 'assistant', true);
        AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $assistant->id, 'agent_user_id' => $this->agent->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);
        $monday = Carbon::parse('2027-03-01');
        \App\Models\RoadToSold\RtsWeekdayTarget::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->agent->branch_id, 'track' => 'sales',
            'user_id' => $this->agent->id, 'weekday' => 1, 'target' => 9, 'set_by_user_id' => $this->admin->id,
        ]);

        $this->assertSame(9, app()->make(WorkdayService::class)->targetFor($assistant->fresh(), $monday), 'the agent\'s own Monday number');
        $this->assertSame(9, app()->make(WorkdayService::class)->targetFor($this->agent, $monday));
    }

    public function test_an_assistant_without_an_active_assignment_has_only_their_own_areas(): void
    {
        $assistant = $this->makeUser('Free Assistant', 'assistant', true);
        $this->assign($this->agent, $this->sub['manaba']);

        $this->assertSame([], app(AreaService::class)->suburbIdsFor($assistant->fresh()));
    }
}
