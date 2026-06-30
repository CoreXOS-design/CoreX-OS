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
    use \App\Http\Controllers\Concerns\EnforcesMarketingReadiness;

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
        // A draft is never publishable — only guard when turning the website ON.
        if ($nowEnabled) {
            $this->enforceListingNotDraft($property, $apiKey->name ?: 'this website');
        }
        $row = $this->service->setEnabled($property, $apiKey, $nowEnabled);

        return $this->state($apiKey, $row);
    }

    /** Submit / activate this listing on the website (enable). */
    public function activate(Request $request, Property $property, AgencyApiKey $apiKey): JsonResponse
    {
        $this->authorizeProperty($property);
        $this->ensureBelongs($apiKey, $property);
        $this->enforceListingNotDraft($property, $apiKey->name ?: 'this website');

        $row = $this->service->setEnabled($property, $apiKey, true);

        return $this->state($apiKey, $row);
    }

    /** Deactivate this listing on the website (disable). */
    public function deactivate(Request $request, Property $property, AgencyApiKey $apiKey): JsonResponse
    {
        $this->authorizeProperty($property);
        $this->ensureBelongs($apiKey, $property);

        $row = $this->service->setEnabled($property, $apiKey, false);

        return $this->state($apiKey, $row);
    }

    /** Refresh — re-send the listing so the website re-pulls the latest. */
    public function refresh(Request $request, Property $property, AgencyApiKey $apiKey): JsonResponse
    {
        $this->authorizeProperty($property);
        $this->ensureBelongs($apiKey, $property);

        $row = $this->service->resend($property, $apiKey);
        if (!$row) {
            return response()->json([
                'success' => false,
                'message' => 'Activate the listing on this website before refreshing.',
            ], 422);
        }

        return $this->state($apiKey, $row, 'Update sent to ' . $apiKey->name . '.');
    }

    private function ensureBelongs(AgencyApiKey $apiKey, Property $property): void
    {
        if ((int) $apiKey->agency_id !== (int) $property->agency_id) {
            abort(404);
        }
    }

    private function state(AgencyApiKey $apiKey, PropertyWebsiteSyndication $row, ?string $message = null): JsonResponse
    {
        return response()->json([
            'success'     => true,
            'website'     => $apiKey->name,
            'enabled'     => (bool) $row->enabled,
            'status'      => $row->status,
            'last_synced' => optional($row->last_synced_at)->diffForHumans(),
            'activated'   => optional($row->activated_at)->format('d M Y H:i'),
            'message'     => $message,
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
