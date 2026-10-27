<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsSetting;
use App\Models\RoadToSold\RtsWeekdayTarget;
use App\Models\Target;
use App\Services\Agent\MyPortalNumbers;
use App\View\Components\PeriodSelector;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\SplitAllocator;
use App\Services\RoadToSold\Support\Routing;
use App\Services\RoadToSold\TargetService;
use App\Services\RoadToSold\VisibleAgents;
use App\Services\RoadToSold\WorkdayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * AT-454 — the agents x weekdays target grid (.ai/specs/road-to-sold.md §5.3).
 *
 * A blank cell means "no own number: use the agency default". Every {user} route re-checks
 * the viewer's own/branch/agency breadth and answers 404 outside it; an "own"-breadth viewer
 * may look but never write (agents do not set their own targets).
 */
class TargetsController extends Controller
{
    private const PAGE_SIZE = 25;

    /** Sales or rentals: decided by where these screens are mounted (see Support\Routing). */
    private function track(): string
    {
        return Routing::track();
    }

    public function __construct(private readonly TargetService $targets)
    {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $viewer   = $request->user();
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return redirect()->route('corex.dashboard')->with('error', 'Switch into an agency first - targets belong to one agency.');
        }

        $settings = SettingsService::forAgency($agencyId, $this->track());
        $default  = $settings->defaultWeekdayTargets();
        $agents   = VisibleAgents::for($viewer);

        $status  = $request->query('status') === 'cleared' ? 'cleared' : 'active';
        $mode    = in_array($request->query('mode'), ['custom', 'default'], true) ? $request->query('mode') : 'all';
        $q       = trim((string) $request->query('q', ''));
        $branchId = (int) $request->query('branch_id', 0);
        $sort    = in_array($request->query('sort'), ['name', 'branch', 'total'], true) ? $request->query('sort') : 'name';

        $own     = $this->targets->ownForMany($agencyId, $this->track(), $agents->keys()->all());
        $cleared = array_flip($this->targets->clearedAgentIds($agencyId, $this->track(), $agents->keys()->all()));

        $rows = $agents->map(function (User $u) use ($own, $default, $cleared) {
            $mine = $own[$u->id] ?? [];

            return [
                'user' => $u, 'own' => $mine, 'week' => TargetService::merge($mine, $default),
                'custom' => $mine !== [], 'cleared' => isset($cleared[$u->id]),
            ];
        })->values();

        $rows = $status === 'cleared' ? $rows->filter(fn ($r) => $r['cleared']) : $rows;
        if ($mode === 'custom') {
            $rows = $rows->filter(fn ($r) => $r['custom']);
        } elseif ($mode === 'default') {
            $rows = $rows->filter(fn ($r) => ! $r['custom']);
        }
        if ($branchId > 0) {
            $rows = $rows->filter(fn ($r) => (int) $r['user']->branch_id === $branchId);
        }
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower((string) $r['user']->name), $needle)
                || str_contains(mb_strtolower((string) $r['user']->email), $needle));
        }

        $rows = match ($sort) {
            'branch' => $rows->sortBy(fn ($r) => mb_strtolower((string) ($r['user']->branch?->name ?? '')) . '|' . mb_strtolower((string) $r['user']->name)),
            'total'  => $rows->sortByDesc(fn ($r) => array_sum($r['week'])),
            default  => $rows->sortBy(fn ($r) => mb_strtolower((string) $r['user']->name)),
        };

        $page = $this->paginate($rows->values(), $request);

        // This month, as the work-day calculator sees it (public holidays and approved leave already taken off).
        $workdays = app(WorkdayService::class);
        $today    = now();
        $page->through(function (array $row) use ($workdays, $today) {
            $row['month_to_date'] = $workdays->monthTargetToDate($row['user'], $today, $this->track());
            $row['month_total']   = $workdays->monthTarget($row['user'], $today, $this->track());

            return $row;
        });

        return view('road-to-sold.settings.targets', [
            'monthly'  => $this->monthlyData($request, $viewer, $agents, $settings),
            'tabs'     => SettingsController::tabsFor($viewer, 'targets'),
            'rows'     => $page,
            'default'  => $default,
            'weekdays' => RtsWeekdayTarget::WEEKDAYS,
            'max'      => RtsWeekdayTarget::MAX_TARGET,
            'weekdayOn' => \App\Services\RoadToSold\Scope::callListOn($viewer),
            'canWrite' => $this->canWrite($viewer),
            'canEditDefault' => $this->canEditDefault($viewer),
            'branches' => $agents->pluck('branch.name', 'branch_id')->filter()->sort(),
            'filters'  => compact('q', 'status', 'mode', 'branchId', 'sort'),
            'emptyFiltered' => $page->total() === 0 && ($q !== '' || $branchId > 0 || $mode !== 'all' || $status === 'cleared'),
        ]);
    }

    /** The agency's default week (Mon-Fri 5, Sat/Sun 0 until changed). */
    public function updateDefault(Request $request): RedirectResponse
    {
        abort_unless($this->canEditDefault($request->user()), 403, 'Only someone who manages Road to Sold settings for the whole agency can change the agency default.');
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);
        abort_unless($agencyId > 0, 404);

        $request->validate($this->weekRules('default_targets', required: true), $this->weekMessages('Agency default'));

        // Same canonical saver the settings page and the wizard use (audited, partial-update safe).
        app(SettingsController::class)->saveFor($request, $agencyId, (int) $request->user()->id);

        return $this->back($request)->with('success', 'Agency default week saved. It applies to every agent without their own number for a day.');
    }

    /** Save one agent's week. Blank = no own number (use the default). */
    public function saveAgent(Request $request, int $user): RedirectResponse
    {
        $viewer = $request->user();
        $agent  = $this->agentOr404($viewer, $user);
        $this->assertCanWrite($viewer);

        $request->validate($this->weekRules('week', required: false), $this->weekMessages('Target'));
        $changed = $this->targets->saveWeek($agent, TargetService::normalisePosted($request->input('week')), (int) $viewer->id, $this->track());

        return $this->back($request)->with('success', $changed === 0
            ? 'Nothing changed for ' . $agent->name . '.'
            : $agent->name . '\'s week saved.');
    }

    /** One number for Monday to Friday: copies the posted Monday number onto Tuesday-Friday. */
    public function fillWeekdays(Request $request, int $user): RedirectResponse
    {
        $viewer = $request->user();
        $agent  = $this->agentOr404($viewer, $user);
        $this->assertCanWrite($viewer);

        $request->validate($this->weekRules('week', required: false), $this->weekMessages('Target'));
        $week = TargetService::normalisePosted($request->input('week'));
        if (! array_key_exists(1, $week) || $week[1] === null) {
            throw ValidationException::withMessages(['week.1' => 'Type a number in Monday first - it is copied onto Tuesday to Friday.']);
        }
        foreach ([2, 3, 4, 5] as $day) {
            $week[$day] = $week[1];
        }
        $this->targets->saveWeek($agent, $week, (int) $viewer->id, $this->track());

        return $this->back($request)->with('success', $agent->name . ': Monday\'s number copied to Tuesday-Friday and saved.');
    }

    /**
     * Give every agent the posted week (the source row's cells, or the agency default).
     * Blank cells clear that day for everyone ("use the default"). Confirmed in the browser.
     */
    public function copyToAgents(Request $request): RedirectResponse
    {
        $viewer = $request->user();
        $this->assertCanWrite($viewer);
        // The source is either a grid row (week[1..7]) or the agency default row (default_targets[1..7]).
        $field = $request->has('default_targets') && ! $request->has('week') ? 'default_targets' : 'week';
        $request->validate($this->weekRules($field, required: $field === 'default_targets'), $this->weekMessages($field === 'default_targets' ? 'Agency default' : 'Target'));

        $week   = TargetService::normalisePosted($request->input($field));
        $agents = VisibleAgents::for($viewer);

        $n = 0;
        DB::transaction(function () use ($agents, $week, $viewer, &$n) {
            foreach ($agents as $agent) {
                $this->targets->saveWeek($agent, $week, (int) $viewer->id, $this->track());
                $n++;
            }
        });

        return $this->back($request)->with('success', 'That week was given to ' . $n . ' ' . ($n === 1 ? 'agent' : 'agents') . '.');
    }

    /** Clear an agent's own numbers (they go back to the agency default). Soft - can be restored. */
    public function clear(Request $request, int $user): RedirectResponse
    {
        $viewer = $request->user();
        $agent  = $this->agentOr404($viewer, $user);
        $this->assertCanWrite($viewer);

        $n = $this->targets->clear($agent, $this->track());

        return $this->back($request)->with('success', $n === 0
            ? $agent->name . ' had no numbers of their own - nothing to clear.'
            : $agent->name . ' is back on the agency default. You can bring their numbers back from "Cleared numbers".');
    }

    public function restore(Request $request, int $user): RedirectResponse
    {
        $viewer = $request->user();
        $agent  = $this->agentOr404($viewer, $user);
        $this->assertCanWrite($viewer);

        $n = $this->targets->restore($agent, $this->track());

        return $this->back($request)->with('success', $n === 0 ? 'Nothing to bring back.' : $agent->name . '\'s numbers are back.');
    }

    // ── monthly targets: points / deals / value / listings per agent per month (agency-tracker-rehome.md 5.7) ──

    /**
     * Everything the "Monthly targets" section prints. Its own filters (m_q, m_branch, m_show, m_sort) and its own
     * page (mpage) so it never fights the weekday grid below it; the month comes from the shared month picker.
     */
    private function monthlyData(Request $request, User $viewer, \Illuminate\Support\Collection $agents, \App\Services\RoadToSold\SettingsService $settings): array
    {
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        $period = PeriodSelector::monthFrom($request->query('period'));
        $ids = $agents->keys()->all();

        $q = trim((string) $request->query('m_q', ''));
        $branchId = (int) $request->query('m_branch', 0);
        $show = in_array($request->query('m_show'), ['set', 'notset', 'archived'], true) ? $request->query('m_show') : 'all';
        $sort = in_array($request->query('m_sort'), ['name', 'branch', 'points', 'deals', 'value', 'listings'], true) ? $request->query('m_sort') : 'name';

        $live = Target::query()->where('agency_id', $agencyId)->where('period', $period)->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $archived = Target::onlyTrashed()->where('agency_id', $agencyId)->where('period', $period)->whereIn('user_id', $ids)->get()->keyBy('user_id');

        $rows = $agents->map(fn (User $u) => ['user' => $u, 'row' => $live->get($u->id), 'archived' => $archived->get($u->id)])->values();

        // Totals of what is set, per branch and for everyone you can see (the old Monthly Goals roll-ups).
        $sum = fn ($set) => [
            'points' => (int) $set->sum(fn ($r) => (int) ($r['row']->points_target ?? 0)),
            'deals' => (int) $set->sum(fn ($r) => (int) ($r['row']->deals_target ?? 0)),
            'listings' => (int) $set->sum(fn ($r) => (int) ($r['row']->listings_target ?? 0)),
            'value' => (float) $set->sum(fn ($r) => (float) ($r['row']->value_target ?? 0)),
            'agents' => $set->count(),
            'withTargets' => $set->filter(fn ($r) => $r['row'])->count(),
        ];
        $totalAll = $sum($rows);
        $totalsByBranch = $rows->groupBy(fn ($r) => (int) $r['user']->branch_id)->map(fn ($g) => $sum($g));

        if ($show === 'archived') {
            $rows = $rows->filter(fn ($r) => $r['archived'] && ! $r['row']);
        } elseif ($show === 'set') {
            $rows = $rows->filter(fn ($r) => $r['row']);
        } elseif ($show === 'notset') {
            $rows = $rows->filter(fn ($r) => ! $r['row']);
        }
        if ($branchId > 0) {
            $rows = $rows->filter(fn ($r) => (int) $r['user']->branch_id === $branchId);
        }
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower((string) $r['user']->name), $needle)
                || str_contains(mb_strtolower((string) $r['user']->email), $needle));
        }
        $field = ['points' => 'points_target', 'deals' => 'deals_target', 'value' => 'value_target', 'listings' => 'listings_target'];
        $rows = match (true) {
            $sort === 'branch' => $rows->sortBy(fn ($r) => mb_strtolower((string) ($r['user']->branch?->name ?? '')) . '|' . mb_strtolower((string) $r['user']->name)),
            isset($field[$sort]) => $rows->sortByDesc(fn ($r) => (float) ($r['row']->{$field[$sort]} ?? 0)),
            default => $rows->sortBy(fn ($r) => mb_strtolower((string) $r['user']->name)),
        };

        $page = new LengthAwarePaginator(
            $rows->values()->forPage(max(1, (int) $request->query('mpage', 1)), self::PAGE_SIZE)->values(), $rows->count(), self::PAGE_SIZE,
            max(1, (int) $request->query('mpage', 1)), ['path' => $request->url(), 'query' => $request->query(), 'pageName' => 'mpage']
        );

        // Actual against target, one rule shared with My Portal: listings = the agent's listings that went live
        // in the month (CoreX properties, not the frozen import); points = the shared daily-points rule.
        $numbers = app(MyPortalNumbers::class);
        $page->through(function (array $r) use ($numbers, $period) {
            $u = $r['user'];
            $deals = $numbers->ownActuals($u, $period);
            $r['actual'] = [
                'points' => (int) round($numbers->points($u, $period)['month']),
                'deals' => $deals['deals'], 'value' => $deals['salesValue'],
                'listings' => $numbers->listingsLive($u, $period),
            ];

            return $r;
        });

        // The months worth offering: the ones that have any target row.
        $months = Target::query()->where('agency_id', $agencyId)->distinct()->orderByDesc('period')->pluck('period')->all();

        return [
            'period' => $period, 'months' => $months, 'rows' => $page,
            'default' => $settings->defaultMonthlyTargets(),
            'totalAll' => $totalAll, 'totalsByBranch' => $totalsByBranch,
            'filters' => compact('q', 'show', 'sort') + ['branchId' => $branchId],
            'emptyFiltered' => $page->total() === 0 && ($q !== '' || $branchId > 0 || $show !== 'all'),
            'max' => ['count' => RtsSetting::MAX_MONTHLY_COUNT, 'value' => RtsSetting::MAX_MONTHLY_VALUE],
            'split' => $this->monthlySplit($viewer, $agents, $period, $live),
        ];
    }

    /** The four monthly measures, as the split panel and the save name them. */
    private const SPLIT_LABELS = ['points' => 'Points', 'deals' => 'Deals', 'listings' => 'Listings', 'value' => 'Sales value (rand)'];

    /**
     * The "type the total, split it by percentage" panel for the monthly targets (spec 21.3). The totals start from what the agents you
     * can see already have for the month; the percentages from how those totals are spread (an even split when nothing is set). The
     * split writes each agent's four numbers for the month. Agents with no branch cannot be part of a branch split and are left out.
     *
     * @param  Collection<int,User>                        $agents
     * @param  Collection<int,\App\Models\Target>          $live  this month's target rows keyed by agent id
     * @return array<string,mixed>|null
     */
    private function monthlySplit(User $viewer, Collection $agents, string $period, Collection $live): ?array
    {
        if (! $this->canWrite($viewer)) {
            return null;
        }
        $byBranch = $this->agentsByBranch($agents);
        if ($byBranch === []) {
            return null;
        }
        $scope = PermissionService::getDataScope($viewer, 'road_to_sold');
        $field = ['points' => 'points_target', 'deals' => 'deals_target', 'listings' => 'listings_target', 'value' => 'value_target'];
        $of = fn (int $uid, string $m) => (float) ($live->get($uid)->{$field[$m]} ?? 0);

        $measures = [];
        foreach (TargetService::SPLIT_MEASURES as $m => $decimals) {
            $measures[] = ['key' => $m, 'label' => self::SPLIT_LABELS[$m], 'decimals' => $decimals, 'money' => $m === 'value'];
        }
        $branchNames = \App\Models\Branch::query()->whereIn('id', array_keys($byBranch))->pluck('name', 'id');

        $branchRows = [];
        $totals = array_fill_keys(array_keys(TargetService::SPLIT_MEASURES), 0.0);
        $branchWeight = [];
        foreach ($byBranch as $bid => $ids) {
            $weight = 0.0;
            foreach ($ids as $uid) {
                foreach (TargetService::SPLIT_MEASURES as $m => $_) {
                    $totals[$m] += $of($uid, $m);
                }
                $weight += $of($uid, 'points') ?: ($of($uid, 'deals') ?: ($of($uid, 'listings') ?: $of($uid, 'value')));
            }
            $branchWeight[$bid] = $weight;
            $agentPct = SplitAllocator::percentsOf(array_combine($ids, array_map(fn ($uid) => $of($uid, 'points') ?: ($of($uid, 'deals') ?: ($of($uid, 'listings') ?: $of($uid, 'value'))), $ids)));
            $branchRows[$bid] = ['id' => (int) $bid, 'name' => (string) ($branchNames[$bid] ?? 'Branch'), 'pct' => 0.0,
                'agents' => array_map(fn ($uid) => ['id' => (int) $uid, 'name' => (string) $agents[$uid]->name, 'pct' => (float) $agentPct[$uid]], $ids)];
        }
        $branchPct = SplitAllocator::percentsOf($branchWeight);
        foreach ($branchRows as $bid => &$row) {
            $row['pct'] = (float) $branchPct[$bid];
        }
        unset($row);

        $totalsRounded = [];
        foreach ($totals as $m => $v) {
            $totalsRounded[$m] = $m === 'value' ? round($v, 2) : (int) round($v);
        }
        $common = ['id' => 'targets-split', 'action' => route(Routing::base() . 'targets.monthly.split'), 'period' => $period, 'measures' => $measures, 'canWrite' => true, 'totalEditable' => true];
        if ($scope === 'all') {
            return $common + ['mode' => 'agency', 'branchId' => null, 'totalLabel' => 'What the agency\'s targets add up to for ' . $this->monthLabel($period),
                'totals' => $totalsRounded, 'branches' => array_values($branchRows)];
        }
        $mine = (int) $viewer->effectiveBranchId();
        if (! isset($branchRows[$mine])) {
            return null;
        }
        $mineTotals = array_fill_keys(array_keys(TargetService::SPLIT_MEASURES), 0.0);
        foreach ($byBranch[$mine] as $uid) {
            foreach (TargetService::SPLIT_MEASURES as $m => $_) {
                $mineTotals[$m] += $of($uid, $m);
            }
        }
        $branchRows[$mine]['pct'] = 100.0;

        return $common + ['mode' => 'branch', 'branchId' => $mine, 'totalLabel' => 'What ' . $branchRows[$mine]['name'] . '\'s targets add up to for ' . $this->monthLabel($period),
            'totals' => array_map(fn ($v, $m) => $m === 'value' ? round($v, 2) : (int) round($v), $mineTotals, array_keys($mineTotals)), 'branches' => [$branchRows[$mine]]];
    }

    /**
     * The agents each branch's share is split between: the ones the viewer can see, grouped by branch, in name order.
     *
     * @param  Collection<int,User> $agents
     * @return array<int,int[]> branch id => agent ids
     */
    private function agentsByBranch(Collection $agents): array
    {
        $out = [];
        foreach ($agents->sortBy(fn (User $u) => mb_strtolower((string) $u->name))->values() as $u) {
            if ((int) $u->branch_id > 0) {
                $out[(int) $u->branch_id][] = (int) $u->id;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Save the monthly-target split in one go: the totals, the branches' shares and each branch's agents. Every level must add up to
     * 100% or nothing is saved. Writes each agent's four numbers for the month.
     */
    public function splitMonthly(Request $request): RedirectResponse
    {
        $viewer = $request->user();
        $this->assertCanWrite($viewer);
        $period = $this->monthOrFail($request);
        $level = $request->input('level') === 'branch' ? 'branch' : 'agency';

        $request->replace($this->stripSeparators($request, ['total.value']));
        $data = $request->validate([
            'level'          => ['required', 'in:agency,branch'],
            'branch_id'      => ['nullable', 'integer'],
            'total'          => ['required', 'array'],
            'total.points'   => ['required', 'integer', 'min:0', 'max:' . RtsSetting::MAX_MONTHLY_COUNT],
            'total.deals'    => ['required', 'integer', 'min:0', 'max:' . RtsSetting::MAX_MONTHLY_COUNT],
            'total.listings' => ['required', 'integer', 'min:0', 'max:' . RtsSetting::MAX_MONTHLY_COUNT],
            'total.value'    => ['required', 'numeric', 'min:0', 'max:' . RtsSetting::MAX_MONTHLY_VALUE],
            'branches'       => ['nullable', 'array'],
            'branches.*'     => ['numeric', 'min:0', 'max:100'],
            'agents'         => ['nullable', 'array'],
            'agents.*'       => ['array'],
            'agents.*.*'     => ['numeric', 'min:0', 'max:100'],
        ], [
            'total.*.required' => 'Targets split: enter every total (0 if none).',
            'total.*.integer'  => 'Targets split: points, deals and listings are whole numbers from 0 up.',
            'total.*.numeric'  => 'Targets split: the sales value is an amount in rand from 0 up.',
            'total.*.min'      => 'Targets split: a total cannot be below 0.',
            'total.*.max'      => 'Targets split: that total is too large.',
            'branches.*.*'     => 'Each branch percentage must be a number from 0 to 100.',
            'agents.*.*.*'     => 'Each agent percentage must be a number from 0 to 100.',
        ]);

        $agents = VisibleAgents::for($viewer);
        $byBranch = $this->agentsByBranch($agents);
        $branchId = null;
        if ($level === 'agency') {
            abort_unless(PermissionService::getDataScope($viewer, 'road_to_sold') === 'all', 403, 'Only someone who manages Road to Sold for the whole agency can split the agency\'s targets.');
        } else {
            $branchId = (int) ($data['branch_id'] ?? 0);
            abort_unless($branchId > 0 && isset($byBranch[$branchId]) && (PermissionService::getDataScope($viewer, 'road_to_sold') === 'all' || $branchId === (int) $viewer->effectiveBranchId()), 404);
            $byBranch = [$branchId => $byBranch[$branchId]];
        }

        $agentPercents = [];
        foreach ((array) ($data['agents'] ?? []) as $bid => $list) {
            $agentPercents[(int) $bid] = array_combine(array_map('intval', array_keys($list)), array_values($list));
        }
        $branchPercents = [];
        foreach ((array) ($data['branches'] ?? []) as $bid => $v) {
            $branchPercents[(int) $bid] = $v;
        }

        $this->targets->saveSplit($viewer, $period, $branchId, $data['total'], $branchPercents, $agentPercents, $byBranch);

        return $this->backMonthly($request, $period)->with('success', 'The targets for ' . $this->monthLabel($period) . ' are split and saved for every agent.');
    }

    /**
     * Save one agent's monthly targets. Only the boxes that arrive are written, so saving the points box never
     * touches deals, value or listings (the old Targets screen zeroed them). A blank box is left alone; type 0 to set zero.
     */
    public function saveMonthly(Request $request, int $user): RedirectResponse
    {
        $viewer = $request->user();
        $agent = $this->agentOr404($viewer, $user);
        $this->assertCanWrite($viewer);
        $period = $this->monthOrFail($request);

        $request->replace($this->stripSeparators($request, ['value_target']));
        $data = $request->validate([
            'points_target'   => ['nullable', 'integer', 'min:0', 'max:' . RtsSetting::MAX_MONTHLY_COUNT],
            'deals_target'    => ['nullable', 'integer', 'min:0', 'max:' . RtsSetting::MAX_MONTHLY_COUNT],
            'listings_target' => ['nullable', 'integer', 'min:0', 'max:' . RtsSetting::MAX_MONTHLY_COUNT],
            'value_target'    => ['nullable', 'numeric', 'min:0', 'max:' . RtsSetting::MAX_MONTHLY_VALUE],
        ], $this->monthlyMessages());

        // array_intersect_key on what was POSTED: an absent box is never turned into zero.
        $posted = array_intersect_key($data, array_flip(array_keys($request->only(TargetService::MONTHLY_FIELDS))));
        $this->targets->saveMonthly($viewer, $agent, $period, $posted);

        return $this->backMonthly($request, $period)->with('success', $agent->name . '\'s targets for ' . $this->monthLabel($period) . ' are saved.');
    }

    public function archiveMonthly(Request $request, int $user): RedirectResponse
    {
        $viewer = $request->user();
        $agent = $this->agentOr404($viewer, $user);
        $this->assertCanWrite($viewer);
        $period = $this->monthOrFail($request);

        $ok = $this->targets->archiveMonthly($viewer, $agent, $period);

        return $this->backMonthly($request, $period)->with('success', $ok
            ? $agent->name . '\'s targets for ' . $this->monthLabel($period) . ' are removed. You can bring them back from "Removed targets".'
            : $agent->name . ' had no targets for ' . $this->monthLabel($period) . ' - nothing to remove.');
    }

    public function restoreMonthly(Request $request, int $user): RedirectResponse
    {
        $viewer = $request->user();
        $agent = $this->agentOr404($viewer, $user);
        $this->assertCanWrite($viewer);
        $period = $this->monthOrFail($request);

        $ok = $this->targets->restoreMonthly($viewer, $agent, $period);

        return $this->backMonthly($request, $period)->with($ok ? 'success' : 'error', $ok
            ? $agent->name . '\'s targets are back.'
            : 'Nothing to bring back for ' . $agent->name . ' that month.');
    }

    /** The agency default for the monthly targets (what an agent without a number is given). Agency-wide managers only. */
    public function updateMonthlyDefault(Request $request): RedirectResponse
    {
        abort_unless($this->canEditDefault($request->user()), 403, 'Only someone who manages Road to Sold settings for the whole agency can change the agency default.');
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);
        abort_unless($agencyId > 0, 404);

        $request->replace($this->stripSeparators($request, ['default_monthly.value']));
        $request->validate([
            'default_monthly'          => ['required', 'array'],
            'default_monthly.points'   => ['required', 'integer', 'min:0', 'max:' . RtsSetting::MAX_MONTHLY_COUNT],
            'default_monthly.deals'    => ['required', 'integer', 'min:0', 'max:' . RtsSetting::MAX_MONTHLY_COUNT],
            'default_monthly.listings' => ['required', 'integer', 'min:0', 'max:' . RtsSetting::MAX_MONTHLY_COUNT],
            'default_monthly.value'    => ['required', 'numeric', 'min:0', 'max:' . RtsSetting::MAX_MONTHLY_VALUE],
        ], [
            'default_monthly.*.required' => 'Agency default: enter a number for each box (0 if you do not set one).',
            'default_monthly.*.integer'  => 'Agency default: enter a whole number from 0 up.',
            'default_monthly.*.numeric'  => 'Agency default: enter an amount in rand from 0 up.',
            'default_monthly.*.min'      => 'Agency default: it cannot be below 0.',
            'default_monthly.*.max'      => 'Agency default: that number is too large.',
        ]);

        app(SettingsController::class)->saveFor($request, $agencyId, (int) $request->user()->id);

        return $this->backMonthly($request, PeriodSelector::monthFrom($request->input('period')))->with('success', 'Agency default targets saved. Press "Give agents without targets the default" to hand them out for a month.');
    }

    /** Give the agency default to every agent you can see who has no targets row for the month. Existing numbers are never replaced. */
    public function applyMonthlyDefault(Request $request): RedirectResponse
    {
        $viewer = $request->user();
        $this->assertCanWrite($viewer);
        $period = $this->monthOrFail($request);
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);

        $default = SettingsService::forAgency($agencyId, $this->track())->defaultMonthlyTargets();
        if (! array_filter($default)) {
            return $this->backMonthly($request, $period)->with('error', 'The agency has no default targets yet - type them in "Agency default" first.');
        }
        $n = $this->targets->applyMonthlyDefault($viewer, VisibleAgents::for($viewer), $period, $default);

        return $this->backMonthly($request, $period)->with('success', $n === 0
            ? 'Every agent you can see already has targets for ' . $this->monthLabel($period) . ' - nobody needed the default.'
            : $n . ' ' . ($n === 1 ? 'agent was' : 'agents were') . ' given the agency default for ' . $this->monthLabel($period) . '.');
    }

    private function monthOrFail(Request $request): string
    {
        $raw = $request->input('period', $request->query('period'));
        if (! is_string($raw) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)) {
            throw ValidationException::withMessages(['period' => 'Pick a month first.']);
        }

        return $raw;
    }

    /** "R 1 500 000" / "1,500,000" typed into a money box: take the spaces and commas out before validating (siblings untouched). */
    private function stripSeparators(Request $request, array $keys): array
    {
        $all = $request->all();
        foreach ($keys as $k) {
            $v = \Illuminate\Support\Arr::get($all, $k);
            if (is_string($v)) {
                \Illuminate\Support\Arr::set($all, $k, str_replace([' ', ','], '', $v));
            }
        }

        return $all;
    }

    private function monthLabel(string $period): string
    {
        return \Carbon\CarbonImmutable::createFromFormat('!Y-m', $period)->format('F Y');
    }

    /** @return array<string,string> */
    private function monthlyMessages(): array
    {
        $m = [];
        foreach (['points_target' => 'Points', 'deals_target' => 'Deals', 'listings_target' => 'Listings', 'value_target' => 'Sales value'] as $f => $label) {
            $m["{$f}.integer"] = "{$label}: enter a whole number from 0 up.";
            $m["{$f}.numeric"] = "{$label}: enter an amount in rand from 0 up (no letters).";
            $m["{$f}.min"] = "{$label}: it cannot be below 0.";
            $m["{$f}.max"] = "{$label}: that number is too large.";
        }

        return $m;
    }

    private function backMonthly(Request $request, string $period): RedirectResponse
    {
        return redirect()->to(route(Routing::base() . 'targets.index', array_filter(
            ['period' => $period] + $request->only(['m_q', 'm_show', 'm_branch', 'm_sort', 'mpage'])
        )) . '#monthly');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function agentOr404(User $viewer, int $userId): User
    {
        $agent = VisibleAgents::for($viewer)->get($userId);
        abort_unless($agent, 404);

        return $agent;
    }

    /** An "own"-breadth viewer may look but not write: agents do not set their own targets. */
    private function canWrite(User $viewer): bool
    {
        $scope = PermissionService::getDataScope($viewer, 'road_to_sold');

        return in_array($scope, ['all', 'branch'], true) && $viewer->hasPermission('road_to_sold.manage_targets');
    }

    /** The agency default is agency-wide, so a branch-only manager cannot change it. */
    private function canEditDefault(User $viewer): bool
    {
        if ($viewer->hasPermission('road_to_sold.manage_settings')) {
            return true;
        }

        return $this->canWrite($viewer) && PermissionService::getDataScope($viewer, 'road_to_sold') === 'all';
    }

    private function assertCanWrite(User $viewer): void
    {
        abort_unless($this->canWrite($viewer), 403, 'You can look at targets but not change them.');
    }

    /** @return array<string,array<int,string>> */
    private function weekRules(string $field, bool $required): array
    {
        $rules = [$field => $required ? ['required', 'array'] : ['sometimes', 'array']];
        foreach (array_keys(RtsWeekdayTarget::WEEKDAYS) as $day) {
            $rules["{$field}.{$day}"] = $required
                ? ['required', 'integer', 'min:0', 'max:' . RtsWeekdayTarget::MAX_TARGET]
                : ['nullable', 'integer', 'min:0', 'max:' . RtsWeekdayTarget::MAX_TARGET];
        }

        return $rules;
    }

    /** @return array<string,string> */
    private function weekMessages(string $prefix): array
    {
        $m = [];
        foreach (RtsWeekdayTarget::WEEKDAYS as $day => $label) {
            foreach (['week', 'default_targets'] as $field) {
                $text = "{$prefix} {$label}: enter a whole number from 0 to " . RtsWeekdayTarget::MAX_TARGET . ' (or leave it blank to use the agency default).';
                foreach (['integer', 'min', 'max'] as $rule) {
                    $m["{$field}.{$day}.{$rule}"] = $text;
                }
                $m["{$field}.{$day}.required"] = "{$prefix} {$label}: enter a number (0 if nobody works that day).";
            }
        }

        return $m;
    }

    private function back(Request $request): RedirectResponse
    {
        return redirect()->route(Routing::base() . 'targets.index', array_filter($request->only(['q', 'status', 'mode', 'branch_id', 'sort', 'page'])));
    }

    private function paginate(Collection $items, Request $request): LengthAwarePaginator
    {
        $page = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator(
            $items->forPage($page, self::PAGE_SIZE)->values(), $items->count(), self::PAGE_SIZE, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }
}
