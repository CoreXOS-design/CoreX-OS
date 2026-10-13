<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsSetting;
use App\Models\RoadToSold\RtsWeekdayTarget;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\SettingsService;
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
    private const TRACK     = RtsSetting::TRACK_SALES;
    private const PAGE_SIZE = 25;

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

        $settings = SettingsService::forAgency($agencyId, self::TRACK);
        $default  = $settings->defaultWeekdayTargets();
        $agents   = VisibleAgents::for($viewer);

        $status  = $request->query('status') === 'cleared' ? 'cleared' : 'active';
        $mode    = in_array($request->query('mode'), ['custom', 'default'], true) ? $request->query('mode') : 'all';
        $q       = trim((string) $request->query('q', ''));
        $branchId = (int) $request->query('branch_id', 0);
        $sort    = in_array($request->query('sort'), ['name', 'branch', 'total'], true) ? $request->query('sort') : 'name';

        $own     = $this->targets->ownForMany($agencyId, self::TRACK, $agents->keys()->all());
        $cleared = array_flip($this->targets->clearedAgentIds($agencyId, self::TRACK, $agents->keys()->all()));

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
            $row['month_to_date'] = $workdays->monthTargetToDate($row['user'], $today, self::TRACK);
            $row['month_total']   = $workdays->monthTarget($row['user'], $today, self::TRACK);

            return $row;
        });

        return view('road-to-sold.settings.targets', [
            'tabs'     => SettingsController::tabsFor($viewer, 'targets'),
            'rows'     => $page,
            'default'  => $default,
            'weekdays' => RtsWeekdayTarget::WEEKDAYS,
            'max'      => RtsWeekdayTarget::MAX_TARGET,
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
        $changed = $this->targets->saveWeek($agent, TargetService::normalisePosted($request->input('week')), (int) $viewer->id, self::TRACK);

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
        $this->targets->saveWeek($agent, $week, (int) $viewer->id, self::TRACK);

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
                $this->targets->saveWeek($agent, $week, (int) $viewer->id, self::TRACK);
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

        $n = $this->targets->clear($agent, self::TRACK);

        return $this->back($request)->with('success', $n === 0
            ? $agent->name . ' had no numbers of their own - nothing to clear.'
            : $agent->name . ' is back on the agency default. You can bring their numbers back from "Cleared numbers".');
    }

    public function restore(Request $request, int $user): RedirectResponse
    {
        $viewer = $request->user();
        $agent  = $this->agentOr404($viewer, $user);
        $this->assertCanWrite($viewer);

        $n = $this->targets->restore($agent, self::TRACK);

        return $this->back($request)->with('success', $n === 0 ? 'Nothing to bring back.' : $agent->name . '\'s numbers are back.');
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
        return redirect()->route('road-to-sold.settings.targets.index', array_filter($request->only(['q', 'status', 'mode', 'branch_id', 'sort', 'page'])));
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
