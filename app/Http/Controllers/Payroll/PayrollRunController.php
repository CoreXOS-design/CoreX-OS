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

    // ── FINALISE ──

    public function finalise(Request $request, $id)
    {
        $run = PayrollRun::findOrFail($id);

        if (!$run->isDraft()) {
            abort(422, 'Only draft runs can be finalised.');
        }

        $service = new \App\Services\Payroll\PayrollFinaliseService();
        $result = $service->finalise($run, auth()->user());

        if (!$result['success']) {
            return redirect()->route('payroll.runs.show', $run)
                ->with('error', implode(' ', $result['errors']));
        }

        $msg = "Run {$run->run_number} finalised. {$result['payslip_count']} payslip(s) generated and filed.";
        if (!empty($result['warnings'])) {
            $msg .= ' Warnings: ' . implode('; ', $result['warnings']);
        }

        return redirect()->route('payroll.runs.show', $run)
            ->with('success', $msg);
    }

    // ── BUNDLE PDF DOWNLOAD ──

    public function bundlePdf($id)
    {
        $run = PayrollRun::with('payslips')->findOrFail($id);

        if (!$run->isFinalised()) {
            abort(422, 'Only finalised runs can be bundled.');
        }

        $pdfService = new \App\Services\Payroll\PayslipPdfService();
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $periodYm = $run->period_month->format('Ym');
        $zipFilename = "Payroll-Run-{$run->run_number}-{$periodYm}.zip";
        $zipPath = $tempDir . '/' . $zipFilename;

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Failed to create ZIP archive.');
        }

        foreach ($run->payslips as $payslip) {
            $pdfPath = $pdfService->getStoredPath($payslip);
            if (!$pdfPath) {
                $pdfPath = $pdfService->regenerate($payslip);
            }

            $nameParts = explode(' ', $payslip->employee_name_snapshot);
            $lastName = \Illuminate\Support\Str::slug(last($nameParts));
            $firstName = \Illuminate\Support\Str::slug($nameParts[0] ?? 'employee');
            $entryName = "Payslip-{$lastName}-{$firstName}-{$periodYm}.pdf";

            $zip->addFile($pdfPath, $entryName);
        }

        $zip->close();

        return response()->download($zipPath, $zipFilename)->deleteFileAfterSend(true);
    }

    // ── RUN REPORT ──

    public function runReport($id)
    {
        $run = PayrollRun::with([
            'payslips' => fn($q) => $q->orderBy('employee_name_snapshot'),
            'payslips.lines',
            'payslips.employee.user.branch',
            'createdBy', 'finalisedBy',
        ])->findOrFail($id);

        if (!$run->isFinalised()) {
            abort(422, 'Reports are available for finalised runs only.');
        }

        // Per-branch breakdown
        $branchBreakdown = [];
        foreach ($run->payslips as $ps) {
            $branchName = $ps->employee?->user?->branch?->name ?? 'Unassigned';
            if (!isset($branchBreakdown[$branchName])) {
                $branchBreakdown[$branchName] = [
                    'headcount' => 0, 'gross' => '0.00', 'paye' => '0.00',
                    'uif_employee' => '0.00', 'uif_employer' => '0.00',
                    'sdl' => '0.00', 'net' => '0.00',
                ];
            }
            $b = &$branchBreakdown[$branchName];
            $b['headcount']++;
            $b['gross'] = bcadd($b['gross'], (string) $ps->total_earnings, 2);
            $b['paye'] = bcadd($b['paye'], (string) $ps->paye_amount, 2);
            $b['uif_employee'] = bcadd($b['uif_employee'], (string) $ps->uif_employee_amount, 2);
            $b['uif_employer'] = bcadd($b['uif_employer'], (string) $ps->uif_employer_amount, 2);
            $b['sdl'] = bcadd($b['sdl'], (string) $ps->sdl_amount, 2);
            $b['net'] = bcadd($b['net'], (string) $ps->net_pay, 2);
        }

        // Earning lines summary (grouped by sars code + label)
        $earningsSummary = [];
        $deductionsSummary = [];

        foreach ($run->payslips as $ps) {
            foreach ($ps->lines as $line) {
                $key = ($line->sars_source_code_snapshot ?: '0000') . '|' . $line->label_snapshot;
                if ($line->line_type === 'earning') {
                    if (!isset($earningsSummary[$key])) {
                        $earningsSummary[$key] = [
                            'sars' => $line->sars_source_code_snapshot,
                            'label' => $line->label_snapshot,
                            'total' => '0.00', 'count' => 0,
                        ];
                    }
                    $earningsSummary[$key]['total'] = bcadd($earningsSummary[$key]['total'], (string) $line->amount, 2);
                    $earningsSummary[$key]['count']++;
                } elseif ($line->line_type === 'deduction') {
                    if (!isset($deductionsSummary[$key])) {
                        $deductionsSummary[$key] = [
                            'sars' => $line->sars_source_code_snapshot,
                            'label' => $line->label_snapshot,
                            'total' => '0.00', 'count' => 0,
                        ];
                    }
                    $deductionsSummary[$key]['total'] = bcadd($deductionsSummary[$key]['total'], (string) $line->amount, 2);
                    $deductionsSummary[$key]['count']++;
                }
            }
        }

        ksort($earningsSummary);
        ksort($deductionsSummary);

        // Statutory totals (EMP201 numbers)
        $statutory = [
            'paye'         => (string) $run->total_paye,
            'uif_employee' => (string) $run->total_uif_employee,
            'uif_employer' => (string) $run->total_uif_employer,
            'sdl'          => (string) $run->total_sdl,
        ];
        $statutory['total'] = bcadd(
            bcadd(bcadd($statutory['paye'], $statutory['uif_employee'], 2), $statutory['uif_employer'], 2),
            $statutory['sdl'], 2
        );

        return view('payroll.runs.report', compact(
            'run', 'branchBreakdown', 'earningsSummary', 'deductionsSummary', 'statutory'
        ));
    }

    // ── PAYSLIP PDF PREVIEW ──

    public function payslipPdfPreview($runId, $payslipId)
    {
        $run = PayrollRun::findOrFail($runId);
        $payslip = PayrollPayslip::where('payroll_run_id', $run->id)->findOrFail($payslipId);

        $pdfService = new \App\Services\Payroll\PayslipPdfService();
        $path = $pdfService->regenerate($payslip);

        return $pdfService->getInlineResponse($payslip, $path);
    }

    // ── PAYSLIP PDF DOWNLOAD ──

    public function payslipPdfDownload($runId, $payslipId)
    {
        $run = PayrollRun::findOrFail($runId);
        $payslip = PayrollPayslip::where('payroll_run_id', $run->id)->findOrFail($payslipId);

        $pdfService = new \App\Services\Payroll\PayslipPdfService();
        $path = $pdfService->getStoredPath($payslip);

        if (!$path) {
            // Draft or missing PDF — regenerate
            $path = $pdfService->regenerate($payslip);
        }

        return $pdfService->getDownloadResponse($payslip, $path);
    }

    // ══════════════════════════════════════════════════════════════
    // PAYSLIP EDITING (Prompt I)
    // ══════════════════════════════════════════════════════════════

    public function payslipEdit($runId, $payslipId)
    {
        $run = PayrollRun::findOrFail($runId);
        $this->guardDraftStatus($run);

        $payslip = PayrollPayslip::where('payroll_run_id', $run->id)
            ->with(['lines' => fn($q) => $q->orderBy('sort_order'), 'employee.user'])
            ->findOrFail($payslipId);

        $earningLines = $payslip->lines->where('line_type', 'earning');
        $deductionLines = $payslip->lines->where('line_type', 'deduction');
        $contributionLines = $payslip->lines->where('line_type', 'employer_contribution');

        $earningTypes = PayrollEarningType::active()->orderBy('sort_order')->get();
        $deductionTypes = \App\Models\Payroll\PayrollDeductionType::active()->orderBy('sort_order')->get();

        return view('payroll.runs.payslip-edit', compact(
            'run', 'payslip', 'earningLines', 'deductionLines', 'contributionLines',
            'earningTypes', 'deductionTypes'
        ));
    }

    public function storePayslipLine(Request $request, $runId, $payslipId)
    {
        $run = PayrollRun::findOrFail($runId);
        $this->guardDraftStatus($run);
        $payslip = PayrollPayslip::where('payroll_run_id', $run->id)->findOrFail($payslipId);

        $validated = $request->validate([
            'line_type'      => 'required|in:earning,deduction',
            'source_type_id' => 'required|integer',
            'amount'         => 'required|numeric|min:0',
        ]);

        // Snapshot from type
        if ($validated['line_type'] === 'earning') {
            $type = PayrollEarningType::findOrFail($validated['source_type_id']);
            $isTaxable = $type->is_taxable;
        } else {
            $type = \App\Models\Payroll\PayrollDeductionType::findOrFail($validated['source_type_id']);
            $isTaxable = false;
        }

        $maxSort = $payslip->lines()->max('sort_order') ?? 0;

        PayrollPayslipLine::create([
            'payroll_payslip_id'      => $payslip->id,
            'line_type'               => $validated['line_type'],
            'source_type_id'          => $type->id,
            'code_snapshot'           => $type->code ?? '',
            'label_snapshot'          => $type->label,
            'sars_source_code_snapshot' => $type->sars_source_code,
            'amount'                  => $validated['amount'],
            'is_taxable_snapshot'     => $isTaxable,
            'sort_order'              => $maxSort + 10,
        ]);

        $this->recalculatePayslipTotals($payslip);
        $this->recalculateRunTotals($run);

        return redirect()->route('payroll.runs.payslips.edit', [$run, $payslip])
            ->with('success', "{$type->label} added.");
    }

    public function updatePayslipLine(Request $request, $runId, $payslipId, $lineId)
    {
        $run = PayrollRun::findOrFail($runId);
        $this->guardDraftStatus($run);
        $payslip = PayrollPayslip::where('payroll_run_id', $run->id)->findOrFail($payslipId);
        $line = PayrollPayslipLine::where('payroll_payslip_id', $payslip->id)->findOrFail($lineId);

        if ($line->line_type === 'employer_contribution') {
            abort(422, 'Employer contribution lines cannot be edited directly. Use Recalculate to refresh.');
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
        ]);

        $line->update(['amount' => $validated['amount']]);

        $this->recalculatePayslipTotals($payslip);
        $this->recalculateRunTotals($run);

        return redirect()->route('payroll.runs.payslips.edit', [$run, $payslip])
            ->with('success', "{$line->label_snapshot} updated to R " . number_format($validated['amount'], 2) . ".");
    }

    public function destroyPayslipLine($runId, $payslipId, $lineId)
    {
        $run = PayrollRun::findOrFail($runId);
        $this->guardDraftStatus($run);
        $payslip = PayrollPayslip::where('payroll_run_id', $run->id)->findOrFail($payslipId);
        $line = PayrollPayslipLine::where('payroll_payslip_id', $payslip->id)->findOrFail($lineId);

        if ($line->line_type === 'employer_contribution') {
            abort(422, 'Employer contribution lines cannot be removed. Use Recalculate to refresh.');
        }

        // Check if this is a statutory deduction (PAYE or UIF)
        if ($line->line_type === 'deduction') {
            $deductionType = \App\Models\Payroll\PayrollDeductionType::find($line->source_type_id);
            if ($deductionType && $deductionType->is_statutory) {
                abort(422, 'Statutory deductions (PAYE, UIF) cannot be removed. Edit the amount to override the auto-calculation.');
            }
        }

        $label = $line->label_snapshot;
        $line->delete();

        $this->recalculatePayslipTotals($payslip);
        $this->recalculateRunTotals($run);

        return redirect()->route('payroll.runs.payslips.edit', [$run, $payslip])
            ->with('success', "{$label} removed.");
    }

    public function recalculatePayslip(Request $request, $runId, $payslipId)
    {
        $run = PayrollRun::findOrFail($runId);
        $this->guardDraftStatus($run);
        $payslip = PayrollPayslip::where('payroll_run_id', $run->id)
            ->with('employee')
            ->findOrFail($payslipId);

        $calculator = new PayrollCalculator();

        DB::transaction(function () use ($payslip, $run, $calculator) {
            // Delete all existing lines (hard delete — no soft deletes on lines)
            $payslip->lines()->delete();

            // Recalculate from current employee profile
            $calc = $calculator->calculatePayslip($payslip->employee, $run->period_month);

            // Recreate lines
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

            // Update payslip totals
            $payslip->update([
                'total_earnings'      => $calc->totalEarnings,
                'total_deductions'    => $calc->totalDeductions,
                'taxable_income'      => $calc->taxableIncome,
                'paye_amount'         => $calc->payeAmount,
                'uif_employee_amount' => $calc->uifEmployeeAmount,
                'uif_employer_amount' => $calc->uifEmployerAmount,
                'sdl_amount'          => $calc->sdlAmount,
                'net_pay'             => $calc->netPay,
                'notes'               => !empty($calc->warnings) ? implode('; ', $calc->warnings) : null,
            ]);

            $this->recalculateRunTotals($run);
        });

        return redirect()->route('payroll.runs.payslips.edit', [$run, $payslip])
            ->with('success', 'Payslip recalculated from current employee profile.');
    }

    public function updatePayslipNotes(Request $request, $runId, $payslipId)
    {
        $run = PayrollRun::findOrFail($runId);
        $this->guardDraftStatus($run);
        $payslip = PayrollPayslip::where('payroll_run_id', $run->id)->findOrFail($payslipId);

        $validated = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        $payslip->update(['notes' => $validated['notes']]);

        return redirect()->route('payroll.runs.payslips.edit', [$run, $payslip])
            ->with('success', 'Notes updated.');
    }

    // ══════════════════════════════════════════════════════════════
    // INTERNAL HELPERS
    // ══════════════════════════════════════════════════════════════

    protected function guardDraftStatus(PayrollRun $run): void
    {
        if (!$run->isDraft()) {
            abort(422, "This payslip cannot be edited because the run is {$run->status}. Finalised payslips are immutable; cancelled runs cannot be edited.");
        }
    }

    protected function recalculatePayslipTotals(PayrollPayslip $payslip): void
    {
        $lines = $payslip->lines()->get();

        $totalEarnings = '0.00';
        $totalDeductions = '0.00';
        $taxableIncome = '0.00';
        $payeAmount = '0.00';
        $uifEmployeeAmount = '0.00';
        $uifEmployerAmount = '0.00';
        $sdlAmount = '0.00';

        foreach ($lines as $line) {
            if ($line->line_type === 'earning') {
                $totalEarnings = bcadd($totalEarnings, (string) $line->amount, 2);
                if ($line->is_taxable_snapshot) {
                    $taxableIncome = bcadd($taxableIncome, (string) $line->amount, 2);
                }
            } elseif ($line->line_type === 'deduction') {
                $totalDeductions = bcadd($totalDeductions, (string) $line->amount, 2);
                // Identify PAYE and UIF by sars code snapshot
                if ($line->sars_source_code_snapshot === '4102') {
                    $payeAmount = bcadd($payeAmount, (string) $line->amount, 2);
                } elseif ($line->sars_source_code_snapshot === '4141') {
                    $uifEmployeeAmount = bcadd($uifEmployeeAmount, (string) $line->amount, 2);
                }
            } elseif ($line->line_type === 'employer_contribution') {
                if ($line->sars_source_code_snapshot === '4141') {
                    $uifEmployerAmount = bcadd($uifEmployerAmount, (string) $line->amount, 2);
                } else {
                    $sdlAmount = bcadd($sdlAmount, (string) $line->amount, 2);
                }
            }
        }

        $netPay = bcsub($totalEarnings, $totalDeductions, 2);

        $payslip->update([
            'total_earnings'      => $totalEarnings,
            'total_deductions'    => $totalDeductions,
            'taxable_income'      => $taxableIncome,
            'paye_amount'         => $payeAmount,
            'uif_employee_amount' => $uifEmployeeAmount,
            'uif_employer_amount' => $uifEmployerAmount,
            'sdl_amount'          => $sdlAmount,
            'net_pay'             => $netPay,
        ]);
    }

    protected function recalculateRunTotals(PayrollRun $run): void
    {
        $payslips = $run->payslips()->get();

        $totalGross = '0.00';
        $totalPaye = '0.00';
        $totalUifEmployee = '0.00';
        $totalUifEmployer = '0.00';
        $totalSdl = '0.00';
        $totalNet = '0.00';

        foreach ($payslips as $ps) {
            $totalGross = bcadd($totalGross, (string) $ps->total_earnings, 2);
            $totalPaye = bcadd($totalPaye, (string) $ps->paye_amount, 2);
            $totalUifEmployee = bcadd($totalUifEmployee, (string) $ps->uif_employee_amount, 2);
            $totalUifEmployer = bcadd($totalUifEmployer, (string) $ps->uif_employer_amount, 2);
            $totalSdl = bcadd($totalSdl, (string) $ps->sdl_amount, 2);
            $totalNet = bcadd($totalNet, (string) $ps->net_pay, 2);
        }

        $run->update([
            'payslip_count'      => $payslips->count(),
            'total_gross'        => $totalGross,
            'total_paye'         => $totalPaye,
            'total_uif_employee' => $totalUifEmployee,
            'total_uif_employer' => $totalUifEmployer,
            'total_sdl'          => $totalSdl,
            'total_net'          => $totalNet,
        ]);
    }
}
