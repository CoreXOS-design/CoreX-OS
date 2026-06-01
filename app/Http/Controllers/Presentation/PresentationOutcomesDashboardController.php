<?php

declare(strict_types=1);

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\Presentation;
use App\Models\PresentationOutcome;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Phase 8 — outcomes dashboard at /corex/presentations/outcomes.
 *
 * Scope:
 *   - regular agents see only their own outcomes
 *   - branch_manager / principal see the whole agency
 *   - super_admin sees all (across agencies — uses effectiveAgencyId)
 *
 * Filters: date range, outcome, cancellation_reason, agent.
 */
final class PresentationOutcomesDashboardController extends Controller
{
    /** GET /corex/presentations/outcomes */
    public function index(Request $request): View
    {
        $user = $request->user();
        $effective = $user?->effectiveAgencyId();
        abort_unless($effective, 403);

        $from = $request->date('from') ?: now()->subDays(90)->startOfDay();
        $to   = $request->date('to')   ?: now()->endOfDay();

        $outcomeFilter   = $request->string('outcome')->toString() ?: null;
        $reasonFilter    = $request->string('reason')->toString()  ?: null;
        $agentFilter     = $request->integer('agent_id')          ?: null;

        $base = PresentationOutcome::query()
            ->where('presentation_outcomes.agency_id', $effective)
            ->whereBetween('presentation_outcomes.recorded_at', [$from, $to]);

        // Permission-aware narrowing.
        $isManager = in_array((string) $user->role, ['branch_manager', 'principal', 'super_admin', 'admin'], true);
        if (!$isManager) {
            // Limit to outcomes recorded by this user OR on presentations
            // they created. Either path makes the row "theirs".
            $base->where(function ($q) use ($user) {
                $q->where('recorded_by_user_id', $user->id)
                  ->orWhereHas('presentation', fn ($p) => $p->where('created_by_user_id', $user->id));
            });
        }

        if ($outcomeFilter) {
            $base->where('outcome', $outcomeFilter);
        }
        if ($reasonFilter) {
            $base->where('cancellation_reason', $reasonFilter);
        }
        if ($agentFilter && $isManager) {
            $base->whereHas('presentation', fn ($p) => $p->where('created_by_user_id', $agentFilter));
        }

        // Top metrics. Total = count of all outcomes (filtered).
        $totalOutcomes = (clone $base)->count();
        $wonMandate    = (clone $base)->where('outcome', PresentationOutcome::OUTCOME_WON_MANDATE)->count();
        $wonSale       = (clone $base)->where('outcome', PresentationOutcome::OUTCOME_WON_SALE)->count();
        $lostComp      = (clone $base)->where('outcome', PresentationOutcome::OUTCOME_LOST_TO_COMPETITOR)->count();
        $lostNoDec     = (clone $base)->where('outcome', PresentationOutcome::OUTCOME_LOST_TO_NO_DECISION)->count();
        $stillPending  = (clone $base)->where('outcome', PresentationOutcome::OUTCOME_STILL_PENDING)->count();

        // Average days from presentation creation → outcome recorded.
        $avgDays = (clone $base)
            ->join('presentations', 'presentations.id', '=', 'presentation_outcomes.presentation_id')
            ->selectRaw('AVG(DATEDIFF(presentation_outcomes.recorded_at, presentations.created_at)) AS d')
            ->value('d');
        $avgDaysInt = $avgDays !== null ? (int) round((float) $avgDays) : null;

        // Loss reasons breakdown.
        $lossReasons = (clone $base)
            ->whereNotNull('cancellation_reason')
            ->groupBy('cancellation_reason')
            ->selectRaw('cancellation_reason, COUNT(*) AS n')
            ->orderByDesc('n')
            ->pluck('n', 'cancellation_reason')
            ->toArray();

        // Paginated list.
        $outcomes = (clone $base)
            ->with(['presentation:id,property_address,suburb,created_by_user_id', 'recorder:id,name'])
            ->orderByDesc('recorded_at')
            ->paginate(30)
            ->withQueryString();

        // Agents picker (manager-only) — only show users with presentations.
        $agents = $isManager
            ? User::where('agency_id', $effective)
                ->whereIn('id', Presentation::where('agency_id', $effective)->distinct()->pluck('created_by_user_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
            : collect();

        // Total presentations in the same window (denominator hint).
        $totalPresentations = Presentation::query()
            ->where('agency_id', $effective)
            ->whereBetween('created_at', [$from, $to])
            ->when(!$isManager, fn ($q) => $q->where('created_by_user_id', $user->id))
            ->count();

        return view('presentations.outcomes.index', [
            'from' => $from, 'to' => $to,
            'outcomeFilter' => $outcomeFilter,
            'reasonFilter'  => $reasonFilter,
            'agentFilter'   => $agentFilter,
            'totalOutcomes' => $totalOutcomes,
            'totalPresentations' => $totalPresentations,
            'wonMandate'   => $wonMandate,
            'wonSale'      => $wonSale,
            'lostComp'     => $lostComp,
            'lostNoDec'    => $lostNoDec,
            'stillPending' => $stillPending,
            'avgDays'      => $avgDaysInt,
            'lossReasons'  => $lossReasons,
            'outcomes'     => $outcomes,
            'agents'       => $agents,
            'isManager'    => $isManager,
        ]);
    }
}
