<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Services\Performance\PeriodComparison;
use App\Services\Performance\PeriodResolver;
use App\Services\RoadToSold\BeltFunnelService;
use App\Services\RoadToSold\Track;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Road to Sold v4 (road-to-sold.md 23.12): the belt funnel and where properties are lost. Anyone with Road to Sold sees their own funnel next to the branch's and the
 * agency's; a branch manager also sees every agent of the branch, an admin every agent (the breadth is the query layer's: BeltFunnelService / VisibleAgents).
 * Periods: today, last 7 days, this month, last 30 days, a custom range - and optionally a second period to compare against.
 */
class BeltFunnelController extends Controller
{
    public const PERIODS = ['today' => 'Today', 'last_7_days' => 'Last 7 days', 'this_month' => 'This month', 'last_30_days' => 'Last 30 days', 'custom' => 'Pick dates'];
    public const COMPARES = ['off' => 'No comparison', 'previous' => 'The period before', 'same_last_year' => 'Same period last year', 'custom' => 'Pick dates'];

    public function __construct(private readonly BeltFunnelService $funnel, private readonly PeriodResolver $periods)
    {
    }

    public function index(Request $request): View
    {
        $viewer = $request->user();
        abort_if((int) ($viewer->effectiveAgencyId() ?: 0) <= 0, 403, 'Switch into an agency first.');
        $track = Track::fromRequest($request);
        $preset = array_key_exists($request->query('period'), self::PERIODS) ? $request->query('period') : 'last_30_days';
        $mode = array_key_exists($request->query('compare'), self::COMPARES) ? $request->query('compare') : 'off';

        $error = null;
        try {
            $period = $this->periods->resolve($preset, $request->query('start'), $request->query('end'));
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage() . ' Showing the last 30 days instead.';
            $preset = 'last_30_days';
            $period = $this->periods->resolve($preset);
        }
        $compare = null;
        try {
            $compare = $this->periods->resolveComparison($mode, $period, $request->query('c_start'), $request->query('c_end'));
        } catch (\InvalidArgumentException $e) {
            $error = trim(($error ? $error . ' ' : '') . $e->getMessage() . ' The comparison was left out.');
            $mode = 'off';
        }

        $data = $this->funnel->forViewer($viewer, $period, $compare, $track);

        return view('road-to-sold.funnel.belt', $data + [
            'track' => $track, 'preset' => $preset, 'mode' => $mode, 'error' => $error, 'periods' => self::PERIODS, 'compares' => self::COMPARES,
            'delta' => fn (float $now, float $then, string $dir = 'higher_is_better') => PeriodComparison::compute($now, $then, $dir),
            'start' => $request->query('start'), 'end' => $request->query('end'), 'cStart' => $request->query('c_start'), 'cEnd' => $request->query('c_end'),
        ]);
    }
}
