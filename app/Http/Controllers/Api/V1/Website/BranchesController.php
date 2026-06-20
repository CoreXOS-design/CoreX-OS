<?php

namespace App\Http\Controllers\Api\V1\Website;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebsiteApi\BranchResource;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public branches (offices) for an agency website. Returns every branch under
 * the key's agency, each carrying its trading identity (trading name, address,
 * phone override, email, logo), the public agents that fall under it, and a
 * count of the syndicated listings beneath it.
 *
 * The explicit ->where('agency_id', …) constraints are defence-in-depth: our
 * principal is an AgencyApiKey whose id could collide with a row id in another
 * agency under AgencyScope's auth-id special-case, so we AND the agency
 * explicitly — same posture as AgentsController.
 *
 * Spec: .ai/specs/agency-public-api.md §5 (branches), §2 (layer 3).
 */
class BranchesController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $key = $request->user();
        $agencyId = $key->agency_id;
        $perPage = max(1, min(100, (int) $request->integer('per_page', 50)));

        $branchesQuery = Branch::query()
            ->where('agency_id', $agencyId);

        // CoreX decides the order; the website just renders the array in order.
        if (optional($key->agency)->website_branch_order_mode === \App\Models\Agency::BRANCH_ORDER_CUSTOM) {
            // Custom positions first (nulls last), then name as a tiebreaker.
            $branchesQuery->orderByRaw('website_order IS NULL, website_order ASC')->orderBy('name');
        } else {
            $branchesQuery->orderBy('name');
        }

        $branches = $branchesQuery->paginate($perPage);

        $this->hydrate($branches->getCollection(), $key);

        return BranchResource::collection($branches);
    }

    public function show(Request $request, int $id): BranchResource
    {
        $key = $request->user();
        $agencyId = $key->agency_id;

        $branch = Branch::query()
            ->where('agency_id', $agencyId)
            ->where('id', $id)
            ->first();

        abort_if($branch === null, 404, 'Branch not found.');

        $this->hydrate(collect([$branch]), $key);

        return new BranchResource($branch);
    }

    /**
     * Attach the public agents, agent count, and syndicated-listing count to
     * each branch in one pass (no per-branch N+1). Agents mirror AgentsController:
     * agency-scoped + show_on_website only. Listing counts mirror ListingsController:
     * only properties whose syndication portal is enabled for THIS key.
     *
     * @param  \Illuminate\Support\Collection<int,\App\Models\Branch>  $branches
     */
    private function hydrate($branches, $key): void
    {
        if ($branches->isEmpty()) {
            return;
        }

        $agencyId = $key->agency_id;
        $branchIds = $branches->pluck('id')->all();

        // Public agents in these branches, ordered the same way as /agents.
        // is_active gate mirrors AgentsController (WEB-1) — no departed agents.
        $agentsByBranch = User::query()
            ->where('agency_id', $agencyId)
            ->where('show_on_website', true)
            ->where('is_active', true)
            ->whereIn('branch_id', $branchIds)
            ->orderBy('name')
            ->get()
            ->groupBy('branch_id');

        // Syndicated-listing count per branch — only listings enabled for this key.
        $listingCounts = Property::query()
            ->whereHas('websiteSyndication', fn ($q) => $q
                ->where('agency_api_key_id', $key->getKey())
                ->where('enabled', true))
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('branch_id, COUNT(*) as aggregate')
            ->groupBy('branch_id')
            ->pluck('aggregate', 'branch_id');

        foreach ($branches as $branch) {
            $agents = $agentsByBranch->get($branch->id) ?? collect();
            $branch->setAttribute('website_agents', $agents->values());
            $branch->setAttribute('website_agent_count', $agents->count());
            $branch->setAttribute('website_listing_count', (int) ($listingCounts[$branch->id] ?? 0));
        }
    }
}
