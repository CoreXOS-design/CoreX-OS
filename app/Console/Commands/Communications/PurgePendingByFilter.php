<?php

namespace App\Console\Commands\Communications;

use App\Models\Agency;
use App\Models\Communications\CommunicationPending;
use App\Models\Scopes\AgencyScope;
use App\Services\Communications\CommunicationIngestFilter;
use Illuminate\Console\Command;

/**
 * Apply the deterministic ingestion filter (AT-43) to EXISTING pending rows
 * captured before the filter existed. Soft-purges never-business senders
 * (no-reply / bank / service domains) that don't match a contact; KEEPS anything
 * that matches a CoreX contact or isn't on the droplist. Contact always wins.
 *
 * Always dry-run first (--dry-run) — shows exactly what would be purged/kept.
 * No hard deletes: purged rows get purged_at/purged_reason + a soft delete.
 */
class PurgePendingByFilter extends Command
{
    protected $signature = 'communications:purge-pending-by-filter
                            {--agency= : Limit to one agency_id}
                            {--dry-run : Show what would be purged/kept without writing}';

    protected $description = 'Soft-purge pending communications whose sender is never-business (no-reply/bank/service) and not a contact (AT-43).';

    public function handle(CommunicationIngestFilter $filter): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $agencyId = $this->option('agency');

        $agencies = [];
        $purged = 0;
        $kept = 0;
        $byReason = [];

        CommunicationPending::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->whereNull('purged_at')
            ->when($agencyId !== null, fn ($q) => $q->where('agency_id', (int) $agencyId))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($filter, $dryRun, &$agencies, &$purged, &$kept, &$byReason) {
                foreach ($rows as $pending) {
                    $aid = (int) $pending->agency_id;
                    $agencies[$aid] ??= Agency::withoutGlobalScope(AgencyScope::class)->find($aid);

                    [$keep, $reason] = $filter->evaluateExisting((string) $pending->from_identifier, $aid, $agencies[$aid]);

                    if ($keep) {
                        $kept++;
                        continue;
                    }

                    $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
                    $this->line(($dryRun ? '[would purge] ' : '[purge] ') . "pending {$pending->id}  from={$pending->from_identifier}  reason={$reason}");

                    if (! $dryRun) {
                        $pending->update(['purged_at' => now(), 'purged_reason' => 'ingest_filter_' . $reason]);
                        $pending->delete(); // soft
                    }
                    $purged++;
                }
            });

        $reasonSummary = empty($byReason) ? '—' : collect($byReason)->map(fn ($n, $r) => "{$r}={$n}")->implode(', ');
        $this->info(($dryRun ? '[dry-run] ' : '') . "Scanned: {$purged} would-purge / {$kept} kept. Reasons: {$reasonSummary}");

        return self::SUCCESS;
    }
}
