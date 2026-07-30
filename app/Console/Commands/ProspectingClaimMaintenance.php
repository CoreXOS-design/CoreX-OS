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
        $expired = ProspectingClaim::active()
            ->whereNull('pitched_at')
            ->whereNull('feedback_at')
            ->where('claimed_at', '<', now()->subHours(48))
            ->update([
                'is_active'   => false,
                'released_at' => now(),
            ]);

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
