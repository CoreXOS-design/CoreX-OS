<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Models\User;
use App\Services\MarketIntelligence\ThisWeekTileBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MIC Phase E2 — nightly warm-up for the "This Week" tile cache.
 *
 * Runs at 02:30 SAST so the first agent visit of the day is sub-100ms
 * (cache hit) and the AI cost lands overnight rather than during peak
 * working hours. Skips agents whose agency has hit its monthly hard
 * cap (canMakeAiCall() == false) so over-budget agencies stop accruing
 * cost without breaking the surface — agent visits during the day fall
 * back to deterministic tiles per Phase B2.
 *
 * Spec: .ai/specs/mic-complete-spec.md §4.2.
 */
class WarmThisWeekTilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ThisWeekTileBuilder $builder): void
    {
        $started = microtime(true);

        $agents = User::query()
            ->whereIn('role', ['agent', 'manager', 'branch_manager', 'admin', 'super_admin'])
            ->where('is_active', true)
            ->whereNotNull('agency_id')
            ->with('agency')
            ->get();

        $built = 0;
        $skippedBudget = 0;
        $skippedNoAgency = 0;
        $errors = 0;

        foreach ($agents as $agent) {
            $agency = $agent->agency;
            if (!$agency) {
                $skippedNoAgency++;
                continue;
            }
            if (!$agency->canMakeAiCall()) {
                $skippedBudget++;
                continue;
            }

            try {
                $builder->buildFor($agent);
                $built++;
            } catch (Throwable $e) {
                $errors++;
                Log::warning('WarmThisWeekTilesJob: tile build failed', [
                    'agent_id' => $agent->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $elapsed = round(microtime(true) - $started, 2);

        Log::info('WarmThisWeekTilesJob complete', [
            'agents_built'         => $built,
            'skipped_budget'       => $skippedBudget,
            'skipped_no_agency'    => $skippedNoAgency,
            'errors'               => $errors,
            'total_eligible'       => $agents->count(),
            'elapsed_seconds'      => $elapsed,
        ]);
    }
}
