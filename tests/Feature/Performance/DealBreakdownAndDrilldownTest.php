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

    public function test_status_buckets_precedence_values_and_all(): void
    {
        $this->dr1(['accepted_status' => 'P', 'property_value' => 1_000_000, 'total_commission' => 40_000]);
        $this->dr1(['accepted_status' => 'G', 'granted_at' => '2026-08-11', 'property_value' => 2_000_000, 'total_commission' => 60_000]);
        $this->dr1(['accepted_status' => 'R', 'registration_date' => '2026-08-12', 'property_value' => 3_000_000, 'total_commission' => 90_000]);
        $this->dr1(['accepted_status' => 'D', 'property_value' => 4_000_000, 'total_commission' => 10_000]);
        // precedence: has BOTH granted_at and registration_date -> Registered wins, not Granted
        $this->dr1(['accepted_status' => 'G', 'granted_at' => '2026-08-05', 'registration_date' => '2026-08-14', 'property_value' => 5_000_000, 'total_commission' => 100_000]);

        $out = app(DealStatusBreakdownService::class)->forUsers([$this->a1->id], $this->period(), $this->ag1->id);
        $a1 = $out['perAgent'][$this->a1->id];

        $this->assertSame(5, $a1['all']['count']);
        $this->assertSame(1, $a1['pending']['count']);
        $this->assertSame(1, $a1['granted']['count']);
        $this->assertSame(2, $a1['registered']['count'], 'the both-set deal falls into Registered by precedence');
        $this->assertSame(1, $a1['declined']['count']);
        $this->assertEqualsWithDelta(15_000_000.0, $a1['all']['value'], 0.01);
        $this->assertEqualsWithDelta(8_000_000.0, $a1['registered']['value'], 0.01, '3m + 5m registered');
        $this->assertEqualsWithDelta(190_000.0, $a1['registered']['commission'], 0.01);
    }

    public function test_co_agent_deal_counts_for_each_agent_but_distinct_once(): void
    {
        $d = $this->dr1(['accepted_status' => 'G', 'granted_at' => '2026-08-11']);
        DB::table('deal_user')->insert(['deal_id' => $d, 'user_id' => $this->a2->id]); // a2 co-agent

        $out = app(DealStatusBreakdownService::class)->forUsers([$this->a1->id, $this->a2->id], $this->period(), $this->ag1->id);
        $this->assertSame(1, $out['perAgent'][$this->a1->id]['granted']['count']);
        $this->assertSame(1, $out['perAgent'][$this->a2->id]['granted']['count']);
        $this->assertSame(1, $out['distinct']['granted']['count'], 'distinct rollup counts the shared deal once');
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
}
