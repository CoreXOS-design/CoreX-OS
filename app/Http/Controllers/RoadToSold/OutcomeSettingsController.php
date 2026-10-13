<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Services\RoadToSold\OutcomeDefinitionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * AT-454 - the agency's Road to Sold outcome list (rename, add, switch off, re-time, reorder,
 * archive, restore). Spec: .ai/specs/road-to-sold.md §9.4. Agency-wide settings: the scope is the
 * agency (AgencyScope on the model; route binding answers 404 for another agency's row), gated by
 * road_to_sold.manage_settings.
 */
class OutcomeSettingsController extends Controller
{
    public function __construct(private readonly OutcomeDefinitionService $service)
    {
    }

    private function agencyId(Request $request): int
    {
        $id = (int) ($request->user()->effectiveAgencyId() ?: 0);
        abort_if($id <= 0, 403, 'Switch into an agency to change its Road to Sold outcomes.');

        return $id;
    }

    public function index(Request $request)
    {
        $agencyId = $this->agencyId($request);
        $this->service->ensureDefaults($agencyId);

        $status = in_array($request->query('status'), ['active', 'off', 'archived', 'all'], true) ? $request->query('status') : 'all';
        $q = trim((string) $request->query('q', ''));
        $sort = in_array($request->query('sort'), ['order', 'name', 'kind'], true) ? $request->query('sort') : 'order';

        $query = $status === 'archived' || $status === 'all' ? Def::withTrashed() : Def::query();
        $query->where('agency_id', $agencyId)->where('track', 'sales');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'off') {
            $query->where('is_active', false);
        } elseif ($status === 'archived') {
            $query->onlyTrashed();
        }
        if ($q !== '') {
            $query->where('label', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%');
        }
        match ($sort) {
            'name'  => $query->orderBy('label'),
            'kind'  => $query->orderBy('kind')->orderBy('sort_order'),
            default => $query->orderBy('sort_order')->orderBy('id'),
        };

        return view('road-to-sold.settings.outcomes', [
            'outcomes' => $query->paginate(50)->withQueryString(),
            'status'   => $status,
            'q'        => $q,
            'sort'     => $sort,
            'kinds'    => Def::KINDS,
            'actions'  => $this->actionLabels(),
            'canReorder' => $sort === 'order' && $status !== 'archived' && $q === '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $agencyId = $this->agencyId($request);
        try {
            $def = $this->service->save($agencyId, $request->user()->id, $this->input($request));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors(), 'outcome')->withInput()->with('rts_open_add', true);
        }

        return redirect()->route('road-to-sold.settings.outcomes.index')->with('status', "Added the outcome \"{$def->label}\".");
    }

    public function update(Request $request, Def $definition): RedirectResponse
    {
        $this->agencyId($request);
        try {
            $this->service->save((int) $definition->agency_id, $request->user()->id, $this->input($request), $definition);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors(), 'outcome')->withInput()->with('rts_open_edit', $definition->id);
        }

        return redirect()->route('road-to-sold.settings.outcomes.index')->with('status', "Saved \"{$definition->label}\".");
    }

    public function toggle(Request $request, Def $definition): RedirectResponse
    {
        $this->agencyId($request);
        $this->service->setActive($definition, ! $definition->is_active, $request->user()->id);

        return back()->with('status', "\"{$definition->label}\" is now " . ($definition->is_active ? 'on' : 'off') . '.');
    }

    public function move(Request $request, Def $definition): RedirectResponse
    {
        $agencyId = $this->agencyId($request);
        $dir = $request->input('dir') === 'up' ? 'up' : 'down';
        $ids = Def::query()->where('agency_id', $agencyId)->where('track', $definition->track)
            ->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
        $pos = array_search($definition->id, $ids, true);
        if ($pos !== false) {
            $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
            if (isset($ids[$swap])) {
                [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
                $this->service->reorder($agencyId, $ids, $definition->track);
            }
        }

        return back();
    }

    public function archive(Request $request, Def $definition): RedirectResponse
    {
        $this->agencyId($request);
        $this->service->archive($definition, $request->user()->id);

        return back()->with('status', "Archived \"{$definition->label}\". Calls already logged with it keep their wording.");
    }

    public function restore(Request $request, Def $definition): RedirectResponse
    {
        $this->agencyId($request);
        $this->service->restore($definition, $request->user()->id);

        return back()->with('status', "Restored \"{$definition->label}\".");
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        return [
            'label'                  => $request->input('label'),
            'kind'                   => $request->input('kind'),
            'next_action'            => $request->input('next_action'),
            'delay_days'             => $request->input('delay_days'),
            'list_tag'               => $request->input('list_tag'),
            'counts_as_no_answer'    => $request->boolean('counts_as_no_answer'),
            'records_do_not_contact' => $request->boolean('records_do_not_contact'),
            'is_active'              => $request->has('is_active') ? $request->boolean('is_active') : true,
        ];
    }

    /** @return array<string,string> */
    private function actionLabels(): array
    {
        return [
            Def::ACTION_AFTER_DAYS   => 'Comes back after a number of days',
            Def::ACTION_PICKED_DATE  => 'Comes back on a date the agent picks',
            Def::ACTION_EXPIRY_ELSE  => 'Comes back on the mandate end date (agent picks if unknown)',
            Def::ACTION_MOVE_TO_LIST => 'Moves to a named list',
            Def::ACTION_NEVER_AGAIN  => 'Never suggested again',
            Def::ACTION_APPOINTMENT  => 'Becomes a calendar appointment',
        ];
    }
}
