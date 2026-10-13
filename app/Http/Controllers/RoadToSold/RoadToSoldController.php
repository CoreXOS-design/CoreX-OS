<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Services\RoadToSold\CallListService;
use App\Services\RoadToSold\FeedService;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\SummaryBarService;
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
    ) {
    }

    public function index(Request $request)
    {
        $viewer = $request->user();
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        abort_if($agencyId <= 0, 403, 'Switch into an agency to open Road to Sold.');

        $agents = $this->list->viewableAgents($viewer);
        $agentId = (int) ($request->query('agent') ?: $viewer->ownershipUserId());
        abort_unless($agents->contains('id', $agentId), 404);
        $viewingOwn = $agentId === (int) $viewer->ownershipUserId();

        $date = $this->list->resolveDate($request->query('date'));
        $this->buildIfMissing($viewer, $agentId, $date, $viewingOwn);

        $data = $this->list->page($viewer, $agentId, [
            'view'      => $request->query('view'),
            'status'    => $request->query('status'),
            'selection' => $request->query('selection'),
            'q'         => $request->query('q'),
            'sort'      => $request->query('sort'),
            'dir'       => $request->query('dir'),
            'date'      => $date->toDateString(),
            'page'      => (int) $request->query('page', 1),
        ]);

        $agentUser = \App\Models\User::query()->find($agentId);
        $feeds = $agentUser ? [
            'buyers'    => $this->feeds->buyerFollowUps($agentUser),
            'leads'     => $this->feeds->leadFollowUps($agentUser),
            'mandates'  => $this->feeds->expiringMandates($agentUser),
        ] : [];

        $outcomes = $this->definitions->list($agencyId)->map(fn (Def $d) => [
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

        return view('road-to-sold.index', $data + [
            'feeds'          => $feeds,
            'bar'            => $agentUser ? $this->bar->forAgent($agentUser, $date) : ['month' => $date->format('F Y'), 'steps' => []],
            'drilldownBase'  => route('road-to-sold.drilldown', [
                'agent_id' => $agentId, 'period' => 'custom',
                'start' => $date->copy()->startOfMonth()->toDateString(), 'end' => $date->toDateString(),
            ]),
            'outcomeChoices' => $outcomes,
            'agents'         => $agents,
            'agentId'        => $agentId,
            'viewingOwn'     => $viewingOwn,
            'date'           => $date,
            'filters'        => [
                'view'      => in_array($request->query('view'), CallListService::VIEWS, true) ? $request->query('view') : 'today',
                'status'    => in_array($request->query('status'), CallListService::STATUSES, true) ? $request->query('status') : 'open',
                'selection' => (string) $request->query('selection', ''),
                'q'         => (string) $request->query('q', ''),
                'sort'      => in_array($request->query('sort'), CallListService::SORTS, true) ? $request->query('sort') : 'order',
                'dir'       => $request->query('dir') === 'desc' ? 'desc' : 'asc',
            ],
            'canManageOutcomes' => $viewer->hasPermission('road_to_sold.manage_settings'),
            'canAddToDay'       => $viewingOwn,
            'addChoices'        => collect(\App\Services\RoadToSold\SettingsService::forUser($viewer)->selections())
                ->where('enabled', true)->pluck('label', 'key')->all(),
        ]);
    }

    /** The overnight build normally has today's list ready; if it is missing, build it when the page opens. */
    private function buildIfMissing($viewer, int $agentId, $date, bool $viewingOwn): void
    {
        if (! $viewingOwn || ! $date->isToday()) {
            return;
        }
        try {
            $has = RtsListItem::query()->visibleTo($viewer)->forDay($agentId, $date)->exists();
            if (! $has) {
                app(ListBuilderService::class)->buildFor($viewer, $date);
            }
        } catch (Throwable $e) {
            report($e);
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
            'appointment_at.date'            => 'We could not read the valuation date and time. Pick it again.',
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
            $outcome = $this->outcomes->record($user, (int) $data['tracked_property_id'], (int) $data['outcome_definition_id'], $input);
        } catch (RoadToSoldException $e) {
            return back()->withInput()->with('rts_error', $e->getMessage())
                ->with('rts_reopen', ['tp' => (int) $data['tracked_property_id'], 'item' => $data['list_item_id'] ?? null]);
        }

        return back()->with('status', $this->doneMessage($outcome));
    }

    /** "Add to my day" - extra properties from one selection, reported apart from the target (lane 2 builds them). */
    public function addToDay(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'selection' => ['required', 'string', 'max:40'],
            'count'     => ['required', 'integer', 'min:1', 'max:100'],
        ], [
            'selection.required' => 'Pick which selection to add from.',
            'count.required'     => 'Enter how many to add (1 to 100).',
            'count.integer'      => 'Enter how many to add as a whole number from 1 to 100.',
            'count.min'          => 'Enter how many to add (1 to 100).',
            'count.max'          => 'You can add up to 100 at a time.',
        ]);

        try {
            $added = app(ListBuilderService::class)->addToDay($request->user(), $data['selection'], (int) $data['count']);
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
            return $m . ' The valuation is in your calendar.';
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
