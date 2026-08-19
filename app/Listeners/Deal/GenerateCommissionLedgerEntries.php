<?php

namespace App\Listeners\Deal;

use App\Events\Deal\DealCommissionFinalised;
use App\Models\CommissionLedger;
use App\Models\PerformanceSetting;
use App\Services\CommissionCalculationService;
use App\Services\Finance\CommissionCalculator;
use Illuminate\Support\Facades\Log;

/**
 * Wires the cap/revenue-share "Commission Engine" (CommissionLedger, AgentCapPeriod,
 * RevenueShareLedger) to actual deal completion — the integration point
 * commission_engine_spec.md §13 calls for ("Deal completion → auto-create
 * CommissionLedger entry") but that was never built. Until this listener, nothing in
 * the app ever created a CommissionLedger row, so every agent's "My Earnings" /
 * cap-progress / revenue-share dashboard read empty forever (.ai/atlas/deals-commission.md
 * §8.1 — "System C commission engine is orphaned").
 *
 * One ledger entry per agent on the deal, summed across that agent's listing/selling
 * pivot rows (a dual-mandate agent gets one entry, not two). The amount fed in is the
 * agent's OWN allocation of their side's pool (side pool × their agent_split_percent),
 * never the raw side pool or the deal's total_commission — either of those would
 * double-count on any deal with co-agents sharing a side.
 *
 * Prevent-or-absorb: never break the deal save that triggered this. Failure-isolated
 * per agent so one bad row doesn't block the rest of the deal's agents.
 */
class GenerateCommissionLedgerEntries
{
    public function handle(DealCommissionFinalised $event): void
    {
        try {
            $deal = $event->deal;
            $dealAgencyId = (int) ($deal->agency_id ?? 0);
            if ($dealAgencyId <= 0) {
                return;
            }

            $agents = $deal->agents()->get();
            if ($agents->isEmpty()) {
                return;
            }

            $vatRate = max(0.0, ((float) PerformanceSetting::get('vat_rate', 15)) / 100.0);

            // Sum each agent's own allocation across their pivot rows on this deal.
            $byAgent = [];
            foreach ($agents as $agent) {
                if ((int) ($agent->agency_id ?? 0) !== $dealAgencyId) {
                    // E7 belt-and-braces — AgencyScope should already prevent this.
                    Log::error('GenerateCommissionLedgerEntries: agent/deal agency mismatch, skipped', [
                        'deal_id' => $deal->id,
                        'agent_id' => $agent->id,
                        'deal_agency_id' => $dealAgencyId,
                        'agent_agency_id' => $agent->agency_id,
                    ]);
                    continue;
                }

                $side = strtolower(trim((string) ($agent->pivot->side ?? '')));
                $sidePool = CommissionCalculator::companyIncomeExVatForSide($deal, $side);
                if ($sidePool <= 0) {
                    continue;
                }

                $allocPct = max(0.0, min(100.0, (float) ($agent->pivot->agent_split_percent ?? 0)));
                $allocation = round($sidePool * ($allocPct / 100.0), 2);
                if ($allocation <= 0) {
                    continue;
                }

                $uid = (int) $agent->id;
                $byAgent[$uid] = round(($byAgent[$uid] ?? 0.0) + $allocation, 2);
            }

            foreach ($byAgent as $userId => $exVat) {
                if ($exVat <= 0) {
                    continue;
                }

                // Idempotent — a re-fired event (commission_status corrected back and
                // forward) must not double-post the same deal for the same agent.
                if (CommissionLedger::where('deal_id', $deal->id)->where('user_id', $userId)->exists()) {
                    continue;
                }

                $grossIncVat = round($exVat * (1 + $vatRate), 2);
                $vatAmount = round($grossIncVat - $exVat, 2);

                try {
                    CommissionCalculationService::calculateDealCommission(
                        userId: $userId,
                        grossCommission: (string) $grossIncVat,
                        vatAmount: (string) $vatAmount,
                        transactionType: 'sale',
                        description: 'Deal #' . $deal->id . ($deal->property_address ? ' — ' . $deal->property_address : ''),
                        dealId: $deal->id,
                        propertyId: $deal->property_id,
                    );
                } catch (\Throwable $e) {
                    Log::error('GenerateCommissionLedgerEntries failed for one agent', [
                        'deal_id' => $deal->id,
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('GenerateCommissionLedgerEntries failed', [
                'error' => $e->getMessage(),
                'deal_id' => $event->deal->id ?? null,
            ]);
        }
    }
}
