<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Property;
use App\Models\PropertyMarketingActivity;
use App\Models\Scopes\AgencyScope;
use App\Services\ClientAuthService;
use App\Services\PropertyIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Authenticated client (seller) property-intelligence endpoints.
 *
 * Surfaces the SAME seller-facing dataset as the public "Seller Live Link"
 * page ({@see \App\Http\Controllers\SellerLinkController}) and the
 * "Preview as Seller" toggle on the agent property page — but scoped to the
 * signed-in client (ClientUser → Contact in current agency) and authorised by
 * the contact↔property linkage rather than a one-off token.
 *
 * A client may only see a property's intelligence if their Contact in the
 * current agency is linked to that Property with an ownership-side role
 * (owner | seller | landlord | lessor). This mirrors exactly which contacts
 * the agent can mint a seller live link for.
 *
 * Seller-facing curation is delegated wholesale to PropertyIntelligenceService
 * (excludeInternalOnly, seller_visible recommendations, finalized presentations
 * only) so this surface can never leak agent-only data.
 *
 * Sanctum ability: `client`. Spec: .ai/specs/client-seller-insights.md
 */
class ClientSellerInsightsController extends Controller
{
    /** Pivot roles that grant a client seller-side visibility of a property. */
    private const SELLER_ROLES = ['owner', 'seller', 'landlord', 'lessor'];

    public function __construct(
        private readonly ClientAuthService $service,
        private readonly PropertyIntelligenceService $intel,
    ) {}

    /**
     * GET /api/v1/client/seller-properties
     *
     * Properties the signed-in client owns / is selling in the current agency,
     * each with the headline stats needed to render a list card.
     */
    public function index(Request $request): JsonResponse
    {
        $contact = $this->resolveContact($request);
        if (!$contact instanceof Contact) {
            return $contact;
        }

        $links = DB::table('contact_property')
            ->where('contact_id', $contact->id)
            ->whereIn('role', self::SELLER_ROLES)
            ->pluck('role', 'property_id');

        if ($links->isEmpty()) {
            return response()->json(['agency_id' => $contact->agency_id, 'properties' => []]);
        }

        $properties = Property::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->whereIn('id', $links->keys())
            ->where('agency_id', $contact->agency_id)
            ->orderByDesc('updated_at')
            ->get();

        $payload = $properties->map(function (Property $p) use ($links) {
            $rollup     = $this->intel->getFeedbackRollup($p->id, excludeInternalOnly: true);
            $compliance = $this->intel->getComplianceStatus($p->id);

            return [
                'id'            => $p->id,
                'title'         => $p->title ?? null,
                'address'       => method_exists($p, 'buildDisplayAddress') ? $p->buildDisplayAddress() : ($p->address ?? null),
                'suburb'        => $p->suburb,
                'role'          => $links->get($p->id),
                'status'        => $p->status ?? null,
                'price'         => $p->price,
                'price_display' => method_exists($p, 'formattedPrice') ? $p->formattedPrice() : null,
                'thumbnail'     => ($p->gallery_images_json ?? [])[0] ?? null,
                'headline'      => [
                    'viewings'       => $rollup['total_viewings'] ?? 0,
                    'days_on_market' => $compliance['days_on_market'] ?? null,
                ],
            ];
        })->values();

        return response()->json([
            'agency_id'  => $contact->agency_id,
            'properties' => $payload,
        ]);
    }

    /**
     * GET /api/v1/client/seller-properties/{property}/insights
     *
     * Full seller-facing intelligence for one property — the mobile equivalent
     * of the Seller Live Link page. 404 if the client is not seller-linked.
     */
    public function show(Request $request, int $property): JsonResponse
    {
        $contact = $this->resolveContact($request);
        if (!$contact instanceof Contact) {
            return $contact;
        }

        $role = DB::table('contact_property')
            ->where('contact_id', $contact->id)
            ->where('property_id', $property)
            ->whereIn('role', self::SELLER_ROLES)
            ->value('role');

        if (!$role) {
            return response()->json(['message' => 'Property not available.'], 404);
        }

        /** @var Property|null $p */
        $p = Property::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->with(['agent:id,name,phone,email'])
            ->where('id', $property)
            ->where('agency_id', $contact->agency_id)
            ->first();

        if (!$p) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        // Seller-facing dataset — identical curation to SellerLinkController::show.
        $feedbackRollup = $this->intel->getFeedbackRollup($p->id, excludeInternalOnly: true);
        $compliance     = $this->intel->getComplianceStatus($p->id);
        $presentations  = $this->intel->getPresentations($p->id, sellerView: true);
        $marketPosition = $this->intel->getLatestMarketPosition($p->id);
        $comparables    = $this->intel->getComparableListings($p->id);

        $recommendations = DB::table('property_recommendations')
            ->where('property_id', $p->id)
            ->where('seller_visible', true)
            ->whereNull('dismissed_at')
            ->whereNull('actioned_at')
            ->whereNotNull('seller_facing_title')
            ->orderByDesc('generated_at')
            ->get();

        $marketing = PropertyMarketingActivity::where('property_id', $p->id)
            ->sellerVisible()
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get();

        $agency = Agency::withoutGlobalScopes()->find($p->agency_id);
        $latestPresentation = $presentations->first();

        return response()->json([
            'property' => [
                'id'            => $p->id,
                'title'         => $p->title ?? null,
                'address'       => method_exists($p, 'buildDisplayAddress') ? $p->buildDisplayAddress() : ($p->address ?? null),
                'suburb'        => $p->suburb,
                'status'        => $p->status ?? null,
                'listing_type'  => $p->listing_type ?? null,
                'property_type' => $p->property_type ?? null,
                'beds'          => $p->beds,
                'baths'         => $p->baths,
                'garages'       => $p->garages,
                'price'         => $p->price,
                'price_display' => method_exists($p, 'formattedPrice') ? $p->formattedPrice() : null,
                'thumbnail'     => ($p->gallery_images_json ?? [])[0] ?? null,
                'images'        => $p->gallery_images_json ?? [],
            ],
            'role'   => $role,
            'agency' => $agency ? [
                'name'     => $agency->name,
                'logo_url' => $agency->logo_path ? asset($agency->logo_path) : null,
            ] : null,
            'performance' => [
                'viewings'       => $feedbackRollup['total_viewings'] ?? 0,
                'days_on_market' => $compliance['days_on_market'] ?? null,
                'market_value'   => $marketPosition['recommended_price'] ?? null,
                'area_average'   => $marketPosition['area_avg_price'] ?? null,
            ],
            'feedback_summary' => [
                'total_viewings'      => $feedbackRollup['total_viewings'] ?? 0,
                'total_feedback_rows' => $feedbackRollup['total_feedback_rows'] ?? 0,
            ],
            'agent_insights' => $recommendations->map(fn ($r) => [
                'title'     => $r->seller_facing_title,
                'reasoning' => $r->seller_facing_reasoning,
            ])->values(),
            'marketing_activity' => $marketing->map(fn (PropertyMarketingActivity $m) => [
                'date'  => $m->occurred_at?->toDateString(),
                'type'  => $m->activity_type,
                'label' => str_replace('_', ' ', ucfirst((string) $m->activity_type)),
            ])->values(),
            'comparables' => $comparables->map(fn ($c) => [
                'title'          => $c['title'] ?? null,
                'suburb'         => $c['suburb'] ?? null,
                'price'          => $c['price'] ?? null,
                'price_display'  => isset($c['price']) ? 'R ' . number_format((float) $c['price'], 0, '.', ',') : null,
                'days_on_market' => $c['days_on_market'] ?? null,
            ])->values(),
            'presentation' => $latestPresentation ? [
                'title'        => $latestPresentation->title,
                'generated_at' => $latestPresentation->created_at,
            ] : null,
            'listing_status' => [
                'published'      => (bool) ($compliance['published'] ?? false),
                'mandate_active' => !($compliance['mandate_expired'] ?? true),
            ],
            'agent' => $p->agent ? [
                'name'  => $p->agent->name,
                'email' => $p->agent->email,
                'phone' => $p->agent->phone,
            ] : null,
            'last_refreshed_at' => now()->toIso8601String(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Returns the Contact for the signed-in client in their current agency,
     * or a 409/404 JsonResponse if not resolvable. Mirrors
     * ClientPortalController::resolveContact.
     */
    private function resolveContact(Request $request): Contact|JsonResponse
    {
        /** @var ClientUser $client */
        $client = $request->user();

        $agencyId = $client->current_agency_id;
        if (!$agencyId) {
            return response()->json(['message' => 'Select an agency first.'], 409);
        }

        $contact = $this->service->contactForAgency($client, $agencyId);
        if (!$contact) {
            return response()->json(['message' => 'No contact record in this agency.'], 404);
        }

        return $contact;
    }
}
