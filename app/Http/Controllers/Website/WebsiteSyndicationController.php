<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\AgencyApiKey;
use App\Models\Property;
use App\Models\PropertyWebsiteSyndication;
use App\Models\Scopes\AgencyScope;
use App\Services\PermissionService;
use App\Services\Syndication\Website\WebsiteSyndicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-property website syndication toggle — the website portal in a property's
 * Syndication panel, one row per agency website (API key). Mirrors
 * P24SyndicationController@toggle.
 *
 * Spec: .ai/specs/agency-public-api.md §6.5.2
 */
class WebsiteSyndicationController extends Controller
{
    public function __construct(private WebsiteSyndicationService $service)
    {
    }

    public function toggle(Request $request, Property $property, AgencyApiKey $apiKey): JsonResponse
    {
        $this->authorizeProperty($property);

        // The website must belong to the property's agency.
        if ((int) $apiKey->agency_id !== (int) $property->agency_id) {
            abort(404);
        }

        $current = PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $property->id)
            ->where('agency_api_key_id', $apiKey->id)
            ->first();

        $nowEnabled = !($current && $current->enabled);
        $row = $this->service->setEnabled($property, $apiKey, $nowEnabled);

        return response()->json([
            'success'  => true,
            'website'  => $apiKey->name,
            'enabled'  => $row->enabled,
            'status'   => $row->status,
        ]);
    }

    private function authorizeProperty(Property $property): void
    {
        $user  = auth()->user();
        $scope = PermissionService::getDataScope($user, 'properties');
        if ($scope === 'all') {
            return;
        }
        if ($scope === 'branch' && (int) $property->branch_id === (int) $user->effectiveBranchId()) {
            return;
        }
        if ($scope === 'own' && (int) $property->agent_id === (int) $user->id) {
            return;
        }
        abort(403);
    }
}
