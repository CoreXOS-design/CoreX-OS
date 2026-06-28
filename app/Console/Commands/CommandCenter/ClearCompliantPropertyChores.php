<?php

namespace App\Console\Commands\CommandCenter;

use App\Models\CommandCenter\CommandTask;
use Illuminate\Console\Command;

/**
 * One-off / repeatable cleanup: soft-delete the auto-generated document-upload
 * and idle-attention tasks that belong to properties already marked compliant.
 *
 * These were created by AutoEventService at property-create (P24 go-live
 * imports fire them, then get stamped compliant on confirm), so they're
 * redundant noise — every compliant listing carried "Upload signed mandate /
 * owner ID / proof of ownership" + "needs attention" prompts it never needed.
 *
 * Going forward DismissComplianceClearedChores prevents the build-up; this
 * command clears the existing backlog for every user across every agency.
 */
class ClearCompliantPropertyChores extends Command
{
    protected $signature = 'command-center:clear-compliant-chores {--dry : Count only, delete nothing}';
    protected $description = 'Soft-delete redundant auto document/attention tasks for already-compliant properties';

    /** Task types that exist only to chase a property toward compliance/activity. */
    private const CHORE_TYPES = ['document_upload', 'review'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $cleared = 0;
        $affectedUsers = [];

        CommandTask::withoutGlobalScopes()
            ->where('source_type', 'automation_rule')
            ->whereIn('task_type', self::CHORE_TYPES)
            ->whereNotIn('status', [CommandTask::STATUS_DONE, CommandTask::STATUS_DISMISSED])
            ->whereNull('deleted_at')
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                  ->from('properties')
                  ->whereColumn('properties.id', 'command_tasks.property_id')
                  ->whereNotNull('properties.compliance_snapshot_at')
                  ->whereNull('properties.deleted_at');
            })
            ->orderBy('id')
            ->chunkById(200, function ($tasks) use (&$cleared, &$affectedUsers, $dry) {
                foreach ($tasks as $task) {
                    if ($task->assigned_to) {
                        $affectedUsers[$task->assigned_to] = true;
                    }
                    if (!$dry) {
                        // Soft delete; the model's deleted hook busts each
                        // assignee's Today cockpit cache automatically.
                        $task->delete();
                    }
                    $cleared++;
                }
            });

        $prefix = $dry ? '[dry] ' : '';
        $this->info("{$prefix}Cleared {$cleared} redundant chore task(s) across " . count($affectedUsers) . ' user(s).');

        return self::SUCCESS;
    }
}
