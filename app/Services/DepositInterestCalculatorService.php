<?php

namespace App\Services;

use App\Models\DepositTrustInterest;
use Carbon\Carbon;

class DepositInterestCalculatorService
{
    private const SCALE = 10;

    public function calculate(
        float $depositAmount,
        string $investDate,
        string $refundDate,
        array $topups = []
    ): array {
        $deposit = number_format($depositAmount, 2, '.', '');
        $runningBalance = $deposit;
        $topupsTotal = '0.00';
        $breakdown = [];

        // First breakdown row: initial deposit
        $breakdown[] = [
            'date' => Carbon::parse($investDate),
            'type' => 'deposit',
            'description' => 'Deposit Invested',
            'total_invested_funds' => null,
            'running_balance' => round((float) $runningBalance, 2),
            'share_percentage' => null,
            'interest_earned' => null,
            'interest_share' => null,
        ];

        // Sort topups by date
        usort($topups, fn ($a, $b) => strcmp($a['date'], $b['date']));

        // Get trust interest records in the date range
        $trustRecords = DepositTrustInterest::whereBetween('interest_date', [$investDate, $refundDate])
            ->orderBy('interest_date')
            ->get();

        // Track which topups have been applied
        $topupIndex = 0;

        foreach ($trustRecords as $record) {
            $interestDateStr = $record->interest_date->format('Y-m-d');

            // Apply any topups that fall on or before this interest date
            while ($topupIndex < count($topups) && $topups[$topupIndex]['date'] <= $interestDateStr) {
                $topupAmount = number_format((float) $topups[$topupIndex]['amount'], 2, '.', '');
                $runningBalance = bcadd($runningBalance, $topupAmount, self::SCALE);
                $topupsTotal = bcadd($topupsTotal, $topupAmount, self::SCALE);

                $breakdown[] = [
                    'date' => Carbon::parse($topups[$topupIndex]['date']),
                    'type' => 'topup',
                    'description' => 'Deposit Topup',
                    'total_invested_funds' => null,
                    'running_balance' => round((float) $runningBalance, 2),
                    'share_percentage' => null,
                    'interest_earned' => null,
                    'interest_share' => null,
                ];

                $topupIndex++;
            }

            // Calculate proportional interest share
            $totalFunds = number_format((float) $record->total_invested_funds, 2, '.', '');
            $interestEarned = number_format((float) $record->interest_earned, 2, '.', '');

            if (bccomp($totalFunds, '0', self::SCALE) > 0) {
                $sharePercentage = bcdiv($runningBalance, $totalFunds, self::SCALE);
                $interestShare = bcmul($interestEarned, $sharePercentage, self::SCALE);
                $runningBalance = bcadd($runningBalance, $interestShare, self::SCALE);

                $breakdown[] = [
                    'date' => $record->interest_date,
                    'type' => 'interest',
                    'description' => 'Interest Earned',
                    'total_invested_funds' => round((float) $totalFunds, 2),
                    'running_balance' => round((float) $runningBalance, 2),
                    'share_percentage' => round((float) $sharePercentage, 6),
                    'interest_earned' => round((float) $interestEarned, 2),
                    'interest_share' => round((float) $interestShare, 2),
                ];
            }
        }

        // Apply any remaining topups after the last interest record
        while ($topupIndex < count($topups)) {
            $topupAmount = number_format((float) $topups[$topupIndex]['amount'], 2, '.', '');
            $runningBalance = bcadd($runningBalance, $topupAmount, self::SCALE);
            $topupsTotal = bcadd($topupsTotal, $topupAmount, self::SCALE);

            $breakdown[] = [
                'date' => Carbon::parse($topups[$topupIndex]['date']),
                'type' => 'topup',
                'description' => 'Deposit Topup',
                'total_invested_funds' => null,
                'running_balance' => round((float) $runningBalance, 2),
                'share_percentage' => null,
                'interest_earned' => null,
                'interest_share' => null,
            ];

            $topupIndex++;
        }

        $totalDeposited = bcadd($deposit, $topupsTotal, 2);
        $totalInterest = bcsub($runningBalance, $totalDeposited, 2);
        $grandTotal = round((float) $runningBalance, 2);

        return [
            'deposit_amount' => round((float) $deposit, 2),
            'topups_total' => round((float) $topupsTotal, 2),
            'total_deposited' => round((float) $totalDeposited, 2),
            'total_interest' => round((float) $totalInterest, 2),
            'grand_total' => $grandTotal,
            'breakdown' => $breakdown,
        ];
    }
}
