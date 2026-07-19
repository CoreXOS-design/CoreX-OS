<?php

namespace App\Console\Commands;

use App\Models\AssistantAssignment;
use App\Services\Assistants\AssistantMatrixSnapshotService;
use Illuminate\Console\Command;

/**
 * AT-267 §14 E4 — the drift sync.
 *
 * When an Assigned Agent GAINS a permission after their assistant was set up, that new
 * capability is added to the matrix switched OFF (a brand-new capability is handed over
 * consciously, never silently — the resolver already follows the agent's ceiling live, but a
 * *new* row is a decision). This command is the nightly top-up that inserts those rows; the
 * agent's Assistant page then shows the "N new permissions available" chip.
 *
 * Idempotent and safe to re-run: syncDrift() only inserts missing keys, never flips an existing
 * choice. Runs in console context, so the global Agency/Branch scopes are no-ops and every
 * agency's active assignments are covered in one pass.
 */
class SyncAssistantMatrix extends Command
{
    protected $signature = 'assistants:sync-matrix';

    protected $description = 'Add newly-gained agent permissions to each active assistant matrix (switched off).';

    public function handle(AssistantMatrixSnapshotService $service): int
    {
        $assignments = 0;
        $newRows     = 0;

        AssistantAssignment::query()
            ->where('status', AssistantAssignment::STATUS_ACTIVE)
            ->with('assignedAgent')
            ->chunkById(200, function ($chunk) use ($service, &$assignments, &$newRows) {
                foreach ($chunk as $assignment) {
                    $assignments++;
                    $newRows += $service->syncDrift($assignment);
                }
            });

        $this->info("assistants:sync-matrix — {$assignments} active assignment(s) synced, {$newRows} new permission row(s) added (off).");

        return self::SUCCESS;
    }
}
