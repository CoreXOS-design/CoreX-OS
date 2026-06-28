<?php

namespace App\Console\Commands\CommandCenter;

use App\Models\CommandCenter\CommandTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off / repeatable cleanup for already-compliant properties:
 *   1. Soft-delete the auto-generated document-upload + idle-attention tasks.
 *   2. Mark-read the unread "documents missing" pillar notifications.
 *
 * Compliant stock (P24 go-live imports especially) legitimately has no uploaded
 * documents, so both the "Upload signed mandate / owner ID / proof of
 * ownership" tasks AND the "documents missing" notifications are pure noise.
 *
 * Going forward DismissComplianceClearedChores (tasks) and the compliance guard
 * in ScanPropertyNotifications (notifications) prevent the build-up; this
 * command clears the existing backlog for every user across every agency.
 */
class ClearCompliantPropertyChores extends Command
{
    protected $signature = 'command-center:clear-compliant-chores {--dry : Count only, change nothing}';
    protected $description = 'Clear redundant auto tasks + documents-missing notifications for already-compliant properties';

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

        // Unread "documents missing" notifications for compliant properties.
        // Mark-read (never hard-delete — non-negotiable #1) so they drop off the
        // Unread Notifications card. Matched by the pillar notification's stored
        // data: event_key + the subject property being currently compliant.
        $notifQuery = DB::table('notifications')
            ->whereNull('read_at')
            ->where('data->event_key', 'property.documents_missing')
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                  ->from('properties')
                  ->whereRaw("properties.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(notifications.data, '$.subject_id')) AS UNSIGNED)")
                  ->whereNotNull('properties.compliance_snapshot_at')
                  ->whereNull('properties.deleted_at');
            });

        $notifCount = $notifQuery->count();
        if (!$dry && $notifCount > 0) {
            $notifQuery->update(['read_at' => now(), 'updated_at' => now()]);
        }

        $prefix = $dry ? '[dry] ' : '';
        $this->info("{$prefix}Cleared {$cleared} redundant chore task(s) across " . count($affectedUsers) . ' user(s).');
        $this->info("{$prefix}Marked {$notifCount} documents-missing notification(s) read.");

        return self::SUCCESS;
    }
}
