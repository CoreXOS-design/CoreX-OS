<?php

namespace App\Console\Commands\FeatureRollout;

use App\Services\FeatureRollout\FeatureRolloutService;
use App\Services\FeatureRollout\FeatureRolloutUpdates;
use App\Services\FeatureRollout\FeatureRolloutUsage;
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

    public function handle(FeatureRolloutService $rollout, FeatureRolloutUsage $usage, FeatureRolloutUpdates $updates): int
    {
        $count = $rollout->releaseDue();
        $this->info("Released {$count} wave(s).");
        // §12.6 — once, a few days after a feature switched on, remind people who have not taken its tour.
        $nudged = $usage->nudgeTours();
        $this->info("Sent {$nudged} tour reminder(s).");
        // §12.4 — the release day before, tell the rollout admins what switches on next (once per group).
        $told = $updates->notifyDayBefore();
        $this->info("Told admins about {$told} group(s) switching on next.");

        return self::SUCCESS;
    }
}
