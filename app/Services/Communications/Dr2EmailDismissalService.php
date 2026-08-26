<?php

namespace App\Services\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationDr2Dismissal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CX-113 Phase G (Johan, 2026-08-22) — "how do i remove it?" Takes an email out of the
 * DR2 Unfiled Emails queue without touching the Communication row or its contact link.
 * Reversible, agency-wide, one row per communication (see the create-table migration).
 */
class Dr2EmailDismissalService
{
    public function dismiss(Communication $communication, User $user, string $reason, ?string $reasonOther = null): CommunicationDr2Dismissal
    {
        if (! array_key_exists($reason, CommunicationDr2Dismissal::REASONS)) {
            throw new InvalidArgumentException("Unknown dismissal reason: {$reason}");
        }

        return DB::transaction(function () use ($communication, $user, $reason, $reasonOther) {
            return CommunicationDr2Dismissal::updateOrCreate(
                ['communication_id' => $communication->id],
                [
                    'agency_id'             => $communication->agency_id,
                    'reason'                => $reason,
                    'reason_other'          => $reason === CommunicationDr2Dismissal::REASON_OTHER ? trim((string) $reasonOther) : null,
                    'dismissed_by_user_id'  => $user->id,
                    'dismissed_at'          => now(),
                    'restored_by_user_id'   => null,
                    'restored_at'           => null,
                ]
            );
        });
    }

    public function restore(Communication $communication, User $user): void
    {
        CommunicationDr2Dismissal::where('communication_id', $communication->id)
            ->whereNull('restored_at')
            ->update([
                'restored_by_user_id' => $user->id,
                'restored_at'         => now(),
            ]);
    }
}
