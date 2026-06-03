<?php

declare(strict_types=1);

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\Presentation;
use App\Models\PresentationDelivery;
use App\Models\PresentationOutcome;
use App\Models\PresentationSnapshotLink;
use App\Models\PresentationTeaserLead;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Phase 9a G1 — BM-facing single-page lifecycle dashboard.
 *
 * Answers: "across the agency this period, how is the presentations
 * pipeline behaving end-to-end". Different from Phase 8's outcomes
 * dashboard, which focuses on outcomes alone — this one sweeps the full
 * funnel: generated → shared → viewed → leads → outcomes.
 *
 * Permission gate: any agent with access_presentations sees their own
 * cohort (presentations they created). branch_manager/principal/admin/
 * super_admin see the whole agency.
 *
 * Filters: date range + agent.
 */
final class PresentationAnalyticsController extends Controller
{
    /** GET /corex/presentations/analytics */
    public function index(Request $request): View
    {
        $user = $request->user();
        $effective = (int) ($user?->effectiveAgencyId() ?? 0);
        abort_unless($effective, 403);

        $isManager = in_array((string) $user->role, ['branch_manager', 'principal', 'super_admin', 'admin'], true);
        $agentFilter = $request->integer('agent_id') ?: ($isManager ? null : (int) $user->id);

        $from = $request->date('from') ?: now()->subDays(30)->startOfDay();
        $to   = $request->date('to')   ?: now()->endOfDay();

        $generatedQ = Presentation::query()
            ->where('agency_id', $effective)
            ->whereBetween('created_at', [$from, $to]);
        if ($agentFilter) {
            $generatedQ->where('created_by_user_id', $agentFilter);
        }
        $generatedIds = $generatedQ->pluck('id');
        $generatedCount = $generatedIds->count();

        $sharedCount = $generatedCount > 0
            ? PresentationDelivery::whereIn('presentation_id', $generatedIds)
                ->where('status', '!=', 'failed')
                ->distinct('presentation_id')
                ->count('presentation_id')
            : 0;

        $viewedCount = $generatedCount > 0
            ? PresentationSnapshotLink::whereIn('presentation_id', $generatedIds)
                ->where('view_count', '>', 0)
                ->distinct('presentation_id')
                ->count('presentation_id')
            : 0;

        $leadCount = $generatedCount > 0
            ? PresentationTeaserLead::whereIn('presentation_id', $generatedIds)->count()
            : 0;

        $outcomeRows = $generatedCount > 0
            ? PresentationOutcome::whereIn('presentation_id', $generatedIds)->get(['outcome'])
            : collect();
        $outcomeRecordedCount = $outcomeRows->count();
        $outcomePendingCount  = max(0, $generatedCount - $outcomeRecordedCount);
        $wonCount = $outcomeRows->whereIn('outcome', PresentationOutcome::WON_OUTCOMES)->count();
        $winRate = $outcomeRecordedCount > 0
            ? round($wonCount / $outcomeRecordedCount * 100, 1)
            : 0.0;

        $byAgent = collect();
        if ($isManager && $generatedCount > 0) {
            // `generated` is a reserved word in MySQL 8 (generated columns) — alias as
            // `generated_count` so the SELECT + ORDER BY parse cleanly. The PHP array
            // key below stays `'generated'` because the view (analytics/index.blade.php)
            // consumes `$a['generated']` directly.
            $byAgent = Presentation::query()
                ->selectRaw('created_by_user_id, COUNT(*) AS generated_count')
                ->where('agency_id', $effective)
                ->whereBetween('created_at', [$from, $to])
                ->groupBy('created_by_user_id')
                ->orderByDesc('generated_count')
                ->get()
                ->map(function ($row) use ($effective, $from, $to) {
                    $ids = Presentation::query()
                        ->where('agency_id', $effective)
                        ->where('created_by_user_id', $row->created_by_user_id)
                        ->whereBetween('created_at', [$from, $to])
                        ->pluck('id');
                    $won = PresentationOutcome::whereIn('presentation_id', $ids)
                        ->whereIn('outcome', PresentationOutcome::WON_OUTCOMES)->count();
                    $recorded = PresentationOutcome::whereIn('presentation_id', $ids)->count();
                    return [
                        'user_id'   => $row->created_by_user_id,
                        'name'      => User::find($row->created_by_user_id)?->name ?? 'Former agent',
                        'generated' => (int) $row->generated_count,
                        'recorded'  => $recorded,
                        'won'       => $won,
                        'win_rate'  => $recorded > 0 ? round($won / $recorded * 100, 1) : null,
                    ];
                });
        }

        $agents = $isManager
            ? User::where('agency_id', $effective)
                ->whereIn('id', Presentation::where('agency_id', $effective)->distinct()->pluck('created_by_user_id'))
                ->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('presentations.analytics.index', [
            'from' => $from, 'to' => $to,
            'agentFilter' => $agentFilter, 'isManager' => $isManager,
            'generatedCount' => $generatedCount,
            'sharedCount'    => $sharedCount,
            'viewedCount'    => $viewedCount,
            'leadCount'      => $leadCount,
            'outcomeRecordedCount' => $outcomeRecordedCount,
            'outcomePendingCount'  => $outcomePendingCount,
            'wonCount'       => $wonCount,
            'winRate'        => $winRate,
            'byAgent'        => $byAgent,
            'agents'         => $agents,
        ]);
    }
}
