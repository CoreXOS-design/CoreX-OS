<?php

namespace App\Console\Commands\CommandCenter;

use App\Models\CommandCenter\CommandTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off / repeatable cleanup of redundant auto chore tasks (and the matching
 * "documents missing" notifications) that should never have lived:
 *
 *   1. Soft-delete the auto document-upload + idle-attention tasks whose linked
 *      property is REDUNDANT for chasing — any of:
 *        a. already compliant (compliance_snapshot_at set), OR
 *        b. bulk-imported P24 stock (p24_listing_number set) — existing
 *           inventory, not a freshly captured mandate, so it is never chased, OR
 *        c. orphaned — the property is soft-deleted or gone.
 *   2. Mark-read the unread "documents missing" pillar notifications for
 *      already-compliant properties.
 *
 * Compliant + imported stock legitimately has no uploaded documents, so the
 * "Upload signed mandate / owner ID / proof of ownership" tasks are pure noise;
 * left unchecked they accumulate without bound (an 18k-row todo backlog OOM'd
 * the Tasks board on staging).
 *
 * Going forward Property::$skipNewListingAutomation stops imported stock from
 * generating these at all, and DismissComplianceClearedChores clears them when
 * a property passes compliance. This command clears the existing backlog and is
 * scheduled daily as a self-healing backstop (routes/console.php).
 */
class ClearCompliantPropertyChores extends Command
{
    protected $signature = 'command-center:clear-compliant-chores {--dry : Count only, change nothing}';
    protected $description = 'Clear redundant auto chore tasks (compliant / imported / orphaned stock) + documents-missing notifications';

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
            ->where(function ($outer) {
                // (a) + (b): live property that is compliant OR imported P24 stock.
                $outer->whereExists(function ($q) {
                    $q->selectRaw('1')
                      ->from('properties')
                      ->whereColumn('properties.id', 'command_tasks.property_id')
                      ->whereNull('properties.deleted_at')
                      ->where(function ($p) {
                          $p->whereNotNull('properties.compliance_snapshot_at')
                            ->orWhereNotNull('properties.p24_listing_number');
                      });
                })
                // (c): orphaned — no live property backs this task.
                ->orWhereNotExists(function ($q) {
                    $q->selectRaw('1')
                      ->from('properties')
                      ->whereColumn('properties.id', 'command_tasks.property_id')
                      ->whereNull('properties.deleted_at');
                });
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
