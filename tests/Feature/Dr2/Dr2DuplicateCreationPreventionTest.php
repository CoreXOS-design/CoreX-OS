<?php

namespace Tests\Feature\Dr2;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Deal;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Duplicate-deal fix, 2026-09-29 — Falan loaded ONE deal on live and got TWO
 * (#1826, #1827): the "Save Deal" button had no double-submit guard and
 * deal_no was allocated by reading MAX(deal_no) with no lock, so two near-
 * simultaneous submits each minted their own "next" number and each
 * inserted a full deal. This covers the three server-side guarantees the
 * fix adds: an idempotency token makes a resubmission a no-op, deal_no
 * allocation is genuinely race-safe (AtomicSequenceServiceTest covers the
 * locking mechanism directly), and the DB-layer unique index is a real
 * backstop even if application logic is ever bypassed.
 */
class Dr2DuplicateCreationPreventionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function payload(int $listingAgentId, int $sellingAgentId, array $overrides = []): array
    {
        return array_merge([
            'period'                => '2026-06',
            'deal_date'             => '2026-06-10',
            'deal_type'             => 'bond',
            'property_value'        => 900000,
            'total_commission'      => 58500,
            'listing_split_percent' => 50,
            'selling_split_percent' => 50,
            'listing_agents'        => [(string) $listingAgentId],
            'selling_agents'        => [(string) $sellingAgentId],
        ], $overrides);
    }

    /** @return array{0:Agency,1:Branch,2:User,3:User,4:User} */
    private function scaffold(string $slug): array
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => $slug]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Shelly Beach']);
        $admin  = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true,
        ]);
        $l = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $s = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        return [$agency, $branch, $admin, $l, $s];
    }

    public function test_the_same_create_token_submitted_twice_yields_exactly_one_deal(): void
    {
        [$agency, $branch, $admin, $l, $s] = $this->scaffold('dr2-dup-token');

        $token = (string) Str::uuid();
        $before = DB::table('deals')->count();

        $payload = $this->payload($l->id, $s->id, [
            'branch_id'        => $branch->id,
            'property_address' => 'Unit 3, Villa Cordoba, 23 Crown Road, St Michaels On Sea',
            'seller_name'      => 'Leanne Scott',
            'buyer_name'       => 'Linda Trytsman',
            'create_token'     => $token,
        ]);

        $first = $this->actingAs($admin)->post(route('deals-dr2.store'), $payload);
        $first->assertRedirect(route('deals-dr2.index'))->assertSessionHasNoErrors();

        // Same token, same submission — the double-click / Enter+click case.
        $second = $this->actingAs($admin)->post(route('deals-dr2.store'), $payload);
        $second->assertRedirect(route('deals-dr2.index'));

        $this->assertSame($before + 1, DB::table('deals')->count(), 'a resubmitted token must never create a second deal');
        $this->assertSame(
            1,
            DB::table('deals')->where('agency_id', $agency->id)->where('create_token', $token)->count(),
            'exactly one deal row carries this token'
        );
    }

    public function test_two_different_submissions_get_distinct_consecutive_deal_numbers(): void
    {
        [$agency, $branch, $admin, $l, $s] = $this->scaffold('dr2-dup-consecutive');

        $this->actingAs($admin)->post(route('deals-dr2.store'), $this->payload($l->id, $s->id, [
            'branch_id' => $branch->id, 'property_address' => 'Property A', 'create_token' => (string) Str::uuid(),
        ]))->assertRedirect(route('deals-dr2.index'));

        $this->actingAs($admin)->post(route('deals-dr2.store'), $this->payload($l->id, $s->id, [
            'branch_id' => $branch->id, 'property_address' => 'Property B', 'create_token' => (string) Str::uuid(),
        ]))->assertRedirect(route('deals-dr2.index'));

        $deals = Deal::withoutGlobalScopes()->where('agency_id', $agency->id)->orderBy('id')->get();
        $this->assertCount(2, $deals);
        $this->assertSame((int) $deals[0]->deal_no + 1, (int) $deals[1]->deal_no, 'consecutive, not colliding');
    }

    public function test_duplicate_create_token_is_rejected_at_the_db_layer_even_bypassing_the_app_check(): void
    {
        [$agency, $branch] = $this->scaffold('dr2-dup-db-layer');
        $token = (string) Str::uuid();

        DB::table('deals')->insert([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'deal_no' => '5001',
            'create_token' => $token, 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'property_value' => 100, 'total_commission' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('deals')->insert([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'deal_no' => '5002',
            'create_token' => $token, 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'property_value' => 200, 'total_commission' => 20,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_duplicate_agency_deal_no_is_rejected_at_the_db_layer_even_bypassing_the_app_check(): void
    {
        [$agency, $branch] = $this->scaffold('dr2-dup-dealno-db-layer');

        DB::table('deals')->insert([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'deal_no' => '6001',
            'period' => '2026-06', 'deal_date' => '2026-06-10',
            'property_value' => 100, 'total_commission' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('deals')->insert([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'deal_no' => '6001', // same agency, same number
            'period' => '2026-06', 'deal_date' => '2026-06-10',
            'property_value' => 200, 'total_commission' => 20,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
