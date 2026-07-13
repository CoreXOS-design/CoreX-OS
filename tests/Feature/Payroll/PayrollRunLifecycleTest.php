<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Http\Controllers\Payroll\PayrollRunController;
use App\Models\Payroll\PayrollEarningType;
use App\Models\Payroll\PayrollEmployee;
use App\Models\Payroll\PayrollEmployeeEarning;
use App\Models\Payroll\PayrollPayslipLine;
use App\Models\Payroll\PayrollRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-237 Batch 3 (A1/D3) — a cancelled run must free its month (soft-delete/status-blind
 * unique fix), and cancel must SOFT-delete payslip lines (non-negotiable #1), not hard-delete.
 */
final class PayrollRunLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'LifeCo', 'slug' => 'lc-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actor = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $branchId, 'role' => 'admin', 'is_active' => true,
        ]);
        $basic = PayrollEarningType::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'code' => 'basic', 'label' => 'Basic Salary',
            'is_taxable' => true, 'affects_uif_remuneration' => true, 'affects_sdl_remuneration' => true,
            'pro_rates_on_partial' => true, 'sort_order' => 1,
        ]);
        $user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $branchId, 'role' => 'agent', 'date_of_birth' => '1985-06-15',
        ]);
        $emp = PayrollEmployee::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'user_id' => $user->id, 'branch_id' => $branchId,
            'employment_date' => '2024-01-01', 'designation_snapshot' => 'Agent',
            'pay_frequency' => 'monthly', 'pay_day_of_month' => 25, 'is_active' => true, 'created_by' => $this->actor->id,
        ]);
        PayrollEmployeeEarning::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'payroll_employee_id' => $emp->id, 'earning_type_id' => $basic->id,
            'amount' => 30000, 'effective_from' => '2024-01-01', 'created_by' => $this->actor->id,
        ]);
        $this->empId = $emp->id;
        $this->seed(\Database\Seeders\PayrollDeductionTypeSeeder::class);
        $this->seed(\Database\Seeders\PayrollTaxTableSeeder::class);
        $this->seed(\Database\Seeders\PayrollTaxRebateSeeder::class);
        Auth::login($this->actor);
    }

    private int $empId;

    private function createRun(): PayrollRun
    {
        $req = Request::create('/x', 'POST', [
            'period_month' => '2026-07-01', 'pay_date' => '2026-07-25', 'employee_ids' => [$this->empId],
        ]);
        $req->setUserResolver(fn () => $this->actor);
        app(PayrollRunController::class)->store($req);
        return PayrollRun::withoutGlobalScopes()->where('agency_id', $this->agencyId)->orderByDesc('id')->firstOrFail();
    }

    private function cancel(PayrollRun $run): void
    {
        $req = Request::create('/x', 'POST', ['cancellation_reason' => 'test cancel']);
        $req->setUserResolver(fn () => $this->actor);
        app(PayrollRunController::class)->cancel($req, $run->id);
    }

    /** A1 — cancel a month's run, then re-create the same month; no 1062, a fresh run is born. */
    public function test_cancel_then_recreate_same_month_succeeds(): void
    {
        $first = $this->createRun();
        $this->cancel($first);
        $second = $this->createRun();

        $this->assertNotSame($first->id, $second->id, 'a fresh run is created for the month after cancel');
        $this->assertSame('draft', PayrollRun::withoutGlobalScopes()->find($second->id)->status);
        $this->assertSame('cancelled', PayrollRun::withoutGlobalScopes()->find($first->id)->status);
    }

    /** D3 — cancel soft-deletes payslip lines (recoverable), never hard-deletes. */
    public function test_cancel_soft_deletes_payslip_lines(): void
    {
        $run = $this->createRun();
        $lineIds = PayrollPayslipLine::whereHas('payslip', fn ($q) => $q->where('payroll_run_id', $run->id))->pluck('id');
        $this->assertTrue($lineIds->count() > 0, 'the run produced payslip lines');

        $this->cancel($run);

        $this->assertSame(0, PayrollPayslipLine::whereIn('id', $lineIds)->count(), 'lines gone from the live query');
        $this->assertSame($lineIds->count(), PayrollPayslipLine::withTrashed()->whereIn('id', $lineIds)->count(), 'but recoverable — soft-deleted, not hard-deleted');
    }

    /**
     * D2 — a non-draft run is rejected by finalise (the guard + the lock-and-recheck use the
     * same isDraft() verdict, so this covers the concurrency loser's path). The full
     * files-once + no-duplicate-Documents behaviour is Tinker-verified on qa1 (PDF gen needs
     * the node/chromium pipeline that the unit test env doesn't wire up).
     */
    public function test_finalise_rejects_a_nondraft_run(): void
    {
        $run = $this->createRun();
        PayrollRun::withoutGlobalScopes()->where('id', $run->id)->update(['status' => 'finalised']);

        $res = app(\App\Services\Payroll\PayrollFinaliseService::class)->finalise($run->fresh(), $this->actor);

        $this->assertFalse($res['success'], 'finalise refuses a run that is no longer draft (D2 guard/lock verdict)');
    }
}
