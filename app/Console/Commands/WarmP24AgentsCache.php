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
        foreach ($agencies as $agency) {
            $p24Id = (string) $agency->p24_agency_id;
            $client = new Property24ApiClient($agency);

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

        $this->info("P24 agents cache warm complete: {$warmed}/{$agencies->count()} agencies.");
        return self::SUCCESS;
    }
}
