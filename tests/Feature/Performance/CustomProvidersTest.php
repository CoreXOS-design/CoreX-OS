<?php

namespace Tests\Feature\Performance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Performance\Period;
use App\Services\Performance\Providers\DealsRegisteredProvider;
use App\Services\Performance\Providers\PortalViewsProvider;
use App\Services\Performance\Providers\ViewingsProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-366-B — the providers with hand-written SQL: DR2 deals (pivot, distinct),
 * portal views (SUM via listing agent), and the category-filtered viewings.
 */
class CustomProvidersTest extends TestCase
{
    use RefreshDatabase;

    private function period(): Period
    {
        return new Period(
            CarbonImmutable::parse('2026-08-01 00:00:00'),
            CarbonImmutable::parse('2026-08-31 23:59:59'),
            'August', 'this_month'
        );
    }

    public function test_deals_registered_attributes_via_direct_columns_and_pivot(): void
    {
        $agency = Agency::create(['name' => 'A', 'slug' => 'a']);
        $b = Branch::create(['agency_id' => $agency->id, 'name' => 'B']);
        $a1 = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $b->id]);
        $a2 = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $b->id]);
        $a3 = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $b->id]);
        $other = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $b->id]);

        // Deal 1 — DIRECT columns: listing a1, selling a2 (no pivot). In period.
        $this->makeDeal($agency, $b, $a1, '2026-08-10', $a2->id);
        // Deal 2 — DIRECT: a1 only, in period.
        $this->makeDeal($agency, $b, $a1, '2026-08-20');
        // Deal 3 — PIVOT co-agent a3, direct listing is someone outside the cohort of interest.
        $d3 = $this->makeDeal($agency, $b, $other, '2026-08-15');
        DB::table('deal_v2_agents')->insert([['deal_id' => $d3, 'user_id' => $a3->id, 'side' => 'selling']]);
        // Deal 4 — DIRECT a1 but OUT of period → excluded.
        $this->makeDeal($agency, $b, $a1, '2026-07-01');

        $res = app(DealsRegisteredProvider::class)->forUsers([$a1->id, $a2->id, $a3->id], $this->period());

        $this->assertSame(2, $res[$a1->id], 'a1: d1 + d2 via direct column (d4 out of period).');
        $this->assertSame(1, $res[$a2->id], 'a2: d1 via direct selling column.');
        $this->assertSame(1, $res[$a3->id], 'a3: d3 via the pivot.');
    }

    public function test_portal_views_sum_attributes_via_listing_agent(): void
    {
        $agency = Agency::create(['name' => 'A', 'slug' => 'a']);
        $b = Branch::create(['agency_id' => $agency->id, 'name' => 'B']);
        $a1 = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $b->id]);
        $pid = DB::table('properties')->insertGetId([
            'external_id' => 'EXT' . uniqid(), 'title' => 'Test Prop',
            'agent_id' => $a1->id, 'branch_id' => $b->id, 'agency_id' => $agency->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('property_portal_metrics')->insert([
            ['agency_id' => $agency->id, 'property_id' => $pid, 'portal' => 'p24', 'portal_listing_number' => '1', 'metric_date' => '2026-08-05', 'view_count' => 40],
            ['agency_id' => $agency->id, 'property_id' => $pid, 'portal' => 'pp', 'portal_listing_number' => '2', 'metric_date' => '2026-08-06', 'view_count' => 10],
            ['agency_id' => $agency->id, 'property_id' => $pid, 'portal' => 'p24', 'portal_listing_number' => '1', 'metric_date' => '2026-07-01', 'view_count' => 99],
        ]);

        $res = app(PortalViewsProvider::class)->forUsers([$a1->id], $this->period());

        $this->assertSame(50, $res[$a1->id], '40 + 10 in period; July 99 excluded.');
    }

    public function test_viewings_counts_only_viewing_category_in_period(): void
    {
        $agency = Agency::create(['name' => 'A', 'slug' => 'a']);
        $b = Branch::create(['agency_id' => $agency->id, 'name' => 'B']);
        $a1 = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $b->id]);

        DB::table('calendar_events')->insert([
            ['user_id' => $a1->id, 'event_type' => 'property', 'category' => 'viewing', 'title' => 'V1', 'event_date' => '2026-08-10 10:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $a1->id, 'event_type' => 'property', 'category' => 'viewing', 'title' => 'V2', 'event_date' => '2026-08-11 10:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $a1->id, 'event_type' => 'manual', 'category' => 'meeting', 'title' => 'M', 'event_date' => '2026-08-12 10:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $a1->id, 'event_type' => 'property', 'category' => 'viewing', 'title' => 'Vout', 'event_date' => '2026-07-01 10:00:00', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $res = app(ViewingsProvider::class)->forUsers([$a1->id], $this->period());

        $this->assertSame(2, $res[$a1->id], 'Two in-period viewings; the meeting and the July viewing are excluded.');
    }

    private function makeDeal(Agency $agency, Branch $branch, User $agent, string $reg, ?int $sellingAgentId = null): int
    {
        return DB::table('deals_v2')->insertGetId([
            'reference'         => 'D' . uniqid(),
            'deal_type'         => 'cash',
            'listing_agent_id'  => $agent->id,
            'selling_agent_id'  => $sellingAgentId,
            'purchase_price'    => 1000000,
            'commission_amount' => 50000,
            'commission_vat'    => 7500,
            'offer_date'        => $reg,
            'actual_registration' => $reg,
            'branch_id'         => $branch->id,
            'agency_id'         => $agency->id,
            'created_by_id'     => $agent->id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }
}
