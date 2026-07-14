<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\User;
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
                    $mapped = $this->mapAgentIds($result['data'] ?? [], (int) $p24Id);
                    $this->info("Warmed P24 agency {$p24Id}: " . count($result['data'] ?? []) . " agents in {$secs}s ({$mapped} id(s) mapped)");
                } else {
                    $this->warn("Failed to warm P24 agency {$p24Id} ({$secs}s): " . ($result['message'] ?? 'unknown'));
                }
            }
        }

        $this->info("P24 agents cache warm complete: {$warmed}/{$attempted} P24 agency IDs.");
        return self::SUCCESS;
    }

    /**
     * Stamp each CoreX user with the P24 agent id they hold under this agency.
     *
     * This is what keeps the ~90s GET /agencies/{id}/agents OFF the listing-submit
     * path entirely: with the (p24_agent_id, p24_agent_agency_id) pair recorded,
     * Property24SyndicationService::resolveRegisteredAgentId answers "is this agent
     * on P24?" from the users table with zero HTTP. Warming the cache alone was
     * never enough — `php artisan cache:clear` is in the deploy checklist, so the
     * cache is cold after every deploy and the next agent to press Refresh ate the
     * cold fetch. The users table survives cache:clear.
     *
     * @param array<int,array<string,mixed>> $agents
     */
    private function mapAgentIds(array $agents, int $p24AgencyId): int
    {
        $mapped = 0;

        foreach ($agents as $agent) {
            $sourceRef  = (string) ($agent['sourceReference'] ?? '');
            $p24AgentId = (int) ($agent['id'] ?? 0);

            if ($p24AgentId <= 0 || !preg_match('/^CoreX-Agent-(\d+)$/', $sourceRef, $m)) {
                continue;
            }

            // withTrashed: a soft-deleted agent is still ON P24 (pushed Inactive),
            // and their id must still resolve without a list scan.
            $user = User::withoutGlobalScopes()->withTrashed()->find((int) $m[1]);
            if (!$user) {
                continue;
            }

            if ((int) $user->p24_agent_id === $p24AgentId && (int) $user->p24_agent_agency_id === $p24AgencyId) {
                continue; // already mapped
            }

            // saveQuietly: a cache stamp, not a user edit — must not fire the User
            // observer and bounce this agent straight back to P24.
            $user->forceFill([
                'p24_agent_id'        => $p24AgentId,
                'p24_agent_agency_id' => $p24AgencyId,
            ])->saveQuietly();

            $mapped++;
        }

        return $mapped;
    }
}
