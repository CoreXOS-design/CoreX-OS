<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\OtherAgencyStockConsent;
use App\Services\Properties\OtherAgencyStockImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
     * 2026-09-29 URGENT FIX: fields the extension may send as a relative
     * path (e.g. "/estate-agents/…", straight off listingLeadFormContext /
     * agentPageUrl) rather than an absolute URL. Normalised in place before
     * validation so a real extension payload never 422s over this.
     */
    private const URL_FIELDS = [
        'listing_url',
        'source_agent_profile_url',
        'source_agent_image_url',
        'source_agency_logo_url',
    ];

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
        $this->normaliseUrlFields($request);

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

            // 2026-09-29 URGENT FIX #2 (Clayville) — the listing's real
            // title (P24 JSON-LD name / PP bundleParams.title), so
            // deriveTitle() stops falling back to a bare suburb name.
            'listing_title'  => ['nullable', 'string', 'max:255'],
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
            // 2026-09-30 URGENT FIX #3 (Norkem Park, property #21098): P24's
            // own sequential image-id pattern, the SAME signal
            // PropertyPullController already accepts — the Pull flow's photos
            // worked; OAS's client-collected photos[] URL array didn't.
            // Reusing this instead of reimplementing collection.
            'first_image_id' => ['nullable', 'integer'],
            'image_count'    => ['nullable', 'integer', 'min:0', 'max:500'],
            'region'         => ['nullable', 'string', 'max:100'],

            'source_agency_name'       => ['nullable', 'string', 'max:255'],
            'source_agent_name'        => ['nullable', 'string', 'max:255'],
            'source_agent_phone'       => ['nullable', 'string', 'max:64'],
            'source_agent_email'       => ['nullable', 'email', 'max:255'],
            // 2026-09-29 URGENT FIX: no longer $fail()s here — an optional
            // URL must never block an import. normaliseUrlFields() has
            // already resolved a relative path against the portal's own
            // host; dropInvalidOptionalUrls() strips anything still bad
            // AFTER validation, logs it, and lets the import proceed.
            'source_agent_profile_url' => ['nullable', 'string', 'max:2048'],
            'date_posted'    => ['nullable', 'date'],

            // .ai/specs/other-agency-stock.md §5 — 2026-09-29 gallery-filter
            // fix. Never persisted; used ONLY by the service to strip these
            // exact URLs out of `photos[]` server-side, independent of
            // whatever the extension's own client-side exclusion already
            // did (defense in depth — a regressed extension version must
            // never be the only thing standing between an agent photo and
            // the imported gallery).
            // 2026-09-30 field audit (property #21098, Norkem Park). Raw
            // currency text ("R 1 800") — parsed server-side by the shared
            // mapper (OtherAgencyStockFieldMapper::parseCurrency()), same
            // reasoning as property_type_raw above: the extension sends
            // what the portal shows, the mapper turns it into CoreX data.
            'levy'                 => ['nullable', 'string', 'max:50'],
            'rates_taxes'          => ['nullable', 'string', 'max:50'],
            'zone_type_raw'        => ['nullable', 'string', 'max:100'],
            'pets_allowed'         => ['nullable', 'boolean'],
            'parking_count'        => ['nullable', 'integer', 'min:0', 'max:50'],
            'pool'                 => ['nullable', 'boolean'],
            'kitchen_features'     => ['nullable', 'array'],
            'kitchen_features.*'   => ['string', 'max:200'],
            'garden_features'      => ['nullable', 'array'],
            'garden_features.*'    => ['string', 'max:200'],
            'security_features'    => ['nullable', 'array'],
            'security_features.*'  => ['string', 'max:200'],

            'source_agent_image_url'  => ['nullable', 'string', 'max:2048'],
            'source_agency_logo_url'  => ['nullable', 'string', 'max:2048'],
        ]);

        $validated = $this->dropInvalidOptionalUrls($validated);

        $property = $service->import($validated, $request->user());

        // Same response shape PropertyPullController returns — the popup's
        // image-download polling (pull-status) is shared code and reads
        // images_count from here exactly like the Pull flow.
        $imagesCount = (int) ($validated['image_count'] ?? count($validated['photos'] ?? []));

        return response()->json([
            'success'      => true,
            'property_id'  => $property->id,
            'url'          => url('/corex/properties/' . $property->id),
            'images_count' => $imagesCount,
        ]);
    }

    /**
     * 2026-09-29 URGENT FIX: Johan's real extension sent a relative
     * source_agent_profile_url ("/estate-agents/…", straight off P24's own
     * agentPageUrl/listingLeadFormContext) and every import 422'd on it.
     * Rewrites every field in self::URL_FIELDS in place on the request: a
     * protocol-relative "//…" gets "https:" prefixed, anything else with no
     * scheme gets the portal's own host prefixed (resolved from the listing
     * URL's host, falling back to the declared `portal`). Runs BEFORE
     * validation so the strict listing_url host check and the (now lenient)
     * optional-field checks both see an absolute URL.
     */
    private function normaliseUrlFields(Request $request): void
    {
        $baseHost = $this->resolvePortalHost($request->input('portal'), $request->input('listing_url'));

        $normalised = [];
        foreach (self::URL_FIELDS as $field) {
            $value = $request->input($field);
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $normalised[$field] = self::normaliseUrl($value, $baseHost);
        }

        if ($normalised) {
            $request->merge($normalised);
        }
    }

    private function resolvePortalHost(?string $portal, ?string $listingUrl): string
    {
        if ($listingUrl) {
            $host = strtolower((string) parse_url($listingUrl, PHP_URL_HOST));
            if ($host && in_array($host, self::ALLOWED_HOSTS, true)) {
                return 'https://' . $host;
            }
        }

        return $portal === 'pp' ? 'https://www.privateproperty.co.za' : 'https://www.property24.com';
    }

    private static function normaliseUrl(string $value, string $baseHost): string
    {
        $value = trim($value);

        if (str_starts_with($value, '//')) {
            return 'https:' . $value;
        }

        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        // No scheme at all — a relative path, with or without a leading
        // slash ("/estate-agents/jane-agent" or "estate-agents/jane-agent").
        return rtrim($baseHost, '/') . '/' . ltrim($value, '/');
    }

    /**
     * 2026-09-29 URGENT FIX: an optional URL (agent profile, agent image,
     * agency logo) must NEVER block an import — even after normalising, a
     * portal can still hand us garbage. Anything left that doesn't parse as
     * a real URL (and, for the agent profile link specifically, doesn't sit
     * on an allowed portal host) is dropped and logged rather than failed.
     */
    private function dropInvalidOptionalUrls(array $validated): array
    {
        $optionalUrlFields = [
            'source_agent_profile_url' => true,  // must also be an allowed portal host
            'source_agent_image_url'   => false,
            'source_agency_logo_url'   => false,
        ];

        foreach ($optionalUrlFields as $field => $restrictToPortalHosts) {
            $value = $validated[$field] ?? null;
            if ($value === null) {
                continue;
            }

            $valid = filter_var($value, FILTER_VALIDATE_URL) !== false;
            if ($valid && $restrictToPortalHosts) {
                $valid = self::hostAllowed($value);
            }

            if (! $valid) {
                Log::warning('OtherAgencyStock import: dropped an invalid optional URL field rather than block the import', [
                    'field' => $field,
                    'value' => $value,
                ]);
                unset($validated[$field]);
            }
        }

        return $validated;
    }

    private static function hostAllowed(?string $url): bool
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

        return in_array($host, self::ALLOWED_HOSTS, true);
    }
}
