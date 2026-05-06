<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LostDealAnalyticsService
{
    public function getReasonDistribution(int $agencyId, int $days = 90, ?int $branchId = null, ?int $agentId = null): Collection
    {
        $query = DB::table('buyer_lost_records')
            ->where('agency_id', $agencyId)
            ->where('recorded_at', '>=', now()->subDays($days));

        if ($branchId) $query->where('branch_id_at_loss', $branchId);
        if ($agentId) $query->where('agent_owner_user_id_at_loss', $agentId);

        return $query->selectRaw('reason_code, reason_label, COUNT(*) as cnt')
            ->groupBy('reason_code', 'reason_label')
            ->orderByDesc('cnt')
            ->get();
    }

    public function getAgentBenchmark(int $userId, int $days = 90): array
    {
        $agencyId = DB::table('users')->where('id', $userId)->value('agency_id') ?? 1;

        $agentDist = DB::table('buyer_lost_records')
            ->where('agent_owner_user_id_at_loss', $userId)
            ->where('recorded_at', '>=', now()->subDays($days))
            ->selectRaw('reason_code, COUNT(*) as cnt')
            ->groupBy('reason_code')
            ->pluck('cnt', 'reason_code');

        $agencyDist = DB::table('buyer_lost_records')
            ->where('agency_id', $agencyId)
            ->where('recorded_at', '>=', now()->subDays($days))
            ->selectRaw('reason_code, COUNT(*) as cnt')
            ->groupBy('reason_code')
            ->pluck('cnt', 'reason_code');

        return ['agent' => $agentDist->toArray(), 'agency' => $agencyDist->toArray()];
    }

    public function getValueAtLoss(int $agencyId, int $days = 90): array
    {
        $result = DB::table('buyer_lost_records')
            ->where('agency_id', $agencyId)
            ->where('recorded_at', '>=', now()->subDays($days))
            ->selectRaw('COUNT(*) as total_lost, SUM(preapproval_amount_at_loss) as total_value')
            ->first();

        return [
            'count' => (int) ($result->total_lost ?? 0),
            'value' => (float) ($result->total_value ?? 0),
        ];
    }

    public function getRecoverySuggestions(object $lostRecord): array
    {
        $suggestions = [];
        $code = $lostRecord->reason_code ?? '';

        if ($code === 'price_too_high') {
            $suggestions[] = 'Consider properties in a lower price bracket for re-engagement.';
            $suggestions[] = 'Check if any matching properties have had price reductions.';
        } elseif ($code === 'found_alternative') {
            $suggestions[] = 'Buyer went elsewhere — review if similar properties are available. Could re-engage if that deal falls through.';
        } elseif ($code === 'timing_changed') {
            $suggestions[] = 'Set a reminder to re-engage when buyer\'s timing may have improved (3-6 months).';
        } elseif ($code === 'mortgage_decline') {
            $suggestions[] = 'Buyer may have resolved financial situation. Follow up in 30-60 days.';
        } elseif ($code === 'no_activity') {
            $suggestions[] = 'Send new matches to test if buyer is still in the market.';
            $suggestions[] = 'Quick call or WhatsApp: "Still looking?"';
        }

        return $suggestions;
    }
}
