<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\MinionRunAreaJob;
use App\Models\MinionCaptureArea;
use App\Models\MinionCaptureRun;
use App\Models\MinionCaptureSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AT-284 — the P24 minion setup page: the province -> region -> town -> suburb tick tree
 * (the capture universe), cadence settings, master switch, run-log and per-area "run now".
 */
class MinionCaptureController extends Controller
{
    private function agencyId(Request $r): int
    {
        return (int) ($r->user()->agency_id ?? 0);
    }

    public function index(Request $request)
    {
        $agencyId = $this->agencyId($request);

        $provinces = DB::table('p24_provinces as p')
            ->join('p24_cities as c', 'c.p24_province_id', '=', 'p.id')
            ->join('p24_suburbs as s', 's.p24_city_id', '=', 'c.id')
            ->whereNull('s.deleted_at')->whereNotNull('s.p24_id')
            ->groupBy('p.id', 'p.name')
            ->orderBy('p.name')
            ->selectRaw('p.id, p.name, COUNT(s.id) as suburb_count')
            ->get();

        return view('admin.minion.setup', [
            'provinces'   => $provinces,
            'settings'    => MinionCaptureSettings::resolved($agencyId),
            'tickedCount' => MinionCaptureArea::where('agency_id', $agencyId)->count(),
            'runs'        => MinionCaptureRun::where('agency_id', $agencyId)
                ->orderByDesc('started_at')->limit(60)->get(),
        ]);
    }

    /** JSON: towns (p24 cities) in a province, grouped by region, with tick counts. */
    public function treeTowns(Request $request)
    {
        $agencyId = $this->agencyId($request);
        $rows = DB::table('p24_cities as c')
            ->join('p24_suburbs as s', 's.p24_city_id', '=', 'c.id')
            ->leftJoin('minion_capture_areas as a', function ($j) use ($agencyId) {
                $j->on('a.p24_suburb_id', '=', 's.id')->where('a.agency_id', $agencyId)->whereNull('a.deleted_at');
            })
            ->where('c.p24_province_id', (int) $request->query('province_id'))
            ->whereNull('s.deleted_at')->whereNotNull('s.p24_id')
            ->groupBy('c.id', 'c.name', 's.region')
            ->orderBy('s.region')->orderBy('c.name')
            ->selectRaw('c.id as city_id, c.name as town, s.region, COUNT(s.id) as suburb_count, COUNT(a.id) as ticked_count')
            ->get();

        return response()->json($rows);
    }

    /** JSON: suburbs in a town (city) with ticked state. */
    public function treeSuburbs(Request $request)
    {
        $agencyId = $this->agencyId($request);
        $rows = DB::table('p24_suburbs as s')
            ->leftJoin('minion_capture_areas as a', function ($j) use ($agencyId) {
                $j->on('a.p24_suburb_id', '=', 's.id')->where('a.agency_id', $agencyId)->whereNull('a.deleted_at');
            })
            ->where('s.p24_city_id', (int) $request->query('city_id'))
            ->whereNull('s.deleted_at')->whereNotNull('s.p24_id')
            ->orderBy('s.name')
            ->selectRaw('s.id, s.name, (a.id IS NOT NULL) as ticked')
            ->get();

        return response()->json($rows);
    }

    /** Incremental tick/untick of a suburb, or a whole town (expands to its suburbs). No data loss. */
    public function toggleArea(Request $request)
    {
        $agencyId = $this->agencyId($request);
        $data = $request->validate([
            'scope'  => ['required', 'in:suburb,town'],
            'id'     => ['required', 'integer'],
            'ticked' => ['required', 'boolean'],
        ]);

        $suburbIds = $data['scope'] === 'town'
            ? DB::table('p24_suburbs')->where('p24_city_id', (int) $data['id'])
                ->whereNull('deleted_at')->whereNotNull('p24_id')->pluck('id')->all()
            : [(int) $data['id']];

        if ($request->boolean('ticked')) {
            foreach ($suburbIds as $sid) {
                $row = MinionCaptureArea::withTrashed()
                    ->where('agency_id', $agencyId)->where('p24_suburb_id', $sid)->first();
                if ($row) {
                    if ($row->trashed()) {
                        $row->restore();
                    }
                } else {
                    MinionCaptureArea::create([
                        'agency_id'        => $agencyId,
                        'p24_suburb_id'    => $sid,
                        'added_by_user_id' => $request->user()->id,
                    ]);
                }
            }
        } else {
            MinionCaptureArea::where('agency_id', $agencyId)->whereIn('p24_suburb_id', $suburbIds)->delete();
        }

        return response()->json([
            'ticked_count' => MinionCaptureArea::where('agency_id', $agencyId)->count(),
            'affected'     => count($suburbIds),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $agencyId = $this->agencyId($request);
        $data = $request->validate([
            'targets_per_night' => ['required', 'integer', 'min:1', 'max:500'],
            'cycle_days'        => ['required', 'integer', 'min:1', 'max:31'],
            'run_at'            => ['required', 'date_format:H:i'],
            'run_days'          => ['nullable', 'array'],
            'pace_min_seconds'  => ['required', 'integer', 'min:2', 'max:600'],
            'pace_max_seconds'  => ['required', 'integer', 'min:2', 'max:600', 'gte:pace_min_seconds'],
        ]);

        MinionCaptureSettings::updateOrCreate(
            ['agency_id' => $agencyId],
            [
                'enabled'           => $request->boolean('enabled'),
                'targets_per_night' => (int) $data['targets_per_night'],
                'cycle_days'        => (int) $data['cycle_days'],
                'run_at'            => $data['run_at'],
                'run_days'          => $data['run_days'] ?? null,
                'pace_min_seconds'  => (int) $data['pace_min_seconds'],
                'pace_max_seconds'  => (int) $data['pace_max_seconds'],
                'alert_enabled'     => $request->boolean('alert_enabled'),
            ]
        );

        return back()->with('success', 'Minion cadence settings saved.');
    }

    /** Manual "run now" for a town or a single suburb — off the queue. */
    public function runNow(Request $request)
    {
        $agencyId = $this->agencyId($request);
        $town     = $request->input('town');
        $suburbId = $request->input('suburb_id');

        if (! $town && ! $suburbId) {
            return back()->with('error', 'Pick a town or suburb to run.');
        }

        MinionRunAreaJob::dispatch($agencyId, $town ?: null, $suburbId ? (int) $suburbId : null, $request->user()->id);

        return back()->with('success', 'Run queued — the run-log updates as capture progresses.');
    }
}
