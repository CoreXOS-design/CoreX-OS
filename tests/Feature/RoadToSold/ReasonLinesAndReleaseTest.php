<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Contact;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RoadToSold\RtsSetting;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Prospecting\ProspectingClaimService;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\Reasons\ReasonLine;
use App\Services\RoadToSold\Selections\BuyerSourcingSelection;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v3 - the reason line with numbers and names on every offered property (road-to-sold.md 22.5, R4), stored when it is offered; and
 * giving a property back to the pool (22.6): its own key, a reason, an audit line, and what the agent held in Market Intelligence goes back too.
 */
final class ReasonLinesAndReleaseTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const WED = '2026-10-14';

    private User $manager;
    private ProspectService $prospects;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::WED . ' 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        $this->prospects = app(ProspectService::class);
        $this->agent->forceFill(['name' => 'Anna Agent'])->save();
        $this->mate->forceFill(['name' => 'Mia Mate'])->save();
        $this->manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true, 'name' => 'Mo Manager']);
        foreach (['agent' => 'own', 'branch_manager' => 'branch'] as $role => $scope) {
            RolePermission::create(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
            RolePermission::create(['role' => $role, 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id, 'scope' => $scope]);
        }
        RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'road_to_sold.release_prospects', 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function named(User $agent, string $first, int $listingId, int $score = 85): Contact
    {
        $b = $this->buyer($agent->id);
        $b->forceFill(['first_name' => $first, 'last_name' => 'Smith'])->save();
        $this->buyerMatch($listingId, $b->id, 'strong', $score);

        return $b;
    }

    // ── The words ────────────────────────────────────────────────────────

    public function test_the_wording_helpers(): void
    {
        $this->assertSame('R120k', ReasonLine::money(120000));
        $this->assertSame('R1.2m', ReasonLine::money(1200000));
        $this->assertSame('R950', ReasonLine::money(950));
        $this->assertSame('Piet, Sanet, Jo', ReasonLine::names(['Piet', 'Sanet', 'Jo']));
        $this->assertSame('Piet, Sanet, Jo and 2 more', ReasonLine::names(['Piet', 'Sanet', 'Jo', 'Lize', 'Kobus']));
        $this->assertSame('today', ReasonLine::days(0));
        $this->assertSame('1 day', ReasonLine::days(1));
        $this->assertSame('Hot pocket: 12 sales / 180 days', ReasonLine::hotPocket(['sales' => 12], 180)['line']);
        $this->assertSame('Hot pocket: 12 sales, 8 leads, 1 buyer / 180 days', ReasonLine::hotPocket(['sales' => 12, 'leads' => 8, 'buyers' => 1, 'label' => 'Uvongo'], 180)['line']);
        $this->assertSame('Expired with Pam Golding 12 Oct', ReasonLine::expiredCompetitor('Pam Golding', 'Property24', '2026-10-12')['line']);
        $this->assertSame('Expired on Private Property 12 Oct', ReasonLine::expiredCompetitor(null, 'Private Property', '2026-10-12')['line']);
        $this->assertSame('You found this on 12 Oct (Drive an Area)', ReasonLine::foundByYou('2026-10-12 10:00:00', 'Drive an Area')['line']);
        $this->assertSame('Owner on file: Piet Botha, last called 12 Sep', ReasonLine::ownerKnown('Piet Botha', Carbon::parse('2026-09-12'))['line']);
    }

    public function test_my_buyers_are_named_best_match_first(): void
    {
        $l = $this->listing();
        $this->named($this->agent, 'Piet', $l, 80);
        $this->named($this->agent, 'Sanet', $l, 95);
        $this->named($this->agent, 'Jo', $l, 90);
        $this->named($this->mate, 'Kobus', $l, 99);   // somebody else's buyer: not "your" buyer

        $c = BuyerSourcingSelection::mine()->candidates($this->ctx(), 10);

        $this->assertSame('3 of your buyers match: Sanet, Jo, Piet', $c[0]->reason);
        $this->assertSame('my_buyers', $c[0]->facts['kind']);
        $this->assertSame(3, $c[0]->facts['count']);
        $this->assertSame(95, $c[0]->facts['best_match']);
    }

    public function test_agency_buyers_show_each_buyers_agent(): void
    {
        $l = $this->listing();
        $this->named($this->agent, 'Piet', $l, 90);
        $this->named($this->mate, 'Sanet', $l, 85);

        $c = BuyerSourcingSelection::agency()->candidates($this->ctx(), 10);

        $this->assertSame('Agency has 2 buyers (Piet · agent Anna, Sanet · agent Mia)', $c[0]->reason);
        $this->assertSame('Piet Smith', $c[0]->facts['buyers'][0]['name']);
        $this->assertSame('Anna Agent', $c[0]->facts['buyers'][0]['agent']);
    }

    public function test_price_drop_new_and_long_on_market_have_their_numbers(): void
    {
        $drop = $this->listing(['price' => 1330000]);
        DB::table('prospecting_price_history')->insert(['prospecting_listing_id' => $drop, 'old_price' => 1450000, 'new_price' => 1330000, 'changed_at' => '2026-10-03 09:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('Price drop R120k on 3 Oct', (new \App\Services\RoadToSold\Selections\PriceDropSelection())->candidates($this->ctx(), 5)[0]->reason);

        $new = $this->listing(['listed_date' => '2026-10-10', 'listed_date_source' => 'portal']);
        $c = (new \App\Services\RoadToSold\Selections\NewOnMarketSelection())->candidates($this->ctx(), 10);
        $this->assertContains('New on market 4 days', array_map(fn ($x) => $x->reason, $c));

        $long = $this->listing(['listed_date' => '2026-06-24', 'listed_date_source' => 'portal', 'first_seen_at' => '2026-06-24 08:00:00', 'price' => 1250000]);
        $c = (new \App\Services\RoadToSold\Selections\LongOnMarketSelection())->candidates($this->ctx(), 10);
        $this->assertContains('Long on market 112 days', array_map(fn ($x) => $x->reason, $c));
        $this->assertSame(1250000, collect($c)->firstWhere('prospectingListingId', $long)->facts['price']);
    }

    public function test_the_owner_on_file_is_named(): void
    {
        $owner = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id, 'agent_id' => $this->agent->id,
            'first_name' => 'Piet', 'last_name' => 'Botha', 'phone' => '0825550101',
        ]);
        $tp = $this->tp(['owner_contact_id' => $owner->id]);
        $this->listing(['first_seen_at' => '2026-03-01 08:00:00'], $tp);

        $c = (new \App\Services\RoadToSold\Selections\OwnerKnownSelection())->candidates($this->ctx(), 5);

        $this->assertSame('Owner on file: Piet Botha', $c[0]->reason);
    }

    public function test_the_line_and_facts_are_stored_on_the_list_item_and_the_prospect_when_offered(): void
    {
        $l = $this->listing();
        $this->named($this->agent, 'Piet', $l);
        $this->named($this->agent, 'Sanet', $l);
        RtsSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], ['selections' => [['key' => 'my_buyers_sourcing', 'enabled' => true]]]);
        SettingsService::forget();

        $items = app(ListBuilderService::class)->buildFor($this->agent, self::WED);

        $this->assertCount(1, $items);
        $this->assertSame('2 of your buyers match: Piet, Sanet', $items->first()->reason);
        $this->assertSame(2, $items->first()->reason_facts['count']);
        $prospect = RtsProspect::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('2 of your buyers match: Piet, Sanet', $prospect->reason);

        // what the market does next does not rewrite what was offered
        $this->named($this->agent, 'Jo', $l);
        Cache::flush();
        $again = app(ListBuilderService::class)->buildFor($this->agent, self::WED);
        $this->assertSame('2 of your buyers match: Piet, Sanet', $again->first()->reason);
    }

    // ── Release ──────────────────────────────────────────────────────────

    private function release(User $as, int $prospectId, array $data = ['reason' => 'Agent has left the area'])
    {
        return $this->actingAs($as)->post(route('road-to-sold.prospects.release', $prospectId), $data);
    }

    public function test_a_manager_gives_a_property_back_with_a_reason_and_leaves_an_audit_line(): void
    {
        $tp = $this->tp();
        $p = $this->prospects->place($this->agent, $tp->id);
        RtsListItem::withoutAgencyStamping(fn () => RtsListItem::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'user_id' => $this->agent->id, 'list_date' => self::WED, 'track' => 'sales',
            'tracked_property_id' => $tp->id, 'selection' => 'long_on_market', 'origin' => 'auto', 'position' => 1, 'status' => 'open', 'reason' => 'x', 'prospect_id' => $p->id,
        ]));

        $this->release($this->manager, $p->id)->assertRedirect()->assertSessionHas('status');

        $p->refresh();
        $this->assertNotNull($p->released_at);
        $this->assertSame($this->manager->id, (int) $p->released_by_user_id);
        $event = DB::table('rts_prospect_events')->where('prospect_id', $p->id)->where('kind', 'release')->first();
        $this->assertSame('Agent has left the area', $event->note);
        $this->assertSame($this->manager->id, (int) $event->actor_user_id);
        $this->assertSame('missed', RtsListItem::withoutGlobalScopes()->where('prospect_id', $p->id)->value('status'), 'the agent\'s open row for it is closed');
        $this->assertNotNull($this->prospects->place($this->mate, $tp->id), 'and another agent can now be offered it');
    }

    public function test_a_reason_is_required_and_an_agent_without_the_key_is_refused(): void
    {
        $p = $this->prospects->place($this->agent, $this->tp()->id);

        $this->release($this->manager, $p->id, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->release($this->manager, $p->id, ['reason' => 'ab'])->assertSessionHasErrors('reason');
        $this->assertNull($p->refresh()->released_at, 'nothing was released');

        $this->release($this->agent, $p->id)->assertForbidden();
        $this->assertNull($p->refresh()->released_at);
    }

    public function test_release_is_limited_to_the_viewers_breadth_and_never_crosses_an_agency(): void
    {
        $far = $this->makeAgent(null, \App\Models\Branch::create(['agency_id' => $this->agency->id, 'name' => 'Elsewhere']));
        $farProspect = $this->prospects->place($far, $this->tp()->id);
        $this->release($this->manager, $farProspect->id)->assertNotFound();
        $this->assertNull($farProspect->refresh()->released_at);

        $foreign = \App\Models\Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'oth-' . uniqid()]);
        $fb = \App\Models\Branch::create(['agency_id' => $foreign->id, 'name' => 'Main']);
        $stranger = $this->makeAgent($foreign, $fb);
        $theirs = $this->prospects->place($stranger, $this->tp([], $foreign)->id);
        $this->release($this->manager, $theirs->id)->assertNotFound();
    }

    public function test_an_already_released_property_is_not_released_twice(): void
    {
        $p = $this->prospects->place($this->agent, $this->tp()->id);
        $this->release($this->manager, $p->id)->assertSessionHas('status');
        $this->release($this->manager, $p->id)->assertSessionHas('rts_error');

        $this->assertSame(1, DB::table('rts_prospect_events')->where('prospect_id', $p->id)->where('kind', 'release')->count());
    }

    public function test_giving_a_property_back_also_hands_back_the_agents_claim_and_pitch_lock(): void
    {
        $tp = $this->tp();
        $listing = $this->listing([], $tp);
        $p = $this->prospects->place($this->agent, $tp->id, 'sales', ['prospecting_listing_id' => $listing]);
        $claims = app(ProspectingClaimService::class);
        $claims->createTempLockForTrackedProperty($tp->id, $this->agent->id, $this->agency->id);
        $claimId = DB::table('prospecting_claims')->insertGetId([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $listing, 'user_id' => $this->agent->id, 'is_active' => 1,
            'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->release($this->manager, $p->id)->assertSessionHas('status');

        $this->assertNotNull(DB::table('prospecting_claims')->where('id', $claimId)->value('released_at'), 'the claim is released through the claim service');
        $this->assertSame(0, DB::table('prospecting_claims')->where('id', $claimId)->value('is_active'));
        $lock = DB::table('prospecting_pitch_locks')->where('tracked_property_id', $tp->id)->first();
        $this->assertNotNull($lock->released_at);
        $this->assertSame('manual_release', $lock->release_reason);
    }

    public function test_the_page_draws_release_only_for_someone_holding_the_key(): void
    {
        $this->prospects->place($this->agent, $this->tp()->id);

        $asManager = $this->actingAs($this->manager)->get(route('road-to-sold.index', ['block' => 'prospects', 'agent' => $this->agent->id]))->assertOk();
        $asManager->assertSee('data-qa="prospect-release"', false)->assertSee('Give back to the pool');

        $asAgent = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['block' => 'prospects']))->assertOk();
        $asAgent->assertDontSee('data-qa="prospect-release"', false)->assertDontSee('Give back to the pool');
    }

    public function test_the_key_is_in_the_catalogue_and_branch_managers_get_it_by_default(): void
    {
        $keys = array_column(config('corex-permissions.permissions'), 'key');
        $this->assertContains('road_to_sold.release_prospects', $keys);
        $this->assertContains('road_to_sold.release_prospects', config('corex-permissions.role_defaults.branch_manager.include'));
        $this->assertNotContains('road_to_sold.release_prospects', config('corex-permissions.role_defaults.agent.include', config('corex-permissions.role_defaults.agent')));
    }
}
