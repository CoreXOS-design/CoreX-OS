<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\Compliance\MarketingReadinessService;
use Illuminate\Http\JsonResponse;

/**
 * Renders the shared syndication panel for one property.
 *
 * The Properties index shows the SAME control surface as the property page —
 * per-website toggle / refresh / deactivate, the Private Property and
 * Property24 panels, and the live preview. Rather than duplicating that
 * surface (or rendering it 20× per page), the index fetches it here for the
 * property the agent clicked and injects it into one shared modal.
 *
 * Agency isolation comes from the Property route binding (AgencyScope → 404
 * across agencies); the route carries permission:access_properties.
 *
 * The panel is refused for a listing that may not go to market, matching the
 * property page, where the Syndication action opens Compliance Status instead.
 */
final class PropertySyndicationPanelController extends Controller
{
    public function __invoke(Property $property, MarketingReadinessService $readiness): JsonResponse
    {
        $report = $readiness->statusFor($property);
        $marketable = $property->compliance_snapshot_at !== null || $report->ready;

        if (! $marketable) {
            return response()->json([
                'message' => 'Marketing is blocked for this listing — resolve its compliance gates first.',
            ], 403);
        }

        return response()->json([
            'property_id' => $property->id,
            'html' => view('corex.properties.partials.syndication-panel', [
                'property' => $property,
            ])->render(),
        ]);
    }
}
