<?php

namespace App\Console\Commands\FeatureRollout;

use App\Services\FeatureRollout\FeatureRolloutService;
use Illuminate\Console\Command;

/**
 * Releases the gradual-rollout waves that are due (AT-478, spec agency-feature-rollout.md §3.5).
 * Scheduled every 15 minutes; a wave goes out once its day and the plan's release time have come.
 * Idempotent — re-running in the same window releases nothing new.
 */
class ReleaseDueWavesCommand extends Command
{
    protected $signature = 'rollout:release';

    protected $description = 'Release gradual feature rollout waves that are due';

    public function handle(FeatureRolloutService $rollout): int
    {
        $count = $rollout->releaseDue();
        $this->info("Released {$count} wave(s).");

        return self::SUCCESS;
    }
}
