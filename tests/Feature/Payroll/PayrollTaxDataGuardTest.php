<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Exceptions\Payroll\MissingTaxDataException;
use App\Models\Payroll\PayrollEarningType;
use App\Models\Payroll\PayrollEmployee;
use App\Models\Payroll\PayrollEmployeeEarning;
use App\Models\Payroll\PayrollTaxRebate;
use App\Models\User;
use App\Services\Payroll\PayrollCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-237 Batch 1 (C-class) — a missing/out-of-range SARS tax table must be a HARD
 * STOP, never a silent PAYE = R0 payslip. Covers C2 (calculator throws) and
 * C3 (rebate lookup bounded to its actual tax year — no stale forward-fill).
 */
final class PayrollTaxDataGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private PayrollEmployee $emp;
    private Carbon $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->period = Carbon::parse('2026-07-01'); // 2026/27 tax year
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'TaxCo', 'slug' => 'tax-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $branchId, 'role' => 'agent', 'date_of_birth' => '1985-06-15',
        ]);
        $basic = PayrollEarningType::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'code' => 'basic', 'label' => 'Basic Salary',
            'is_taxable' => true, 'affects_uif_remuneration' => true, 'affects_sdl_remuneration' => true, 'sort_order' => 1,
        ]);
        $this->emp = PayrollEmployee::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'user_id' => $user->id, 'branch_id' => $branchId,
            'employment_date' => '2025-01-01', 'designation_snapshot' => 'Agent',
            'pay_frequency' => 'monthly', 'pay_day_of_month' => 25, 'is_active' => true,
            'created_by' => $user->id,
        ]);
        PayrollEmployeeEarning::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'payroll_employee_id' => $this->emp->id,
            'earning_type_id' => $basic->id, 'amount' => 25000, 'effective_from' => '2025-01-01',
            'created_by' => $user->id,
        ]);
    }

    /** C2 — no tax table loaded → calculation is a HARD STOP (throws), not silent R0. */
    public function test_calculator_throws_when_no_tax_table_loaded(): void
    {
        $this->expectException(MissingTaxDataException::class);
        (new PayrollCalculator())->calculatePayslip($this->emp->fresh(), $this->period);
    }

    /** Regression — with the 2026/27 tables seeded it calculates real PAYE (no false refusal). */
    public function test_calculator_succeeds_with_tax_data(): void
    {
        $this->seed(\Database\Seeders\PayrollTaxTableSeeder::class);
        $this->seed(\Database\Seeders\PayrollTaxRebateSeeder::class);
        $calc = (new PayrollCalculator())->calculatePayslip($this->emp->fresh(), $this->period);
        $this->assertTrue(bccomp((string) $calc->payeAmount, '0', 2) > 0, 'R25k in 2026/27 must yield PAYE > 0');
    }

    /** C3 — rebate lookup is bounded to its tax year; an out-of-range year forward-fills NOTHING. */
    public function test_rebate_scope_is_bounded_to_its_tax_year(): void
    {
        $this->seed(\Database\Seeders\PayrollTaxRebateSeeder::class); // 2026/27 only
        $this->assertNotNull(
            PayrollTaxRebate::forTaxYear(Carbon::parse('2026-07-01'))->first(),
            'in-year period must find the rebate'
        );
        $this->assertNull(
            PayrollTaxRebate::forTaxYear(Carbon::parse('2028-07-01'))->first(),
            'out-of-year period must NOT silently forward-fill last year\'s rebate'
        );
    }
}
