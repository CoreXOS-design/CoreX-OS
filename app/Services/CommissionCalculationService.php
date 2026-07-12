<?php

namespace App\Services;

use App\Models\AgentCapPeriod;
use App\Models\AgentMentor;
use App\Models\AgentSponsorship;
use App\Models\CommissionLedger;
use App\Models\CommissionSetting;
use App\Models\RevenueShareLedger;
use App\Models\User;

class CommissionCalculationService
{
    /**
     * Calculate and record a deal commission for an agent.
     *
     * All monetary calculations use bcmath with scale 2.
     */
    public static function calculateDealCommission(
        int $userId,
        string $grossCommission,
        string $vatAmount,
        string $transactionType,
        string $description,
        ?int $dealId = null,
        ?int $propertyId = null
    ): CommissionLedger {
        $user = User::findOrFail($userId);
        $agencyId = $user->effectiveAgencyId() ?? 1;

        // 1. Get or create current cap period
        $capPeriod = AgentCapPeriod::currentForUser($userId, $agencyId);

        // 2. Get agency settings
        $settings = CommissionSetting::forAgency($agencyId);

        // 3. Commission excl VAT
        $commissionExclVat = bcsub($grossCommission, $vatAmount, 2);

        // 4. Check mentee status. An agency that does not run a mentor programme
        // switches it off in Settings — no mentor fee is charged, even if an
        // AgentMentor row still exists from before.
        $mentor = $settings->mentor_program_enabled
            ? AgentMentor::where('mentee_user_id', $userId)->where('is_active', true)->first()
            : null;
        $isMentored = $mentor && $capPeriod->transactions_mentored < $settings->mentor_transactions;

        // 5. Check cap status
        $isCapped = $capPeriod->checkCap();

        // Risk fee (capped annually)
        $riskFeeRemaining = bcsub((string) $settings->risk_management_cap, (string) ($capPeriod->risk_fees_paid ?? '0'), 2);
        $riskFee = '0.00';
        if (bccomp($riskFeeRemaining, '0', 2) > 0) {
            $riskFee = bccomp($riskFeeRemaining, (string) $settings->risk_management_fee, 2) >= 0
                ? (string) $settings->risk_management_fee
                : $riskFeeRemaining;
        }

        $transactionFee = '0.00';
        $mentorFee = '0.00';
        $agentSplitPercent = $settings->commission_split_agent;

        if ($isCapped) {
            // POST-CAP: 100% minus fees
            $agentSplitPercent = 100;

            // Transaction fee (reduced if post-cap fee cap reached)
            if (bccomp($capPeriod->post_cap_fees_paid, (string) $settings->post_cap_fee_cap, 2) >= 0) {
                $transactionFee = (string) $settings->post_cap_reduced_fee;
            } else {
                $transactionFee = (string) $settings->post_cap_transaction_fee;
            }

            $agentAmount = $commissionExclVat;
            $agencyAmount = '0.00';
            $netAgent = bcsub(bcsub($commissionExclVat, $transactionFee, 2), $riskFee, 2);
            $companyDollar = bcadd($transactionFee, $riskFee, 2);
        } else {
            // PRE-CAP: Standard split
            $agentAmount = bcdiv(bcmul($commissionExclVat, (string) $agentSplitPercent, 2), '100', 2);
            $agencyAmount = bcsub($commissionExclVat, $agentAmount, 2);

            if ($isMentored) {
                // Mentor fee: extra split on commission
                $mentorFee = bcdiv(bcmul($commissionExclVat, (string) $settings->mentor_extra_split, 2), '100', 2);
                $agentAmount = bcsub($agentAmount, $mentorFee, 2);
            }

            $netAgent = bcsub($agentAmount, $riskFee, 2);

            // Company dollar: agency portion + risk fee + half mentor fee (agency keeps half)
            $companyDollar = bcadd($agencyAmount, $riskFee, 2);
            if ($isMentored) {
                $agencyMentorShare = bcdiv($mentorFee, '2', 2);
                $companyDollar = bcadd($companyDollar, $agencyMentorShare, 2);
            }
        }

        // 6. Revenue share pool
        $revenueSharePool = '0.00';
        if ($settings->revenue_share_enabled) {
            $revenueSharePool = bcdiv(bcmul($companyDollar, (string) $settings->revenue_share_pool_percent, 2), '100', 2);
        }

        // 7. Create ledger entry
        $entry = CommissionLedger::create([
            'user_id' => $userId,
            'agency_id' => $agencyId,
            'cap_period_id' => $capPeriod->id,
            'deal_id' => $dealId,
            'property_id' => $propertyId,
            'transaction_type' => $transactionType,
            'description' => $description,
            'gross_commission' => $grossCommission,
            'vat_amount' => $vatAmount,
            'commission_excl_vat' => $commissionExclVat,
            'agent_split_percent' => $agentSplitPercent,
            'agent_amount' => $isCapped ? $commissionExclVat : bcadd($agentAmount, $riskFee, 2),
            'agency_amount' => $agencyAmount,
            'transaction_fee' => $transactionFee,
            'risk_fee' => $riskFee,
            'mentor_fee' => $mentorFee,
            'is_post_cap' => $isCapped,
            'net_agent_amount' => $netAgent,
            'company_dollar' => $companyDollar,
            'revenue_share_pool' => $revenueSharePool,
            'status' => 'pending',
            'deal_date' => now()->toDateString(),
        ]);

        // 8. Update cap period
        $capPeriod->company_dollar_paid = bcadd((string) ($capPeriod->company_dollar_paid ?? '0'), $companyDollar, 2);
        $capPeriod->transactions_count++;
        $capPeriod->gross_commission_income = bcadd((string) ($capPeriod->gross_commission_income ?? '0'), $commissionExclVat, 2);

        if ($isCapped) {
            $capPeriod->post_cap_fees_paid = bcadd((string) ($capPeriod->post_cap_fees_paid ?? '0'), $transactionFee, 2);
        }
        if (bccomp($riskFee, '0', 2) > 0) {
            $capPeriod->risk_fees_paid = bcadd((string) ($capPeriod->risk_fees_paid ?? '0'), $riskFee, 2);
        }

        // 9. Check if just capped
        if (!$capPeriod->is_capped && bccomp($capPeriod->company_dollar_paid, (string) $capPeriod->cap_amount, 2) >= 0) {
            $capPeriod->is_capped = true;
            $capPeriod->capped_at = now();
        }

        if ($isMentored) {
            $capPeriod->transactions_mentored++;
        }

        $capPeriod->save();

        // 10. Distribute revenue share
        if ($settings->revenue_share_enabled && bccomp($revenueSharePool, '0', 2) > 0) {
            static::distributeRevenueShare($entry);
        }

        // 11. Update mentor tracking
        if ($mentor && $isMentored) {
            $mentor->recordTransaction();
        }

        return $entry;
    }

    /**
     * Distribute revenue share through the sponsorship tree.
     * Returns total amount distributed.
     */
    public static function distributeRevenueShare(CommissionLedger $entry): string
    {
        $totalDistributed = '0.00';

        if (bccomp($entry->revenue_share_pool, '0', 2) <= 0) {
            return $totalDistributed;
        }

        $settings = CommissionSetting::forAgency($entry->agency_id);
        $sponsorChain = static::getSponsorChain($entry->user_id);
        $periodMonth = ($entry->deal_date ?? now())->startOfMonth()->toDateString();

        foreach ($sponsorChain as $sponsor) {
            $tier = $sponsor['tier'];
            $sponsorUserId = $sponsor['user_id'];

            // Get tier percent and FLQA requirement
            $tierPercent = static::getTierPercent($settings, $tier);
            $flqaRequired = static::getTierFlqaRequirement($settings, $tier);

            // Check FLQA requirement for tiers 4+
            if ($flqaRequired > 0) {
                $flqaCount = AgentSponsorship::getFLQACount($sponsorUserId);
                if ($flqaCount < $flqaRequired) {
                    continue;
                }
            }

            // Check receiving agent is active
            $receiver = User::where('id', $sponsorUserId)->where('is_active', true)->first();
            if (!$receiver) {
                continue;
            }

            $shareAmount = bcdiv(bcmul((string) $entry->company_dollar, (string) $tierPercent, 4), '100', 2);

            if (bccomp($shareAmount, '0', 2) <= 0) {
                continue;
            }

            RevenueShareLedger::create([
                'commission_ledger_id' => $entry->id,
                'producing_agent_id' => $entry->user_id,
                'receiving_agent_id' => $sponsorUserId,
                'tier' => $tier,
                'company_dollar' => $entry->company_dollar,
                'share_percent' => $tierPercent,
                'share_amount' => $shareAmount,
                'period_month' => $periodMonth,
            ]);

            $totalDistributed = bcadd($totalDistributed, $shareAmount, 2);
        }

        return $totalDistributed;
    }

    /**
     * Walk the sponsorship tree upward from producing agent.
     * Returns array of ['user_id' => int, 'tier' => int].
     */
    public static function getSponsorChain(int $userId, int $maxDepth = 7): array
    {
        $chain = [];
        $currentId = $userId;

        for ($tier = 1; $tier <= $maxDepth; $tier++) {
            $sponsorship = AgentSponsorship::active()
                ->where('agent_user_id', $currentId)
                ->first();

            if (!$sponsorship) {
                break;
            }

            $chain[] = [
                'user_id' => $sponsorship->sponsor_user_id,
                'tier' => $tier,
            ];

            $currentId = $sponsorship->sponsor_user_id;
        }

        return $chain;
    }

    /**
     * Get the revenue share percent for a given tier from settings.
     */
    private static function getTierPercent(CommissionSetting $settings, int $tier): string
    {
        return match ($tier) {
            1 => (string) $settings->tier_1_percent,
            2 => (string) $settings->tier_2_percent,
            3 => (string) $settings->tier_3_percent,
            4 => (string) $settings->tier_4_percent,
            5 => (string) $settings->tier_5_percent,
            6 => (string) $settings->tier_6_percent,
            7 => (string) $settings->tier_7_percent,
            default => '0.00',
        };
    }

    /**
     * Get the FLQA requirement for a given tier from settings.
     */
    private static function getTierFlqaRequirement(CommissionSetting $settings, int $tier): int
    {
        return match ($tier) {
            4 => $settings->tier_4_flqa_requirement,
            5 => $settings->tier_5_flqa_requirement,
            6 => $settings->tier_6_flqa_requirement,
            7 => $settings->tier_7_flqa_requirement,
            default => 0,
        };
    }
}
