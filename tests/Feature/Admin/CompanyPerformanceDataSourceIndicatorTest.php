<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Admin\CompanyPerformanceService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DR2 Financial Audit F4 (AT-410) — Company/Branch Performance can be built
 * two different ways: from finance_computed_values (the Finance Engine, kept
 * correct by the routine per-deal rollup — see AT-408) or, when that cache
 * has no rows yet for a period, a separate inline recomputation with its own
 * bucketing rule (deals.deal_date, not deals.period). Previously nothing on
 * screen said which one produced the numbers being shown. Fixed by exposing
 * $rollup['data_source'] ('engine'|'fallback') and rendering it as a visible
 * badge on both admin.performance and bm.performance.
 *
 * Verified separately (not re-tested here, see report): every (agency,
 * period) with a real deal on QA1 already has engine data — the fallback is
 * not currently serving a wrong number to anyone. This is a design-risk /
 * transparency fix, not a historical-correction finding.
 */
final class CompanyPerformanceDataSourceIndicatorTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Source Badge Co', 'slug' => 'sb-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Role::create(['name' => 'admin', 'label' => 'Admin', 'agency_id' => $this->agencyId]);
        foreach (['view_performance'] as $key) {
            RolePermission::create(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agencyId, 'scope' => 'all']);
        }
        Role::clearCache();
        PermissionService::clearCache();
        $this->admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'admin', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function seedDeal(string $period): void
    {
        DB::table('deals')->insert([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => random_int(600000, 699999), 'period' => $period, 'deal_date' => $period . '-10',
            'accepted_status' => 'R', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'listing_split_percent' => 100, 'selling_split_percent' => 0,
            'listing_external' => 0, 'listing_our_share_percent' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_data_source_is_engine_when_finance_computed_values_has_rows(): void
    {
        $this->seedDeal('2026-06');
        $definitionId = (int) DB::table('finance_definitions')->insertGetId([
            'key' => 'company_period.money.total_nondeclined.ledger_company_income_ex_vat',
            'version' => 1, 'status' => 'active', 'entity_type' => 'company_period', 'value_type' => 'money',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('finance_computed_values')->insert([
            'definition_id' => $definitionId, 'definition_key' => 'company_period.money.total_nondeclined.ledger_company_income_ex_vat',
            'definition_version' => 1, 'entity_type' => 'company_period', 'entity_id' => $this->agencyId,
            'period' => '2026-06', 'value_numeric' => 50000, 'engine_version' => 'v0', 'agency_id' => $this->agencyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rollup = app(CompanyPerformanceService::class)->getPeriodRollup('2026-06', $this->agencyId);

        $this->assertSame('engine', $rollup['data_source']);
    }

    public function test_data_source_is_fallback_when_finance_computed_values_has_no_rows(): void
    {
        $this->seedDeal('2026-07');

        $rollup = app(CompanyPerformanceService::class)->getPeriodRollup('2026-07', $this->agencyId);

        $this->assertSame('fallback', $rollup['data_source']);
    }

    public function test_branch_rollup_data_source_reflects_the_branch_specific_engine_check(): void
    {
        $this->seedDeal('2026-08');

        $rollup = app(CompanyPerformanceService::class)->getBranchRollup($this->branchId, '2026-08', $this->agencyId);

        $this->assertSame('fallback', $rollup['data_source']);
    }

    public function test_admin_performance_page_shows_the_recomputed_badge_when_engine_has_no_data(): void
    {
        $this->seedDeal('2026-09');
        $this->withoutVite();

        $response = $this->actingAs($this->admin)->get(route('admin.performance', ['period' => '2026-09']));

        $response->assertOk();
        $response->assertSee('Recomputed (Engine pending for this period)');
    }
}
