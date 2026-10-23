<?php

namespace App\Services\RoadToSold;

use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * AT-454 v2 phase 2 - the agent's list of task completions: what was done, when, for how many points (road-to-sold.md section 10.4,
 * "My task completions"). Read only. Own / branch / agency breadth is applied in the query by TaskCompletionService::visibleTo(),
 * and the agent must be one the viewer may see.
 *
 *   search   task name, note
 *   sort     when (newest first - the default), task, points
 *   filters  task, step, state, how it was made, archived or not, date range
 *   page     25 a page
 */
class CompletionListService
{
    public const PER_PAGE = 25;

    public const SORTS = ['when' => 'When', 'task' => 'Task', 'points' => 'Points'];

    public const STATUSES = ['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'];

    public const SOURCES = [
        RtsTaskCompletion::SOURCE_AUTO_EVENT => 'Automatic',
        RtsTaskCompletion::SOURCE_CAPTURED   => 'Captured',
        RtsTaskCompletion::SOURCE_MANUAL     => 'Added by hand',
        RtsTaskCompletion::SOURCE_MIGRATED   => 'Earlier system',
    ];

    public const STATES = [
        RtsTaskCompletion::STATE_PROVISIONAL => 'Waiting for review',
        RtsTaskCompletion::STATE_CONFIRMED   => 'Confirmed',
        RtsTaskCompletion::STATE_REVOKED     => 'Revoked',
        RtsTaskCompletion::STATE_OVERRIDDEN  => 'Changed by a manager',
    ];

    public function __construct(private readonly TaskCompletionService $engine)
    {
    }

    /**
     * @param array{q?:?string,sort?:?string,dir?:?string,task?:mixed,step?:mixed,state?:?string,source?:?string,status?:?string,from?:?string,to?:?string,page?:mixed} $f
     */
    public function page(User $viewer, int $agentId, array $f, string $track): LengthAwarePaginator
    {
        $status = in_array($f['status'] ?? 'active', array_keys(self::STATUSES), true) ? $f['status'] : 'active';
        $sort   = array_key_exists($f['sort'] ?? 'when', self::SORTS) ? ($f['sort'] ?? 'when') : 'when';
        $dir    = ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $q = $this->engine->visibleTo($viewer)
            ->where('rts_task_completions.user_id', $agentId)->where('rts_task_completions.track', $track)
            ->with(['task', 'step', 'recordedBy:id,name', 'records']);

        if ($status === 'archived') {
            $q->onlyTrashed();
        } elseif ($status === 'all') {
            $q->withTrashed();
        }
        if (($task = (int) ($f['task'] ?? 0)) > 0) {
            $q->where('rts_task_completions.task_id', $task);
        }
        if (($step = (int) ($f['step'] ?? 0)) > 0) {
            $q->where('rts_task_completions.step_id', $step);
        }
        if (array_key_exists($f['state'] ?? '', self::STATES)) {
            $q->where('rts_task_completions.state', $f['state']);
        }
        if (array_key_exists($f['source'] ?? '', self::SOURCES)) {
            $q->where('rts_task_completions.source', $f['source']);
        }
        if ($from = $this->day($f['from'] ?? null)) {
            $q->where('rts_task_completions.occurred_at', '>=', $from->startOfDay()->format('Y-m-d H:i:s'));
        }
        if ($to = $this->day($f['to'] ?? null)) {
            $q->where('rts_task_completions.occurred_at', '<=', $to->endOfDay()->format('Y-m-d H:i:s'));
        }
        if (($term = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $taskIds = RtsTask::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
                ->where('agency_id', $viewer->effectiveAgencyId())->where('label', 'like', $like)->select('id');
            $q->where(fn ($w) => $w->whereIn('rts_task_completions.task_id', $taskIds)->orWhere('rts_task_completions.evidence_note', 'like', $like));
        }

        match ($sort) {
            'task'   => $q->orderBy(RtsTask::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()->select('label')
                ->whereColumn('rts_tasks.id', 'rts_task_completions.task_id')->limit(1), $dir),
            'points' => $q->orderBy('rts_task_completions.points_awarded', $dir),
            default  => $q->orderBy('rts_task_completions.occurred_at', $dir),
        };
        $q->orderByDesc('rts_task_completions.id');

        return $q->paginate(self::PER_PAGE, ['*'], 'page', max(1, (int) ($f['page'] ?? 1)))->withQueryString();
    }

    /**
     * What each completion on the page produced, as links: completion id => [[label, url], ...]. One read per record type for the whole page.
     *
     * @return array<int,array<int,array{0:string,1:?string}>>
     */
    public function recordLinks(LengthAwarePaginator $page): array
    {
        $byType = [];
        foreach ($page->items() as $c) {
            foreach ($c->records as $r) {
                $byType[$r->record_type][] = (int) $r->record_id;
            }
        }
        $labels = [];
        if (! empty($byType[TrackedProperty::class])) {
            foreach (TrackedProperty::query()->whereIn('id', array_unique($byType[TrackedProperty::class]))->get() as $tp) {
                $labels[TrackedProperty::class][$tp->id] = [
                    trim((string) ($tp->display_address ?? '')) ?: trim(implode(' ', array_filter([$tp->street_number, $tp->street_name, $tp->suburb]))) ?: 'Property #' . $tp->id,
                    route('corex.tracked-properties.show', $tp),
                ];
            }
        }
        if (! empty($byType[Contact::class])) {
            foreach (Contact::query()->whereIn('id', array_unique($byType[Contact::class]))->get() as $c) {
                $labels[Contact::class][$c->id] = [trim($c->first_name . ' ' . $c->last_name) ?: 'Contact #' . $c->id, route('corex.contacts.show', $c)];
            }
        }
        $out = [];
        foreach ($page->items() as $c) {
            foreach ($c->records as $r) {
                if (isset($labels[$r->record_type][$r->record_id])) {
                    $out[$c->id][] = $labels[$r->record_type][$r->record_id];
                }
            }
        }

        return $out;
    }

    /** The tasks and steps the filters offer: those the agency has, archived ones included (their completions still exist). */
    public function filterChoices(int $agencyId, string $track): array
    {
        return [
            'tasks' => RtsTask::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
                ->where('agency_id', $agencyId)->where('track', $track)->orderBy('label')->pluck('label', 'id')->all(),
            'steps' => RtsStep::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
                ->where('agency_id', $agencyId)->where('track', $track)->orderBy('position')->pluck('label', 'id')->all(),
        ];
    }

    private function day(mixed $value): ?CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
