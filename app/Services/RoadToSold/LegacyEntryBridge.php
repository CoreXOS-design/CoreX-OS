<?php

namespace App\Services\RoadToSold;

use App\Models\DailyActivityEntry;
use App\Models\RoadToSold\RtsLevel;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\RoadToSold\RtsTaskCompletionRecord;
use App\Models\Scopes\AgencyScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AT-454 v2 phase 3 - keeps the task completions complete for an agency that has been cut over (road-to-sold.md section 7).
 *
 * The old detectors (a captured contact, a calendar event, a signed mandate, a deal ...) still decide WHEN a credit is earned and still write
 * a daily_activity_entries row. For an agency that has been cut over, every create, state change (confirm / revoke / override), value
 * change and removal of such a row is mirrored here into exactly one completion (keyed by the entry's id), so the points the agency reads
 * - from the completions - never miss a credit and never count one twice. Nothing else in the old engine changes.
 *
 *  - a new credit: points = how many x the task's points in the agent's level (else the activity's weight), snapshotted
 *  - a changed credit: state, dates, and - when the count changed (a manager override) - the points at the SAME per-time rate the
 *    completion was written with, so editing a task's points later changes the future only
 *  - an agency that has not been cut over: nothing happens
 * Never throws to the caller: a failure is logged and the old row (still the detector's own record) stands.
 */
class LegacyEntryBridge
{
    public function sync(DailyActivityEntry $entry): void
    {
        try {
            $agencyId = (int) $entry->agency_id;
            if ($agencyId <= 0 || ! ActivityPointsService::usesCompletions($agencyId)) {
                return;
            }
            $task = $this->taskFor($agencyId, (int) $entry->activity_definition_id)
                ?? app(DailyActivityMigrationService::class)->taskForNewActivity($agencyId, (int) $entry->activity_definition_id);
            if (! $task) {
                Log::warning('Road to Sold bridge: no task for an activity credit (run rts:migrate-daily-activity)', ['entry_id' => $entry->id, 'definition_id' => $entry->activity_definition_id]);

                return;
            }
            $existing = RtsTaskCompletion::query()->withoutGlobalScopes()->withTrashed()->where('legacy_entry_id', $entry->id)->first();
            $quantity = max(0, (int) $entry->value);

            if ($existing) {
                $unit = (int) $existing->quantity > 0 ? (float) $existing->points_awarded / (int) $existing->quantity : $this->unitPoints($agencyId, $task, $entry);
                $existing->forceFill([
                    'state'          => $entry->point_state,
                    'quantity'       => $quantity,
                    'points_awarded' => round($quantity * $unit, 2),
                    'confirmed_at'   => $entry->confirmed_at,
                    'revoked_at'     => $entry->revoked_at,
                    'revoke_reason'  => $entry->revoke_reason !== null ? mb_substr((string) $entry->revoke_reason, 0, 255) : null,
                    'override_audit' => $entry->override_audit_json,
                    'activity_date'  => $entry->activity_date?->toDateString(),
                    'period'         => $entry->period,
                ])->save();

                return;
            }

            $this->create($agencyId, $task, $entry, $quantity);
        } catch (\Throwable $e) {
            Log::error('Road to Sold bridge failed (the credit stands in daily_activity_entries)', ['entry_id' => $entry->id ?? null, 'error' => $e->getMessage()]);
        }
    }

    /** An entry removed: its completion is archived (soft delete), never hard deleted. */
    public function remove(DailyActivityEntry $entry): void
    {
        try {
            RtsTaskCompletion::query()->withoutGlobalScopes()->where('legacy_entry_id', $entry->id)->get()->each->delete();
        } catch (\Throwable $e) {
            Log::error('Road to Sold bridge failed to archive a completion', ['entry_id' => $entry->id ?? null, 'error' => $e->getMessage()]);
        }
    }

    private function create(int $agencyId, RtsTask $task, DailyActivityEntry $entry, int $quantity): void
    {
        $level = RtsLevel::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('track', $task->track)->where('number', 1)->first();
        $date = $entry->activity_date instanceof Carbon ? $entry->activity_date->toDateString() : (string) $entry->activity_date;
        $unit = $this->unitPoints($agencyId, $task, $entry);
        $manual = $entry->source === DailyActivityEntry::SOURCE_MANUAL;

        DB::transaction(function () use ($agencyId, $task, $entry, $quantity, $level, $date, $unit, $manual) {
            $c = RtsTaskCompletion::withoutAgencyStamping(fn () => RtsTaskCompletion::create([
                'agency_id' => $agencyId, 'branch_id' => $entry->branch_id, 'user_id' => $entry->user_id,
                'recorded_by_user_id' => $entry->user_id, 'recorded_by_kind' => $manual ? RtsTaskCompletion::KIND_AGENT : RtsTaskCompletion::KIND_SYSTEM,
                'track' => $task->track, 'task_id' => $task->id, 'step_id' => $task->step_id, 'level_id' => $level?->id,
                'occurred_at' => $entry->created_at ?? Carbon::parse($date), 'period_unit' => 'day', 'period_start' => Carbon::parse($date)->startOfDay(),
                'activity_date' => $date, 'period' => $entry->period ?: substr($date, 0, 7), 'quantity' => $quantity,
                'points_awarded' => round($quantity * $unit, 2), 'state' => $entry->point_state,
                'source' => $manual ? RtsTaskCompletion::SOURCE_MANUAL : RtsTaskCompletion::SOURCE_AUTO_EVENT,
                'legacy_source' => $entry->source, 'legacy_entry_id' => $entry->id,
                'subject_type' => $entry->subject_type, 'subject_id' => $entry->subject_id,
                'idempotency_key' => sha1('legacy-entry:' . $entry->id),
                'evidence' => $entry->calendar_event_id ? ['calendar_event_id' => (int) $entry->calendar_event_id] : null,
                'confirmed_at' => $entry->confirmed_at, 'revoked_at' => $entry->revoked_at,
                'revoke_reason' => $entry->revoke_reason !== null ? mb_substr((string) $entry->revoke_reason, 0, 255) : null,
                'override_audit' => $entry->override_audit_json,
            ]));
            if ($entry->subject_type && $entry->subject_id) {
                RtsTaskCompletionRecord::withoutAgencyStamping(fn () => RtsTaskCompletionRecord::firstOrCreate(
                    ['completion_id' => $c->id, 'record_type' => $entry->subject_type, 'record_id' => (int) $entry->subject_id],
                    ['agency_id' => $agencyId]
                ));
            }
        });
    }

    /** The task that stands for this activity in the agency (restored if it was archived - a credit must never be lost to an archive). */
    private function taskFor(int $agencyId, int $definitionId): ?RtsTask
    {
        $task = RtsTask::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
            ->where('agency_id', $agencyId)->where('legacy_activity_definition_id', $definitionId)->first();

        return $task;
    }

    /** Points for ONE time: the task's points in Level 1 (the level every agent works until levels per agent exist), else the activity's weight. */
    private function unitPoints(int $agencyId, RtsTask $task, DailyActivityEntry $entry): float
    {
        $lt = RtsLevelTask::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('task_id', $task->id)->orderBy('level_id')->first();
        if ($lt) {
            return (float) $lt->points;
        }

        return (float) DB::table('activity_definitions')->where('id', $entry->activity_definition_id)->value('weight');
    }
}
