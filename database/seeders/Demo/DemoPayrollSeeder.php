<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Http\Controllers\Payroll\PayrollRunController;
use App\Models\Payroll\PayrollEarningType;
use App\Models\Payroll\PayrollDeductionType;
use App\Models\Payroll\PayrollEmployee;
use App\Models\Payroll\PayrollEmployeeDeduction;
use App\Models\Payroll\PayrollEmployeeEarning;
use App\Models\Payroll\PayrollPayslip;
use App\Models\Payroll\PayrollRun;
use App\Models\User;
use App\Models\UserBankingDetail;
use App\Services\Payroll\PayrollFinaliseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

/**
 * Seeds payroll employees (+ bank details + earnings/deductions) for the
 * demo staff, and drives real payroll runs through the real controller/
 * calculator/finalise-service pipeline — the webinar (Johan, 2026-09-03)
 * Payroll screens currently show "no employees" / "no runs" for agency 1.
 *
 * Deliberately driven through App\Http\Controllers\Payroll\PayrollRunController
 * ::store() and App\Services\Payroll\PayrollFinaliseService::finalise(), not
 * hand-inserted totals — PAYE/UIF/SDL math is nontrivial (bracket lookups,
 * rebates, proration) and depends on the already-seeded 2026/27 SARS tax
 * tables; driving the real pipeline guarantees internally-consistent numbers
 * and real generated payslip PDFs, exactly like a real payroll run.
 *
 * ANONYMISATION: id numbers, tax reference numbers, and bank details are all
 * deliberately obviously-synthetic — repeating zero blocks, sequential
 * tails, a fictional "Demo Bank Ltd" — never a real-looking SA ID or a real
 * bank's real account-numbering shape. PayrollRunController hardcodes an
 * "HFC-" prefix on generated payslip numbers (pre-existing application
 * code, not this seeder's doing — flagged separately to Johan); this
 * seeder renames its OWN seeded payslip numbers to a "PAY-" prefix
 * immediately after creation, before any PDF is generated, so nothing
 * HFC-branded appears anywhere in the seeded demo data.
 *
 * IDEMPOTENT: each employee is firstOrCreate-keyed on (agency_id, user_id);
 * banking/earnings/deductions are only added if missing; runs are only
 * created for a period_month that doesn't already have one for this agency.
 */
final class DemoPayrollSeeder
{
    /** user_id => [designation, monthly basic salary in ZAR]. */
    private function employeePlan(): array
    {
        return [
            1  => ['Managing Director', 45000, 6],
            2  => ['Branch Manager', 32000, 5],
            3  => ['Sales Agent', 16500, 4],
            4  => ['Sales Agent', 17800, 3],
            6  => ['Branch Manager', 33500, 4],
            7  => ['Sales Agent', 19200, 3],
            8  => ['Sales Agent', 21000, 1],
            9  => ['Sales Agent', 18500, 2],
            10 => ['Branch Manager', 31000, 5],
            11 => ['Sales Agent', 20200, 3],
            12 => ['Sales Agent', 17200, 2],
            13 => ['Sales Agent', 19800, 1],
            // 5, 9 excluded here already covered; 14 (viewer) intentionally
            // excluded — not a real paid staff member.
        ];
    }

    public function run(int $agencyId): array
    {
        $notes = [];
        $admin = User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('role', 'admin')->first();
        if (!$admin) {
            return ['created' => 0, 'skipped' => 0, 'notes' => ['FAILED: no admin user found for agency ' . $agencyId]];
        }

        [$empCreated, $empSkipped, $empNotes] = $this->seedEmployees($agencyId, $admin);
        $notes = array_merge($notes, $empNotes);

        [$runsCreated, $runNotes] = $this->seedRuns($agencyId, $admin);
        $notes = array_merge($notes, $runNotes);

        return ['created' => $empCreated + $runsCreated, 'skipped' => $empSkipped, 'notes' => $notes];
    }

    private function seedEmployees(int $agencyId, User $admin): array
    {
        $plan = $this->employeePlan();
        $users = User::withoutGlobalScopes()->whereIn('id', array_keys($plan))->get()->keyBy('id');

        $basicType = PayrollEarningType::where('agency_id', $agencyId)->where('code', 'basic')->first();
        $travelType = PayrollEarningType::where('agency_id', $agencyId)->where('code', 'travel_allowance_fixed')->first();
        $cellType = PayrollEarningType::where('agency_id', $agencyId)->where('code', 'cell_allowance')->first();
        $cellDeductionType = PayrollDeductionType::where('agency_id', $agencyId)->where('code', 'cellphone_deduction')->first();

        $created = 0;
        $skipped = 0;
        $notes = [];
        $i = 0;

        foreach ($plan as $userId => [$designation, $basicSalary, $tenureYears]) {
            $i++;
            $user = $users->get($userId);
            if (!$user) {
                $notes[] = "SKIPPED: user_id={$userId} not found";
                continue;
            }

            $employmentDate = now()->subYears($tenureYears)->subMonths($i % 5)->startOfMonth();
            $birthYear = 1970 + (($i * 3) % 30); // spreads ages ~26-56
            $birthMonth = str_pad((string) ((($i * 7) % 12) + 1), 2, '0', STR_PAD_LEFT);
            $birthDay = str_pad((string) ((($i * 11) % 27) + 1), 2, '0', STR_PAD_LEFT);
            $dob = Carbon::createFromDate($birthYear, (int) $birthMonth, (int) $birthDay);
            $idNumber = substr((string) $birthYear, 2, 2) . $birthMonth . $birthDay . '00000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $taxRef = '0000000' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);

            $alreadySeeded = (bool) $user->tax_reference_number || PayrollEmployee::withoutGlobalScopes()->where('user_id', $userId)->exists();

            if ($alreadySeeded) {
                $skipped++;
                $notes[] = "SKIPPED (already seeded): {$user->name} (id={$userId})";
                continue;
            }

            $user->id_number = $idNumber;
            $user->tax_reference_number = $taxRef;
            $user->date_of_birth = $dob->toDateString();
            $user->employment_date = $employmentDate->toDateString();
            $user->save();

            $payrollEmployee = PayrollEmployee::withoutGlobalScopes()->create([
                'agency_id'             => $agencyId,
                'branch_id'             => $user->branch_id,
                'user_id'               => $userId,
                'employment_date'       => $employmentDate->toDateString(),
                'designation_snapshot'  => $designation,
                'is_active'             => true,
                'created_by'            => $admin->id,
            ]);

            UserBankingDetail::withoutGlobalScopes()->create([
                'user_id'        => $userId,
                'agency_id'      => $agencyId,
                'account_holder' => $user->name,
                'bank_name'      => 'Demo Bank Ltd',
                'branch_code'    => '000000',
                'account_number' => '00000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'account_type'   => 'cheque',
                'is_primary'     => true,
                'verified_at'    => $employmentDate->copy()->addDays(7),
                'verified_by'    => $admin->id,
            ]);

            if ($basicType) {
                PayrollEmployeeEarning::withoutGlobalScopes()->create([
                    'agency_id'            => $agencyId,
                    'payroll_employee_id'  => $payrollEmployee->id,
                    'earning_type_id'      => $basicType->id,
                    'amount'               => $basicSalary,
                    'effective_from'       => $employmentDate->toDateString(),
                    'notes'                => 'Basic salary.',
                    'created_by'           => $admin->id,
                ]);
            }

            if (str_contains($designation, 'Manager') || str_contains($designation, 'Director')) {
                if ($cellType) {
                    PayrollEmployeeEarning::withoutGlobalScopes()->create([
                        'agency_id'            => $agencyId,
                        'payroll_employee_id'  => $payrollEmployee->id,
                        'earning_type_id'      => $cellType->id,
                        'amount'               => 1500,
                        'effective_from'       => $employmentDate->toDateString(),
                        'notes'                => 'Cell phone allowance.',
                        'created_by'           => $admin->id,
                    ]);
                }
            } else {
                if ($travelType) {
                    PayrollEmployeeEarning::withoutGlobalScopes()->create([
                        'agency_id'            => $agencyId,
                        'payroll_employee_id'  => $payrollEmployee->id,
                        'earning_type_id'      => $travelType->id,
                        'amount'               => 2500,
                        'effective_from'       => $employmentDate->toDateString(),
                        'notes'                => 'Fixed travel allowance.',
                        'created_by'           => $admin->id,
                    ]);
                }
            }

            // A couple of employees carry a small voluntary deduction, for realism.
            if ($cellDeductionType && in_array($userId, [4, 12], true)) {
                PayrollEmployeeDeduction::withoutGlobalScopes()->create([
                    'agency_id'            => $agencyId,
                    'payroll_employee_id'  => $payrollEmployee->id,
                    'deduction_type_id'    => $cellDeductionType->id,
                    'amount'               => 250,
                    'effective_from'       => $employmentDate->toDateString(),
                    'notes'                => 'Company cellphone contribution.',
                    'created_by'           => $admin->id,
                ]);
            }

            $created++;
            $notes[] = "CREATED: {$user->name} (id={$userId}) → {$designation}, basic R" . number_format($basicSalary, 2);
        }

        return [$created, $skipped, $notes];
    }

    private function seedRuns(int $agencyId, User $admin): array
    {
        $notes = [];
        $created = 0;

        $employeeIds = PayrollEmployee::withoutGlobalScopes()->where('agency_id', $agencyId)->where('is_active', true)->pluck('id')->all();
        if (empty($employeeIds)) {
            $notes[] = 'SKIPPED runs: no active payroll employees';
            return [0, $notes];
        }

        Auth::login($admin);
        $ctrl = app(PayrollRunController::class);

        $plans = [
            ['period' => now()->subMonths(2)->startOfMonth(), 'finalise' => true],
            ['period' => now()->subMonth()->startOfMonth(), 'finalise' => true],
            ['period' => now()->startOfMonth(), 'finalise' => false],
        ];

        foreach ($plans as $p) {
            $periodMonth = $p['period'];
            $existing = PayrollRun::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereYear('period_month', $periodMonth->year)
                ->whereMonth('period_month', $periodMonth->month)
                ->first();

            if ($existing) {
                $notes[] = "SKIPPED (run already exists for {$periodMonth->format('F Y')}): run #{$existing->id} status={$existing->status}";
                continue;
            }

            $payDate = $periodMonth->copy()->endOfMonth();
            $req = Request::create('/corex/payroll/runs', 'POST', [
                'period_month'   => $periodMonth->toDateString(),
                'pay_date'       => $payDate->toDateString(),
                'employee_ids'   => $employeeIds,
                'notes'          => 'Demo payroll run.',
            ]);
            $req->setUserResolver(fn () => $admin);

            $resp = $ctrl->store($req);

            $run = PayrollRun::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereYear('period_month', $periodMonth->year)
                ->whereMonth('period_month', $periodMonth->month)
                ->first();

            if (!$run) {
                $notes[] = "FAILED to create run for {$periodMonth->format('F Y')}: " . ($resp->getSession()?->get('error') ?? 'unknown error');
                continue;
            }

            // Anonymise the payslip number prefix BEFORE any PDF is generated
            // (finalise() renders payslip_number into the PDF) — the real
            // controller hardcodes "HFC-", which must never appear in seeded
            // demo data. See class docblock.
            PayrollPayslip::withoutGlobalScopes()->where('payroll_run_id', $run->id)->get()->each(function (PayrollPayslip $slip) {
                if (str_starts_with($slip->payslip_number, 'HFC-')) {
                    $slip->update(['payslip_number' => 'PAY-' . substr($slip->payslip_number, 4)]);
                }
            });

            $created++;

            if ($p['finalise']) {
                $result = app(PayrollFinaliseService::class)->finalise($run, $admin);
                if ($result['success']) {
                    $notes[] = "CREATED + FINALISED: run #{$run->id} {$periodMonth->format('F Y')}, {$result['payslip_count']} payslips, net R" . number_format((float) $run->fresh()->total_net, 2);
                } else {
                    $notes[] = "CREATED run #{$run->id} {$periodMonth->format('F Y')} but finalise FAILED: " . implode('; ', $result['errors']);
                }
            } else {
                $notes[] = "CREATED (draft/in-progress): run #{$run->id} {$periodMonth->format('F Y')}, {$run->payslip_count} payslips, net R" . number_format((float) $run->total_net, 2);
            }
        }

        return [$created, $notes];
    }
}
