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
 * Other Agency Stock" endpoint.
 *
 * 2026-09-29 URGENT FIX: this route family used to sit on
 * auth.portal_capture (users.api_token) on the false assumption that was
 * "the same mechanism" the rest of the extension uses. It is not — Pull
 * Property, healthCheck and every other extension call authenticate via a
 * real Sanctum personal access token, so this route now lives in the same
 * auth:sanctum + app_access group as those (see routes/api.php) and takes
 * the identical Authorization: Bearer {sanctum token} the extension already
 * sends everywhere else.
 */
class OtherAgencyStockImportController extends Controller
{
    /** Only these hosts may ever be stored as a listing/profile URL — .ai/specs/other-agency-stock.md §9. */
    private const ALLOWED_HOSTS = ['property24.com', 'www.property24.com', 'privateproperty.co.za', 'www.privateproperty.co.za'];

    /**
     * The consent wording currently in effect for the authenticated user's
     * agency — the extension fetches this before rendering the required
     * checkbox, so it never shows stale/hardcoded text. Also returns the
     * user's own agency name — 2026-09-29 Pomona incident (property
     * #21094): "Pull as My Own Listing" now cross-checks this against the
     * page's own listing agency before pulling, so an agent can't silently
     * pull another agency's mandate into their own stock as a draft.
     */
    public function consentWording(Request $request): JsonResponse
    {
        $user = $request->user();
        $agency = $user->effectiveAgencyId() ? Agency::find($user->effectiveAgencyId()) : null;

        return response()->json([
            'wording'      => $agency?->other_agency_stock_consent_wording ?: OtherAgencyStockConsent::DEFAULT_WORDING,
            'version'      => OtherAgencyStockConsent::WORDING_VERSION,
            'agency_name'  => $agency?->name,
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
            // .ai/specs/other-agency-stock.md §3/§4 — 2026-09-29 Pomona
            // field-mapping fix. property_type is now OPTIONAL and, when
            // absent, derived server-side by OtherAgencyStockFieldMapper
            // from property_type_raw/property_type_label_hint — the exact
            // string a caller sends here (if any) still wins outright, so
            // this stays a valid explicit override, not a removed field.
            'property_type'            => ['nullable', 'string', 'max:100'],
            'property_type_raw'        => ['nullable', 'string', 'max:100'],
            'property_type_label_hint' => ['nullable', 'string', 'max:100'],
            // listing_type is now effectively required in practice (the
            // extension always derives it from the URL), but stays
            // 'nullable' at the validation layer — an absent value is a
            // data-quality gap for the service to handle, not a 422.
            'listing_type'   => ['nullable', 'string', 'in:sale,rental'],
            // P24's OWN external suburb id, straight off the listing URL —
            // resolved server-side via P24LocationResolver::resolveByP24Id()
            // into CoreX's internal p24_suburb_id/p24_city_id/p24_province_id
            // chain (and the denormalised suburb/city/province/town text).
            'p24_suburb_external_id' => ['nullable', 'integer'],
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
