<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Models\Deal;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * My Deals (/agent/deals) — search, filters, sort, pagination, empty states, own-deals scoping.
 * Spec: .ai/specs/deal-register-v2-spec.md "My Deals — search, filters, sort, pagination".
 */
final class MyDealsFiltersTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $me;
    private User $other;
    private int $seq = 7000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'MyDeals Co', 'slug' => 'md-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agencyId]);
        foreach (['access_deal_register', 'view_deals', 'deals.view'] as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agencyId]);
        }
        Role::clearCache();
        PermissionService::clearCache();

        $this->me = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => true]);
        $this->other = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => true]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function deal(User $agent, array $attrs = []): Deal
    {
        $deal = Deal::create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => $this->seq++, 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'property_address' => '1 Default Rd', 'seller_name' => 'Sam Seller', 'buyer_name' => 'Bea Buyer',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ], $attrs));
        $deal->agents()->attach($agent->id, ['side' => 'listing']);

        return $deal;
    }

    private function myDeals(array $q = [])
    {
        return $this->actingAs($this->me)->get(route('agent.deals.index', $q));
    }

    public function test_agent_sees_only_own_deals_even_with_forged_params(): void
    {
        $mine = $this->deal($this->me, ['property_address' => '10 Mine Street']);
        $theirs = $this->deal($this->other, ['property_address' => '99 Theirs Avenue']);

        foreach ([[], ['agent' => $this->other->id], ['user_id' => $this->other->id], ['branch' => 999], ['search' => 'Theirs']] as $q) {
            $r = $this->myDeals($q)->assertOk();
            $r->assertDontSee('99 Theirs Avenue');
        }
        $this->myDeals()->assertSee('10 Mine Street');
        $this->assertSame([$mine->id], $this->myDeals()->viewData('deals')->pluck('id')->all());
        $this->assertNotContains($theirs->id, $this->myDeals(['agent' => $this->other->id])->viewData('deals')->pluck('id')->all());
    }

    public function test_search_matches_address_seller_buyer_and_deal_number(): void
    {
        $a = $this->deal($this->me, ['property_address' => '5 Palm Close', 'seller_name' => 'Zed', 'buyer_name' => 'Yan', 'deal_no' => 8101]);
        $b = $this->deal($this->me, ['property_address' => '6 Oak Close', 'seller_name' => 'Quentin Seller', 'buyer_name' => 'Xena', 'deal_no' => 8202]);
        $c = $this->deal($this->me, ['property_address' => '7 Elm Close', 'seller_name' => 'Wim', 'buyer_name' => 'Barnaby Buyer', 'deal_no' => 8303]);

        $ids = fn (string $s) => $this->myDeals(['search' => $s])->viewData('deals')->pluck('id')->all();
        $this->assertSame([$a->id], $ids('Palm'));
        $this->assertSame([$b->id], $ids('Quentin'));
        $this->assertSame([$c->id], $ids('Barnaby'));
        $this->assertSame([$b->id], $ids('8202'));
        $this->assertSame([], $ids('nothing-matches-this'));
        $this->assertCount(3, $ids('Close'));
    }

    public function test_status_commission_and_date_filters(): void
    {
        $p = $this->deal($this->me, ['accepted_status' => 'P', 'commission_status' => 'Not Paid', 'deal_date' => '2026-01-15']);
        $g = $this->deal($this->me, ['accepted_status' => 'G', 'commission_status' => 'Not Paid', 'deal_date' => '2026-03-15']);
        $r = $this->deal($this->me, ['accepted_status' => 'R', 'commission_status' => 'Paid', 'deal_date' => '2026-05-15']);
        $d = $this->deal($this->me, ['accepted_status' => 'D', 'commission_status' => 'Loss', 'deal_date' => '2026-07-15']);

        $ids = fn (array $q) => $this->myDeals($q)->viewData('deals')->pluck('id')->sort()->values()->all();
        $this->assertSame([$p->id], $ids(['status' => 'Pending']));
        $this->assertSame([$g->id], $ids(['status' => 'Granted']));
        $this->assertSame([$r->id], $ids(['status' => 'Registered']));
        $this->assertSame([$d->id], $ids(['status' => 'Declined']));
        $this->assertSame([$r->id], $ids(['commission' => 'Paid']));
        $this->assertSame([$d->id], $ids(['commission' => 'Loss']));
        $this->assertSame([$p->id, $g->id], $ids(['commission' => 'Not Paid']));
        $this->assertSame([$g->id, $r->id], $ids(['date_from' => '2026-03-15', 'date_to' => '2026-05-15']));
        $this->assertSame([$d->id], $ids(['date_from' => '2026-06-01']));
        $this->assertSame([$p->id], $ids(['date_to' => '2026-02-01']));
        // Garbage values are ignored, not an error.
        $this->assertCount(4, $ids(['status' => "x'; DROP TABLE deals;--", 'date_from' => 'banana', 'commission' => 'zzz']));
    }

    public function test_default_sort_is_deal_number_newest_first_and_columns_sort(): void
    {
        $a = $this->deal($this->me, ['deal_no' => 9001, 'property_address' => 'B Street', 'property_value' => 300, 'deal_date' => '2026-02-01']);
        $b = $this->deal($this->me, ['deal_no' => 9003, 'property_address' => 'A Street', 'property_value' => 100, 'deal_date' => '2026-03-01']);
        $c = $this->deal($this->me, ['deal_no' => 9002, 'property_address' => 'C Street', 'property_value' => 200, 'deal_date' => '2026-01-01']);

        $ids = fn (array $q) => $this->myDeals($q)->viewData('deals')->pluck('id')->all();
        $this->assertSame([$b->id, $c->id, $a->id], $ids([]));
        $this->assertSame([$a->id, $c->id, $b->id], $ids(['sort' => 'deal_no', 'direction' => 'asc']));
        $this->assertSame([$b->id, $a->id, $c->id], $ids(['sort' => 'property_address', 'direction' => 'asc']));
        $this->assertSame([$a->id, $c->id, $b->id], $ids(['sort' => 'property_value', 'direction' => 'desc']));
        $this->assertSame([$c->id, $a->id, $b->id], $ids(['sort' => 'deal_date', 'direction' => 'asc']));
        // Unknown sort column falls back to the default.
        $this->assertSame([$b->id, $c->id, $a->id], $ids(['sort' => 'password; --']));
    }

    public function test_pagination_is_twenty_per_page_and_keeps_filters(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->deal($this->me, ['property_address' => $i + 1 . ' Page Road']);
        }
        $this->deal($this->me, ['property_address' => 'Odd One Out']);

        $deals = $this->myDeals()->viewData('deals');
        $this->assertSame(26, $deals->total());
        $this->assertCount(20, $deals);
        $this->assertCount(6, $this->myDeals(['page' => 2])->viewData('deals'));

        $this->myDeals(['search' => 'Page Road'])->assertSee('search=Page%20Road', false);
    }

    public function test_empty_states_and_header_count(): void
    {
        $this->myDeals()->assertOk()->assertSee('No deals allocated to you yet')->assertSee('0 deals')
            ->assertDontSee('list-header-filter', false);

        $this->deal($this->me);
        $this->deal($this->me, ['accepted_status' => 'G']);
        $this->deal($this->other);

        $this->myDeals()->assertOk()->assertSee('2 deals')->assertSee('list-header-filter', false)
            ->assertSee('All statuses')->assertDontSee('No deals match these filters');

        $this->myDeals(['status' => 'Declined'])->assertOk()
            ->assertSee('No deals match these filters')->assertSee('Clear filters')
            ->assertSee('0 of 2 deals')->assertDontSee('No deals allocated to you yet');

        // Totals stay over ALL of the agent's own deals, not the current page / filter, and never include another agent's.
        $this->assertSame(2, $this->myDeals(['status' => 'Granted'])->viewData('totalMine'));
        $this->myDeals(['status' => 'Granted'])->assertSee('1 of 2 deals')->assertSee('is-active', false);
    }
}
