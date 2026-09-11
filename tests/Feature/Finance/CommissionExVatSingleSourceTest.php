<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DR2 Financial Audit F7/F9 (AT-413/AT-415) — Deal::commissionExVat() was
 * byte-copied in three inline WorksheetController spots (each operating on
 * raw stdClass query rows, not hydrated Deal models, so they couldn't call
 * the instance method directly). Extracted the shared arithmetic into
 * Deal::stripVat(float): float, which commissionExVat() itself now calls,
 * so WorksheetController's rows can share the exact same formula.
 *
 * Deliberately did NOT touch FinanceComputeService::dealTotalCommissionExVat()
 * despite it being the same formula — that one is the deliberate independent
 * "new engine" side of AuditService's shadow-compare against
 * Deal::commissionExVat() (via legacy()). Collapsing it would make the audit
 * tool compare a formula against itself, never able to catch a future
 * regression — see that method's own docblock and AT-413's report.
 */
final class CommissionExVatSingleSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_strip_vat_matches_commission_ex_vat_for_a_real_shaped_deal(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'VAT Co', 'slug' => 'vc-' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $deal = Deal::create([
            'agency_id' => $agencyId, 'branch_id' => $branchId,
            'deal_no' => (string) random_int(700000, 799999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'accepted_status' => 'R', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 100_000,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ]);

        $viaInstance = $deal->commissionExVat();
        $viaStatic = Deal::stripVat((float) $deal->total_commission);

        $this->assertSame($viaInstance, $viaStatic);
        $this->assertEqualsWithDelta(86956.52, $viaInstance, 0.01);
    }

    public function test_strip_vat_handles_zero_and_negative_commission_safely(): void
    {
        $this->assertSame(0.0, Deal::stripVat(0.0));
        $this->assertSame(0.0, Deal::stripVat(-100.0));
    }
}
