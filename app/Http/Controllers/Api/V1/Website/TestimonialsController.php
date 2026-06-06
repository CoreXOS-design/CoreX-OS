<?php

namespace App\Http\Controllers\Api\V1\Website;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebsiteApi\TestimonialResource;
use App\Models\ContactTestimonial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public published testimonials for an agency website. Returns only testimonials
 * with published=true. The explicit agency_id constraint is defence-in-depth:
 * the request principal is an AgencyApiKey whose id could collide with a row id
 * in another agency — the explicit AND agency_id neutralises that (mirrors
 * AgentsController).
 *
 * Spec: .ai/specs/testimonials.md §4.
 */
class TestimonialsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $agencyId = $request->user()->agency_id;
        $perPage = max(1, min(100, (int) $request->integer('per_page', 50)));

        $query = ContactTestimonial::query()
            ->where('agency_id', $agencyId)
            ->where('published', true)
            ->with('agent')
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        // Optional ?agent_id= — the agent's website profile pulls just their
        // testimonials. AgencyScope + the explicit agency_id keep it tenant-safe.
        if (($agentId = (int) $request->integer('agent_id')) > 0) {
            $query->where('agent_id', $agentId);
        }

        return TestimonialResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, int $id): TestimonialResource
    {
        $agencyId = $request->user()->agency_id;

        $testimonial = ContactTestimonial::query()
            ->where('agency_id', $agencyId)
            ->where('published', true)
            ->where('id', $id)
            ->with('agent')
            ->first();

        abort_if($testimonial === null, 404, 'Testimonial not found.');

        return new TestimonialResource($testimonial);
    }
}
