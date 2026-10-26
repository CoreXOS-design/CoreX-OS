<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\Branch;
use App\Models\Deal;
use App\Models\RoadToSold\RtsBudget;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\Agent\MyPortalNumbers;
use App\Services\PermissionService;
use App\Services\RoadToSold\BudgetService;
use App\Services\RoadToSold\TargetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * agency-tracker-rehome.md step 2 (P3): My Portal "Targets & Budget", "Where I am vs where I should be"
 * and "My Activity" - read only, no HFC constants, scoped at the query layer.
 *
 * Input paths proven: budget set / carried into the current month / past month empty; agent with deals,
 * agent with none (agency average, then "not enough deals"), a second agency's data never leaking; lens
 * limited by the user's deal scope; declined deals left out; pipeline = own net on not-registered / not-paid;
 * stock counted from CoreX properties (sales vs rentals, off-market and other-agency stock excluded);
 * points: only confirmed / scored rows, 14-day strip across a month boundary; ?period= honoured and a bad
 * value absorbed; nothing written by viewing; the assistant gets none of it.
 */
final class MyPortalBlocksTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $colleague;
    private int $dealSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20 10:00:00');
        \Illuminate\Support\Carbon::setTestNow('2026-10-20 10:00:00');

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->agent = $this->user('agent');
        $this->colleague = $this->user('agent');
        $this->actingAs($this->agent);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────

    /** Rows for ANOTHER agency must be made with nobody logged in: a logged-in user's agency is force-stamped on create. */
    private function asNobody(callable $make): mixed
    {
        $me = auth()->user();
        auth()->guard()->forgetUser();
        try {
            return $make();
        } finally {
            if ($me) {
                $this->actingAs($me);
            }
        }
    }

    private function user(string $role, ?Agency $agency = null, ?Branch $branch = null): User
    {
        return User::factory()->create([
            'agency_id' => ($agency ?? $this->agency)->id, 'branch_id' => ($branch ?? $this->branch)->id,
            'role' => $role, 'is_active' => true,
        ]);
    }

    /** Grants for the agent role, so the "no grants = everything allowed" test posture is off and the scope is the real one. */
    private function grantAgent(string $dealsScope): void
    {
        foreach (['access_my_portal', 'access_daily_activity'] as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id]);
        }
        RolePermission::create(['role' => 'agent', 'permission_key' => 'deals.view', 'scope' => $dealsScope, 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();
    }

    /**
     * A budget for the agent and month. Since re-home step 4 the budget lives in Road to Sold (rts_budgets); the
     * legacy Worksheet row is still made too, because several tests compare My Portal with the old Worksheet maths.
     */
    private function worksheet(User $u, string $period, float $personal, float $business): Worksheet
    {
        $make = fn () => Worksheet::create([
            'agency_id' => $u->agency_id, 'user_id' => $u->id, 'period' => $period,
            'personal_net_target' => $personal, 'business_net_target' => $business,
        ]);
        $w = (int) $u->agency_id === (int) $this->agency->id ? $make() : $this->asNobody($make);
        $this->mirror($w->fresh());

        return $w;
    }

    /** Write the Worksheet row's budget and planning figures to the Road to Sold store (what My Portal reads). */
    private function mirror(Worksheet $w): void
    {
        DB::table('rts_budgets')->updateOrInsert(
            ['agency_id' => $w->agency_id, 'track' => 'sales', 'scope_key' => 'user:' . $w->user_id, 'period' => $w->period],
            [
                'level' => 'agent', 'user_id' => $w->user_id, 'branch_id' => User::withoutGlobalScopes()->find($w->user_id)?->branch_id,
                'personal_budget' => $w->personal_net_target, 'business_budget' => $w->business_net_target, 'requirement' => 0,
                'avg_sale_price' => $w->avg_sale_price, 'commission_percent' => $w->commission_percent, 'paye_percent' => $w->paye_percent,
                'agent_split_percent' => $w->agent_split_percent, 'correctly_priced_percent' => $w->correctly_priced_percent,
                'created_at' => now(), 'updated_at' => now(),
            ]
        );
    }

    /** Change the Worksheet row, then keep the Road to Sold copy the same. */
    private function tweak(Worksheet $w, array $attrs): void
    {
        $w->forceFill($attrs)->save();
        $this->mirror($w->fresh());
    }

    /** A deal with the agent on it and one money line. */
    private function deal(User $agent, string $date, string $status, float $value, float $commission, float $gross, float $net, string $commissionStatus = 'Pending', ?Agency $agency = null, ?Branch $branch = null): Deal
    {
        $agency ??= $this->agency;
        $branch ??= $this->branch;

        return $agency->is($this->agency) ? $this->makeDeal($agent, $date, $status, $value, $commission, $gross, $net, $commissionStatus, $agency, $branch)
            : $this->asNobody(fn () => $this->makeDeal($agent, $date, $status, $value, $commission, $gross, $net, $commissionStatus, $agency, $branch));
    }

    private function makeDeal(User $agent, string $date, string $status, float $value, float $commission, float $gross, float $net, string $commissionStatus, Agency $agency, Branch $branch): Deal
    {
        $deal = Deal::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'deal_no' => (string) (9000 + ++$this->dealSeq),
            'period' => substr($date, 0, 7), 'deal_date' => $date, 'property_value' => $value, 'total_commission' => $commission,
            'accepted_status' => $status, 'commission_status' => $commissionStatus,
        ]);
        DB::table('deal_user')->insert(['deal_id' => $deal->id, 'user_id' => $agent->id, 'side' => 'listing', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('deal_money_lines')->insert([
            'deal_id' => $deal->id, 'agency_id' => $agency->id, 'user_id' => $agent->id, 'branch_id' => $branch->id, 'period' => substr($date, 0, 7),
            'side' => 'listing', 'agent_gross_ex_vat' => $gross, 'agent_net_ex_vat' => $net, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $deal;
    }

    private function property(User $agent, string $status = 'active', string $type = 'sale'): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agent->agency_id, 'agent_id' => $agent->id, 'branch_id' => $agent->branch_id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4), 'address' => '14 Marine Drive',
            'suburb' => 'Uvongo', 'property_type' => 'house', 'listing_type' => $type, 'status' => $status,
            'price' => 1500000, 'beds' => 3, 'baths' => 2, 'garages' => 1, 'city' => 'Margate', 'province' => 'KwaZulu-Natal',
        ]);
    }

    private function svc(): MyPortalNumbers
    {
        return app(MyPortalNumbers::class);
    }

    private function entry(User $u, string $date, int $value, int $defId, string $state = 'confirmed', string $source = 'manual'): void
    {
        DB::table('daily_activity_entries')->insert([
            'activity_date' => $date, 'period' => substr($date, 0, 7), 'user_id' => $u->id, 'agency_id' => $u->agency_id,
            'activity_definition_id' => $defId, 'value' => $value, 'point_state' => $state, 'source' => $source,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function definition(int $weight): int
    {
        return (int) DB::table('activity_definitions')->insertGetId([
            'name' => 'Call an owner ' . Str::random(4), 'weight' => $weight, 'sort_order' => 1, 'scope' => 'system',
            'is_enabled' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── budget ───────────────────────────────────────────────────────────────────────────

    public function test_budget_is_the_agents_own_for_the_month_with_personal_and_business_apart(): void
    {
        $this->worksheet($this->agent, '2026-10', 40000, 20000);

        $b = app(BudgetService::class)->forUser($this->agent, '2026-10');

        $this->assertSame(40000.0, $b['personalBudget']);
        $this->assertSame(20000.0, $b['businessBudget']);
        $this->assertSame(60000.0, $b['total']);
        $this->assertSame('user', $b['source']);
    }

    public function test_the_want_figure_is_not_part_of_the_budget(): void
    {
        $w = $this->worksheet($this->agent, '2026-10', 40000, 20000);
        $this->tweak($w, ['want_net_target' => 99999]);

        $this->assertSame(60000.0, app(BudgetService::class)->forUser($this->agent, '2026-10')['total']);
    }

    public function test_the_current_month_carries_the_last_budget_forward_but_a_past_month_does_not(): void
    {
        $this->worksheet($this->agent, '2026-08', 30000, 10000);
        $svc = app(BudgetService::class);

        $now = $svc->forUser($this->agent, '2026-10');
        $this->assertSame('carried_forward', $now['source']);
        $this->assertSame('2026-08', $now['setForPeriod']);
        $this->assertSame(40000.0, $now['total']);

        $this->assertSame('none', $svc->forUser($this->agent, '2026-09')['source'], 'a past month with no row is empty, not carried');
        $this->assertSame(0.0, $svc->forUser($this->agent, '2026-09')['total']);
    }

    public function test_a_zero_budget_row_reads_as_nothing_set_and_another_agency_never_leaks(): void
    {
        $this->worksheet($this->agent, '2026-10', 0, 0);
        $this->assertSame('none', app(BudgetService::class)->forUser($this->agent, '2026-10')['source']);

        $other = Agency::create(['name' => 'Other', 'slug' => 'other-' . Str::random(5)]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'B']);
        $stranger = $this->user('agent', $other, $ob);
        $this->worksheet($stranger, '2026-10', 90000, 10000);
        $this->assertSame('none', app(BudgetService::class)->forUser($this->agent, '2026-10')['source']);
    }

    public function test_branch_requirement_per_agent_counts_only_active_agents_who_share_the_budget(): void
    {
        RtsBudget::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'track' => 'sales', 'level' => 'branch', 'scope_key' => 'branch:' . $this->branch->id, 'period' => '2026-10', 'requirement' => 300000]);
        $this->agent->forceFill(['counts_for_branch_split' => 1])->save();
        $this->colleague->forceFill(['counts_for_branch_split' => 1])->save();
        $this->user('agent')->forceFill(['counts_for_branch_split' => 0])->save();

        $r = app(BudgetService::class)->requirementFor($this->agency->id, $this->branch->id, '2026-10');

        $this->assertSame(300000.0, $r['requirement']);
        $this->assertSame(2, $r['agents']);
        $this->assertSame(150000.0, $r['requiredPerAgent']);
        $this->assertSame(0.0, app(BudgetService::class)->requirementFor($this->agency->id, $this->branch->id, '2026-09')['requirement']);
        $this->assertSame(0.0, app(BudgetService::class)->requirementFor($this->agency->id, null, '2026-10')['requirement']);
    }

    // ── targets ──────────────────────────────────────────────────────────────────────────

    public function test_monthly_targets_are_read_and_nothing_is_written(): void
    {
        DB::table('targets')->insert([
            'period' => '2026-10', 'user_id' => $this->agent->id, 'agency_id' => $this->agency->id, 'listings_target' => 6,
            'deals_target' => 2, 'value_target' => 3500000, 'points_target' => 800, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $svc = app(TargetService::class);

        $set = $svc->monthlyFor($this->agent, '2026-10');
        $this->assertSame(['deals' => 2, 'value' => 3500000.0, 'listings' => 6, 'points' => 800, 'set' => true], $set);

        $this->assertFalse($svc->monthlyFor($this->agent, '2026-09')['set']);
        $this->assertSame(1, DB::table('targets')->count(), 'reading a month with no row creates none');
    }

    // ── stock ────────────────────────────────────────────────────────────────────────────

    public function test_stock_counts_the_agents_active_sales_and_rental_listings_from_corex_properties(): void
    {
        $this->property($this->agent);
        $this->property($this->agent);
        $this->property($this->agent, 'active', 'rental');
        $this->property($this->agent, 'sold');                                   // off the market
        $this->property($this->agent, Property::STATUS_OTHER_AGENCY_STOCK);      // someone else's stock
        $this->property($this->colleague);                                       // another agent's

        $stock = $this->svc()->stock($this->agent);

        $this->assertSame(2, $stock['sales']);
        $this->assertSame(1, $stock['rentals']);
    }

    // ── deals ────────────────────────────────────────────────────────────────────────────

    public function test_deal_summary_counts_all_four_statuses_but_leaves_declined_out_of_the_averages(): void
    {
        $this->deal($this->agent, '2026-10-05', 'R', 2000000, 115000, 60000, 45000);
        $this->deal($this->agent, '2026-10-07', 'P', 1000000, 57500, 30000, 22000);
        $this->deal($this->agent, '2026-10-09', 'D', 9000000, 500000, 0, 0);
        $this->deal($this->agent, '2026-09-30', 'G', 5000000, 250000, 0, 0);   // another month

        $s = $this->svc()->dealSummary($this->agent, 'own', '2026-10');

        $this->assertSame(['P' => 1, 'G' => 0, 'R' => 1, 'D' => 1], $s['status']);
        $this->assertSame(2, $s['deals']);
        $this->assertEqualsWithDelta(1500000.0, $s['avgPrice'], 0.01, 'declined R9m is not in the average');
        // (115000 + 57500) / 1.15 = 150000 ex VAT over R3m = 5%
        $this->assertEqualsWithDelta(5.0, $s['avgCommissionPct'], 0.001);
    }

    public function test_a_month_with_no_deals_gives_nulls_not_zero_averages(): void
    {
        $s = $this->svc()->dealSummary($this->agent, 'own', '2026-10');

        $this->assertSame(0, $s['deals']);
        $this->assertNull($s['avgPrice']);
        $this->assertNull($s['avgCommissionPct']);
    }

    public function test_the_lens_is_limited_by_the_users_deal_scope_at_the_query_layer(): void
    {
        $this->grantAgent('own');
        $this->deal($this->agent, '2026-10-05', 'P', 1000000, 57500, 30000, 20000);
        $this->deal($this->colleague, '2026-10-06', 'P', 3000000, 172500, 90000, 60000);

        $this->assertSame(['own'], $this->svc()->lensesFor($this->agent));
        // asking for the agency lens anyway still only sees the agent's own deals
        $s = $this->svc()->dealSummary($this->agent, 'agency', '2026-10');
        $this->assertSame(1, $s['deals']);
        $this->assertEqualsWithDelta(1000000.0, $s['avgPrice'], 0.01);
    }

    public function test_a_branch_scope_sees_the_branch_and_an_all_scope_sees_the_agency_but_never_another_agency(): void
    {
        $other = Agency::create(['name' => 'Other', 'slug' => 'other-' . Str::random(5)]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Elsewhere']);
        $stranger = $this->user('agent', $other, $otherBranch);
        $branch2 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $farAgent = $this->user('agent', $this->agency, $branch2);

        $this->deal($this->agent, '2026-10-05', 'P', 1000000, 57500, 30000, 20000);
        $this->deal($this->colleague, '2026-10-06', 'P', 3000000, 172500, 90000, 60000);
        $this->deal($farAgent, '2026-10-07', 'P', 5000000, 287500, 150000, 100000, 'Pending', null, $branch2);
        $this->deal($stranger, '2026-10-08', 'P', 99000000, 1, 1, 1, 'Pending', $other, $otherBranch);

        $this->grantAgent('branch');
        $this->actingAs($this->agent);
        $this->assertSame(['own', 'branch'], $this->svc()->lensesFor($this->agent));
        $this->assertSame(2, $this->svc()->dealSummary($this->agent, 'branch', '2026-10')['deals'], 'own + colleague, not the other branch');
        $this->assertSame(1, $this->svc()->dealSummary($this->agent, 'agency', '2026-10')['deals'], 'agency lens not allowed: falls back to own');

        RolePermission::query()->where('permission_key', 'deals.view')->update(['scope' => 'all']);
        PermissionService::clearCache();
        $this->assertSame(['own', 'branch', 'agency'], $this->svc()->lensesFor($this->agent));
        $this->assertSame(3, $this->svc()->dealSummary($this->agent, 'agency', '2026-10')['deals'], 'the whole agency, not the stranger');
    }

    public function test_own_money_is_gross_and_net_from_money_lines_and_the_pipeline_is_not_registered_not_paid(): void
    {
        $this->deal($this->agent, '2026-10-05', 'R', 2000000, 115000, 60000, 45000, 'Paid');      // registered + paid: out of the pipeline
        $this->deal($this->agent, '2026-10-06', 'G', 1000000, 57500, 30000, 22000, 'Pending');    // in the pipeline
        $this->deal($this->agent, '2026-11-02', 'P', 1000000, 57500, 20000, 15000, 'Pending');    // pipeline, but another month
        $this->deal($this->agent, '2026-10-08', 'D', 5000000, 1, 99999, 99999, 'Pending');        // declined: nowhere
        $this->deal($this->colleague, '2026-10-09', 'P', 1000000, 1, 77777, 77777, 'Pending');    // someone else's

        $m = $this->svc()->ownMoney($this->agent, '2026-10');

        $this->assertSame(90000.0, $m['gross']);
        $this->assertSame(67000.0, $m['net']);
        $this->assertSame(22000.0 + 15000.0, $m['pipeline']);
    }

    /** Money is rebuilt over time: the old rows are soft-deleted and a new live one written. Only live rows count. */
    private function rebuiltOver(Deal $deal, User $agent, float $oldGross, float $oldNet, int $copies = 3): void
    {
        for ($i = 0; $i < $copies; $i++) {
            DB::table('deal_money_lines')->insert([
                'deal_id' => $deal->id, 'agency_id' => $deal->agency_id, 'user_id' => $agent->id, 'branch_id' => $deal->branch_id, 'period' => $deal->period,
                'side' => 'listing', 'agent_gross_ex_vat' => $oldGross, 'agent_net_ex_vat' => $oldNet, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now(),
            ]);
        }
    }

    public function test_money_lines_that_were_rebuilt_over_are_never_counted(): void
    {
        $deal = $this->deal($this->agent, '2026-10-05', 'G', 2000000, 115000, 60000, 45000);
        $this->rebuiltOver($deal, $this->agent, 55555, 44444);
        $this->deal($this->colleague, '2026-09-05', 'R', 2000000, 115000, 60000, 30000);
        $this->rebuiltOver(Deal::query()->where('deal_no', '9002')->firstOrFail(), $this->colleague, 99999, 88888, 5);

        $m = $this->svc()->ownMoney($this->agent, '2026-10');
        $this->assertSame(60000.0, $m['gross'], 'only the live line');
        $this->assertSame(45000.0, $m['net']);
        $this->assertSame(45000.0, $m['pipeline']);
    }

    // ── what the budget needs ────────────────────────────────────────────────────────────

    // ── what the budget needs: TODAY's Worksheet formula (Johan, 20 Oct 2026: "same as what we have there for now") ──

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function worksheetRows(): array
    {
        return [
            'the stored defaults'                       => [[]],
            'custom planning figures'                   => [['avg_sale_price' => 2400000, 'commission_percent' => 6, 'paye_percent' => 25, 'agent_split_percent' => 70, 'correctly_priced_percent' => 50]],
            'an admin average sale price override'      => [['avg_sale_price' => 1000000, 'avg_sale_price_admin' => 1750000, 'commission_percent' => 5.5]],
            'a business budget only'                    => [['personal_net_target' => 0, 'business_net_target' => 35000]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('worksheetRows')]
    public function test_my_portal_equals_the_old_worksheet_figures_for_the_same_agent(array $override): void
    {
        $row = $this->worksheet($this->agent, '2026-10', 40000, 20000);
        $this->tweak($row, $override);
        $this->property($this->agent);
        $this->property($this->agent);

        $old = \App\Http\Controllers\WorksheetController::calculate($row->fresh()->replicate()->forceFill(['want_net_target' => 0, 'user_id' => null]));
        $req = $this->svc()->requirement(app(BudgetService::class)->worksheetFor($this->agent, '2026-10'), 2);

        $this->assertSame('ok', $req['state']);
        $this->assertEqualsWithDelta($old['net_need'], $req['netNeed'], 0.001);
        $this->assertEqualsWithDelta($old['sales_needed_per_month'], $req['salesNeeded'], 0.0001);
        $this->assertEqualsWithDelta($old['total_listings_needed'], $req['listingsNeeded'], 0.0001);
        $this->assertEqualsWithDelta($old['total_listings_needed'] - 2, $req['gap'], 0.0001, 'gap is against the 2 active sales listings in CoreX');
    }

    public function test_my_portal_shows_the_same_numbers_as_the_old_worksheet_page(): void
    {
        $row = $this->worksheet($this->agent, '2026-10', 40000, 20000);
        $this->tweak($row, ['avg_sale_price' => 2400000, 'commission_percent' => 6, 'paye_percent' => 25, 'agent_split_percent' => 70, 'correctly_priced_percent' => 50]);
        $this->actingAs($this->agent);

        // /worksheet now 301s to My Portal (step 5), so the old page is rendered straight from its controller - it is kept, just unreachable.
        \Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag());
        $worksheet = html_entity_decode(app(\App\Http\Controllers\WorksheetController::class)->index()->render());
        $portal = $this->get(route('agent.portal', ['sub' => 'targets']))->assertOk()->getContent();

        preg_match('/Sales Needed \/ Month:<\/span>\s*<span class="ds-value">([\d,\.]+)</', $worksheet, $wSales);
        preg_match('/Total Listings Needed:<\/span>\s*<span class="ds-value">([\d,\.]+)</', $worksheet, $wList);
        $this->assertNotEmpty($wSales, 'the old Worksheet page shows Sales Needed / Month');
        $this->assertStringContainsString('data-testid="req-deals">' . $wSales[1] . '<', $portal);
        $this->assertStringContainsString('data-testid="req-listings">' . $wList[1] . '<', $portal);
    }

    public function test_the_want_figure_stays_out_of_the_portal_calculation(): void
    {
        $row = $this->worksheet($this->agent, '2026-10', 40000, 20000);
        $this->tweak($row, ['want_net_target' => 90000]);

        $req = $this->svc()->requirement(app(BudgetService::class)->worksheetFor($this->agent, '2026-10'), 0);

        $this->assertSame(60000.0, $req['netNeed'], 'personal + business only');
    }

    public function test_the_block_shows_the_planning_figures_it_works_from(): void
    {
        $row = $this->worksheet($this->agent, '2026-10', 40000, 20000);
        $this->tweak($row, ['avg_sale_price' => 2400000, 'commission_percent' => 6, 'paye_percent' => 25, 'agent_split_percent' => 70, 'correctly_priced_percent' => 50]);

        $html = $this->get(route('agent.portal', ['sub' => 'targets']))->assertOk()->getContent();

        $this->assertStringContainsString('average sale price R 2,400,000, commission 6%, PAYE 25%, your split 70%, correctly priced 50%', $html);
    }

    public function test_no_budget_and_an_unworkable_row_are_told_plainly(): void
    {
        $this->assertSame('no_budget', $this->svc()->requirement(null, 3)['state']);

        $row = $this->worksheet($this->agent, '2026-10', 40000, 20000);
        $this->tweak($row, ['avg_sale_price' => 0]);
        $this->assertSame('incomplete', $this->svc()->requirement($row->fresh(), 3)['state']);
        $this->get(route('agent.portal', ['sub' => 'targets']))->assertOk()->assertSee('data-testid="requirement-incomplete"', false);
    }

    public function test_a_month_with_no_budget_row_has_no_requirement_block_figures(): void
    {
        $html = $this->get(route('agent.portal', ['sub' => 'targets', 'period' => '2026-08']))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-testid="req-deals"', $html);
        $this->assertStringContainsString('Once a budget is set for August 2026', $html);
    }

    // ── points ───────────────────────────────────────────────────────────────────────────

    public function test_points_count_only_confirmed_scored_rows_and_the_strip_crosses_the_month_boundary(): void
    {
        CarbonImmutable::setTestNow('2026-10-02 10:00:00');
        \Illuminate\Support\Carbon::setTestNow('2026-10-02 10:00:00');
        $def = $this->definition(50);
        $this->entry($this->agent, '2026-10-02', 2, $def);                                 // today: 100
        $this->entry($this->agent, '2026-10-01', 1, $def);                                 // 50
        $this->entry($this->agent, '2026-09-30', 3, $def);                                 // 150, last month
        $this->entry($this->agent, '2026-10-02', 4, $def, 'provisional', 'auto_calendar'); // not yet earned
        $this->entry($this->agent, '2026-10-02', 4, $def, 'revoked', 'auto_calendar');     // taken back
        $this->entry($this->colleague, '2026-10-02', 9, $def);                             // someone else

        $p = $this->svc()->points($this->agent, '2026-10');

        $this->assertSame(150.0, $p['month'], 'October only');
        $this->assertSame(100.0, $p['today']);
        $this->assertSame(14, count($p['days']));
        $this->assertSame(150.0, $p['days']['2026-09-30'], 'the strip still shows the last days of September');
        $this->assertSame('2026-10-02', array_key_last($p['days']));
    }

    // ── history months ───────────────────────────────────────────────────────────────────

    public function test_history_lists_the_months_with_figures_newest_first_and_only_the_agents_own(): void
    {
        $this->worksheet($this->agent, '2026-06', 1000, 1000);
        $this->deal($this->agent, '2026-08-12', 'P', 1000000, 1, 1, 1);
        $this->entry($this->agent, '2026-09-03', 1, $this->definition(10));
        $this->deal($this->colleague, '2026-05-12', 'P', 1000000, 1, 1, 1);

        $this->assertSame(['2026-09', '2026-08', '2026-06'], $this->svc()->historyMonths($this->agent));
    }

    // ── the page ─────────────────────────────────────────────────────────────────────────

    public function test_the_portal_shows_targets_and_budget_for_an_agent_with_deals(): void
    {
        $this->worksheet($this->agent, '2026-10', 40000, 20000);
        $this->property($this->agent);
        $this->property($this->agent, 'active', 'rental');
        $this->deal($this->agent, '2026-10-05', 'R', 2000000, 115000, 60000, 45000);

        $html = $this->actingAs($this->agent)->get(route('agent.portal', ['sub' => 'targets']))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="stock-sales">1<', $html);
        $this->assertStringContainsString('data-testid="stock-rentals">1<', $html);
        $this->assertStringContainsString('data-testid="budget-personal">R 40,000<', $html);
        $this->assertStringContainsString('data-testid="budget-business">R 20,000<', $html);
        $this->assertStringContainsString('data-testid="budget-total">R 60,000<', $html);
        $expected = \App\Http\Controllers\WorksheetController::calculate(Worksheet::query()->where('user_id', $this->agent->id)->firstOrFail()->replicate()->forceFill(['want_net_target' => 0, 'user_id' => null]));
        $this->assertStringContainsString('data-testid="req-deals">' . number_format($expected['sales_needed_per_month'], 2) . '<', $html);
        $this->assertStringContainsString('data-testid="money-gross">R 60,000<', $html);
        $this->assertStringContainsString('data-testid="count-R-0">1<', $html);
        $this->assertStringContainsString('Where I Am', $html);
        $this->assertStringContainsString('Targets &amp; Budget', $html);
        $this->assertStringContainsString('My Activity', $html);
    }

    public function test_period_is_honoured_and_a_bad_period_is_absorbed(): void
    {
        $this->worksheet($this->agent, '2026-08', 30000, 10000);
        $this->deal($this->agent, '2026-08-12', 'R', 2000000, 115000, 60000, 45000);
        $this->actingAs($this->agent);

        $aug = $this->get(route('agent.portal', ['sub' => 'targets', 'period' => '2026-08']))->assertOk()->getContent();
        $this->assertStringContainsString('Targets &amp; Budget &mdash; August 2026', str_replace('—', '&mdash;', $aug));
        $this->assertStringContainsString('data-testid="budget-total">R 40,000<', $aug);

        $sep = $this->get(route('agent.portal', ['sub' => 'targets', 'period' => '2026-09']))->assertOk()->getContent();
        $this->assertStringContainsString('No budget has been set for September 2026', $sep, 'a past month with nothing set is an empty state');
        $this->assertStringContainsString('No deals in September 2026', $sep);

        foreach (['garbage', '2026-13', "2026-10'\"><script>", ''] as $bad) {
            $html = $this->get(route('agent.portal', ['sub' => 'targets', 'period' => $bad]))->assertOk()->getContent();
            $this->assertStringContainsString('October 2026', $html, "bad period [$bad] falls back to this month");
            $this->assertStringNotContainsString('<script>"', $html);
        }
    }

    public function test_where_am_i_shows_targets_against_actuals_and_an_honest_empty_state(): void
    {
        $this->actingAs($this->agent);
        $this->get(route('agent.portal'))->assertOk()->assertSee('data-testid="no-targets"', false);

        DB::table('targets')->insert([
            'period' => '2026-10', 'user_id' => $this->agent->id, 'agency_id' => $this->agency->id, 'listings_target' => 6,
            'deals_target' => 2, 'value_target' => 4000000, 'points_target' => 800, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->deal($this->agent, '2026-10-05', 'R', 2000000, 115000, 60000, 45000);

        $html = $this->get(route('agent.portal'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-testid="no-targets"', $html);
        $this->assertStringContainsString('data-testid="actual-deals">1<', $html);
        $this->assertStringContainsString('data-testid="actual-value">R 1,000,000<', $html, 'split-aware: the agent holds the listing side, 50% of R2m');
        $this->assertStringContainsString('50% of target', $html);
    }

    public function test_viewing_the_portal_writes_nothing(): void
    {
        $this->worksheet($this->agent, '2026-08', 30000, 10000);
        $before = [DB::table('targets')->count(), DB::table('worksheets')->count(), DB::table('monthly_target_goals')->count()];

        foreach (['whereami', 'targets', 'myactivity'] as $sub) {
            $this->actingAs($this->agent)->get(route('agent.portal', ['sub' => $sub, 'period' => '2026-10']))->assertOk();
        }

        $this->assertSame($before, [DB::table('targets')->count(), DB::table('worksheets')->count(), DB::table('monthly_target_goals')->count()]);
    }

    public function test_the_three_blocks_have_no_inputs_that_save_anything(): void
    {
        $this->actingAs($this->agent);
        $html = $this->get(route('agent.portal'))->assertOk()->getContent();
        $start = strpos($html, 'data-testid="portal-where-am-i"');
        $end = strpos($html, 'data-tour="portal-home-earnings"') ?: strlen($html);
        $blocks = substr($html, $start, $end - $start);

        $this->assertStringNotContainsString('method="POST"', $blocks);
        $this->assertStringNotContainsString('name="_token"', $blocks);
        $this->assertStringNotContainsString('type="number"', $blocks);
        $this->assertStringNotContainsString('<textarea', $blocks);
        $this->assertSame(0, preg_match_all('/<form\b(?![^>]*method="GET")/i', $blocks), 'every form in the blocks is a GET display picker');
    }

    public function test_the_old_my_agent_dashboard_url_moves_permanently_to_my_portal_keeping_the_month(): void
    {
        $this->actingAs($this->agent);

        $r = $this->get('/bm/my-dashboard?period=2026-08');
        $r->assertStatus(301)->assertRedirect(route('agent.portal', ['sub' => 'whereami', 'period' => '2026-08']));
        $this->get('/bm/my-dashboard')->assertStatus(301)->assertRedirect(route('agent.portal', ['sub' => 'whereami']));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('bm.my.dashboard'), 'the route name still resolves for every old caller');
        $this->assertStringNotContainsString("route('bm.my.dashboard')", (string) file_get_contents(resource_path('views/layouts/corex-sidebar.blade.php')), 'no sidebar link left');
    }

    public function test_an_assistant_sees_none_of_it(): void
    {
        $this->agency->forceFill(['assistants_enabled' => true])->save();
        \App\Models\Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        \App\Models\Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);
        $assistant = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'assistant', 'is_active' => true, 'is_assistant' => true,
        ]);
        $assignment = AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $assistant->id, 'agent_user_id' => $this->agent->id, 'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_my_portal', 'agency_id' => $this->agency->id, 'scope' => 'all']);
        \App\Models\AssistantAssignmentPermission::create([
            'assistant_assignment_id' => $assignment->id, 'permission_key' => 'access_my_portal', 'agency_id' => $this->agency->id, 'granted' => true, 'scope' => null,
        ]);
        PermissionService::clearCache();
        \App\Models\Role::clearCache();
        User::flushAssistantsEnabledCache();
        PermissionService::forceProductionPosture();
        $this->worksheet($this->agent, '2026-10', 40000, 20000);

        $html = $this->actingAs($assistant->fresh())->get(route('agent.portal', ['sub' => 'targets']))->assertOk()->getContent();

        $this->assertStringNotContainsString('portal-targets-budget', $html);
        $this->assertStringNotContainsString('portal-where-am-i', $html);
        $this->assertStringNotContainsString('portal-my-activity', $html);
        $this->assertStringNotContainsString('R 40,000', $html);
    }

    public function test_a_second_agency_with_no_data_gets_a_clean_page_and_no_hfc_numbers(): void
    {
        $other = Agency::create(['name' => 'Cape Town Rentals', 'slug' => 'ct-' . Str::random(5)]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Sea Point']);
        $newAgent = $this->user('agent', $other, $ob);

        $html = $this->actingAs($newAgent)->get(route('agent.portal', ['sub' => 'targets']))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="stock-sales">0<', $html);
        $this->assertStringContainsString('No budget has been set', $html);
        foreach (['1,060,000', '1060000', '7.5%'] as $hfc) {
            $this->assertStringNotContainsString($hfc, $html);
        }
    }

    public function test_the_new_code_carries_no_planning_constants_or_agency_wording(): void
    {
        $files = [
            app_path('Services/Agent/MyPortalNumbers.php'),
            app_path('Services/RoadToSold/BudgetService.php'),
            resource_path('views/agent/portal-blocks/_targets-budget.blade.php'),
            resource_path('views/agent/portal-blocks/_where-am-i.blade.php'),
            resource_path('views/agent/portal-blocks/_my-activity.blade.php'),
        ];
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            foreach (['1060000', '1,060,000', '0.075', '7.5', 'hfc', 'HFC', 'Home Finders', 'propcon', 'ListingStock', 'listing_stocks', 'CommissionLedger'] as $banned) {
                $this->assertStringNotContainsString($banned, $code, basename($file) . " must not contain {$banned}");
            }
        }
    }
}
