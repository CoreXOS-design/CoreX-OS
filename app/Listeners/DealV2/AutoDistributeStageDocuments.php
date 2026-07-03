<?php

declare(strict_types=1);

namespace App\Listeners\DealV2;

use App\Events\DealV2\DealStepCompleted;
use App\Models\User;
use App\Services\DealV2\DealDistributionService;
use Illuminate\Support\Facades\Log;

/**
 * AT-158 DR2 · WS4 (§8.3) — the red-button moment.
 *
 * When a stage ticks, fire every auto_on_stage_tick distribution rule for that
 * stage: e.g. OTP marked granted → the electrician's COC request is generated
 * from deal/property/contact data and emailed automatically. Synchronous but
 * fully guarded — a distribution failure must never disturb the deal engine.
 */
class AutoDistributeStageDocuments
{
    public function __construct(private DealDistributionService $distribution)
    {
    }

    public function handle(DealStepCompleted $event): void
    {
        try {
            $actorId = $event->actorUserId();
            $actor = $actorId ? User::find($actorId) : null;
            if (! $actor) {
                return; // can't attribute a send without an actor
            }

            $this->distribution->autoDistributeForStep($event->step, $actor);
        } catch (\Throwable $e) {
            Log::warning('AutoDistributeStageDocuments failed (non-fatal)', [
                'step_id' => $event->step->id ?? null,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
