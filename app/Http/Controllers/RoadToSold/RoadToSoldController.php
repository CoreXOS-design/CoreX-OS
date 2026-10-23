<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Services\RoadToSold\CallListService;
use App\Services\RoadToSold\CompletionListService;
use App\Services\RoadToSold\NextActionService;
use App\Services\RoadToSold\RecipeProgressService;
use App\Services\RoadToSold\TaskCaptureService;
use App\Services\RoadToSold\FeedService;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\SummaryBarService;
use App\Services\RoadToSold\Track;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\OutcomeService;
use App\Services\RoadToSold\RoadToSoldException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * AT-454 - the Road to Sold page and the "Contacted client" capture.
 * Spec: .ai/specs/road-to-sold.md section 10.5. Breadth (own / branch / agency) is enforced in the
 * query layer by the models' visibleTo(); an agent outside the viewer's scope is a 404.
 */
class RoadToSoldController extends Controller
{
    public function __construct(
        private readonly CallListService $list,
        private readonly OutcomeService $outcomes,
        private readonly OutcomeDefinitionService $definitions,
        private readonly FeedService $feeds,
        private readonly SummaryBarService $bar,
        private readonly RecipeProgressService $progress,
        private readonly NextActionService $nextAction,
        private readonly TaskCaptureService $capture,
        private readonly CompletionListService $completionList,
    ) {
    }

    public function index(Request $request)
    {
        $viewer = $request->user();
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        abort_if($agencyId <= 0, 403, 'Switch into an agency to open Road to Sold.');

        $track = Track::fromRequest($request);
        $agents = $this->list->viewableAgents($viewer);
        $agentId = (int) ($request->query('agent') ?: $viewer->ownershipUserId());
        // A platform owner switched into an agency belongs to no agency, so their own id is never one of the agency's agents: with no
        // ?agent= in the address they land on the first agent they may look at instead of a 404. A named ?agent= outside their breadth still 404s.
        if (! $request->query('agent') && ! $agents->contains('id', $agentId) && $agents->isNotEmpty()) {
            $agentId = (int) $agents->first()->id;
        }
        abort_unless($agents->contains('id', $agentId), 404);
        $viewingOwn = $agentId === (int) $viewer->ownershipUserId();

        $date = $this->list->resolveDate($request->query('date'));
        $this->buildIfMissing($viewer, $agentId, $date, $viewingOwn, $track);

        $isCaptures = $request->query('view') === 'captures';
        $listFilters = [
            'view'      => $request->query('view'),
            'status'    => $request->query('status'),
            'selection' => $request->query('selection'),
            'q'         => $request->query('q'),
            'sort'      => $request->query('sort'),
            'dir'       => $request->query('dir'),
            'date'      => $date->toDateString(),
            'page'      => (int) $request->query('page', 1),
        ];
        // The captures list (v2 phase 2) replaces the call list on the same page; it never builds the call list's rows.
        $data = $isCaptures
            ? ['rows' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, CallListService::PER_PAGE), 'selections' => [], 'capped' => false,
               'counts' => ['any' => 0, 'worked' => 0, 'target_items' => 0, 'added' => 0, 'added_worked' => 0]]
            : $this->list->page($viewer, $agentId, $listFilters, $track);

        $agentUser = \App\Models\User::query()->find($agentId);
        // The three feeds (buyers, enquiries, expiring mandates) are sales work; another track shows none.
        $feeds = ($agentUser && $track === 'sales') ? [
            'buyers'    => $this->feeds->buyerFollowUps($agentUser),
            'leads'     => $this->feeds->leadFollowUps($agentUser),
            'mandates'  => $this->feeds->expiringMandates($agentUser),
        ] : [];

        $outcomes = $this->definitions->list($agencyId, $track)->map(fn (Def $d) => [
            'id'              => $d->id,
            'label'           => $d->label,
            'kind'            => $d->kind,
            'next_action'     => $d->next_action,
            'summary'         => $d->nextActionSummary(),
            'delay_days'      => $d->delay_days,
            'needs_date'      => $d->next_action === Def::ACTION_PICKED_DATE || $d->next_action === Def::ACTION_EXPIRY_ELSE,
            'needs_appointment' => $d->next_action === Def::ACTION_APPOINTMENT,
            'offers_valuation'  => $d->kind === Def::KIND_CONVERSATION
                && ! in_array($d->next_action, [Def::ACTION_APPOINTMENT, Def::ACTION_NEVER_AGAIN], true),
        ])->values()->all();

        $progress = $agentUser ? $this->progress->forAgent($agentUser, $track) : ['started' => false, 'level' => null, 'tasks' => [], 'steps' => [], 'met' => 0, 'counted' => 0, 'points' => 0.0, 'points_label' => ''];
        $nextAction = $this->nextAction->resolve($progress, $feeds, $this->todaysOpen($viewer, $agentId, $date, $track, $isCaptures, $listFilters, $data), $viewingOwn);
        $captureTasks = $viewingOwn && $agentUser ? $this->capture->capturableTasks($agentUser, $track) : collect();
        $captures = null;
        $captureFilters = [];
        $captureChoices = ['tasks' => [], 'steps' => []];
        if ($isCaptures) {
            $captureFilters = [
                'q' => (string) $request->query('q', ''), 'sort' => $request->query('sort'), 'dir' => $request->query('dir'),
                'task' => $request->query('task'), 'step' => $request->query('step'), 'state' => $request->query('state'),
                'source' => $request->query('source'), 'status' => $request->query('status'), 'from' => $request->query('from'),
                'to' => $request->query('to'), 'page' => $request->query('page'),
            ];
            $captures = $this->completionList->page($viewer, $agentId, $captureFilters, $track);
            $captureChoices = $this->completionList->filterChoices($agencyId, $track);
        }

        return view('road-to-sold.index', $data + [
            'feeds'          => $feeds,
            'progress'       => $progress,
            'nextAction'     => $nextAction,
            'captureTasks'   => $captureTasks,
            'isCaptures'     => $isCaptures,
            'captures'       => $captures,
            'captureFilters' => $captureFilters,
            'captureChoices' => $captureChoices,
            'canManageRecipe' => $viewer->hasPermission('road_to_sold.manage_recipe'),
            'bar'            => $agentUser ? $this->bar->forAgent($agentUser, $date, $track) : ['month' => $date->format('F Y'), 'steps' => []],
            'drilldownBase'  => route('road-to-sold.drilldown', [
                'agent_id' => $agentId, 'period' => 'custom',
                'start' => $date->copy()->startOfMonth()->toDateString(), 'end' => $date->toDateString(),
            ] + Track::params($track)),
            'track'          => $track,
            'outcomeChoices' => $outcomes,
            'history'        => $this->outcomes->historyForPage($viewer, collect($data['rows']->items())->pluck('tracked_property_id')->all(), $track),
            'agents'         => $agents,
            'agentId'        => $agentId,
            'viewingOwn'     => $viewingOwn,
            'date'           => $date,
            'filters'        => [
                'view'      => $isCaptures ? 'captures' : (in_array($request->query('view'), CallListService::VIEWS, true) ? $request->query('view') : 'today'),
                'status'    => in_array($request->query('status'), CallListService::STATUSES, true) ? $request->query('status') : 'open',
                'selection' => (string) $request->query('selection', ''),
                'q'         => (string) $request->query('q', ''),
                'sort'      => in_array($request->query('sort'), CallListService::SORTS, true) ? $request->query('sort') : 'order',
                'dir'       => $request->query('dir') === 'desc' ? 'desc' : 'asc',
            ],
            'canManageOutcomes' => $viewer->hasPermission('road_to_sold.manage_settings'),
            'canAddToDay'       => $viewingOwn,
            'addMax'            => \App\Services\RoadToSold\SettingsService::forUser($viewer, $track)->addToDayMax(),
            'addChoices'        => collect(\App\Services\RoadToSold\SettingsService::forUser($viewer, $track)->selections())
                ->where('enabled', true)->pluck('label', 'key')->all(),
        ]);
    }

    /**
     * How many properties on today's list are still open and the first of them - what "do this next" calls. Taken from the page's own
     * rows when it already shows the open list in order; otherwise one more read, so the card never depends on a filter the agent set.
     *
     * @return array{open:int,first:?array}
     */
    private function todaysOpen($viewer, int $agentId, $date, string $track, bool $isCaptures, array $f, array $data): array
    {
        if ($isCaptures || ! $date->isToday()) {
            return ['open' => 0, 'first' => null];
        }
        $counts = $this->list->dayCounts($viewer, $agentId, $date, $track);
        $open = max(0, $counts['target_items'] - $counts['worked']) + max(0, $counts['added'] - $counts['added_worked']);
        if ($open === 0) {
            return ['open' => 0, 'first' => null];
        }
        $default = in_array($f['view'] ?? 'today', [null, '', 'today'], true) && in_array($f['status'] ?? 'open', [null, '', 'open'], true)
            && ($f['q'] ?? '') === '' && ($f['selection'] ?? '') === '' && in_array($f['sort'] ?? 'order', [null, '', 'order'], true) && ($f['dir'] ?? 'asc') !== 'desc';
        $rows = $default && (int) ($f['page'] ?? 1) === 1
            ? $data['rows']
            : $this->list->page($viewer, $agentId, ['view' => 'today', 'status' => 'open', 'date' => $date->toDateString(), 'page' => 1], $track)['rows'];
        $first = collect($rows->items())->firstWhere('status', 'open');

        return ['open' => $open, 'first' => $first ? ['tracked_property_id' => $first['tracked_property_id'], 'list_item_id' => $first['list_item_id'], 'address' => $first['street'] !== '' ? $first['street'] : $first['address']] : null];
    }

    /** The overnight build normally has today's list ready; if it is missing, build it when the page opens. */
    private function buildIfMissing($viewer, int $agentId, $date, bool $viewingOwn, string $track): void
    {
        if (! $viewingOwn || ! $date->isToday()) {
            return;
        }
        try {
            $has = RtsListItem::query()->visibleTo($viewer)->forDay($agentId, $date, $track)->exists();
            if (! $has) {
                app(ListBuilderService::class)->buildFor($viewer, $date, $track);
            }
        } catch (Throwable $e) {
            // Never a silently empty page: log it, and tell the agent their list could not be built.
            report($e);
            session()->now('rts_error', 'We could not build your call list just now, so this list may be empty or short. Please try again in a minute or tell your manager.');
        }
    }

    public function recordOutcome(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'tracked_property_id'   => ['required', 'integer', 'min:1'],
            'outcome_definition_id' => ['required', 'integer', 'min:1'],
            'list_item_id'          => ['nullable', 'integer', 'min:1'],
            'note'                  => ['nullable', 'string', 'max:5000'],
            'next_action_at'        => ['nullable', 'date'],
            'valuation_booked'      => ['nullable', 'boolean'],
            'appointment_at'        => ['nullable', 'date'],
        ], [
            'tracked_property_id.required'   => 'Pick the property again.',
            'outcome_definition_id.required' => 'Pick an outcome.',
            'next_action_at.date'            => 'We could not read that date. Pick it again.',
            'appointment_at.date'            => 'We could not read the evaluation date and time. Pick it again.',
            'note.max'                       => 'Keep the note under 5000 characters.',
        ]);

        $input = [
            'note'             => $data['note'] ?? null,
            'next_action_at'   => $data['next_action_at'] ?? null,
            'valuation_booked' => $request->boolean('valuation_booked'),
            'appointment_at'   => $data['appointment_at'] ?? null,
        ];

        if (! empty($data['list_item_id'])) {
            $item = RtsListItem::query()->visibleTo($user)->find($data['list_item_id']);
            abort_unless($item && (int) $item->tracked_property_id === (int) $data['tracked_property_id'], 404);
            $input['list_item_id'] = $item->id;
            $input['prospecting_listing_id'] = $item->prospecting_listing_id;
            $input['property_id'] = $item->property_id;
        }

        try {
            $outcome = $this->outcomes->record($user, (int) $data['tracked_property_id'], (int) $data['outcome_definition_id'], $input, Track::fromRequest($request));
        } catch (RoadToSoldException $e) {
            return back()->withInput()->with('rts_error', $e->getMessage())
                ->with('rts_reopen', ['tp' => (int) $data['tracked_property_id'], 'item' => $data['list_item_id'] ?? null]);
        }

        return back()->with('status', $this->doneMessage($outcome));
    }

    /** "Add to my day" - extra properties from one selection, reported apart from the target (lane 2 builds them). */
    public function addToDay(Request $request): RedirectResponse
    {
        $max = \App\Services\RoadToSold\SettingsService::forUser($request->user(), Track::fromRequest($request))->addToDayMax();
        $data = $request->validate([
            'selection' => ['required', 'string', 'max:40'],
            'count'     => ['required', 'integer', 'min:1', 'max:' . $max],
        ], [
            'selection.required' => 'Pick which selection to add from.',
            'count.required'     => "Enter how many to add (1 to {$max}).",
            'count.integer'      => "Enter how many to add as a whole number from 1 to {$max}.",
            'count.min'          => "Enter how many to add (1 to {$max}).",
            'count.max'          => "You can add up to {$max} at a time.",
        ]);

        try {
            $added = app(ListBuilderService::class)->addToDay($request->user(), $data['selection'], (int) $data['count'], null, Track::fromRequest($request));
        } catch (\InvalidArgumentException $e) {
            return back()->with('rts_error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('rts_error', 'We could not add those just now. Try again, or pick a smaller number.');
        }

        $n = is_countable($added) ? count($added) : 0;

        return back()->with('status', $n > 0 ? "Added {$n} to your day." : 'Nothing more is available from that selection right now.');
    }

    private function doneMessage($o): string
    {
        $m = 'Saved: ' . $o->outcome_label . '.';
        if ($o->dropped) {
            return $m . ' That is ' . $o->no_answer_streak . ' no-answers in a row, so it comes off your list and goes on the manager\'s report.';
        }
        if ($o->valuation_booked) {
            return $m . ' The evaluation is in your calendar.';
        }
        if ($o->next_action_at) {
            return $m . ' It comes back on ' . $o->next_action_at->format('j M Y') . '.';
        }
        if ($o->list_tag) {
            return $m . ' It moved to "' . $o->list_tag . '".';
        }

        return $o->next_action === Def::ACTION_NEVER_AGAIN ? $m . ' It will not be suggested again.' : $m;
    }
}
