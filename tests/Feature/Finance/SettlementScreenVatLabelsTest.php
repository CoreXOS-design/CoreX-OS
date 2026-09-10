<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DR2 Financial Audit F1 (AT-407) — the settlement screen showed three money
 * figures side by side (Total Commission, Pool/Company Portion, External
 * Payable) with NO indication that they sit on two different VAT bases
 * (Deal::commissionExVat()'s own docblock: total_commission is captured
 * Incl VAT; internal pools/allocations are Ex VAT — DealMoneyLineRebuilder's
 * external payable is Incl VAT too, never converted). Reproduced by hand on
 * real deal 6 (deal_no 1665): External Payable showed R50,000 next to a
 * Listing Pool of R43,478.26 — a R6,521.74 gap with nothing telling a reader
 * they're different bases. This is the exact class of error behind deal
 * 1818 (AT-403) — a Branch Manager reads this exact screen before paying.
 *
 * Display-only fix, no calculation touched — proven by hand that the
 * underlying computed figures were always correct for what they represent
 * (external payable Incl VAT is genuinely the right amount to hand an
 * external agency); the defect was purely the missing disclosure. No
 * historical data correction applies to this finding.
 *
 * DR2 has its OWN separate settle view (resources/views/dr2/settle.blade.php)
 * — a verbatim copy of the older Admin/DR1 one
 * (resources/views/admin/deals/settle.blade.php) carrying the identical
 * defect; both are fixed and both are covered here (fix the class, not the
 * instance).
 */
final class SettlementScreenVatLabelsTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $bm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Vat Label Co', 'slug' => 'vl-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Role::create(['name' => 'branch_manager', 'label' => 'Branch Manager', 'agency_id' => $this->agencyId]);
        foreach (['view_deals', 'deals.view', 'settle_deals'] as $key) {
            RolePermission::create(['role' => 'branch_manager', 'permission_key' => $key, 'agency_id' => $this->agencyId, 'scope' => 'all']);
        }
        Role::clearCache();
        PermissionService::clearCache();
        $this->bm = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'branch_manager', 'is_active' => true,
        ]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    /** Mirrors real deal 6 (deal_no 1665): selling side fully external, R100,000 total commission. */
    private function makeExternalSplitDealId(): int
    {
        return (int) DB::table('deals')->insertGetId([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => random_int(300000, 399999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'accepted_status' => 'G', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 100_000,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
            'listing_external' => 0, 'selling_external' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_dr2_settle_screen_labels_every_money_figure_with_its_vat_basis(): void
    {
        $dealId = $this->makeExternalSplitDealId();

        $response = $this->actingAs($this->bm)->get(route('deals-dr2.settle', $dealId));

        $response->assertOk();
        $response->assertSee('Listing Pool (Our share, Ex VAT)');
        $response->assertSee('External payable (Incl VAT): R 0.00', false);
        $response->assertSee('Total Commission (Incl VAT):', false);
        $response->assertSee('External Payable (Incl VAT):', false);
        $response->assertSee('Company Portion (Ex VAT):', false);
        $response->assertSee('Checksum (Ex VAT):', false);
        // The real worked example: R100,000 total (Incl VAT), fully-external selling side
        // pays out R50,000 (Incl VAT) — the correct, unconverted figure — not the R43,478.26
        // ex-VAT-equivalent a reader could mistakenly expect sitting next to an Ex-VAT pool.
        $response->assertSee('R 100,000.00', false);
        $response->assertSee('R 50,000.00', false);
    }

    public function test_admin_settle_screen_labels_every_money_figure_with_its_vat_basis(): void
    {
        $dealId = $this->makeExternalSplitDealId();

        $response = $this->actingAs($this->bm)->get(route('admin.deals.settle', $dealId));

        $response->assertOk();
        $response->assertSee('Selling Pool (Our share, Ex VAT)');
        $response->assertSee('External payable (Incl VAT): R 50,000.00', false);
        $response->assertSee('Total Commission (Incl VAT):', false);
        $response->assertSee('External Payable (Incl VAT):', false);
        $response->assertSee('Company Portion (Ex VAT):', false);
        $response->assertSee('Checksum (Ex VAT):', false);
    }
}
