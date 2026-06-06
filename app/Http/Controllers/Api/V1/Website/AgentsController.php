<?php

namespace App\Http\Controllers\Api\V1\Website;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebsiteApi\AgentResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public agent profiles for an agency website. Returns only agents flagged
 * show_on_website. The explicit agency_id constraint is defence-in-depth:
 * AgencyScope's User special-case ORs in the authenticated principal's id,
 * and our principal is an AgencyApiKey whose id could collide with a User id
 * in another agency — the explicit AND agency_id neutralises that.
 *
 * Spec: .ai/specs/agency-public-api.md §5, §2 (layer 3)
 */
class AgentsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $key = $request->user();
        $agencyId = $key->agency_id;
        $perPage = max(1, min(100, (int) $request->integer('per_page', 50)));

        $query = User::query()
            ->where('agency_id', $agencyId)
            ->where('show_on_website', true);

        // CoreX decides the order; the website just renders the array in order.
        if (optional($key->agency)->website_agent_order_mode === \App\Models\Agency::AGENT_ORDER_CUSTOM) {
            // Custom positions first (nulls last), then name as a tiebreaker.
            $query->orderByRaw('website_order IS NULL, website_order ASC')->orderBy('name');
        } else {
            $query->orderBy('name');
        }

        return AgentResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, int $id): AgentResource
    {
        $agencyId = $request->user()->agency_id;

        $agent = User::query()
            ->where('agency_id', $agencyId)
            ->where('show_on_website', true)
            ->where('id', $id)
            ->first();

        abort_if($agent === null, 404, 'Agent not found.');

        return new AgentResource($agent);
    }
}
