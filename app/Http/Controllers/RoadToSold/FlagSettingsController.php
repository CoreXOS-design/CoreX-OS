<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsTrainingPointer;
use App\Services\RoadToSold\FunnelService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\Support\Routing;
use App\Services\RoadToSold\TrainingPointerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-454 v2 phase 5 - Road to Sold settings -> Training & flags (road-to-sold.md sections 9.3, 13.1): the flag and suggestion thresholds
 * (saved through the General tab's own writer, so there is one audit) and the training the agency points at for each funnel ratio.
 * Permission `road_to_sold.manage_settings` on every route. Archive and restore only.
 */
class FlagSettingsController extends Controller
{
    public function __construct(private readonly TrainingPointerService $pointers)
    {
    }

    public function index(Request $request): View
    {
        $viewer = $request->user();
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        abort_if($agencyId <= 0, 403, 'Switch into an agency first.');
        $track = Routing::track();
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $show = in_array($request->query('show'), ['chosen', 'none', 'archived'], true) ? $request->query('show') : 'all';

        $live = $this->pointers->forAgency($agencyId, $track);
        $archived = $this->pointers->forAgency($agencyId, $track, true)->filter(fn ($p) => $p->trashed());
        $rows = [];
        foreach (FunnelService::RATIOS as $key => [$label, , , , $skill]) {
            if ($q !== '' && ! str_contains(mb_strtolower($label . ' ' . $skill), $q)) {
                continue;
            }
            $pointer = $live->get($key);
            $old = $archived->get($key);
            if (($show === 'chosen' && ! $pointer) || ($show === 'none' && $pointer) || ($show === 'archived' && ! $old)) {
                continue;
            }
            $rows[] = ['key' => $key, 'label' => $label, 'skill' => $skill, 'pointer' => $pointer, 'available' => $pointer ? $this->pointers->resolve($pointer) !== null : null, 'archived' => $old];
        }

        return view('road-to-sold.settings.flags', [
            'tabs' => SettingsController::tabsFor($viewer, 'flags'), 'settings' => SettingsService::forAgency($agencyId, $track), 'rows' => $rows,
            'choices' => $this->pointers->choices($viewer), 'filters' => ['q' => $request->query('q', ''), 'show' => $show], 'track' => $track,
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate(['ratio_key' => ['required', 'string'], 'kind' => ['required', 'string'], 'target' => ['required', 'string']]);
        $this->pointers->save($request->user(), Routing::track(), $data['ratio_key'], $data['kind'], $data['target']);

        return redirect()->route(Routing::base() . 'flags.index')->with('success', 'Training saved.');
    }

    public function archive(Request $request, string $ratio): RedirectResponse
    {
        abort_unless($this->pointers->archive($request->user(), Routing::track(), $ratio), 404);

        return redirect()->route(Routing::base() . 'flags.index')->with('success', 'Training removed for that skill. You can bring it back from the Archived list.');
    }

    public function restore(Request $request, string $ratio): RedirectResponse
    {
        abort_unless($this->pointers->restore($request->user(), Routing::track(), $ratio), 404);

        return redirect()->route(Routing::base() . 'flags.index')->with('success', 'Training restored.');
    }
}
