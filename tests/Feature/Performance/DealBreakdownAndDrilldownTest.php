<?php

namespace Tests\Feature\Performance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\Performance\DealStatusBreakdownService;
use App\Services\Performance\PerformanceDrilldownService;
use App\Services\Performance\Period;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** AT-366 (6)+(9) — deal status breakdown + agency-scoped drilldown. */
class DealBreakdownAndDrilldownTest extends TestCase
{
    use RefreshDatabase;

    private Agency $ag1;
    private Agency $ag2;
    private Branch $b1;
    private User $a1;
    private User $a2;

    private function period(): Period
    {
        return new Period(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31 23:59:59'), 'Aug', 'this_month');
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['performance.count_untouched_imports' => false]);
        $this->ag1 = Agency::create(['name' => 'A1', 'slug' => 'a1']);
        $this->ag2 = Agency::create(['name' => 'A2', 'slug' => 'a2']);
        $this->b1  = Branch::create(['agency_id' => $this->ag1->id, 'name' => 'B']);
        $this->a1  = User::factory()->create(['agency_id' => $this->ag1->id, 'branch_id' => $this->b1->id]);
        $this->a2  = User::factory()->create(['agency_id' => $this->ag1->id, 'branch_id' => $this->b1->id]);
    }

    private function dr1(array $a): int
    {
        return DB::table('deals')->insertGetId(array_merge([
            'agency_id' => $this->ag1->id, 'branch_id' => $this->b1->id, 'managed_by_user_id' => $this->a1->id,
            'period' => '2026-08', 'deal_date' => '2026-08-10', 'property_value' => 1_000_000, 'total_commission' => 50_000,
            'created_at' => now(), 'updated_at' => now(),
        ], $a));
    }

    /** Money line = the canonical GROSS EX-VAT commission source (as CommissionGrossProvider). */
    private function ml(int $dealId, int $userId, float $exVat): void
    {
        DB::table('deal_money_lines')->insert([
            'deal_id' => $dealId, 'agency_id' => $this->ag1->id, 'user_id' => $userId,
            'period' => '2026-08', 'agent_gross_ex_vat' => $exVat, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_status_buckets_precedence_values_and_all(): void
    {
        $this->dr1(['accepted_status' => 'P', 'property_value' => 1_000_000, 'total_commission' => 40_000]);
        $this->dr1(['accepted_status' => 'G', 'granted_at' => '2026-08-11', 'property_value' => 2_000_000, 'total_commission' => 60_000]);
        $dR = $this->dr1(['accepted_status' => 'R', 'registration_date' => '2026-08-12', 'property_value' => 3_000_000, 'total_commission' => 90_000]);
        $this->dr1(['accepted_status' => 'D', 'property_value' => 4_000_000, 'total_commission' => 10_000]);
        // precedence: has BOTH granted_at and registration_date -> Registered wins, not Granted
        $dGR = $this->dr1(['accepted_status' => 'G', 'granted_at' => '2026-08-05', 'registration_date' => '2026-08-14', 'property_value' => 5_000_000, 'total_commission' => 100_000]);
        // Commission is now GROSS EX-VAT from money lines (reconciles with the KPI), not total_commission.
        $this->ml($dR, $this->a1->id, 78_000);
        $this->ml($dGR, $this->a1->id, 87_000);

        $out = app(DealStatusBreakdownService::class)->forUsers([$this->a1->id], $this->period(), $this->ag1->id);
        $a1 = $out['perAgent'][$this->a1->id];

        $this->assertSame(5, $a1['all']['count']);
        $this->assertSame(1, $a1['pending']['count']);
        $this->assertSame(1, $a1['granted']['count']);
        $this->assertSame(2, $a1['registered']['count'], 'the both-set deal falls into Registered by precedence');
        $this->assertSame(1, $a1['declined']['count']);
        $this->assertEqualsWithDelta(15_000_000.0, $a1['all']['value'], 0.01);
        $this->assertEqualsWithDelta(8_000_000.0, $a1['registered']['value'], 0.01, '3m + 5m registered');
        // commission = money-line ex-VAT for the two registered deals (78k + 87k); other buckets have no money lines
        $this->assertEqualsWithDelta(165_000.0, $a1['registered']['commission'], 0.01);
        $this->assertEqualsWithDelta(0.0, $a1['pending']['commission'], 0.01, 'no money line → 0 ex-VAT commission');
        $this->assertEqualsWithDelta(165_000.0, $a1['all']['commission'], 0.01);
    }

    public function test_build_rollup_deal_status_carries_commission_per_bucket(): void
    {
        // Commission-tile fix: the report rollup's deal_status must expose commission per bucket
        // (gross ex-VAT from money lines, reconciling with the KPI) so the toggles recompute
        // commission for selected statuses AND the All-selected total == the Commission KPI.
        $dR = $this->dr1(['accepted_status' => 'R', 'registration_date' => '2026-08-12', 'property_value' => 3_000_000, 'total_commission' => 90_000]);
        $dP = $this->dr1(['accepted_status' => 'P', 'property_value' => 1_000_000, 'total_commission' => 40_000]);
        $this->ml($dR, $this->a1->id, 78_000);   // registered ex-VAT commission
        $this->ml($dP, $this->a1->id, 30_000);   // pending ex-VAT commission

        $report = app(\App\Services\Performance\AgencyPerformanceReportService::class)
            ->build(new \App\Services\Performance\PerformanceScope($this->ag1->id), $this->period());

        $ds = $report['company']['deal_status'];
        $this->assertArrayHasKey('commission', $ds['registered'], 'deal_status buckets must carry commission');
        $this->assertArrayHasKey('commission', $ds['pending']);
        $this->assertEqualsWithDelta(78_000.0, $ds['registered']['commission'], 0.01);
        $this->assertEqualsWithDelta(30_000.0, $ds['pending']['commission'], 0.01);
        $this->assertEqualsWithDelta(0.0, $ds['granted']['commission'], 0.01);
        // All-selected commission == the Commission (gross ex-VAT) KPI card total
        $allComm = $ds['pending']['commission'] + $ds['granted']['commission'] + $ds['registered']['commission'] + $ds['declined']['commission'];
        $this->assertEqualsWithDelta((float) ($report['company']['commission_gross_ex_vat'] ?? 0), $allComm, 0.01, 'All-selected commission must equal the KPI');
        // qty + value still intact alongside commission
        $this->assertSame(1, $ds['registered']['qty']);
        $this->assertEqualsWithDelta(3_000_000.0, $ds['registered']['value'], 0.01);
    }

    public function test_co_agent_deal_counts_for_each_agent_but_distinct_once(): void
    {
        $d = $this->dr1(['accepted_status' => 'G', 'granted_at' => '2026-08-11']);
        DB::table('deal_user')->insert(['deal_id' => $d, 'user_id' => $this->a2->id]); // a2 co-agent
        // each co-agent has their OWN money line (ex-VAT split)
        $this->ml($d, $this->a1->id, 50_000);
        $this->ml($d, $this->a2->id, 40_000);

        $out = app(DealStatusBreakdownService::class)->forUsers([$this->a1->id, $this->a2->id], $this->period(), $this->ag1->id);
        $this->assertSame(1, $out['perAgent'][$this->a1->id]['granted']['count']);
        $this->assertSame(1, $out['perAgent'][$this->a2->id]['granted']['count']);
        $this->assertSame(1, $out['distinct']['granted']['count'], 'distinct rollup counts the shared deal once');
        // per-agent commission = that agent's OWN money line (not the full deal)
        $this->assertEqualsWithDelta(50_000.0, $out['perAgent'][$this->a1->id]['granted']['commission'], 0.01);
        $this->assertEqualsWithDelta(40_000.0, $out['perAgent'][$this->a2->id]['granted']['commission'], 0.01);

        // and the COMPANY rollup (sum over agents) must NOT double-count the co-agent deal:
        // company commission == 50k + 40k == the KPI, not 180k.
        $report = app(\App\Services\Performance\AgencyPerformanceReportService::class)
            ->build(new \App\Services\Performance\PerformanceScope($this->ag1->id), $this->period());
        $this->assertEqualsWithDelta(90_000.0, $report['company']['deal_status']['granted']['commission'], 0.01, 'co-agent commission must not double-count in the company rollup');
        $this->assertEqualsWithDelta((float) ($report['company']['commission_gross_ex_vat'] ?? 0), $report['company']['deal_status']['granted']['commission'], 0.01, 'company deal_status commission == the KPI');
    }

    public function test_breakdown_is_agency_scoped(): void
    {
        $this->dr1(['accepted_status' => 'P']);                          // agency 1
        $this->dr1(['accepted_status' => 'P', 'agency_id' => $this->ag2->id]); // agency 2, same managed_by → must be excluded

        $out = app(DealStatusBreakdownService::class)->forUsers([$this->a1->id], $this->period(), $this->ag1->id);
        $this->assertSame(1, $out['perAgent'][$this->a1->id]['all']['count'], 'only the agency-1 deal counts');
    }

    public function test_drilldown_deals_with_status_filter(): void
    {
        $this->dr1(['accepted_status' => 'R', 'registration_date' => '2026-08-12', 'property_address' => '1 Reg Rd']);
        $this->dr1(['accepted_status' => 'D', 'property_address' => '2 Dec St']);
        $drill = app(PerformanceDrilldownService::class);
        $all = $drill->rows('deals', [$this->a1->id], $this->period(), $this->ag1->id, null);
        $this->assertSame(2, $all['count']);
        $reg = $drill->rows('deals', [$this->a1->id], $this->period(), $this->ag1->id, 'registered');
        $this->assertSame(1, $reg['count']);
        $this->assertSame('registered', $reg['rows'][0]['status']);
        $this->assertSame('1 Reg Rd', $reg['rows'][0]['address']);
        $this->assertEquals(50000.0, $reg['rows'][0]['commission']);
    }

    public function test_drilldown_contacts_excludes_imports_and_other_agencies(): void
    {
        $mk = fn (array $x) => DB::table('contacts')->insert(array_merge([
            'agency_id' => $this->ag1->id, 'branch_id' => $this->b1->id, 'created_by_user_id' => $this->a1->id,
            'agent_id' => $this->a1->id, 'first_name' => 'F', 'last_name' => 'L',
            'created_at' => '2026-08-10', 'updated_at' => now(),
        ], $x));
        $mk(['loaded_at' => null]);                                         // native → in
        $mk(['loaded_at' => '2026-06-17']);                                 // untouched import → OUT
        $mk(['loaded_at' => '2026-06-17', 'buyer_pipeline_entered_at' => '2026-08-05']); // worked import → in
        $mk(['loaded_at' => null, 'agency_id' => $this->ag2->id]);          // other agency → OUT

        $res = app(PerformanceDrilldownService::class)->rows('contacts_created', [$this->a1->id], $this->period(), $this->ag1->id);
        $this->assertSame(2, $res['count'], 'native + worked-import only; import + other-agency excluded');
    }

    public function test_drilldown_appointments_scoped_by_category_and_agency(): void
    {
        $mk = fn (array $x) => DB::table('calendar_events')->insert(array_merge([
            'agency_id' => $this->ag1->id, 'user_id' => $this->a1->id, 'category' => 'viewing', 'event_type' => 'appointment',
            'title' => 'Appt', 'event_date' => '2026-08-10', 'created_at' => now(), 'updated_at' => now(),
        ], $x));
        $mk(['category' => 'viewing']);                                  // in
        $mk(['category' => 'meeting']);                                  // in
        $mk(['category' => 'personal']);                                 // OUT (not an appointment category)
        $mk(['category' => 'viewing', 'agency_id' => $this->ag2->id]);   // OUT (other agency)

        $res = app(PerformanceDrilldownService::class)->rows('appointments', [$this->a1->id], $this->period(), $this->ag1->id);
        $this->assertSame(2, $res['count']);
        $this->assertArrayHasKey('event_date', $res['rows'][0]);
    }

    public function test_drilldown_outreach_scoped_by_agency(): void
    {
        $cid = DB::table('contacts')->insertGetId([
            'agency_id' => $this->ag1->id, 'branch_id' => $this->b1->id, 'created_by_user_id' => $this->a1->id,
            'agent_id' => $this->a1->id, 'first_name' => 'Sipho', 'last_name' => 'Ndlovu',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $code = 0;
        $mk = function (array $x) use ($cid, &$code) {
            $code++;
            return DB::table('seller_outreach_sends')->insert(array_merge([
                'agency_id' => $this->ag1->id, 'agent_id' => $this->a1->id, 'contact_id' => $cid, 'channel' => 'whatsapp',
                'address_snapshot' => '1 Sea Rd', 'body_snapshot' => 'Hi', 'facts_snapshot' => '{}',
                'tracking_short_code' => str_pad((string) $code, 6, '0', STR_PAD_LEFT), 'sent_at' => '2026-08-10 09:00:00',
                'created_at' => now(), 'updated_at' => now(),
            ], $x));
        };
        $mk([]);                                        // in
        $mk(['channel' => 'email']);                    // in
        $mk(['agency_id' => $this->ag2->id]);           // OUT (other agency)

        $res = app(PerformanceDrilldownService::class)->rows('outreach_messages', [$this->a1->id], $this->period(), $this->ag1->id);
        $this->assertSame(2, $res['count']);
        $this->assertArrayHasKey('channel', $res['rows'][0]);
    }

    public function test_drilldown_commission_sums_money_lines_agency_scoped(): void
    {
        $d1 = $this->dr1(['property_address' => '9 Cliff Ave']);
        $d2 = $this->dr1(['property_address' => '9 Cliff Ave', 'agency_id' => $this->ag2->id]);
        DB::table('deal_money_lines')->insert([
            ['deal_id' => $d1, 'agency_id' => $this->ag1->id, 'period' => '2026-08', 'user_id' => $this->a1->id, 'agent_gross_ex_vat' => 25_000, 'created_at' => now(), 'updated_at' => now()],
            ['deal_id' => $d2, 'agency_id' => $this->ag2->id, 'period' => '2026-08', 'user_id' => $this->a1->id, 'agent_gross_ex_vat' => 99_000, 'created_at' => now(), 'updated_at' => now()], // other agency deal → OUT
        ]);
        $res = app(PerformanceDrilldownService::class)->rows('commission', [$this->a1->id], $this->period(), $this->ag1->id);
        $this->assertSame(1, $res['count'], 'only the agency-1 deal money line');
        $this->assertEqualsWithDelta(25_000.0, $res['rows'][0]['commission'], 0.01);
    }
}
