<?php

namespace App\Console\Commands\RoadToSold;

use App\Models\RoadToSold\RtsProspect;
use App\Services\RoadToSold\ProspectService;
use Illuminate\Console\Command;

/**
 * Road to Sold v3 (road-to-sold.md 22.6) - an agent who has gone (deactivated or removed) keeps no allocation: their open prospects are
 * released to the pool so another agent can be offered the property. History keeps the agent. Safe to re-run.
 */
class ReleaseInactiveProspects extends Command
{
    protected $signature = 'rts:release-inactive {--agency= : Only this agency id} {--dry-run}';

    protected $description = 'Road to Sold v3: release the open prospects of deactivated or removed agents';

    public function handle(ProspectService $prospects): int
    {
        $n = 0;
        RtsProspect::withoutGlobalScopes()->owned()
            ->when($this->option('agency'), fn ($q, $a) => $q->where('rts_prospects.agency_id', (int) $a))
            ->join('users as u', 'u.id', '=', 'rts_prospects.user_id')
            ->where(fn ($w) => $w->where('u.is_active', false)->orWhereNotNull('u.deleted_at'))
            ->select('rts_prospects.*')
            ->chunkById(200, function ($rows) use ($prospects, &$n) {
                foreach ($rows as $p) {
                    $n++;
                    if (! $this->option('dry-run')) {
                        $prospects->release($p, null, 'agent no longer active');
                    }
                }
            }, 'rts_prospects.id', 'id');

        $this->info(($this->option('dry-run') ? 'Would release ' : 'Released ') . $n . ' prospect(s) of agents who have gone.');

        return self::SUCCESS;
    }
}
