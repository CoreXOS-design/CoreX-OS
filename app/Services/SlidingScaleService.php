<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealSettlement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SlidingScaleService
{
    /**
     * Apply (or clear) sliding scale audit fields.
     *
     * Key business rule:
     * - Sequence is the order deals BECOME Granted (accepted_status='G'), i.e. granted_at order.
     * - Whenever a deal enters/leaves Granted (before Paid), we must RECOMPUTE the entire agent+month set
     *   to avoid duplicate/incorrect sequences.
     *
     * Safety rules:
     * - Never touch Paid deals (commission_status = 'Paid')
     * - Overrides always win: if a settlement override exists for deal+user+side, do not update that row
     * - Do NOT overwrite pivot snapshotted agent_cut_percent; sliding is stored only in sliding_* fields
     */
    public function applyForDeal(Deal $deal): void
    {
        if ((string)($deal->commission_status ?? '') === 'Paid') {
            return;
        }

        DB::transaction(function () use ($deal) {

            $status = (string)($deal->accepted_status ?? '');

            // Capture old month before changes (if we are un-granting)
            $oldMonthKey = Carbon::parse($deal->deal_date)->format('Y-m');

            // Get distinct user_ids on this deal (both sides)
            $userIds = DB::table('deal_user')
                ->where('deal_id', $deal->id)
                ->distinct()
                ->pluck('user_id')
                ->map(fn($v) => (int)$v)
                ->all();

            if ($status !== 'G') {
                // Not granted: clear granted_at and sliding fields on THIS deal rows
                if ($deal->granted_at !== null) {
                    $deal->granted_at = null;
                    $deal->save();
                }

                DB::table('deal_user')
                    ->where('deal_id', $deal->id)
                    ->update([
                        'sliding_granted_month' => null,
                        'sliding_sequence_in_month' => null,
                        'sliding_applied_cut_percent' => null,
                        'sliding_applied_at' => null,
                        'updated_at' => now(),
                    ]);

                // Recompute the old month sequences for each agent (because removing a Granted deal shifts ranks)
                if ($oldMonthKey) {
                    foreach ($userIds as $uid) {
                        $this->recomputeUserMonth($uid, $oldMonthKey);
                    }
                }

                return;
            }

            // Granted: ensure granted_at is set once (stable ordering)
            if ($deal->granted_at === null) {
                // Microseconds to preserve ordering for back-to-back grants.
                $deal->granted_at = Carbon::now()->format('Y-m-d H:i:s.u');
                $deal->save();
            }

            $monthKey = Carbon::parse($deal->deal_date)->format('Y-m');

            // Recompute month sequences for each agent on this deal
            foreach ($userIds as $uid) {
                $this->recomputeUserMonth($uid, $monthKey);
            }
        });
    }

    /**
     * Recompute sliding sequences for one user for a given YYYY-MM month.
     * Updates all NON-PAID deals in that month for that user, except rows that have settlement overrides.
     */
    private function recomputeUserMonth(int $userId, string $monthKey): void
    {
        $user = User::find($userId);

        // If sliding is disabled or user missing, clear sliding fields on non-paid deals for that month (respect overrides)
        if (!$user || !$user->sliding_enabled) {
            $dealRows = $this->getUserDealRowsForMonth($userId, $monthKey);

            foreach ($dealRows as $row) {
                if ($row->commission_status === 'Paid') continue;
                if ($this->hasOverride((int)$row->deal_id, $userId, (string)$row->side)) continue;

                DB::table('deal_user')
                    ->where('deal_id', (int)$row->deal_id)
                    ->where('user_id', $userId)
                    ->where('side', (string)$row->side)
                    ->update([
                        'sliding_granted_month' => null,
                        'sliding_sequence_in_month' => null,
                        'sliding_applied_cut_percent' => null,
                        'sliding_applied_at' => null,
                        'updated_at' => now(),
                    ]);
            }

            return;
        }

        // Build ordered list of Granted deals (distinct deal ids) for the month
        $dealList = DB::table('deals')
            ->join('deal_user', 'deal_user.deal_id', '=', 'deals.id')
            ->where('deal_user.user_id', $userId)
            ->where('deals.accepted_status', 'G')
            ->whereNotNull('deals.granted_at')
            ->whereRaw("strftime('%Y-%m', deals.deal_date) = ?", [$monthKey])
            ->select('deals.id', 'deals.granted_at', 'deals.commission_status')
            ->distinct()
            ->orderBy('deals.granted_at')
            ->orderBy('deals.id')
            ->get();

        // Map deal_id => sequence (counting ALL granted deals, including paid ones, because they happened in that order)
        $seqMap = [];
        $seq = 0;
        foreach ($dealList as $d) {
            $seq++;
            $seqMap[(int)$d->id] = $seq;
        }

        // Now update deal_user rows for that user in those deals (both sides), skipping Paid deals and overrides
        $rows = $this->getUserDealRowsForMonth($userId, $monthKey);

        foreach ($rows as $row) {
            $dealId = (int)$row->deal_id;
            $side = (string)$row->side;

            // Only deals in seqMap are granted deals for this month
            if (!isset($seqMap[$dealId])) {
                continue;
            }

            // Never touch Paid deals
            if ((string)($row->commission_status ?? '') === 'Paid') {
                continue;
            }

            // Overrides always win
            if ($this->hasOverride($dealId, $userId, $side)) {
                continue;
            }

            $sequence = (int)$seqMap[$dealId];

            $tierCut = null;
            if ($sequence === 1) {
                $tierCut = $user->sliding_tier1_cut_percent;
            } elseif ($sequence === 2) {
                $tierCut = $user->sliding_tier2_cut_percent;
            } else {
                $tierCut = $user->sliding_tier3_cut_percent;
            }

            if ($tierCut === null || $tierCut === '') {
                DB::table('deal_user')
                    ->where('deal_id', $dealId)
                    ->where('user_id', $userId)
                    ->where('side', $side)
                    ->update([
                        'sliding_granted_month' => null,
                        'sliding_sequence_in_month' => null,
                        'sliding_applied_cut_percent' => null,
                        'sliding_applied_at' => null,
                        'updated_at' => now(),
                    ]);
                continue;
            }

            DB::table('deal_user')
                ->where('deal_id', $dealId)
                ->where('user_id', $userId)
                ->where('side', $side)
                ->update([
                    'sliding_granted_month' => $monthKey,
                    'sliding_sequence_in_month' => $sequence,
                    'sliding_applied_cut_percent' => (float)$tierCut,
                    'sliding_applied_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    private function getUserDealRowsForMonth(int $userId, string $monthKey)
    {
        return DB::table('deal_user')
            ->join('deals', 'deals.id', '=', 'deal_user.deal_id')
            ->where('deal_user.user_id', $userId)
            ->where('deals.accepted_status', 'G')
            ->whereNotNull('deals.granted_at')
            ->whereRaw("strftime('%Y-%m', deals.deal_date) = ?", [$monthKey])
            ->select('deal_user.deal_id', 'deal_user.side', 'deals.commission_status')
            ->get();
    }

    private function hasOverride(int $dealId, int $userId, string $side): bool
    {
        return DealSettlement::where('deal_id', $dealId)
            ->where('user_id', $userId)
            ->where('side', $side)
            ->exists();
    }
}
