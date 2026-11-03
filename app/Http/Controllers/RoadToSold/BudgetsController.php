<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\RoadToSold\RtsBudget;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\BudgetService;
use App\Services\RoadToSold\SplitAllocator;
use App\Services\RoadToSold\Support\Routing;
use App\Services\RoadToSold\VisibleAgents;
use App\View\Components\PeriodSelector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Road to Sold settings -> Budgets & requirements (agency-tracker-rehome.md 4.3, step 4).
 *
 * The one home of what the Worksheet, the Branch Performance budget box and Admin Monthly Goals used to hold:
 *   - what the agency needs each month,
 *   - what each branch needs each month,
 *   - each agent's personal and business budget (and the planning figures behind "what the budget needs").
 * Screens only: every write goes through BudgetService (restore-never-duplicate, agency boundary).
 *
 * Breadth is decided here, in the query: an agency-wide manager sees everyone; a branch manager sees (and may
 * write for) their own branch's agents and their own branch's requirement only; an "own"-breadth viewer may
 * look but never write. Every {user} / {branch} route answers 404 outside the viewer's breadth.
 */
class BudgetsController extends Controller
{
    private const PAGE_SIZE = 25;

    public function __construct(private readonly BudgetService $budgets)
    {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $viewer = $request->user();
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return redirect()->route('corex.dashboard')->with('error', 'Switch into an agency first - budgets belong to one agency.');
        }
        $track = Routing::track();
        $period = PeriodSelector::monthFrom($request->query('period'));

        $q = trim((string) $request->query('q', ''));
        $branchFilter = (int) $request->query('branch_id', 0);
        $show = in_array($request->query('show'), ['set', 'notset', 'archived'], true) ? $request->query('show') : 'all';
        $sort = in_array($request->query('sort'), ['name', 'branch', 'personal', 'business', 'total'], true) ? $request->query('sort') : 'name';

        $agents = VisibleAgents::for($viewer);
        $ids = $agents->keys()->all();

        $live = RtsBudget::query()->where('agency_id', $agencyId)->where('track', $track)->where('level', RtsBudget::LEVEL_AGENT)
            ->where('period', $period)->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $archived = RtsBudget::onlyTrashed()->where('agency_id', $agencyId)->where('track', $track)->where('level', RtsBudget::LEVEL_AGENT)
            ->where('period', $period)->whereIn('user_id', $ids)->get()->keyBy('user_id');

        $rows = $agents->map(function (User $u) use ($live, $archived, $period, $track) {
            $row = $live->get($u->id);
            // A row that only holds the agent's share of a branch total (spec 21.3) is not their budget for the month.
            if ($row && $row->isShareOnly()) {
                $row = null;
            }
            $carried = null;
            if (! $row) {
                $f = $this->budgets->forUser($u, $period, $track);
                $carried = $f['source'] === BudgetService::SOURCE_CARRIED ? $f : null;
            }

            // A month with no row of its own shows the figures it is using (the latest earlier month's): a 0 next to
            // "using April's figures" would contradict itself.
            return [
                'user' => $u, 'row' => $row, 'archived' => $archived->get($u->id), 'carried' => $carried,
                'personal' => (float) ($row->personal_budget ?? ($carried['personalBudget'] ?? 0)),
                'business' => (float) ($row->business_budget ?? ($carried['businessBudget'] ?? 0)),
            ];
        })->values();

        if ($show === 'archived') {
            $rows = $rows->filter(fn ($r) => $r['archived'] && ! $r['row']);
        } elseif ($show === 'set') {
            $rows = $rows->filter(fn ($r) => $r['row']);
        } elseif ($show === 'notset') {
            $rows = $rows->filter(fn ($r) => ! $r['row']);
        }
        if ($branchFilter > 0) {
            $rows = $rows->filter(fn ($r) => (int) $r['user']->branch_id === $branchFilter);
        }
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower((string) $r['user']->name), $needle)
                || str_contains(mb_strtolower((string) $r['user']->email), $needle));
        }
        $rows = match ($sort) {
            'branch'   => $rows->sortBy(fn ($r) => mb_strtolower((string) ($r['user']->branch?->name ?? '')) . '|' . mb_strtolower((string) $r['user']->name)),
            'personal' => $rows->sortByDesc('personal'),
            'business' => $rows->sortByDesc('business'),
            'total'    => $rows->sortByDesc(fn ($r) => $r['personal'] + $r['business']),
            default    => $rows->sortBy(fn ($r) => mb_strtolower((string) $r['user']->name)),
        };
        $page = $this->paginate($rows->values(), $request);

        $scope = PermissionService::getDataScope($viewer, 'road_to_sold');
        $branches = $this->visibleBranches($viewer, $agencyId, $scope);
        $reqs = $this->budgets->branchRequirements($agencyId, $period, $track);

        $months = RtsBudget::query()->where('agency_id', $agencyId)->where('track', $track)->distinct()->orderByDesc('period')->pluck('period')->all();

        return view('road-to-sold.settings.budgets', [
            'tabs'       => SettingsController::tabsFor($viewer, 'budgets'),
            'rb'         => Routing::base(),
            'period'     => $period,
            'months'     => $months,
            'rows'       => $page,
            'canWrite'   => $this->canWrite($viewer),
            'canEditAgency' => $this->canWrite($viewer) && $scope === 'all',
            'split'      => $this->splitConfig($viewer, $agencyId, $period, $track, $scope, $branches),
            'agencyDetail' => $this->budgets->agencyRequirementDetail($agencyId, $period, $track),
            'branchRows' => $branches->map(function (Branch $b) use ($reqs, $viewer, $agencyId, $period, $track) {
                $r = $this->budgets->requirementFor($agencyId, (int) $b->id, $period, $track);

                return ['branch' => $b, 'requirement' => (float) ($reqs[$b->id] ?? 0), 'perAgent' => $r['requiredPerAgent'], 'agents' => $r['agents'],
                    'canEdit' => $this->canWrite($viewer) && $this->mayWriteBranch($viewer, (int) $b->id)];
            })->values(),
            'branches'   => $agents->pluck('branch.name', 'branch_id')->filter()->sort(),
            'filters'    => compact('q', 'show', 'sort') + ['branchId' => $branchFilter],
            'emptyFiltered' => $page->total() === 0 && ($q !== '' || $branchFilter > 0 || $show !== 'all'),
            'planningKeys' => [
                'avg_sale_price'           => ['Average sale price (rand)', 'The sale price the calculation plans around. Leave blank if you have not decided one.'],
                'commission_percent'       => ['Commission %', 'The commission on a sale, as a percentage of the price.'],
                'paye_percent'             => ['Tax (PAYE) %', 'The share of the agent\'s commission taken as tax.'],
                'agent_split_percent'      => ['Agent share %', 'The agent\'s share of the commission after the agency\'s cut.'],
                'correctly_priced_percent' => ['Correctly priced listings %', 'How many of the agent\'s listings are priced to sell, which sets how many listings are needed.'],
            ],
        ]);
    }

    /** Save one agent's budget for the month. A blank box leaves that figure as it is; type 0 to set zero. */
    public function saveAgent(Request $request, int $user): RedirectResponse
    {
        $viewer = $request->user();
        $agent = $this->agentOr404($viewer, $user);
        $this->assertCanWrite($viewer);

        $period = $this->periodOrFail($request);
        $max = RtsBudget::MAX_MONEY;
        $rules = [
            'personal_budget' => ['nullable', 'numeric', 'min:0', "max:{$max}"],
            'business_budget' => ['nullable', 'numeric', 'min:0', "max:{$max}"],
            'planning'        => ['sometimes', 'array'],
            'planning.avg_sale_price'           => ['nullable', 'numeric', 'min:0', "max:{$max}"],
            'planning.commission_percent'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'planning.paye_percent'             => ['nullable', 'numeric', 'min:0', 'max:100'],
            'planning.agent_split_percent'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'planning.correctly_priced_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
        $request->merge(array_map(fn ($v) => is_string($v) ? str_replace([' ', ','], '', $v) : $v, $request->only(['personal_budget', 'business_budget'])));
        $data = $request->validate($rules, $this->moneyMessages());

        // Only the planning boxes that were posted are touched; a posted blank clears that one figure.
        $planning = [];
        foreach (RtsBudget::PLANNING as $key) {
            if (is_array($data['planning'] ?? null) && array_key_exists($key, $request->input('planning', []))) {
                $v = $data['planning'][$key] ?? null;
                $planning[$key] = ($v === null || $v === '') ? null : (float) $v;
            }
        }

        $this->budgets->saveAgent(
            $viewer, $agent, $period,
            $this->blankToNull($data['personal_budget'] ?? null), $this->blankToNull($data['business_budget'] ?? null),
            $planning, Routing::track()
        );

        return $this->back($request, $period)->with('success', $agent->name . '\'s budget for ' . $this->monthLabel($period) . ' is saved.');
    }

    /** What a branch needs for the month. */
    public function saveBranch(Request $request, int $branch): RedirectResponse
    {
        $viewer = $request->user();
        $this->assertCanWrite($viewer);
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        $b = Branch::query()->where('agency_id', $agencyId)->find($branch);
        abort_unless($b && $this->mayWriteBranch($viewer, (int) $b->id), 404);

        $period = $this->periodOrFail($request);
        $request->merge(['requirement' => str_replace([' ', ','], '', (string) $request->input('requirement', ''))]);
        $data = $request->validate(['requirement' => ['required', 'numeric', 'min:0', 'max:' . RtsBudget::MAX_MONEY]], $this->moneyMessages());
        $this->budgets->saveRequirement($viewer, (int) $b->id, $period, (float) $data['requirement'], Routing::track());

        return $this->back($request, $period)->with('success', $b->name . ' needs ' . 'R ' . number_format((float) $data['requirement'], 2) . ' for ' . $this->monthLabel($period) . ' - saved.');
    }

    /** What the whole agency needs for the month. Agency-wide managers only. */
    public function saveAgency(Request $request): RedirectResponse
    {
        $viewer = $request->user();
        $this->assertCanWrite($viewer);
        abort_unless(PermissionService::getDataScope($viewer, 'road_to_sold') === 'all', 403, 'Only someone who manages Road to Sold for the whole agency can set what the agency needs.');

        $period = $this->periodOrFail($request);
        $request->merge(['requirement' => str_replace([' ', ','], '', (string) $request->input('requirement', ''))]);
        $data = $request->validate(['requirement' => ['required', 'numeric', 'min:0', 'max:' . RtsBudget::MAX_MONEY]], $this->moneyMessages());
        $this->budgets->saveRequirement($viewer, null, $period, (float) $data['requirement'], Routing::track());

        return $this->back($request, $period)->with('success', 'What the agency needs for ' . $this->monthLabel($period) . ' is saved.');
    }

    /**
     * The "type the total, split it by percentage" panel (spec 21.3). An agency-wide manager types what the agency needs, splits it to
     * the branches and each branch to its agents; a branch manager does the same for their own branch. The percentages start from what
     * is saved (the saved branch / agent amounts as shares of their total), or an even split when nothing has been split yet; a single
     * branch (or agent) is 100%. Null for someone who cannot write, or an agency with no branch.
     *
     * @param  Collection<int,Branch> $branches
     * @return array<string,mixed>|null
     */
    private function splitConfig(User $viewer, int $agencyId, string $period, string $track, ?string $scope, Collection $branches): ?array
    {
        if (! $this->canWrite($viewer) || $branches->isEmpty()) {
            return null;
        }
        $measures = [['key' => 'requirement', 'label' => 'Amount (rand)', 'decimals' => 2, 'money' => true]];
        $reqs = $this->budgets->branchRequirements($agencyId, $period, $track);

        $agentsOf = function (Branch $b) use ($agencyId, $period, $track) {
            $ids = $this->budgets->splitAgentIds($agencyId, (int) $b->id);
            $names = User::query()->withoutGlobalScopes()->whereIn('id', $ids)->pluck('name', 'id');
            $pct = SplitAllocator::percentsOf(array_replace(array_fill_keys($ids, 0.0), $this->budgets->agentRequirements($agencyId, $ids, $period, $track)));

            return array_map(fn ($id) => ['id' => $id, 'name' => (string) $names[$id], 'pct' => (float) $pct[$id]], $ids);
        };

        if ($scope === 'all') {
            $detail = $this->budgets->agencyRequirementDetail($agencyId, $period, $track);
            $branchPct = SplitAllocator::percentsOf(array_replace(array_fill_keys($branches->pluck('id')->all(), 0.0), array_intersect_key($reqs, array_flip($branches->pluck('id')->all()))));

            return [
                'id' => 'budget-split', 'action' => route(Routing::base() . 'budgets.split'), 'period' => $period, 'mode' => 'agency', 'branchId' => null,
                'totalLabel' => 'What the agency needs for ' . $this->monthLabel($period), 'measures' => $measures,
                'totals' => ['requirement' => (float) $detail['effective']], 'totalEditable' => true, 'canWrite' => true,
                'branches' => $branches->map(fn (Branch $b) => ['id' => (int) $b->id, 'name' => (string) $b->name, 'pct' => (float) $branchPct[$b->id], 'agents' => $agentsOf($b)])->values()->all(),
            ];
        }

        $mine = $branches->first(fn (Branch $b) => $this->mayWriteBranch($viewer, (int) $b->id));
        if (! $mine) {
            return null;
        }

        return [
            'id' => 'budget-split', 'action' => route(Routing::base() . 'budgets.split'), 'period' => $period, 'mode' => 'branch', 'branchId' => (int) $mine->id,
            'totalLabel' => 'What ' . $mine->name . ' needs for ' . $this->monthLabel($period), 'measures' => $measures,
            'totals' => ['requirement' => (float) ($reqs[$mine->id] ?? 0)], 'totalEditable' => true, 'canWrite' => true,
            'branches' => [['id' => (int) $mine->id, 'name' => (string) $mine->name, 'pct' => 100.0, 'agents' => $agentsOf($mine)]],
        ];
    }

    /**
     * Save the whole split in one go: the total, the branches' shares and each branch's agents (spec 21.3). Every level must add up
     * to 100% or nothing is saved.
     */
    public function split(Request $request): RedirectResponse
    {
        $viewer = $request->user();
        $this->assertCanWrite($viewer);
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        $period = $this->periodOrFail($request);
        $level = $request->input('level') === 'branch' ? 'branch' : 'agency';

        $all = $request->all();
        if (isset($all['total']['requirement']) && is_string($all['total']['requirement'])) {
            $all['total']['requirement'] = str_replace([' ', ','], '', $all['total']['requirement']);
        }
        $request->replace($all);
        $data = $request->validate([
            'level'          => ['required', 'in:agency,branch'],
            'branch_id'      => ['nullable', 'integer'],
            'total'          => ['required', 'array'],
            'total.requirement' => ['required', 'numeric', 'min:0', 'max:' . RtsBudget::MAX_MONEY],
            'branches'       => ['nullable', 'array'],
            'branches.*'     => ['numeric', 'min:0', 'max:100'],
            'agents'         => ['nullable', 'array'],
            'agents.*'       => ['array'],
            'agents.*.*'     => ['numeric', 'min:0', 'max:100'],
        ], $this->moneyMessages() + [
            'total.requirement.required' => 'The amount: enter an amount (0 if none).',
            'total.requirement.numeric'  => 'The amount: enter an amount in rand, for example 450000 (no letters).',
            'branches.*.numeric'         => 'Each branch percentage must be a number from 0 to 100.',
            'branches.*.min'             => 'Each branch percentage must be a number from 0 to 100.',
            'branches.*.max'             => 'Each branch percentage must be a number from 0 to 100.',
            'agents.*.*.numeric'         => 'Each agent percentage must be a number from 0 to 100.',
            'agents.*.*.min'             => 'Each agent percentage must be a number from 0 to 100.',
            'agents.*.*.max'             => 'Each agent percentage must be a number from 0 to 100.',
        ]);

        $branchId = null;
        if ($level === 'agency') {
            abort_unless(PermissionService::getDataScope($viewer, 'road_to_sold') === 'all', 403, 'Only someone who manages Road to Sold for the whole agency can split what the agency needs.');
        } else {
            $branchId = (int) ($data['branch_id'] ?? 0);
            $b = Branch::query()->where('agency_id', $agencyId)->find($branchId);
            abort_unless($b && $this->mayWriteBranch($viewer, (int) $b->id), 404);
        }

        $agentPercents = [];
        foreach ((array) ($data['agents'] ?? []) as $bid => $list) {
            $agentPercents[(int) $bid] = array_combine(array_map('intval', array_keys($list)), array_values($list));
        }
        $branchPercents = [];
        foreach ((array) ($data['branches'] ?? []) as $bid => $v) {
            $branchPercents[(int) $bid] = $v;
        }
        if ($level === 'branch') {
            // A branch manager may only split their own branch's agents.
            $agentPercents = array_intersect_key($agentPercents, [$branchId => true]);
        }

        $this->budgets->saveSplit($viewer, $period, $branchId, (float) $data['total']['requirement'], $branchPercents, $agentPercents, Routing::track());

        return $this->back($request, $period)->with('success', $level === 'agency'
            ? 'What the agency needs for ' . $this->monthLabel($period) . ' is saved and split to the branches and agents.'
            : 'What the branch needs for ' . $this->monthLabel($period) . ' is saved and split to its agents.');
    }

    /** Take an agent's budget for the month off (soft - it can be brought back). */
    public function archiveAgent(Request $request, int $user): RedirectResponse
    {
        $viewer = $request->user();
        $agent = $this->agentOr404($viewer, $user);
        $this->assertCanWrite($viewer);
        $period = $this->periodOrFail($request);

        $row = RtsBudget::query()->where('agency_id', (int) $viewer->effectiveAgencyId())->where('track', Routing::track())
            ->where('scope_key', RtsBudget::keyForUser((int) $agent->id))->where('period', $period)->first();
        $ok = $row ? $this->budgets->archive($viewer, (int) $row->id) : false;

        return $this->back($request, $period)->with('success', $ok
            ? $agent->name . '\'s budget for ' . $this->monthLabel($period) . ' is removed. You can bring it back from "Removed budgets".'
            : $agent->name . ' had no budget for ' . $this->monthLabel($period) . ' - nothing to remove.');
    }

    public function restoreAgent(Request $request, int $user): RedirectResponse
    {
        $viewer = $request->user();
        $agent = $this->agentOr404($viewer, $user);
        $this->assertCanWrite($viewer);
        $period = $this->periodOrFail($request);

        $row = RtsBudget::onlyTrashed()->where('agency_id', (int) $viewer->effectiveAgencyId())->where('track', Routing::track())
            ->where('scope_key', RtsBudget::keyForUser((int) $agent->id))->where('period', $period)->first();
        $ok = $row ? $this->budgets->restore($viewer, (int) $row->id) : false;

        return $this->back($request, $period)->with($ok ? 'success' : 'error', $ok
            ? $agent->name . '\'s budget is back.'
            : 'Nothing to bring back - there is no removed budget for ' . $agent->name . ' that month, or a newer one has been set since.');
    }

    /** For every agent you can see with no budget this month: give them last month's figures. */
    public function copyPrevious(Request $request): RedirectResponse
    {
        $viewer = $request->user();
        $this->assertCanWrite($viewer);
        $period = $this->periodOrFail($request);

        $n = $this->budgets->copyFromPreviousMonth($viewer, VisibleAgents::for($viewer), $period, Routing::track());

        return $this->back($request, $period)->with('success', $n === 0
            ? 'Nobody needed last month\'s figures - everyone either has a budget this month or had none last month.'
            : $n . ' ' . ($n === 1 ? 'agent was' : 'agents were') . ' given last month\'s figures for ' . $this->monthLabel($period) . '.');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function agentOr404(User $viewer, int $userId): User
    {
        $agent = VisibleAgents::for($viewer)->get($userId);
        abort_unless($agent, 404);

        return $agent;
    }

    /** An "own"-breadth viewer may look but not write. Either settings key opens the tab (like Targets). */
    private function canWrite(User $viewer): bool
    {
        $scope = PermissionService::getDataScope($viewer, 'road_to_sold');

        return in_array($scope, ['all', 'branch'], true)
            && ($viewer->hasPermission('road_to_sold.manage_targets') || $viewer->hasPermission('road_to_sold.manage_settings'));
    }

    private function assertCanWrite(User $viewer): void
    {
        abort_unless($this->canWrite($viewer), 403, 'You can look at budgets but not change them.');
    }

    /** A branch-breadth manager may set only their own branch; an agency-wide manager any branch of the agency. */
    private function mayWriteBranch(User $viewer, int $branchId): bool
    {
        $scope = PermissionService::getDataScope($viewer, 'road_to_sold');

        return $scope === 'all' || ($scope === 'branch' && (int) $viewer->effectiveBranchId() === $branchId);
    }

    /** @return Collection<int,Branch> */
    private function visibleBranches(User $viewer, int $agencyId, ?string $scope): Collection
    {
        $q = Branch::query()->where('agency_id', $agencyId)->orderBy('name');
        if ($scope === 'branch' || $scope === 'own') {
            $q->where('id', (int) $viewer->effectiveBranchId());
        } elseif ($scope === null) {
            return collect();
        }

        return $q->get();
    }

    private function periodOrFail(Request $request): string
    {
        $raw = $request->input('period', $request->query('period'));
        if (! is_string($raw) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)) {
            throw ValidationException::withMessages(['period' => 'Pick a month first.']);
        }

        return $raw;
    }

    private function blankToNull(mixed $v): ?float
    {
        return ($v === null || $v === '') ? null : (float) $v;
    }

    private function monthLabel(string $period): string
    {
        return \Carbon\CarbonImmutable::createFromFormat('!Y-m', $period)->format('F Y');
    }

    /** @return array<string,string> */
    private function moneyMessages(): array
    {
        $m = [];
        foreach (['personal_budget' => 'Personal budget', 'business_budget' => 'Business budget', 'requirement' => 'The amount',
            'planning.avg_sale_price' => 'Average sale price'] as $f => $label) {
            $m["{$f}.numeric"] = "{$label}: enter an amount in rand, for example 45000 (no letters).";
            $m["{$f}.min"] = "{$label}: it cannot be below 0.";
            $m["{$f}.max"] = "{$label}: that amount is too large.";
            $m["{$f}.required"] = "{$label}: enter an amount (0 if none).";
        }
        foreach (['commission_percent' => 'Commission %', 'paye_percent' => 'Tax %', 'agent_split_percent' => 'Agent share %', 'correctly_priced_percent' => 'Correctly priced %'] as $f => $label) {
            foreach (['numeric', 'min', 'max'] as $rule) {
                $m["planning.{$f}.{$rule}"] = "{$label}: enter a percentage from 0 to 100.";
            }
        }

        return $m;
    }

    private function back(Request $request, string $period): RedirectResponse
    {
        return redirect()->route(Routing::base() . 'budgets.index', array_filter(
            ['period' => $period] + $request->only(['q', 'show', 'branch_id', 'sort', 'page'])
        ));
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
