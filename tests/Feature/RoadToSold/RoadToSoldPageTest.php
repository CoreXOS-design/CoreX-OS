<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-454 (lane 3) - the Road to Sold page and the "Contacted client" capture over HTTP.
 * Spec: .ai/specs/road-to-sold.md section 10.5.
 *
 * Paths proven: the day's list renders (address, price, days on market, listing agency, why, owner +
 * phone, last outcome); a no-owner row offers "Find the owner"; search by address / owner phone;
 * status filter; the four views; own scope hides another agent's list by direct URL (404); a branch
 * manager can open an agent in the branch; capture over HTTP (happy, refused, missing outcome,
 * another agent's list item = 404, list row marked worked); no access key = 403; empty states.
 * Needs lane 2's rts_list_items table - skipped until it is in the schema.
 */
final class RoadToSoldPageTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('rts_list_items')) {
            $this->markTestSkipped('rts_list_items (lane 2) is not in the schema yet.');
        }
        OutcomeDefinitionService::forgetMemo();
        SettingsService::forget();
        Carbon::setTestNow('2026-10-14 09:00:00');

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true, 'name' => 'Anna Agent']);
        $this->other = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true, 'name' => 'Bheki Other']);
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agencyId);

        // A populated grants table (an empty one permits everything in tests): agents hold the page key + own breadth.
        foreach (['agent' => 'own', 'branch_manager' => 'branch'] as $role => $scope) {
            RolePermission::create(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agencyId]);
            RolePermission::create(['role' => $role, 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agencyId, 'scope' => $scope]);
        }
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(string $first = 'Pieter', string $phone = '0825512345'): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'first_name' => $first, 'last_name' => 'van der Merwe', 'phone' => $phone,
        ]);
    }

    private function tp(string $street, ?int $ownerId = null, string $suburb = 'Uvongo'): TrackedProperty
    {
        return TrackedProperty::create([
            'agency_id' => $this->agencyId, 'street_number' => '14', 'street_name' => $street, 'suburb' => $suburb,
            'town' => 'Margate', 'owner_contact_id' => $ownerId, 'last_known_asking_price' => 1250000,
        ]);
    }

    private function item(User $user, TrackedProperty $tp, array $extra = []): RtsListItem
    {
        return RtsListItem::withoutGlobalScopes()->create($extra + [
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'user_id' => $user->id,
            'list_date' => '2026-10-14', 'track' => 'sales', 'tracked_property_id' => $tp->id,
            'selection' => 'price_drops', 'origin' => 'auto', 'position' => 1, 'status' => 'open', 'reason' => 'Price dropped 8% in 10 days',
        ]);
    }

    private function def(string $key): Def
    {
        return Def::withoutGlobalScopes()->where('agency_id', $this->agencyId)->where('key', $key)->firstOrFail();
    }

    public function test_the_days_list_shows_what_the_agent_needs_to_phone(): void
    {
        $owner = $this->owner();
        $withOwner = $this->tp('Marine Drive', $owner->id);
        $noOwner = $this->tp('Ocean View Road');
        DB::table('prospecting_listings')->insert([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'captured_by_user_id' => $this->agent->id,
            'portal_source' => 'p24', 'portal_ref' => 'P24-' . Str::random(6), 'portal_url' => 'https://example.test/x',
            'address' => '14 Marine Drive', 'suburb' => 'Uvongo', 'tracked_property_id' => $withOwner->id,
            'price' => 1495000, 'agency_name' => 'Seeff Margate', 'is_active' => true,
            'first_seen_at' => now()->subDays(40), 'last_seen_at' => now(), 'listed_date' => now()->subDays(33)->toDateString(),
            'listed_date_source' => 'portal', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->item($this->agent, $withOwner, ['position' => 1]);
        $this->item($this->agent, $noOwner, ['position' => 2, 'selection' => 'long_on_market', 'reason' => 'On the market 120 days']);

        $res = $this->actingAs($this->agent)->get(route('road-to-sold.index'));

        $res->assertOk();
        $res->assertSee('Marine Drive')->assertSee('Ocean View Road')->assertSee('Uvongo');
        $res->assertSee('R 1,495,000')->assertSee('R 1,250,000');
        $res->assertSee('Seeff Margate')->assertSee('Price dropped 8% in 10 days')->assertSee('On the market 120 days');
        $res->assertSee('Pieter van der Merwe')->assertSee('0825512345');
        $res->assertSee('Find the owner')->assertSee(route('seller-outreach.entry.from-tracked-property', $noOwner->id), false);
        $res->assertSee('0 of 2')->assertSee('Contacted client');
        $this->assertSame(2, substr_count($res->getContent(), 'data-tp='));
    }

    public function test_search_status_and_view_filters(): void
    {
        $owner = $this->owner('Sipho', '0731112222');
        $a = $this->tp('Marine Drive', $owner->id);
        $b = $this->tp('Ocean View Road', null, 'Margate');
        $b->update(['town' => 'Shelly Beach']);
        $this->item($this->agent, $a, ['position' => 1]);
        $this->item($this->agent, $b, ['position' => 2, 'status' => 'worked']);

        $get = fn (array $q) => $this->actingAs($this->agent)->get(route('road-to-sold.index', $q));

        $get([])->assertSee('Marine Drive')->assertDontSee('Ocean View Road');                       // open only by default
        $get(['status' => 'all'])->assertSee('Marine Drive')->assertSee('Ocean View Road');
        $get(['status' => 'worked'])->assertDontSee('Marine Drive')->assertSee('Ocean View Road');
        $get(['status' => 'all', 'q' => 'ocean'])->assertSee('Ocean View Road')->assertDontSee('Marine Drive');
        $get(['status' => 'all', 'q' => 'Shelly'])->assertSee('Ocean View Road')->assertDontSee('Marine Drive');   // town
        $get(['status' => 'all', 'q' => '0731112222'])->assertSee('Marine Drive')->assertDontSee('Ocean View Road'); // owner phone
        $get(['status' => 'all', 'q' => 'Sipho'])->assertSee('Marine Drive')->assertDontSee('Ocean View Road');      // owner name
        $get(['status' => 'all', 'view' => 'no_owner'])->assertSee('Ocean View Road')->assertDontSee('Marine Drive');
        $get(['status' => 'all', 'selection' => 'price_drops'])->assertSee('Marine Drive');
        $get(['status' => 'all', 'q' => 'zzz-nothing'])->assertSee('Nothing matches this search.');
        $get(['sort' => 'address', 'dir' => 'desc', 'status' => 'all'])->assertOk();
        $get(['sort' => 'nonsense', 'dir' => 'sideways', 'view' => 'bogus', 'status' => 'weird'])->assertOk();   // absorbed
    }

    public function test_an_empty_day_says_why(): void
    {
        $this->actingAs($this->agent)->get(route('road-to-sold.index'))
            ->assertOk()->assertSee('No list for today.');
    }

    public function test_capture_over_http_saves_the_outcome_and_marks_the_row_worked(): void
    {
        $owner = $this->owner();
        $tp = $this->tp('Marine Drive', $owner->id);
        $item = $this->item($this->agent, $tp);

        $res = $this->actingAs($this->agent)->post(route('road-to-sold.outcomes.record'), [
            'tracked_property_id' => $tp->id, 'list_item_id' => $item->id,
            'outcome_definition_id' => $this->def('no_answer')->id, 'note' => 'Rang twice',
        ]);

        $res->assertRedirect()->assertSessionHas('status');
        $row = RtsContactOutcome::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->agent->id, (int) $row->user_id);
        $this->assertSame($item->id, (int) $row->list_item_id);
        $this->assertSame('worked', RtsListItem::withoutGlobalScopes()->find($item->id)->status);

        $page = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['status' => 'all']));
        $page->assertSee('No answer')->assertSee('1 of 1');

        // The "No answer" view finds it any day (owner phones back) and still offers Contacted client.
        $this->actingAs($this->agent)->get(route('road-to-sold.index', ['view' => 'no_answer', 'date' => '2026-11-30']))
            ->assertSee('Marine Drive')->assertSee('Contacted client');
    }

    public function test_wrong_number_view_lists_the_tagged_property(): void
    {
        $tp = $this->tp('Marine Drive', $this->owner()->id);
        $this->actingAs($this->agent)->post(route('road-to-sold.outcomes.record'), [
            'tracked_property_id' => $tp->id, 'outcome_definition_id' => $this->def('wrong_number')->id,
        ])->assertRedirect();

        $this->actingAs($this->agent)->get(route('road-to-sold.index', ['view' => 'wrong_number']))
            ->assertSee('Marine Drive')->assertSee('Find the right contact');
        $this->actingAs($this->agent)->get(route('road-to-sold.index', ['view' => 'no_answer']))
            ->assertDontSee('Marine Drive');   // a wrong number is an attempt with no no-answer run, so not on this view
    }

    public function test_capture_refusals_come_back_in_plain_words(): void
    {
        $tp = $this->tp('Marine Drive');

        // missing outcome
        $this->actingAs($this->agent)->post(route('road-to-sold.outcomes.record'), ['tracked_property_id' => $tp->id])
            ->assertSessionHasErrors('outcome_definition_id');

        // picked date missing
        $this->actingAs($this->agent)->post(route('road-to-sold.outcomes.record'), [
            'tracked_property_id' => $tp->id, 'outcome_definition_id' => $this->def('spoke_call_back')->id,
        ])->assertSessionHas('rts_error', 'Pick the date this property should come back.');

        // held by another agent
        $this->actingAs($this->other)->post(route('road-to-sold.outcomes.record'), [
            'tracked_property_id' => $tp->id, 'outcome_definition_id' => $this->def('no_answer')->id,
        ])->assertSessionHas('status');
        $this->actingAs($this->agent)->post(route('road-to-sold.outcomes.record'), [
            'tracked_property_id' => $tp->id, 'outcome_definition_id' => $this->def('no_answer')->id,
        ])->assertSessionHas('rts_error');

        $this->assertSame(1, RtsContactOutcome::withoutGlobalScopes()->count(), 'only the first capture was saved');
    }

    public function test_another_agents_list_row_cannot_be_used_by_direct_post(): void
    {
        $tp = $this->tp('Marine Drive');
        $theirs = $this->item($this->other, $tp);

        $this->actingAs($this->agent)->post(route('road-to-sold.outcomes.record'), [
            'tracked_property_id' => $tp->id, 'list_item_id' => $theirs->id,
            'outcome_definition_id' => $this->def('no_answer')->id,
        ])->assertNotFound();
        $this->assertSame(0, RtsContactOutcome::withoutGlobalScopes()->count());
    }

    public function test_own_scope_hides_another_agents_list_by_direct_url_and_branch_scope_can_open_it(): void
    {
        $tp = $this->tp('Marine Drive');
        $this->item($this->other, $tp);

        $this->actingAs($this->agent)->get(route('road-to-sold.index', ['agent' => $this->other->id]))->assertNotFound();

        $manager = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'branch_manager', 'is_active' => true]);
        PermissionService::clearCache();
        $res = $this->actingAs($manager)->get(route('road-to-sold.index', ['agent' => $this->other->id]));
        $res->assertOk()->assertSee('Marine Drive')->assertSee('Bheki Other');
        $res->assertDontSee('data-tp=', false);   // looking at someone else's list is read-only: no Contacted client buttons
    }

    public function test_a_second_agencys_list_never_shows(): void
    {
        $tp = $this->tp('Marine Drive');
        $this->item($this->agent, $tp);

        $bAgency = (int) DB::table('agencies')->insertGetId(['name' => 'Other ' . Str::random(4), 'slug' => 'o-' . Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $bAgency, 'agency_id' => $bAgency, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $bAgent = User::factory()->create(['agency_id' => $bAgency, 'branch_id' => $bAgency, 'role' => 'agent', 'is_active' => true]);
        foreach (['access_road_to_sold', 'road_to_sold.view'] as $k) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $k, 'agency_id' => $bAgency, 'scope' => 'own']);
        }
        PermissionService::clearCache();

        $this->actingAs($bAgent)->get(route('road-to-sold.index'))->assertOk()->assertDontSee('Marine Drive');
        $this->actingAs($bAgent)->get(route('road-to-sold.index', ['agent' => $this->agent->id]))->assertNotFound();
    }

    public function test_the_three_blocks_under_the_list_show_and_do_not_count_against_the_target(): void
    {
        Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'created_by_user_id' => $this->other->id,
            'agent_id' => $this->agent->id, 'is_buyer' => true, 'buyer_state' => 'warm', 'first_name' => 'Thandi', 'last_name' => 'Dlamini',
            'phone' => '0821230000', 'last_activity_at' => now()->subDays(45),
        ]);
        $this->item($this->agent, $this->tp('Marine Drive'));

        $res = $this->actingAs($this->agent)->get(route('road-to-sold.index'));

        $res->assertOk()->assertSee('Buyers to follow up')->assertSee('Enquiries waiting')->assertSee('Mandates ending soon');
        $res->assertSee('Thandi Dlamini')->assertSee('No contact for 45 days');
        $res->assertSee('0 of 1', false);   // the feed rows are not part of the day's target
    }

    public function test_a_rows_history_is_one_click_away_and_shows_only_what_the_viewers_breadth_allows(): void
    {
        $tp = $this->tp('Marine Drive', $this->owner()->id);
        $this->item($this->agent, $tp);
        $mk = fn (User $by, string $label, string $note) => RtsContactOutcome::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'track' => 'sales', 'user_id' => $by->id,
            'tracked_property_id' => $tp->id, 'outcome_key' => 'x', 'outcome_label' => $label, 'kind' => 'conversation',
            'note' => $note, 'next_action' => 'none', 'is_current' => false, 'occurred_at' => '2026-10-10 10:00:00',
        ]);
        $mk($this->agent, 'Spoke - call back', 'Wants to wait for the school holidays');
        $mk($this->other, 'Spoke - not interested', 'Another agent private note');

        $own = $this->actingAs($this->agent)->get(route('road-to-sold.index'));
        $own->assertOk()->assertSee('openHistory', false)->assertSee('Wants to wait for the school holidays')
            ->assertDontSee('Another agent private note');

        $branchManager = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'branch_manager', 'is_active' => true]);
        $this->actingAs($branchManager)->get(route('road-to-sold.index', ['agent' => $this->agent->id]))
            ->assertOk()->assertSee('Wants to wait for the school holidays')->assertSee('Another agent private note');

        $bare = $this->tp('Ocean View Road');
        $this->item($this->agent, $bare, ['position' => 2]);
        $this->assertSame(1, substr_count($this->actingAs($this->agent)->get(route('road-to-sold.index'))->getContent(), '@click="openHistory($el.dataset)"'),
            'only the row with history offers the History button');
    }

    public function test_a_user_without_the_page_key_is_refused(): void
    {
        $viewer = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'viewer', 'is_active' => true]);
        RolePermission::create(['role' => 'viewer', 'permission_key' => 'view_dashboard', 'agency_id' => $this->agencyId]);
        PermissionService::clearCache();

        $this->actingAs($viewer)->get(route('road-to-sold.index'))->assertForbidden();
        $this->actingAs($viewer)->post(route('road-to-sold.outcomes.record'), [])->assertForbidden();
    }
}
