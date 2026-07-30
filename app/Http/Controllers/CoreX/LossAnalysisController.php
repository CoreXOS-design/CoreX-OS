<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\PropertyThirdPartySale;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-350 — "Lost to Competitors".
 *
 * Spec: .ai/specs/property-sold-by-third-party.md §6.6
 *
 * This report is why the loss record is worth keeping. Without a place to read
 * it back, property_third_party_sales would be a write-only field — an agency
 * would capture the data and never learn anything from it.
 *
 * Read-only. Rides the properties permission group; within the agency the same
 * own/branch/all data scope that governs the Properties list governs this, so an
 * agent sees their own losses and a principal sees the agency's.
 */
class LossAnalysisController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $from = $request->date('from') ?? now()->subMonths(12)->startOfDay();
        $to   = $request->date('to')   ?? now()->endOfDay();

        // AgencyScope handles the tenant boundary. The within-agency data scope
        // is applied here, mirroring PropertyController's index — an 'own'-scope
        // agent must not read the branch's or the agency's loss book.
        $scope = PermissionService::getDataScope($user, 'properties');

        $base = PropertyThirdPartySale::query()
            ->with(['property:id,title,suburb,agent_id,status', 'property.agent:id,name'])
            // Filter on recorded_at, not sold_date: sold_date is optional (spec D4),
            // and filtering on a nullable column would silently drop every loss
            // where the agent only knew THAT it sold — the exact rows a "why are we
            // losing listings?" report most needs.
            ->whereBetween('recorded_at', [$from, $to]);

        if ($scope === 'branch') {
            $base->where('branch_id', $user->effectiveBranchId());
        } elseif ($scope === 'own') {
            $ids = $user->dataIdentityIds();
            $base->whereHas('property', fn ($q) => $q->whereIn('agent_id', $ids));
        }

        $records = (clone $base)->orderByDesc('recorded_at')->paginate(50)->withQueryString();

        // Aggregates computed off the same filtered set, so the headline numbers
        // can never disagree with the rows underneath them.
        $all = (clone $base)->get();

        $byCompetitor = $all->groupBy(fn ($r) => $r->sold_by_agency ?: 'Not recorded')
            ->map(fn ($g) => [
                'count' => $g->count(),
                'value' => (float) $g->sum(fn ($r) => (float) ($r->sold_price ?? 0)),
            ])
            ->sortByDesc('count');

        $byReason = $all->groupBy(fn ($r) => $r->lossReasonLabel() ?: 'Not recorded')
            ->map->count()
            ->sortDesc();

        $bySuburb = $all->groupBy(fn ($r) => $r->property?->suburb ?: 'Unknown')
            ->map->count()
            ->sortDesc();

        $byAgent = $all->groupBy(fn ($r) => $r->property?->agent?->name ?: 'Unassigned')
            ->map->count()
            ->sortDesc();

        // Average gap only over the rows that can actually answer it — averaging
        // an unknown as zero would report a false "we price accurately".
        $gaps = $all->map->priceGap()->filter(fn ($g) => $g !== null);
        $doms = $all->pluck('days_on_market')->filter(fn ($d) => $d !== null);

        $summary = [
            'total'            => $all->count(),
            'with_price'       => $all->filter->isComparable()->count(),
            'lost_value'       => (float) $all->sum(fn ($r) => (float) ($r->sold_price ?? 0)),
            'avg_price_gap'    => $gaps->isEmpty() ? null : (float) $gaps->avg(),
            'gap_sample'       => $gaps->count(),
            'avg_days_on_market' => $doms->isEmpty() ? null : (int) round((float) $doms->avg()),
            'dom_sample'       => $doms->count(),
            'still_lost'       => $all->whereNull('reverted_at')->count(),
        ];

        return view('corex.properties.reports.lost-to-competitors', compact(
            'records', 'summary', 'byCompetitor', 'byReason', 'bySuburb', 'byAgent', 'from', 'to'
        ));
    }
}
