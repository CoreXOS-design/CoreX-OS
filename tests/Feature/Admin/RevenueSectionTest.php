<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\RevenueBillingRecord;
use App\Models\Billing\BillingPriceList;
use App\Models\PlatformEsign\Document as PlatformDocument;
use App\Models\RevenueProspect;
use App\Models\Role;
use App\Models\User;
use App\Services\Revenue\RevenueCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** System owner > Revenue: the price list maths, totals, pipeline weighting, cash flow, CRUD and owner-only access. */
class RevenueSectionTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
    }

    public function test_the_published_bands_give_the_expected_monthly_amounts(): void
    {
        $c = new RevenueCalculator();
        $this->assertSame(450.0, $c->listPrice(1));
        $this->assertSame(900.0, $c->listPrice(2));
        $this->assertSame(1495.0, $c->listPrice(3));
        $this->assertSame(1495.0, $c->listPrice(5));
        $this->assertSame(2495.0, $c->listPrice(8));
        $this->assertSame(2495.0, $c->listPrice(10));
        $this->assertSame(3295.0, $c->listPrice(11));
        $this->assertSame(3295.0, $c->listPrice(13));
        $this->assertSame(3995.0, $c->listPrice(17));
        $this->assertSame(4595.0, $c->listPrice(25));
        $this->assertSame(5095.0, $c->listPrice(30));
        $this->assertSame(3995.0 + 1500.0, $c->listPrice(17, 3), 'R750 for each branch after the first');
        $this->assertNull($c->listPrice(31), '31+ agents is on quotation');
        $this->assertNull($c->listPrice(null));
    }

    public function test_signed_totals_and_a_special_deal_override(): void
    {
        RevenueBillingRecord::create(['name' => 'HFC', 'users' => 17]);
        RevenueBillingRecord::create(['name' => 'Mega', 'users' => 1]);
        RevenueBillingRecord::create(['name' => 'Chris Shelter Properties', 'users' => 5]);
        $c = new RevenueCalculator();
        $this->assertSame(['agencies' => 3, 'users' => 23, 'monthly' => 5940.0], $c->signedTotals($c->signed()));

        RevenueBillingRecord::where('name', 'HFC')->update(['override_monthly' => 2500, 'override_note' => 'Founding Partner']);
        $rows = (new RevenueCalculator())->signed()->keyBy('name');
        $this->assertSame(2500.0, $rows['HFC']['monthly']);
        $this->assertSame('Founding Partner', $rows['HFC']['override']);
        $this->assertSame(4445.0, (new RevenueCalculator())->signedTotals((new RevenueCalculator())->signed())['monthly']);
    }

    public function test_the_pipeline_is_a_range_weighted_by_chance_and_totalled(): void
    {
        RevenueProspect::create(['name' => 'Caprivi Properties', 'users_min' => 8, 'users_max' => 13, 'chance_percent' => 50, 'stage' => 'talking']);
        RevenueProspect::create(['name' => 'Neil Roberts', 'users_min' => 5, 'users_max' => 5, 'chance_percent' => 100, 'stage' => 'agreement_back']);
        RevenueProspect::create(['name' => 'Realt Group', 'users_min' => 5, 'users_max' => 5, 'chance_percent' => 80, 'stage' => 'agreement_sent']);
        RevenueProspect::create(['name' => 'Already signed', 'users_min' => 9, 'users_max' => 9, 'chance_percent' => 100, 'stage' => 'signed']);
        $c = new RevenueCalculator();
        $rows = $c->pipeline()->keyBy(fn ($r) => $r['prospect']->name);

        $this->assertSame([2495.0, 3295.0], [$rows['Caprivi Properties']['low'], $rows['Caprivi Properties']['high']]);
        $this->assertSame(1447.5, $rows['Caprivi Properties']['weighted']);   // midpoint 2 895 x 50%
        $this->assertSame(1495.0, $rows['Neil Roberts']['weighted']);
        $this->assertSame(1196.0, $rows['Realt Group']['weighted']);
        $this->assertCount(3, $rows, 'a signed prospect is no longer pipeline');
        $t = $c->pipelineTotals($c->pipeline());
        $this->assertSame([3, 18, 23, 5485.0, 6285.0, 4138.5], [$t['count'], $t['users_min'], $t['users_max'], $t['low'], $t['high'], $t['weighted']]);
    }

    public function test_billing_starts_the_month_after_go_live_in_the_cash_flow(): void
    {
        $now = now()->startOfMonth();
        RevenueBillingRecord::create(['name' => 'Now', 'users' => 5, 'start_date' => $now->copy()->subMonth()]);
        RevenueBillingRecord::create(['name' => 'Later', 'users' => 1, 'start_date' => $now->copy()->addMonths(2)->addDays(9)]);
        RevenueBillingRecord::create(['name' => 'This month', 'users' => 2, 'start_date' => $now->copy()->addDays(3)]);
        RevenueProspect::create(['name' => 'Soon', 'users_min' => 5, 'users_max' => 5, 'chance_percent' => 50, 'stage' => 'talking', 'expected_start_month' => $now->copy()->addMonth()]);
        RevenueProspect::create(['name' => 'No month', 'users_min' => 5, 'users_max' => 5, 'chance_percent' => 100, 'stage' => 'talking']);

        $f = (new RevenueCalculator())->cashFlow();
        $this->assertCount(12, $f);
        $this->assertSame([1495.0, 0.0], [$f[0]['signed'], $f[0]['pipeline']], 'go-live this month is the free take-on month: billed from next month');
        $this->assertSame([1495.0 + 900.0, 0.0], [$f[1]['signed'], $f[1]['pipeline']]);
        $this->assertSame([1495.0 + 900.0, 747.5, 3142.5], [$f[2]['signed'], $f[2]['pipeline'], $f[2]['total']], 'prospect expected to go live next month is billed from the month after');
        $this->assertSame([1495.0 + 900.0 + 450.0, 747.5], [$f[3]['signed'], $f[3]['pipeline']]);
    }

    public function test_only_the_system_owner_can_open_or_change_revenue(): void
    {
        $agency = Agency::create(['name' => 'Some Agency', 'slug' => 'some-agency']);
        $admin = User::factory()->create(['role' => 'admin', 'agency_id' => $agency->id]);

        $this->actingAs($admin)->get(route('admin.revenue.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('admin.revenue.signed.store'), ['name' => 'X'])->assertForbidden();
        $this->assertSame(0, RevenueBillingRecord::count());

        $owner = $this->owner();
        foreach (['signed', 'pipeline', 'cashflow'] as $tab) {
            $this->actingAs($owner)->get(route('admin.revenue.index', ['tab' => $tab]))->assertOk()->assertSee('Revenue');
        }
    }

    public function test_signed_and_pipeline_have_full_crud_search_sort_and_convert(): void
    {
        $owner = $this->owner();
        $agency = Agency::create(['name' => 'Realt Group', 'slug' => 'realt']);

        $this->actingAs($owner)->post(route('admin.revenue.signed.store'), ['name' => 'Mega Real Estate', 'users' => 1])->assertRedirect();
        $this->actingAs($owner)->post(route('admin.revenue.signed.store'), ['name' => 'HFC', 'users' => 17, 'branches' => 1])->assertRedirect();
        $page = $this->actingAs($owner)->get(route('admin.revenue.index', ['tab' => 'signed']))->assertOk();
        $page->assertSee('R 450.00')->assertSee('R 3 995.00')->assertSee('R 4 445.00')->assertSeeInOrder(['HFC', 'Mega Real Estate']);
        $this->actingAs($owner)->get(route('admin.revenue.index', ['tab' => 'signed', 'q' => 'mega']))->assertSee('Mega Real Estate')->assertDontSee('HFC');
        $this->actingAs($owner)->get(route('admin.revenue.index', ['tab' => 'signed', 'sort' => 'users', 'dir' => 'desc']))->assertSeeInOrder(['HFC', 'Mega Real Estate']);

        $hfc = RevenueBillingRecord::where('name', 'HFC')->first();
        $this->actingAs($owner)->delete(route('admin.revenue.signed.archive', $hfc))->assertRedirect();
        $this->actingAs($owner)->get(route('admin.revenue.index', ['tab' => 'signed']))->assertSee('HFC archived.')->assertSee('R 450.00')->assertDontSee('R 3 995.00');
        $this->actingAs($owner)->post(route('admin.revenue.signed.restore', $hfc->id))->assertRedirect();
        $this->assertNull($hfc->fresh()->deleted_at);

        $this->actingAs($owner)->post(route('admin.revenue.pipeline.store'), ['name' => 'Realt Group', 'stage' => 'agreement_sent', 'users_min' => 5, 'users_max' => 5, 'chance_percent' => 80, 'expected_start_month' => '2026-12'])->assertRedirect();
        $p = RevenueProspect::first();
        $this->actingAs($owner)->get(route('admin.revenue.index', ['tab' => 'pipeline']))->assertSee('Realt Group')->assertSee('R 1 196.00');

        $this->actingAs($owner)->post(route('admin.revenue.pipeline.convert', $p), ['agency_id' => $agency->id])->assertRedirect();
        $this->assertSame('signed', $p->fresh()->stage);
        $this->assertSame($agency->id, (int) RevenueBillingRecord::where('name', 'Realt Group')->value('agency_id'));
        $this->actingAs($owner)->get(route('admin.revenue.index', ['tab' => 'pipeline']))->assertSee('No prospects')->assertDontSee('data-row="prospect"', false);
    }

    public function test_the_price_list_lives_in_agency_billing_is_dated_and_revenue_follows_it(): void
    {
        $owner = $this->owner();
        RevenueBillingRecord::create(['name' => 'Mega', 'users' => 1]);
        $std = BillingPriceList::asOf();
        $this->assertSame(450.0, $std['bands'][0]['amount'], 'no saved list = the standard published prices');
        $bands = $std['bands'];

        // a new list from today: Revenue follows at once
        $bands[0]['amount'] = 500;
        $this->actingAs($owner)->post(route('admin.billing.prices.save'), ['effective_from' => now()->toDateString(), 'bands' => $bands, 'branch_rate' => 800])->assertRedirect();
        $this->assertSame(500.0, (new RevenueCalculator())->listPrice(1));
        $this->assertSame(500.0 + 800.0, (new RevenueCalculator())->listPrice(1, 2), 'the branch fee is on the same list');
        $this->actingAs($owner)->get(route('admin.revenue.index', ['tab' => 'signed']))->assertSee('R 500.00');

        // a list dated in the future leaves today (history) alone
        $bands[0]['amount'] = 650;
        $future = now()->addMonths(2)->startOfMonth();
        $this->actingAs($owner)->post(route('admin.billing.prices.save'), ['effective_from' => $future->toDateString(), 'bands' => $bands, 'branch_rate' => 800])->assertRedirect();
        $this->assertSame(500.0, (new RevenueCalculator())->listPrice(1), 'today still uses the list in force today');
        $this->assertSame(650.0, (new RevenueCalculator())->listPrice(1, 1, $future));
        $this->actingAs($owner)->get(route('admin.billing.index'))->assertOk()->assertSee('Price list')->assertSee('Scheduled from');

        // a date in the past would rewrite history: refused. Gaps in the bands: refused. Not the owner: refused.
        $this->actingAs($owner)->post(route('admin.billing.prices.save'), ['effective_from' => now()->subDay()->toDateString(), 'bands' => $bands, 'branch_rate' => 800])->assertSessionHasErrors('effective_from');
        $gap = $bands; unset($gap[1]); $gap = array_values($gap);   // 1 agent, then straight to 3-5: a hole at 2
        $this->actingAs($owner)->post(route('admin.billing.prices.save'), ['effective_from' => $future->toDateString(), 'bands' => $gap, 'branch_rate' => 800])->assertSessionHasErrors('bands');
        $agency = Agency::create(['name' => 'X Agency', 'slug' => 'x-agency']);
        $admin = User::factory()->create(['role' => 'admin', 'agency_id' => $agency->id]);
        $this->actingAs($admin)->post(route('admin.billing.prices.save'), ['effective_from' => now()->toDateString(), 'bands' => $bands, 'branch_rate' => 800])->assertForbidden();
    }

    public function test_signed_agencies_take_their_agent_count_from_the_signed_agreement(): void
    {
        $agency = Agency::create(['name' => 'Agreed Realty', 'slug' => 'agreed']);
        RevenueBillingRecord::create(['name' => 'Agreed Realty', 'agency_id' => $agency->id, 'users' => 2]);   // typed figure, overridden by the agreement
        PlatformDocument::withoutGlobalScopes()->forceCreate([
            'title' => 'CoreX Subscription Agreement', 'status' => 'completed', 'source' => 'webdoc', 'agency_id' => $agency->id,
            'completed_at' => now(), 'rr_data' => ['agents_signed' => '7', 'take_on_month' => '2026-11'],
        ]);
        $row = (new RevenueCalculator())->signed()->first();
        $this->assertSame(7, $row['users']);
        $this->assertTrue($row['users_from_agreement']);
        $this->assertSame(2495.0, $row['monthly']);
        $this->assertSame(7, (new RevenueCalculator())->agreementAgents($agency->id));
        $this->assertNull((new RevenueCalculator())->agreementAgents(999999));
    }
}
