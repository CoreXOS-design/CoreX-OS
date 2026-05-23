<?php

namespace App\Observers;

use App\Events\Deal\DealClosed;
use App\Events\Deal\DealCommissionFinalised;
use App\Events\Deal\DealCreated;
use App\Events\Deal\DealStageAdvanced;
use App\Events\Deal\DealStatusChanged;
use App\Models\Deal;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

class DealObserver
{
    public function created(Deal $deal): void
    {
        // Domain event — spec .ai/specs/corex-domain-events-spec.md
        event(new DealCreated($deal, Auth::id()));
    }

    public function updated(Deal $deal): void
    {
        // Fire DealStatusChanged when accepted_status or commission_status flips.
        $statusFields = ['accepted_status', 'commission_status'];
        foreach ($statusFields as $field) {
            if (!$deal->wasChanged($field)) {
                continue;
            }
            $from = $deal->getOriginal($field);
            $to   = $deal->{$field};
            event(new DealStatusChanged($deal, (string)$from ?: null, (string)$to ?: null, Auth::id()));

            if ($field === 'accepted_status') {
                $outcome = match ($to) {
                    'R'     => 'won',        // Registered = won
                    'D'     => 'lost',       // Declined
                    default => null,
                };
                if ($outcome) {
                    event(new DealClosed($deal, $outcome, Auth::id()));
                }

                // DealStageAdvanced — forward progression only (P → G → R).
                // Spec: .ai/specs/corex-domain-events-spec.md (Wave 6).
                $rank = ['P' => 1, 'G' => 2, 'R' => 3];
                $fromRank = $rank[(string) $from] ?? 0;
                $toRank   = $rank[(string) $to] ?? 0;
                if ($toRank > 0 && $toRank > $fromRank) {
                    event(new DealStageAdvanced(
                        deal: $deal,
                        fromStage: $from !== null && $from !== '' ? (string) $from : null,
                        toStage: (string) $to,
                        actorUserId: Auth::id(),
                    ));
                }
            }

            if ($field === 'commission_status') {
                // DealCommissionFinalised when commission_status transitions to a
                // terminal "paid" state.
                $terminalStates = ['Paid', 'paid', 'finalised', 'finalized'];
                if (in_array((string) $to, $terminalStates, true) && !in_array((string) $from, $terminalStates, true)) {
                    event(new DealCommissionFinalised(
                        deal: $deal,
                        commission: [
                            'commission_status' => (string) $to,
                            'total_commission'  => $deal->total_commission,
                        ],
                        actorUserId: Auth::id(),
                    ));
                }
            }
        }
    }

    public function saved(Deal $deal): void
    {
        Artisan::call('deals:recalc-money-lines');
    }
}
