<?php

declare(strict_types=1);

namespace App\Console\Commands\Prospecting;

use App\Models\ProspectingClaim;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Prospecting\ProspectingConfigurationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MIC funnel phase 2 (Johan 2026-08-13) — warn the agent when their pitched/claimed property is
 * going stale (unworked past the agency's configurable `claim_warn_days`, default 7). Working the
 * claim resets the timer (recordActionOnClaim clears warned_at), so the warn re-arms each cycle.
 * Dedup via `warned_at`: an already-warned, still-unworked claim is not re-notified.
 *
 * Does NOT auto-release — at `claim_release_days` the claim surfaces in the BM/admin stale-review
 * screen for a move-or-keep decision (agents never grab stale stock from each other). Manual op +
 * scheduled; idempotent.
 *
 *   php artisan prospecting:warn-stale-claims [--dry-run] [--agency=N]
 */
class ProspectingStaleClaimWarnCommand extends Command
{
    protected $signature = 'prospecting:warn-stale-claims {--dry-run} {--agency=}';
    protected $description = 'Warn agents when their pitched/claimed property is going stale (agency-configurable claim_warn_days).';

    public function handle(ProspectingConfigurationService $config, NotificationDispatcher $dispatcher): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $agencyOpt = $this->option('agency');
        $agencyFilter = $agencyOpt !== null ? (int) $agencyOpt : null;

        $agencyIds = ProspectingClaim::query()
            ->where('is_active', true)
            ->whereNull('released_at')
            ->whereNull('warned_at')
            ->when($agencyFilter !== null, fn ($q) => $q->where('agency_id', $agencyFilter))
            ->whereNotNull('agency_id')
            ->distinct()->pluck('agency_id')->map(fn ($v) => (int) $v)->all();

        $totalWarned = 0;
        foreach ($agencyIds as $agencyId) {
            $warnDays = (int) $config->getSuggestedActionThresholds($agencyId)->claim_warn_days;

            $claims = ProspectingClaim::query()
                ->where('agency_id', $agencyId)
                ->where('is_active', true)
                ->whereNull('released_at')
                ->whereNull('warned_at')
                ->get();

            foreach ($claims as $claim) {
                if (! $claim->needsStaleWarning($warnDays)) {
                    continue;
                }
                $agent = User::find($claim->user_id);
                $days = $claim->staleAgeDays();
                $label = $this->propertyLabel($claim);

                $this->line(sprintf('  agency %d: claim #%d (agent #%s, %d days unworked) — %s', $agencyId, $claim->id, $claim->user_id, $days, $label));

                if ($dryRun || ! $agent) {
                    if ($dryRun) { $totalWarned++; }
                    continue;
                }

                $dispatcher->fire($agent, 'prospecting.claim_stale_warning', $claim, [
                    'title'            => "{$label} — going stale",
                    'body'            => "You've held this for {$days} days without an update. Work it or it goes to your manager for review.",
                    'subject_label'    => $label,
                    'action_url'       => route('market-intelligence.work', ['action_preset' => 'my_claims'], false),
                    'severity'         => 'warning',
                    'threshold_hit_at' => now()->startOfDay(),
                ]);
                $claim->update(['warned_at' => now()]);
                $totalWarned++;
            }
        }

        $tag = $dryRun ? '[DRY-RUN]' : '[WRITE]';
        $this->info("$tag prospecting:warn-stale-claims — " . ($dryRun ? "$totalWarned claim(s) WOULD be warned." : "$totalWarned agent warning(s) sent."));
        return self::SUCCESS;
    }

    private function propertyLabel(ProspectingClaim $claim): string
    {
        $addr = DB::table('prospecting_listings')->where('id', $claim->prospecting_listing_id)->value('address');
        if (trim((string) $addr) !== '') {
            return (string) $addr;
        }
        if ($claim->property_id) {
            $paddr = DB::table('properties')->where('id', $claim->property_id)->value('address');
            if (trim((string) $paddr) !== '') {
                return (string) $paddr;
            }
        }
        return 'Your claimed property';
    }
}
