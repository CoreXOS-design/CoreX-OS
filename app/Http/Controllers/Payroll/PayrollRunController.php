<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Payroll\PayrollEarningType;
use App\Models\Payroll\PayrollEmployee;
use App\Models\Payroll\PayrollPayslip;
use App\Models\Payroll\PayrollPayslipLine;
use App\Models\Payroll\PayrollRun;
use App\Services\Payroll\PayrollCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollRunController extends Controller
{
    // ── INDEX ──

    public function index(Request $request)
    {
        $status = $request->query('status', 'all');

        $query = PayrollRun::with('finalisedBy', 'createdBy')
            ->orderBy('period_month', 'desc')
            ->orderBy('created_at', 'desc');

        if ($status === 'draft') {
            $query->draft();
        } elseif ($status === 'finalised') {
            $query->finalised();
        } elseif ($status === 'cancelled') {
            $query->cancelled();
        }

        $runs = $query->paginate(25)->withQueryString();

        $counts = [
            'all'       => PayrollRun::count(),
            'draft'     => PayrollRun::draft()->count(),
            'finalised' => PayrollRun::finalised()->count(),
            'cancelled' => PayrollRun::cancelled()->count(),
        ];

        return view('payroll.runs.index', compact('runs', 'status', 'counts'));
    }

    // ── CREATE ──

    public function create()
    {
        // Default period: current month if before pay day, else next month
        $today = now();
        $defaultPayDay = 25;
        if ($today->day >= $defaultPayDay) {
            $defaultPeriod = $today->copy()->addMonth()->startOfMonth();
        } else {
            $defaultPeriod = $today->copy()->startOfMonth();
        }

        // Active employees with current basic salary
        $basicType = PayrollEarningType::where('code', 'basic')->first();
        $employees = PayrollEmployee::with('user', 'user.branch')
            ->active()
            ->orderBy('created_at')
            ->get();

        foreach ($employees as $emp) {
            $emp->basic_salary = $basicType
                ? $emp->currentEarnings()->where('earning_type_id', $basicType->id)->value('amount')
                : null;

            // Last finalised run for this employee
            $lastPayslip = PayrollPayslip::where('payroll_employee_id', $emp->id)
                ->whereHas('run', fn($q) => $q->where('status', 'finalised'))
                ->orderBy('period_month', 'desc')
                ->first();
            $emp->last_run_period = $lastPayslip?->period_month;
        }

        // Check for existing run in default period
        $existingRun = PayrollRun::forMonth($defaultPeriod)
            ->whereIn('status', ['draft', 'finalised'])
            ->first();

        // Calculate projected totals server-side
        $calculator = new PayrollCalculator();
        $projectedTotals = [
            'gross' => '0.00', 'paye' => '0.00',
            'uif_employee' => '0.00', 'uif_employer' => '0.00',
            'sdl' => '0.00', 'net' => '0.00', 'headcount' => 0,
        ];
        foreach ($employees as $emp) {
            try {
                $calc = $calculator->calculatePayslip($emp, $defaultPeriod);
                $projectedTotals['gross'] = bcadd($projectedTotals['gross'], $calc->totalEarnings, 2);
                $projectedTotals['paye'] = bcadd($projectedTotals['paye'], $calc->payeAmount, 2);
                $projectedTotals['uif_employee'] = bcadd($projectedTotals['uif_employee'], $calc->uifEmployeeAmount, 2);
                $projectedTotals['uif_employer'] = bcadd($projectedTotals['uif_employer'], $calc->uifEmployerAmount, 2);
                $projectedTotals['sdl'] = bcadd($projectedTotals['sdl'], $calc->sdlAmount, 2);
                $projectedTotals['net'] = bcadd($projectedTotals['net'], $calc->netPay, 2);
                $projectedTotals['headcount']++;
            } catch (\Throwable $e) {
                // Skip employees with calculation errors — will surface during store()
            }
        }

        return view('payroll.runs.create', compact(
            'defaultPeriod', 'employees', 'existingRun', 'projectedTotals'
        ));
    }

    // ── STORE ──

    public function store(Request $request)
    {
        $validated = $request->validate([
            'period_month' => 'required|date',
            'pay_date'     => 'required|date',
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer|exists:payroll_employees,id',
            'notes'        => 'nullable|string|max:2000',
        ]);

        $periodMonth = Carbon::parse($validated['period_month'])->startOfMonth();
        $payDate = Carbon::parse($validated['pay_date']);

        // Block if finalised run exists for this period
        $existing = PayrollRun::forMonth($periodMonth)->finalised()->first();
        if ($existing) {
            return back()->withInput()->with('error',
                "A finalised run already exists for {$periodMonth->format('F Y')}. You cannot create another.");
        }

        // Block if draft run exists (must cancel it first)
        $existingDraft = PayrollRun::forMonth($periodMonth)->draft()->first();
        if ($existingDraft) {
            return back()->withInput()->with('error',
                "A draft run already exists for {$periodMonth->format('F Y')}. Cancel it first or continue editing.");
        }

        $agencyId = auth()->user()->effectiveAgencyId();
        $calculator = new PayrollCalculator();

        $run = DB::transaction(function () use ($validated, $periodMonth, $payDate, $agencyId, $calculator) {
            // Generate run number: YYYYMM-001
            $ym = $periodMonth->format('Ym');
            $seq = PayrollRun::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where('run_number', 'like', "{$ym}-%")
                ->count() + 1;
            $runNumber = $ym . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

            // Create run
            $run = PayrollRun::create([
                'run_number'   => $runNumber,
                'period_month' => $periodMonth,
                'pay_date'     => $payDate,
                'status'       => 'draft',
                'notes'        => $validated['notes'],
                'created_by'   => auth()->id(),
            ]);

            // Generate payslips
            $employees = PayrollEmployee::with('user')
                ->whereIn('id', $validated['employee_ids'])
                ->where('is_active', true)
                ->get();

            $payslipSeq = 0;
            $totals = [
                'gross' => '0.00', 'paye' => '0.00',
                'uif_employee' => '0.00', 'uif_employer' => '0.00',
                'sdl' => '0.00', 'net' => '0.00',
            ];

            foreach ($employees as $emp) {
                $payslipSeq++;
                $user = $emp->user;

                // Calculate
                $calc = $calculator->calculatePayslip($emp, $periodMonth);

                // Generate payslip number
                $payslipNumber = 'HFC-' . $periodMonth->format('Ym') . '-' . str_pad($payslipSeq, 3, '0', STR_PAD_LEFT);

                // Create payslip
                $payslip = PayrollPayslip::create([
                    'branch_id'               => $emp->branch_id,
                    'payroll_run_id'          => $run->id,
                    'payroll_employee_id'     => $emp->id,
                    'user_id'                 => $user->id,
                    'payslip_number'          => $payslipNumber,
                    'employee_name_snapshot'  => $user->name,
                    'id_number_snapshot'      => $user->id_number,
                    'tax_reference_snapshot'  => $user->tax_reference_number,
                    'employment_date_snapshot' => $emp->employment_date,
                    'designation_snapshot'    => $emp->designation_snapshot,
                    'period_month'           => $periodMonth,
                    'pay_date'               => $payDate,
                    'total_earnings'         => $calc->totalEarnings,
                    'total_deductions'       => $calc->totalDeductions,
                    'taxable_income'         => $calc->taxableIncome,
                    'paye_amount'            => $calc->payeAmount,
                    'uif_employee_amount'    => $calc->uifEmployeeAmount,
                    'uif_employer_amount'    => $calc->uifEmployerAmount,
                    'sdl_amount'             => $calc->sdlAmount,
                    'net_pay'                => $calc->netPay,
                    'notes'                  => !empty($calc->warnings) ? implode('; ', $calc->warnings) : null,
                ]);

                // Create payslip lines — earnings
                $sortOrder = 0;
                foreach ($calc->earnings as $earning) {
                    $sortOrder++;
                    PayrollPayslipLine::create([
                        'payroll_payslip_id'      => $payslip->id,
                        'line_type'               => 'earning',
                        'source_type_id'          => $earning['earning_type_id'],
                        'code_snapshot'           => $earning['sars_code'] ?? '',
                        'label_snapshot'          => $earning['label'],
                        'sars_source_code_snapshot' => $earning['sars_code'],
                        'amount'                  => $earning['amount'],
                        'is_taxable_snapshot'     => $earning['is_taxable'],
                        'sort_order'              => $sortOrder,
                    ]);
                }

                // Create payslip lines — deductions
                foreach ($calc->deductions as $deduction) {
                    $sortOrder++;
                    PayrollPayslipLine::create([
                        'payroll_payslip_id'      => $payslip->id,
                        'line_type'               => 'deduction',
                        'source_type_id'          => $deduction['deduction_type_id'],
                        'code_snapshot'           => $deduction['sars_code'] ?? '',
                        'label_snapshot'          => $deduction['label'],
                        'sars_source_code_snapshot' => $deduction['sars_code'],
                        'amount'                  => $deduction['amount'],
                        'is_taxable_snapshot'     => false,
                        'sort_order'              => $sortOrder,
                    ]);
                }

                // Create payslip lines — employer contributions
                foreach ($calc->employerContributions as $contrib) {
                    $sortOrder++;
                    PayrollPayslipLine::create([
                        'payroll_payslip_id'      => $payslip->id,
                        'line_type'               => 'employer_contribution',
                        'source_type_id'          => 0,
                        'code_snapshot'           => $contrib['sars_code'] ?? '',
                        'label_snapshot'          => $contrib['label'],
                        'sars_source_code_snapshot' => $contrib['sars_code'],
                        'amount'                  => $contrib['amount'],
                        'is_taxable_snapshot'     => false,
                        'sort_order'              => $sortOrder,
                    ]);
                }

                // Accumulate totals
                $totals['gross'] = bcadd($totals['gross'], $calc->totalEarnings, 2);
                $totals['paye'] = bcadd($totals['paye'], $calc->payeAmount, 2);
                $totals['uif_employee'] = bcadd($totals['uif_employee'], $calc->uifEmployeeAmount, 2);
                $totals['uif_employer'] = bcadd($totals['uif_employer'], $calc->uifEmployerAmount, 2);
                $totals['sdl'] = bcadd($totals['sdl'], $calc->sdlAmount, 2);
                $totals['net'] = bcadd($totals['net'], $calc->netPay, 2);
            }

            // Cache totals on run
            $run->update([
                'payslip_count'      => $payslipSeq,
                'total_gross'        => $totals['gross'],
                'total_paye'         => $totals['paye'],
                'total_uif_employee' => $totals['uif_employee'],
                'total_uif_employer' => $totals['uif_employer'],
                'total_sdl'          => $totals['sdl'],
                'total_net'          => $totals['net'],
            ]);

            return $run;
        });

        return redirect()->route('payroll.runs.show', $run)
            ->with('success', "Payroll run {$run->run_number} created with {$run->payslip_count} draft payslip(s). Review and finalise when ready.");
    }

    // ── SHOW ──

    public function show($id)
    {
        $run = PayrollRun::with([
            'payslips' => fn($q) => $q->orderBy('employee_name_snapshot'),
            'payslips.employee.user',
            'payslips.employee.user.branch',
            'createdBy', 'finalisedBy', 'cancelledBy',
        ])->findOrFail($id);

        return view('payroll.runs.show', compact('run'));
    }

    // ── CANCEL ──

    public function cancel(Request $request, $id)
    {
        $run = PayrollRun::findOrFail($id);

        if (!$run->isDraft()) {
            abort(422, 'Only draft runs can be cancelled.');
        }

        $validated = $request->validate([
            'cancellation_reason' => 'required|string|max:500',
        ]);

        DB::transaction(function () use ($run, $validated) {
            $run->update([
                'status'              => 'cancelled',
                'cancelled_at'        => now(),
                'cancelled_by'        => auth()->id(),
                'cancellation_reason' => $validated['cancellation_reason'],
            ]);

            // Soft-delete child payslips and their lines
            foreach ($run->payslips as $payslip) {
                $payslip->lines()->delete();
                $payslip->delete();
            }
        });

        return redirect()->route('payroll.runs.index')
            ->with('success', "Run {$run->run_number} cancelled.");
    }

    // ── PAYSLIP SHOW ──

    public function payslipShow($runId, $payslipId)
    {
        $run = PayrollRun::findOrFail($runId);
        $payslip = PayrollPayslip::where('payroll_run_id', $run->id)
            ->with(['lines' => fn($q) => $q->orderBy('sort_order'), 'employee.user.bankingDetail'])
            ->findOrFail($payslipId);

        $earningLines = $payslip->lines->where('line_type', 'earning');
        $deductionLines = $payslip->lines->where('line_type', 'deduction');
        $contributionLines = $payslip->lines->where('line_type', 'employer_contribution');

        return view('payroll.runs.payslip-show', compact(
            'run', 'payslip', 'earningLines', 'deductionLines', 'contributionLines'
        ));
    }
}
