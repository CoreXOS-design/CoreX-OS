<?php

namespace App\Http\Controllers\Api\V1\Website;

use App\Http\Controllers\Controller;
use App\Services\Website\WebsiteListingStatsIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound website listing statistics — the agency's public website batches its
 * engagement counters locally and POSTs them here hourly (it does NOT call
 * CoreX per page view). Requires the `stats:write` scope.
 *
 * The validation below is deliberately STRUCTURAL ONLY. 422 means "this body is
 * not the agreed shape"; it must never mean "I don't recognise that listing" or
 * "I don't recognise that metric". The website retries the entire batch on any
 * non-2xx and only advances its watermark on a 2xx, so a 4xx over one stale
 * listing id or one new metric key would wedge its whole queue permanently.
 * Both of those are absorbed downstream instead — skipped ids come back in the
 * response body, unknown metrics are simply stored.
 *
 * Spec: .ai/specs/website-listing-stats.md §3
 */
class ListingStatsController extends Controller
{
    public function store(Request $request, WebsiteListingStatsIngestService $service): JsonResponse
    {
        $key = $request->user();

        $data = $request->validate([
            'source'       => ['sometimes', 'nullable', 'string', 'max:32'],
            'site'         => ['required', 'string', 'max:64'],
            'batch_id'     => ['required', 'string', 'max:64'],
            'generated_at' => ['sometimes', 'nullable', 'string', 'max:64'],

            // The contract says up to 200 entries per request. The cap here is
            // deliberately well above that: it is a payload-size backstop, not a
            // contract check. Rejecting 201 entries would strand a batch the
            // website can only ever resend unchanged.
            'listings'     => ['required', 'array', 'max:1000'],
            'listings.*'   => ['array'],

            // listing_id arrives as a STRING by contract; accept either without
            // coercing, and let the service do the casting and the matching.
            'listings.*.listing_id' => ['sometimes', 'nullable'],
            'listings.*.reference'  => ['sometimes', 'nullable', 'string', 'max:64'],
            'listings.*.days'       => ['sometimes', 'nullable', 'array'],
            'listings.*.days.*'     => ['array'],
            'listings.*.delta'      => ['sometimes', 'nullable', 'array'],
            'listings.*.totals'     => ['sometimes', 'nullable', 'array'],
        ]);

        $result = $service->ingest($key, $data);

        return response()->json([
            'batch_id' => $result['batch_id'],
            'accepted' => $result['accepted'],
            'skipped'  => $result['skipped'],
        ]);
    }
}
