<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\Performance\AgencyPerformanceReportService;
use App\Services\Performance\DealStatusSelection;
use App\Services\Performance\PerformanceScope;
use App\Services\Performance\PeriodResolver;
use App\Services\Performance\PerformanceReportScopeResolver;
use App\Services\Performance\ReportPeriodComparator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agency Performance & ROI — the deal-status ticks drive the WHOLE page (2026-11-02).
 *
 * Before: only the four tiles at the top honoured the ticks. The headline sentence and every deal-based
 * card were built over ALL deals including Declined, so declined deals were counted as commission "earned".
 * Now one selection (?status[]=…, absent = Pending+Granted+Registered) reaches every deal-based figure, for
 * the main AND the comparison period, the drilldown, and Print; and it can only filter rows already inside
 * the viewer's own / branch / agency cohort.
 *
 * Fixture (money line = agent / company ex-VAT):
 *   Oct: a1 Pending 10/6 · Granted 20/10 · Registered 30/20 (registered Oct) · Declined 40/25 ·
 *        Declined-but-registration-dated-Oct 0/0 ; a2 Granted 7/4.   Sep: a1 Granted 5/3 · Declined 50/30.
 *   Other agency, Oct: Declined 999/999 (must never appear anywhere).
 */
final class AgencyReportDealStatusTicksTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $other;
    private Branch $b1;
    private User $admin;
    private User $a1;
    private User $a2;

    protected function setUp(): void
    {
        parent::setUp();
        config(['performance.count_untouched_imports' => false]);

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $this->other  = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->b1     = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $otherBranch  = Branch::create(['agency_id' => $this->other->id, 'name' => 'Sea Point']);

        $this->admin = User::factory()->create(['name' => 'Pam Principal', 'role' => 'admin', 'agency_id' => $this->agency->id, 'branch_id' => $this->b1->id]);
        $this->a1    = User::factory()->create(['name' => 'Alice Ramsgate', 'role' => 'agent', 'agency_id' => $this->agency->id, 'branch_id' => $this->b1->id]);
        $this->a2    = User::factory()->create(['name' => 'Colin Ramsgate', 'role' => 'agent', 'agency_id' => $this->agency->id, 'branch_id' => $this->b1->id]);
        $foreign     = User::factory()->create(['name' => 'Frank Foreign', 'role' => 'agent', 'agency_id' => $this->other->id, 'branch_id' => $otherBranch->id]);

        // October (the period under test)
        $this->deal($this->a1, ['accepted_status' => 'P', 'deal_date' => '2026-10-03'], 10, 6);
        $this->deal($this->a1, ['accepted_status' => 'G', 'granted_at' => '2026-10-05', 'deal_date' => '2026-10-05'], 20, 10);
        $this->deal($this->a1, ['accepted_status' => 'R', 'registration_date' => '2026-10-20', 'deal_date' => '2026-10-07'], 30, 20);
        $this->deal($this->a1, ['accepted_status' => 'D', 'deal_date' => '2026-10-09'], 40, 25);
        $this->deal($this->a1, ['accepted_status' => 'D', 'registration_date' => '2026-10-22', 'deal_date' => '2026-10-11'], 0, 0);
        $this->deal($this->a2, ['accepted_status' => 'G', 'granted_at' => '2026-10-12', 'deal_date' => '2026-10-12'], 7, 4);
        // September (the comparison period)
        $this->deal($this->a1, ['accepted_status' => 'G', 'granted_at' => '2026-09-05', 'deal_date' => '2026-09-05'], 5, 3);
        $this->deal($this->a1, ['accepted_status' => 'D', 'deal_date' => '2026-09-15'], 50, 30);
        // Another agency — must never reach this agency's report, whatever the ticks.
        $this->deal($foreign, ['accepted_status' => 'D', 'deal_date' => '2026-10-10', 'agency_id' => $this->other->id, 'branch_id' => $otherBranch->id], 999, 999);
    }

    protected function tearDown(): void
    {
        \App\Services\PermissionService::clearCache();
        parent::tearDown();
    }

    private function deal(User $agent, array $attrs, float $agentMl, float $companyMl): int
    {
        $row = array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->b1->id, 'managed_by_user_id' => $agent->id,
            'period' => substr((string) ($attrs['deal_date'] ?? '2026-10'), 0, 7), 'property_value' => 1_000_000, 'total_commission' => 50_000,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs);
        $id = DB::table('deals')->insertGetId($row);
        DB::table('deal_money_lines')->insert([
            'deal_id' => $id, 'agency_id' => $row['agency_id'], 'user_id' => $agent->id, 'period' => $row['period'],
            'agent_gross_ex_vat' => $agentMl, 'company_gross_ex_vat' => $companyMl, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function build(?DealStatusSelection $sel, string $start, string $end, ?PerformanceScope $scope = null): array
    {
        $period = app(PeriodResolver::class)->resolve('custom', $start, $end);

        return app(AgencyPerformanceReportService::class)->build($scope ?? new PerformanceScope($this->agency->id), $period, $sel);
    }

    private const URL = ['period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-31', 'compare' => 'custom', 'compare_start' => '2026-09-01', 'compare_end' => '2026-09-30'];

    // ───────────────────────── the selection itself ─────────────────────────

    public function test_selection_defaults_to_pending_granted_registered_and_round_trips_through_the_url(): void
    {
        $fromQuery = fn (array $q) => DealStatusSelection::fromRequest(Request::create('/x', 'GET', $q));

        $this->assertSame(['pending', 'granted', 'registered'], $fromQuery([])->buckets(), 'absent = default: Declined is out');
        $this->assertTrue($fromQuery([])->isDefault());
        $this->assertSame([], $fromQuery([])->toQuery(), 'the default needs nothing in the URL');

        $this->assertSame(['pending', 'granted', 'registered', 'declined'], $fromQuery(['status' => ['all']])->buckets());
        $this->assertSame(['granted', 'declined'], $fromQuery(['status' => ['declined', 'granted']])->buckets(), 'order follows the screen, not the URL');
        $this->assertSame(['registered'], $fromQuery(['status' => 'registered'])->buckets(), 'a single string works too');
        $this->assertSame([], $fromQuery(['status' => ['none']])->buckets());
        $this->assertSame(['pending', 'granted', 'registered'], $fromQuery(['status' => ['junk']])->buckets(), 'unusable = default, never a 500');

        $sel = DealStatusSelection::of(['declined', 'pending']);
        $this->assertSame(['pending', 'declined'], $fromQuery($sel->toQuery())->buckets());
        $this->assertSame(['none'], DealStatusSelection::of([])->toQuery()['status']);
        $this->assertTrue($fromQuery(['status' => ['all']])->isEverything());
    }

    // ───────────────────────── the numbers, service level ─────────────────────────

    public function test_declined_deals_leave_every_deal_based_figure_by_default_and_come_back_when_ticked(): void
    {
        $default = $this->build(DealStatusSelection::default(), '2026-10-01', '2026-10-31')['company'];
        $all     = $this->build(DealStatusSelection::all(), '2026-10-01', '2026-10-31')['company'];
        $legacy  = $this->build(null, '2026-10-01', '2026-10-31')['company'];

        // Pending 10 + Granted 20 + Registered 30 + a2 Granted 7 = 67 agent; 6+10+20+4 = 40 company.
        $this->assertEqualsWithDelta(67.0, $default['commission_gross_ex_vat'], 0.001);
        $this->assertEqualsWithDelta(40.0, $default['commission_gross_ex_vat_company'], 0.001);
        $this->assertSame(4, $default['deals_created']);
        $this->assertSame(1, $default['deals_registered'], 'the declined deal that carries a registration date is not a registered deal');

        // Everything ticked = 67+40 agent, 40+25 company; both declined deals back in.
        $this->assertEqualsWithDelta(107.0, $all['commission_gross_ex_vat'], 0.001);
        $this->assertEqualsWithDelta(65.0, $all['commission_gross_ex_vat_company'], 0.001);
        $this->assertSame(6, $all['deals_created']);
        $this->assertSame(2, $all['deals_registered']);

        // No selection given = every status, exactly as before this change (other callers are untouched).
        $this->assertEqualsWithDelta(107.0, $legacy['commission_gross_ex_vat'], 0.001);
        $this->assertSame(6, $legacy['deals_created']);

        // The tiles' own numbers (per-status buckets) stay complete and add up to the same figures on the same ticks.
        $ds = $this->build(DealStatusSelection::default(), '2026-10-01', '2026-10-31')['company']['deal_status'];
        $tileAgent = $ds['pending']['commission'] + $ds['granted']['commission'] + $ds['registered']['commission'];
        $tileCo    = $ds['pending']['company_commission'] + $ds['granted']['company_commission'] + $ds['registered']['company_commission'];
        $this->assertEqualsWithDelta($default['commission_gross_ex_vat'], $tileAgent, 0.001, 'tile agent share = card agent share');
        $this->assertEqualsWithDelta($default['commission_gross_ex_vat_company'], $tileCo, 0.001, 'tile company share = card company share');
        $this->assertSame(4, $ds['pending']['qty'] + $ds['granted']['qty'] + $ds['registered']['qty']);
        $this->assertSame(2, $ds['declined']['qty']);
    }

    public function test_a_single_status_and_nothing_ticked(): void
    {
        $declinedOnly = $this->build(DealStatusSelection::of(['declined']), '2026-10-01', '2026-10-31')['company'];
        $this->assertEqualsWithDelta(40.0, $declinedOnly['commission_gross_ex_vat'], 0.001);
        $this->assertSame(2, $declinedOnly['deals_created']);

        $registeredUnticked = $this->build(DealStatusSelection::of(['pending', 'granted']), '2026-10-01', '2026-10-31')['company'];
        $this->assertSame(0, $registeredUnticked['deals_registered']);

        $none = $this->build(DealStatusSelection::of([]), '2026-10-01', '2026-10-31')['company'];
        $this->assertSame(0, $none['deals_created']);
        $this->assertEqualsWithDelta(0.0, $none['commission_gross_ex_vat'], 0.001);
        $this->assertEqualsWithDelta(0.0, $none['commission_gross_ex_vat_company'], 0.001);
    }

    public function test_deltas_are_main_minus_previous_on_the_same_ticks(): void
    {
        foreach ([DealStatusSelection::default(), DealStatusSelection::all(), DealStatusSelection::of(['declined'])] as $sel) {
            $cur  = $this->build($sel, '2026-10-01', '2026-10-31');
            $prev = $this->build($sel, '2026-09-01', '2026-09-30');
            $cmp  = app(ReportPeriodComparator::class)->build($cur, $prev)['company'];

            foreach (['deals_created', 'deals_registered', 'commission_gross_ex_vat', 'commission_gross_ex_vat_company'] as $k) {
                $this->assertEqualsWithDelta((float) $cur['company'][$k], $cmp[$k]['value'], 0.001, "$k main");
                $this->assertEqualsWithDelta((float) $prev['company'][$k], $cmp[$k]['previous'], 0.001, "$k previous is the compare period's own figure on the same ticks");
                $this->assertEqualsWithDelta($cmp[$k]['value'] - $cmp[$k]['previous'], $cmp[$k]['delta'], 0.001, "$k delta");
            }
            $grossCur  = $cur['company']['commission_gross_ex_vat'] + $cur['company']['commission_gross_ex_vat_company'];
            $grossPrev = $prev['company']['commission_gross_ex_vat'] + $prev['company']['commission_gross_ex_vat_company'];
            $this->assertEqualsWithDelta($grossCur - $grossPrev, $cmp['commission_gross_total']['delta'], 0.001, 'gross delta');
        }

        // Default ticks: Oct 67 vs Sep 5 (Sep's Declined 50 is out of BOTH sides, not just one).
        $d = app(ReportPeriodComparator::class)->build(
            $this->build(DealStatusSelection::default(), '2026-10-01', '2026-10-31'),
            $this->build(DealStatusSelection::default(), '2026-09-01', '2026-09-30'),
        )['company']['commission_gross_ex_vat'];
        $this->assertEqualsWithDelta(5.0, $d['previous'], 0.001);
        $this->assertEqualsWithDelta(62.0, $d['delta'], 0.001);
    }

    // ───────────────────────── the page, the print and the link ─────────────────────────

    private function region(string $html, string $qa): string
    {
        $this->assertSame(1, preg_match('/data-qa="' . preg_quote($qa, '/') . '"[^>]*>(.*?)<\/(?:p|div)>/s', $html, $m), "missing $qa");

        return preg_replace('/\s+/', ' ', trim(html_entity_decode(strip_tags($m[1]))));
    }

    public function test_headline_and_cards_follow_the_ticks_and_show_the_previous_periods_actual_figure(): void
    {
        $this->actingAs($this->admin);

        $page = $this->get(route('performance.agency-report', self::URL))->assertOk()->getContent();
        $this->assertStringContainsString('Counting Pending, Granted, Registered deals (Declined excluded)', preg_replace('/\s+/', ' ', strip_tags($page)));
        $this->assertSame('R 67.00 · ▲ +R 62.00 (+1240%) · 2026-09-01 → 2026-09-30: R 5.00', $this->region($page, 'hero-compare'));
        $this->assertStringContainsString('the agency earned', $page);
        // the card for deals created: 4 now, previous period 1 on the same ticks
        $this->assertMatchesRegularExpression('/▲\s*\+3 \(\+300%\)\s*·\s*2026-09-01 → 2026-09-30: 1/u', preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($page))));

        $all = $this->get(route('performance.agency-report', self::URL + ['status' => ['all']]))->assertOk()->getContent();
        $this->assertStringContainsString('Counting all deals, including Declined', preg_replace('/\s+/', ' ', strip_tags($all)));
        $this->assertSame('R 107.00 · ▲ +R 52.00 (+94.5%) · 2026-09-01 → 2026-09-30: R 55.00', $this->region($all, 'hero-compare'));

        // The ticks reach the page's own controls: the checkboxes start from the selection in the URL.
        $this->assertStringContainsString('statusSelected: JSON.parse(\'[\u0022pending\u0022,\u0022granted\u0022,\u0022registered\u0022]\')', html_entity_decode($page));
        $this->assertStringContainsString('statusSelected: JSON.parse(\'[\u0022pending\u0022,\u0022granted\u0022,\u0022registered\u0022,\u0022declined\u0022]\')', html_entity_decode($all));
    }

    public function test_print_and_a_copied_link_show_the_same_figures_as_the_screen(): void
    {
        $this->actingAs($this->admin);

        foreach ([[], ['status' => ['all']], ['status' => ['declined']], ['status' => ['none']]] as $ticks) {
            $screen = $this->get(route('performance.agency-report', self::URL + $ticks))->assertOk()->getContent();
            $print  = $this->get(route('performance.agency-report.print', self::URL + $ticks))->assertOk()->getContent();

            $this->assertSame($this->region($screen, 'counting-statuses'), $this->region($print, 'counting-statuses'));
            if (preg_match('/data-qa="hero-compare"/', $screen)) {
                $this->assertSame($this->region($screen, 'hero-compare'), $this->region($print, 'hero-compare'), 'headline comparison, ticks ' . json_encode($ticks));
            }
        }

        // The Print button on the screen links to the print route WITH the period, comparison and ticks.
        $screen = $this->get(route('performance.agency-report', self::URL + ['status' => ['granted', 'declined']]))->getContent();
        $this->assertSame(1, preg_match('/href="([^"]*agency-report\/print[^"]*)" target="_blank"/', $screen, $m));
        parse_str((string) parse_url(html_entity_decode($m[1]), PHP_URL_QUERY), $q);
        $this->assertSame('2026-10-01', $q['start']);
        $this->assertSame('2026-09-30', $q['compare_end']);
        $this->assertSame(['granted', 'declined'], $q['status']);
    }

    public function test_drilldown_lists_exactly_the_deals_the_card_counted(): void
    {
        $this->actingAs($this->admin);
        $url = fn (array $q) => route('performance.agency-report.drilldown', $q + ['metric' => 'deals_created', 'level' => 'company', 'period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-31']);

        $this->assertSame(4, $this->getJson($url([]))->assertOk()->json('total'), 'default ticks: Declined deals are not listed');
        $this->assertSame(6, $this->getJson($url(['status' => ['all']]))->json('total'));
        $this->assertSame(2, $this->getJson($url(['status' => ['declined']]))->json('total'));
        $this->assertSame(2, $this->getJson($url(['status' => 'declined']))->json('total'), 'the single-status form still works');

        $commission = fn (array $q) => $this->getJson(route('performance.agency-report.drilldown', $q + ['metric' => 'commission_gross_ex_vat', 'level' => 'company', 'period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-31']));
        $this->assertSame(4, $commission([])->json('total'));
        $this->assertEqualsWithDelta(67.0, array_sum(array_column($commission([])->json('rows'), 'commission')), 0.001);
        $this->assertSame(6, $commission(['status' => ['all']])->json('total'));
    }

    // ───────────────────────── deal date + one deal counted once (ruling G) ─────────────────────────

    public function test_a_joint_deal_is_counted_once_in_branch_and_company_totals_and_agrees_with_the_deal_register(): void
    {
        // A joint deal: a1 manages it, a2 is a co-agent. The Deal Register lists it ONCE.
        $joint = $this->deal($this->a1, ['accepted_status' => 'G', 'granted_at' => '2026-10-14', 'deal_date' => '2026-10-14'], 8, 5);
        DB::table('deal_user')->insert(['deal_id' => $joint, 'user_id' => $this->a2->id]);
        // Captured in October (created_at) but DATED in September: the report goes by the deal date.
        $dated = $this->deal($this->a1, ['accepted_status' => 'G', 'granted_at' => '2026-09-20', 'deal_date' => '2026-09-24', 'created_at' => '2026-10-02 09:00:00'], 1, 1);
        // Captured in September, dated October.
        $this->deal($this->a1, ['accepted_status' => 'P', 'deal_date' => '2026-10-28', 'created_at' => '2026-09-30 09:00:00'], 0, 0);

        foreach ([DealStatusSelection::default(), DealStatusSelection::all(), DealStatusSelection::of(['declined'])] as $sel) {
            $report = $this->build($sel, '2026-10-01', '2026-10-31');

            // The register's own count: deals DATED in October, on the same ticks (its status filter).
            $register = DB::table('deals')->where('agency_id', $this->agency->id)->whereNull('deleted_at')
                ->whereBetween('deal_date', ['2026-10-01', '2026-10-31'])->get(['id', 'accepted_status', 'registration_date', 'granted_at'])
                ->filter(fn ($d) => $sel->has(\App\Services\Performance\DealStatusBreakdownService::dr1Bucket($d)))->count();

            $tiles = $report['company']['deal_status'];
            $tileQty = array_sum(array_map(fn ($b) => $sel->has($b) ? $tiles[$b]['qty'] : 0, $sel::BUCKETS));
            $this->assertSame($register, $tileQty, 'tile deal count = register count, ticks ' . json_encode($sel->buckets()));
            $this->assertSame($register, $report['company']['deals_created'], 'Deals created card = register count, ticks ' . json_encode($sel->buckets()));

            $branchQty = array_sum(array_map(fn ($b) => $sel->has($b) ? $report['branches'][(string) $this->b1->id]['deal_status'][$b]['qty'] : 0, $sel::BUCKETS));
            $this->assertSame($register, $branchQty, 'one branch holding every agent = the same count');
        }

        $default = $this->build(DealStatusSelection::default(), '2026-10-01', '2026-10-31');
        // Oct dated, default ticks: P 03, G 05, R 07, a2 G 12, joint G 14, P 28 = 6 deals (the Sep-dated one is out, the Sep-captured one is in).
        $this->assertSame(6, $default['company']['deals_created']);
        // The two agents each still see the joint deal on their own row.
        $rows = array_column($default['agents'], 'metrics', 'user_id');
        $this->assertSame(5, $rows[$this->a1->id]['deals_created']);
        $this->assertSame(2, $rows[$this->a2->id]['deals_created']);
        // Their commission is each one's OWN line, so it still adds up to the total.
        $this->assertEqualsWithDelta(75.0, $default['company']['commission_gross_ex_vat'], 0.001, '67 + 8');

        $sep = $this->build(DealStatusSelection::default(), '2026-09-01', '2026-09-30');
        $this->assertSame(2, $sep['company']['deals_created'], 'Sep: the Granted 5 and the deal dated 24 Sep (captured in October)');
        $this->assertSame($dated, (int) DB::table('deals')->where('deal_date', '2026-09-24')->value('id'));
    }

    // ───────────────────────── scoping stays exactly as it was ─────────────────────────

    public function test_the_ticks_can_never_widen_who_is_in_the_report(): void
    {
        // An agent's ceiling is their own figures. Ticking Declined / All adds THEIR declined deal, never a colleague's or another agency's.
        $own = app(PerformanceReportScopeResolver::class)->ceiling($this->a1);

        $mine = $this->build(DealStatusSelection::all(), '2026-10-01', '2026-10-31', $own);
        $this->assertSame([$this->a1->id], array_column($mine['agents'], 'user_id'));
        $this->assertEqualsWithDelta(100.0, $mine['company']['commission_gross_ex_vat'], 0.001, 'a1 only: 10+20+30+40');
        $this->assertEqualsWithDelta(61.0, $mine['company']['commission_gross_ex_vat_company'], 0.001, 'a1 only: 6+10+20+25');
        $this->assertSame(5, $mine['company']['deals_created']);

        $mineDefault = $this->build(DealStatusSelection::default(), '2026-10-01', '2026-10-31', $own);
        $this->assertEqualsWithDelta(60.0, $mineDefault['company']['commission_gross_ex_vat'], 0.001);

        // The whole-agency view never includes the other agency's 999/999, whatever is ticked.
        $agencyAll = $this->build(DealStatusSelection::all(), '2026-10-01', '2026-10-31');
        $this->assertEqualsWithDelta(107.0, $agencyAll['company']['commission_gross_ex_vat'], 0.001);
        $this->assertNotContains($this->other->id, array_map(fn ($a) => DB::table('users')->where('id', $a['user_id'])->value('agency_id'), $agencyAll['agents']));
    }

    public function test_an_agent_asking_for_all_statuses_and_a_colleague_still_sees_only_their_own_page_and_drilldown(): void
    {
        $this->actingAs($this->a1);

        $page = $this->get(route('performance.agency-report', self::URL + ['status' => ['all'], 'user_id' => $this->a2->id, 'branch_id' => $this->b1->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Alice Ramsgate', $page);
        $this->assertStringNotContainsString('Colin Ramsgate', substr($page, (int) strpos($page, 'agencyReport({'), 6000));
        $this->assertSame('R 100.00 · ▲ +R 45.00 (+81.8%) · 2026-09-01 → 2026-09-30: R 55.00', $this->region($page, 'hero-compare'));

        $rows = $this->getJson(route('performance.agency-report.drilldown', ['metric' => 'deals_created', 'level' => 'company', 'status' => ['all'], 'period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-31']))->assertOk();
        $this->assertSame(5, $rows->json('total'), 'own five deals, not the colleague\'s sixth');

        $print = $this->get(route('performance.agency-report.print', self::URL + ['status' => ['all'], 'user_id' => $this->a2->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Colin Ramsgate', $print);
        $this->assertSame('R 100.00 · ▲ +R 45.00 (+81.8%) · 2026-09-01 → 2026-09-30: R 55.00', $this->region($print, 'hero-compare'));
    }

    // ───────────────────────── branch / agent pages follow the same ticks (AT-535 follow-on) ─────────────────────────

    private function metricOf(array $journey, string $key): array
    {
        return collect($journey['metrics'])->firstWhere('key', $key);
    }

    public function test_agent_and_branch_journeys_follow_the_ticks_for_both_periods(): void
    {
        $svc = app(AgencyPerformanceReportService::class);
        $period = app(PeriodResolver::class)->resolve('custom', '2026-10-01', '2026-10-31');

        $agentDefault = $svc->agentJourney($this->agency->id, $this->a1->id, $period, null, DealStatusSelection::default());
        $agentAll     = $svc->agentJourney($this->agency->id, $this->a1->id, $period, null, DealStatusSelection::all());
        $agentLegacy  = $svc->agentJourney($this->agency->id, $this->a1->id, $period);

        // a1, October: Pending 10 + Granted 20 + Registered 30 = 60 (+ Declined 40 = 100). The previous period is the 31 days before
        // (1 Sep - 30 Sep), on the SAME ticks: Granted 5 (+ Declined 50 = 55).
        $c = $this->metricOf($agentDefault, 'commission_gross_ex_vat');
        $this->assertEqualsWithDelta(60.0, $c['value'], 0.001);
        $this->assertEqualsWithDelta(5.0, $c['previous'], 0.001);
        $this->assertEqualsWithDelta(55.0, $c['delta'], 0.001);
        $call = $this->metricOf($agentAll, 'commission_gross_ex_vat');
        $this->assertEqualsWithDelta(100.0, $call['value'], 0.001);
        $this->assertEqualsWithDelta(55.0, $call['previous'], 0.001);
        $this->assertEqualsWithDelta(100.0, $this->metricOf($agentLegacy, 'commission_gross_ex_vat')['value'], 0.001, 'no selection = every status, as before');
        $this->assertSame(3, $this->metricOf($agentDefault, 'deals_created')['value']);
        $this->assertSame(5, $this->metricOf($agentAll, 'deals_created')['value']);

        $branchDefault = $svc->branchJourney($this->agency->id, (string) $this->b1->id, $period, null, DealStatusSelection::default());
        $branchAll     = $svc->branchJourney($this->agency->id, (string) $this->b1->id, $period, null, DealStatusSelection::all());
        $this->assertEqualsWithDelta(67.0, $this->metricOf($branchDefault, 'commission_gross_ex_vat')['value'], 0.001);
        $this->assertEqualsWithDelta(107.0, $this->metricOf($branchAll, 'commission_gross_ex_vat')['value'], 0.001);
        $this->assertSame(4, $this->metricOf($branchDefault, 'deals_created')['value']);
        $this->assertSame(6, $this->metricOf($branchAll, 'deals_created')['value']);
    }

    public function test_branch_agent_and_agent_print_pages_show_the_counting_line_and_carry_the_selection_through_their_links(): void
    {
        $this->actingAs($this->admin);
        $q = ['period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-31'];
        $text = fn (string $html) => preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));

        $branch = $this->get(route('performance.agency-report.branch', ['branch' => $this->b1->id] + $q))->assertOk()->getContent();
        $this->assertStringContainsString('Counting Pending, Granted, Registered deals (Declined excluded)', $text($branch));
        $this->assertStringContainsString('Counting all deals, including Declined', $text($this->get(route('performance.agency-report.branch', ['branch' => $this->b1->id, 'status' => ['all']] + $q))->getContent()));

        // links from the branch page keep the period AND the ticks
        $withTicks = $this->get(route('performance.agency-report.branch', ['branch' => $this->b1->id, 'status' => ['granted', 'declined']] + $q))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/href="([^"]*agency-report\/agent\/' . $this->a1->id . '[^"]*)"/', $withTicks, $m));
        parse_str((string) parse_url(html_entity_decode($m[1]), PHP_URL_QUERY), $link);
        $this->assertSame(['granted', 'declined'], $link['status']);
        $this->assertSame('2026-10-01', $link['start']);
        $this->assertStringContainsString('name="status[]" value="granted"', $withTicks, 'changing the period on the page keeps the ticks');

        $agentUrl = route('performance.agency-report.agent', ['user' => $this->a1->id, 'status' => ['all']] + $q);
        $agent = $this->get($agentUrl)->assertOk()->getContent();
        $this->assertStringContainsString('Counting all deals, including Declined', $text($agent));
        $this->assertSame(1, preg_match('/href="([^"]*agency-report\/agent\/' . $this->a1->id . '\/print[^"]*)"/', $agent, $pm));
        parse_str((string) parse_url(html_entity_decode($pm[1]), PHP_URL_QUERY), $printQ);
        $this->assertSame(['pending', 'granted', 'registered', 'declined'], $printQ['status'], 'the selection, spelled out');

        $print = $this->get(route('performance.agency-report.agent.print', ['user' => $this->a1->id] + $q))->assertOk()->getContent();
        $this->assertStringContainsString('Counting Pending, Granted, Registered deals (Declined excluded)', $text($print));
        $printAll = $this->get(route('performance.agency-report.agent.print', ['user' => $this->a1->id, 'status' => ['all']] + $q))->assertOk()->getContent();
        $this->assertStringContainsString('Counting all deals, including Declined', $text($printAll));
        $this->assertNotSame($text($print), $text($printAll), 'the printed figures move with the ticks');
    }

    public function test_company_report_links_to_branch_and_agent_pages_carry_period_and_ticks(): void
    {
        $this->actingAs($this->admin);
        $html = html_entity_decode($this->get(route('performance.agency-report', self::URL + ['status' => ['granted', 'declined']]))->assertOk()->getContent());

        foreach (['branchUrlBase' => '/agency-report/branch/__KEY__', 'agentUrlBase' => '/agency-report/agent/__UID__'] as $key => $path) {
            $this->assertSame(1, preg_match('/' . $key . ": '(.*?)'/", $html, $m), "$key present");
            $url = json_decode('"' . str_replace("'", "\\'", $m[1]) . '"');
            $this->assertStringContainsString($path, $url);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $this->assertSame('custom', $q['period']);
            $this->assertSame('2026-10-01', $q['start']);
            $this->assertSame('2026-10-31', $q['end']);
            $this->assertSame(['granted', 'declined'], $q['status'], "$key carries the ticks");
        }
    }

    public function test_the_commission_column_is_headed_for_the_number_it_holds(): void
    {
        // The branch / agent columns hold the AGENT share (CommissionGrossProvider::forUsers), not the gross the card headlines.
        $report = $this->build(DealStatusSelection::default(), '2026-10-01', '2026-10-31');
        $meta = collect($report['metrics'])->keyBy('key');
        $this->assertSame('Commission (agent share, ex-VAT)', $meta['commission_gross_ex_vat']['column_label']);
        $this->assertSame('Commission (gross ex-VAT)', $meta['commission_gross_ex_vat']['label'], 'the card / drilldown name is unchanged');
        $this->assertSame($meta['deals_created']['label'], $meta['deals_created']['column_label']);

        $this->actingAs($this->admin);
        $q = ['period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-31'];
        $this->assertStringContainsString('Commission (agent share, ex-VAT)', $this->get(route('performance.agency-report.branch', ['branch' => $this->b1->id] + $q))->getContent());
        $this->assertStringContainsString('Commission (agent share, ex-VAT)', $this->get(route('performance.agency-report.agent', ['user' => $this->a1->id] + $q))->getContent());
        $this->assertStringContainsString('Commission (agent share, ex-VAT)', $this->get(route('performance.agency-report.print', self::URL))->getContent());
        $this->assertStringContainsString('Commission (agent share, ex-VAT)', html_entity_decode($this->get(route('performance.agency-report', self::URL))->getContent()));
    }

    public function test_buyer_activity_tiles_show_the_previous_periods_figure_beside_the_delta(): void
    {
        $metrics = \App\Services\Performance\BuyerActivityService::METRICS;
        $cmp = [];
        foreach ($metrics as $m) {
            $cmp[$m['key']] = \App\Services\Performance\PeriodComparison::compute($m['currency'] ? 1500.5 : 5.0, $m['currency'] ? 1000.0 : 3.0, $m['direction']);
        }
        $html = view('performance.agency-report._buyer-summary', [
            'report' => ['period' => ['label' => 'Oct']],
            'buyer' => ['metrics' => $metrics, 'aggregate' => array_map(fn ($c) => $c['value'], $cmp)],
            'buyerComparison' => ['company' => $cmp],
            'comparisonMeta' => ['phrase' => 'vs 2026-09-01 → 2026-09-30', 'range' => '2026-09-01 → 2026-09-30'],
        ])->render();
        $text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));

        $this->assertStringContainsString('▲ +2 (+66.7%) · 2026-09-01 → 2026-09-30: 3', $text, 'a count: delta, then the previous period and its figure');
        $this->assertStringContainsString('▲ +R 500.50 (+50.1%) · 2026-09-01 → 2026-09-30: R 1,000.00', $text, 'Lost value, same format as the other cards');
        $this->assertSame(count($metrics), substr_count($text, '2026-09-01 → 2026-09-30:'), 'all six tiles');
    }

    public function test_the_ticks_on_the_detail_pages_cannot_widen_the_scope(): void
    {
        $this->actingAs($this->a1);
        $q = ['period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-31', 'status' => ['all']];
        $this->get(route('performance.agency-report.agent', ['user' => $this->a2->id] + $q))->assertForbidden();
        $this->get(route('performance.agency-report.agent.print', ['user' => $this->a2->id] + $q))->assertForbidden();
        $this->get(route('performance.agency-report.branch', ['branch' => $this->b1->id] + $q))->assertForbidden();
        $own = $this->get(route('performance.agency-report.agent', ['user' => $this->a1->id] + $q))->assertOk()->getContent();
        $this->assertStringContainsString('100', strip_tags($own), 'their own commission, Declined ticked in');
    }
}
