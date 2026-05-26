<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AgentDeletionService
{
    /**
     * Count records that would be reassigned/removed if this agent were deleted.
     *
     * @return array{
     *     properties_primary:int,
     *     properties_secondary:int,
     *     contacts:int,
     *     calendar_events:int,
     *     command_tasks:int,
     *     has_any:bool
     * }
     */
    public function preview(User $user): array
    {
        $primary   = DB::table('properties')->whereNull('deleted_at')->where('agent_id', $user->id)->count();
        $secondary = DB::table('properties')->whereNull('deleted_at')->where('pp_second_agent_id', $user->id)->count();
        $contacts  = DB::table('contacts')->whereNull('deleted_at')->where('created_by_user_id', $user->id)->count();
        $events    = DB::table('calendar_events')->whereNull('deleted_at')->where('user_id', $user->id)->count();
        $tasks     = DB::table('command_tasks')->whereNull('deleted_at')->where('assigned_to', $user->id)->count();

        return [
            'properties_primary'   => $primary,
            'properties_secondary' => $secondary,
            'contacts'             => $contacts,
            'calendar_events'      => $events,
            'command_tasks'        => $tasks,
            'has_any'              => ($primary + $secondary + $contacts + $events + $tasks) > 0,
        ];
    }

    /**
     * Point the departing agent's QR slug at a live agent.
     *
     * The slug stays on $source (audit anchor); scans now resolve through
     * the reroute pointer to $target. Mandatory on every agent delete so no
     * printed QR code ever dead-ends. Chained automatically if $target later
     * leaves too (see User::resolveByQrSlug).
     *
     * Spec: .ai/specs/agent-qr-onboarding.md
     */
    public function setQrReroute(User $source, User $target, int $actorId): void
    {
        DB::table('users')->where('id', $source->id)->update([
            'qr_reroute_user_id' => $target->id,
            'updated_at'         => now(),
        ]);

        Log::info('agent.qr_rerouted', [
            'actor_user_id'   => $actorId,
            'source_user_id'  => $source->id,
            'source_qr_slug'  => $source->qr_code_slug,
            'target_user_id'  => $target->id,
            'target_user_name' => $target->name,
        ]);
    }

    /**
     * Bulk reassign properties + contacts from $source to $target,
     * soft-delete calendar events + tasks owned by $source.
     *
     * Secondary handling:
     *   'promote' = where source is primary AND a different secondary exists,
     *               promote the secondary to primary and clear the secondary slot.
     *               Otherwise reassign primary to target.
     *   'replace' = always set primary to target; secondary slot unchanged
     *               (except where source itself is the secondary, that slot becomes target).
     *
     * Returns the counts that were actually changed.
     *
     * @return array{
     *     properties_primary:int,
     *     properties_secondary:int,
     *     contacts:int,
     *     calendar_events:int,
     *     command_tasks:int
     * }
     */
    public function reassignAndCleanup(User $source, User $target, string $secondaryHandling, int $actorId): array
    {
        return DB::transaction(function () use ($source, $target, $secondaryHandling, $actorId) {
            $now = now();

            $primaryChanged = 0;

            if ($secondaryHandling === 'promote') {
                // 1a. Properties where source is primary AND a different non-null secondary exists:
                //     promote the secondary to primary, clear the secondary slot.
                $promoted = DB::table('properties')
                    ->whereNull('deleted_at')
                    ->where('agent_id', $source->id)
                    ->whereNotNull('pp_second_agent_id')
                    ->where('pp_second_agent_id', '!=', $source->id)
                    ->get(['id', 'pp_second_agent_id']);

                foreach ($promoted as $row) {
                    DB::table('properties')->where('id', $row->id)->update([
                        'agent_id'           => $row->pp_second_agent_id,
                        'pp_second_agent_id' => null,
                        'updated_at'         => $now,
                    ]);
                    $primaryChanged++;
                }

                // 1b. Remaining properties where source is still primary (no secondary to promote):
                //     reassign to target.
                $primaryChanged += DB::table('properties')
                    ->whereNull('deleted_at')
                    ->where('agent_id', $source->id)
                    ->update(['agent_id' => $target->id, 'updated_at' => $now]);
            } else {
                // 'replace' — always set primary to target.
                $primaryChanged = DB::table('properties')
                    ->whereNull('deleted_at')
                    ->where('agent_id', $source->id)
                    ->update(['agent_id' => $target->id, 'updated_at' => $now]);
            }

            // 2. Properties where source is the secondary agent → set secondary to target,
            //    unless that would duplicate the existing primary (then null it instead).
            $secondaryRows = DB::table('properties')
                ->whereNull('deleted_at')
                ->where('pp_second_agent_id', $source->id)
                ->get(['id', 'agent_id']);

            $secondaryChanged = 0;
            foreach ($secondaryRows as $row) {
                $newSecondary = ((int) $row->agent_id === (int) $target->id) ? null : $target->id;
                DB::table('properties')->where('id', $row->id)->update([
                    'pp_second_agent_id' => $newSecondary,
                    'updated_at'         => $now,
                ]);
                $secondaryChanged++;
            }

            // 3. Contacts — reassign created_by_user_id.
            $contactsChanged = DB::table('contacts')
                ->whereNull('deleted_at')
                ->where('created_by_user_id', $source->id)
                ->update(['created_by_user_id' => $target->id, 'updated_at' => $now]);

            // 4. Calendar events owned by source → soft delete.
            $eventsDeleted = DB::table('calendar_events')
                ->whereNull('deleted_at')
                ->where('user_id', $source->id)
                ->update(['deleted_at' => $now, 'updated_at' => $now]);

            // 5. Command tasks assigned to source → soft delete.
            $tasksDeleted = DB::table('command_tasks')
                ->whereNull('deleted_at')
                ->where('assigned_to', $source->id)
                ->update(['deleted_at' => $now, 'updated_at' => $now]);

            $counts = [
                'properties_primary'   => $primaryChanged,
                'properties_secondary' => $secondaryChanged,
                'contacts'             => $contactsChanged,
                'calendar_events'      => $eventsDeleted,
                'command_tasks'        => $tasksDeleted,
            ];

            // No general activity_log table exists in this DB; write a structured log line.
            Log::info('agent.deleted_with_reassignment', [
                'actor_user_id'      => $actorId,
                'source_user_id'     => $source->id,
                'source_user_name'   => $source->name,
                'target_user_id'     => $target->id,
                'target_user_name'   => $target->name,
                'secondary_handling' => $secondaryHandling,
                'counts'             => $counts,
            ]);

            return $counts;
        });
    }
}
