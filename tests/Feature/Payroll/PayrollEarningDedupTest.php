<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Models\Payroll\PayrollEarningType;
use App\Models\Payroll\PayrollEmployee;
use App\Models\Payroll\PayrollEmployeeEarning;
use App\Models\User;
use App\Services\Payroll\PayrollCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-237 B3 — overlapping effective-dated earnings of the SAME type must NOT be
 * summed into double pay. A raise entered as a new Basic row without end-dating
 * the old one leaves both "current" at month-end; the latest version must win.
 */
final class PayrollEarningDedupTest extends TestCase
{
    use RefreshDatabase;

    public function test_overlapping_basic_rows_pay_the_latest_not_the_sum(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'DedupCo', 'slug' => 'dd-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'agent', 'date_of_birth' => '1985-06-15',
        ]);
        $basic = PayrollEarningType::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'code' => 'basic', 'label' => 'Basic Salary',
            'is_taxable' => true, 'affects_uif_remuneration' => true, 'affects_sdl_remuneration' => true, 'sort_order' => 1,
        ]);
        $emp = PayrollEmployee::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'user_id' => $user->id, 'branch_id' => $branchId,
            'employment_date' => '2025-01-01', 'designation_snapshot' => 'Agent',
            'pay_frequency' => 'monthly', 'pay_day_of_month' => 25, 'is_active' => true, 'created_by' => $user->id,
        ]);
        // Original R20 000 (never end-dated) + a raise to R25 000 — BOTH current at 2026-07-31.
        PayrollEmployeeEarning::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'payroll_employee_id' => $emp->id, 'earning_type_id' => $basic->id,
            'amount' => 20000, 'effective_from' => '2025-01-01', 'created_by' => $user->id,
        ]);
        PayrollEmployeeEarning::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'payroll_employee_id' => $emp->id, 'earning_type_id' => $basic->id,
            'amount' => 25000, 'effective_from' => '2026-06-01', 'created_by' => $user->id,
        ]);

        $this->seed(\Database\Seeders\PayrollTaxTableSeeder::class);
        $this->seed(\Database\Seeders\PayrollTaxRebateSeeder::class);

        $calc = (new PayrollCalculator())->calculatePayslip($emp->fresh(), Carbon::parse('2026-07-01'));

        $this->assertSame('25000.00', (string) $calc->totalEarnings, 'latest Basic wins — not R45 000 summed');
    }
}
