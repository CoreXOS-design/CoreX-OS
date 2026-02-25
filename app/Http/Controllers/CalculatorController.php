<?php

namespace App\Http\Controllers;

use App\Models\PerformanceSetting;
use App\Services\PropertyCostService;
use Illuminate\Http\Request;

class CalculatorController extends Controller
{
    public function index()
    {
        $primeRate = (float) PerformanceSetting::get('sa_prime_rate', 11.75);
        $feeInfo = PropertyCostService::getFeeScaleInfo();

        return view('calculators.index', [
            'primeRate' => $primeRate,
            'feeEffectiveDate' => $feeInfo['effective_date'],
            'feeSourceDocument' => $feeInfo['source_document'],
        ]);
    }

    public function uploadFeeSheet(Request $request)
    {
        if (!auth()->user()->isEffectiveAdmin()) {
            abort(403);
        }

        $request->validate([
            'fee_sheet' => 'required|file|mimes:pdf|max:10240',
            'effective_date' => 'nullable|date',
        ]);

        try {
            $path = $request->file('fee_sheet')->store('fee-sheets', 'local');
            $fullPath = storage_path('app/' . $path);

            $parser = new \App\Services\FeeSheetParserService();
            $parsed = $parser->parse($fullPath);

            $effectiveDate = $request->input('effective_date', now()->format('Y-m-d'));
            $filename = $request->file('fee_sheet')->getClientOriginalName();

            foreach (['conveyancing', 'deeds_office', 'transfer_duty'] as $type) {
                if (!empty($parsed[$type])) {
                    \DB::table('calculator_fee_scales')->insert([
                        'type' => $type,
                        'brackets' => json_encode($parsed[$type]),
                        'source_document' => $filename,
                        'effective_date' => $effectiveDate,
                        'additional_costs_note' => $parsed['additional_costs'] ?? null,
                        'uploaded_by' => auth()->id(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            PropertyCostService::clearCache();

            return back()->with('fee_upload_success',
                "Fee scales updated from {$filename}. " .
                "Parsed: " . count($parsed['conveyancing']) . " conveyancing brackets, " .
                count($parsed['deeds_office']) . " deeds office brackets."
            );
        } catch (\Throwable $e) {
            return back()->with('fee_upload_error',
                "Failed to parse fee sheet: " . $e->getMessage()
            );
        }
    }

    public function calculateCommission(Request $request)
    {
        $data = $request->validate([
            'sale_price' => 'required|numeric|min:0',
            'commission_rate' => 'required|numeric|min:0|max:100',
        ]);

        $salePrice = (float) $data['sale_price'];
        $rate = (float) $data['commission_rate'];
        $vatRate = 0.15;

        $commissionExcVat = $salePrice * ($rate / 100);
        $vat = $commissionExcVat * $vatRate;
        $commissionIncVat = $commissionExcVat + $vat;

        return response()->json([
            'ok' => true,
            'commission_exc_vat' => round($commissionExcVat, 2),
            'vat' => round($vat, 2),
            'commission_inc_vat' => round($commissionIncVat, 2),
            'agent_split_50' => round($commissionExcVat * 0.50, 2),
            'agent_split_60' => round($commissionExcVat * 0.60, 2),
            'agent_split_70' => round($commissionExcVat * 0.70, 2),
        ]);
    }

    public function calculateBond(Request $request)
    {
        $data = $request->validate([
            'loan_amount' => 'required|numeric|min:0',
            'interest_rate' => 'required|numeric|min:0|max:50',
            'term_years' => 'required|integer|min:1|max:30',
        ]);

        $principal = (float) $data['loan_amount'];
        $annualRate = (float) $data['interest_rate'];
        $years = (int) $data['term_years'];

        $monthly = $this->calcMonthlyRepayment($principal, $annualRate, $years);
        $totalRepaid = $monthly * $years * 12;
        $totalInterest = $totalRepaid - $principal;

        // Comparison rows: prime+1%, prime+2%
        $monthlyPlus1 = $this->calcMonthlyRepayment($principal, $annualRate + 1, $years);
        $monthlyPlus2 = $this->calcMonthlyRepayment($principal, $annualRate + 2, $years);

        return response()->json([
            'ok' => true,
            'monthly_repayment' => round($monthly, 2),
            'total_repaid' => round($totalRepaid, 2),
            'total_interest' => round($totalInterest, 2),
            'monthly_plus_1' => round($monthlyPlus1, 2),
            'total_repaid_plus_1' => round($monthlyPlus1 * $years * 12, 2),
            'monthly_plus_2' => round($monthlyPlus2, 2),
            'total_repaid_plus_2' => round($monthlyPlus2 * $years * 12, 2),
        ]);
    }

    public function calculateTransferCosts(Request $request)
    {
        $data = $request->validate([
            'purchase_price' => 'required|numeric|min:0',
            'needs_bond' => 'required|boolean',
            'bond_amount' => 'nullable|numeric|min:0',
        ]);

        $price = (float) $data['purchase_price'];
        $needsBond = (bool) $data['needs_bond'];
        $bondAmount = $needsBond ? (float) ($data['bond_amount'] ?? $price) : 0;

        $transfer = PropertyCostService::calcTransferCosts($price);

        $bond = null;
        if ($needsBond && $bondAmount > 0) {
            $bond = PropertyCostService::calcBondCosts($bondAmount);
        }

        $grandTotal = $transfer['total'] + ($bond['total'] ?? 0);

        return response()->json([
            'ok' => true,
            'transfer' => $transfer,
            'bond' => $bond,
            'grand_total' => round($grandTotal, 2),
        ]);
    }

    public function calculateBondOverpayment(Request $request)
    {
        $data = $request->validate([
            'loan_amount' => 'required|numeric|min:1',
            'interest_rate' => 'required|numeric|min:0.01|max:50',
            'term_years' => 'required|integer|min:1|max:30',
            'extra_payment' => 'required|numeric|min:1',
        ]);

        $principal = (float) $data['loan_amount'];
        $annualRate = (float) $data['interest_rate'];
        $years = (int) $data['term_years'];
        $extra = (float) $data['extra_payment'];

        $r = ($annualRate / 100) / 12;
        $totalMonths = $years * 12;

        // Normal monthly payment
        $factor = pow(1 + $r, $totalMonths);
        $normalMonthly = $principal * ($r * $factor) / ($factor - 1);

        // Normal totals
        $normalTotalRepaid = $normalMonthly * $totalMonths;
        $normalTotalInterest = $normalTotalRepaid - $principal;

        // Simulate accelerated payoff month-by-month
        $balance = $principal;
        $accelMonths = 0;
        $accelTotalRepaid = 0;
        $accelTotalInterest = 0;
        $yearlyNormal = [];
        $yearlyAccel = [];
        $normalBalance = $principal;

        // Track both normal and accelerated balances for comparison table
        $accelDone = false;
        for ($m = 1; $m <= $totalMonths; $m++) {
            // Normal balance
            $normalInterest = $normalBalance * $r;
            $normalPrincipalPaid = $normalMonthly - $normalInterest;
            $normalBalance = max(0, $normalBalance - $normalPrincipalPaid);

            // Accelerated balance
            if (!$accelDone) {
                $interest = $balance * $r;
                $payment = $normalMonthly + $extra;
                if ($payment >= $balance + $interest) {
                    // Final payment — pays off the loan
                    $accelTotalRepaid += $balance + $interest;
                    $accelTotalInterest += $interest;
                    $balance = 0;
                    $accelMonths = $m;
                    $accelDone = true;
                } else {
                    $principalPaid = $payment - $interest;
                    $balance = $balance - $principalPaid;
                    $accelTotalRepaid += $payment;
                    $accelTotalInterest += $interest;
                }
            }

            // Record year-end balances
            if ($m % 12 === 0) {
                $yearNum = $m / 12;
                if ($yearNum <= 25) {
                    $yearlyNormal[] = round($normalBalance, 2);
                    $yearlyAccel[] = round($balance, 2);
                }
            }
        }

        // If extra payment wasn't enough to shorten meaningfully
        if (!$accelDone) {
            $accelMonths = $totalMonths;
        }

        $accelYears = intdiv($accelMonths, 12);
        $accelRemainingMonths = $accelMonths % 12;

        $monthsSaved = $totalMonths - $accelMonths;
        $yearsSaved = intdiv($monthsSaved, 12);
        $monthsSavedRemainder = $monthsSaved % 12;

        $interestSaved = $normalTotalInterest - $accelTotalInterest;
        $interestSavedPct = $normalTotalInterest > 0
            ? ($interestSaved / $normalTotalInterest) * 100
            : 0;

        return response()->json([
            'ok' => true,
            'normal' => [
                'monthly_payment' => round($normalMonthly, 2),
                'term_months' => $totalMonths,
                'term_years' => $years,
                'total_repaid' => round($normalTotalRepaid, 2),
                'total_interest' => round($normalTotalInterest, 2),
            ],
            'accelerated' => [
                'monthly_payment' => round($normalMonthly + $extra, 2),
                'term_months' => $accelMonths,
                'term_years' => $accelYears,
                'term_remaining_months' => $accelRemainingMonths,
                'total_repaid' => round($accelTotalRepaid, 2),
                'total_interest' => round($accelTotalInterest, 2),
            ],
            'savings' => [
                'months_saved' => $monthsSaved,
                'years_saved' => $yearsSaved,
                'months_saved_remainder' => $monthsSavedRemainder,
                'interest_saved' => round($interestSaved, 2),
                'interest_saved_pct' => round($interestSavedPct, 1),
            ],
            'yearly_comparison' => [
                'normal' => $yearlyNormal,
                'accelerated' => $yearlyAccel,
            ],
        ]);
    }

    /**
     * Standard amortisation formula.
     * M = P * [r(1+r)^n] / [(1+r)^n - 1]
     */
    private function calcMonthlyRepayment(float $principal, float $annualRate, int $years): float
    {
        if ($principal <= 0 || $annualRate <= 0 || $years <= 0) {
            return 0;
        }

        $r = ($annualRate / 100) / 12;
        $n = $years * 12;
        $factor = pow(1 + $r, $n);

        return $principal * ($r * $factor) / ($factor - 1);
    }

}
