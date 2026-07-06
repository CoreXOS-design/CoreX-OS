<?php

namespace App\Http\Controllers\Api\V1\Website;

use App\Http\Controllers\Controller;
use App\Services\Website\WebsiteLeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Inbound website lead capture — the public agency website POSTs property
 * enquiries here. Requires the `leads:write` scope. The enquiry lands in the
 * shared portal_leads pipeline (visible under Real Estate → Portal Leads,
 * routed to the listing agent(s), seeds the buyer pipeline).
 *
 * Spec: .ai/specs/agency-public-api.md §9 (built).
 */
class LeadsController extends Controller
{
    public function store(Request $request, WebsiteLeadService $service): JsonResponse
    {
        $key = $request->user();

        $data = $request->validate([
            'source'            => ['sometimes', 'nullable', 'string', 'max:50'],
            'listing_id'        => ['required_without:listing_reference', 'nullable', 'integer'],
            'listing_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'agent_ids'         => ['sometimes', 'nullable', 'array'],
            'agent_ids.*'       => ['integer'],
            'name'              => ['required', 'string', 'max:255'],
            'email'             => ['required_without:phone', 'nullable', 'email:rfc', 'max:255'],
            'phone'             => ['sometimes', 'nullable', 'string', 'max:64'],
            'message'           => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $lead = $service->capture($key, $data);

        return response()->json([
            'ok'                 => true,
            'lead_id'            => $lead->id,
            'contact_id'         => $lead->contact_id,
            'contact_matched'    => (bool) $lead->contact_exists,
            'listing_id'         => $lead->listing_id,
            'assigned_agent_ids' => $lead->agentIds(),
        ], 201);
    }
}
