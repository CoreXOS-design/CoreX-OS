<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Performance\Period;
use App\Services\PermissionService;
use App\Services\RoadToSold\BeltFunnelService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ParkReasonService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\ResultOptionService;
use App\Services\RoadToSold\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v4 leftovers (the 2 Nov 12:30 ruling): no reason-less loss from Market Intelligence, the Stock loaded swap is a structured off-ramp, and a funnel number or
 * bar opens the properties behind it.
 */
final class BeltLeftoversTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        ResultOptionService::forgetMemo();
        ParkReasonService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        foreach (['agent', 'branch_manager'] as $role) {
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => 'access_prospecting', 'agency_id' => $this->agency->id]);   // the Market Intelligence door
        }
        foreach (['agent' => 'own', 'branch_manager' => 'branch'] as $role => $scope) {
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id], ['scope' => $scope]);
        }
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function period(): Period
    {
        return new Period(CarbonImmutable::parse('2026-10-01')->startOfDay(), CarbonImmutable::parse('2026-10-31')->endOfDay(), 'Test', 'custom');
    }

    private function claimed(?User $agent = null, string $stage = S::CONTACTED): array
    {
        $agent ??= $this->agent;
        $tp = $this->tp(['street_name' => 'Bairn Street', 'street_number' => (string) random_int(1, 900)]);
        $listing = $this->listing([], $tp);
        $claim = ProspectingClaim::create(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $listing, 'user_id' => $agent->id, 'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now()])->refresh();
        $p = RtsProspect::withoutGlobalScopes()->findOrFail($claim->rts_prospect_id);
        $p->forceFill(['stage' => $stage])->save();

        return [$p->refresh(), $claim, $listing, $tp];
    }

    // ── 1. no reason-less loss from Market Intelligence ──────────────────

    public function test_a_lost_feedback_asks_why_first_and_nothing_changes_without_a_reason(): void
    {
        [$p, $claim, $listing] = $this->claimed();

        $this->actingAs($this->agent)->post(route('market-intelligence.feedback', $listing), ['status' => 'lost'])->assertSessionHas('error');
        $this->actingAs($this->agent)->post(route('market-intelligence.feedback', $listing), ['status' => 'lost', 'reason' => 'wrong_number'])->assertSessionHas('error');   // another station's reason

        $this->assertTrue((bool) $claim->fresh()->is_active, 'still held');
        $this->assertNull($p->fresh()->parked_at);
    }

    public function test_a_lost_feedback_with_a_reason_goes_through_the_result_service(): void
    {
        [$p, $claim, $listing] = $this->claimed();

        $this->actingAs($this->agent)->post(route('market-intelligence.feedback', $listing), ['status' => 'lost', 'reason' => 'already_listed_open', 'notes' => 'Listed with Anna'])
            ->assertSessionHas('success');

        $p->refresh();
        $this->assertNotNull($p->parked_at, 'set aside with the reason');
        $this->assertNull($p->released_at, 'the record is kept');
        $this->assertSame('Already listed - open mandate', $p->park_reason_label);
        $this->assertSame('2027-01-28', $p->parked_until->toDateString(), 'its own 90 days');
        $claim->refresh();
        $this->assertFalse((bool) $claim->is_active);
        $this->assertSame(ProspectingClaim::STATUS_LOST, $claim->status);
        $this->assertSame(1, DB::table('rts_prospect_events')->where('prospect_id', $p->id)->where('kind', 'result')->count());
        $this->assertNotNull($claim->feedback_at);
        $this->assertSame('Listed with Anna', $claim->notes);
    }

    public function test_a_lost_feedback_for_a_claim_off_the_belt_still_needs_a_reason_and_keeps_it_in_the_notes(): void
    {
        $tp = $this->tp(['street_name' => 'Old Street']);
        $claim = ProspectingClaim::withoutEvents(fn () => ProspectingClaim::create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $this->listing([], $tp), 'user_id' => $this->agent->id, 'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now(),
        ]))->refresh();
        $this->assertNull($claim->rts_prospect_id);

        $this->actingAs($this->agent)->post(route('market-intelligence.feedback', $claim->prospecting_listing_id), ['status' => 'lost'])->assertSessionHas('error');
        $this->assertTrue((bool) $claim->fresh()->is_active);

        $this->actingAs($this->agent)->post(route('market-intelligence.feedback', $claim->prospecting_listing_id), ['status' => 'lost', 'reason' => 'selling_privately']);
        $this->assertFalse((bool) $claim->fresh()->is_active);
        $this->assertStringContainsString('Selling privately', (string) $claim->fresh()->notes);
    }

    public function test_the_reason_a_never_reason_closes_the_record_for_good(): void
    {
        [$p, , $listing] = $this->claimed(null, S::OWNER_FOUND);
        $this->actingAs($this->agent)->post(route('market-intelligence.feedback', $listing), ['status' => 'lost', 'reason' => 'do_not_contact'])->assertSessionHas('success');

        $this->assertNotNull($p->fresh()->closed_at);
    }

    // ── 2. the Stock loaded swap ─────────────────────────────────────────

    private function loaded(): array
    {
        $tp = $this->tp(['street_name' => 'Swap Road', 'street_number' => (string) random_int(1, 900)]);
        $p = app(ProspectService::class)->place($this->agent, (int) $tp->id, 'sales', ['stage' => S::FOUND]);
        $item = RtsListItem::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'user_id' => $this->agent->id, 'list_date' => '2026-10-30', 'track' => 'sales',
            'tracked_property_id' => $tp->id, 'prospect_id' => $p->id, 'selection' => 'price_drops', 'origin' => 'auto', 'position' => 1, 'status' => 'open', 'reason' => 'Price dropped',
        ]);

        return [$item, $p, $tp];
    }

    public function test_a_swap_is_the_stock_loaded_off_ramp_and_the_funnel_counts_it_by_reason(): void
    {
        [$a, $pa] = $this->loaded();
        [$b, $pb] = $this->loaded();

        $this->actingAs($this->agent)->post(route('road-to-sold.swap', $a->id), ['reason_key' => 'not_for_me_wrong_area'])->assertSessionHas('status');
        $this->actingAs($this->agent)->post(route('road-to-sold.swap', $b->id), ['reason_key' => 'not_for_me_wrong_area'])->assertSessionHas('status');

        $this->assertSame('swapped', $a->fresh()->status);
        $this->assertNotNull($pa->fresh()->released_at);
        $f = app(BeltFunnelService::class);
        $row = collect($f->funnelOf([$this->agent->id], $f->figures($this->agency->id, $this->period()))['stations'])->firstWhere('key', 'loaded');
        $this->assertSame(2, $row['reasons']['Not for me - wrong area'], 'one bar, one wording, however many swaps');
        $this->assertSame(2, $row['lost']);
        $this->assertSame('not_for_me_wrong_area', $a->fresh()->reason_facts['swap']['reason_key']);
    }

    public function test_other_needs_a_few_words_and_an_unknown_reason_is_refused(): void
    {
        [$item, $p] = $this->loaded();

        $this->actingAs($this->agent)->post(route('road-to-sold.swap', $item->id), ['reason_key' => 'not_for_me_other'])->assertSessionHas('rts_error');
        $this->assertSame('open', $item->fresh()->status);
        $this->actingAs($this->agent)->post(route('road-to-sold.swap', $item->id), ['reason_key' => 'eval_no_show'])->assertSessionHas('rts_error');   // another station's reason
        $this->assertSame('open', $item->fresh()->status);

        $this->actingAs($this->agent)->post(route('road-to-sold.swap', $item->id), ['reason_key' => 'not_for_me_other', 'note' => 'Too steep to reach'])->assertSessionHas('status');
        $this->assertSame('swapped', $item->fresh()->status);
        $this->assertSame('Too steep to reach', $item->fresh()->reason_facts['swap']['note']);
        $this->assertStringContainsString('Too steep to reach', $item->fresh()->reason_facts['swap']['reason']);
    }

    public function test_the_old_free_text_box_still_works_and_counts_as_other(): void
    {
        [$item] = $this->loaded();

        $this->actingAs($this->agent)->post(route('road-to-sold.swap', $item->id), ['reason' => 'Already listed with Anna'])->assertSessionHas('status');

        $facts = $item->fresh()->reason_facts['swap'];
        $this->assertSame('not_for_me_other', $facts['reason_key']);
        $this->assertSame('Already listed with Anna', $facts['note']);
        $this->actingAs($this->agent)->post(route('road-to-sold.swap', $item->id), ['reason' => ''])->assertSessionHasErrors('reason');
    }

    public function test_a_swapped_row_with_no_belt_record_gets_one_so_the_funnel_counts_it(): void
    {
        $tp = $this->tp(['street_name' => 'No Record Road']);
        $item = RtsListItem::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'user_id' => $this->agent->id, 'list_date' => '2026-10-30', 'track' => 'sales',
            'tracked_property_id' => $tp->id, 'selection' => 'price_drops', 'origin' => 'auto', 'position' => 1, 'status' => 'open', 'reason' => 'x',
        ]);

        $this->actingAs($this->agent)->post(route('road-to-sold.swap', $item->id), ['reason_key' => 'not_for_me_no_address'])->assertSessionHas('status');

        $f = app(BeltFunnelService::class);
        $row = collect($f->funnelOf([$this->agent->id], $f->figures($this->agency->id, $this->period()))['stations'])->firstWhere('key', 'loaded');
        $this->assertSame(1, $row['reached']);
        $this->assertSame(1, $row['reasons']['Not for me - no address']);
    }

    // ── 3. a number or a bar opens the properties behind it ──────────────

    public function test_a_lost_bar_lists_the_properties_behind_it_and_a_reached_number_lists_who_reached(): void
    {
        $a = app(ProspectService::class)->place($this->agent, (int) $this->tp(['street_name' => 'A Road', 'street_number' => '1'])->id, 'sales', ['stage' => S::CONTACTED]);
        $b = app(ProspectService::class)->place($this->agent, (int) $this->tp(['street_name' => 'B Road', 'street_number' => '2'])->id, 'sales', ['stage' => S::CONTACTED]);
        $c = app(ProspectService::class)->place($this->agent, (int) $this->tp(['street_name' => 'C Road', 'street_number' => '3'])->id, 'sales', ['stage' => S::CONTACTED]);
        app(ProspectService::class)->park($a, 'not_interested', null, null, $this->agent);
        app(ProspectService::class)->park($b, 'selling_privately', null, null, $this->agent);
        app(ProspectService::class)->park($c, 'not_now', null, null, $this->agent);
        $svc = app(BeltFunnelService::class);

        $bar = $svc->drill($this->agent, $this->period(), 'contacted', 'lost', 'Not interested', 'me');
        $this->assertTrue($bar['allowed']);
        $this->assertCount(1, $bar['rows']);
        $this->assertStringContainsString('A Road', $bar['rows'][0]['address']);
        $this->assertSame('Not interested', $bar['rows'][0]['reason']);
        $this->assertSame('Set aside', $bar['rows'][0]['now']);

        $number = $svc->drill($this->agent, $this->period(), 'contacted', 'lost', null, 'me');
        $this->assertCount(2, $number['rows'], 'the lost figure leaves "Not now" out, and so does the list behind it');
        $reached = $svc->drill($this->agent, $this->period(), 'contacted', 'reached', null, 'me');
        $this->assertCount(3, $reached['rows']);
    }

    public function test_an_agent_is_never_listed_a_colleagues_properties_but_a_manager_sees_the_branch(): void
    {
        $mine = app(ProspectService::class)->place($this->agent, (int) $this->tp(['street_name' => 'Mine', 'street_number' => '1'])->id, 'sales', ['stage' => S::CONTACTED]);
        $theirs = app(ProspectService::class)->place($this->mate, (int) $this->tp(['street_name' => 'Theirs', 'street_number' => '2'])->id, 'sales', ['stage' => S::CONTACTED]);
        $manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true]);
        $svc = app(BeltFunnelService::class);

        $branchAsAgent = $svc->drill($this->agent, $this->period(), 'contacted', 'reached', null, 'branch');
        $this->assertFalse($branchAsAgent['allowed'], 'the figure is theirs to see, the properties are not');
        $this->assertSame(1, count($svc->drill($this->agent, $this->period(), 'contacted', 'reached', null, 'me')['rows']));

        $branchAsManager = $svc->drill($manager, $this->period(), 'contacted', 'reached', null, 'branch');
        $this->assertTrue($branchAsManager['allowed']);
        $this->assertCount(2, $branchAsManager['rows']);
        $agencyAsManager = $svc->drill($manager, $this->period(), 'contacted', 'reached', null, 'agency');
        $this->assertTrue($agencyAsManager['allowed'], 'one branch: the agency is the same agents');

        $other = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Other branch']);
        $far = $this->makeAgent($this->agency, $other);
        app(ProspectService::class)->place($far, (int) $this->tp(['street_name' => 'Far', 'street_number' => '3'])->id, 'sales', ['stage' => S::CONTACTED]);
        $this->assertFalse($svc->drill($manager, $this->period(), 'contacted', 'reached', null, 'agency')['allowed'], "a manager does not get another branch's properties");
    }

    public function test_the_funnel_page_links_its_numbers_and_bars_and_the_list_page_renders(): void
    {
        $a = app(ProspectService::class)->place($this->agent, (int) $this->tp(['street_name' => 'A Road', 'street_number' => '1'])->id, 'sales', ['stage' => S::CONTACTED]);
        app(ProspectService::class)->park($a, 'not_interested', null, null, $this->agent);

        $page = $this->actingAs($this->agent)->get(route('road-to-sold.belt-funnel', ['period' => 'this_month']))->assertOk();
        $html = $page->getContent();
        $this->assertStringContainsString('data-qa="drill"', $html);
        $this->assertStringContainsString('reason=Not+interested', str_replace('%20', '+', $html), 'the bar for "Not interested" opens the list behind it');
        $this->assertStringNotContainsString('scope=agency', $html, 'an agent is not offered the agency\'s properties');

        $list = $this->actingAs($this->agent)->get(route('road-to-sold.belt-funnel.list', ['station' => 'contacted', 'kind' => 'lost', 'reason' => 'Not interested', 'scope' => 'me', 'period' => 'this_month']));
        $list->assertOk()->assertSee('A Road')->assertSee('Not interested')->assertSee('data-qa="list-row"', false);
        $this->actingAs($this->agent)->get(route('road-to-sold.belt-funnel.list', ['station' => 'contacted', 'kind' => 'reached', 'scope' => 'agency', 'period' => 'this_month']))->assertOk()->assertSee('data-qa="list-not-allowed"', false);
        $this->actingAs($this->agent)->get(route('road-to-sold.belt-funnel.list', ['station' => 'nonsense']))->assertNotFound();
    }
}
