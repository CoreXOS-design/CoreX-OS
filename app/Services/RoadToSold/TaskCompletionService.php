<?php

namespace App\Services\RoadToSold;

use App\Events\Contracts\DomainEvent;
use App\Models\Agency;
use App\Models\RoadToSold\RtsLevel;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\RoadToSold\RtsTaskCompletionRecord;
use App\Models\RoadToSold\RtsUserTaskOverride;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Support\ActingFor;
use App\Support\AppDate;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AT-454 phase 1 - the one writer of rts_task_completions (road-to-sold.md section 5.1 / 5.2 / 5.4 / 7.5).
 *
 *  - completeFromEvent(): a domain event completes every active, enabled core task of the agency that is bound to it and whose
 *    filter it satisfies - for the agent the work counts for, once.
 *  - complete(): the single place a completion row is written. Points are SNAPSHOTTED here (section 3.4): editing a task's points
 *    later changes the future only.
 *
 * Idempotency (section 5.1): the key is a hash of (user, task, period start, subject) - or of the event id when the event names no
 * record - and the column is unique, so one real record never credits the same task twice in a period, and the same event delivered
 * twice completes once, even under a race.
 *
 * A task counts only when it is in the agent's level (an agent works the tasks of their level; a task nobody placed in a level is
 * a catalogue entry, not yet part of anyone's recipe). Until agents hold a level of their own (phase 4) every agent works the
 * lowest active level, "a new agent starts at level 1".
 *
 * Reads here name the agency explicitly: this runs from listeners and jobs where the AgencyScope has no signed-in user to read.
 */
class TaskCompletionService
{
    /**
     * Complete every task this event completes. Returns how many NEW completions were written.
     * Never throws for a missing agent / recipe - only the listener's own try/catch stands between a real fault and the caller.
     */
    public function completeFromEvent(DomainEvent $event): int
    {
        $class = $event::class;
        if (! isset(RecipeCatalogue::EVENTS[$class])) {
            return 0;
        }
        $agencyId = (int) ($event->agencyId() ?: 0);
        if ($agencyId <= 0) {
            return 0;
        }

        $tasks = RtsTask::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('type', RtsTask::TYPE_CORE)
            ->where('event_key', $class)
            ->where('is_active', true)
            ->whereIn('step_id', RtsStep::query()->withoutGlobalScope(AgencyScope::class)
                ->where('agency_id', $agencyId)->where('is_enabled', true)->select('id'))
            ->get();
        if ($tasks->isEmpty()) {
            return 0;
        }

        $people = $this->peopleFor($event, $agencyId);
        if ($people === null) {
            return 0;
        }
        [$agent, $recordedBy, $kind] = $people;

        $subject    = $event->subject();
        $written    = 0;
        $occurredAt = isset($event->occurredAt) ? Carbon::instance($event->occurredAt) : now();

        foreach ($tasks as $task) {
            if (! RecipeCatalogue::matches($event, $task->event_filter)) {
                continue;
            }
            $completion = $this->complete($task, $agent, $subject, [
                'recorded_by' => $recordedBy,
                'kind'        => $kind,
                'occurred_at' => $occurredAt,
                'event_id'    => $event->eventId ?? null,
                'source'      => RtsTaskCompletion::SOURCE_AUTO_EVENT,
            ]);
            if ($completion !== null) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * Write one completion of $task for $user (the list owner).
     *
     * @param  array{0:string,1:int|string}|null $subject  [class, id] of the real record this is about
     * @param  array{recorded_by?:?User,kind?:string,occurred_at?:?CarbonInterface,event_id?:?string,source?:string,quantity?:int,evidence_note?:?string,evidence?:?array,token?:?string,records?:array<int,array{0:string,1:int}>} $context
     *   token   one submission's own id (a captured task): the same submission pressed twice completes once. Stands in for the event id
     *           when nothing names a record.
     *   records every real record the completion produced, [class, id] each (the subject is always one of them)
     * @return RtsTaskCompletion|null null = already completed for this record in this period, or the task is not in the agent's recipe
     */
    public function complete(RtsTask $task, User $user, ?array $subject = null, array $context = []): ?RtsTaskCompletion
    {
        $agencyId = (int) $task->agency_id;
        if ($agencyId <= 0 || (int) $user->agency_id !== $agencyId) {
            return null;
        }

        $level = $this->levelFor($agencyId, (string) $task->track);
        if (! $level) {
            return null;
        }
        $levelTask = RtsLevelTask::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('level_id', $level->id)->where('task_id', $task->id)->first();
        if (! $levelTask) {
            return null;
        }

        $quantity   = max(1, (int) ($context['quantity'] ?? 1));
        $occurredAt = AppDate::store(($context['occurred_at'] ?? null) ? Carbon::instance($context['occurred_at']) : now());
        $periodUnit = array_key_exists((string) $task->recurrence_unit, RtsTask::UNITS) ? (string) $task->recurrence_unit : 'day';
        $periodStart = $this->periodStart($agencyId, $periodUnit, $occurredAt);
        $localDay    = $this->localDay($agencyId, $occurredAt);

        $subjectType = $subject[0] ?? null;
        $subjectId   = isset($subject[1]) ? (int) $subject[1] : null;
        $eventId     = $context['event_id'] ?? null;
        $token       = $context['token'] ?? null;

        // A captured task is told apart by its submission, never by the record alone: two drives in one week are two completions.
        $key = $token !== null
            ? self::submissionKey((int) $user->id, (int) $task->id, $token)
            : $this->idempotencyKey((int) $user->id, (int) $task->id, $periodStart, $subjectType, $subjectId, $eventId);
        if (RtsTaskCompletion::query()->withoutGlobalScopes()->withTrashed()->where('idempotency_key', $key)->exists()) {
            return null;
        }

        $recordedBy = $context['recorded_by'] ?? null;
        $recordedBy = $recordedBy instanceof User ? $recordedBy : $user;
        $kind       = $context['kind'] ?? ((int) $recordedBy->id === (int) $user->id ? RtsTaskCompletion::KIND_AGENT : RtsTaskCompletion::KIND_ASSISTANT);
        $points     = $this->pointsFor($agencyId, $user, $task, $level, $levelTask);
        // Confirmed unless the caller holds it back for a manager (a captured task in an agency that asks for review).
        $state      = ($context['state'] ?? null) === RtsTaskCompletion::STATE_PROVISIONAL ? RtsTaskCompletion::STATE_PROVISIONAL : RtsTaskCompletion::STATE_CONFIRMED;

        try {
            return DB::transaction(function () use ($agencyId, $user, $recordedBy, $kind, $state, $task, $level, $occurredAt, $periodUnit, $periodStart, $localDay, $quantity, $points, $subjectType, $subjectId, $eventId, $key, $context) {
                $completion = RtsTaskCompletion::withoutAgencyStamping(fn () => RtsTaskCompletion::create([
                    'agency_id'           => $agencyId,
                    'branch_id'           => $user->branch_id,
                    'user_id'             => $user->id,
                    'recorded_by_user_id' => $recordedBy->id,
                    'recorded_by_kind'    => $kind,
                    'track'               => $task->track,
                    'task_id'             => $task->id,
                    'step_id'             => $task->step_id,
                    'level_id'            => $level->id,
                    'occurred_at'         => $occurredAt,
                    'period_unit'         => $periodUnit,
                    'period_start'        => $periodStart,
                    'activity_date'       => $localDay,
                    'period'              => substr($localDay, 0, 7),
                    'quantity'            => $quantity,
                    'points_awarded'      => round($points * $quantity, 2),
                    'state'               => $state,
                    'source'              => $context['source'] ?? RtsTaskCompletion::SOURCE_AUTO_EVENT,
                    'subject_type'        => $subjectType,
                    'subject_id'          => $subjectId,
                    'event_id'            => $eventId,
                    'idempotency_key'     => $key,
                    'evidence_note'       => $context['evidence_note'] ?? null,
                    'evidence'            => $context['evidence'] ?? null,
                    'confirmed_at'        => $state === RtsTaskCompletion::STATE_CONFIRMED ? now() : null,
                ]));

                $records = $context['records'] ?? [];
                if ($subjectType !== null && $subjectId !== null) {
                    array_unshift($records, [$subjectType, $subjectId]);
                }
                $seen = [];
                foreach ($records as [$recordType, $recordId]) {
                    $id = (int) $recordId;
                    if ($id <= 0 || isset($seen[$recordType . ':' . $id])) {
                        continue;
                    }
                    $seen[$recordType . ':' . $id] = true;
                    RtsTaskCompletionRecord::withoutAgencyStamping(fn () => RtsTaskCompletionRecord::create([
                        'agency_id'     => $agencyId,
                        'completion_id' => $completion->id,
                        'record_type'   => $recordType,
                        'record_id'     => $id,
                    ]));
                }

                return $completion;
            });
        } catch (QueryException $e) {
            // Two deliveries of the same event raced past the exists() check above; the unique key let exactly one through.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $e;
        }
    }

    public static function submissionKey(int $userId, int $taskId, string $token): string
    {
        return sha1($userId . '|' . $taskId . '|submission:' . $token);
    }

    /** Has this exact submission (one press of Save on a capture form) already been saved? Archived completions count: it must not come back. */
    public function hasSubmission(int $userId, int $taskId, string $token): bool
    {
        return RtsTaskCompletion::query()->withoutGlobalScopes()->withTrashed()
            ->where('idempotency_key', self::submissionKey($userId, $taskId, $token))->exists();
    }

    /** The completions $viewer may see - own / branch / agency breadth (road_to_sold.view) applied in the query. */
    public function visibleTo(User $viewer, ?string $requestedScope = null): Builder
    {
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);

        return RtsTaskCompletion::query()
            ->where('agency_id', $agencyId)
            ->visibleTo($viewer, $requestedScope);
    }

    /** One completion by id, only when it is inside $viewer's breadth - otherwise 404 (direct-URL access is blocked, not just unlinked). */
    public function findVisible(User $viewer, int $id): RtsTaskCompletion
    {
        return $this->visibleTo($viewer)->whereKey($id)->firstOrFail();
    }

    /** The level an agent works at. Phase 4 swaps this for the agent's own level record. */
    public function levelFor(int $agencyId, string $track): ?RtsLevel
    {
        return RtsLevel::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('track', $track)->where('is_active', true)
            ->orderBy('number')->first();
    }

    /** Points for ONE completion: the agent's own override (this level's, else any level's), else the level's value. */
    public function pointsFor(int $agencyId, User $user, RtsTask $task, RtsLevel $level, RtsLevelTask $levelTask): float
    {
        $override = RtsUserTaskOverride::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('user_id', $user->id)->where('task_id', $task->id)
            ->whereIn('level_key', [(int) $level->id, 0])->whereNotNull('points')
            ->orderByDesc('level_key')->first();

        return (float) ($override?->points ?? $levelTask->points);
    }

    /** The agency-local calendar day (Y-m-d) of a moment - the day a credit belongs to (the old activity_date). */
    public function localDay(int $agencyId, CarbonInterface $at): string
    {
        static $tz = [];
        $tz[$agencyId] ??= AppDate::agencyTimezone(Agency::query()->withoutGlobalScopes()->find($agencyId));

        return Carbon::instance($at)->setTimezone($tz[$agencyId])->toDateString();
    }

    /** Start of the calendar-aligned period (agency timezone, weeks start Monday), stored in the app timezone. */
    public function periodStart(int $agencyId, string $unit, CarbonInterface $at): Carbon
    {
        $tz    = AppDate::agencyTimezone(Agency::query()->withoutGlobalScopes()->find($agencyId));
        $local = Carbon::instance($at)->setTimezone($tz);
        $start = match ($unit) {
            'hour'  => $local->copy()->startOfHour(),
            'week'  => $local->copy()->startOfWeek(Carbon::MONDAY),
            'month' => $local->copy()->startOfMonth(),
            default => $local->copy()->startOfDay(),
        };

        return AppDate::store($start);
    }

    private function idempotencyKey(int $userId, int $taskId, CarbonInterface $periodStart, ?string $subjectType, ?int $subjectId, ?string $eventId): string
    {
        // An event that names no record can only be told apart by the event itself.
        $subjectPart = $subjectType !== null && $subjectId !== null ? $subjectType . ':' . $subjectId : 'event:' . ($eventId ?? '');

        return sha1($userId . '|' . $taskId . '|' . $periodStart->format('Y-m-d H:i:s') . '|' . $subjectPart);
    }

    /**
     * Who the work counts for and who did it. An assistant's work counts under their agent (Johan, 20 Oct 2026, answer 4) and the
     * row records the assistant as the one who did it. Returns null when nobody can be credited: no agent, an agent from another
     * agency, an inactive or assistant account, or an agency that has Road to Sold switched off.
     *
     * @return array{0:User,1:User,2:string}|null [agent credited, who did it, agent|assistant|system]
     */
    private function peopleFor(DomainEvent $event, int $agencyId): ?array
    {
        $meta    = RecipeCatalogue::EVENTS[$event::class] ?? ['credit' => [], 'recorded_by' => []];
        $actorId = $event->actorUserId();

        $creditId = RecipeCatalogue::firstId($event, $meta['credit'] ?? [])
            ?? ($actorId ? (ActingFor::onBehalfOfUserId() ?? $actorId) : null);
        if (! $creditId) {
            return null;
        }
        $recordedId = RecipeCatalogue::firstId($event, $meta['recorded_by'] ?? []) ?? $actorId ?? $creditId;

        $agent = User::query()->withoutGlobalScopes()->find($creditId);
        if (! $agent || $agent->trashed() || (int) $agent->agency_id !== $agencyId || ! $agent->is_active || $agent->is_assistant) {
            return null;
        }

        $agency = Agency::query()->withoutGlobalScopes()->find($agencyId);
        if (! $agency || ! app(AgencyFeatureService::class)->enabled('road-to-sold', $agency)) {
            return null;
        }

        $recorded = (int) $recordedId === (int) $agent->id ? $agent : (User::query()->withoutGlobalScopes()->find($recordedId) ?? $agent);
        $kind     = $actorId === null && RecipeCatalogue::firstId($event, $meta['recorded_by'] ?? []) === null
            ? RtsTaskCompletion::KIND_SYSTEM
            : ((int) $recorded->id !== (int) $agent->id && $recorded->is_assistant ? RtsTaskCompletion::KIND_ASSISTANT : RtsTaskCompletion::KIND_AGENT);

        return [$agent, $recorded, $kind];
    }
}
