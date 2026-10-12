<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\CommandCenter\AlertSwitchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * AT-451 — "all alerts switched off": the one-line warning bar's two buttons (a user acting on themselves) and the
 * agency list of users in that state (people with `manage_users`, own / branch / agency scoped in the query).
 * Every decision is recorded by AlertSwitchService in the append-only user_alert_decisions table.
 */
class AlertSwitchController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(private AlertSwitchService $alerts) {}

    // ── The warning bar (a user acting on THEMSELVES — no id is ever accepted) ──────────────────

    public function barTurnOn(Request $request): RedirectResponse
    {
        $user = $request->user();

        try {
            $this->alerts->turnOn($user, $user);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'In-app alerts are on.');
    }

    public function barKeepOff(Request $request): RedirectResponse
    {
        $user = $request->user();

        try {
            $this->alerts->acceptOff($user, $user);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Noted - your alerts stay off.');
    }

    // ── The agency list ────────────────────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('manage_users'), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:all,open,accepted'],
            'branch_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'in:name,role,branch,since'],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);

        $rows = $this->alerts->allOffRows($request->user(), $filters);
        $total = $this->alerts->allOffCount($request->user());

        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $agencyId = $request->user()->effectiveAgencyId();
        $branches = $agencyId
            ? Branch::query()->where('agency_id', $agencyId)->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('corex.alerts-off.index', [
            'rows' => $paginator,
            'total' => $total,
            'filters' => $filters,
            'branches' => $branches,
            'filtered' => collect($filters)->filter(fn ($v) => $v !== null && $v !== '' && $v !== 'all')->except(['sort', 'dir'])->isNotEmpty(),
        ]);
    }

    public function turnOn(Request $request, int $user): RedirectResponse
    {
        return $this->decide($request, $user, 'turn-on');
    }

    public function accept(Request $request, int $user): RedirectResponse
    {
        return $this->decide($request, $user, 'accept');
    }

    private function decide(Request $request, int $userId, string $action): RedirectResponse
    {
        $viewer = $request->user();
        abort_unless($viewer?->hasPermission('manage_users'), 403);

        // The SAME scoped query as the list: someone outside the viewer's own / branch / agency is a 404.
        $subject = $this->alerts->findScopedUser($viewer, $userId);
        abort_unless($subject, 404);

        try {
            $action === 'turn-on'
                ? $this->alerts->turnOn($subject, $viewer)
                : $this->alerts->acceptOff($subject, $viewer);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $action === 'turn-on'
            ? 'In-app alerts are on for ' . $subject->name . '.'
            : 'Noted - ' . $subject->name . ' stays without alerts, and that is now on record.');
    }
}
