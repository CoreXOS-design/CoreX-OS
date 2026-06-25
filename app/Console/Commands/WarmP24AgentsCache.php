<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Services\Syndication\Property24\Property24ApiClient;
use Illuminate\Console\Command;

/**
 * Pre-fetch each agency's P24 agent list into the cross-request cache so manual
 * Refresh and agent sync stay fast (~7s) instead of paying P24's ~90s cold
 * GET /agencies/{id}/agents. Scheduled nightly (22:00) — the cache TTL is long
 * enough that one nightly warm keeps it hot all the next day. Idempotent.
 */
class WarmP24AgentsCache extends Command
{
    protected $signature = 'p24:warm-agents-cache';

    protected $description = 'Warm the P24 agent-list cache per agency so manual Refresh / sync stays fast';

    public function handle(): int
    {
        $agencies = Agency::query()
            ->whereNotNull('p24_agency_id')
            ->where('p24_agency_id', '!=', '')
            ->get();

        if ($agencies->isEmpty()) {
            $this->warn('No agencies with a P24 agency ID configured.');
            return self::SUCCESS;
        }

        $warmed = 0;
        $attempted = 0;
        foreach ($agencies as $agency) {
            // A listing submit resolves its P24 agency ID at the BRANCH level
            // first (Branch::resolveP24AgencyId), falling back to the agency's
            // default. getAgents() caches per resolved ID, so warming ONLY the
            // agency default leaves every branch-overridden ID cold — and a cold
            // ~90-120s getAgents on the submit path blows the 180s job timeout,
            // freezing the listing at 'submitting'. Warm EVERY distinct ID the
            // submit path can resolve to, all under this agency's credentials.
            $p24Ids = collect([$agency->p24_agency_id])
                ->merge($agency->branches()->pluck('p24_agency_id'))
                ->map(fn ($id) => (string) $id)
                ->filter(fn ($id) => $id !== '')
                ->unique()
                ->values();

            $client = new Property24ApiClient($agency);

            foreach ($p24Ids as $p24Id) {
                $attempted++;
                $t = microtime(true);
                $result = $client->getAgents($p24Id, forceRefresh: true);
                $secs = round(microtime(true) - $t, 1);

                if ($result['success'] ?? false) {
                    $warmed++;
                    $this->info("Warmed P24 agency {$p24Id}: " . count($result['data'] ?? []) . " agents in {$secs}s");
                } else {
                    $this->warn("Failed to warm P24 agency {$p24Id} ({$secs}s): " . ($result['message'] ?? 'unknown'));
                }
            }
        }

        $this->info("P24 agents cache warm complete: {$warmed}/{$attempted} P24 agency IDs.");
        return self::SUCCESS;
    }
}
