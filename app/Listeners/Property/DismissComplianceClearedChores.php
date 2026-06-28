<?php

declare(strict_types=1);

namespace App\Listeners\Property;

use App\Events\Property\PropertyCompliancePassed;
use App\Models\CommandCenter\CommandTask;
use Illuminate\Support\Facades\Log;

/**
 * When a property passes compliance, its auto-generated "chase" tasks become
 * redundant — the document uploads they ask for are exactly what compliance
 * just confirmed, and a compliant listing isn't an at-risk one.
 *
 * The P24 go-live importer is the headline case: it creates the property
 * (firing AutoEventService's "Upload signed mandate / owner ID / proof of
 * ownership" tasks) and THEN stamps compliance_snapshot_at on confirm. The
 * document tasks already exist by the time compliance is set, so they can't be
 * prevented at create time — this listener clears them the moment the
 * PropertyCompliancePassed event fires (same path for manual compliance too).
 *
 * Only auto-generated tasks (source_type = 'automation_rule') are touched —
 * never a manually-created task. Soft delete, so admin can recover.
 * Failure-isolated: a cleanup hiccup must never break the compliance write.
 */
class DismissComplianceClearedChores
{
    /** Task types that exist only to chase a property toward compliance/activity. */
    private const CHORE_TYPES = ['document_upload', 'review'];

    public function handle(PropertyCompliancePassed $event): void
    {
        try {
            // withoutGlobalScopes: the event commonly fires inside the P24
            // import job (queued, no auth user) — bypass AgencyScope and
            // LivePropertyScope and target by the property's own id.
            $tasks = CommandTask::withoutGlobalScopes()
                ->where('property_id', $event->property->id)
                ->where('source_type', 'automation_rule')
                ->whereIn('task_type', self::CHORE_TYPES)
                ->whereNotIn('status', [CommandTask::STATUS_DONE, CommandTask::STATUS_DISMISSED])
                ->get();

            foreach ($tasks as $task) {
                $task->delete(); // soft delete; fires the cockpit cache bust
            }

            if ($tasks->isNotEmpty()) {
                Log::info('Compliance passed — cleared redundant chore tasks', [
                    'property_id' => $event->property->id,
                    'count'       => $tasks->count(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning(
                "DismissComplianceClearedChores failed for property #{$event->property->id}: {$e->getMessage()}"
            );
        }
    }
}
