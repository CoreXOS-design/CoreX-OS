<?php

namespace App\Console\Commands\Communications;

use App\Models\Communications\Communication;
use App\Models\Scopes\AgencyScope;
use Illuminate\Console\Command;

/**
 * Nightly 5-year retention prune (AT-32, spec §7). Soft event only — sets
 * purged_at/purged_reason on index rows past the window. Never hard-deletes,
 * and never removes content-addressed bytes (they may be shared by dedup).
 */
class PruneCommunicationRetention extends Command
{
    protected $signature = 'communications:prune-retention {--years=5 : Retention window in years}';

    protected $description = 'Soft-purge communications past the retention window (default 5 years).';

    public function handle(): int
    {
        $years = max(1, (int) $this->option('years'));
        $cutoff = now()->subYears($years);

        $purged = Communication::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->whereNull('purged_at')
            ->where('occurred_at', '<', $cutoff)
            ->update([
                'purged_at'     => now(),
                'purged_reason' => "retention_{$years}yr",
            ]);

        $this->info("Purged {$purged} communication(s) past the {$years}-year retention window.");

        return self::SUCCESS;
    }
}
