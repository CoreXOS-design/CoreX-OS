<?php

namespace App\Console\Commands;

use App\Models\ProspectingClaim;
use Illuminate\Console\Command;

class ProspectingClaimMaintenance extends Command
{
    protected $signature = 'prospecting:maintain-claims';

    protected $description = 'Auto-release expired prospecting claims and flag stale listing-status claims for BM review';

    public function handle(): int
    {
        // 1. Auto-release expired claims (48h with no feedback).
        //    PITCHED claims (pitched_at set — the agent captured + linked a
        //    contact via "Pitch now") are permanent and never auto-release; the
        //    48h window applies only to a claim-without-pitch.
        $expiredQuery = ProspectingClaim::active()
            ->whereNull('pitched_at')
            ->whereNull('feedback_at')
            ->where('claimed_at', '<', now()->subHours(48));

        // Bulk ->update() below bypasses Eloquent model events (no individual
        // models hydrated), so ProspectingClaimObserver never sees this sweep —
        // capture which agencies are affected first and bump their MIC counts
        // cache version explicitly (see ProspectingClaimObserver docblock).
        // This sweep spans every agency in one query, unlike the other claim
        // write sites, so the affected-agency set has to be collected up front.
        $affectedAgencyIds = (clone $expiredQuery)->distinct()->pluck('agency_id');

        $expired = $expiredQuery->update([
            'is_active'   => false,
            'released_at' => now(),
        ]);

        foreach ($affectedAgencyIds as $agencyId) {
            ProspectingClaim::bumpCountsCacheVersion((int) $agencyId);
        }

        $this->info("Released {$expired} expired claim(s).");

        // 2. Flag "listing" status claims older than 14 days for BM review
        $flagged = ProspectingClaim::active()
            ->where('status', 'listing')
            ->whereNull('flagged_at')
            ->where('last_updated_at', '<', now()->subDays(14))
            ->update([
                'flagged_at' => now(),
            ]);

        $this->info("Flagged {$flagged} claim(s) for BM review.");

        return self::SUCCESS;
    }
}
