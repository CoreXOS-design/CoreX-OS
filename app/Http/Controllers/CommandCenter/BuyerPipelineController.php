<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\AgencyContactSettings;
use App\Models\BuyerStateTransition;
use App\Models\Contact;
use App\Services\BuyerStateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BuyerPipelineController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $view = $request->get('view', 'kanban');
        $stateFilter = $request->get('state');
        $agentFilter = $request->get('agent_id');

        // Layer 3: Pipeline workspace scope (independent of Layer 2 contact access)
        $pipelineScope = $request->get('scope', $this->defaultPipelineScope($user));

        // AT-74 — eager-load wishlists so the "No core match" tag (hasCountableWishlist)
        // is computed per card without an N+1.
        $query = Contact::buyers()->with(['agent', 'matches']);

        // Layer 3 pipeline scope is driven by the explicit ?scope= param for
        // ALL roles. Admins still see everything BY DEFAULT because
        // defaultPipelineScope() returns 'agency' and applyPipelineScope()
        // no-ops for 'agency' — but Mine/Branch now filter for admins too
        // (previously this gate made the toggle a dead control for
        // admin/super_admin/owner).
        $this->applyPipelineScope($query, $user, $pipelineScope);

        if ($stateFilter) {
            $query->where('buyer_state', $stateFilter);
        }
        if ($agentFilter) {
            // AT-159: the pipeline agent is the ASSIGNED agent (contacts.agent_id),
            // never the capturer (created_by_user_id). Filter on the loaded agent.
            $query->where('agent_id', (int) $agentFilter);
        }

        // Drill-down from a prospecting listing: show only buyers who have an
        // active match against this listing (score >= 50, not dismissed).
        // Multi-tenancy belt-and-braces: explicit agency_id on the match query.
        $contextListing = null;
        if ($request->filled('prospecting_listing_id')) {
            $listingId = (int) $request->query('prospecting_listing_id');
            $agencyId = (int) ($user->effectiveAgencyId() ?: 0);   // AT-253 Rule 17

            $matchingContactIds = DB::table('prospecting_buyer_matches')
                ->where('prospecting_listing_id', $listingId)
                ->where('agency_id', $agencyId)
                ->whereNull('dismissed_at')
                ->where('score', '>=', 50)
                ->pluck('contact_id')
                ->all();

            // Force empty result when no matches rather than returning all buyers.
            $query->whereIn('id', $matchingContactIds ?: [0]);

            $contextListing = DB::table('prospecting_listings')
                ->where('id', $listingId)
                ->where('agency_id', $agencyId)
                ->first(['id', 'address', 'suburb', 'price', 'portal_source']);
        }

        if ($view === 'list') {
            $sortBy = $request->get('sort', 'last_activity_at');
            $sortDir = $request->get('dir', 'desc');
            $buyers = $query->orderBy($sortBy, $sortDir)->paginate(25)->withQueryString();

            return view('command-center.buyers.pipeline', [
                'view' => 'list',
                'buyers' => $buyers,
                'counts' => $this->stateCounts($user, $pipelineScope),
                'coreMatchCounts' => $this->coreMatchCounts($buyers->pluck('id')),
                'pipelineScope' => $pipelineScope,
                'canSeeBranch' => (bool) $user->branch_id,
                'contextListing' => $contextListing,
            ]);
        }

        // Kanban view — group by state
        $allBuyers = $query->orderByDesc('last_activity_at')->get();
        $columns = [
            'new' => $allBuyers->where('buyer_state', 'new')->values(),
            'warm' => $allBuyers->where('buyer_state', 'warm')->values(),
            'cold' => $allBuyers->where('buyer_state', 'cold')->values(),
            'lost' => $allBuyers->where('buyer_state', 'lost')->values(),
        ];

        $riskScores = DB::table('buyer_lost_risk_scores as brs')
            ->joinSub(
                DB::table('buyer_lost_risk_scores')->selectRaw('contact_id, MAX(id) as max_id')->groupBy('contact_id'),
                'latest', fn($j) => $j->on('brs.id', '=', 'latest.max_id')
            )
            ->pluck('brs.score', 'brs.contact_id');

        return view('command-center.buyers.pipeline', [
            'view' => 'kanban',
            'columns' => $columns,
            'counts' => $this->stateCounts($user, $pipelineScope),
            'riskScores' => $riskScores,
            'coreMatchCounts' => $this->coreMatchCounts($allBuyers->pluck('id')),
            'pipelineScope' => $pipelineScope,
            'canSeeBranch' => (bool) $user->branch_id,
            'contextListing' => $contextListing,
        ]);
    }

    /**
     * AT-108 — canonical Core Match COUNT per buyer for the board, from the
     * now-canonical property_buyer_matches cache. ONE batched grouped query for
     * all buyers on the board (no engine call per card, no N+1). The cache only
     * holds VISIBLE canonical matches (recomputeForBuyer), so a plain
     * COUNT(score >= MIN_SCORE_TO_DISPLAY) reconciles to the live Core Matches
     * count. contact_id is already access-scoped by the pipeline query.
     *
     * @return \Illuminate\Support\Collection<int,int>  contact_id => count
     */
    private function coreMatchCounts($contactIds): \Illuminate\Support\Collection
    {
        $ids = collect($contactIds)->filter()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::table('property_buyer_matches')
            ->whereIn('contact_id', $ids)
            ->where('score', '>=', \App\Services\Matching\MatchingService::MIN_SCORE_TO_DISPLAY)
            ->groupBy('contact_id')
            ->selectRaw('contact_id, COUNT(*) as c')
            ->pluck('c', 'contact_id');
    }

    public function updateState(Request $request, Contact $contact)
    {
        $request->validate([
            'state' => 'required|in:new,warm,cold,lost',
            'reason' => 'nullable|string|max:500',
        ]);

        $service = app(BuyerStateService::class);
        $service->transitionTo($contact, $request->input('state'), 'manual_override', auth()->id());

        return response()->json(['success' => true, 'new_state' => $request->input('state')]);
    }

    /**
     * Get the agency's configured default pipeline scope for non-admin roles.
     */
    private function defaultPipelineScope($user): string
    {
        $role = $user->effectiveRole();
        if (in_array($role, ['admin', 'super_admin', 'owner'], true)) {
            return 'agency';
        }

        // AT-253 (Rule 17) — read: AgencyContactSettings::forAgency already guards <=0.
        $agencyId = (int) ($user?->effectiveAgencyId() ?: 0);
        $settings = AgencyContactSettings::forAgency($agencyId);

        return $settings->buyer_pipeline_default_scope ?? 'own';
    }

    /**
     * Apply Layer 3 pipeline workspace filter to query.
     */
    private function applyPipelineScope(Builder $query, $user, string $scope): void
    {
        if ($scope === 'own') {
            $query->where('contacts.agent_id', $user->id);
        } elseif ($scope === 'branch') {
            $branchId = $user->effectiveBranchId() ?? $user->branch_id;
            if ($branchId) {
                $query->whereIn('contacts.agent_id', function ($sub) use ($branchId) {
                    $sub->select('id')->from('users')->where('branch_id', $branchId)->whereNull('deleted_at');
                });
            } else {
                $query->where('contacts.agent_id', $user->id);
            }
        }
        // 'agency' = no additional filter (Layer 2 controls access)
    }

    private function stateCounts($user, string $pipelineScope): array
    {
        $query = Contact::buyers();

        // Same rule as index(): honour the explicit scope for ALL roles so the
        // header totals match the kanban columns under every scope.
        $this->applyPipelineScope($query, $user, $pipelineScope);

        return $query->selectRaw('buyer_state, count(*) as cnt')
            ->groupBy('buyer_state')
            ->pluck('cnt', 'buyer_state')
            ->toArray();
    }
}
