<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\OtherAgencyStockConsent;
use App\Services\Properties\OtherAgencyStockImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/other-agency-stock.md §4 — the Chrome extension's "Import as
 * Other Agency Stock" endpoint. Same auth as the existing portal-capture
 * ingest (AuthenticatePortalCapture: session OR Authorization: Bearer
 * {api_token}) — deliberately NOT the auth:sanctum group the rest of
 * routes/api.php's v1 surface uses, because the extension's popup-issued
 * api_token is checked against users.api_token, not a Sanctum personal
 * access token (see AuthenticatePortalCapture; same reasoning as why
 * /portal-captures/ingest lives outside that group too).
 */
class OtherAgencyStockImportController extends Controller
{
    /** Only these hosts may ever be stored as a listing/profile URL — .ai/specs/other-agency-stock.md §9. */
    private const ALLOWED_HOSTS = ['property24.com', 'www.property24.com', 'privateproperty.co.za', 'www.privateproperty.co.za'];

    /**
     * The consent wording currently in effect for the authenticated user's
     * agency — the extension fetches this before rendering the required
     * checkbox, so it never shows stale/hardcoded text.
     */
    public function consentWording(Request $request): JsonResponse
    {
        $user = $request->user();
        $agency = $user->effectiveAgencyId() ? Agency::find($user->effectiveAgencyId()) : null;

        return response()->json([
            'wording' => $agency?->other_agency_stock_consent_wording ?: OtherAgencyStockConsent::DEFAULT_WORDING,
            'version' => OtherAgencyStockConsent::WORDING_VERSION,
        ]);
    }

    public function import(Request $request, OtherAgencyStockImportService $service)
    {
        $validated = $request->validate([
            'portal'       => ['required', 'string', 'in:p24,pp'],
            'listing_ref'  => ['required', 'string', 'max:64'],
            'listing_url'  => ['required', 'string', 'max:2048', function ($attr, $value, $fail) {
                if (! self::hostAllowed($value)) {
                    $fail('The listing URL must be a property24.com or privateproperty.co.za address.');
                }
            }],
            // Explicit, required, server-enforced consent — a UI-only checkbox
            // is not enough (Johan). 'accepted' requires literally true/"yes"/1.
            'consent'      => ['required', 'accepted'],

            'price'          => ['nullable', 'numeric', 'min:0'],
            'property_type'  => ['required', 'string', 'max:100'],
            'listing_type'   => ['nullable', 'string', 'in:sale,rental'],
            'beds'           => ['nullable', 'integer', 'min:0', 'max:50'],
            'baths'          => ['nullable', 'integer', 'min:0', 'max:50'],
            'garages'        => ['nullable', 'integer', 'min:0', 'max:50'],
            'size_m2'        => ['nullable', 'numeric', 'min:0'],
            'erf_size_m2'    => ['nullable', 'numeric', 'min:0'],
            'description'    => ['nullable', 'string', 'max:20000'],

            'street_number'  => ['nullable', 'string', 'max:50'],
            'street_name'    => ['nullable', 'string', 'max:255'],
            'suburb'         => ['nullable', 'string', 'max:255'],
            'city'           => ['nullable', 'string', 'max:255'],
            'province'       => ['nullable', 'string', 'max:100'],
            'address'        => ['nullable', 'string', 'max:500'],
            'latitude'       => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'      => ['nullable', 'numeric', 'between:-180,180'],

            'features'       => ['nullable', 'array'],
            'features.*'     => ['string', 'max:255'],

            'photos'         => ['nullable', 'array', 'max:200'],
            'photos.*'       => ['url', 'max:2048'],

            'source_agency_name'       => ['nullable', 'string', 'max:255'],
            'source_agent_name'        => ['nullable', 'string', 'max:255'],
            'source_agent_phone'       => ['nullable', 'string', 'max:64'],
            'source_agent_email'       => ['nullable', 'email', 'max:255'],
            'source_agent_profile_url' => ['nullable', 'string', 'max:2048', function ($attr, $value, $fail) {
                if ($value && ! self::hostAllowed($value)) {
                    $fail('The agent profile URL must be a property24.com or privateproperty.co.za address.');
                }
            }],
            'date_posted'    => ['nullable', 'date'],

            // .ai/specs/other-agency-stock.md §5 — 2026-09-29 gallery-filter
            // fix. Never persisted; used ONLY by the service to strip these
            // exact URLs out of `photos[]` server-side, independent of
            // whatever the extension's own client-side exclusion already
            // did (defense in depth — a regressed extension version must
            // never be the only thing standing between an agent photo and
            // the imported gallery).
            'source_agent_image_url'  => ['nullable', 'string', 'max:2048'],
            'source_agency_logo_url'  => ['nullable', 'string', 'max:2048'],
        ]);

        $property = $service->import($validated, $request->user());

        return response()->json([
            'success'     => true,
            'property_id' => $property->id,
            'url'         => url('/corex/properties/' . $property->id),
        ]);
    }

    private static function hostAllowed(?string $url): bool
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

        return in_array($host, self::ALLOWED_HOSTS, true);
    }
}
