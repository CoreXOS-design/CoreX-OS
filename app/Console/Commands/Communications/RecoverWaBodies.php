<?php

namespace App\Console\Commands\Communications;

use App\Models\Communications\Communication;
use App\Models\Scopes\AgencyScope;
use App\Services\Communications\WaEmbargoReleaseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * AT-168 Part B — one-time (and on-demand) recovery of withheld WhatsApp bodies.
 *
 * Fills the blank bodies that predate the embargo fix: embargoed rows are
 * hydrated from their stored raw (reliable); legacy consent_pending rows whose
 * raw was redacted at capture are re-fetched from the WAHA session store where
 * still retrievable (GOWS evicts old messages, and an extension-era chat a WAHA
 * session never held cannot be recovered — the report is honest about this).
 *
 * A row is only made VISIBLE where the owning agent has opted in to that contact
 * (WaEmbargoReleaseService is consent-aware); otherwise the recovered body is
 * kept embargoed and released later on opt-in.
 */
class RecoverWaBodies extends Command
{
    protected $signature = 'communications:recover-wa-bodies
        {--agency= : Restrict to one agency id (default: all agencies)}
        {--dry-run : Report the recoverable set without writing}';

    protected $description = 'Recover withheld/blank WhatsApp bodies (embargoed from raw; legacy consent_pending from WAHA where retrievable).';

    public function handle(WaEmbargoReleaseService $release): int
    {
        $agencyId = $this->option('agency') !== null ? (int) $this->option('agency') : null;
        $dryRun   = (bool) $this->option('dry-run');
        $scope    = $agencyId !== null ? "agency {$agencyId}" : 'all agencies';

        $base = Communication::query()->withoutGlobalScope(AgencyScope::class)
            ->where('channel', Communication::CHANNEL_WHATSAPP)
            ->whereNull('purged_at')
            ->whereIn('body_status', ['embargoed', 'consent_pending'])
            ->when($agencyId !== null, fn ($q) => $q->where('agency_id', $agencyId));

        $total = (clone $base)->count();
        if ($total === 0) {
            $this->info("No withheld WhatsApp bodies to recover ({$scope}).");
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("[dry-run] {$total} withheld WhatsApp message(s) are candidates for recovery ({$scope}). Nothing written.");
            return self::SUCCESS;
        }

        $tally = ['released' => 0, 'recovered' => 0, 'failed' => 0];
        (clone $base)->orderBy('id')->chunkById(200, function ($rows) use ($release, &$tally) {
            foreach ($rows as $row) {
                $outcome = $release->releaseOne($row);
                if (isset($tally[$outcome])) {
                    $tally[$outcome]++;
                }
            }
        });

        Log::info('AT-168 WhatsApp body recovery run', array_merge($tally, [
            'scope' => $scope, 'agency_id' => $agencyId, 'candidates' => $total,
        ]));

        $this->info("Recovery complete ({$scope}): {$tally['released']} released (now visible), "
            . "{$tally['recovered']} recovered into embargo (await opt-in), {$tally['failed']} unrecoverable of {$total}.");

        return self::SUCCESS;
    }
}
