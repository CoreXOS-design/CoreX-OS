<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Http\Controllers\Payroll\PayrollRunController;
use App\Models\Payroll\PayrollEarningType;
use App\Models\Payroll\PayrollEmployee;
use App\Models\Payroll\PayrollEmployeeEarning;
use App\Models\Payroll\PayrollPayslip;
use App\Models\User;
use App\Services\Payroll\PayrollCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-237 B1/B2 — partial-period payroll (Johan's rules): Basic pro-rates to the
 * cut/termination date on the employee's daily_rate_basis; allowances pay FULL;
 * a full month is unchanged; a mid-period leaver ALWAYS gets an auto-generated
 * final payslip even if not selected.
 */
final class PayrollProrationTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $actor;
    private PayrollEarningType $basic;
    private PayrollEarningType $travel;
    private Carbon $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->period = Carbon::parse('2026-07-01');
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'ProrateCo', 'slug' => 'pr-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actor = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'admin', 'is_active' => true,
        ]);
        // Basic pro-rates; Travel allowance pays full.
        $this->basic = PayrollEarningType::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'code' => 'basic', 'label' => 'Basic Salary',
            'is_taxable' => true, 'affects_uif_remuneration' => true, 'affects_sdl_remuneration' => true,
            'pro_rates_on_partial' => true, 'sort_order' => 1,
        ]);
        $this->travel = PayrollEarningType::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'code' => 'travel_allowance_fixed', 'label' => 'Travel Allowance',
            'is_taxable' => true, 'affects_uif_remuneration' => true, 'affects_sdl_remuneration' => true,
            'pro_rates_on_partial' => false, 'sort_order' => 6,
        ]);
        $this->seed(\Database\Seeders\PayrollDeductionTypeSeeder::class); // PAYE/UIF statutory types
        $this->seed(\Database\Seeders\PayrollTaxTableSeeder::class);
        $this->seed(\Database\Seeders\PayrollTaxRebateSeeder::class);
    }

    private function makeEmployee(string $basicAmt, string $travelAmt = '2000', array $over = []): PayrollEmployee
    {
        $user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'date_of_birth' => '1985-06-15',
        ]);
        $emp = PayrollEmployee::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agencyId, 'user_id' => $user->id, 'branch_id' => $this->branchId,
            'employment_date' => '2024-01-01', 'designation_snapshot' => 'Agent',
            'pay_frequency' => 'monthly', 'pay_day_of_month' => 25, 'is_active' => true,
            'daily_rate_basis' => 'calendar_working_days', 'created_by' => $this->actor->id,
        ], $over));
        PayrollEmployeeEarning::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'payroll_employee_id' => $emp->id, 'earning_type_id' => $this->basic->id,
            'amount' => $basicAmt, 'effective_from' => '2024-01-01', 'created_by' => $this->actor->id,
        ]);
        PayrollEmployeeEarning::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'payroll_employee_id' => $emp->id, 'earning_type_id' => $this->travel->id,
            'amount' => $travelAmt, 'effective_from' => '2024-01-01', 'created_by' => $this->actor->id,
        ]);
        return $emp->fresh();
    }

    /** Mon–Fri working days in [a,b] inclusive (default working_days_mask = Mon–Fri). */
    private function wd(Carbon $a, Carbon $b): int
    {
        $n = 0;
        for ($c = $a->copy(); $c->lte($b); $c->addDay()) {
            if ($c->isWeekday()) { $n++; }
        }
        return $n;
    }

    public function test_full_month_pays_full_basic_plus_allowance(): void
    {
        $emp = $this->makeEmployee('30000');
        $calc = (new PayrollCalculator())->calculatePayslip($emp, $this->period); // no cut = full month
        $this->assertSame('32000.00', (string) $calc->totalEarnings, 'full month = R30k basic + R2k travel, no proration');
    }

    public function test_mid_month_cut_prorates_basic_only_allowance_full(): void
    {
        $emp = $this->makeEmployee('30000');
        $cut = Carbon::parse('2026-07-15');
        $factor = $this->wd(Carbon::parse('2026-07-01'), $cut) / $this->wd(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));
        $expectedBasic = round(30000 * $factor, 2);

        $calc = (new PayrollCalculator())->calculatePayslip($emp, $this->period, null, [], $cut);

        $this->assertSame(number_format($expectedBasic + 2000, 2, '.', ''), (string) $calc->totalEarnings,
            'basic pro-rated to the cut, travel allowance FULL');
        $this->assertTrue(bccomp((string) $calc->totalEarnings, '32000', 2) < 0, 'partial < full month');
    }

    public function test_termination_auto_generates_final_payslip_even_if_not_selected(): void
    {
        Auth::login($this->actor);
        $ongoing = $this->makeEmployee('25000');
        $leaver  = $this->makeEmployee('40000', '2000', ['termination_date' => '2026-07-10']);

        // Operator selects ONLY the ongoing employee — the leaver must STILL be paid.
        $req = Request::create('/corex/payroll/runs', 'POST', [
            'period_month' => '2026-07-01', 'pay_date' => '2026-07-25', 'employee_ids' => [$ongoing->id],
        ]);
        $req->setUserResolver(fn () => $this->actor);
        app(PayrollRunController::class)->store($req);

        $leaverSlip = PayrollPayslip::withoutGlobalScopes()->where('payroll_employee_id', $leaver->id)->first();
        $this->assertNotNull($leaverSlip, 'terminated employee auto-included — final payslip generated');
        // Their basic is pro-rated to the 10th; travel full → total < full R42k.
        $this->assertTrue(bccomp((string) $leaverSlip->total_earnings, '42000', 2) < 0, 'leaver basic pro-rated to termination');
        $this->assertTrue(bccomp((string) $leaverSlip->total_earnings, '2000', 2) > 0, 'leaver still paid worked basic + full travel');
    }
}
