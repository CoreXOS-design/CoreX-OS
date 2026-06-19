<?php

namespace App\Console\Commands\Communications;

use App\Models\Agency;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Scopes\AgencyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prune orphaned provisional outbound communications (AT-59).
 *
 * A provisional row is created when an agent clicks WhatsApp/Email; ingestion
 * normally reconciles it within minutes/hours. But if the agent edits the
 * message before sending (breaking the text hash) AND it falls outside the
 * reconcile window, the provisional row never matches its real send — leaving an
 * orphan that would otherwise double-count. This command soft-purges provisional
 * rows older than the agency's configured prune age. No hard deletes.
 *
 * Mirrors communications:prune-pending (the inbound grace-buffer pruner).
 */
class PruneProvisionalComms extends Command
{
    protected $signature = 'communications:prune-provisional';

    protected $description = 'Soft-purge unreconciled provisional outbound communications past their prune age.';

    public function handle(): int
    {
        $pruneHoursByAgency = [];
        $pruned = 0;

        Communication::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->whereNotNull('provisional_at')
            ->whereNull('purged_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$pruneHoursByAgency, &$pruned) {
                foreach ($rows as $comm) {
                    $agencyId = (int) $comm->agency_id;

                    if (! array_key_exists($agencyId, $pruneHoursByAgency)) {
                        $agency = Agency::query()->withoutGlobalScope(AgencyScope::class)->find($agencyId);
                        $pruneHoursByAgency[$agencyId] = $agency
                            ? $agency->provisionalPruneHours()
                            : max(1, (int) config('communications.provisional_prune_hours', 168));
                    }

                    $cutoff = now()->subHours($pruneHoursByAgency[$agencyId]);

                    if (! $comm->provisional_at || $comm->provisional_at->gt($cutoff)) {
                        continue; // still within the reconcile window
                    }

                    DB::transaction(function () use ($comm, $agencyId) {
                        $comm->update([
                            'purged_at'     => now(),
                            'purged_reason' => 'provisional_unreconciled',
                        ]);
                        $comm->delete(); // soft — drops out of derived tile counts

                        CommunicationLink::query()
                            ->withoutGlobalScope(AgencyScope::class)
                            ->where('agency_id', $agencyId)
                            ->where('communication_id', $comm->id)
                            ->delete(); // soft
                    });

                    $pruned++;
                }
            });

        $this->info("Pruned {$pruned} unreconciled provisional communication(s).");

        return self::SUCCESS;
    }
}
